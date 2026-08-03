<?php

namespace Tests\Feature;

use App\Models\Appliance;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use JsonSchema\Validator;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Holds the API to openapi.yaml, in both directions.
 *
 * A specification maintained by hand drifts from the code the first time
 * somebody is in a hurry, and then it is worse than no specification: it is a
 * promise the application no longer keeps. So it is not maintained by hand.
 *
 * Two directions matter and only one is obvious. A route in the application but
 * missing from the spec is undocumented - that is the one people think of. A
 * route in the spec but missing from the application is a promise to an
 * integrator that nothing will honour, and it fails here too.
 *
 * Response schemas are then validated against live responses, so a field that
 * silently changes shape - a number becoming a string, a required field going
 * missing - fails the build rather than an integration.
 */
class ContractTest extends TestCase
{
    use RefreshDatabase;

    private static ?array $spec = null;

    private function spec(): array
    {
        return self::$spec ??= Yaml::parseFile(base_path('openapi.yaml'));
    }

    /** Turns `/api/v1/sensors/{sensorId}/latest` into Laravel's `api/v1/sensors/{sensorId}/latest`. */
    private function documentedRoutes(): array
    {
        $out = [];
        foreach ($this->spec()['paths'] as $path => $operations) {
            foreach ($operations as $method => $_) {
                $out[strtoupper($method).' '.ltrim($path, '/')] = true;
            }
        }

        return $out;
    }

    private function applicationRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $out[$method.' '.$route->uri()] = true;
            }
        }

        return $out;
    }

    private function validate(array $data, array $schema, string $context): void
    {
        // Resolve $ref against the document itself. The validator needs the
        // whole spec as the root to follow #/components/schemas/... references.
        $document = json_decode(json_encode($this->spec()));
        $schemaObject = json_decode(json_encode($schema));
        $schemaObject->components = $document->components;

        $payload = json_decode(json_encode($data));

        $validator = new Validator();
        $validator->validate($payload, $schemaObject);

        if (! $validator->isValid()) {
            $problems = array_map(
                fn ($e) => sprintf('  %s: %s', $e['property'] ?: '(root)', $e['message']),
                $validator->getErrors(),
            );
            $this->fail("$context does not match its schema:\n".implode("\n", $problems));
        }

        $this->addToAssertionCount(1);
    }

    private function schemaFor(string $path, string $method, string $status): array
    {
        $operation = $this->spec()['paths'][$path][$method]
            ?? $this->fail("openapi.yaml has no $method $path");

        return $operation['responses'][$status]['content']['application/json']['schema']
            ?? $this->fail("openapi.yaml has no $status JSON schema for $method $path");
    }

    private function engineer(): User
    {
        $user = User::factory()->create(['role' => Roles::ENGINEER, 'active' => true]);
        $this->app['auth']->forgetGuards();

        return $user;
    }

    private function seedSensor(): void
    {
        $appliance = Appliance::create(['appliance_id' => 'QV-EDGE-TEST', 'name' => 'test']);
        $model = SensorModel::create([
            'model' => 'WTVB01-485', 'manufacturer' => 'WitMotion',
            'profile_version' => '1.1.0', 'verification_status' => 'verified',
        ]);
        Sensor::create([
            'sensor_id' => 'SENSOR-001', 'appliance_id' => $appliance->id,
            'sensor_model_id' => $model->id, 'slave_id' => 80, 'status' => 'active',
        ]);
    }

    // --- the spec and the application agree on what exists -------------------

    public function test_every_application_route_is_documented(): void
    {
        $undocumented = array_diff_key($this->applicationRoutes(), $this->documentedRoutes());

        $this->assertSame([], array_keys($undocumented),
            "These routes exist but are not in openapi.yaml:\n  "
            .implode("\n  ", array_keys($undocumented)));
    }

    public function test_every_documented_route_exists(): void
    {
        // The direction people forget. A route in the spec but not in the
        // application is a promise to an integrator that nothing will honour.
        $missing = array_diff_key($this->documentedRoutes(), $this->applicationRoutes());

        $this->assertSame([], array_keys($missing),
            "openapi.yaml documents routes that do not exist:\n  "
            .implode("\n  ", array_keys($missing)));
    }

    // --- live responses match the schemas ------------------------------------

    public function test_sensors_response_matches_the_schema(): void
    {
        $this->seedSensor();
        Sanctum::actingAs($this->engineer(), ['read']);

        $response = $this->getJson('/api/v1/sensors')->assertOk();

        $this->validate(
            $response->json(),
            $this->schemaFor('/api/v1/sensors', 'get', '200'),
            'GET /api/v1/sensors',
        );
    }

    public function test_overview_response_matches_the_schema(): void
    {
        $this->seedSensor();
        Sanctum::actingAs($this->engineer(), ['read']);

        $response = $this->getJson('/api/v1/overview')->assertOk();

        $this->validate(
            $response->json(),
            $this->schemaFor('/api/v1/overview', 'get', '200'),
            'GET /api/v1/overview',
        );
    }

    public function test_alarms_response_matches_the_schema(): void
    {
        $this->seedSensor();
        Sanctum::actingAs($this->engineer(), ['read']);

        $response = $this->getJson('/api/v1/alarms')->assertOk();

        $this->validate(
            $response->json(),
            $this->schemaFor('/api/v1/alarms', 'get', '200'),
            'GET /api/v1/alarms',
        );
    }

    public function test_me_response_matches_the_schema(): void
    {
        Sanctum::actingAs($this->engineer(), ['read']);

        $response = $this->getJson('/api/v1/me')->assertOk();

        $this->validate(
            $response->json(),
            $this->schemaFor('/api/v1/me', 'get', '200'),
            'GET /api/v1/me',
        );
    }

    public function test_ingest_health_matches_the_schema(): void
    {
        Sanctum::actingAs($this->engineer(), ['ingest']);

        $response = $this->getJson('/api/internal/v1/ingest/health')->assertOk();

        $this->validate(
            $response->json(),
            $this->schemaFor('/api/internal/v1/ingest/health', 'get', '200'),
            'GET /api/internal/v1/ingest/health',
        );
    }

    public function test_an_unauthenticated_request_matches_the_error_schema(): void
    {
        $response = $this->getJson('/api/v1/sensors')->assertUnauthorized();

        $this->validate(
            $response->json(),
            $this->spec()['components']['schemas']['Error'],
            '401 response',
        );
    }

    // --- the fields that carry judgement are not optional ---------------------

    public function test_a_sensor_must_carry_its_verification_status(): void
    {
        // Not decoration. A reading from a sensor whose register map was never
        // confirmed is a picture of an assumption, and nothing downstream should
        // have to go and ask.
        $required = $this->spec()['components']['schemas']['Sensor']['required'];

        $this->assertContains('verification_status', $required);
        $this->assertContains('trustworthy', $required);
    }

    public function test_a_reading_must_carry_its_quality_and_provenance(): void
    {
        $required = $this->spec()['components']['schemas']['Reading']['required'];

        $this->assertContains('quality', $required);
        $this->assertContains('source_type', $required);
    }

    public function test_an_alarm_must_say_whether_it_is_provisional(): void
    {
        // A provisional alarm is displayed and never notifies. An integration
        // that cannot see the difference would treat an unconfirmed threshold
        // as a confirmed one.
        $required = $this->spec()['components']['schemas']['Alarm']['required'];

        $this->assertContains('provisional', $required);
        $this->assertContains('actionable', $required);
    }

    public function test_a_spectrum_must_say_whether_it_is_defensible(): void
    {
        $analysis = $this->spec()['components']['schemas']['SpectrumAnalysis']['required'];
        $spectrum = $this->spec()['components']['schemas']['Spectrum']['required'];

        $this->assertContains('allowed', $analysis);
        $this->assertContains('explanation', $analysis);
        $this->assertContains('transient', $spectrum);
        $this->assertContains('peak_significant', $spectrum);
    }

    public function test_a_series_point_must_carry_its_bounds(): void
    {
        // lo and hi exist so downsampling cannot hide a transient. Dropping them
        // would silently erase exactly the peak a vibration limit is written
        // against.
        $required = $this->spec()['components']['schemas']['SeriesPoint']['required'];

        $this->assertContains('lo', $required);
        $this->assertContains('hi', $required);
    }
}
