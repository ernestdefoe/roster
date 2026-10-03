<?php

namespace ErnestDefoe\Roster\Frontend;

use ErnestDefoe\Roster\Team;
use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A real title and description for the roster pages, written server side.
 *
 * 🚨 Without this every one of them is titled with the forum's own name.
 * Measured on fbsfb: `/roster`, `/picks`, `/fantasy` and `/gallery` all answered
 * 200 with `<title>FBSFB</title>` — and `/roster/{slug}` is 136 separate team
 * pages, every one of them indistinguishable from the others to a search
 * engine, and from the site's front page.
 *
 * 🚨 On the SERVER's document, not in the browser. Mithril sets the title after
 * the page loads, which is right for a reader and invisible to a crawler: what
 * gets indexed is what came down the wire.
 */
class RosterPageContent
{
    /**
     * 🚨 The TITLE only. The description is fof/seo's to write.
     *
     * Setting one here left TWO `<meta name="description">` tags on the page —
     * measured on the demo, both served — because fof/seo appends the
     * forum-wide one AFTER a route's own content callable runs, so filtering
     * first removes nothing. A crawler then picks one, usually the first, and
     * the specific description loses to the generic one silently.
     *
     * A per-page description belongs in a fof/seo page driver
     * (`FoF\Seo\Extend\SEO::addExtender`), which is a larger piece of work and
     * a hard coupling to that extension. The title is the stronger signal and
     * carries no such conflict.
     */
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $slug = (string) ($request->getQueryParams()['slug'] ?? '');

        if ($slug === '') {
            $document->title = 'College football rosters';

            return;
        }

        $team = Team::query()->where('slug', $slug)->first();

        if ($team === null) {
            return;
        }

        // A player's own page: his name, then his club.
        $playerSlug = (string) ($request->getQueryParams()['player'] ?? '');
        if ($playerSlug !== '') {
            $player = \ErnestDefoe\Roster\Player::query()->where('slug', $playerSlug)->where('team_id', $team->id)->first();

            if ($player !== null) {
                $document->title = trim((string) $player->name) . ' — ' . trim((string) $team->name);

                return;
            }
        }

        $name = trim((string) $team->name);
        $document->title = $name . ' roster';

        $where = trim((string) ($team->conference ?? ''));
    }

    /**
     * Replace the page's description rather than adding a second one.
     *
     * 🚨 Appending leaves TWO `<meta name="description">` tags on the page —
     * fof/seo has already written the forum-wide one by the time this runs, and
     * measured on the demo both were served. A crawler picks one, usually the
     * first, so the specific description silently loses to the generic one and
     * every page still describes the whole site.
     */
}
