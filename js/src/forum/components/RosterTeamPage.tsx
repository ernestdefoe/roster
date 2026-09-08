import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';

declare const m: any;

/** One club: its crest, its division, and the roster grouped as the sport groups it. */
export default class RosterTeamPage extends Page {
  loading = true;
  team: any = null;
  groups: any[] = [];
  collegiate = false;

  /* The four gridiron groups are ours to name; every other group name came
     from the provider and is printed exactly as it was given. */
  static readonly LABELS: Record<string, string> = {
    offense: 'ernestdefoe-roster.forum.group.offense',
    defense: 'ernestdefoe-roster.forum.group.defense',
    specialists: 'ernestdefoe-roster.forum.group.specialists',
    other: 'ernestdefoe-roster.forum.group.other',
  };

  oninit(vnode: any) {
    super.oninit(vnode);

    app
      .request({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/roster/team`,
        params: { slug: m.route.param('slug') },
      })
      .then((data: any) => {
        this.team = data.team;
        this.groups = data.groups || [];
        this.collegiate = !!data.collegiate;
        this.loading = false;
        app.history.push('roster', this.team?.name || '');
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    if (this.loading) return <LoadingIndicator />;
    if (!this.team) return <div className="container"><p>{app.translator.trans('ernestdefoe-roster.forum.no_team')}</p></div>;

    return (
      <div className="RosterPage RosterPage--team">
        <div className="container">
          <Link className="RosterTeam-back" href={app.route('roster.index')}>
            {app.translator.trans('ernestdefoe-roster.forum.title')}
          </Link>

          <header className="RosterTeam-head">
            {this.team.logo ? <img className="RosterTeam-crest" src={this.team.logo} alt="" /> : null}
            <div>
              <h1>
                {this.team.name} {this.team.mascot ? <small>{this.team.mascot}</small> : null}
              </h1>
              {this.team.conference ? <p className="RosterTeam-conference">{this.team.conference}</p> : null}
            </div>
          </header>

          {this.groups.length === 0 ? (
            <p className="RosterPage-empty">{app.translator.trans('ernestdefoe-roster.forum.no_roster')}</p>
          ) : (
            this.groups.map((group: any) => (
              <section className="RosterGroup">
                <h3>
                  {RosterTeamPage.LABELS[group.group]
                    ? app.translator.trans(RosterTeamPage.LABELS[group.group])
                    : group.group}
                </h3>
                <div className="RosterTable-scroll">
                  <table className="RosterTable">
                    <thead>
                      <tr>
                        <th>{app.translator.trans('ernestdefoe-roster.forum.jersey')}</th>
                        <th>{app.translator.trans('ernestdefoe-roster.forum.player')}</th>
                        <th>{app.translator.trans('ernestdefoe-roster.forum.position')}</th>
                        {/* A college column. Nobody in the NBA is a sophomore. */}
                        {this.collegiate && <th>{app.translator.trans('ernestdefoe-roster.forum.class')}</th>}
                        <th>{app.translator.trans('ernestdefoe-roster.forum.height')}</th>
                        <th>{app.translator.trans('ernestdefoe-roster.forum.weight')}</th>
                        <th>{app.translator.trans('ernestdefoe-roster.forum.hometown')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {group.players.map((p: any) => (
                        <tr>
                          {/*
                            🚨 A null check, NOT a falsy one. Number 0 has been
                            legal in college football since 2020 and is common
                            now, and `0` is falsy — a truthiness test renders an
                            empty cell for a jersey the player genuinely has.
                          */}
                          <td className="RosterTable-num">{p.jersey !== null && p.jersey !== undefined ? p.jersey : ''}</td>
                          <td className="RosterTable-name">{p.name}</td>
                          <td>{p.position}</td>
                          {this.collegiate && <td>{this.classYear(p.classYear)}</td>}
                          <td>{p.height || ''}</td>
                          <td>{p.weight || ''}</td>
                          <td>{p.hometown || ''}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            ))
          )}
        </div>
      </div>
    );
  }

  classYear(year: number | null) {
    if (!year) return '';

    const names = ['freshman', 'sophomore', 'junior', 'senior', 'super_senior'];
    const key = names[year - 1];

    return key ? app.translator.trans(`ernestdefoe-roster.forum.class_year.${key}`) : '';
  }
}
