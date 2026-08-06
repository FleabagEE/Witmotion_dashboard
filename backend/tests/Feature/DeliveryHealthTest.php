<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeliveryHealth;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Two silences that look identical and mean opposite things.
 *
 * On 2026-08-06 the database was down for sixteen hours. Every sensor was
 * healthy, the structure was still, and not one reading reached the database.
 * Sensor health reported that as "silent", which reads as a dead instrument —
 * and would have sent somebody to a silo to check a cable that was not broken.
 *
 * The readings were on disk the whole time. Nothing was lost. The appliance
 * knew that and had no way to say it.
 *
 * These tests hold the difference: not arriving *and safe* must never be
 * reported the same way as not arriving *and being lost*.
 */
class DeliveryHealthTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = storage_path('framework/testing/forwarder.prom');
        @mkdir(dirname($this->path), 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function metrics(int $backlog, int $dead = 0, int $delivered = 200): void
    {
        file_put_contents($this->path, <<<PROM
        # TYPE quakevault_forwarder_backlog gauge
        quakevault_forwarder_backlog{appliance="QV-EDGE-001"} {$backlog}
        quakevault_forwarder_delivered_total{appliance="QV-EDGE-001"} {$delivered}
        quakevault_forwarder_dead_letters{appliance="QV-EDGE-001"} {$dead}
        PROM);
    }

    private function asRole(string $role = Roles::VIEWER): self
    {
        $user = User::firstOrCreate(
            ['email' => "{$role}@example.test"],
            [
                'name' => ucfirst($role),
                'password' => Hash::make('correct-horse-battery'),
                'role' => $role,
                'active' => true,
            ],
        );

        // The guard caches the first resolved user for the lifetime of a test.
        $this->app['auth']->forgetGuards();

        return $this->withHeader(
            'Authorization',
            'Bearer '.$user->createToken('test', Roles::abilitiesFor($role))->plainTextToken,
        );
    }

    private function health(): array
    {
        return (new DeliveryHealth($this->path))->current();
    }

    public function test_a_quiet_appliance_delivering_normally_is_healthy(): void
    {
        $this->metrics(backlog: 12);

        $result = $this->health();

        $this->assertSame('pass', $result['state']);
        $this->assertSame(12, $result['backlog']);
    }

    public function test_a_large_backlog_says_the_readings_are_safe(): void
    {
        // The whole point. An operator seeing this must not panic, and must not
        // be told to check a sensor.
        $this->metrics(backlog: 187_671);

        $result = $this->health();

        $this->assertSame('warn', $result['state']);
        $this->assertStringContainsString('safe on disk', $result['summary']);
    }

    public function test_a_stopped_forwarder_is_a_failure_not_a_healthy_backlog(): void
    {
        // The dangerous case. The file still says "backlog 12, all fine" and it
        // is describing a moment three hours ago. Reporting that stale number
        // as health is worse than reporting nothing at all.
        $this->metrics(backlog: 12);
        touch($this->path, time() - 3600);
        clearstatcache();

        $result = $this->health();

        $this->assertSame('fail', $result['state']);
        $this->assertStringContainsString('stopped reporting', $result['summary']);
        $this->assertStringContainsString('recorded to disk', $result['summary']);
    }

    public function test_parked_readings_are_reported_with_the_command_that_frees_them(): void
    {
        // Dead letters are the one state here that does not fix itself. A
        // number with no action beside it is what the appliance shipped before,
        // and 31,307 readings sat behind it.
        $this->metrics(backlog: 31_307, dead: 31_307);

        $result = $this->health();

        $this->assertSame('warn', $result['state']);
        $this->assertStringContainsString('31,307', $result['summary']);
        $this->assertStringContainsString('nothing is lost', $result['summary']);
        $this->assertSame('qv-spool retry-dead-letters --confirm', $result['action']);
    }

    public function test_parked_readings_outrank_a_merely_large_backlog(): void
    {
        // Both are true during a recovery. The one that needs a human must win.
        $this->metrics(backlog: 500_000, dead: 5);

        $this->assertStringContainsString('parked', $this->health()['summary']);
    }

    public function test_a_missing_metrics_file_is_unknown_not_healthy(): void
    {
        @unlink($this->path);

        $result = $this->health();

        $this->assertSame('unknown', $result['state']);
        $this->assertNull($result['backlog']);
    }

    public function test_it_survives_a_metrics_file_without_labels(): void
    {
        file_put_contents($this->path, "quakevault_forwarder_backlog 7\n");

        $this->assertSame(7, $this->health()['backlog']);
    }

    public function test_the_endpoint_reports_delivery_beside_the_sensors(): void
    {
        $this->metrics(backlog: 3);
        config(['appliance.forwarder_metrics' => $this->path]);

        $response = $this->asRole('viewer')->getJson('/api/v1/sensor-health');

        $response->assertOk()->assertJsonStructure([
            'status',
            'sensors',
            'delivery' => ['state', 'summary', 'backlog', 'dead_letters'],
        ]);
    }

    public function test_delivery_trouble_does_not_make_the_sensors_look_sick(): void
    {
        // Folding delivery into the overall status would let a patient,
        // correctly-working spool turn every instrument on the page amber, and
        // the page would then be lying about the hardware.
        $this->metrics(backlog: 500_000, dead: 400_000);
        config(['appliance.forwarder_metrics' => $this->path]);

        $response = $this->asRole('viewer')->getJson('/api/v1/sensor-health');

        $this->assertSame('warn', $response->json('delivery.state'));
        $this->assertNotSame('warn', $response->json('status'));
    }
}
