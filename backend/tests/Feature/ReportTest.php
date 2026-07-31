<?php

namespace Tests\Feature;

use App\Models\Appliance;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Services\ReportGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private ReportGenerator $generator;
    private Carbon $from;
    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $appliance = Appliance::create(['appliance_id' => 'QV-EDGE-TEST', 'name' => 'test']);
        $model = SensorModel::create([
            'model' => 'WTVB01-485', 'manufacturer' => 'WitMotion',
            'profile_version' => '1.0.0', 'verification_status' => 'verified',
        ]);
        Sensor::create([
            'sensor_id' => 'SENSOR-001', 'appliance_id' => $appliance->id,
            'sensor_model_id' => $model->id, 'slave_id' => 80,
        ]);

        $this->generator = new ReportGenerator();
        $this->to = Carbon::parse('2026-07-31T12:00:00Z');
        $this->from = $this->to->copy()->subHour();
    }

    /** One sample per minute across the whole window unless told otherwise. */
    private function seedSamples(int $minutes = 60, float $base = 1.0): void
    {
        $rows = [];
        for ($i = 0; $i < $minutes; $i++) {
            $rows[] = [
                'time' => $this->from->copy()->addMinutes($i)->toDateTimeString(),
                'appliance_id' => 'QV-EDGE-TEST', 'sensor_id' => 'SENSOR-001',
                'channel_key' => 'vib_velocity_x', 'value' => $base + $i * 0.01,
                'unit' => 'mm/s', 'quality' => 'good', 'source_type' => 'native',
                'sequence' => $i + 1, 'run_id' => 'run-a', 'ingested_at' => now(),
            ];
        }
        DB::table('measurements')->insert($rows);
    }

    public function test_statistics_are_computed_over_the_window(): void
    {
        $this->seedSamples();
        $report = $this->generator->summary($this->from, $this->to);

        $channel = $report['channels'][0];
        $this->assertSame('vib_velocity_x', $channel['channel_key']);
        $this->assertSame(60, $channel['samples']);
        $this->assertEqualsWithDelta(1.0, $channel['min'], 1e-6);
        $this->assertEqualsWithDelta(1.59, $channel['max'], 1e-6);
    }

    public function test_the_same_parameters_reproduce_the_same_checksum(): void
    {
        $this->seedSamples();

        $first = $this->generator->summary($this->from, $this->to);
        $second = $this->generator->summary($this->from, $this->to);

        // A report that cannot be reproduced is an assertion, not evidence.
        $this->assertSame($first['content_checksum'], $second['content_checksum']);
        // The uid differs: this is a second document reporting the same facts.
        $this->assertNotSame($first['report_uid'], $second['report_uid']);
    }

    public function test_changed_data_changes_the_checksum(): void
    {
        $this->seedSamples();
        $before = $this->generator->summary($this->from, $this->to)['content_checksum'];

        DB::table('measurements')->insert([[
            'time' => $this->from->copy()->addMinutes(5)->toDateTimeString(),
            'appliance_id' => 'QV-EDGE-TEST', 'sensor_id' => 'SENSOR-001',
            'channel_key' => 'vib_velocity_x', 'value' => 99.0, 'unit' => 'mm/s',
            'quality' => 'good', 'source_type' => 'native', 'sequence' => 999,
            'run_id' => 'run-a', 'ingested_at' => now(),
        ]]);

        $this->assertNotSame($before, $this->generator->summary($this->from, $this->to)['content_checksum']);
    }

    public function test_gaps_are_reported_as_gaps(): void
    {
        // Only 15 minutes of the hour carried data.
        $this->seedSamples(15);
        $report = $this->generator->summary($this->from, $this->to);

        // A gap is not a quiet period. Conflating them would let the document
        // imply the structure was still when nothing was being measured.
        $this->assertSame(60, $report['coverage']['window_minutes']);
        $this->assertSame(15, $report['coverage']['minutes_with_data']);
        $this->assertSame(45, $report['coverage']['gap_minutes']);
        $this->assertEqualsWithDelta(25.0, $report['coverage']['coverage_percent'], 0.01);
    }

    public function test_full_coverage_reports_no_gap(): void
    {
        $this->seedSamples(60);
        $this->assertSame(0, $this->generator->summary($this->from, $this->to)['coverage']['gap_minutes']);
    }

    public function test_degraded_samples_are_counted_separately(): void
    {
        $this->seedSamples(30);
        DB::table('measurements')->insert([[
            'time' => $this->from->copy()->addMinutes(45)->toDateTimeString(),
            'appliance_id' => 'QV-EDGE-TEST', 'sensor_id' => 'SENSOR-001',
            'channel_key' => 'vib_velocity_x', 'value' => null, 'unit' => 'mm/s',
            'quality' => 'bad', 'source_type' => 'native', 'sequence' => 500,
            'run_id' => 'run-a', 'ingested_at' => now(),
        ]]);

        $this->assertSame(1, $this->generator->summary($this->from, $this->to)['channels'][0]['degraded']);
    }

    public function test_report_records_its_processing_version(): void
    {
        $this->seedSamples();
        $report = $this->generator->summary($this->from, $this->to);

        // An old document must never be silently reinterpreted under new rules.
        $this->assertSame(ReportGenerator::PROCESSING_VERSION, $report['processing_version']);
    }

    public function test_report_records_the_standard_table_status(): void
    {
        $this->seedSamples();
        $this->assertSame('candidate', $this->generator->summary($this->from, $this->to)['standard_tables_status']);
    }

    public function test_persisting_stores_provenance(): void
    {
        $this->seedSamples();
        $report = $this->generator->summary($this->from, $this->to, generatedBy: 'J. Engineer');
        $this->generator->persist($report);

        $row = DB::table('reports')->first();
        $this->assertSame($report['report_uid'], $row->report_uid);
        $this->assertSame('J. Engineer', $row->generated_by);
        $this->assertSame($report['content_checksum'], $row->content_checksum);
        $this->assertSame('candidate', $row->standard_tables_status);
    }

    public function test_csv_carries_provenance_in_its_header(): void
    {
        $this->seedSamples();
        $csv = $this->generator->toCsv($this->generator->summary($this->from, $this->to));

        // A CSV opened years later must still explain itself.
        $this->assertStringContainsString('# report_uid', $csv);
        $this->assertStringContainsString('# processing_version', $csv);
        $this->assertStringContainsString('# content_checksum', $csv);
        $this->assertStringContainsString('# coverage_percent', $csv);
        $this->assertStringContainsString('sensor_id,channel_key,unit,samples', $csv);
    }

    public function test_pdf_renders_and_carries_the_unverified_caveat(): void
    {
        $this->seedSamples(15);
        $report = $this->generator->summary($this->from, $this->to);
        $html = view('reports.summary', ['report' => $report])->render();

        // Anything limiting what the document may be relied upon for belongs at
        // the top, not in a footnote.
        $this->assertStringContainsString('Guideline values are not verified', $html);
        // Matched on a single word-run: the template wraps lines, so asserting
        // a whole sentence would break on formatting rather than on meaning.
        $this->assertStringContainsString('compliance assessment', $html);
        $this->assertStringContainsString('Data gaps', $html);
        $this->assertStringContainsString($report['content_checksum'], $html);

        $pdf = Pdf::loadView('reports.summary', ['report' => $report])->output();
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_empty_window_produces_a_report_rather_than_failing(): void
    {
        $report = $this->generator->summary($this->from, $this->to);

        // "Nothing was recorded" is itself a finding, and a report that refused
        // to render would hide it.
        $this->assertSame([], $report['channels']);
        $this->assertSame(60, $report['coverage']['gap_minutes']);
        $this->assertStringContainsString('No measurements in this window', view('reports.summary', ['report' => $report])->render());
    }

    public function test_command_writes_both_formats(): void
    {
        $this->seedSamples();
        $dir = sys_get_temp_dir().'/qv-report-test-'.uniqid();

        $this->artisan('report:summary', [
            '--from' => $this->from->toIso8601String(),
            '--to' => $this->to->toIso8601String(),
            '--out' => $dir,
            '--by' => 'test',
        ])->assertSuccessful();

        $files = glob("{$dir}/*");
        $this->assertCount(2, $files);
        array_map('unlink', $files);
        rmdir($dir);
    }

    public function test_command_rejects_a_reversed_window(): void
    {
        $this->artisan('report:summary', [
            '--from' => $this->to->toIso8601String(),
            '--to' => $this->from->toIso8601String(),
        ])->assertFailed();
    }
}
