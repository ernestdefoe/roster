<?php

namespace ErnestDefoe\Roster\Tests\integration\api;

use ErnestDefoe\Roster\Job\BuildPlayerProfile;
use Flarum\Testing\integration\TestCase;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;

class RosterTest extends TestCase
{
    /** @var list<string> every outbound URL the player profile asked for */
    private array $outbound = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-roster');

        $this->prepareDatabase([
            'roster_teams' => [
                ['id' => 1, 'league' => 'cfb', 'name' => 'Alabama', 'slug' => 'alabama', 'mascot' => 'Crimson Tide', 'conference' => 'SEC', 'external_id' => '333'],
                ['id' => 2, 'league' => 'cfb', 'name' => 'Auburn', 'slug' => 'auburn', 'mascot' => 'Tigers', 'conference' => 'SEC', 'external_id' => '2'],
                ['id' => 3, 'league' => 'cfb', 'name' => 'Army', 'slug' => 'army', 'mascot' => 'Black Knights', 'conference' => '', 'external_id' => '349'],
                ['id' => 4, 'league' => 'cfb', 'name' => 'Ohio State', 'slug' => 'ohio-state', 'mascot' => 'Buckeyes', 'conference' => 'Big Ten', 'external_id' => '194'],
                ['id' => 5, 'league' => 'nfl', 'name' => 'Chicago Bears', 'slug' => 'chicago-bears', 'conference' => 'NFC North', 'external_id' => '3'],
            ],
            'roster_players' => [
                ['id' => 1, 'team_id' => 1, 'league' => 'cfb', 'name' => 'Ty Simpson', 'slug' => 'ty-simpson', 'position' => 'QB', 'jersey' => 15, 'height' => 74, 'weight' => 208, 'home_city' => 'Martin', 'home_state' => 'TN', 'class_year' => 3, 'external_id' => '4870895'],
                ['id' => 2, 'team_id' => 1, 'league' => 'cfb', 'name' => 'Ryan Williams', 'slug' => 'ryan-williams', 'position' => 'WR', 'jersey' => 20, 'external_id' => '5079424'],
                ['id' => 3, 'team_id' => 1, 'league' => 'cfb', 'name' => 'Walk On', 'slug' => 'walk-on', 'position' => 'LS', 'jersey' => null],
                ['id' => 4, 'team_id' => 1, 'league' => 'cfb', 'name' => 'Deontae Lawson', 'slug' => 'deontae-lawson', 'position' => 'LB', 'jersey' => 0],
                ['id' => 5, 'team_id' => 1, 'league' => 'cfb', 'name' => 'Espn Snapper', 'slug' => 'espn-snapper', 'position' => 'LS', 'position_group' => 'Special Team', 'jersey' => 99],
                ['id' => 6, 'team_id' => 1, 'league' => 'cfb', 'name' => 'Espn Pitcher', 'slug' => 'espn-pitcher', 'position' => 'P', 'position_group' => 'Pitchers', 'jersey' => 98, 'home_city' => 'Lagos', 'home_country' => 'Nigeria'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Every outbound request answered by $answer (404 by default) and recorded, so no test reaches ESPN or Wikipedia. */
    private function offline(?callable $answer = null): void
    {
        $handler = HandlerStack::create(function (RequestInterface $request) use ($answer) {
            $this->outbound[] = (string) $request->getUri();

            return $answer ? $answer($request) : \GuzzleHttp\Promise\Create::promiseFor(new Response(404));
        });

        $this->app()->getContainer()->instance(HttpClient::class, new HttpClient(['handler' => $handler]));
    }

    /**
     * The extension's own strings. Flarum's test harness resolves the locale
     * manager before extensions extend it, so Extend\Locales never reaches it
     * here (it does on a real forum).
     */
    private function withStrings(): void
    {
        $this->app()->getContainer()->make(\Flarum\Locale\LocaleManager::class)
            ->addTranslations('en', __DIR__.'/../../../resources/locale/en.yml');
    }

    private function json(string $path, array $query = []): array
    {
        $response = $this->send($this->request('GET', $path)->withQueryParams($query));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    /** @return array<string, array<string, mixed>> included resources of one type, by slug */
    private function included(array $body, string $type): array
    {
        $out = [];

        foreach ($body['included'] ?? [] as $row) {
            if ($row['type'] === $type) {
                $out[$row['attributes']['slug']] = $row['attributes'];
            }
        }

        return $out;
    }

    #[Test]
    public function the_forum_lists_the_leagues_it_holds_and_the_index_filters_by_one()
    {
        $this->setting('ernestdefoe-roster.leagues', 'nfl,cfb');
        $this->withStrings();

        [, $forum] = $this->json('/api');
        $this->assertSame([['key' => 'cfb', 'name' => 'College football'], ['key' => 'nfl', 'name' => 'NFL']], $forum['data']['attributes']['rosterLeagues'], 'In the registry\'s order');

        [$status, $body] = $this->json('/api/roster-teams', ['filter' => ['league' => 'cfb']]);

        $this->assertSame(200, $status);
        $this->assertSame(['army', 'ohio-state', 'alabama', 'auburn'], array_column(array_column($body['data'], 'attributes'), 'slug'), 'By conference, then name');
        $this->assertSame(['Independent', 'Big Ten', 'SEC', 'SEC'], array_column(array_column($body['data'], 'attributes'), 'group'));
        $this->assertTrue($body['data'][0]['attributes']['collegiate']);

        [, $body] = $this->json('/api/roster-teams', ['filter' => ['league' => 'nfl']]);
        $this->assertSame(['NFC North'], array_column(array_column($body['data'], 'attributes'), 'group'));
        $this->assertFalse($body['data'][0]['attributes']['collegiate']);
    }

    #[Test]
    public function an_unticked_league_leaves_the_switcher()
    {
        $this->setting('ernestdefoe-roster.leagues', 'cfb,mlb');

        [, $forum] = $this->json('/api');

        $this->assertSame([['key' => 'cfb', 'name' => 'College football']], $forum['data']['attributes']['rosterLeagues'], 'NFL clubs are held but not followed; MLB is followed but holds none');
    }

    #[Test]
    public function the_list_cannot_include_every_roster()
    {
        [$status, $body] = $this->json('/api/roster-teams', ['filter' => ['league' => 'cfb'], 'include' => 'players']);

        $this->assertSame(200, $status);
        $this->assertSame([], $this->included($body, 'roster-players'), 'The include is ignored on the list');
        $this->assertArrayNotHasKey('players', $body['data'][0]['relationships'] ?? []);
    }

    #[Test]
    public function a_team_page_carries_its_roster_with_each_players_group()
    {
        [$status, $body] = $this->json('/api/roster-teams/alabama', ['include' => 'players']);

        $this->assertSame(200, $status);
        $this->assertSame('Alabama', $body['data']['attributes']['name']);

        $players = $this->included($body, 'roster-players');
        $this->assertSame('offense', $players['ty-simpson']['positionGroup']);
        $this->assertSame('specialists', $players['espn-snapper']['positionGroup'], "ESPN's own unit name, as the key the locale translates");
        $this->assertSame('Pitchers', $players['espn-pitcher']['positionGroup'], 'Any other sport keeps its own group');
        $this->assertSame('Lagos, Nigeria', $players['espn-pitcher']['hometown'], 'No state: the country stands in');
        $this->assertSame('defense', $players['deontae-lawson']['positionGroup']);
        $this->assertSame('specialists', $players['walk-on']['positionGroup']);
        $this->assertSame('6-2', $players['ty-simpson']['height']);
        $this->assertSame('Martin, TN', $players['ty-simpson']['hometown']);
        $this->assertSame(3, $players['ty-simpson']['classYear']);
        $this->assertSame(0, $players['deontae-lawson']['jersey'], 'Zero is a jersey, not a missing one');
        $this->assertNull($players['walk-on']['jersey']);
        $this->assertArrayNotHasKey('profile', $players['ty-simpson'], 'A roster carries no profiles');
    }

    #[Test]
    public function an_unknown_team_or_player_is_not_found()
    {
        $this->offline();

        foreach (['/api/roster-teams/nobody', '/api/roster-players/nobody'] as $path) {
            [$status] = $this->json($path);
            $this->assertSame(404, $status, $path);
        }

        $this->assertSame([], $this->outbound);
    }

    #[Test]
    public function the_roster_is_hidden_from_guests_who_cannot_view_the_forum()
    {
        $this->database()->table('group_permission')->where('group_id', 2)->where('permission', 'viewForum')->delete();

        foreach (['/api/roster-teams', '/api/roster-teams/alabama', '/api/roster-players/ty-simpson'] as $path) {
            [$status] = $this->json($path);
            $this->assertContains($status, [401, 403], $path);
        }
    }

    #[Test]
    public function a_player_page_carries_the_roster_row_and_the_profile()
    {
        $this->offline();

        [$status, $body] = $this->json('/api/roster-players/ty-simpson', ['include' => 'team']);

        $this->assertSame(200, $status);
        $this->assertSame('Ty Simpson', $body['data']['attributes']['name']);
        $this->assertSame('6-2', $body['data']['attributes']['height']);
        $this->assertArrayHasKey('alabama', $this->included($body, 'roster-teams'));
        $this->assertArrayHasKey('about', $body['data']['attributes']['profile']);
        $this->assertFalse($body['data']['attributes']['profile']['pending'], 'On the sync queue the build has already run');
        $this->assertNotEmpty($this->outbound, 'An uncached player is built from the providers');

        // Built once: the failed answers are cached, so a second view asks nobody.
        $asked = count($this->outbound);
        $this->json('/api/roster-players/ty-simpson');
        $this->assertCount($asked, $this->outbound);
    }

    #[Test]
    public function while_espn_is_down_the_last_good_profile_is_served()
    {
        $this->offline();

        $key = 'ernestdefoe-roster.profile.espn.'.md5('football/college-football|4870895');
        $cache = $this->app()->getContainer()->make(\Illuminate\Contracts\Cache\Repository::class);
        $cache->put($key.'.stale', ['bio' => ['status' => 'Active']], 3600);

        [, $body] = $this->json('/api/roster-players/ty-simpson');

        $this->assertSame(['status' => 'Active'], $body['data']['attributes']['profile']['bio']);
        $this->assertSame(['bio' => ['status' => 'Active']], $cache->get($key), 'The good copy stands in, briefly');
    }

    #[Test]
    public function with_a_queue_worker_the_page_asks_nobody_and_says_the_profile_is_coming()
    {
        $this->offline();

        $pushed = [];
        $queue = new class($pushed) extends NullQueue {
            public function __construct(private array &$pushed)
            {
            }

            public function push($job, $data = '', $queue = null)
            {
                $this->pushed[] = $job;
            }
        };
        $this->app()->getContainer()->instance(Queue::class, $queue);

        [, $body] = $this->json('/api/roster-players/ty-simpson');
        [, $again] = $this->json('/api/roster-players/ty-simpson');

        $this->assertSame([], $this->outbound, 'Nothing outbound inside the request');
        $this->assertTrue($body['data']['attributes']['profile']['pending']);
        $this->assertTrue($again['data']['attributes']['profile']['pending']);
        $this->assertCount(1, $pushed, 'One build queued, however often the page is asked');
        $this->assertInstanceOf(BuildPlayerProfile::class, $pushed[0]);
    }

    #[Test]
    public function a_crawl_of_player_pages_cannot_spend_more_than_the_build_budget()
    {
        $players = [];
        for ($id = 10; $id < 20; $id++) {
            $players[] = ['id' => $id, 'team_id' => 4, 'league' => 'cfb', 'name' => "Player $id", 'slug' => "player-$id", 'position' => 'WR', 'external_id' => (string) (900 + $id)];
        }
        $this->prepareDatabase(['roster_players' => $players]);
        $this->offline();

        // The budget is counted per clock minute; hold the clock inside one.
        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00:05'));

        $builtBy = [];
        for ($id = 10; $id < 20; $id++) {
            $before = count($this->outbound);
            [$status] = $this->json("/api/roster-players/player-$id");
            $this->assertSame(200, $status, 'Over budget, a page is still served, only from the cache');
            $builtBy[$id] = count($this->outbound) > $before;
        }

        $this->assertSame(6, count(array_filter($builtBy)), 'Six uncached builds per visitor per minute');
        $this->assertFalse($builtBy[19]);
    }

    #[Test]
    public function a_roster_page_is_titled_for_search_engines()
    {
        // Debug mode recompiles the forum's JS with source maps on every
        // page render, which outgrows PHP's default memory limit.
        $this->config('debug', false);
        $this->withStrings();

        $title = fn (string $path) => preg_match('#<title>(.*?)</title>#s', (string) $this->send($this->request('GET', $path))->getBody(), $m) ? html_entity_decode($m[1]) : null;

        $this->assertStringStartsWith('Rosters', $title('/roster'));
        $this->assertStringStartsWith('Alabama roster', $title('/roster/alabama'));
        $this->assertStringStartsWith('Ty Simpson — Alabama', $title('/roster/alabama/ty-simpson'));
    }

    #[Test]
    public function the_index_costs_the_same_queries_for_four_clubs_or_forty()
    {
        $count = function (): int {
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            [$status] = $this->json('/api/roster-teams', ['filter' => ['league' => 'cfb']]);
            $this->assertSame(200, $status);

            return count($db->getQueryLog());
        };

        $count(); // the first request also warms settings and caches
        $few = $count();

        $teams = [];
        for ($id = 10; $id < 46; $id++) {
            $teams[] = ['id' => $id, 'league' => 'cfb', 'name' => "Club $id", 'slug' => "club-$id", 'conference' => 'MAC', 'external_id' => (string) $id];
        }
        $this->prepareDatabase(['roster_teams' => $teams]);

        $this->assertSame($few, $count());
    }

    #[Test]
    public function a_big_roster_is_one_query()
    {
        $players = [];
        for ($id = 100; $id < 160; $id++) {
            $players[] = ['id' => $id, 'team_id' => 2, 'league' => 'cfb', 'name' => "Tiger $id", 'slug' => "tiger-$id", 'position' => $id % 2 ? 'QB' : 'LB', 'jersey' => $id - 100];
        }
        $this->prepareDatabase(['roster_players' => $players]);

        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();
        [$status, $body] = $this->json('/api/roster-teams/auburn', ['include' => 'players']);
        $playerQueries = array_filter(array_column($db->getQueryLog(), 'query'), fn ($q) => str_contains($q, 'roster_players'));

        $this->assertSame(200, $status);
        $this->assertCount(60, $this->included($body, 'roster-players'));
        $this->assertCount(1, $playerQueries);
    }
}
