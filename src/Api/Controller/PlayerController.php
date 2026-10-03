<?php

namespace ErnestDefoe\Roster\Api\Controller;

use ErnestDefoe\Roster\Player;
use ErnestDefoe\Roster\Service\Leagues\Leagues;
use ErnestDefoe\Roster\Service\PlayerProfile;
use Flarum\Api\Exception\ResourceNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** One player: what the roster holds, and everything the profile could find. */
class PlayerController implements RequestHandlerInterface
{
    public function __construct(protected PlayerProfile $profile)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $slug = (string) ($request->getQueryParams()['slug'] ?? '');

        $player = Player::query()->with('team')->where('slug', $slug)->first();

        if ($player === null || $player->team === null) {
            throw new ResourceNotFoundException();
        }

        $team = $player->team;

        return new JsonResponse([
            'player' => [
                'name' => (string) $player->name,
                'slug' => (string) $player->slug,
                'position' => (string) $player->position,
                'jersey' => $player->jersey,
                'height' => $player->height_label,
                'weight' => $player->weight,
                'hometown' => $player->hometown,
                'classYear' => $player->class_year,
                'photo' => (string) $player->photo_url,
            ],
            'team' => [
                'name' => (string) $team->name,
                'slug' => (string) $team->slug,
                'mascot' => (string) $team->mascot,
                'logo' => (string) $team->logo,
                'color' => (string) $team->color,
            ],
            'collegiate' => (new Leagues())->get($team->league)->collegiate,
            'profile' => $this->profile->for($player, $team),
        ]);
    }
}
