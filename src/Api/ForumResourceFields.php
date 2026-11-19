<?php

namespace ErnestDefoe\Roster\Api;

use ErnestDefoe\Roster\Service\HeldLeagues;
use Flarum\Api\Schema;

class ForumResourceFields
{
    public function __construct(protected HeldLeagues $held)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Arr::make('rosterLeagues')
                ->get(fn () => $this->held->all()),
        ];
    }
}
