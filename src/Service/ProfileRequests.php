<?php

namespace ErnestDefoe\Roster\Service;

use ErnestDefoe\Roster\Job\BuildPlayerProfile;
use ErnestDefoe\Roster\Player;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Carbon;

/**
 * A player page's profile: answered from the cache, built on the queue.
 *
 * 🚨 Building one costs up to seven outbound calls and several seconds, so it
 * is a queued job: on a forum with a queue worker it runs in the background,
 * and the page gets what the cache holds (the fresh copy, else the last good
 * one) plus a `pending` flag, and asks again a few seconds later. On Flarum's
 * default sync queue the job runs before the page is answered.
 */
class ProfileRequests
{
    /*
     * 🚨 Guests can reach every player's slug from the team pages, so a crawl
     * could queue a build for each of fifteen thousand players. Cached pages
     * are free and unlimited; only builds are budgeted.
     */
    private const BUILDS_PER_IP_PER_MINUTE = 6;
    private const BUILDS_PER_MINUTE = 30;

    /** How long a queued build counts as in flight before it may be queued again. */
    private const PENDING_TTL = 300;

    public function __construct(
        protected PlayerProfile $profile,
        protected Cache $cache,
        protected Queue $queue,
    ) {
    }

    /** @return array<string, mixed> */
    public function for(Player $player, string $ip): array
    {
        if ($player->team === null) {
            return ['pending' => false];
        }

        $throttled = false;

        if (! $this->profile->isCached($player)) {
            if ($this->mayBuild($player, $ip)) {
                $this->queue->push(new BuildPlayerProfile((int) $player->id));
            } else {
                // Over budget (or already queued): the page says it is coming
                // and asks again, rather than claiming there is nothing.
                $throttled = true;
            }
        }

        // Read after the push: on the sync queue the build has already run.
        $profile = $this->profile->for($player, $player->team, false);
        $profile['pending'] = ! $this->profile->isCached($player)
            && ($throttled || $this->cache->has($this->pendingKey((int) $player->id)));

        return $profile;
    }

    public function built(int $playerId): void
    {
        $this->cache->forget($this->pendingKey($playerId));
    }

    private function pendingKey(int $playerId): string
    {
        return 'ernestdefoe-roster.profile.pending.'.$playerId;
    }

    /** Spend one build from this visitor's budget and the site's, unless one is already queued. */
    private function mayBuild(Player $player, string $ip): bool
    {
        if ($this->cache->has($this->pendingKey((int) $player->id))) {
            return false;
        }

        $minute = intdiv(Carbon::now()->getTimestamp(), 60);

        return $this->spend('ernestdefoe-roster.builds.ip.'.sha1($ip).'.'.$minute, self::BUILDS_PER_IP_PER_MINUTE)
            && $this->spend('ernestdefoe-roster.builds.all.'.$minute, self::BUILDS_PER_MINUTE)
            && $this->cache->add($this->pendingKey((int) $player->id), 1, self::PENDING_TTL);
    }

    private function spend(string $key, int $limit): bool
    {
        $this->cache->add($key, 0, 120);

        return $this->cache->increment($key) <= $limit;
    }
}
