import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import IndexPage from 'flarum/forum/components/IndexPage';
import Link from 'flarum/common/components/Link';

declare const m: any;

/**
 * Every club this site holds, grouped by conference or division.
 *
 * 🚨 The grouping arrives already done from the server. The rule — conference,
 * then club, with conferences ordered by size — is the same rule the Convoro
 * build uses, and a second copy of it here is a copy that drifts.
 */
export default class RosterIndexPage extends Page {
  loading = true;
  leagues: any[] = [];
  league = '';
  conferences: any[] = [];

  oninit(vnode: any) {
    super.oninit(vnode);
    app.history.push('roster', app.translator.trans('ernestdefoe-roster.forum.title'));
    this.load();
  }

  load() {
    const wanted = m.route.param('league') || '';

    this.loading = true;

    app
      .request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/roster/teams`, params: wanted ? { league: wanted } : {} })
      .then((data: any) => {
        this.leagues = data.leagues || [];
        this.league = data.league || '';
        this.conferences = data.conferences || [];
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <div className="RosterPage">
        {IndexPage.prototype.hero ? null : null}
        <div className="container">
          <div className="RosterPage-head">
            <h1>{app.translator.trans('ernestdefoe-roster.forum.title')}</h1>
            <p className="RosterPage-intro">{app.translator.trans('ernestdefoe-roster.forum.intro')}</p>

            {/*
              The league switcher, shown only when this site holds more than
              one — a board that follows one competition should see no chrome
              for a choice it does not have.
            */}
            {this.leagues.length > 1 && (
              <div className="RosterPage-leagues">
                {this.leagues.map((l: any) => (
                  <Link
                    className={'Button Button--small' + (l.key === this.league ? ' Button--primary' : '')}
                    href={app.route('roster.index') + (l.key ? `?league=${l.key}` : '')}
                  >
                    {l.name}
                  </Link>
                ))}
              </div>
            )}
          </div>

          {this.loading ? (
            <LoadingIndicator />
          ) : this.conferences.length === 0 ? (
            <p className="RosterPage-empty">{app.translator.trans('ernestdefoe-roster.forum.empty')}</p>
          ) : (
            this.conferences.map((group: any) => (
              <section className="RosterConference">
                <h2>
                  {group.conference}
                  {/*
                    🚨 `trans` with a `count` parameter, and the plural lives in
                    the STRING as ICU MessageFormat. Flarum 2's translator has
                    no `transChoice` at all — calling it throws inside `view()`,
                    which does not show an error: the redraw simply never lands
                    and the page sits on its spinner for ever. And the pipe form
                    ("# team|# teams") is Laravel's, not ICU's: `trans` renders
                    both halves of it verbatim.
                  */}
                  <span className="RosterConference-count">
                    {app.translator.trans('ernestdefoe-roster.forum.team_count', { count: group.teams.length })}
                  </span>
                </h2>
                <div className="RosterGrid">
                  {group.teams.map((team: any) => (
                    <Link className="RosterCard" href={app.route('roster.team', { slug: team.slug })}>
                      {team.logo ? <img className="RosterCard-crest" src={team.logo} alt="" loading="lazy" /> : <span className="RosterCard-crest RosterCard-crest--none" />}
                      <span className="RosterCard-name">
                        {team.name}
                        {team.mascot ? <span className="RosterCard-mascot">{team.mascot}</span> : null}
                      </span>
                    </Link>
                  ))}
                </div>
              </section>
            ))
          )}
        </div>
      </div>
    );
  }
}
