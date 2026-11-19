<?php

namespace ErnestDefoe\Roster;

use ErnestDefoe\Roster\Api\ForumResourceFields;
use ErnestDefoe\Roster\Api\Resource\PlayerResource;
use ErnestDefoe\Roster\Api\Resource\TeamResource;
use ErnestDefoe\Roster\Console\SyncCommand;
use ErnestDefoe\Roster\Frontend\AdminLeagues;
use ErnestDefoe\Roster\Frontend\RosterPageContent;
use Flarum\Api\Resource\ForumResource;
use Flarum\Extend;
use Flarum\Search\Database\DatabaseSearchDriver;

return [
    /*
     * 🚨 The routes are registered on the FRONTEND as well as in the forum's
     * JavaScript. Without the frontend route a visitor who opens /roster
     * directly — from a link, a bookmark, a search result — gets the
     * discussion list instead, and only in-app navigation works.
     */
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The roster pages are their own chunks, loaded only when opened.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/resources/less/forum.less')
        ->route('/roster', 'roster.index', RosterPageContent::class)
        ->route('/roster/{slug}', 'roster.team', RosterPageContent::class)
        ->route('/roster/{slug}/{player}', 'roster.player', RosterPageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->content(AdminLeagues::class),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\ServiceProvider())
        ->register(RosterServiceProvider::class),

    new Extend\ApiResource(TeamResource::class),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addSearcher(Team::class, Search\TeamSearcher::class)
        ->addFilter(Search\TeamSearcher::class, Search\LeagueFilter::class),

    new Extend\ApiResource(PlayerResource::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(ForumResourceFields::class),

    (new Extend\Settings())
        // No league until an administrator ticks one: every league is opt-in.
        ->default('ernestdefoe-roster.leagues', ''),

    (new Extend\Console())
        ->command(SyncCommand::class)
        ->schedule(SyncCommand::class, function ($event) {
            $event->hourly()->withoutOverlapping();
        }),

    /*
     * The crest wall as a Page Builder block, only where Page Builder is on.
     * The block class extends Page Builder's own base class, so nothing names
     * it outside this callback.
     */
    (new Extend\Conditional())
        ->whenExtensionEnabled('ernestdefoe-page-builder', fn () => [
            new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(Block\CrestWallBlock::class),
        ])
        /*
         * Every team's roster page offered to search engines, where fof/sitemap
         * is on. TeamPages extends fof/sitemap's Resource, so nothing names it
         * outside this callback.
         */
        ->whenExtensionEnabled('fof-sitemap', fn () => [
            (new \FoF\Sitemap\Extend\Sitemap())
                ->addResource(Sitemap\TeamPages::class),
        ]),
];
