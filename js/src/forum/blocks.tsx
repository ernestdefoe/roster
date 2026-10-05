import app from 'flarum/forum/app';
import crestUrl from './crest';

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
      const dark = darkGround();

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
              slice.map((t: any) => crest(t, false, dark)),
              slice.map((t: any) => crest(t, true, dark)),
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

  function crest(team: any, duplicate: boolean, dark: boolean) {
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
         * 🚨 The crest on show is NOT lazy. The track is twelve thousand pixels
         * wide inside an overflow-hidden row and moves by a CSS animation; a
         * lazy image there loads only once it is inside the clip, which is a
         * bet on every browser re-checking intersection on every animated
         * frame. The crests ARE the section, so the visible ground loads
         * straight away (the two copies of each URL are one request).
         *
         * The ground CSS hides is lazy, and a lazy image that is display:none
         * is never fetched — so a reader downloads one set of crests, not two.
         * Switch theme and the other set appears and loads then.
         */
        crestImg('light', team.logo, dark),
        crestImg('dark', team.logoDark, dark),
      ]
    );
  }

  /*
   * 66px on the wall, 1.22x that on hover — asked for at the hover size so a
   * lifted crest is as sharp as a resting one.
   */
  function crestImg(ground: 'light' | 'dark', url: string, dark: boolean) {
    return m('img.RosterCrests-img.RosterCrests-img--' + ground, {
      src: crestUrl(url, 80),
      alt: '',
      loading: (ground === 'dark') === dark ? undefined : 'lazy',
      decoding: 'async',
      referrerpolicy: 'no-referrer',
    });
  }

  /** Which ground the stylesheet will show — the same two rules it uses. */
  function darkGround(): boolean {
    const theme = document.documentElement.getAttribute('data-theme');
    if (theme === 'dark') return true;
    if (theme === 'light') return false;
    return !!window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  const registry = (app as any).pageBuilder;

  if (registry && typeof registry.registerBlock === 'function') {
    registry.registerBlock('roster-crests', component);
    return;
  }

  const queue = ((window as any).PageBuilderBlockQueue = (window as any).PageBuilderBlockQueue || []);
  queue.push({ type: 'roster-crests', component });
}
