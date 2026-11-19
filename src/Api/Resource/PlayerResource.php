<?php

namespace ErnestDefoe\Roster\Api\Resource;

use ErnestDefoe\Roster\Player;
use ErnestDefoe\Roster\Service\ProfileRequests;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * Players (`/api/roster-players`). Read-only: the hourly sync is the only writer.
 *
 * Only Show exists. A player is always reached from his club's roster, which
 * includes the whole roster in one request.
 *
 * @extends AbstractDatabaseResource<Player>
 */
class PlayerResource extends AbstractDatabaseResource
{
    public function __construct(protected ProfileRequests $profiles)
    {
    }

    public function type(): string
    {
        return 'roster-players';
    }

    public function model(): string
    {
        return Player::class;
    }

    /** A player's page is addressed by his slug, which is what the URL carries. */
    public function find(string $id, BaseContext $context): ?object
    {
        return $this->query($context)->where('slug', $id)->first();
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make()
                ->can('viewForum')
                ->eagerLoad('team'),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name'),
            Schema\Str::make('firstName')->nullable()
                ->get(fn (Player $player) => $player->first_name),
            Schema\Str::make('lastName')->nullable()
                ->get(fn (Player $player) => $player->last_name),
            Schema\Str::make('slug'),
            Schema\Str::make('position')->nullable(),
            Schema\Str::make('positionGroup')
                ->get(fn (Player $player) => $player->group),
            Schema\Integer::make('jersey')->nullable(),
            Schema\Str::make('height')->nullable()
                ->get(fn (Player $player) => $player->height_label),
            Schema\Integer::make('weight')->nullable(),
            Schema\Str::make('hometown')->nullable(),
            // Where a professional played his college football or basketball.
            Schema\Str::make('college')->nullable(),
            Schema\Integer::make('classYear')->nullable()
                ->get(fn (Player $player) => $player->class_year),
            Schema\Str::make('photo')->nullable()
                ->get(fn (Player $player) => $player->photo_url),

            /*
             * Everything ESPN and Wikipedia have on him, on his own page only:
             * a roster lists a hundred players and needs none of it.
             */
            Schema\Arr::make('profile')
                ->visible(fn (Player $player, Context $context) => $context->showing(self::class))
                ->get(fn (Player $player, Context $context) => $this->profiles->for(
                    $player,
                    (string) $context->request->getAttribute('ipAddress')
                )),

            Schema\Relationship\ToOne::make('team')
                ->type('roster-teams')
                ->includable(),
        ];
    }
}
