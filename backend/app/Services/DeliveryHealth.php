<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Are the readings reaching the database, and if not, are they safe?
 *
 * A THIRD QUESTION, AND THE ONE NOBODY WAS ASKING
 * -----------------------------------------------
 *
 * `StructureMovement` answers what the silo did. `SensorHealth` answers whether
 * the instruments can be believed. Neither answers what happened on 2026-08-06,
 * when the database was down for sixteen hours: the sensors were perfect, the
 * structure was still, and the dashboard was an error page — and once it came
 * back it would have shown yesterday's readings with nothing to say they were
 * yesterday's.
 *
 * The distinction that matters is between two failures that look identical on a
 * chart and could not be more different to an operator:
 *
 *   the readings are not arriving     - and they are safe on disk, be patient
 *   the readings are not arriving     - and they are being lost, act now
 *
 * `SensorHealth` cannot tell these apart. It sees the same silence and says
 * "silent for 900s", which reads as a dead sensor when the sensor is fine and
 * the spool is doing exactly what it was built for. Guessing wrong in that
 * direction sends somebody to a silo to check a cable that is not broken.
 *
 * WHERE THE NUMBERS COME FROM
 * ---------------------------
 *
 * The forwarder writes them every batch to a Prometheus text file. Read rather
 * than queried, because the spool is a SQLite database owned by another service
 * account and opening it from here would mean two writers and a lock to argue
 * over. A stale file is itself the signal that the forwarder has stopped.
 */
class DeliveryHealth
{
    /** Beyond this the metrics file is describing a forwarder that is gone. */
    private const STALE_SECONDS = 120;

    /**
     * Backlog that means something is wrong rather than merely busy.
     *
     * The forwarder clears roughly 3,000 records a second once the database is
     * up, and the sensors produce three a second. A backlog of 10,000 is three
     * seconds of work and entirely normal during a restart; ten times that is a
     * forwarder losing the race.
     */
    private const BACKLOG_WARN = 100_000;

    public function __construct(private readonly string $path)
    {
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        if (! is_readable($this->path)) {
            return $this->unknown(
                'The forwarder has not reported. Either it has never run, or its '
                .'metrics file is not readable from here.'
            );
        }

        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            return $this->unknown('The forwarder metrics could not be read.');
        }

        $written = Carbon::createFromTimestamp(filemtime($this->path) ?: 0);
        $age = (int) $written->diffInSeconds(now(), true);

        $backlog = $this->metric($contents, 'quakevault_forwarder_backlog');
        $dead = $this->metric($contents, 'quakevault_forwarder_dead_letters');
        $delivered = $this->metric($contents, 'quakevault_forwarder_delivered_total');

        // Stale first. Every other number in the file is a claim about a moment
        // that may be hours ago, and reporting a healthy backlog from a dead
        // forwarder is worse than reporting nothing.
        if ($age > self::STALE_SECONDS) {
            return [
                'state' => 'fail',
                'summary' => sprintf(
                    'The forwarder stopped reporting %s ago. Readings are still being '
                    .'recorded to disk, but nothing is moving them into the database.',
                    $written->diffForHumans(syntax: true)
                ),
                'action' => 'systemctl status quakevault-forwarder',
                'backlog' => $backlog,
                'dead_letters' => $dead,
                'delivered_last_cycle' => $delivered,
                'reported_at' => $written->toIso8601String(),
                'age_seconds' => $age,
            ];
        }

        [$state, $summary, $action] = $this->judge($backlog, $dead);

        return [
            'state' => $state,
            'summary' => $summary,
            'action' => $action,
            'backlog' => $backlog,
            'dead_letters' => $dead,
            'delivered_last_cycle' => $delivered,
            'reported_at' => $written->toIso8601String(),
            'age_seconds' => $age,
        ];
    }

    /** @return array{0:string,1:string,2:?string} */
    private function judge(?int $backlog, ?int $dead): array
    {
        if ($dead !== null && $dead > 0) {
            // Said first, because this is the only state here that will not fix
            // itself. Everything else drains; these records are parked until
            // somebody decides.
            return [
                'warn',
                sprintf(
                    '%s reading(s) are parked past the retry ceiling. They are still on '
                    .'disk and nothing is lost, but they will not be delivered until an '
                    .'operator releases them. A long outage strands healthy readings this '
                    .'way.',
                    number_format($dead)
                ),
                'qv-spool retry-dead-letters --confirm',
            ];
        }

        if ($backlog !== null && $backlog > self::BACKLOG_WARN) {
            return [
                'warn',
                sprintf(
                    '%s reading(s) are waiting to be written. They are safe on disk. This '
                    .'is normal while catching up after an outage and a problem if it '
                    .'keeps growing.',
                    number_format($backlog)
                ),
                null,
            ];
        }

        return [
            'pass',
            $backlog === null
                ? 'Readings are being delivered.'
                : sprintf('Readings are being delivered; %s waiting.', number_format($backlog)),
            null,
        ];
    }

    private function metric(string $contents, string $name): ?int
    {
        // Matches `name{labels} value` and `name value`. The appliance always
        // writes a label, but a metric file that loses one should degrade to a
        // number rather than to nothing.
        if (preg_match('/^'.preg_quote($name, '/').'(?:\{[^}]*\})?\s+([0-9.]+)$/m', $contents, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function unknown(string $summary): array
    {
        return [
            'state' => 'unknown',
            'summary' => $summary,
            'action' => null,
            'backlog' => null,
            'dead_letters' => null,
            'delivered_last_cycle' => null,
            'reported_at' => null,
            'age_seconds' => null,
        ];
    }
}
