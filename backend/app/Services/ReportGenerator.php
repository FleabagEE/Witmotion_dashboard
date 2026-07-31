<?php

namespace App\Services;

use App\Support\StructuralVibration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds evidence documents.
 *
 * A vibration report may end up attached to a damage claim, so the priority is
 * reproducibility over presentation. Every report stores the parameters, the
 * software and processing versions, and a checksum of its own content, so the
 * same document can be regenerated and shown to be the same document.
 *
 * Gaps are reported as gaps. A quiet hour and an hour the sensor was offline
 * look identical on a chart, and conflating them would let a report imply the
 * building was still when nothing was actually being measured.
 */
class ReportGenerator
{
    /**
     * Bumped whenever the arithmetic below changes. A stored report records the
     * version that produced it, so an old document is never silently reinterpreted
     * under new rules.
     */
    public const PROCESSING_VERSION = '1.0.0';

    public function summary(
        Carbon $from,
        Carbon $to,
        ?string $sensorId = null,
        string $generatedBy = 'system',
        string $timezone = 'UTC',
    ): array {
        $channels = $this->channelStatistics($from, $to, $sensorId);
        $alarms = $this->alarmsInWindow($from, $to);
        $coverage = $this->coverage($from, $to, $sensorId);

        $payload = [
            'report_uid' => 'RPT-'.strtoupper(Str::random(10)),
            'type' => 'summary',
            'title' => sprintf(
                'Vibration summary %s to %s',
                $from->copy()->tz($timezone)->format('Y-m-d H:i'),
                $to->copy()->tz($timezone)->format('Y-m-d H:i'),
            ),
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'timezone' => $timezone,
            'parameters' => ['sensor_id' => $sensorId, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'software_version' => config('app.version', 'dev'),
            'processing_version' => self::PROCESSING_VERSION,
            'standard_tables_status' => StructuralVibration::STATUS,
            'generated_by' => $generatedBy,
            'generated_at' => now()->toIso8601String(),
            'channels' => $channels,
            'alarms' => $alarms,
            'coverage' => $coverage,
        ];

        $payload['content_checksum'] = hash('sha256', json_encode([
            $payload['parameters'], $channels, $alarms, $coverage, self::PROCESSING_VERSION,
        ]));

        return $payload;
    }

    /** Persist the report's provenance so it can be reproduced and audited. */
    public function persist(array $report): int
    {
        return DB::table('reports')->insertGetId([
            'report_uid' => $report['report_uid'],
            'type' => $report['type'],
            'title' => $report['title'],
            'parameters' => json_encode($report['parameters']),
            'window_from' => $report['window']['from'],
            'window_to' => $report['window']['to'],
            'timezone' => $report['timezone'],
            'software_version' => $report['software_version'],
            'processing_version' => $report['processing_version'],
            'standard_tables_status' => $report['standard_tables_status'],
            'generated_by' => $report['generated_by'],
            'generated_at' => now(),
            'content_checksum' => $report['content_checksum'],
            'row_count' => count($report['channels']),
        ]);
    }

    private function channelStatistics(Carbon $from, Carbon $to, ?string $sensorId): array
    {
        $bindings = [$from, $to];
        $filter = '';
        if ($sensorId !== null) {
            $filter = 'AND sensor_id = ?';
            $bindings[] = $sensorId;
        }

        $rows = DB::select(<<<SQL
            SELECT sensor_id, channel_key, unit,
                   count(*) AS samples,
                   count(*) FILTER (WHERE quality <> 'good') AS degraded,
                   min(value) AS min_value,
                   max(value) AS max_value,
                   avg(value) AS avg_value,
                   stddev_samp(value) AS stddev_value
            FROM measurements
            WHERE time BETWEEN ? AND ? {$filter}
            GROUP BY sensor_id, channel_key, unit
            ORDER BY sensor_id, channel_key
        SQL, $bindings);

        return array_map(fn ($r) => [
            'sensor_id' => $r->sensor_id,
            'channel_key' => $r->channel_key,
            'unit' => $r->unit,
            'samples' => (int) $r->samples,
            'degraded' => (int) $r->degraded,
            'min' => $r->min_value === null ? null : round((float) $r->min_value, 6),
            'max' => $r->max_value === null ? null : round((float) $r->max_value, 6),
            'avg' => $r->avg_value === null ? null : round((float) $r->avg_value, 6),
            'stddev' => $r->stddev_value === null ? null : round((float) $r->stddev_value, 6),
        ], $rows);
    }

    private function alarmsInWindow(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('alarm_events as e')
            ->leftJoin('alarm_definitions as d', 'd.id', '=', 'e.alarm_definition_id')
            ->whereBetween('e.raised_at', [$from, $to])
            ->orderBy('e.raised_at')
            ->get([
                'e.id', 'd.name', 'e.channel_key', 'e.level', 'e.peak_level', 'e.state',
                'e.trigger_value', 'e.peak_value', 'e.threshold', 'e.unit',
                'e.raised_at', 'e.cleared_at', 'e.acknowledged_at', 'e.provisional',
                'd.thresholds_confirmed_by',
            ]);

        return $rows->map(fn ($r) => (array) $r)->all();
    }

    /**
     * How much of the window actually carried data.
     *
     * A gap is not a quiet period. Reporting them the same way would let the
     * document imply the structure was still when in fact nothing was measured.
     */
    private function coverage(Carbon $from, Carbon $to, ?string $sensorId): array
    {
        $bindings = [$from, $to];
        $filter = '';
        if ($sensorId !== null) {
            $filter = 'AND sensor_id = ?';
            $bindings[] = $sensorId;
        }

        $row = DB::selectOne(<<<SQL
            SELECT count(DISTINCT date_trunc('minute', time)) AS minutes_with_data
            FROM measurements
            WHERE time BETWEEN ? AND ? {$filter}
        SQL, $bindings);

        $totalMinutes = max(1, (int) ceil($from->diffInMinutes($to)));
        $withData = (int) ($row->minutes_with_data ?? 0);

        return [
            'window_minutes' => $totalMinutes,
            'minutes_with_data' => $withData,
            'coverage_percent' => round(min(100, $withData / $totalMinutes * 100), 2),
            'gap_minutes' => max(0, $totalMinutes - $withData),
        ];
    }

    public function toCsv(array $report): string
    {
        $out = fopen('php://temp', 'r+');

        // Provenance first, so a CSV opened years later still explains itself.
        fputcsv($out, ['# report_uid', $report['report_uid']]);
        fputcsv($out, ['# title', $report['title']]);
        fputcsv($out, ['# window_from', $report['window']['from']]);
        fputcsv($out, ['# window_to', $report['window']['to']]);
        fputcsv($out, ['# generated_by', $report['generated_by']]);
        fputcsv($out, ['# generated_at', $report['generated_at']]);
        fputcsv($out, ['# software_version', $report['software_version']]);
        fputcsv($out, ['# processing_version', $report['processing_version']]);
        fputcsv($out, ['# standard_tables', $report['standard_tables_status']]);
        fputcsv($out, ['# coverage_percent', $report['coverage']['coverage_percent']]);
        fputcsv($out, ['# gap_minutes', $report['coverage']['gap_minutes']]);
        fputcsv($out, ['# content_checksum', $report['content_checksum']]);
        fputcsv($out, []);

        fputcsv($out, ['sensor_id', 'channel_key', 'unit', 'samples', 'degraded', 'min', 'max', 'avg', 'stddev']);
        foreach ($report['channels'] as $c) {
            fputcsv($out, [
                $c['sensor_id'], $c['channel_key'], $c['unit'], $c['samples'], $c['degraded'],
                $c['min'], $c['max'], $c['avg'], $c['stddev'],
            ]);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
