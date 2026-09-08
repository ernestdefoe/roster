<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Clubs and the people on them.
 *
 * 🚨 TWO tables and no more, deliberately. The Convoro build of this grew a
 * season-by-season appearance table, a recruiting board and a transfer portal
 * because CollegeFootballData answers all three; ESPN answers none of them for
 * professional sport. Starting from what every league actually has — a club and
 * a current roster — means the college-only tables can arrive later as their own
 * migration, rather than sitting empty under every NBA page from day one.
 *
 * 🚨 `external_id` is a STRING and `cfbd_id` is nullable, which is the same
 * lesson the Picks schema learned the hard way: two providers writing to one
 * integer column means the value means different things on different rows, and
 * a zero written for "no id" collides on the second row of any unique index.
 */
return [
    'up' => function (Builder $schema) {
        $schema->create('roster_teams', function (Blueprint $table) {
            $table->increments('id');

            /*
             * Which competition. Matches Picks' league keys on purpose — a site
             * running both agrees because the keys agree, not because one
             * extension reaches into the other's tables.
             */
            $table->string('league', 20)->default('cfb');

            $table->string('name', 190);
            $table->string('slug', 200)->unique();
            $table->string('mascot', 120)->nullable();
            $table->string('abbreviation', 16)->nullable();

            // The division or conference this club is grouped under on the index.
            $table->string('conference', 100)->default('');

            $table->string('color', 16)->nullable();
            $table->string('logo', 255)->nullable();
            $table->string('logo_dark', 255)->nullable();

            $table->unsignedInteger('cfbd_id')->nullable();
            $table->string('external_id', 40)->nullable();

            /*
             * When this club's roster was last fetched. ESPN answers one club
             * per call, so the sync works oldest-first — and without a stamp it
             * would re-fetch the same first dozen every run and never reach the
             * rest.
             */
            $table->dateTime('roster_at')->nullable();

            $table->timestamps();

            $table->index('league');
            $table->index('conference');
            // 🚨 NULLs are distinct in a MySQL unique index, so every club that
            // has no provider id coexists happily under this.
            $table->unique(['league', 'external_id'], 'roster_teams_external');
        });

        $schema->create('roster_players', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id')->default(0);
            $table->string('league', 20)->default('cfb');

            $table->string('name', 190);
            $table->string('slug', 220)->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();

            $table->string('position', 12)->nullable();

            /*
             * 🚨 The group the PROVIDER gave — "Pitchers", "Centers", "offense".
             * The offence/defence/specialists split is a fact about gridiron and
             * nothing else, and mapping a shortstop through a list of football
             * positions returns "other" for every player on the team.
             */
            $table->string('position_group', 40)->default('');

            $table->unsignedSmallInteger('jersey')->nullable();
            $table->unsignedSmallInteger('height')->nullable();   // inches
            $table->unsignedSmallInteger('weight')->nullable();   // pounds

            $table->string('home_city', 120)->nullable();
            $table->string('home_state', 16)->nullable();
            $table->string('home_country', 60)->nullable();

            // College football's class (1–5). Null everywhere else, because
            // nobody in the NBA is a sophomore.
            $table->unsignedTinyInteger('class_year')->nullable();

            $table->string('college', 120)->nullable();
            $table->string('photo_url', 255)->nullable();

            $table->bigInteger('cfbd_id')->nullable();
            $table->string('external_id', 40)->nullable();

            $table->timestamps();

            $table->index('team_id');
            $table->index('name');
            $table->unique(['league', 'external_id'], 'roster_players_external');
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('roster_players');
        $schema->dropIfExists('roster_teams');
    },
];
