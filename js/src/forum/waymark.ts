import app from 'flarum/forum/app';
import type Mithril from 'mithril';

/**
 * Breadcrumbs from ernestdefoe/waymark, when it is installed.
 *
 * The roster pages build their own layout rather than Flarum's PageStructure,
 * so Waymark cannot place a trail on them; they draw it themselves through
 * this. Without Waymark every call returns null and the pages keep their own
 * "back" links.
 */
type Crumb = { label: Mithril.Children; href?: string } | null;

/** What ernestdefoe/waymark puts on the app. */
type Waymark = { render(crumbs: Exclude<Crumb, null>[]): Mithril.Children };

/** ernestdefoe/header-nav's menu, as far as this reads it. */
type HeaderNav = { items?: { key?: string; label?: unknown }[] };

const waymark = () => (app as typeof app & { waymark?: Waymark }).waymark;

export function hasWaymark(): boolean {
  if (!('ernestdefoe-waymark' in flarum.extensions)) return false;

  const w = waymark();
  return !!(w && w.render) && app.forum.attribute('waymarkOther') !== false;
}

export function trail(crumbs: Crumb[]) {
  return hasWaymark() ? waymark()!.render(crumbs.filter((c): c is Exclude<Crumb, null> => !!c)) : null;
}

/**
 * What this forum calls the roster.
 *
 * 🚨 The header's name wins when Header Nav has renamed it. fbsfb's header says
 * "Teams"; a trail beneath it saying "Roster" names one place two ways.
 */
function rosterName() {
  const nav = app.forum.attribute<HeaderNav | null>('headerNav');
  const entry = nav && Array.isArray(nav.items) ? nav.items.find((i) => i && i.key === 'roster') : null;
  const label = entry && typeof entry.label === 'string' ? entry.label.trim() : '';

  return label || app.translator.trans('ernestdefoe-roster.forum.title');
}

export function teamsCrumb(link = true) {
  const label = rosterName();
  return link ? { label, href: app.route('roster.index') } : { label };
}
