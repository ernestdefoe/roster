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
 * @property int|null    $cfbd_id
 * @property string|null $external_id
 * @property \Carbon\Carbon|null $roster_at
 */
class Team extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'roster_teams';

    protected $fillable = [
        'league', 'name', 'slug', 'mascot', 'abbreviation', 'conference',
        'color', 'logo', 'logo_dark', 'cfbd_id', 'external_id', 'roster_at',
    ];

    protected $casts = [
        'cfbd_id' => 'integer',
        'roster_at' => 'datetime',
    ];

    /** @return HasMany<Player, $this> */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class, 'team_id');
    }

    /** Whether this club is one CollegeFootballData answers for. */
    public function isCollegiate(): bool
    {
        return (new Service\Leagues\Leagues())->get($this->league)->collegiate;
    }
}
