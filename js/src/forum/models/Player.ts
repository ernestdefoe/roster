import Model from 'flarum/common/Model';
import type Team from './Team';
import type { Profile } from '../types';

/** A player, as `/api/roster-players` serves him. */
export default class Player extends Model {
  name = Model.attribute<string>('name');
  firstName = Model.attribute<string | null>('firstName');
  lastName = Model.attribute<string | null>('lastName');
  slug = Model.attribute<string>('slug');
  position = Model.attribute<string | null>('position');
  positionGroup = Model.attribute<string>('positionGroup');
  jersey = Model.attribute<number | null>('jersey');
  height = Model.attribute<string | null>('height');
  weight = Model.attribute<number | null>('weight');
  hometown = Model.attribute<string | null>('hometown');
  college = Model.attribute<string | null>('college');
  classYear = Model.attribute<number | null>('classYear');
  photo = Model.attribute<string | null>('photo');
  /** Only on his own page: what ESPN and Wikipedia have on him, and whether more is on its way. */
  profile = Model.attribute<Profile | undefined>('profile');
  team = Model.hasOne<Team>('team');
}
