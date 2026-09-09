import Extend from 'flarum/common/extenders';
import LeaguePicker from './components/LeaguePicker';

declare const m: any;

/**
 * Declarative admin registration — the Flarum 2 way.
 *
 * 🚨 Flarum 2 REMOVED `app.extensionData`. The imperative
 * `app.extensionData.for('…').registerSetting(…)` this replaces threw
 * "undefined is not an object (evaluating 'app.extensionData.for')" the moment
 * the initializer ran, and core catches that per extension — so the whole admin
 * kept working and Roster alone reported "failed to initialize", with the real
 * cause only in the browser console.
 */
export default [
  new Extend.Admin()
    /*
     * 🚨 `customSetting`, not `setting`. A `setting()` callback is invoked
     * expecting a descriptor object, so one returning a vnode crashes the page.
     *
     * 🚨 A plain function, NOT an arrow. Core calls it with `entry.call(this)`,
     * where `this` is the settings page — which is the only way to reach the
     * page's own `setting()` stream, and therefore the only way the tick boxes
     * are ever saved.
     */
    .customSetting(function (this: any) {
      return m(LeaguePicker, { value: this.setting('ernestdefoe-roster.leagues', '') });
    }),
];
