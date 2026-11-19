<?php

namespace ErnestDefoe\Roster;

use ErnestDefoe\Roster\Service\Leagues\Leagues;
use Flarum\Foundation\AbstractServiceProvider;

class RosterServiceProvider extends AbstractServiceProvider
{
    /**
     * 🚨 One registry for the whole request. A league another extension
     * registers has to be seen by the API, the sync and the admin alike, and a
     * fresh instance per class would each hold only the built-in list.
     */
    public function register(): void
    {
        $this->container->singleton(Leagues::class);
    }
}
