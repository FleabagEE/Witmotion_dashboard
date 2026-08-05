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
        {--top=SENSOR-001} {--top-slave=0x50} {--top-port=/dev/quakevault-rs485-p1}
        {--mid=SENSOR-002} {--mid-slave=0x50} {--mid-port=/dev/quakevault-rs485-p2}
        {--ground=SENSOR-003} {--ground-slave=0x50} {--ground-port=/dev/quakevault-rs485-p4}
        {--shared-bus : all three on one adapter, which requires distinct addresses}
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
                // intval with base 0, not a cast: (int) "0x50" is 0 in PHP, so a
                // cast silently turns every address into the broadcast address.
                'slave_id' => intval((string) $this->option("{$key}-slave"), 0),
                'port' => $this->option("{$key}-port"),
                'spec' => $spec,
            ];
        }

        // Address collision is a property of shared wires, not of sensors. With
        // one adapter per sensor every bus carries exactly one device and all
        // three may keep the factory 0x50; the first version of this check
        // refused that valid arrangement outright.
        //
        // On a shared bus it is the failure that matters most, because two
        // devices answering together produce garbled data rather than an
        // obvious fault - so the check still applies there.
        $slaves = array_column($rows, 'slave_id');
        $ports = array_column($rows, 'port');
        $sharedBus = $this->option('shared-bus') || count(array_unique($ports)) === 1;

        if ($sharedBus && count(array_unique($slaves)) !== count($slaves)) {
            $this->error('These sensors share one bus and one Modbus address. Two devices '
                .'answering together corrupt every reply.');
            $this->line('  Give each a distinct address first: qv-set-address --to 0x51');

            return self::FAILURE;
        }

        if (! $sharedBus && count(array_unique($ports)) !== count($ports)) {
            $this->error('Two sensors are configured on the same port with the same address.');

            return self::FAILURE;
        }

        $this->line("Structure: {$this->option('structure')}");
        $this->newLine();

        foreach ($rows as $row) {
            $this->line(sprintf('  %-8s %-14s 0x%02X  %-32s %s',
                $row['spec']['position'], $row['sensor_id'], $row['slave_id'],
                $row['port'],
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
                // Which socket this unit is in. These adapters report no serial
                // number, so the physical port is the only identity a sensor
                // has - established at commissioning by tapping each one and
                // seeing which port responded, not by tracking cables.
                'port' => $row['port'],
                'slave_id' => $row['slave_id'],
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
        if ($sharedBus) {
            $this->newLine();
            $this->warn('The Modbus addresses above must already be set on the hardware.');
            $this->line('  All three ship as 0x50, and two at the same address on one bus');
            $this->line('  answer simultaneously and corrupt every reply.');
        } else {
            $this->newLine();
            $this->line('One adapter per sensor, so all three keep the factory address.');
            $this->warn('The port is the only identity these sensors have.');
            $this->line('  Moving an adapter to a different USB socket reassigns which sensor');
            $this->line('  the appliance believes it is reading. On this installation that would');
            $this->line('  swap the ground reference with a structural sensor and invert the');
            $this->line('  interpretation of everything. Re-run the tap test after any move.');
        }

        return self::SUCCESS;
    }
}
