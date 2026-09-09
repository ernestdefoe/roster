import app from 'flarum/admin/app';

/**
 * Admin entry. Everything is registered declaratively in ./extend — see the
 * note there about why nothing imperative may live here.
 */
app.initializers.add('ernestdefoe-roster', () => {});

export { default as extend } from './extend';
