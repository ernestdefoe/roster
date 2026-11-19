<?php

namespace ErnestDefoe\Roster\Service;

use ErnestDefoe\Roster\Player;
use ErnestDefoe\Roster\Service\Leagues\League;
use ErnestDefoe\Roster\Service\Leagues\Leagues;
use ErnestDefoe\Roster\Service\Sources\EspnRoster;
use ErnestDefoe\Roster\Team;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Clubs and rosters, from ESPN.
 *
 * 🚨 ESPN charges nothing and needs no key, so there is no budget guard and no
 * resumable cursor here — the machinery the college-football side needs for a
 * thousand-call monthly allowance would be serving a constraint that does not
 * exist. What this needs instead is a plain per-run ceiling, because one roster
 * is one call and a league is thirty-odd of them.
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

    /** How long a club's roster is fresh enough to skip. */
    public const REFRESH_HOURS = 24;

    public function __construct(
        protected EspnRoster $espn,
        protected SettingsRepositoryInterface $settings,
        protected Leagues $leagues,
        protected HeldLeagues $held,
    ) {
    }

    /** @return array<string, int|string> a summary, for the command and for tests */
    public function run(): array
    {
        $following = $this->following();

        if ($following === []) {
            return ['skipped' => 'no leagues'];
        }

        $summary = ['leagues' => 0, 'teams' => 0, 'rosters' => 0, 'players' => 0];

        foreach ($following as $key) {
            $league = $this->leagues->get($key);

            if (! $this->espn->supports($league)) {
                continue;
            }

            $summary['leagues']++;
            $summary['teams'] += $this->teams($league);
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
        $clubs = $this->espn->teams($league);

        if ($clubs === []) {
            return 0;
        }

        $divisions = $this->espn->divisions($league);
        $rows = [];

        foreach ($clubs as $club) {
            $rows[(string) $club['external_id']] = $this->clubValues($league, $club, $divisions);
        }

        // One statement for the whole league, keyed on the provider's id.
        Team::query()->upsert(array_values($rows), ['league', 'external_id'], self::TEAM_UPDATES);

        return count($rows);
    }

    /**
     * @param array<string, mixed> $club
     * @param array<string, string> $divisions
     * @return array<string, mixed>
     */
    protected function clubValues(League $league, array $club, array $divisions): array
    {
        return [
            'league' => $league->key,
            'external_id' => (string) $club['external_id'],
            'name' => Str::limit((string) $club['school'], 189, ''),
            'mascot' => Str::limit((string) $club['mascot'], 119, ''),
            'abbreviation' => Str::limit((string) $club['abbreviation'], 15, ''),
            'conference' => $divisions[$club['external_id']] ?? '',
            'color' => Str::limit((string) $club['color'], 15, ''),
            'logo' => (string) $club['logo'],
            'logo_dark' => (string) $club['logo_dark'],
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
        $fetched = 0;
        $players = 0;

        foreach ($this->due() as $team) {
            $league = $this->leagues->get($team->league);

            if (! $this->espn->supports($league)) {
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
            $team->roster_at = Carbon::now();
            $team->save();

            $fetched++;

            if ($roster !== []) {
                $players += $this->players($league, $team, $roster);
            }
        }

        return [$fetched, $players];
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
            'photo_url' => (string) $row['headshot'],
        ];
    }

    /* --------------------------------------------------------------- naming */

    /** @return list<string> the league keys this site follows */
    protected function following(): array
    {
        $raw = trim((string) $this->settings->get('ernestdefoe-roster.leagues'));

        if ($raw === '') {
            return [];
        }

        $out = [];

        foreach (explode(',', $raw) as $key) {
            $key = trim($key);

            /*
             * Every league here is one somebody ticked. College football used
             * to be excluded by name — see the note in Leagues — which left it
             * permanently on and permanently empty at the same time.
             */
            if ($key !== '' && $this->leagues->has($key)) {
                $out[$key] = $key;
            }
        }

        return array_values($out);
    }

    protected function slug(League $league, string $value): string
    {
        $slug = Str::slug($value);

        return Str::limit($league->key.'-'.($slug ?: substr(md5($value), 0, 10)), 199, '');
    }
}
