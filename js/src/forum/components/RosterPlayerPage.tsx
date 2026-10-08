import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import humanTime from 'flarum/common/helpers/humanTime';
import { hasWaymark, trail, teamsCrumb } from '../waymark';
import crestUrl from '../crest';
import type Mithril from 'mithril';

declare const m: any;

const t = (key: string, params?: any) => app.translator.trans(`ernestdefoe-roster.forum.player_page.${key}`, params);

/**
 * One player: who he is, how he has played, and what has been said and shown
 * about him.
 *
 * Every section is drawn only when there is something in it. ESPN has a full
 * page on a starting quarterback and almost nothing on a walk-on long snapper,
 * and a page of empty headings for the walk-on reads as broken rather than as
 * a player nobody has written about yet.
 */
export default class RosterPlayerPage extends Page {
  loading = true;
  data: any = null;
  playing: string | null = null;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load();
  }

  onbeforeupdate(vnode: any) {
    // Moving between players without leaving the page.
    if (this.data && this.data.player.slug !== m.route.param('player')) this.load();
  }

  load() {
    this.loading = true;
    this.playing = null;

    app
      .request({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/roster/player`,
        params: { slug: m.route.param('player') },
      })
      .then((data: any) => {
        this.data = data;
        this.loading = false;
        app.setTitle(data.player.name);
        app.history.push('roster.player', data.player.name);
        m.redraw();
      })
      .catch(() => {
        this.data = null;
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    if (this.loading) return <div className="RosterPage"><LoadingIndicator /></div>;
    if (!this.data) {
      return (
        <div className="RosterPage">
          <div className="container"><p>{t('not_found')}</p></div>
        </div>
      );
    }

    const { player, team, profile } = this.data;
    const bio = profile.bio || {};
    const photo = bio.headshot || player.photo || null;
    const teamHref = app.route('roster.team', { slug: team.slug });

    return (
      <div
        className={'RosterPage RosterPage--player' + (isLight(clubColor(team.color)) ? ' RosterPage--lightClub' : '')}
        style={clubColor(team.color) ? { '--roster-team': clubColor(team.color) } : undefined}
      >
        {hasWaymark() ? (
          trail([teamsCrumb(), { label: team.name, href: teamHref }, { label: player.name }])
        ) : (
          <div className="container">
            <Link className="RosterTeam-back" href={teamHref}>{team.name}</Link>
          </div>
        )}

        <header className="RosterPlayer-hero">
          <div className="container RosterPlayer-heroInner">
            <div className="RosterPlayer-photo">
              {photo ? <img src={photo} alt="" loading="lazy" /> : <span className="RosterPlayer-initials">{initials(player.name)}</span>}
            </div>

            <div className="RosterPlayer-identity">
              <Link className="RosterPlayer-team" href={teamHref}>
                {team.logo ? <img src={crestUrl(team.logo, 26)} alt="" /> : null}
                {team.name}
              </Link>
              <h1>{player.name}</h1>
              <p className="RosterPlayer-line">
                {[bio.jersey || (player.jersey !== null && player.jersey !== undefined ? '#' + player.jersey : null), bio.position || player.position]
                  .filter(Boolean)
                  .join(' · ')}
              </p>
            </div>
          </div>
        </header>

        <div className="container RosterPlayer-body">
          <dl className="RosterPlayer-facts">
            {fact(t('height'), bio.height || player.height)}
            {fact(t('weight'), bio.weight || (player.weight ? `${player.weight} lbs` : null))}
            {fact(t('class'), bio.experience)}
            {fact(t('hometown'), bio.birthPlace || player.hometown)}
            {fact(t('age'), bio.age)}
            {fact(t('status'), bio.status)}
          </dl>

          {profile.nextGame ? (
            <p className="RosterPlayer-next">
              <strong>{t('next_game')}</strong> {profile.nextGame.name}
              {profile.nextGame.date ? <span> · {formatDate(profile.nextGame.date)}</span> : null}
            </p>
          ) : null}

          {profile.about ? this.about(profile.about) : null}
          {profile.videos && profile.videos.length ? this.videos(profile.videos) : null}
          {profile.stats ? this.table(t('career'), profile.stats.labels, profile.stats.names, profile.stats.rows.map((r: any) => [r.label, ...r.values])) : null}
          {profile.games ? this.recentGames(profile.games) : null}
          {profile.news && profile.news.length ? this.news(profile.news) : null}

          {!profile.about && !profile.stats && !(profile.videos || []).length && !(profile.news || []).length ? (
            <p className="RosterPage-empty">{t('nothing_yet')}</p>
          ) : null}

          {profile.espnUrl ? (
            <p className="RosterPlayer-source">
              <a href={profile.espnUrl} target="_blank" rel="noopener noreferrer">{t('espn_profile')}</a>
            </p>
          ) : null}
        </div>
      </div>
    );
  }

  about(about: any) {
    return (
      <section className="RosterPlayer-section RosterPlayer-about">
        <h2>{t('about')}</h2>
        <p>{about.extract}</p>
        <p className="RosterPlayer-credit">
          <a href={about.url} target="_blank" rel="noopener noreferrer">{t('wikipedia')}</a>
        </p>
      </section>
    );
  }

  /**
   * Highlights, played in ESPN's own syndicated player.
   *
   * 🚨 The player is only built when somebody presses play. Eight ESPN players
   * on one page is eight heavyweight embeds loading before anyone has chosen a
   * clip; a thumbnail costs one image.
   */
  videos(videos: any[]) {
    return (
      <section className="RosterPlayer-section">
        <h2>{t('highlights')}</h2>
        <div className="RosterPlayer-videos">
          {videos.map((v: any) => (
            <figure className="RosterVideo" key={v.id}>
              {this.playing === v.id ? (
                <div className="RosterVideo-frame">
                  <iframe
                    src={`https://www.espn.com/core/video/iframe/_/id/${v.id}/`}
                    title={v.title}
                    allow="autoplay; fullscreen; encrypted-media; picture-in-picture"
                    allowfullscreen
                    loading="lazy"
                    referrerpolicy="strict-origin-when-cross-origin"
                  />
                </div>
              ) : (
                <button
                  type="button"
                  className="RosterVideo-poster"
                  aria-label={t('play', { title: v.title })}
                  onclick={() => {
                    this.playing = v.id;
                  }}
                >
                  {v.image ? <img src={v.image} alt="" loading="lazy" /> : null}
                  <span className="RosterVideo-play" aria-hidden="true">▶</span>
                </button>
              )}
              <figcaption>
                <span className="RosterVideo-title">{v.title}</span>
                {v.url ? (
                  <a className="RosterVideo-link" href={v.url} target="_blank" rel="noopener noreferrer">
                    {t('watch_on_espn')}
                  </a>
                ) : null}
              </figcaption>
            </figure>
          ))}
        </div>
      </section>
    );
  }

  recentGames(games: any) {
    const rows = games.rows.map((g: any) => [
      <span className="RosterGame">
        {g.opponentLogo ? <img src={crestUrl(g.opponentLogo, 20)} alt="" loading="lazy" decoding="async" /> : null}
        {g.atVs} {g.opponent}
        <small className={'RosterGame-result RosterGame-result--' + String(g.result).toLowerCase()}>
          {g.result} {g.score}
        </small>
      </span>,
      ...g.values,
    ]);

    return this.table(t('recent_games'), games.labels, games.names, rows);
  }

  table(title: Mithril.Children, labels: string[], names: string[], rows: any[][]) {
    return (
      <section className="RosterPlayer-section">
        <h2>{title}</h2>
        <div className="RosterTable-scroll">
          <table className="RosterTable RosterTable--stats">
            <thead>
              <tr>
                <th />
                {labels.map((label, i) => (
                  <th title={names[i] || label}>
                    <abbr title={names[i] || label}>{label}</abbr>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr>
                  {row.map((cell, i) => (i === 0 ? <th scope="row">{cell}</th> : <td>{cell}</td>))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    );
  }

  news(news: any[]) {
    return (
      <section className="RosterPlayer-section">
        <h2>{t('news')}</h2>
        <ul className="RosterPlayer-news">
          {news.map((n: any) => (
            <li>
              <a href={n.url} target="_blank" rel="noopener noreferrer">
                {n.image ? <img src={n.image} alt="" loading="lazy" /> : null}
                <span>
                  <strong>{n.title}</strong>
                  {n.description ? <span className="RosterPlayer-newsText">{n.description}</span> : null}
                  {n.published ? <small>{humanTime(new Date(n.published))}</small> : null}
                </span>
              </a>
            </li>
          ))}
        </ul>
      </section>
    );
  }
}

function fact(label: any, value: any) {
  if (value === null || value === undefined || value === '') return null;
  // One cell per fact: a grid lays a bare dt and dd out as two separate cells,
  // and the labels drift away from their values.
  return (
    <div className="RosterPlayer-fact">
      <dt>{label}</dt>
      <dd>{value}</dd>
    </div>
  );
}

/**
 * The club's colour as CSS can use it.
 *
 * 🚨 ESPN gives colours as bare hex — `ba0c2f` — and that is how fbsfb stores
 * them. Without the `#` it is not a colour at all: the custom property is
 * invalid where it is used, the background falls back to nothing, and Ohio
 * State's scarlet banner rendered as pale grey under white text.
 */
function clubColor(color: string | null | undefined): string | null {
  const c = String(color || '').trim();
  if (/^[0-9a-f]{6}$/i.test(c) || /^[0-9a-f]{3}$/i.test(c)) return '#' + c;
  return c || null;
}

/** Whether white text would be hard to read on this colour. */
function isLight(color: string | null): boolean {
  const m6 = /^#([0-9a-f]{6})$/i.exec(color || '');
  if (!m6) return false;
  const [r, g, b] = [0, 2, 4].map((i) => {
    const v = parseInt(m6[1].slice(i, i + 2), 16) / 255;
    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.45;
}

function initials(name: string) {
  return String(name || '')
    .split(/\s+/)
    .map((p) => p.charAt(0))
    .join('')
    .slice(0, 2)
    .toUpperCase();
}

/**
 * 🚨 ESPN writes an unannounced kickoff as 04:00 UTC — midnight Eastern, a
 * placeholder, not a time. Printed as a local time it said "Fri 11:00 PM" for
 * a Saturday game whose time nobody knew yet. The date alone, and "time TBA".
 */
function formatDate(iso: string) {
  if (/T04:00(:00)?(\.000)?Z$/.test(iso)) {
    const d = new Date(iso.slice(0, 10) + 'T12:00:00Z');
    return `${d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })} · ${t('time_tba')}`;
  }
  try {
    return new Date(iso).toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  } catch (e) {
    return iso;
  }
}
