import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

import registerBlocks from './blocks';

app.initializers.add('ernestdefoe-roster', () => {
  // The crest wall, offered to Page Builder where it is enabled. Registration only.
  if ('ernestdefoe-page-builder' in flarum.extensions) registerBlocks();

  /*
   * A place to click, in the nav every other page uses.
   *
   * 🚨 `IndexSidebar`, not `IndexPage`. Flarum 2 moved the index nav into its
   * own component, and extending the old one is not an error — the callback is
   * simply attached to a `navItems` nobody calls. The link was built, styled
   * and translated, and the only route to the roster was typing the URL.
   */
  extend(IndexSidebar.prototype, 'navItems', function (items: ItemList<Mithril.Children>) {
    items.add(
      'roster',
      <LinkButton href={app.route('roster.index')} icon="fas fa-users">
        {app.translator.trans('ernestdefoe-roster.forum.title')}
      </LinkButton>,
      -10
    );
  });
});

export { default as extend } from './extend';
