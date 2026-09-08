<?php

namespace ErnestDefoe\Roster;

use Flarum\Database\AbstractModel;

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

    public function players()
    {
        return $this->hasMany(Player::class, 'team_id');
    }

    /** Whether this club is one CollegeFootballData answers for. */
    public function isCollegiate(): bool
    {
        return (new Service\Leagues\Leagues())->get($this->league)->collegiate;
    }
}
