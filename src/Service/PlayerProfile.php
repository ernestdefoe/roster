<?php

namespace ErnestDefoe\Roster\Service;

use ErnestDefoe\Roster\Player;
use ErnestDefoe\Roster\Service\Leagues\Leagues;
use ErnestDefoe\Roster\Team;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Everything worth knowing about one player, gathered from outside and cached.
 *
 * The roster table only holds what a roster carries — name, number, position,
 * size, hometown. A player page wants the rest: the bio ESPN keeps, his career
 * and recent games, his next game, the clips and stories ESPN has about him,
 * and a Wikipedia summary when he is notable enough to have one.
 *
 * 🚨 Cached hard, and per player. A team page lists ~110 players and a busy
 * season puts hundreds of visitors on them; one outbound request per view is
 * how a forum takes down its own host and gets its address blocked. Six hours
 * for a good answer, fifteen minutes for a failed one, so a provider outage is
 * retried soon without being hammered.
 */
class PlayerProfile
{
    private const ESPN = 'https://site.web.api.espn.com/apis/common/v3/sports';
    private const WIKI = 'https://en.wikipedia.org';
    private const TIMEOUT = 6;
    private const TTL = 6 * 3600;
    private const TTL_FAILED = 15 * 60;
    private const WIKI_TTL = 7 * 86400;
    /** How long a profile stays servable after it goes stale (see $mayFetch). */
    private const STALE_TTL = 14 * 86400;

    public function __construct(
        protected HttpClient $http,
        protected Cache $cache,
    ) {
    }

    /**
     * True when this player's page can be answered from the cache alone, so
     * building it costs no outbound request.
     */
    public function isCached(Player $player): bool
    {
        $league = (new Leagues())->get($player->league);

        if ($league->provider === 'espn' && $league->espnPath !== '' && $player->external_id
            && ! is_array($this->cache->get($this->espnKey($league->espnPath, (string) $player->external_id)))) {
            return false;
        }

        return $this->cache->has('ernestdefoe-roster.profile.wiki.'.$player->id);
    }

    /**
     * 🚨 $mayFetch = false answers from the cache only: the fresh copy, else
     * the last good one (kept two weeks), else nothing. That is what a page
     * gets while the uncached-build budget is spent, so a crawl walking every
     * player cannot hold PHP workers on ESPN and Wikipedia; a page shown in
     * that window simply has fewer sections until the next visit.
     *
     * @return array<string, mixed>
     */
    public function for(Player $player, Team $team, bool $mayFetch): array
    {
        $league = (new Leagues())->get($player->league);

        $espn = [];
        if ($league->provider === 'espn' && $league->espnPath !== '' && $player->external_id) {
            $espn = $this->espn($league->espnPath, (string) $player->external_id, $mayFetch);
        }

        foreach (['videos', 'news'] as $list) {
            if (isset($espn[$list])) {
                $espn[$list] = $this->about((string) $player->name, $espn[$list]);
            }
        }

        return $espn + [
            'about' => $this->wikipedia((string) $player->name, $team, (string) $player->id, $mayFetch),
        ];
    }

    /**
     * Only the items that are actually about him.
     *
     * 🚨 ESPN pads a player's news and clips with whatever college football is
     * talking about that day. A walk-on long snapper and a backup linebacker
     * came back with the SAME six stories — a South Carolina quarterback, an
     * Idaho upset — and even a Heisman candidate's list carried a studio show
     * about other people's games. A story that never names him is not his.
     * Matched on the surname as a whole word, in the headline or the summary.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function about(string $name, array $items): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        // "Jr.", "III" and the like are not the name anybody writes.
        $parts = array_values(array_filter($parts, fn ($p) => ! preg_match('/^(jr\.?|sr\.?|ii|iii|iv|v)$/i', $p)));
        $surname = end($parts) ?: '';

        if ($surname === '' || mb_strlen($surname) < 2) {
            return [];
        }

        $pattern = '/\b'.preg_quote($surname, '/').'\b/iu';

        return array_values(array_filter($items, fn ($item) => preg_match($pattern, ($item['title'] ?? '').' '.($item['description'] ?? ''))));
    }

    private function espnKey(string $path, string $id): string
    {
        return 'ernestdefoe-roster.profile.espn.'.md5($path.'|'.$id);
    }

    private function espn(string $path, string $id, bool $mayFetch): array
    {
        $key = $this->espnKey($path, $id);

        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        if (! $mayFetch) {
            $stale = $this->cache->get($key.'.stale');

            return is_array($stale) ? $stale : [];
        }

        $base = self::ESPN.'/'.$path.'/athletes/'.rawurlencode($id);

        $athlete = $this->json($base);
        $overview = $this->json($base.'/overview');

        if ($athlete === null && $overview === null) {
            $this->cache->put($key, [], self::TTL_FAILED);

            return [];
        }

        $out = [
            'bio' => $this->bio($athlete['athlete'] ?? []),
            'stats' => $this->stats($overview['statistics'] ?? null),
            'games' => $this->games($overview['gameLog'] ?? null),
            'nextGame' => $this->nextGame($overview['nextGame'] ?? null),
            'videos' => $this->videos($overview['news'] ?? []),
            'news' => $this->news($overview['news'] ?? []),
            'espnUrl' => $this->espnLink($athlete['athlete']['links'] ?? []),
        ];

        $this->cache->put($key, $out, self::TTL);
        $this->cache->put($key.'.stale', $out, self::STALE_TTL);

        return $out;
    }

    /** @return array<string, mixed> */
    private function bio(array $a): array
    {
        return array_filter([
            'headshot' => self::web($a['headshot']['href'] ?? null),
            'jersey' => $a['displayJersey'] ?? null,
            'position' => $a['position']['displayName'] ?? null,
            'height' => $a['displayHeight'] ?? null,
            'weight' => $a['displayWeight'] ?? null,
            'experience' => $a['displayExperience'] ?? null,
            'birthPlace' => $a['displayBirthPlace'] ?? null,
            'age' => $a['age'] ?? null,
            'status' => $a['status']['name'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Career numbers by season.
     *
     * 🚨 One label row for every category together. ESPN's career table for a
     * quarterback runs passing straight into rushing, so "YDS" appears twice;
     * the long names travel with the short ones so the two can be told apart.
     *
     * @return array<string, mixed>|null
     */
    private function stats(mixed $s): ?array
    {
        if (! is_array($s) || empty($s['labels']) || empty($s['splits'])) {
            return null;
        }

        return [
            'title' => (string) ($s['displayName'] ?? ''),
            'labels' => array_values($s['labels']),
            'names' => array_values($s['displayNames'] ?? []),
            'rows' => array_values(array_map(fn ($split) => [
                'label' => (string) ($split['displayName'] ?? ''),
                'values' => array_values($split['stats'] ?? []),
            ], array_slice($s['splits'], 0, 8))),
        ];
    }

    /**
     * The last few games, newest first.
     *
     * @return array<string, mixed>|null
     */
    private function games(mixed $g): ?array
    {
        if (! is_array($g) || empty($g['statistics'][0]['events']) || empty($g['events'])) {
            return null;
        }

        $table = $g['statistics'][0];
        $events = $g['events'];
        $rows = [];

        foreach (array_slice($table['events'], 0, 6) as $line) {
            $event = $events[$line['eventId'] ?? ''] ?? null;
            if ($event === null) {
                continue;
            }

            $rows[] = [
                'date' => $event['gameDate'] ?? null,
                'atVs' => $event['atVs'] ?? '',
                'opponent' => $event['opponent']['abbreviation'] ?? ($event['opponent']['displayName'] ?? ''),
                'opponentLogo' => self::web($event['opponent']['logo'] ?? null),
                'result' => $event['gameResult'] ?? '',
                'score' => $event['score'] ?? '',
                'values' => array_values($line['stats'] ?? []),
            ];
        }

        return $rows === [] ? null : [
            'labels' => array_values($table['labels'] ?? []),
            'names' => array_values($table['displayNames'] ?? []),
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed>|null */
    private function nextGame(mixed $n): ?array
    {
        $event = $n['league']['events'][0] ?? null;
        if (! is_array($event)) {
            return null;
        }

        $name = $event['shortName'] ?? ($event['name'] ?? null);

        return $name ? ['name' => (string) $name, 'date' => $event['date'] ?? null] : null;
    }

    /**
     * ESPN's clips about him, to be played in ESPN's own syndicated player.
     *
     * 🚨 The PLAYER, never the video file. ESPN's API hands out the raw MP4 and
     * HLS addresses too, and a bare <video> tag would play them — but that is
     * ESPN's footage without ESPN's player, branding or ads, and the file
     * addresses change without notice. The syndicated player is the one ESPN
     * offers for embedding elsewhere, and it frames on another site.
     *
     * @return list<array<string, mixed>>
     */
    private function videos(array $news): array
    {
        $out = [];

        foreach ($news as $item) {
            if (($item['type'] ?? '') !== 'Media') {
                continue;
            }

            $web = (string) ($item['links']['web']['href'] ?? '');
            $api = (string) ($item['links']['api']['self']['href'] ?? '');

            // The clip id: in the API link (/clips/50035665) or the page's (/id/50035665).
            if (! preg_match('#/clips/(\d+)#', $api, $m) && ! preg_match('#/id/(\d+)#', $web, $m)) {
                continue;
            }

            $out[] = [
                'id' => $m[1],
                'title' => (string) ($item['headline'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'image' => self::web($item['images'][0]['url'] ?? null),
                'published' => $item['published'] ?? ($item['lastModified'] ?? null),
                'url' => self::web($web),
            ];

            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function news(array $news): array
    {
        $out = [];

        foreach ($news as $item) {
            if (($item['type'] ?? '') === 'Media') {
                continue;
            }

            $url = self::web($item['links']['web']['href'] ?? null);
            if (! $url || empty($item['headline'])) {
                continue;
            }

            $out[] = [
                'title' => (string) $item['headline'],
                'description' => (string) ($item['description'] ?? ''),
                'image' => self::web($item['images'][0]['url'] ?? null),
                'published' => $item['published'] ?? ($item['lastModified'] ?? null),
                'url' => $url,
            ];

            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }

    private function espnLink(array $links): ?string
    {
        foreach ($links as $link) {
            if (in_array('playercard', $link['rel'] ?? [], true) && ! empty($link['href'])) {
                return self::web($link['href']);
            }
        }

        return self::web($links[0]['href'] ?? null);
    }

    /**
     * A Wikipedia summary — only when it is certainly him.
     *
     * 🚨 A name is not a person. There are several Jeremiah Smiths on rosters
     * this season and more on Wikipedia, and a confident paragraph about the
     * wrong man is worse than no paragraph. An article is only accepted when
     * its title is the player's name (or "Name (American football)"-style) AND
     * its summary names his school AND says football. Anything less, and the
     * page simply has no "About" section.
     *
     * @return array<string, mixed>|null
     */
    private function wikipedia(string $name, Team $team, string $playerId, bool $mayFetch): ?array
    {
        $key = 'ernestdefoe-roster.profile.wiki.'.$playerId;

        if ($this->cache->has($key)) {
            $hit = $this->cache->get($key);

            return is_array($hit) ? $hit : null;
        }

        if (! $mayFetch) {
            return null;
        }

        $result = $this->findWikipedia($name, $team);

        // 🚨 A miss is cached as well — as false, so has() still sees it. Most
        // players have no article, and asking again on every view would make
        // Wikipedia the busiest thing a roster page does.
        $this->cache->put($key, $result ?? false, self::WIKI_TTL);

        return $result;
    }

    private function findWikipedia(string $name, Team $team): ?array
    {
        $school = trim((string) $team->name);
        if ($name === '' || $school === '') {
            return null;
        }

        $search = $this->json(self::WIKI.'/w/api.php', [
            'action' => 'query',
            'list' => 'search',
            'srsearch' => '"'.$name.'" '.$school.' football',
            'srlimit' => 4,
            'format' => 'json',
        ], true);

        foreach ($search['query']['search'] ?? [] as $hit) {
            $title = (string) ($hit['title'] ?? '');
            $bare = trim(preg_replace('/\s*\(.*\)$/', '', $title));

            if (strcasecmp($bare, $name) !== 0) {
                continue;
            }

            $summary = $this->json(self::WIKI.'/api/rest_v1/page/summary/'.rawurlencode(str_replace(' ', '_', $title)), [], true);
            $extract = (string) ($summary['extract'] ?? '');

            if ($extract === '' || ($summary['type'] ?? '') === 'disambiguation') {
                continue;
            }

            if (stripos($extract, $school) === false || stripos($extract, 'football') === false) {
                continue;
            }

            return [
                'title' => $title,
                'extract' => $extract,
                'url' => self::web($summary['content_urls']['desktop']['page'] ?? null) ?? (self::WIKI.'/wiki/'.rawurlencode(str_replace(' ', '_', $title))),
                'image' => self::web($summary['thumbnail']['source'] ?? null),
            ];
        }

        return null;
    }

    /**
     * A link or image from a feed, only if it is plain http(s). These land in
     * href and src on the player page, so a feed that ever carried a
     * javascript: or data: address must not pass it through.
     */
    private static function web(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https?://[^\s"\'<>]+$#i', $url) ? $url : null;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    private function json(string $url, array $query = [], bool $wikipedia = false): ?array
    {
        try {
            $response = $this->http->get($url, [
                'query' => $query,
                'timeout' => self::TIMEOUT,
                'connect_timeout' => 4,
                'http_errors' => false,
                'headers' => [
                    'Accept' => 'application/json',
                    /*
                     * 🚨 Two different rules. ESPN answers 403 to a CUSTOM user
                     * agent and accepts curl's; Wikipedia asks every client to
                     * identify itself with a way to reach its author, and
                     * throttles anonymous ones.
                     */
                    'User-Agent' => $wikipedia
                        ? 'FlarumRoster/1.2 (https://github.com/ernestdefoe/roster)'
                        : 'curl/8',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = json_decode((string) $response->getBody(), true);

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }
}
