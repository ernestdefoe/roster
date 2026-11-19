<?php

namespace ErnestDefoe\Roster\Service;

use ErnestDefoe\Roster\Service\Leagues\Leagues;
use ErnestDefoe\Roster\Team;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The leagues this site follows, and those it actually holds clubs for.
 *
 * The held list is sent with the forum payload so the index can draw its
 * league switcher without a request of its own. Which leagues have clubs is
 * cached until the sync writes clubs; which are followed is read each time,
 * so a league an administrator unticks leaves the switcher at once.
 */
class HeldLeagues
{
    private const KEY = 'ernestdefoe-roster.held-leagues';

    public function __construct(
        protected Leagues $leagues,
        protected Cache $cache,
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    /** @return list<array{key: string, name: string}> followed leagues with clubs, in the registry's order */
    public function all(): array
    {
        $held = $this->cache->rememberForever(self::KEY, fn () => Team::query()->distinct()->pluck('league')->all());
        $following = $this->following();

        $out = [];

        foreach ($this->leagues->all() as $key => $league) {
            if (in_array($key, $held, true) && in_array($key, $following, true)) {
                $out[] = ['key' => $key, 'name' => $league->name];
            }
        }

        return $out;
    }

    /** @return list<string> the league keys an administrator ticked, known to the registry */
    public function following(): array
    {
        $out = [];

        foreach (explode(',', (string) $this->settings->get('ernestdefoe-roster.leagues')) as $key) {
            $key = trim($key);

            if ($key !== '' && $this->leagues->has($key)) {
                $out[$key] = $key;
            }
        }

        return array_values($out);
    }

    public function forget(): void
    {
        $this->cache->forget(self::KEY);
    }
}
