<?php

namespace ErnestDefoe\Roster\Api\Controller;

use ErnestDefoe\Roster\Player;
use ErnestDefoe\Roster\Service\Leagues\Leagues;
use ErnestDefoe\Roster\Team;
use Flarum\Api\Exception\ResourceNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** One club and its roster. */
class TeamController implements RequestHandlerInterface
{
    /**
     * 🚨 The gridiron position order, used ONLY for gridiron. Every other sport
     * arrives already grouped by the provider — Pitchers, Centers, Guards — and
     * running a shortstop through a list of football positions returns "other"
     * for every player on the team.
     */
    private const GRIDIRON = [
        'offense' => ['QB', 'RB', 'FB', 'WR', 'TE', 'OL', 'OT', 'OG', 'C'],
        'defense' => ['DL', 'DE', 'DT', 'NT', 'LB', 'ILB', 'OLB', 'EDGE', 'CB', 'S', 'DB', 'FS', 'SS'],
        'specialists' => ['K', 'P', 'LS', 'PK', 'ATH'],
    ];

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $slug = (string) ($request->getQueryParams()['slug'] ?? '');

        $team = Team::query()->where('slug', $slug)->first();

        if ($team === null) {
            throw new ResourceNotFoundException();
        }

        $collegiate = (new Leagues())->get($team->league)->collegiate;

        $players = Player::query()
            ->where('team_id', $team->id)
            ->orderByRaw('jersey IS NULL')
            ->orderBy('jersey')
            ->orderBy('name')
            ->get();

        $grouped = [];

        foreach ($players as $player) {
            $group = trim((string) $player->position_group);

            if ($group === '') {
                $group = $this->gridironGroup((string) $player->position);
            }

            $grouped[$group][] = [
                'id' => (int) $player->id,
                'name' => (string) $player->name,
                'slug' => (string) $player->slug,
                'position' => (string) $player->position,
                'jersey' => $player->jersey,
                'height' => $player->height_label,
                'weight' => $player->weight,
                'hometown' => $player->hometown,
                'classYear' => $player->class_year,
                'photo' => (string) $player->photo_url,
            ];
        }

        $groups = [];

        foreach ($grouped as $name => $rows) {
            $groups[] = ['group' => $name, 'players' => $rows];
        }

        return new JsonResponse([
            'team' => [
                'name' => (string) $team->name,
                'mascot' => (string) $team->mascot,
                'conference' => (string) $team->conference,
                'logo' => (string) $team->logo,
                'color' => (string) $team->color,
                'league' => (string) $team->league,
            ],
            /*
             * 🚨 The client is TOLD whether this is collegiate rather than
             * working it out from the league key. There is no such thing as a
             * sophomore in the NBA, so the class column is not drawn — and an
             * empty column under a heading that means nothing in the sport
             * reads as missing data rather than as a concept the sport lacks.
             */
            'collegiate' => $collegiate,
            'groups' => $groups,
        ]);
    }

    private function gridironGroup(string $position): string
    {
        $position = strtoupper(trim($position));

        foreach (self::GRIDIRON as $group => $positions) {
            if (in_array($position, $positions, true)) {
                return $group;
            }
        }

        return 'other';
    }
}
