<?php

namespace ErnestDefoe\Roster;

use ErnestDefoe\Roster\Frontend\RosterPageContent;
use ErnestDefoe\Roster\Api\Controller\PlayerController;
use ErnestDefoe\Roster\Api\Controller\TeamController;
use ErnestDefoe\Roster\Api\Controller\TeamsController;
use ErnestDefoe\Roster\Console\SyncCommand;
use ErnestDefoe\Roster\Service\Leagues\Leagues;
use Flarum\Extend;
use Flarum\Frontend\Document;

$extenders = [
    /*
     * 🚨 The routes are registered on the FRONTEND as well as the API. Without
     * the frontend route a visitor who opens /roster directly — from a link, a
     * bookmark, a search result — gets the discussion list instead, and only
     * in-app navigation works.
     */
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/resources/less/forum.less')
        ->route('/roster', 'roster.index', RosterPageContent::class)
        ->route('/roster/{slug}', 'roster.team', RosterPageContent::class)
        ->route('/roster/{slug}/{player}', 'roster.player', RosterPageContent::class),

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
        ->get('/roster/team', 'roster.api.team', TeamController::class)
        ->get('/roster/player', 'roster.api.player', PlayerController::class),

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

/*
 * The crest wall as a Page Builder block — only where Page Builder is present.
 *
 * 🚨 Guarded on the EXTENDER's class, not on the extension being enabled. This
 * file is read at boot, before anything knows which extensions are on, and
 * naming a class from an extension that is not installed is a fatal at compile
 * time rather than a missing block. The block class is never mentioned outside
 * this branch for the same reason: it extends a base class that would not be
 * there to extend.
 */
if (class_exists(\Ernestdefoe\PageBuilder\Extend\PageBuilderBlock::class)) {
    $extenders[] = new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(
        \ErnestDefoe\Roster\Block\CrestWallBlock::class
    );
}

/*
 * Every team's roster page offered to search engines — 136 real pages that were
 * previously reachable only by somebody who already knew they were there.
 *
 * 🚨 Guarded on the EXTENDER's class, and the resource is named only inside the
 * branch, for the same reason as the block above: this file is read at boot,
 * and FoF\Sitemap\Resources\Resource is the PARENT of the class below. Naming
 * it on a site without fof/sitemap is a fatal at load time rather than a
 * missing sitemap entry.
 */
if (class_exists(\FoF\Sitemap\Extend\Sitemap::class)) {
    $extenders[] = (new \FoF\Sitemap\Extend\Sitemap())
        ->addResource(\ErnestDefoe\Roster\Sitemap\TeamPages::class);
}

return $extenders;


