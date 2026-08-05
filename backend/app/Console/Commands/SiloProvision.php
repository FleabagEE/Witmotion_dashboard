<?php

namespace App\Console\Commands;

use App\Models\Appliance;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Describe a silo installation to the appliance.
 *
 * Three WTVB01-485 in the same orientation on one bus: top, mid-height and
 * ground. What matters here is not the rows themselves but the roles, because
 * every later comparison depends on them.
 *
 * WHY THE GROUND SENSOR IS DIFFERENT
 * ----------------------------------
 *
 * It is the reference, and it is what makes the other two interpretable. A
 * lorry, a piling rig, a distant blast or a seismic event moves the whole site
 * at once; the silo settling moves only the silo. With one sensor those are the
 * same reading. With a ground reference they are separable: what all three did
 * together is the site, and what the upper two did beyond it is the structure.
 *
 * Without this, every passing disturbance looks like the silo moving, and the
 * response to that is either alarms nobody believes or thresholds raised until
 * the instrument is deaf.
 *
 * ORIENTATION IS RECORDED, NOT ASSUMED
 * ------------------------------------
 *
 * All three units go on in the same orientation. That is what makes their
 * readings directly comparable - a difference between two sensors is then a
 * difference in what they experienced, not in how they were bolted on. It is
 * recorded per sensor so a future reader can check the assumption rather than
 * inherit it.
 */
class SiloProvision extends Command
{
    protected $signature = 'silo:provision
        {--appliance=QV-EDGE-001}
        {--structure=Silo pair, joined at mid-height}
        {--top=SENSOR-001} {--top-slave=0x50}
        {--mid=SENSOR-002} {--mid-slave=0x51}
        {--ground=SENSOR-003} {--ground-slave=0x52}
        {--dry-run}';

    protected $description = 'Register the three silo sensors with their positions and roles';

    /** Position, role, and what each one is for. */
    private const POSITIONS = [
        'top' => [
            'position' => 'top',
            'role' => 'monitor',
            'height_note' => 'Upper structure. Moves most for a given rotation, so it '
                .'sees settlement earliest and bending most clearly.',
        ],
        'mid' => [
            'position' => 'mid',
            'role' => 'monitor',
            'height_note' => 'At the joined level. Compared against the top it separates '
                .'a rigid lean from the shell bending between them.',
        ],
        'ground' => [
            'position' => 'ground',
            'role' => 'reference',
            'height_note' => 'Reference. What this one sees, the site did. What the upper '
                .'two see beyond it, the structure did.',
        ],
    ];

    public function handle(AuditLogger $audit): int
    {
        $appliance = Appliance::firstOrCreate(
            ['appliance_id' => $this->option('appliance')],
            ['name' => $this->option('appliance'), 'status' => 'online'],
        );

        $model = SensorModel::where('model', 'WTVB01-485')->first();

        if (! $model) {
            $this->error('No WTVB01-485 sensor model registered. Start acquisition first '
                .'so the profile is announced.');

            return self::FAILURE;
        }

        if (! $model->isTrustworthy()) {
            // The same gate the acquisition service applies. An unverified
            // register map produces plausible numbers rather than an obvious
            // failure, and three sensors would produce three of them.
            $this->error("Profile for {$model->model} is '{$model->verification_status}', "
                .'not verified. Refusing to provision.');

            return self::FAILURE;
        }

        $rows = [];

        foreach (self::POSITIONS as $key => $spec) {
            $rows[] = [
                'sensor_id' => $this->option($key),
                'slave_id' => (int) $this->option("{$key}-slave"),
                'spec' => $spec,
            ];
        }

        $slaves = array_column($rows, 'slave_id');

        if (count(array_unique($slaves)) !== count($slaves)) {
            // Two sensors answering the same address corrupt every reply on the
            // bus, and the symptom is garbled data rather than a clean failure.
            $this->error('Two sensors share a Modbus address. Change one with the '
                .'manufacturer tool before either goes on the bus.');

            return self::FAILURE;
        }

        $this->line("Structure: {$this->option('structure')}");
        $this->newLine();

        foreach ($rows as $row) {
            $this->line(sprintf('  %-12s %-14s slave 0x%02X  %s',
                $row['spec']['position'], $row['sensor_id'], $row['slave_id'],
                $row['spec']['role'] === 'reference' ? '(reference)' : ''));
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry run - nothing was written.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $sensor = Sensor::updateOrCreate(
                ['sensor_id' => $row['sensor_id']],
                [
                    'appliance_id' => $appliance->id,
                    'sensor_model_id' => $model->id,
                    'slave_id' => $row['slave_id'],
                    'status' => 'active',
                ],
            );

            $metadata = $sensor->metadata ?? [];
            $metadata['mounting'] = array_merge($metadata['mounting'] ?? [], [
                'structure' => $this->option('structure'),
                'position' => $row['spec']['position'],
                'role' => $row['spec']['role'],
                'note' => $row['spec']['height_note'],
                'surface' => 'vertical_wall',
                // All three go on the same way. Recorded rather than assumed, so
                // a reader can check it instead of inheriting it.
                'orientation' => 'identical across all three sensors',
                'axis_labels' => [
                    'x' => 'transverse (sideways along the silo face)',
                    'y' => 'longitudinal (up the silo axis)',
                    'z' => 'radial (out of the wall)',
                ],
                'recorded_at' => now()->toIso8601String(),
            ]);
            $sensor->metadata = $metadata;
            $sensor->save();

            $audit->record(
                action: 'sensor.provisioned',
                subjectType: 'sensor',
                subjectId: (string) $sensor->id,
                summary: sprintf('%s registered at %s, slave 0x%02X, role %s',
                    $sensor->sensor_id, $row['spec']['position'],
                    $row['slave_id'], $row['spec']['role']),
                actorTypeOverride: 'console',
                actorNameOverride: 'artisan silo:provision',
            );
        }

        $this->newLine();
        $this->info(sprintf('%d sensor(s) registered.', count($rows)));
        $this->newLine();
        $this->line('Each needs its own commissioning baseline once mounted:');
        foreach ($rows as $row) {
            $this->line("  php artisan tilt:baseline capture --sensor={$row['sensor_id']}");
        }
        $this->newLine();
        $this->warn('The Modbus addresses above must already be set on the hardware.');
        $this->line('  All three ship as 0x50. Two on one bus at the same address answer');
        $this->line('  simultaneously and corrupt every reply, which reads as noise rather');
        $this->line('  than as a fault.');

        return self::SUCCESS;
    }
}
