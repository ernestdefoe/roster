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
 * @property string|null $external_id
 * @property-read string|null $height_label
 * @property-read string|null $hometown
 * @property-read string      $group
 * @property-read Team|null $team
 */
class Player extends AbstractModel
{
    /**
     * 🚨 The gridiron position order, used ONLY for gridiron. Every other sport
     * arrives already grouped by the provider — Pitchers, Centers, Guards — and
     * running a shortstop through a list of football positions returns "other"
     * for every player on the team.
     */
    private const GRIDIRON = [
        'offense' => ['QB', 'RB', 'FB', 'WR', 'TE', 'OL', 'OT', 'OG', 'C'],
        'defense' => ['DL', 'DE', 'DT', 'NT', 'LB', 'ILB', 'OLB', 'EDGE', 'CB', 'S', 'DB', 'FS', 'SS'],
        'specialists' => ['K', 'P', 'LS', 'PK', 'ATH'],
    ];

    /** ESPN's own names for the gridiron units, as the keys the locale file translates. */
    private const GRIDIRON_GROUPS = [
        'offense' => 'offense',
        'defense' => 'defense',
        'special team' => 'specialists',
        'special teams' => 'specialists',
    ];

    public $timestamps = true;

    protected $table = 'roster_players';

    protected $casts = [
        'jersey' => 'integer',
        'height' => 'integer',
        'weight' => 'integer',
        'class_year' => 'integer',
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

        return $inches > 0 ? intdiv($inches, 12).'-'.($inches % 12) : null;
    }

    /**
     * The hometown: city and state, or city and country where there is no
     * state (most of soccer); null when none of it is known.
     */
    public function getHometownAttribute(): ?string
    {
        $region = trim((string) $this->home_state) ?: trim((string) $this->home_country);
        $parts = array_filter([trim((string) $this->home_city), $region]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * The group the provider gave, or the gridiron unit his position belongs to.
     * A gridiron unit comes back as its locale key (`offense`, `specialists`);
     * every other sport's group is returned as the provider wrote it.
     */
    public function getGroupAttribute(): string
    {
        $group = trim((string) $this->position_group);

        if ($group !== '') {
            return self::GRIDIRON_GROUPS[mb_strtolower($group)] ?? $group;
        }

        $position = strtoupper(trim((string) $this->position));

        foreach (self::GRIDIRON as $unit => $positions) {
            if (in_array($position, $positions, true)) {
                return $unit;
            }
        }

        return 'other';
    }
}
