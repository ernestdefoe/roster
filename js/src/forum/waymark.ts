import app from 'flarum/forum/app';

/**
 * Breadcrumbs from ernestdefoe/waymark, when it is installed.
 *
 * The roster pages build their own layout rather than Flarum's PageStructure,
 * so Waymark cannot place a trail on them; they draw it themselves through
 * this. Without Waymark every call returns null and the pages keep their own
 * "back" links.
 */
type Crumb = { label: any; href?: string } | null;

export function hasWaymark(): boolean {
  const w = (app as any).waymark;
  return !!(w && w.render) && app.forum.attribute('waymarkOther') !== false;
}

export function trail(crumbs: Crumb[]) {
  return hasWaymark() ? (app as any).waymark.render(crumbs.filter(Boolean)) : null;
}

/**
 * What this forum calls the roster.
 *
 * 🚨 The header's name wins when Header Nav has renamed it. fbsfb's header says
 * "Teams"; a trail beneath it saying "Roster" names one place two ways.
 */
function rosterName() {
  const nav: any = app.forum.attribute('headerNav');
  const entry = nav && Array.isArray(nav.items) ? nav.items.find((i: any) => i && i.key === 'roster') : null;
  const label = entry && typeof entry.label === 'string' ? entry.label.trim() : '';

  return label || app.translator.trans('ernestdefoe-roster.forum.title');
}

export function teamsCrumb(link = true) {
  const label = rosterName();
  return link ? { label, href: app.route('roster.index') } : { label };
}
