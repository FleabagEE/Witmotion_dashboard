<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pruning replay protection must not weaken it.
 *
 * The table stops a crashed forwarder from double-inserting a batch it re-offers.
 * That only reaches back as far as the spool holds - measured at 10.1 hours - so
 * old rows are dead weight. But delete too aggressively and a genuine replay
 * becomes a duplicate, which is the fault the table exists to prevent.
 */
class PruneIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function poll(string $key, string $measuredAt): void
    {
        DB::table('ingested_polls')->insert([
            'idempotency_key' => $key,
            'appliance_id' => 'QV-EDGE-TEST',
            'run_id' => 'r1',
            'sensor_id' => 'SENSOR-001',
            'group_key' => 'motion',
            'sequence' => 1,
            'measured_at' => $measuredAt,
            'quality' => 'good',
            'channel_count' => 3,
            'simulated' => false,
            'ingested_at' => now(),
        ]);
    }

    public function test_recent_replay_protection_is_kept(): void
    {
        // Anything the spool could still re-offer must survive. The spool holds
        // about 10 hours, so a day-old row is well inside the window that
        // matters.
        $this->poll('recent', now()->subHours(2)->toDateTimeString());
        $this->poll('yesterday', now()->subDay()->toDateTimeString());

        $this->artisan('ingest:prune-idempotency --days=7')->assertSuccessful();

        $this->assertSame(2, DB::table('ingested_polls')->count());
    }

    public function test_expired_rows_are_deleted(): void
    {
        $this->poll('ancient', now()->subDays(30)->toDateTimeString());
        $this->poll('recent', now()->subHours(1)->toDateTimeString());

        $this->artisan('ingest:prune-idempotency --days=7')->assertSuccessful();

        $this->assertSame(1, DB::table('ingested_polls')->count());
        $this->assertSame('recent', DB::table('ingested_polls')->value('idempotency_key'));
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->poll('ancient', now()->subDays(30)->toDateTimeString());

        $this->artisan('ingest:prune-idempotency --days=7 --dry-run')
            ->expectsOutputToContain('would be deleted')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('ingested_polls')->count());
    }

    public function test_the_window_can_never_be_shorter_than_a_day(): void
    {
        // A typo of --days=0 would delete rows the spool is still holding and
        // turn the next replay into duplicated measurements.
        $this->poll('recent', now()->subHours(2)->toDateTimeString());

        $this->artisan('ingest:prune-idempotency --days=0')->assertSuccessful();

        $this->assertSame(1, DB::table('ingested_polls')->count());
    }
}
