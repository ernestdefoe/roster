<?php

namespace ErnestDefoe\Roster;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $team_id
 * @property string      $league
 * @property string      $name
 * @property string      $slug
 * @property string|null $position
 * @property string      $position_group
 * @property int|null    $jersey
 * @property int|null    $height
 * @property int|null    $weight
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $home_city
 * @property string|null $home_state
 * @property string|null $home_country
 * @property int|null    $class_year
 * @property string|null $college
 * @property string|null $photo_url
 * @property int|null    $cfbd_id
 * @property string|null $external_id
 * @property-read string|null $height_label
 * @property-read string|null $hometown
 * @property-read Team|null $team
 */
class Player extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'roster_players';

    protected $fillable = [
        'team_id', 'league', 'name', 'slug', 'first_name', 'last_name',
        'position', 'position_group', 'jersey', 'height', 'weight',
        'home_city', 'home_state', 'home_country', 'class_year',
        'college', 'photo_url', 'cfbd_id', 'external_id',
    ];

    protected $casts = [
        'jersey' => 'integer',
        'height' => 'integer',
        'weight' => 'integer',
        'class_year' => 'integer',
        'cfbd_id' => 'integer',
    ];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * The listed height, as somebody would say it.
     *
     * 🚨 Null rather than 0'0" when it is unknown. ESPN omits a height for most
     * of league football, and "0-0" printed in a roster table reads as a data
     * error rather than as a figure nobody published.
     */
    public function getHeightLabelAttribute(): ?string
    {
        $inches = (int) $this->height;

        return $inches > 0 ? intdiv($inches, 12) . '-' . ($inches % 12) : null;
    }

    /** The hometown, or null when neither half is known. */
    public function getHometownAttribute(): ?string
    {
        $parts = array_filter([trim((string) $this->home_city), trim((string) $this->home_state)]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}
