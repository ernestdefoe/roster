import Extend from 'flarum/common/extenders';
import Team from './models/Team';
import Player from './models/Player';

export default [
  new Extend.Store().add('roster-teams', Team).add('roster-players', Player),

  // Code-split: each page loads when someone opens it, not with every page.
  new Extend.Routes()
    .add('roster.index', '/roster', () => import('./components/RosterIndexPage'))
    .add('roster.team', '/roster/:slug', () => import('./components/RosterTeamPage'))
    .add('roster.player', '/roster/:slug/:player', () => import('./components/RosterPlayerPage')),
];
