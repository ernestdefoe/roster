<?php

namespace ErnestDefoe\Roster\Search;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * `filter[league]=nfl`: one league's clubs, which is what the index shows.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class LeagueFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'league';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        /** @var DatabaseSearchState $state */
        $state->getQuery()->where('league', $negate ? '!=' : '=', $this->asString($value));
    }
}
