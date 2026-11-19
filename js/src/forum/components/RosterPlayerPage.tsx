import app from 'flarum/forum/app';
import Page, { type IPageAttrs } from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import humanTime from 'flarum/common/helpers/humanTime';
import { hasWaymark, trail, teamsCrumb } from '../waymark';
import crestUrl from '../crest';
import type Mithril from 'mithril';
import type Player from '../models/Player';
import type Team from '../models/Team';
import type { About, Bio, GamesTable, NewsItem, Profile, Video } from '../types';

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-roster.forum.player_page.${key}`, params);

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
  player: Player | null = null;
  playing: string | null = null;
  /** Times this page has asked again for a profile that was still being gathered. */
  retries = 0;
  retryTimer: ReturnType<typeof setTimeout> | null = null;
  /** Set once the reader has left, so an answer still on its way changes nothing. */
  removed = false;

  /** A profile is gathered on the queue; the page asks again this often, this many times. */
  static readonly RETRY_MS = 4000;
  static readonly MAX_RETRIES = 4;

  oninit(vnode: Mithril.Vnode<IPageAttrs, this>) {
    super.oninit(vnode);
    this.load();
  }

  onremove(vnode: Mithril.VnodeDOM<IPageAttrs, this>) {
    super.onremove(vnode);
    this.removed = true;
    if (this.retryTimer) clearTimeout(this.retryTimer);
  }

  load(again = false) {
    if (!again) {
      this.loading = true;
      this.playing = null;
      this.retries = 0;
    }

    app.store
      .find<Player>('roster-players', m.route.param('player'), { include: 'team' })
      .then((player) => {
        if (this.removed) return;
        this.player = player;
        app.setTitle(player.name());
        if (!again) app.history.push('roster.player', player.name());
        this.askAgainIfPending();
      })
      .catch(() => {
        // A slug with no player: the page says so below.
        if (!again) this.player = null;
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  /** While the queue is still gathering his profile, look again shortly. */
  askAgainIfPending() {
    if (this.removed || !this.player?.profile()?.pending || this.retries >= RosterPlayerPage.MAX_RETRIES) return;

    this.retries++;
    this.retryTimer = setTimeout(() => this.load(true), RosterPlayerPage.RETRY_MS);
  }

  view() {
    const player = this.player;
    const team = player?.team();

    if (this.loading) {
      return (
        <div className="RosterPage">
          <LoadingIndicator />
        </div>
      );
    }

    if (!player || !team) {
      return (
        <div className="RosterPage">
          <div className="container">
            <p>{t('not_found')}</p>
          </div>
        </div>
      );
    }

    const color = clubColor(team.color());
    const teamHref = app.route('roster.team', { slug: team.slug() });

    return (
      <div
        className={'RosterPage RosterPage--player' + (isLight(color) ? ' RosterPage--lightClub' : '')}
        style={color ? { '--roster-team': color } : undefined}
      >
        {hasWaymark() ? (
          trail([teamsCrumb(), { label: team.name(), href: teamHref }, { label: player.name() }])
        ) : (
          <div className="container">
            <Link className="RosterTeam-back" href={teamHref}>
              {team.name()}
            </Link>
          </div>
        )}

        {this.hero(player, team, teamHref)}
        {this.body(player, player.profile() || {})}
      </div>
    );
  }

  hero(player: Player, team: Team, teamHref: string) {
    const bio: Bio = player.profile()?.bio ?? {};
    const photo = bio.headshot || player.photo() || null;
    const jersey = player.jersey();

    return (
      <header className="RosterPlayer-hero">
        <div className="container RosterPlayer-heroInner">
          <div className="RosterPlayer-photo">
            {photo ? <img src={photo} alt="" loading="lazy" /> : <span className="RosterPlayer-initials">{initials(player.name())}</span>}
          </div>

          <div className="RosterPlayer-identity">
            <Link className="RosterPlayer-team" href={teamHref}>
              {team.logo() ? <img src={crestUrl(team.logo()!, 26)} alt="" /> : null}
              {team.name()}
            </Link>
            <h1>{player.name()}</h1>
            <p className="RosterPlayer-line">
              {[bio.jersey || (jersey !== null && jersey !== undefined ? '#' + jersey : null), bio.position || player.position()]
                .filter(Boolean)
                .join(' · ')}
            </p>
          </div>
        </div>
      </header>
    );
  }

  body(player: Player, profile: Partial<Profile>) {
    const bio: Bio = profile.bio ?? {};
    const weight = player.weight();
    const empty = !profile.about && !profile.stats && !(profile.videos || []).length && !(profile.news || []).length;

    return (
      <div className="container RosterPlayer-body">
        <dl className="RosterPlayer-facts">
          {fact(t('height'), bio.height || player.height())}
          {fact(t('weight'), bio.weight || (weight ? `${weight} lbs` : null))}
          {fact(t('class'), bio.experience)}
          {fact(t('hometown'), bio.birthPlace || player.hometown())}
          {/* A professional's college; a college player's would only name his own school. */}
          {(player.team() || null)?.collegiate() ? null : fact(t('college'), player.college())}
          {fact(t('age'), bio.age)}
          {fact(t('status'), bio.status)}
        </dl>

        {profile.nextGame ? (
          <p className="RosterPlayer-next">
            <strong>{t('next_game')}</strong> {profile.nextGame.name}
            {profile.nextGame.date ? <span> · {formatDate(profile.nextGame.date)}</span> : null}
          </p>
        ) : null}

        {this.sections(profile)}

        {empty ? <p className="RosterPage-empty">{profile.pending ? t('gathering') : t('nothing_yet')}</p> : null}

        {profile.espnUrl ? (
          <p className="RosterPlayer-source">
            <a href={profile.espnUrl} target="_blank" rel="noopener noreferrer">
              {t('espn_profile')}
            </a>
          </p>
        ) : null}
      </div>
    );
  }

  sections(profile: Partial<Profile>) {
    return [
      profile.about ? this.about(profile.about) : null,
      profile.videos && profile.videos.length ? this.videos(profile.videos) : null,
      profile.stats
        ? this.table(
            t('career'),
            profile.stats.labels,
            profile.stats.names,
            profile.stats.rows.map((r) => [r.label, ...r.values])
          )
        : null,
      profile.games ? this.recentGames(profile.games) : null,
      profile.news && profile.news.length ? this.news(profile.news) : null,
    ];
  }

  about(about: About) {
    return (
      <section className="RosterPlayer-section RosterPlayer-about">
        <h2>{t('about')}</h2>
        <p>{about.extract}</p>
        <p className="RosterPlayer-credit">
          <a href={about.url} target="_blank" rel="noopener noreferrer">
            {t('wikipedia')}
          </a>
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
  videos(videos: Video[]) {
    return (
      <section className="RosterPlayer-section">
        <h2>{t('highlights')}</h2>
        <div className="RosterPlayer-videos">
          {videos.map((v) => (
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
                  <span className="RosterVideo-play" aria-hidden="true">
                    ▶
                  </span>
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

  recentGames(games: GamesTable) {
    const rows = games.rows.map((g) => [
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

  table(title: Mithril.Children, labels: string[], names: string[], rows: Mithril.Children[][]) {
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
                <tr>{row.map((cell, i) => (i === 0 ? <th scope="row">{cell}</th> : <td>{cell}</td>))}</tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    );
  }

  news(news: NewsItem[]) {
    return (
      <section className="RosterPlayer-section">
        <h2>{t('news')}</h2>
        <ul className="RosterPlayer-news">
          {news.map((n) => (
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

function fact(label: Mithril.Children, value: string | number | null | undefined) {
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
  const date = new Date(iso);
  if (isNaN(date.getTime())) return iso;

  return date.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}
