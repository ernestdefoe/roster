<?php

namespace ErnestDefoe\Roster\Service;

use ErnestDefoe\Roster\Player;
use ErnestDefoe\Roster\Service\Leagues\League;
use ErnestDefoe\Roster\Service\Leagues\Leagues;
use ErnestDefoe\Roster\Service\Sources\EspnRoster;
use ErnestDefoe\Roster\Team;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Clubs and rosters, from ESPN.
 *
 * 🚨 ESPN charges nothing and needs no key, so there is no budget to guard.
 * What this needs instead is a plain per-run ceiling, because one roster is one
 * call and a league is thirty-odd of them.
 */
class RosterSync
{
    /**
     * 🚨 Rosters fetched per run. A site might follow five leagues; without a
     * ceiling the first tick after installing would fire a hundred and sixty
     * outbound calls in a minute, which has taken a site on this stack down
     * before. A club whose roster is a day old is invisible; a queue worker
     * killed by its own traffic is not.
     */
    public const ROSTERS_PER_RUN = 12;

    /** How long a club's roster, and a league's club list, is fresh enough to skip. */
    public const REFRESH_HOURS = 24;

    /**
     * A run stops starting new fetches after this long. Every call has its own
     * timeout, but twelve slow ones in a row would still hold the scheduler
     * for minutes; whatever is left is first in line next hour.
     */
    public const MAX_SECONDS = 300;

    /** When this run gives up starting new fetches (a Unix time). */
    private int $deadline = 0;

    public function __construct(
        protected EspnRoster $espn,
        protected Leagues $leagues,
        protected HeldLeagues $held,
        protected Cache $cache,
    ) {
    }

    /** @return array<string, int|string> a summary, for the command and for tests */
    public function run(): array
    {
        $following = $this->held->following();

        // An unticked league's clubs leave with it: never fetched again, and
        // gone from the pages once this run has removed them. Ticked again, it
        // is fetched on the next run rather than a day later.
        $this->removeClubs(Team::query()->whereNotIn('league', $following)->pluck('id')->all());

        foreach (array_diff(array_keys($this->leagues->all()), $following) as $key) {
            $this->cache->forget($this->clubsKey($this->leagues->get($key)));
        }

        if ($following === []) {
            return ['skipped' => 'no leagues'];
        }

        $summary = ['leagues' => 0, 'teams' => 0, 'rosters' => 0, 'players' => 0];
        $this->deadline = Carbon::now()->getTimestamp() + self::MAX_SECONDS;

        foreach ($following as $key) {
            $league = $this->leagues->get($key);

            if (! $this->espn->supports($league)) {
                continue;
            }

            $summary['leagues']++;
            $summary['teams'] += $this->clubsDue($league) ? $this->teams($league) : 0;
        }

        // The index's league switcher reads this; a newly followed league joins it.
        $this->held->forget();

        /*
         * 🚨 Rosters are fetched ACROSS leagues in one ordered pass, oldest
         * first, rather than league by league. Per league, a site following
         * five would spend every run's whole ceiling on the first one and the
         * fifth would never be fetched at all.
         */
        [$rosters, $players] = $this->rosters();

        $summary['rosters'] = $rosters;
        $summary['players'] = $players;

        return $summary;
    }

    /* ---------------------------------------------------------------- clubs */

    /**
     * 🚨 `slug` is written on insert and never updated: it is in URLs people
     * have shared.
     */
    private const TEAM_UPDATES = ['name', 'mascot', 'abbreviation', 'conference', 'color', 'logo', 'logo_dark', 'updated_at'];

    private const PLAYER_UPDATES = [
        'team_id', 'name', 'first_name', 'last_name', 'position', 'position_group', 'jersey', 'height', 'weight',
        'home_city', 'home_state', 'home_country', 'class_year', 'college', 'photo_url', 'updated_at',
    ];

    protected function teams(League $league): int
    {
        // Empty when ESPN failed to answer: the league keeps what it has, and
        // its club list is asked for again on the next run.
        $clubs = $this->espn->teams($league);

        if ($clubs === []) {
            $this->cache->forget($this->clubsKey($league));

            return 0;
        }

        $rows = [];

        foreach ($clubs as $club) {
            $rows[(string) $club['external_id']] = $this->clubValues($league, $club);
        }

        // One statement for the whole league, keyed on the provider's id.
        Team::query()->upsert(array_values($rows), ['league', 'external_id'], self::TEAM_UPDATES);

        // A club the league no longer lists (relegated, or out of the
        // division) leaves it, with its roster.
        $this->removeClubs(
            Team::query()->where('league', $league->key)->whereNotIn('external_id', array_map('strval', array_keys($rows)))->pluck('id')->all()
        );

        return count($rows);
    }

    /** @param list<int> $ids clubs to remove, with their rosters */
    protected function removeClubs(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Team::query()->getConnection()->transaction(function () use ($ids) {
            Player::query()->whereIn('team_id', $ids)->delete();
            Team::query()->whereIn('id', $ids)->delete();
        });

        $this->held->forget();
    }

    /**
     * @param array<string, mixed> $club
     * @return array<string, mixed>
     */
    protected function clubValues(League $league, array $club): array
    {
        return [
            'league' => $league->key,
            'external_id' => (string) $club['external_id'],
            'name' => Str::limit((string) $club['school'], 189, ''),
            'mascot' => Str::limit((string) $club['mascot'], 119, ''),
            'abbreviation' => Str::limit((string) $club['abbreviation'], 15, ''),
            'conference' => Str::limit((string) $club['conference'], 99, ''),
            'color' => Str::limit((string) $club['color'], 15, ''),
            'logo' => $this->url($club['logo']),
            'logo_dark' => $this->url($club['logo_dark']),
            /*
             * 🚨 The slug carries the league. Two leagues can hold a club of
             * the same name, `slug` is unique across the table, and the loser
             * of that collision would silently become the winner's page.
             */
            'slug' => $this->slug($league, (string) ($club['slug'] ?: $club['school'])),
        ];
    }

    /* -------------------------------------------------------------- rosters */

    /** @return array{0: int, 1: int} */
    protected function rosters(): array
    {
        $fetched = [];
        $players = 0;

        foreach ($this->due() as $team) {
            $league = $this->leagues->get($team->league);

            if (! $this->espn->supports($league) || $this->outOfTime()) {
                continue;
            }

            $roster = $this->espn->roster($league, (string) $team->external_id);

            /*
             * 🚨 Stamped even when the roster came back EMPTY. ESPN assembles
             * this endpoint from its own upstream calls, and one athlete with a
             * missing record takes a whole club's roster down with a 404 — seen
             * live on one NFL club while the other thirty-one answered. Without
             * the stamp that club is retried first on every run for ever, and
             * no other club is ever reached.
             */
            $fetched[] = (int) $team->id;

            if ($roster !== []) {
                $players += $this->players($league, $team, $roster);
            }
        }

        if ($fetched !== []) {
            Team::query()->whereIn('id', $fetched)->update(['roster_at' => Carbon::now()]);
        }

        return [count($fetched), $players];
    }

    /**
     * The clubs whose rosters this run fetches.
     *
     * 🚨 Never-fetched clubs first, then the stalest — so a newly followed
     * league fills in before anything is refreshed. Two queries rather than one
     * ordered on `roster_at IS NOT NULL`: where NULL sorts differs between
     * MySQL, SQLite and PostgreSQL, and the builder has no portable way to say
     * it.
     *
     * @return list<Team>
     */
    protected function due(): array
    {
        $never = Team::query()
            ->whereNotNull('external_id')
            ->whereNull('roster_at')
            ->orderBy('id')
            ->limit(self::ROSTERS_PER_RUN)
            ->get()
            ->all();

        $room = self::ROSTERS_PER_RUN - count($never);

        if ($room <= 0) {
            return $never;
        }

        $stale = Team::query()
            ->whereNotNull('external_id')
            ->where('roster_at', '<', Carbon::now()->subHours(self::REFRESH_HOURS))
            ->orderBy('roster_at')
            ->limit($room)
            ->get()
            ->all();

        return array_merge($never, $stale);
    }

    /** @param list<array<string, mixed>> $roster */
    protected function players(League $league, Team $team, array $roster): int
    {
        $rows = [];

        // Keyed on the provider's id: one row per athlete, however often the
        // feed lists him.
        foreach ($roster as $row) {
            $rows[(string) $row['external_id']] = $this->playerValues($league, $team, $row);
        }

        // 🚨 One statement for the whole roster. Written player by player, a
        // run of twelve clubs was well over a thousand queries every hour.
        Player::query()->upsert(array_values($rows), ['league', 'external_id'], self::PLAYER_UPDATES);

        // Released, graduated or traded: whoever this club no longer lists
        // leaves its roster. Only after a roster came back, so a failed fetch
        // never empties a club.
        Player::query()->where('team_id', $team->id)->whereNotIn('external_id', array_map('strval', array_keys($rows)))->delete();

        return count($rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function playerValues(League $league, Team $team, array $row): array
    {
        return [
            'league' => $league->key,
            'external_id' => (string) $row['external_id'],
            'slug' => $this->slug($league, (string) $row['name']).'-'.$row['external_id'],
            'team_id' => $team->id,
            'name' => Str::limit((string) $row['name'], 189, ''),
            'first_name' => Str::limit((string) $row['first_name'], 99, ''),
            'last_name' => Str::limit((string) $row['last_name'], 99, ''),
            'position' => Str::limit((string) $row['position'], 11, ''),
            'position_group' => (string) $row['position_group'],
            'jersey' => $row['jersey'],
            'height' => $row['height'],
            'weight' => $row['weight'],
            'home_city' => Str::limit((string) $row['home_city'], 119, ''),
            'home_state' => Str::limit((string) $row['home_state'], 15, ''),
            'home_country' => Str::limit((string) $row['home_country'], 59, ''),
            // 🚨 Written at all only since 1.3: until then every college roster's Class column was empty.
            'class_year' => $row['class_year'],
            'college' => Str::limit((string) $row['college'], 119, ''),
            'photo_url' => $this->url($row['headshot']),
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * Whether a league's club list is due: once a day, and straight away for a
     * league just followed. Clubs change a few times a season; the standings
     * and up to four pages of clubs are not worth asking for every hour.
     */
    protected function clubsDue(League $league): bool
    {
        return ! $this->outOfTime()
            && $this->cache->add($this->clubsKey($league), 1, self::REFRESH_HOURS * 3600);
    }

    private function clubsKey(League $league): string
    {
        return 'ernestdefoe-roster.clubs-fetched.'.$league->key;
    }

    protected function outOfTime(): bool
    {
        return Carbon::now()->getTimestamp() >= $this->deadline;
    }

    /** A feed URL that fits its 255-character column; one that does not is no URL at all. */
    protected function url(mixed $value): string
    {
        $value = (string) $value;

        return strlen($value) <= 255 ? $value : '';
    }

    protected function slug(League $league, string $value): string
    {
        $slug = Str::slug($value);

        return Str::limit($league->key.'-'.($slug ?: substr(md5($value), 0, 10)), 199, '');
    }
}
