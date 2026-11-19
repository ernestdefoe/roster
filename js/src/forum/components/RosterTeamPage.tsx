import app from 'flarum/forum/app';
import Page, { type IPageAttrs } from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import { hasWaymark, trail, teamsCrumb } from '../waymark';
import crestUrl from '../crest';
import type Team from '../models/Team';
import type Player from '../models/Player';
import type Mithril from 'mithril';

type Group = { name: string; players: Player[] };

/** One club: its crest, its division, and the roster grouped as the sport groups it. */
export default class RosterTeamPage extends Page {
  loading = true;
  team: Team | null = null;
  groups: Group[] = [];

  /* The four gridiron groups arrive as locale keys and are ours to name; every
     other group name came from the provider and is printed as it was given. */
  static readonly LABELS: Record<string, string> = {
    offense: 'ernestdefoe-roster.forum.group.offense',
    defense: 'ernestdefoe-roster.forum.group.defense',
    specialists: 'ernestdefoe-roster.forum.group.specialists',
    other: 'ernestdefoe-roster.forum.group.other',
  };

  oninit(vnode: Mithril.Vnode<IPageAttrs, this>) {
    super.oninit(vnode);

    app.store
      .find<Team>('roster-teams', m.route.param('slug'), { include: 'players' })
      .then((team) => {
        this.team = team;
        this.groups = RosterTeamPage.group((team.players() || []).filter((p): p is Player => !!p));
        app.setTitle(team.name());
        app.history.push('roster', team.name());
      })
      .catch(() => {
        // A slug with no club: the page says so below.
        this.team = null;
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  /**
   * Numbered players first, by number, then the rest by name; each group in
   * the order its first player appears.
   *
   * 🚨 A null check, NOT a falsy one. Number 0 has been legal in college
   * football since 2020 and is common now, and `0` is falsy.
   */
  static group(players: Player[]): Group[] {
    const sorted = [...players].sort((a, b) => {
      const ja = a.jersey();
      const jb = b.jersey();
      if ((ja === null) !== (jb === null)) return ja === null ? 1 : -1;
      return (ja ?? 0) - (jb ?? 0) || a.name().localeCompare(b.name());
    });

    const groups = new Map<string, Player[]>();
    sorted.forEach((p) => groups.set(p.positionGroup(), [...(groups.get(p.positionGroup()) || []), p]));

    return [...groups.entries()].map(([name, list]) => ({ name, players: list }));
  }

  view() {
    if (this.loading) return <LoadingIndicator />;
    if (!this.team)
      return (
        <div className="container">
          <p>{app.translator.trans('ernestdefoe-roster.forum.no_team')}</p>
        </div>
      );

    const team = this.team;

    return (
      <div className="RosterPage RosterPage--team">
        {trail([teamsCrumb(), { label: team.name() }])}
        <div className="container">
          {hasWaymark() ? null : (
            <Link className="RosterTeam-back" href={app.route('roster.index')}>
              {app.translator.trans('ernestdefoe-roster.forum.title')}
            </Link>
          )}

          <header className="RosterTeam-head">
            {team.logo() ? <img className="RosterTeam-crest" src={crestUrl(team.logo()!, 64)} alt="" /> : null}
            <div>
              <h1>
                {team.name()} {team.mascot() ? <small>{team.mascot()}</small> : null}
              </h1>
              {team.conference() ? <p className="RosterTeam-conference">{team.conference()}</p> : null}
            </div>
          </header>

          {this.groups.length === 0 ? (
            <p className="RosterPage-empty">{app.translator.trans('ernestdefoe-roster.forum.no_roster')}</p>
          ) : (
            this.groups.map((group) => this.section(group, team.collegiate()))
          )}
        </div>
      </div>
    );
  }

  section(group: Group, collegiate: boolean) {
    const t = (key: string) => app.translator.trans(`ernestdefoe-roster.forum.${key}`);

    return (
      <section className="RosterGroup">
        <h3>{RosterTeamPage.LABELS[group.name] ? app.translator.trans(RosterTeamPage.LABELS[group.name]) : group.name}</h3>
        <div className="RosterTable-scroll">
          <table className="RosterTable">
            <thead>
              <tr>
                <th>{t('jersey')}</th>
                <th>{t('player')}</th>
                <th>{t('position')}</th>
                {/* A college column. Nobody in the NBA is a sophomore. */}
                {collegiate && <th>{t('class')}</th>}
                <th>{t('height')}</th>
                <th>{t('weight')}</th>
                <th>{t('hometown')}</th>
              </tr>
            </thead>
            <tbody>{group.players.map((p) => this.row(p, collegiate))}</tbody>
          </table>
        </div>
      </section>
    );
  }

  row(p: Player, collegiate: boolean) {
    const jersey = p.jersey();

    return (
      <tr>
        <td className="RosterTable-num">{jersey !== null && jersey !== undefined ? jersey : ''}</td>
        <td className="RosterTable-name">
          <Link href={app.route('roster.player', { slug: m.route.param('slug'), player: p.slug() })}>{p.name()}</Link>
        </td>
        <td>{p.position()}</td>
        {collegiate && <td>{this.classYear(p.classYear())}</td>}
        <td>{p.height() || ''}</td>
        <td>{p.weight() || ''}</td>
        <td>{p.hometown() || ''}</td>
      </tr>
    );
  }

  classYear(year: number | null) {
    if (!year) return '';

    const names = ['freshman', 'sophomore', 'junior', 'senior', 'super_senior'];
    const key = names[year - 1];

    return key ? app.translator.trans(`ernestdefoe-roster.forum.class_year.${key}`) : '';
  }
}
