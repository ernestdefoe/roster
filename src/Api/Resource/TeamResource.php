<?php

namespace ErnestDefoe\Roster\Api\Resource;

use ErnestDefoe\Roster\Service\Leagues\Leagues;
use ErnestDefoe\Roster\Team;
use Flarum\Api\Context as FlarumContext;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Locale\TranslatorInterface;
use Tobyz\JsonApiServer\Context;

/**
 * Clubs (`/api/roster-teams`). Read-only: the hourly sync is the only writer.
 *
 * @extends AbstractDatabaseResource<Team>
 */
class TeamResource extends AbstractDatabaseResource
{
    /**
     * A league is a few dozen clubs; FBS college football, the largest, is
     * 138. The index asks for the whole league in one page.
     */
    public const PER_PAGE = 200;

    public function __construct(
        protected Leagues $leagues,
        protected TranslatorInterface $translator,
    ) {
    }

    public function type(): string
    {
        return 'roster-teams';
    }

    public function model(): string
    {
        return Team::class;
    }

    /** A club's page is addressed by its slug, which is what the URL carries. */
    public function find(string $id, Context $context): ?object
    {
        return $this->query($context)->where('slug', $id)->first();
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make()
                ->can('viewForum'),
            Endpoint\Index::make()
                ->can('viewForum')
                ->defaultSort('conference,name')
                ->paginate(self::PER_PAGE, self::PER_PAGE),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name'),
            Schema\Str::make('slug'),
            Schema\Str::make('mascot')->nullable(),
            Schema\Str::make('abbreviation')->nullable(),
            Schema\Str::make('conference'),
            Schema\Str::make('group')
                ->get(fn (Team $team) => $this->group($team)),
            Schema\Str::make('logo')->nullable(),
            Schema\Str::make('logoDark')->nullable()
                ->get(fn (Team $team) => $team->logo_dark),
            Schema\Str::make('color')->nullable(),
            Schema\Str::make('league'),
            /*
             * 🚨 The client is TOLD whether this is collegiate rather than
             * working it out from the league key. There is no such thing as a
             * sophomore in the NBA, so the class column is not drawn.
             */
            Schema\Boolean::make('collegiate')
                ->get(fn (Team $team) => $this->leagues->get($team->league)->collegiate),

            /*
             * 🚨 On a club's own page only. Includable on the list as well, one
             * request could serialize every player of every club in a league
             * — fifteen thousand for college football.
             */
            Schema\Relationship\ToMany::make('players')
                ->type('roster-players')
                ->includable()
                ->visible(fn (Team $team, FlarumContext $context) => $context->showing(self::class)),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('conference'),
            SortColumn::make('name'),
        ];
    }

    /**
     * The heading a club is filed under on the index.
     *
     * 🚨 "Independent" is a college-football word. A professional club with no
     * division — most of league football, where there is one table and no
     * groups — is not independent of anything, so it is filed under the
     * league's own name instead.
     */
    private function group(Team $team): string
    {
        $conference = trim((string) $team->conference);

        if ($conference !== '') {
            return $conference;
        }

        return $team->league === Leagues::DEFAULT
            ? $this->translator->trans('ernestdefoe-roster.api.independent')
            : $this->leagues->get($team->league)->name;
    }
}
