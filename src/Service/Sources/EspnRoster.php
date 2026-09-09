<?php



namespace ErnestDefoe\Roster\Service\Sources;

use GuzzleHttp\Client as HttpClient;

use ErnestDefoe\Roster\Service\Leagues\League;

/**
 * ESPN's team and roster endpoints, for every league that is not college
 * football.
 *
 * 🚨 Two calls' worth of shapes, both read off live responses in September 2026
 * rather than from documentation, because ESPN publishes none for this:
 *
 *     /{sport}/{league}/teams              → sports[0].leagues[0].teams[].team
 *     /{sport}/{league}/teams/{id}/roster  → athletes[]
 *
 * 🚨 And `athletes[]` comes in TWO shapes depending on the sport. The NFL, MLB
 * and the NHL answer GROUPS — `[{position: "offense", items: [...]}]` — and the
 * NBA answers a FLAT list of athletes. Reading only one loses every player in
 * half the leagues, and the failure is silent: an empty roster page.
 *
 * 🚨 No API key, which is why this is the multi-sport source.
 * CollegeFootballData stays where it is used because it carries the recruiting
 * class, the transfer portal and season-by-season statistics that ESPN's roster
 * endpoint does not answer at all — not because ESPN could not list a roster.
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

    /** @var array<string, array<string, string>> league key => id => division */
    private array $divisionCache = [];

    public function __construct(protected HttpClient $http)
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
        if (!$this->supports($league)) {
            return [];
        }

        /*
         * 🚨 PAGED, and a page is capped however large a limit is asked for.
         *
         * College football answers this endpoint with every school ESPN has —
         * upwards of seven hundred, down to Division III — and a single call
         * returned the first two hundred alphabetically. Alabama was not among
         * them. The symptom was a site missing its most famous team while
         * carrying Adams State, and nothing anywhere said the list had been cut.
         */
        $rows = [];

        for ($page = 1; $page <= self::MAX_TEAM_PAGES; $page++) {
            $body = $this->get($league->espnPath . '/teams', ['limit' => '400', 'page' => (string) $page]);

            if ($body === null) {
                break;
            }

            $chunk = $body['sports'][0]['leagues'][0]['teams'] ?? [];

            if (!is_array($chunk) || $chunk === []) {
                break;
            }

            $rows = array_merge($rows, $chunk);

            // A short page is the last page.
            if (count($chunk) < 400) {
                break;
            }
        }

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

        $out = [];

        foreach ($rows as $row) {
            $team = is_array($row) ? ($row['team'] ?? null) : null;

            if (!is_array($team) || ($team['id'] ?? '') === '') {
                continue;
            }

            if ($scope !== [] && !isset($scope[(string) $team['id']])) {
                continue;
            }

            $out[] = [
                'external_id' => (string) $team['id'],
                'school' => (string) ($team['displayName'] ?? ''),
                'mascot' => (string) ($team['name'] ?? ''),
                'slug' => (string) ($team['slug'] ?? ''),
                'abbreviation' => (string) ($team['abbreviation'] ?? ''),
                'color' => (string) ($team['color'] ?? ''),
                'alt_color' => (string) ($team['alternateColor'] ?? ''),
                'logo' => $this->logo($team, false),
                'logo_dark' => $this->logo($team, true),
                /*
                 * 🚨 The division, where ESPN gives one. It is what the index
                 * groups by, and a professional league without it renders as
                 * one long ungrouped list — which is correct for the Premier
                 * League and wrong for the NFL.
                 */
                'conference' => $this->conference($team),
            ];
        }

        return $out;
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
     * @return array<string, string> ESPN team id => division name
     */
    public function divisions(League $league): array
    {
        if (!$this->supports($league)) {
            return [];
        }

        /*
         * Memoised: the club list now asks for this to scope itself, and the
         * sync asks again to label each club. One standings call per league per
         * run, not two identical ones.
         */
        if (isset($this->divisionCache[$league->key])) {
            return $this->divisionCache[$league->key];
        }

        // `level=3` is conference → division → team. Levels 1 and 2 stop short.
        $body = $this->get($league->espnPath . '/standings', ['level' => '3'], 'https://site.api.espn.com/apis/v2/sports');

        if ($body === null) {
            // Not cached: a failed call is worth retrying, unlike an empty answer.
            return [];
        }

        $out = [];

        foreach ((array) ($body['children'] ?? []) as $conference) {
            if (!is_array($conference)) {
                continue;
            }

            /*
             * 🚨 The DIVISION where there is one, the conference where there is
             * not. A league with two levels answers its divisions as children;
             * one with a single level answers its clubs directly, and taking
             * only the children would return nothing for it.
             */
            $groups = (array) ($conference['children'] ?? []);
            $groups = $groups === [] ? [$conference] : $groups;

            foreach ($groups as $group) {
                if (!is_array($group)) {
                    continue;
                }

                $name = mb_substr(trim((string) ($group['name'] ?? '')), 0, 100);

                foreach ((array) (($group['standings'] ?? [])['entries'] ?? []) as $entry) {
                    $id = (string) ((is_array($entry) ? ($entry['team'] ?? []) : [])['id'] ?? '');

                    if ($id !== '' && $name !== '') {
                        $out[$id] = $name;
                    }
                }
            }
        }

        return $this->divisionCache[$league->key] = $out;
    }

    /**
     * One club's roster.
     *
     * @return list<array<string, mixed>>
     */
    public function roster(League $league, string $teamId): array
    {
        if (!$this->supports($league) || $teamId === '') {
            return [];
        }

        $body = $this->get($league->espnPath . '/teams/' . rawurlencode($teamId) . '/roster', []);

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

        if (!is_array($athletes)) {
            return [];
        }

        $out = [];

        foreach ($athletes as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            /*
             * 🚨 The two shapes, told apart by `items` rather than by sport.
             * Keying it off the league would be a list to maintain, and the one
             * ESPN actually varies is the payload.
             */
            if (isset($entry['items']) && is_array($entry['items'])) {
                $group = $this->groupName($entry['position'] ?? '');

                foreach ($entry['items'] as $athlete) {
                    $player = $this->player($athlete, $group);

                    if ($player !== null) {
                        $out[] = $player;
                    }
                }

                continue;
            }

            $player = $this->player($entry, '');

            if ($player !== null) {
                $out[] = $player;
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------- shaping */

    /** @return array<string, mixed>|null */
    private function player(mixed $athlete, string $group): ?array
    {
        if (!is_array($athlete) || ($athlete['id'] ?? '') === '') {
            return null;
        }

        $name = (string) ($athlete['fullName'] ?? $athlete['displayName'] ?? '');

        if (trim($name) === '') {
            return null;
        }

        $birthPlace = is_array($athlete['birthPlace'] ?? null) ? $athlete['birthPlace'] : [];

        return [
            'external_id' => (string) $athlete['id'],
            'name' => $name,
            'first_name' => (string) ($athlete['firstName'] ?? ''),
            'last_name' => (string) ($athlete['lastName'] ?? ''),
            'position' => (string) ((is_array($athlete['position'] ?? null) ? $athlete['position'] : [])['abbreviation'] ?? ''),
            'position_group' => $group !== '' ? $group : $this->groupName(
                (is_array($athlete['position'] ?? null) ? $athlete['position'] : [])['displayName'] ?? ''
            ),
            /*
             * 🚨 Everything below is nullable on purpose. ESPN omits a jersey
             * for an unsigned player, a height for most of soccer, and an age
             * for anybody whose birthday it does not hold — and a zero stored
             * for a missing number is a page that says a player is 0in tall.
             */
            'jersey' => $this->number($athlete['jersey'] ?? null),
            'height' => $this->number($athlete['height'] ?? null),
            'weight' => $this->number($athlete['weight'] ?? null),
            'home_city' => (string) ($birthPlace['city'] ?? ''),
            'home_state' => (string) ($birthPlace['state'] ?? ''),
            'home_country' => (string) ($birthPlace['country'] ?? ''),
            'college' => (string) ((is_array($athlete['college'] ?? null) ? $athlete['college'] : [])['name'] ?? ''),
            /*
             * 🚨 The class, where the sport has one. ESPN sends it as
             * `experience.years` — 1 for a freshman through to 5 — beside a
             * display value this deliberately does not store: "Freshman" is
             * English, and the number is what the locale file turns into a word
             * the reader can read. Null everywhere else, because a professional
             * has no class and a 1 stored for one would say "Freshman" under a
             * thirty-year-old.
             */
            'class_year' => $this->classYear($athlete['experience'] ?? null),
            'headshot' => (string) ((is_array($athlete['headshot'] ?? null) ? $athlete['headshot'] : [])['href'] ?? ''),
        ];
    }

    /** ESPN's `experience` block, as the 1–5 the column holds. */
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

    private function number(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
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
            if (!is_array($logo)) {
                continue;
            }

            $isDark = in_array('dark', (array) ($logo['rel'] ?? []), true);

            if ($isDark === $dark) {
                return (string) ($logo['href'] ?? '');
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
        /*
         * 🚨 A User-Agent, because ESPN answers 403 to a request that sends
         * none. Guzzle sends its own by default — unlike PHP's bare cURL, which
         * sends nothing unless told — but it is set explicitly so the reason
         * survives a refactor. That trap cost the Convoro build of this an
         * afternoon and Picks a whole season of live scores.
         */
        $response = $this->http->get($base . '/' . ltrim($path, '/'), [
            'query' => $params,
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'curl/8',
            ],
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = json_decode((string) $response->getBody(), true);

        return is_array($body) ? $body : null;
    }
}
