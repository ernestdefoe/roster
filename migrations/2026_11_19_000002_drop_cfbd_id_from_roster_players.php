<?php

use Flarum\Database\Migration;

// Every league is read from ESPN, so the CollegeFootballData id the first
// schema made room for was never written.
return Migration::dropColumns('roster_players', [
    'cfbd_id' => ['bigInteger', 'nullable' => true],
]);
