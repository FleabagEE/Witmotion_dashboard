<?php

namespace App\Console\Commands;

use App\Services\ReportGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateReport extends Command
{
    protected $signature = 'report:summary
        {--from= : ISO start, default 24h ago}
        {--to= : ISO end, default now}
        {--sensor= : limit to one sensor}
        {--by=cli : who generated it}
        {--out= : directory to write into}
        {--format=both : csv|pdf|both}';

    protected $description = 'Generate a vibration summary report';

    public function handle(ReportGenerator $generator): int
    {
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : now()->subDay();
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : now();
        if ($from >= $to) {
            $this->error('--from must be before --to');

            return self::FAILURE;
        }

        $report = $generator->summary($from, $to, $this->option('sensor'), $this->option('by'));
        $generator->persist($report);

        $dir = rtrim($this->option('out') ?: storage_path('app/reports'), '/');
        if (! is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }
        $base = "{$dir}/{$report['report_uid']}";
        $format = $this->option('format');

        if (in_array($format, ['csv', 'both'], true)) {
            file_put_contents("{$base}.csv", $generator->toCsv($report));
            $this->info("wrote {$base}.csv");
        }
        if (in_array($format, ['pdf', 'both'], true)) {
            Pdf::loadView('reports.summary', ['report' => $report])->save("{$base}.pdf");
            $this->info("wrote {$base}.pdf");
        }

        $this->newLine();
        $this->line("uid       {$report['report_uid']}");
        $this->line("channels  ".count($report['channels']));
        $this->line("alarms    ".count($report['alarms']));
        $this->line("coverage  {$report['coverage']['coverage_percent']}% ({$report['coverage']['gap_minutes']} min gap)");
        $this->line("checksum  {$report['content_checksum']}");

        return self::SUCCESS;
    }
}
