<?php

namespace ErnestDefoe\Roster\Service\Leagues;

/**
 * One competition this extension can hold rosters for.
 */
class League
{
    public function __construct(
        /** The stored key. Goes in `roster_teams.league` and never changes. */
        public readonly string $key,
        /** What a reader sees. */
        public readonly string $name,
        /** Which source answers for it. Every built-in league is `espn`. */
        public readonly string $provider,
        /** ESPN's own path — `football/nfl`. Empty where no ESPN endpoint covers it. */
        public readonly string $espnPath = '',
        /**
         * 🚨 Whether this league has college classes, freshman to super
         * senior. Professional sport has none, and an empty "Class" column
         * under every NBA roster would read as missing data rather than as a
         * concept the sport lacks.
         */
        public readonly bool $collegiate = false,
        /** The sport's word as Wikipedia writes it, to tell his article from a namesake's. */
        public readonly string $sport = '',
    ) {
    }
}
