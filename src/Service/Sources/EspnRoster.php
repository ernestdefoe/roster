<?php

namespace ErnestDefoe\Roster\Service\Sources;

use ErnestDefoe\Roster\Service\Leagues\League;
use ErnestDefoe\Roster\Service\Http\JsonFetcher;

/**
 * ESPN's team, standings and roster endpoints, for every league this extension
 * carries.
 *
 * 🚨 Shapes read off live responses in September 2026 rather than from
 * documentation, because ESPN publishes none for this:
 *
 *     /{sport}/{league}/teams              → sports[0].leagues[0].teams[].team
 *     /{sport}/{league}/teams/{id}/roster  → athletes[]
 *
 * 🚨 And `athletes[]` comes in TWO shapes depending on the sport. The NFL, MLB
 * and the NHL answer GROUPS — `[{position: "offense", items: [...]}]` — and the
 * NBA answers a FLAT list of athletes. Reading only one loses every player in
 * half the leagues, and the failure is silent: an empty roster page.
 *
 * 🚨 It needs no API key, which is why it is the source.
 */
class EspnRoster
{
    protected const TIMEOUT = 20;

    private const BASE = 'https://site.api.espn.com/apis/site/v2/sports';

    /**
     * How many pages of clubs to walk.
     *
     * Two covers college football's seven hundred; the cap is here so a feed
     * that stops saying "this is the last page" cannot become a loop.
     */
    private const MAX_TEAM_PAGES = 4;

    public function __construct(protected JsonFetcher $fetcher)
    {
    }

    public function supports(League $league): bool
    {
        return $league->espnPath !== '';
    }

    /**
     * Every club in a league.
     *
     * @return list<array<string, mixed>>
     */
    public function teams(League $league): array
    {
        if (! $this->supports($league)) {
            return [];
        }

        $rows = $this->teamPages($league);

        if ($rows === []) {
            return [];
        }

        /*
         * 🚨 Scoped to the division the STANDINGS cover, where there are any.
         *
         * `/teams` has no notion of division; the standings do, and for college
         * football `level=3` answers exactly the hundred and thirty-eight FBS
         * schools. A board about FBS football has no use for six hundred
         * Division III programmes, and no way to tell them apart without this.
         *
         * A league whose standings answer nothing — league football, say — is
         * unaffected: an empty map means take every club, which is what the
         * teams endpoint already meant for a competition that has only one
         * division.
         */
        $scope = $this->divisions($league);

        /*
         * 🚨 A FAILED standings call is not an empty one. Read as "this league
         * has no divisions", it took every club ESPN has — seven hundred
         * college programmes down to Division III — so the league is skipped
         * for this run instead.
         */
        if ($scope === null) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $club = $this->club($row, $scope);

            if ($club !== null) {
                $out[] = $club;
            }
        }

        return $out;
    }

    /**
     * Every page of the league's club list.
     *
     * 🚨 PAGED, and a page is capped however large a limit is asked for.
     *
     * College football answers this endpoint with every school ESPN has —
     * upwards of seven hundred, down to Division III — and a single call
     * returned the first two hundred alphabetically. Alabama was not among
     * them. The symptom was a site missing its most famous team while carrying
     * Adams State, and nothing anywhere said the list had been cut.
     *
     * @return list<mixed> empty when any page failed
     */
    private function teamPages(League $league): array
    {
        $rows = [];

        for ($page = 1; $page <= self::MAX_TEAM_PAGES; $page++) {
            $body = $this->get($league->espnPath.'/teams', ['limit' => '400', 'page' => (string) $page]);

            // A failed page is a failed list: half a league read as all of it
            // would remove the clubs on the missing page.
            if ($body === null) {
                return [];
            }

            $chunk = $body['sports'][0]['leagues'][0]['teams'] ?? [];

            if (! is_array($chunk) || $chunk === []) {
                break;
            }

            $rows = array_merge($rows, $chunk);

            // A short page is the last page.
            if (count($chunk) < 400) {
                break;
            }
        }

        return $rows;
    }

    /**
     * One row of the club list, shaped for the sync; null when it is not a club
     * or is outside the division the standings cover.
     *
     * @param array<string, string> $scope
     * @return array<string, mixed>|null
     */
    private function club(mixed $row, array $scope): ?array
    {
        $team = is_array($row) ? ($row['team'] ?? null) : null;

        if (! is_array($team) || ($team['id'] ?? '') === '') {
            return null;
        }

        if ($scope !== [] && ! isset($scope[(string) $team['id']])) {
            return null;
        }

        return [
            'external_id' => (string) $team['id'],
            'school' => (string) ($team['displayName'] ?? ''),
            'mascot' => (string) ($team['name'] ?? ''),
            'slug' => (string) ($team['slug'] ?? ''),
            'abbreviation' => (string) ($team['abbreviation'] ?? ''),
            'color' => (string) ($team['color'] ?? ''),
            'logo' => $this->logo($team, false),
            'logo_dark' => $this->logo($team, true),
            /*
             * 🚨 The division from the standings where they name one, else the
             * group the club list carries. It is what the index groups by, and
             * a professional league without it renders as one long ungrouped
             * list — which is correct for the Premier League and wrong for the
             * NFL.
             */
            'conference' => $scope[(string) $team['id']] ?? $this->conference($team),
        ];
    }

    /**
     * Which division each club is in, by the club's ESPN id.
     *
     * 🚨 From the STANDINGS, which is the only place ESPN puts the division's
     * NAME. The team list carries no groups at all, and the per-team endpoint
     * carries them as bare ids — `{"id": "3", "parent": {"id": "7"}}` — with
     * nothing to turn 3 into "AFC West". Standings answers every division and
     * every club in one call.
     *
     * 🚨 A league with no divisions answers nothing, and that is fine: the
     * index groups by whatever it is given and shows one list when it is given
     * nothing, which is right for the Premier League and wrong only if it were
     * pretended otherwise.
     *
     * @return array<string, string>|null ESPN team id => division name; null when the call failed
     */
    private function divisions(League $league): ?array
    {
        // `level=3` is conference → division → team. Levels 1 and 2 stop short.
        $body = $this->get($league->espnPath.'/standings', ['level' => '3'], 'https://site.api.espn.com/apis/v2/sports');

        return $body === null ? null : $this->divisionMap($body);
    }

    /**
     * @param array<string, mixed> $body the standings response
     * @return array<string, string> ESPN team id => division name
     */
    private function divisionMap(array $body): array
    {
        $out = [];

        foreach ($this->standingGroups($body) as $group) {
            $name = mb_substr(trim((string) ($group['name'] ?? '')), 0, 100);

            foreach ((array) (($group['standings'] ?? [])['entries'] ?? []) as $entry) {
                $id = (string) ((is_array($entry) ? ($entry['team'] ?? []) : [])['id'] ?? '');

                if ($id !== '' && $name !== '') {
                    $out[$id] = $name;
                }
            }
        }

        return $out;
    }

    /**
     * 🚨 The DIVISION where there is one, the conference where there is not. A
     * league with two levels answers its divisions as children; one with a
     * single level answers its clubs directly, and taking only the children
     * would return nothing for it.
     *
     * @param array<string, mixed> $body the standings response
     * @return list<array<string, mixed>>
     */
    private function standingGroups(array $body): array
    {
        $out = [];

        foreach ((array) ($body['children'] ?? []) as $conference) {
            $children = is_array($conference) ? (array) ($conference['children'] ?? []) : [];

            foreach ($children === [] ? [$conference] : $children as $group) {
                if (is_array($group)) {
                    $out[] = $group;
                }
            }
        }

        return $out;
    }

    /**
     * One club's roster.
     *
     * @return list<array<string, mixed>>
     */
    public function roster(League $league, string $teamId): array
    {
        if (! $this->supports($league) || $teamId === '') {
            return [];
        }

        /*
         * 🚨 A limit, because this endpoint silently answers with the first
         * HUNDRED athletes and says nothing about the rest.
         *
         * An FBS roster is around a hundred and thirty, so every club on the
         * site was quietly missing its last thirty players — a placekicker here,
         * a walk-on there, and no error anywhere. Alabama returned 100 with
         * `limit=200` returning 127. 200 clears any roster in the sports this
         * covers; a league that ever exceeds it would need paging, and would
         * announce itself the same silent way, so the number is deliberate
         * rather than merely large.
         */
        $body = $this->get($league->espnPath.'/teams/'.rawurlencode($teamId).'/roster', ['limit' => '200']);

        /*
         * 🚨 An empty roster is not an error to shout about. ESPN answers this
         * endpoint by assembling its own upstream calls, and a single athlete
         * whose contract record is missing takes the whole team's roster down
         * with a 404 — seen live, on one NFL club, while the other thirty-one
         * answered normally. The club keeps the roster it already has and the
         * next run picks it up.
         */
        if ($body === null) {
            return [];
        }

        $athletes = $body['athletes'] ?? [];

        return is_array($athletes) ? $this->athletes($athletes) : [];
    }

    /**
     * Every athlete in a roster response, in either of its shapes.
     *
     * @param array<mixed> $athletes
     * @return list<array<string, mixed>>
     */
    private function athletes(array $athletes): array
    {
        $out = [];

        foreach ($athletes as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            /*
             * 🚨 The two shapes, told apart by `items` rather than by sport.
             * Keying it off the league would be a list to maintain, and the one
             * ESPN actually varies is the payload.
             */
            $grouped = isset($entry['items']) && is_array($entry['items']);
            $group = $grouped ? $this->groupName($entry['position'] ?? '') : '';

            foreach ($grouped ? $entry['items'] : [$entry] as $athlete) {
                $player = $this->player($athlete, $group);

                if ($player !== null) {
                    $out[] = $player;
                }
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------- shaping */

    /**
     * One athlete, shaped for the sync.
     *
     * 🚨 Everything measured is nullable on purpose. ESPN omits a jersey for an
     * unsigned player and a height for most of soccer — and a zero stored for a missing
     * measurement is a page that says a player is 0in tall. The JERSEY is the
     * exception and has its own guard: see jersey(), where 0 is a number
     * somebody actually wears.
     *
     * @return array<string, mixed>|null
     */
    private function player(mixed $athlete, string $group): ?array
    {
        if (! is_array($athlete) || ($athlete['id'] ?? '') === '') {
            return null;
        }

        $name = (string) ($athlete['fullName'] ?? $athlete['displayName'] ?? '');

        if (trim($name) === '') {
            return null;
        }

        $birthPlace = $this->section($athlete, 'birthPlace');
        $position = $this->section($athlete, 'position');

        return [
            'external_id' => (string) $athlete['id'],
            'name' => $name,
            'first_name' => (string) ($athlete['firstName'] ?? ''),
            'last_name' => (string) ($athlete['lastName'] ?? ''),
            'position' => (string) ($position['abbreviation'] ?? ''),
            'position_group' => $group !== '' ? $group : $this->groupName($position['displayName'] ?? ''),
            'jersey' => $this->jersey($athlete['jersey'] ?? null),
            'height' => $this->number($athlete['height'] ?? null),
            'weight' => $this->number($athlete['weight'] ?? null),
            'home_city' => (string) ($birthPlace['city'] ?? ''),
            'home_state' => (string) ($birthPlace['state'] ?? ''),
            'home_country' => (string) ($birthPlace['country'] ?? ''),
            'college' => (string) ($this->section($athlete, 'college')['name'] ?? ''),
            'class_year' => $this->classYear($athlete['experience'] ?? null),
            'headshot' => (string) JsonFetcher::web($this->section($athlete, 'headshot')['href'] ?? null),
        ];
    }

    /**
     * A nested block of the athlete, or an empty one when the feed left it out.
     *
     * @param array<string, mixed> $athlete
     * @return array<string, mixed>
     */
    private function section(array $athlete, string $key): array
    {
        return is_array($athlete[$key] ?? null) ? $athlete[$key] : [];
    }

    /**
     * ESPN's `experience` block, as the 1–5 the column holds.
     *
     * 🚨 The number, never the display value beside it: "Freshman" is English,
     * and the number is what the locale file turns into a word the reader can
     * read. Null everywhere else, because a professional has no class and a 1
     * stored for one would say "Freshman" under a thirty-year-old.
     */
    private function classYear(mixed $experience): ?int
    {
        if (! is_array($experience)) {
            return null;
        }

        $years = $experience['years'] ?? null;

        if (! is_numeric($years)) {
            return null;
        }

        $years = (int) $years;

        // Anything outside the range is a value this does not understand, and
        // a clamped guess would be a class year invented rather than reported.
        return $years >= 1 && $years <= 5 ? $years : null;
    }

    /**
     * A shirt number, where ZERO is a real one.
     *
     * 🚨 Split from number() because the two mean opposite things by zero. A
     * height of 0 is a missing height; a jersey of 0 is a jersey — legal in
     * college football and increasingly common, and Alabama has worn it. Run
     * through the measurement guard, every player wearing 0 showed up with no
     * number at all, on this port and on the Convoro one before it.
     *
     * Negative is still nothing: it is not a number anybody wears.
     */
    private function jersey(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (int) round((float) $value);

        return $number >= 0 ? $number : null;
    }

    /**
     * A measurement, where zero means the feed did not have one.
     */
    private function number(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (int) round((float) $value);

        return $number > 0 ? $number : null;
    }

    /** ESPN writes a group as "specialTeam" or "Starting Pitchers". */
    private function groupName(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        // camelCase to words, then title case: `specialTeam` → `Special Team`.
        $value = (string) preg_replace('/(?<!^)([A-Z])/', ' $1', $value);

        return mb_substr(ucwords(mb_strtolower(trim($value))), 0, 40);
    }

    /** @param array<string, mixed> $team */
    private function conference(array $team): string
    {
        $groups = is_array($team['groups'] ?? null) ? $team['groups'] : [];

        foreach (['parent', 'group'] as $key) {
            $name = trim((string) ((is_array($groups[$key] ?? null) ? $groups[$key] : [])['name'] ?? ''));

            if ($name !== '') {
                return mb_substr($name, 0, 100);
            }
        }

        return '';
    }

    /** @param array<string, mixed> $team */
    private function logo(array $team, bool $dark): string
    {
        foreach (is_array($team['logos'] ?? null) ? $team['logos'] : [] as $logo) {
            if (! is_array($logo)) {
                continue;
            }

            $isDark = in_array('dark', (array) ($logo['rel'] ?? []), true);

            if ($isDark === $dark) {
                return (string) JsonFetcher::web($logo['href'] ?? null);
            }
        }

        return '';
    }

    /**
     * @param array<string, string> $params
     * @param string $base 🚨 Standings lives under `apis/v2`, not `apis/site/v2`
     * @return array<string, mixed>|null null when nobody answered or the answer was unusable
     */
    private function get(string $path, array $params, string $base = self::BASE): ?array
    {
        return $this->fetcher->get($base.'/'.ltrim($path, '/'), $params, self::TIMEOUT);
    }
}
