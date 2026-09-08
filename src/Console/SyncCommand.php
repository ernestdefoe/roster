<?php

namespace ErnestDefoe\Roster\Console;

use ErnestDefoe\Roster\Service\RosterSync;
use Illuminate\Console\Command;

/**
 * Clubs and rosters for every league this site follows.
 *
 * 🚨 HOURLY on the scheduler, and cheap by construction: it fetches at most a
 * dozen rosters a run and returns immediately on a site that follows no
 * ESPN-backed league, which is every site until somebody ticks one.
 */
class SyncCommand extends Command
{
    protected $signature = 'roster:sync';

    protected $description = 'Fetch clubs and rosters for the leagues this site follows.';

    public function handle(RosterSync $sync): int
    {
        $result = $sync->run();

        if (isset($result['skipped'])) {
            $this->line('Nothing to do: ' . $result['skipped'] . '.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d leagues, %d clubs, %d rosters fetched, %d players.',
            $result['leagues'],
            $result['teams'],
            $result['rosters'],
            $result['players'],
        ));

        return self::SUCCESS;
    }
}
