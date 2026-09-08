<?php

namespace ErnestDefoe\Roster\Api\Controller;

use ErnestDefoe\Roster\Service\Leagues\Leagues;
use ErnestDefoe\Roster\Team;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The index: every club this site holds, grouped.
 *
 * 🚨 Grouped on the SERVER rather than in the browser. The grouping rule —
 * conference, then club name, with conferences ordered by size — is the same
 * rule the Convoro build uses, and a second copy of it in JavaScript is a copy
 * that drifts. The client renders what it is given.
 */
class TeamsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $registry = new Leagues();

        $held = Team::query()
            ->select('league')
            ->distinct()
            ->pluck('league')
            ->all();

        $leagues = [];

        foreach ($registry->all() as $key => $league) {
            if (in_array($key, $held, true)) {
                $leagues[] = ['key' => $key, 'name' => $league->name];
            }
        }

        $wanted = (string) ($request->getQueryParams()['league'] ?? '');
        $keys = array_column($leagues, 'key');
        $wanted = in_array($wanted, $keys, true) ? $wanted : (string) ($keys[0] ?? Leagues::DEFAULT);

        $rows = Team::query()
            ->where('league', $wanted)
            ->orderBy('conference')
            ->orderBy('name')
            ->get();

        $grouped = [];

        foreach ($rows as $team) {
            /*
             * 🚨 "Independent" is a college-football word. A professional club
             * with no division — most of league football, where there is one
             * table and no groups — is not independent of anything, and filing
             * it under that reads as a mistake.
             */
            $key = trim((string) $team->conference);
            $key = $key !== '' ? $key : ($wanted === Leagues::DEFAULT ? 'Independent' : $registry->get($wanted)->name);

            $grouped[$key][] = [
                'id' => (int) $team->id,
                'name' => (string) $team->name,
                'slug' => (string) $team->slug,
                'mascot' => (string) $team->mascot,
                'logo' => (string) $team->logo,
                'color' => (string) $team->color,
            ];
        }

        // Conferences by size, then name — a fixed list of the power leagues is
        // a maintenance problem that comes due every time realignment happens.
        uksort($grouped, function (string $a, string $b) use ($grouped): int {
            return count($grouped[$b]) <=> count($grouped[$a]) ?: strcmp($a, $b);
        });

        $conferences = [];

        foreach ($grouped as $name => $teams) {
            $conferences[] = ['conference' => $name, 'teams' => $teams];
        }

        return new JsonResponse([
            'leagues' => $leagues,
            'league' => $wanted,
            'conferences' => $conferences,
        ]);
    }
}
