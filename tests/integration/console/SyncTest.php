<?php

namespace ErnestDefoe\Roster\Tests\integration\console;

use ErnestDefoe\Roster\Service\RosterSync;
use Flarum\Testing\integration\TestCase;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;

class SyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-roster');
        $this->setting('ernestdefoe-roster.leagues', 'nfl');
    }

    /** ESPN, answered from $routes by path suffix; anything else is a 404. */
    private function espn(callable|array $routes): void
    {
        $handler = HandlerStack::create(function (RequestInterface $request) use ($routes) {
            if (is_callable($routes)) {
                return $routes($request);
            }

            foreach ($routes as $suffix => $body) {
                if (str_ends_with($request->getUri()->getPath(), $suffix)) {
                    return Create::promiseFor(new Response(200, [], json_encode($body)));
                }
            }

            return Create::promiseFor(new Response(404));
        });

        $this->app()->getContainer()->instance(HttpClient::class, new HttpClient(['handler' => $handler]));
    }

    private function sync(): array
    {
        return $this->app()->getContainer()->make(RosterSync::class)->run();
    }

    private function team(string $id, string $name): array
    {
        return ['team' => ['id' => $id, 'displayName' => $name, 'name' => 'Mascot', 'slug' => strtolower(str_replace(' ', '-', $name)), 'abbreviation' => 'X', 'color' => '000000', 'logos' => []]];
    }

    private function athlete(string $id, string $name, int $years): array
    {
        return ['id' => $id, 'fullName' => $name, 'position' => ['abbreviation' => 'QB', 'displayName' => 'Quarterback'], 'jersey' => '0', 'experience' => ['years' => $years]];
    }

    #[Test]
    public function a_network_failure_is_an_empty_answer_not_a_crashed_sync()
    {
        $this->espn(fn (RequestInterface $request) => throw new ConnectException('Could not resolve host', $request));

        $this->assertSame(['leagues' => 1, 'teams' => 0, 'rosters' => 0, 'players' => 0], $this->sync());
    }

    #[Test]
    public function clubs_and_rosters_are_written_in_one_statement_each_and_updated_in_place()
    {
        $this->espn([
            '/football/nfl/teams' => ['sports' => [['leagues' => [['teams' => [$this->team('3', 'Chicago Bears'), $this->team('8', 'Detroit Lions')]]]]]],
            '/football/nfl/standings' => ['children' => [['name' => 'NFC', 'children' => [['name' => 'NFC North', 'standings' => ['entries' => [['team' => ['id' => '3']], ['team' => ['id' => '8']]]]]]]]],
            '/teams/3/roster' => ['athletes' => [['position' => 'offense', 'items' => [$this->athlete('77', 'Caleb Williams', 2), $this->athlete('77', 'Caleb Williams', 2)]]]],
            '/teams/8/roster' => ['athletes' => [$this->athlete('88', 'Jared Goff', 5)]],
        ]);

        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();

        $this->assertSame(['leagues' => 1, 'teams' => 2, 'rosters' => 2, 'players' => 2], $this->sync());

        $writes = array_filter(array_column($db->getQueryLog(), 'query'), fn ($q) => preg_match('/^insert into .?\w*roster_players/i', $q));
        $this->assertCount(2, $writes, 'One statement per roster');

        $caleb = $db->table('roster_players')->where('external_id', '77')->first();
        $this->assertSame('offense', strtolower((string) $caleb->position_group));
        $this->assertSame(0, (int) $caleb->jersey);
        $this->assertSame(2, (int) $caleb->class_year, 'The class year is stored');
        $this->assertSame('NFC North', $db->table('roster_teams')->where('external_id', '3')->value('conference'));

        // A second run updates rows in place; slugs never change.
        $slug = $db->table('roster_teams')->where('external_id', '3')->value('slug');
        $db->table('roster_teams')->update(['roster_at' => null]);
        $this->sync();

        $this->assertSame(2, $db->table('roster_teams')->count());
        $this->assertSame(2, $db->table('roster_players')->count());
        $this->assertSame($slug, $db->table('roster_teams')->where('external_id', '3')->value('slug'));
    }

    #[Test]
    public function a_failed_standings_call_keeps_the_league_as_it_is()
    {
        $this->espn([
            '/football/nfl/teams' => ['sports' => [['leagues' => [['teams' => [$this->team('3', 'Chicago Bears')]]]]]],
        ]);

        $this->sync();

        $this->assertSame(0, $this->database()->table('roster_teams')->count(), 'No club taken unscoped from a league whose divisions are unknown');
    }

    #[Test]
    public function clubs_and_players_the_league_no_longer_lists_leave_it()
    {
        $standings = ['children' => [['name' => 'NFC North', 'standings' => ['entries' => [['team' => ['id' => '3']], ['team' => ['id' => '8']]]]]]];
        $this->espn([
            '/football/nfl/teams' => ['sports' => [['leagues' => [['teams' => [$this->team('3', 'Chicago Bears'), $this->team('8', 'Detroit Lions')]]]]]],
            '/football/nfl/standings' => $standings,
            '/teams/3/roster' => ['athletes' => [$this->athlete('77', 'Caleb Williams', 2), $this->athlete('78', 'Cut Player', 1)]],
            '/teams/8/roster' => ['athletes' => [$this->athlete('88', 'Jared Goff', 5)]],
        ]);
        $this->sync();
        $this->assertSame(3, $this->database()->table('roster_players')->count());

        $db = $this->database();
        $db->table('roster_teams')->update(['roster_at' => null]);
        // A day later: the club list is due again.
        $this->app()->getContainer()->make(\Illuminate\Contracts\Cache\Repository::class)->forget('ernestdefoe-roster.clubs-fetched.nfl');
        $this->espn([
            '/football/nfl/teams' => ['sports' => [['leagues' => [['teams' => [$this->team('3', 'Chicago Bears')]]]]]],
            '/football/nfl/standings' => $standings,
            '/teams/3/roster' => ['athletes' => [$this->athlete('77', 'Caleb Williams', 2)]],
        ]);
        $this->sync();

        $this->assertSame(['3'], $db->table('roster_teams')->pluck('external_id')->all());
        $this->assertSame(['77'], $db->table('roster_players')->pluck('external_id')->all());
    }

    #[Test]
    public function an_unticked_leagues_clubs_leave_with_it()
    {
        $this->prepareDatabase([
            'roster_teams' => [
                ['id' => 1, 'league' => 'cfb', 'name' => 'Alabama', 'slug' => 'cfb-alabama', 'external_id' => '333'],
                ['id' => 2, 'league' => 'nfl', 'name' => 'Bears', 'slug' => 'nfl-bears', 'external_id' => '3', 'roster_at' => date('Y-m-d H:i:s')],
            ],
            'roster_players' => [
                ['id' => 1, 'team_id' => 1, 'league' => 'cfb', 'name' => 'Ty Simpson', 'slug' => 'cfb-ty-simpson-1', 'external_id' => '1'],
            ],
        ]);
        $this->espn([]);

        $this->sync();

        $this->assertSame(['nfl'], $this->database()->table('roster_teams')->pluck('league')->all(), 'Only NFL is followed');
        $this->assertSame(0, $this->database()->table('roster_players')->count());
    }

    #[Test]
    public function a_leagues_club_list_is_fetched_once_a_day_and_again_after_a_failure()
    {
        $asked = 0;
        $this->espn(function (RequestInterface $request) use (&$asked) {
            if (str_ends_with($request->getUri()->getPath(), '/football/nfl/teams')) {
                $asked++;
            }

            return Create::promiseFor(new Response(503));
        });

        $this->sync();
        $this->sync();
        $this->assertSame(2, $asked, 'A failed club list is asked for again on the next run');

        $this->espn([
            '/football/nfl/teams' => ['sports' => [['leagues' => [['teams' => [$this->team('3', 'Chicago Bears')]]]]]],
            '/football/nfl/standings' => ['children' => [['name' => 'NFC North', 'standings' => ['entries' => [['team' => ['id' => '3']]]]]]],
        ]);
        $this->assertSame(1, $this->sync()['teams']);
        $this->assertSame(0, $this->sync()['teams'], 'Fresh for a day');
    }

    #[Test]
    public function a_league_ticked_again_is_fetched_on_the_next_run()
    {
        $this->espn([
            '/football/nfl/teams' => ['sports' => [['leagues' => [['teams' => [$this->team('3', 'Chicago Bears')]]]]]],
            '/football/nfl/standings' => ['children' => [['name' => 'NFC North', 'standings' => ['entries' => [['team' => ['id' => '3']]]]]]],
        ]);
        $settings = $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class);

        $this->assertSame(1, $this->sync()['teams']);

        $settings->set('ernestdefoe-roster.leagues', 'cfb');
        $this->sync();
        $this->assertSame(0, $this->database()->table('roster_teams')->where('league', 'nfl')->count());

        $settings->set('ernestdefoe-roster.leagues', 'nfl');
        $this->assertSame(1, $this->sync()['teams']);
    }

    #[Test]
    public function never_fetched_clubs_come_before_stale_ones()
    {
        $this->prepareDatabase([
            'roster_teams' => [
                ['id' => 1, 'league' => 'nfl', 'name' => 'Stale', 'slug' => 'nfl-stale', 'external_id' => '1', 'roster_at' => '2020-01-01 00:00:00'],
                ['id' => 2, 'league' => 'nfl', 'name' => 'Never', 'slug' => 'nfl-never', 'external_id' => '2', 'roster_at' => null],
                ['id' => 3, 'league' => 'nfl', 'name' => 'Fresh', 'slug' => 'nfl-fresh', 'external_id' => '3', 'roster_at' => date('Y-m-d H:i:s')],
            ],
        ]);

        $asked = [];
        $this->espn(function (RequestInterface $request) use (&$asked) {
            if (preg_match('#/teams/(\d+)/roster#', $request->getUri()->getPath(), $m)) {
                $asked[] = $m[1];
            }

            return Create::promiseFor(new Response(404));
        });

        $this->sync();

        $this->assertSame(['2', '1'], $asked);
    }
}
