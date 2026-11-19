<?php

namespace ErnestDefoe\Roster\Job;

use ErnestDefoe\Roster\Player;
use ErnestDefoe\Roster\Service\PlayerProfile;
use ErnestDefoe\Roster\Service\ProfileRequests;
use Flarum\Queue\AbstractJob;

/** Fetch one player's profile from ESPN and Wikipedia into the cache. */
class BuildPlayerProfile extends AbstractJob
{
    public function __construct(public int $playerId)
    {
    }

    public function handle(PlayerProfile $profile, ProfileRequests $requests): void
    {
        $player = Player::query()->with('team')->find($this->playerId);

        if ($player !== null && $player->team !== null) {
            $profile->for($player, $player->team, true);
        }

        $requests->built($this->playerId);
    }
}
