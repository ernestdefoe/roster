<?php

namespace ErnestDefoe\Roster\Frontend;

use ErnestDefoe\Roster\Player;
use ErnestDefoe\Roster\Team;
use Flarum\Frontend\Document;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A real title for the roster pages, written server side.
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
 *
 * 🚨 The TITLE only. The description is fof/seo's to write: it appends the
 * forum-wide one AFTER a route's own content callable runs, so a description
 * set here left two `<meta name="description">` tags on the page.
 */
class RosterPageContent
{
    public function __construct(protected TranslatorInterface $translator)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $slug = (string) ($request->getQueryParams()['slug'] ?? '');

        if ($slug === '') {
            $document->title = $this->translator->trans('ernestdefoe-roster.forum.meta.index_title');

            return;
        }

        // A player's own page: his name, then his club. Found by his slug alone,
        // as the API finds him, so a link with an outdated club still works.
        $playerSlug = (string) ($request->getQueryParams()['player'] ?? '');
        $player = $playerSlug === '' ? null : Player::query()->with('team')->where('slug', $playerSlug)->first();

        if ($player !== null && $player->team !== null) {
            $document->title = $this->translator->trans('ernestdefoe-roster.forum.meta.player_title', [
                'player' => trim((string) $player->name),
                'team' => trim((string) $player->team->name),
            ]);

            return;
        }

        $team = Team::query()->where('slug', $slug)->first();

        if ($team !== null) {
            $document->title = $this->translator->trans('ernestdefoe-roster.forum.meta.team_title', ['team' => trim((string) $team->name)]);
        }
    }
}
