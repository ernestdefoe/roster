<?php

namespace ErnestDefoe\Roster\Sitemap;

use ErnestDefoe\Roster\Team;
use FoF\Sitemap\Resources\Resource;
use FoF\Sitemap\Sitemap\Frequency;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every team's roster page, offered to search engines.
 *
 * 🚨 This class names a parent that belongs to another extension, so nothing
 * may MENTION it unless fof/sitemap is installed — see the guard in extend.php.
 * PHP resolves a parent class at load time, and a reference from an always-run
 * file would be a fatal error on a site that simply does not use sitemaps.
 *
 * 🚨 Weekly, not daily. A roster changes a handful of times a season; telling a
 * crawler otherwise spends its budget on 136 pages that have not moved and
 * leaves less of it for the game threads, which do.
 */
class TeamPages extends Resource
{
    public function query(): Builder
    {
        // A team with no players is an empty page, and an empty page indexed is
        // a thin page that drags the rest of the site down with it.
        return Team::query()->whereHas('players');
    }

    public function url($model): string
    {
        return $this->generateRouteUrl('roster.team', ['slug' => $model->slug]);
    }

    public function priority(): float
    {
        return 0.6;
    }

    public function frequency(): string
    {
        return Frequency::WEEKLY;
    }

    public function enabled(): bool
    {
        return true;
    }
}
