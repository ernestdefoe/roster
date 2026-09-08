import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexPage from 'flarum/forum/components/IndexPage';
import LinkButton from 'flarum/common/components/LinkButton';

import RosterIndexPage from './components/RosterIndexPage';
import RosterTeamPage from './components/RosterTeamPage';

app.initializers.add('ernestdefoe-roster', () => {
  app.routes['roster.index'] = { path: '/roster', component: RosterIndexPage };
  app.routes['roster.team'] = { path: '/roster/:slug', component: RosterTeamPage };

  // A place to click, in the nav every other page uses.
  extend(IndexPage.prototype, 'navItems', function (items: any) {
    items.add(
      'roster',
      <LinkButton href={app.route('roster.index')} icon="fas fa-users">
        {app.translator.trans('ernestdefoe-roster.forum.title')}
      </LinkButton>,
      -10
    );
  });
});
