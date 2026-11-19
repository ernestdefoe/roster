import Model from 'flarum/common/Model';
import type Player from './Player';

/** A club, as `/api/roster-teams` serves it. */
export default class Team extends Model {
  name = Model.attribute<string>('name');
  slug = Model.attribute<string>('slug');
  mascot = Model.attribute<string | null>('mascot');
  abbreviation = Model.attribute<string | null>('abbreviation');
  conference = Model.attribute<string>('conference');
  /** The heading the index files it under: its conference, or a stand-in when it has none. */
  group = Model.attribute<string>('group');
  logo = Model.attribute<string | null>('logo');
  logoDark = Model.attribute<string | null>('logoDark');
  color = Model.attribute<string | null>('color');
  league = Model.attribute<string>('league');
  collegiate = Model.attribute<boolean>('collegiate');
  players = Model.hasMany<Player>('players');
}
