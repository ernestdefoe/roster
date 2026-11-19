<?php

namespace ErnestDefoe\Roster\Search;

use ErnestDefoe\Roster\Team;
use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/** Backs `GET /api/roster-teams`. Clubs are public reference data: every club is listed. */
class TeamSearcher extends AbstractSearcher
{
    /** @return Builder<Team> */
    public function getQuery(User $actor): Builder
    {
        return Team::query();
    }
}
