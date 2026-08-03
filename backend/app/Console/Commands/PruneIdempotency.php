<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prunes the replay-protection table.
 *
 * `ingested_polls` exists so a forwarder that crashes mid-batch can re-offer the
 * same envelopes without duplicating them. It is a guard, not a record - the
 * measurements themselves live in the hypertable, which has its own retention.
 *
 * Nothing pruned it. Measured on 2026-08-03: 3.9 million rows in three days,
 * 1857 MB across heap and indexes, growing about 1.3 million rows a day. At a
 * year that is roughly 470 million rows and 225 GB - on an appliance, for a
 * table whose entire purpose expires within hours.
 *
 * Hours, because that is how far a replay can reach. The spool holds 500 000
 * envelopes, measured at 10.1 hours of coverage, and a forwarder cannot re-offer
 * anything it no longer holds. Seven days is that window with a very wide margin.
 *
 * Deleted in batches so a long-overdue first run cannot hold one transaction open
 * across millions of rows and block ingestion behind it.
 */
class PruneIdempotency extends Command
{
    protected $signature = 'ingest:prune-idempotency
                            {--days=7 : keep replay protection for this many days}
                            {--batch=50000}
                            {--dry-run}';

    protected $description = 'Delete expired replay-protection rows from ingested_polls';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $batch = max(1000, (int) $this->option('batch'));

        $expired = DB::table('ingested_polls')
            ->where('measured_at', '<', now()->subDays($days))
            ->count();

        $total = DB::table('ingested_polls')->count();

        $this->line(sprintf('%s rows total, %s older than %d day(s)',
            number_format($total), number_format($expired), $days));

        if ($expired === 0) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn(sprintf('%s rows would be deleted. Re-run without --dry-run.',
                number_format($expired)));

            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            // A bounded delete per statement. The first run after this was
            // introduced has years of backlog to clear on a busy appliance, and
            // one transaction over millions of rows would hold locks long enough
            // to stall ingestion - turning a cleanup into an outage.
            $rows = DB::delete(
                'DELETE FROM ingested_polls WHERE id IN (
                    SELECT id FROM ingested_polls WHERE measured_at < ? LIMIT ?
                 )',
                [now()->subDays($days), $batch],
            );
            $deleted += $rows;
            if ($rows > 0) {
                $this->line(sprintf('  deleted %s of %s', number_format($deleted), number_format($expired)));
            }
        } while ($rows > 0);

        $this->info(sprintf('%s rows pruned.', number_format($deleted)));
        // Said rather than done: VACUUM FULL would reclaim the space but takes an
        // exclusive lock, and on a live appliance that is a decision for an
        // operator at a chosen moment, not a side effect of a scheduled cleanup.
        $this->line('Disk space is reused by future inserts. To return it to the');
        $this->line('filesystem, run VACUUM FULL ingested_polls during a maintenance');
        $this->line('window - it takes an exclusive lock and blocks ingestion.');

        return self::SUCCESS;
    }
}
