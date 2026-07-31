<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 18mm 14mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #111; }
  h1 { font-size: 15pt; margin: 0 0 2mm; }
  h2 { font-size: 10pt; margin: 6mm 0 2mm; border-bottom: 1px solid #999; padding-bottom: 1mm; }
  .muted { color: #555; }
  table { width: 100%; border-collapse: collapse; margin-top: 2mm; }
  th, td { border: 1px solid #bbb; padding: 1.4mm 2mm; text-align: left; }
  th { background: #eee; font-size: 8pt; text-transform: uppercase; letter-spacing: .3pt; }
  td.num { text-align: right; font-variant-numeric: tabular-nums; }
  .caution { border: 1px solid #b58900; background: #fdf6e3; padding: 3mm; margin-top: 3mm; }
  .prov { font-size: 8pt; color: #444; margin-top: 6mm; border-top: 1px solid #999; padding-top: 2mm; }
</style>
</head>
<body>

<h1>{{ $report['title'] }}</h1>
<div class="muted">
  {{ $report['report_uid'] }} &middot; generated {{ $report['generated_at'] }} by {{ $report['generated_by'] }}
</div>

{{-- Anything that limits what this document may be relied upon for goes at the
     top, not in a footnote. A reader who stops after page one must still know. --}}
@if ($report['standard_tables_status'] !== 'verified')
<div class="caution">
  <strong>Guideline values are not verified.</strong>
  The structural guideline tables used by this system are marked
  <em>{{ $report['standard_tables_status'] }}</em>: transcribed rather than checked against the
  published text of DIN 4150-3 or BS 7385-2. Any alarm shown below that was raised
  against them is marked provisional and did not notify anyone. This report is not a
  compliance assessment.
</div>
@endif

@if ($report['coverage']['gap_minutes'] > 0)
<div class="caution">
  <strong>Data gaps.</strong>
  {{ $report['coverage']['gap_minutes'] }} minute(s) of the
  {{ $report['coverage']['window_minutes'] }}-minute window carried no measurements
  ({{ $report['coverage']['coverage_percent'] }}% coverage). Absence of a reading is not
  evidence that the structure was still.
</div>
@endif

<h2>Channel statistics</h2>
<table>
  <thead>
    <tr>
      <th>Sensor</th><th>Channel</th><th>Unit</th>
      <th>Samples</th><th>Degraded</th><th>Min</th><th>Max</th><th>Mean</th><th>SD</th>
    </tr>
  </thead>
  <tbody>
  @forelse ($report['channels'] as $c)
    <tr>
      <td>{{ $c['sensor_id'] }}</td>
      <td>{{ $c['channel_key'] }}</td>
      <td>{{ $c['unit'] }}</td>
      <td class="num">{{ number_format($c['samples']) }}</td>
      <td class="num">{{ number_format($c['degraded']) }}</td>
      <td class="num">{{ $c['min'] }}</td>
      <td class="num">{{ $c['max'] }}</td>
      <td class="num">{{ $c['avg'] }}</td>
      <td class="num">{{ $c['stddev'] }}</td>
    </tr>
  @empty
    <tr><td colspan="9">No measurements in this window.</td></tr>
  @endforelse
  </tbody>
</table>

<h2>Alarms raised in this window</h2>
<table>
  <thead>
    <tr><th>Raised</th><th>Name</th><th>Channel</th><th>Peak</th><th>Value</th><th>Limit</th><th>Acknowledged</th><th>Status</th></tr>
  </thead>
  <tbody>
  @forelse ($report['alarms'] as $a)
    <tr>
      <td>{{ $a['raised_at'] }}</td>
      <td>{{ $a['name'] }}</td>
      <td>{{ $a['channel_key'] }}</td>
      <td>{{ strtoupper($a['peak_level']) }}</td>
      <td class="num">{{ $a['peak_value'] }} {{ $a['unit'] }}</td>
      <td class="num">{{ $a['threshold'] }} {{ $a['unit'] }}</td>
      <td>{{ $a['acknowledged_at'] ?? '—' }}</td>
      <td>{{ $a['provisional'] ? 'provisional' : ($a['thresholds_confirmed_by'] ? 'confirmed by '.$a['thresholds_confirmed_by'] : 'confirmed') }}</td>
    </tr>
  @empty
    <tr><td colspan="8">No alarms were raised in this window.</td></tr>
  @endforelse
  </tbody>
</table>

<div class="prov">
  <strong>Provenance.</strong>
  Software {{ $report['software_version'] }} &middot;
  processing {{ $report['processing_version'] }} &middot;
  timezone {{ $report['timezone'] }} &middot;
  coverage {{ $report['coverage']['coverage_percent'] }}%<br>
  Content checksum (SHA-256): {{ $report['content_checksum'] }}<br>
  Regenerating this report with the same parameters and processing version yields the
  same checksum. A differing checksum means the underlying data or the arithmetic changed.
</div>

</body>
</html>
