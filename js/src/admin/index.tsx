import app from 'flarum/admin/app';

declare const m: any;

const t = (k: string) => app.translator.trans('ernestdefoe-roster.admin.' + k);

app.initializers.add('ernestdefoe-roster', () => {
  app.extensionData
    .for('ernestdefoe-roster')
    .registerSetting(function () {
      /*
       * 🚨 The league list comes from the SERVER's registry, never from a copy
       * written here. A second list in the bundle goes stale the first time an
       * extension registers a competition.
       *
       * 🚨 College football is deliberately absent: it is not optional and it
       * is not ESPN's. Offering it as a tick box would suggest it can be
       * switched off here.
       */
      const registry: Record<string, string> = (app.data as any)?.rosterLeagues ?? {};
      const chosen = String(app.data.settings['ernestdefoe-roster.leagues'] || '')
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);

      const toggle = (key: string, on: boolean) => {
        const next = on ? [...new Set([...chosen, key])] : chosen.filter((k) => k !== key);
        app.data.settings['ernestdefoe-roster.leagues'] = next.join(',');
        m.redraw();
      };

      return (
        <div className="Form-group">
          <label>{t('leagues_label')}</label>
          <div className="helpText">{t('leagues_help')}</div>
          {Object.keys(registry)
            .filter((key) => key !== 'cfb')
            .map((key) => (
              <label className="checkbox">
                <input
                  type="checkbox"
                  checked={chosen.includes(key)}
                  onchange={(e: any) => toggle(key, e.target.checked)}
                />
                {registry[key]}
              </label>
            ))}
        </div>
      );
    });
});
