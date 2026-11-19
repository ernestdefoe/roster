<?php

namespace ErnestDefoe\Roster\Service;

use ErnestDefoe\Roster\Service\Leagues\Leagues;
use ErnestDefoe\Roster\Team;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The leagues this site actually holds clubs for, in the registry's order.
 *
 * Sent with the forum payload so the index can draw its league switcher
 * without a request of its own. Cached until the sync writes clubs, which is
 * the only thing that can change the answer.
 */
class HeldLeagues
{
    private const KEY = 'ernestdefoe-roster.held-leagues';

    public function __construct(
        protected Leagues $leagues,
        protected Cache $cache,
    ) {
    }

    /** @return list<array{key: string, name: string}> */
    public function all(): array
    {
        $held = $this->cache->rememberForever(self::KEY, fn () => Team::query()->distinct()->pluck('league')->all());

        $out = [];

        foreach ($this->leagues->all() as $key => $league) {
            if (in_array($key, $held, true)) {
                $out[] = ['key' => $key, 'name' => $league->name];
            }
        }

        return $out;
    }

    public function forget(): void
    {
        $this->cache->forget(self::KEY);
    }
}
