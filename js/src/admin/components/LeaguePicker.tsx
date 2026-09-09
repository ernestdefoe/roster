import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';

declare const m: any;

const t = (k: string) => app.translator.trans('ernestdefoe-roster.admin.' + k);

interface Attrs {
  /**
   * The settings page's OWN stream for `ernestdefoe-roster.leagues`.
   *
   * 🚨 The stream, never `app.data.settings`. The page decides what to save by
   * comparing each stream against `app.data.settings`, so a control that wrote
   * straight into `app.data.settings` would tick, look saved, and send nothing
   * — the settings page would report no changes with the boxes visibly moved.
   */
  value: (next?: string) => string;
}

/**
 * Which competitions get rosters, as tick boxes over one comma-joined setting.
 *
 * 🚨 College football is deliberately absent. It is CollegeFootballData's, not
 * ESPN's, and it is not optional — offering it here as a box would suggest it
 * can be switched off, and unticking it would silently do nothing.
 */
export default class LeaguePicker extends Component<Attrs> {
  chosen(): string[] {
    return String(this.attrs.value() || '')
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean);
  }

  toggle(key: string, on: boolean) {
    const chosen = this.chosen();
    const next = on ? [...new Set([...chosen, key])] : chosen.filter((k) => k !== key);

    this.attrs.value(next.join(','));
  }

  view() {
    /*
     * 🚨 From the SERVER's registry, never a copy written here. A second list
     * in the bundle goes stale the first time an extension registers a league,
     * which is the whole reason the registry exists.
     */
    const registry: Record<string, string> = (app.data as any)?.rosterLeagues ?? {};
    const chosen = this.chosen();
    const keys = Object.keys(registry).filter((key) => key !== 'cfb');

    return (
      <div className="Form-group RosterLeagues">
        <label>{t('leagues_label')}</label>
        <div className="helpText">{t('leagues_help')}</div>

        {/* An empty registry means the payload never arrived. Said out loud,
            because an empty box list is indistinguishable from "no leagues". */}
        {keys.length === 0 ? <div className="helpText">{t('leagues_empty')}</div> : null}

        {keys.map((key) => (
          <label className="checkbox">
            <input
              type="checkbox"
              checked={chosen.includes(key)}
              onchange={(e: any) => this.toggle(key, e.target.checked)}
            />
            {registry[key]}
          </label>
        ))}
      </div>
    );
  }
}
