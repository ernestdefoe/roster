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

export function teamsCrumb(link = true) {
  const label = app.translator.trans('ernestdefoe-roster.forum.title');
  return link ? { label, href: app.route('roster.index') } : { label };
}
