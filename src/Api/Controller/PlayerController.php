<?php

namespace ErnestDefoe\Roster\Api\Controller;

use ErnestDefoe\Roster\Player;
use ErnestDefoe\Roster\Service\Leagues\Leagues;
use ErnestDefoe\Roster\Service\PlayerProfile;
use Flarum\Api\Exception\ResourceNotFoundException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** One player: what the roster holds, and everything the profile could find. */
class PlayerController implements RequestHandlerInterface
{
    /*
     * 🚨 An uncached player costs up to four outbound calls and 5-7 seconds of
     * a PHP worker. Guests can reach every player's slug from the team pages,
     * so without a budget a crawl ties up every worker the forum has. Cached
     * pages are free and unlimited; only builds count.
     */
    private const BUILDS_PER_IP_PER_MINUTE = 6;
    private const BUILDS_PER_MINUTE = 30;

    public function __construct(
        protected PlayerProfile $profile,
        protected Cache $cache,
    ) {
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
            'profile' => $this->profile->for(
                $player,
                $team,
                $this->profile->isCached($player) || $this->mayBuild((string) $request->getAttribute('ipAddress'))
            ),
        ]);
    }

    /** Spend one uncached build from this visitor's budget and the site's. */
    private function mayBuild(string $ip): bool
    {
        $minute = intdiv(time(), 60);

        return $this->spend('ernestdefoe-roster.builds.ip.' . sha1($ip) . '.' . $minute, self::BUILDS_PER_IP_PER_MINUTE)
            && $this->spend('ernestdefoe-roster.builds.all.' . $minute, self::BUILDS_PER_MINUTE);
    }

    private function spend(string $key, int $limit): bool
    {
        $this->cache->add($key, 0, 120);

        return $this->cache->increment($key) <= $limit;
    }
}
