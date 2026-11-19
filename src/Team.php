<?php

namespace ErnestDefoe\Roster;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property string      $league
 * @property string      $name
 * @property string      $slug
 * @property string|null $mascot
 * @property string|null $abbreviation
 * @property string      $conference
 * @property string|null $color
 * @property string|null $logo
 * @property string|null $logo_dark
 * @property string|null $external_id
 * @property \Carbon\Carbon|null $roster_at
 */
class Team extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'roster_teams';

    protected $casts = [
        'roster_at' => 'datetime',
    ];

    /** @return HasMany<Player, $this> */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class, 'team_id');
    }
}
