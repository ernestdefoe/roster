import app from 'flarum/forum/app';
import { trail, teamsCrumb } from '../waymark';
import Page, { type IPageAttrs } from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import extractText from 'flarum/common/utils/extractText';
import crestUrl from '../crest';
import type Team from '../models/Team';
import type Mithril from 'mithril';

type League = { key: string; name: string };
type Conference = { name: string; teams: Team[] };

/**
 * Every club this site holds, grouped by conference or division.
 *
 * Each club arrives with the heading it is filed under (`group`), worked out
 * on the server; this page only collects them and puts the biggest first.
 */
export default class RosterIndexPage extends Page {
  loading = true;
  leagues: League[] = [];
  league = '';
  conferences: Conference[] = [];

  oninit(vnode: Mithril.Vnode<IPageAttrs, this>) {
    super.oninit(vnode);
    app.history.push('roster', extractText(app.translator.trans('ernestdefoe-roster.forum.title')));
    this.load();
  }

  load() {
    this.leagues = app.forum.attribute<League[]>('rosterLeagues') || [];

    const wanted = m.route.param('league') || '';
    this.league = this.leagues.some((l) => l.key === wanted) ? wanted : this.leagues[0]?.key || '';

    if (!this.league) {
      this.loading = false;
      return;
    }

    this.loading = true;

    app.store
      .find<Team[]>('roster-teams', { filter: { league: this.league }, page: { limit: 200 } })
      .then((teams) => {
        this.conferences = RosterIndexPage.group(teams);
      })
      .catch(() => {
        // app.request has already shown the error; the page shows its empty state.
        this.conferences = [];
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  /**
   * Conferences by size, then name — a fixed list of the power leagues is a
   * maintenance problem that comes due every time realignment happens.
   */
  static group(teams: Team[]): Conference[] {
    const byName = new Map<string, Team[]>();

    teams.forEach((team) => {
      const name = team.group();
      byName.set(name, [...(byName.get(name) || []), team]);
    });

    return [...byName.entries()]
      .map(([name, list]) => ({ name, teams: list }))
      .sort((a, b) => b.teams.length - a.teams.length || a.name.localeCompare(b.name));
  }

  view() {
    return (
      <div className="RosterPage">
        {trail([teamsCrumb(false)])}
        <div className="container">
          {this.head()}
          {this.loading ? (
            <LoadingIndicator />
          ) : this.conferences.length === 0 ? (
            <p className="RosterPage-empty">{app.translator.trans('ernestdefoe-roster.forum.empty')}</p>
          ) : (
            this.conferences.map((group) => this.conference(group))
          )}
        </div>
      </div>
    );
  }

  head() {
    return (
      <div className="RosterPage-head">
        <h1>{app.translator.trans('ernestdefoe-roster.forum.title')}</h1>
        <p className="RosterPage-intro">{app.translator.trans('ernestdefoe-roster.forum.intro')}</p>

        {/*
          The league switcher, shown only when this site holds more than one —
          a board that follows one competition should see no chrome for a
          choice it does not have.
        */}
        {this.leagues.length > 1 && (
          <div className="RosterPage-leagues">
            {this.leagues.map((l) => (
              <Link
                className={'Button Button--small' + (l.key === this.league ? ' Button--primary' : '')}
                href={app.route('roster.index') + `?league=${encodeURIComponent(l.key)}`}
              >
                {l.name}
              </Link>
            ))}
          </div>
        )}
      </div>
    );
  }

  conference(group: Conference) {
    return (
      <section className="RosterConference">
        <h2>
          {group.name}
          {/*
            🚨 `trans` with a `count` parameter, and the plural lives in the
            STRING as ICU MessageFormat. Flarum 2's translator has no
            `transChoice` at all — calling it throws inside `view()`, which does
            not show an error: the redraw simply never lands and the page sits
            on its spinner for ever.
          */}
          <span className="RosterConference-count">{app.translator.trans('ernestdefoe-roster.forum.team_count', { count: group.teams.length })}</span>
        </h2>
        <div className="RosterGrid">
          {group.teams.map((team) => (
            <Link className="RosterCard" href={app.route('roster.team', { slug: team.slug() })}>
              {team.logo() ? (
                <img className="RosterCard-crest" src={crestUrl(team.logo()!, 34)} alt="" loading="lazy" decoding="async" />
              ) : (
                <span className="RosterCard-crest RosterCard-crest--none" />
              )}
              <span className="RosterCard-name">
                {team.name()}
                {team.mascot() ? <span className="RosterCard-mascot">{team.mascot()}</span> : null}
              </span>
            </Link>
          ))}
        </div>
      </section>
    );
  }
}
