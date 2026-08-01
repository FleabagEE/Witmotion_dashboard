<?php

namespace App\Console\Commands;

use App\Events\LiveMeasurement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class LiveBridge extends Command
{
    protected $signature = 'live:bridge {--channel=quakevault:live} {--max-hz=15}';

    protected $description = 'Relay the acquisition live feed onto websockets';

    public function handle(): int
    {
        $channel = $this->option('channel');
        $maxHz = max(1, (int) $this->option('max-hz'));
        $minInterval = 1.0 / $maxHz;
        $lastSent = [];
        $relayed = 0;
        $throttled = 0;

        $this->info("bridging {$channel} to websockets (cap {$maxHz} Hz per sensor/group)");

        // The 'live' connection, not the default one: it has the read timeout
        // disabled and the key prefix cleared, both of which a blocking
        // subscribe needs. See config/database.php.
        Redis::connection('live')->subscribe([$channel], function (string $message) use (
            &$lastSent, &$relayed, &$throttled, $minInterval
        ): void {
            $frame = json_decode($message, true);
            if (! is_array($frame) || ! isset($frame['sensor_id'])) {
                return;
            }

            // Cap the rate per sensor and group. A browser cannot usefully render
            // faster than this, and pushing every frame from a fast channel would
            // spend the client's main thread on work nobody can see.
            $key = $frame['sensor_id'].'|'.($frame['group'] ?? '');
            $now = microtime(true);
            if (isset($lastSent[$key]) && ($now - $lastSent[$key]) < $minInterval) {
                $throttled++;

                return;
            }
            $lastSent[$key] = $now;

            try {
                LiveMeasurement::dispatch($frame);
                $relayed++;
            } catch (\Throwable $exception) {
                // The live view is a convenience; the durable path is untouched.
                $this->warn('broadcast failed: '.$exception->getMessage());
            }

            if ($relayed % 500 === 0) {
                $this->line("relayed {$relayed}, throttled {$throttled}");
            }
        });

        return self::SUCCESS;
    }
}
