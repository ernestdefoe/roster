<?php

namespace ErnestDefoe\Roster\Tests\integration\api;

use Flarum\Testing\integration\TestCase;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
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
            ],
        ]);
    }

    /** Every outbound request answered 404 and recorded, so no test reaches ESPN or Wikipedia. */
    private function offline(): void
    {
        $mock = new MockHandler();
        $handler = HandlerStack::create(function (RequestInterface $request) {
            $this->outbound[] = (string) $request->getUri();

            return \GuzzleHttp\Promise\Create::promiseFor(new Response(404));
        });

        $this->app()->getContainer()->instance(HttpClient::class, new HttpClient(['handler' => $handler]));
    }

    private function json(string $path, array $query = []): array
    {
        $response = $this->send($this->request('GET', $path)->withQueryParams($query));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function the_index_groups_clubs_by_conference_biggest_first()
    {
        [$status, $body] = $this->json('/api/roster/teams');

        $this->assertSame(200, $status);
        $this->assertSame([['key' => 'cfb', 'name' => 'College football'], ['key' => 'nfl', 'name' => 'NFL']], $body['leagues']);
        $this->assertSame('cfb', $body['league']);
        $this->assertSame(['SEC', 'Big Ten', 'Independent'], array_column($body['conferences'], 'conference'));
        $this->assertSame(['alabama', 'auburn'], array_column($body['conferences'][0]['teams'], 'slug'));

        [, $body] = $this->json('/api/roster/teams', ['league' => 'nfl']);
        $this->assertSame('nfl', $body['league']);
        $this->assertSame(['NFC North'], array_column($body['conferences'], 'conference'));

        [, $body] = $this->json('/api/roster/teams', ['league' => 'not-a-league']);
        $this->assertSame('cfb', $body['league'], 'An unknown league falls back to the first one held');
    }

    #[Test]
    public function a_team_page_groups_its_roster_by_side_of_the_ball()
    {
        [$status, $body] = $this->json('/api/roster/team', ['slug' => 'alabama']);

        $this->assertSame(200, $status);
        $this->assertSame('Alabama', $body['team']['name']);
        $this->assertTrue($body['collegiate']);

        $groups = array_column($body['groups'], 'players', 'group');
        $this->assertEqualsCanonicalizing(['offense', 'defense', 'specialists'], array_keys($groups));
        $this->assertSame(['deontae-lawson'], array_column($groups['defense'], 'slug'));
        $this->assertSame(['ty-simpson', 'ryan-williams'], array_column($groups['offense'], 'slug'), 'By jersey number, not name');
        $this->assertSame('6-2', $groups['offense'][0]['height']);
        $this->assertSame('Martin, TN', $groups['offense'][0]['hometown']);
        $this->assertNull($groups['specialists'][0]['jersey']);
    }

    #[Test]
    public function an_unknown_team_or_player_is_not_found()
    {
        $this->offline();

        foreach (['/api/roster/team' => 'nobody', '/api/roster/player' => 'nobody'] as $path => $slug) {
            [$status] = $this->json($path, ['slug' => $slug]);
            $this->assertSame(404, $status, $path);
        }

        $this->assertSame([], $this->outbound);
    }

    #[Test]
    public function a_player_page_carries_the_roster_row_and_the_profile()
    {
        $this->offline();

        [$status, $body] = $this->json('/api/roster/player', ['slug' => 'ty-simpson']);

        $this->assertSame(200, $status);
        $this->assertSame('Ty Simpson', $body['player']['name']);
        $this->assertSame('6-2', $body['player']['height']);
        $this->assertSame('alabama', $body['team']['slug']);
        $this->assertTrue($body['collegiate']);
        $this->assertArrayHasKey('about', $body['profile']);
        $this->assertNotEmpty($this->outbound, 'An uncached player is built from the providers');

        // Built once: the failed answers are cached, so a second view asks nobody.
        $asked = count($this->outbound);
        $this->json('/api/roster/player', ['slug' => 'ty-simpson']);
        $this->assertCount($asked, $this->outbound);
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

        // The budget is counted per clock minute; keep the crawl inside one.
        if (time() % 60 > 50) {
            sleep(61 - time() % 60);
        }

        $builtBy = [];
        for ($id = 10; $id < 20; $id++) {
            $before = count($this->outbound);
            [$status] = $this->json('/api/roster/player', ['slug' => "player-$id"]);
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

        $title = fn (string $path) => preg_match('#<title>(.*?)</title>#s', (string) $this->send($this->request('GET', $path))->getBody(), $m) ? html_entity_decode($m[1]) : null;

        $this->assertStringStartsWith('College football rosters', $title('/roster'));
        $this->assertStringStartsWith('Alabama roster', $title('/roster/alabama'));
        $this->assertStringStartsWith('Ty Simpson — Alabama', $title('/roster/alabama/ty-simpson'));
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
        [$status, $body] = $this->json('/api/roster/team', ['slug' => 'auburn']);
        $playerQueries = array_filter(array_column($db->getQueryLog(), 'query'), fn ($q) => str_contains($q, 'roster_players'));

        $this->assertSame(200, $status);
        $this->assertSame(60, array_sum(array_map(fn ($g) => count($g['players']), $body['groups'])));
        $this->assertCount(1, $playerQueries);
    }
}
