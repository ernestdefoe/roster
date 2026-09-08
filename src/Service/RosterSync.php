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
        protected Leagues $leagues = new Leagues()
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

            if (!$this->espn->supports($league)) {
                continue;
            }

            $summary['leagues']++;
            $summary['teams'] += $this->teams($league);
        }

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

    protected function teams(League $league): int
    {
        $clubs = $this->espn->teams($league);

        if ($clubs === []) {
            return 0;
        }

        $divisions = $this->espn->divisions($league);
        $written = 0;

        foreach ($clubs as $club) {
            $existing = Team::query()
                ->where('league', $league->key)
                ->where('external_id', $club['external_id'])
                ->first();

            $values = [
                'name' => Str::limit((string) $club['school'], 189, ''),
                'mascot' => Str::limit((string) $club['mascot'], 119, ''),
                'abbreviation' => Str::limit((string) $club['abbreviation'], 15, ''),
                'conference' => $divisions[$club['external_id']] ?? '',
                'color' => Str::limit((string) $club['color'], 15, ''),
                'logo' => (string) $club['logo'],
                'logo_dark' => (string) $club['logo_dark'],
            ];

            if ($existing !== null) {
                // 🚨 `slug` is never updated. It is in URLs people have shared.
                $existing->fill($values)->save();
                $written++;

                continue;
            }

            Team::query()->create($values + [
                'league' => $league->key,
                'external_id' => $club['external_id'],
                /*
                 * 🚨 The slug carries the league. Two leagues can hold a club
                 * of the same name, `slug` is unique across the table, and the
                 * loser of that collision would silently become the winner's
                 * page.
                 */
                'slug' => $this->slug($league, (string) ($club['slug'] ?: $club['school'])),
            ]);

            $written++;
        }

        return $written;
    }

    /* -------------------------------------------------------------- rosters */

    /** @return array{0: int, 1: int} */
    protected function rosters(): array
    {
        $stale = Carbon::now()->subHours(self::REFRESH_HOURS);

        /*
         * 🚨 Oldest first, and a club never fetched sorts first of all — so a
         * newly followed league fills in before anything is refreshed. A site
         * that has just added the NBA wants thirty rosters, not one club's
         * update.
         */
        $due = Team::query()
            ->whereNotNull('external_id')
            ->where(fn ($q) => $q->whereNull('roster_at')->orWhere('roster_at', '<', $stale))
            ->orderByRaw('roster_at IS NOT NULL')
            ->orderBy('roster_at')
            ->limit(self::ROSTERS_PER_RUN)
            ->get();

        $fetched = 0;
        $players = 0;

        foreach ($due as $team) {
            $league = $this->leagues->get($team->league);

            if (!$this->espn->supports($league)) {
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

            if ($roster === []) {
                continue;
            }

            $players += $this->players($league, $team, $roster);
        }

        return [$fetched, $players];
    }

    /** @param list<array<string, mixed>> $roster */
    protected function players(League $league, Team $team, array $roster): int
    {
        $written = 0;

        foreach ($roster as $row) {
            $values = [
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
                'college' => Str::limit((string) $row['college'], 119, ''),
                'photo_url' => (string) $row['headshot'],
            ];

            $existing = Player::query()
                ->where('league', $league->key)
                ->where('external_id', $row['external_id'])
                ->first();

            if ($existing !== null) {
                $existing->fill($values)->save();
                $written++;

                continue;
            }

            Player::query()->create($values + [
                'league' => $league->key,
                'external_id' => $row['external_id'],
                'slug' => $this->slug($league, (string) $row['name']) . '-' . $row['external_id'],
            ]);

            $written++;
        }

        return $written;
    }

    /* --------------------------------------------------------------- naming */

    /** @return list<string> the league keys this site follows */
    protected function following(): array
    {
        $raw = trim((string) $this->settings->get('ernestdefoe-roster.leagues', ''));

        if ($raw === '') {
            return [];
        }

        $out = [];

        foreach (explode(',', $raw) as $key) {
            $key = trim($key);

            /*
             * 🚨 College football is never in this list even if somebody puts
             * it there. It is CollegeFootballData's, and syncing it from ESPN
             * would overwrite a season-by-season history with a current roster.
             */
            if ($key !== '' && $key !== Leagues::DEFAULT && $this->leagues->has($key)) {
                $out[$key] = $key;
            }
        }

        return array_values($out);
    }

    protected function slug(League $league, string $value): string
    {
        $slug = Str::slug($value);

        return Str::limit($league->key . '-' . ($slug ?: substr(md5($value), 0, 10)), 199, '');
    }
}
