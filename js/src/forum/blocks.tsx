import app from 'flarum/forum/app';

declare const m: any;

/**
 * The crest wall, offered to Page Builder.
 *
 * 🚨 Page Builder is neither imported nor required, and registration goes
 * through its queue: `app.pageBuilder` does not exist until its own initializer
 * has run, and which of two extensions initialises first is not something
 * either of them decides.
 */
export default function registerBlocks(): void {
  const component = {
    view(vnode: any) {
      const settings = vnode.attrs.settings || {};
      const data = vnode.attrs.data || {};
      const teams: any[] = data.teams || [];

      if (teams.length === 0) return null;

      const rows = Math.max(1, Math.min(parseInt(settings.rows, 10) || 2, 3));
      const per = Math.ceil(teams.length / rows);
      const counts = data.counts;

      return m('.RosterCrests', [
        m('.RosterCrests-wall', Array.from({ length: rows }, (_, i) => {
          const slice = teams.slice(i * per, (i + 1) * per);

          /*
           * 🚨 The list is rendered TWICE per row, and that is what makes the
           * loop seamless: the track scrolls exactly half its width and starts
           * again, so the second copy is already in the place the first one
           * was. One copy would snap back visibly at the end of every pass.
           *
           * The duplicate is aria-hidden — a screen reader should hear every
           * club once, not twice.
           */
          return m('.RosterCrests-row', { key: i, className: i % 2 ? 'RosterCrests-row--reverse' : '' }, [
            m('.RosterCrests-track', [
              slice.map((t: any) => crest(t, false)),
              slice.map((t: any) => crest(t, true)),
            ]),
          ]);
        })),

        settings.caption || counts
          ? m('.RosterCrests-caption', [
              settings.caption ? m('span.RosterCrests-captionText', settings.caption) : null,
              counts
                ? m('span.RosterCrests-counts', [
                    m('b', String(counts.teams)),
                    ' programs',
                    m('span.RosterCrests-dot', '·'),
                    m('b', Number(counts.players).toLocaleString()),
                    ' players',
                  ])
                : null,
            ])
          : null,
      ]);
    },
  };

  function crest(team: any, duplicate: boolean) {
    return m(
      'a.RosterCrests-crest',
      {
        href: app.forum.attribute('baseUrl') + '/roster/' + team.slug,
        title: duplicate ? undefined : team.name,
        'aria-hidden': duplicate ? 'true' : undefined,
        tabindex: duplicate ? '-1' : undefined,
      },
      [
        // Both grounds, one shown by CSS — the wall sits on the page's own
        // background, and a mark drawn for a dark ground vanishes on a light one.
        /*
         * 🚨 NOT lazy. The track is twelve thousand pixels wide inside an
         * overflow-hidden row, and a lazily-loaded image that far outside the
         * viewport is never fetched — the wall rendered with a hundred and
         * fifty of five hundred images loaded and the rest as empty squares.
         * The crests ARE the section; there is nothing here worth deferring,
         * and the browser dedupes the two copies of each URL anyway.
         */
        m('img.RosterCrests-img.RosterCrests-img--light', { src: team.logo, alt: '', referrerpolicy: 'no-referrer' }),
        m('img.RosterCrests-img.RosterCrests-img--dark', { src: team.logoDark, alt: '', referrerpolicy: 'no-referrer' }),
      ]
    );
  }

  const registry = (app as any).pageBuilder;

  if (registry && typeof registry.registerBlock === 'function') {
    registry.registerBlock('roster-crests', component);
    return;
  }

  const queue = ((window as any).PageBuilderBlockQueue = (window as any).PageBuilderBlockQueue || []);
  queue.push({ type: 'roster-crests', component });
}
