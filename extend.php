<?php

namespace ErnestDefoe\Roster;

use ErnestDefoe\Roster\Api\Controller\TeamController;
use ErnestDefoe\Roster\Api\Controller\TeamsController;
use ErnestDefoe\Roster\Console\SyncCommand;
use ErnestDefoe\Roster\Service\Leagues\Leagues;
use Flarum\Extend;
use Flarum\Frontend\Document;

return [
    /*
     * 🚨 The routes are registered on the FRONTEND as well as the API. Without
     * the frontend route a visitor who opens /roster directly — from a link, a
     * bookmark, a search result — gets the discussion list instead, and only
     * in-app navigation works.
     */
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/resources/less/forum.less')
        ->route('/roster', 'roster.index')
        ->route('/roster/{slug}', 'roster.team'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        /*
         * 🚨 The league list reaches the admin from the registry rather than
         * being written into the JavaScript. A second copy of the list in the
         * bundle goes stale the first time an extension registers a league.
         */
        ->content(function (Document $document): void {
            $document->payload['rosterLeagues'] = (new Leagues())->choices();
        }),

    new Extend\Locales(__DIR__ . '/resources/locale'),

    (new Extend\Routes('api'))
        ->get('/roster/teams', 'roster.api.teams', TeamsController::class)
        ->get('/roster/team', 'roster.api.team', TeamController::class),

    (new Extend\Settings())
        /*
         * College football is not in this list and cannot be: it is
         * CollegeFootballData's, and syncing it from ESPN would overwrite a
         * season-by-season history with a current roster.
         */
        ->default('ernestdefoe-roster.leagues', ''),

    (new Extend\Console())
        ->command(SyncCommand::class)
        ->schedule(SyncCommand::class, function ($event) {
            $event->hourly()->withoutOverlapping();
        }),
];
