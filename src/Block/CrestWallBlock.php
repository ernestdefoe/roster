<?php

declare(strict_types=1);

namespace ErnestDefoe\Roster\Block;

use Ernestdefoe\PageBuilder\Block\AbstractBlock;
use ErnestDefoe\Roster\Player;
use ErnestDefoe\Roster\Team;
use Flarum\User\User;

/**
 * Every crest in the competition, moving.
 *
 * 🚨 Only ever loaded where Page Builder is installed — the extender that
 * registers it is added conditionally in extend.php and nothing else names it.
 * It extends a class from an extension this one does not require.
 */
class CrestWallBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'roster-crests';
    }

    public function name(): string
    {
        return 'Crest wall';
    }

    public function icon(): string
    {
        return 'fas fa-shield-halved';
    }

    public function category(): string
    {
        return 'forum';
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'league', 'type' => 'text', 'label' => 'League key', 'default' => 'cfb', 'help' => 'Which competition\'s clubs to show — cfb, nfl, nba…'],
            ['key' => 'caption', 'type' => 'text', 'label' => 'Caption', 'default' => 'Every program in the country', 'help' => 'Leave empty for no caption.'],
            [
                'key' => 'show_counts',
                'type' => 'toggle',
                'label' => 'Show the numbers',
                'default' => true,
                'help' => 'Programs and players actually held, counted at render — never a number typed in by hand.',
            ],
            ['key' => 'rows', 'type' => 'range', 'label' => 'Rows', 'default' => 2, 'min' => 1, 'max' => 3],
        ];
    }

    /**
     * 🚨 Resolved server-side and shuffled ONCE per render, not per viewer in
     * JavaScript. A wall that reordered itself on every redraw would reshuffle
     * while somebody was looking at it.
     */
    public function resolve(array $settings, User $actor): array
    {
        $league = preg_replace('/[^a-z0-9_-]/i', '', (string) ($settings['league'] ?? 'cfb')) ?: 'cfb';

        $teams = Team::query()
            ->where('league', $league)
            ->whereNotNull('logo')
            ->where('logo', '!=', '')
            ->get(['name', 'slug', 'logo', 'logo_dark', 'conference']);

        /*
         * 🚨 Shuffled, so the wall does not open with three Air Forces and an
         * Akron. Alphabetical order in a decorative strip reads as a list that
         * was cut off rather than as a wall of everybody.
         */
        $rows = $teams->shuffle()->map(fn (Team $t) => [
            'name' => (string) $t->name,
            'slug' => (string) $t->slug,
            'logo' => (string) $t->logo,
            'logoDark' => (string) ($t->logo_dark ?: $t->logo),
        ])->values()->all();

        return [
            'teams' => $rows,
            'counts' => ($settings['show_counts'] ?? true) === false ? null : [
                'teams' => count($rows),
                // Counted, never typed. A hand-written "15,000+" is wrong the
                // week after somebody writes it and nobody notices for a year.
                'players' => Player::query()->where('league', $league)->count(),
            ],
        ];
    }
}
