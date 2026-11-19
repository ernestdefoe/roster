<?php

namespace ErnestDefoe\Roster\Frontend;

use ErnestDefoe\Roster\Service\Leagues\Leagues;
use Flarum\Frontend\Document;

/**
 * 🚨 The league list reaches the admin from the registry rather than being
 * written into the JavaScript. A second copy of the list in the bundle goes
 * stale the first time an extension registers a league.
 */
class AdminLeagues
{
    public function __construct(protected Leagues $leagues)
    {
    }

    public function __invoke(Document $document): void
    {
        $document->payload['rosterLeagues'] = $this->leagues->choices();
    }
}
