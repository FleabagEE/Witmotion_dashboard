<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The appliance serves its own dashboard.
 *
 * This used to be two hand-started processes and it did not survive a power
 * cut: the sensors came back under systemd and the screen did not. The routing
 * that replaced it is a single catch-all, which is a cheap thing to get subtly
 * wrong — a wildcard that is one character too greedy swallows the API and
 * every request starts returning HTML with a 200, which looks like success to
 * anything that is not a browser.
 *
 * So the boundary is pinned here rather than trusted to a regex nobody reads.
 */
class DashboardServingTest extends TestCase
{
    /**
     * The real dashboard, put back exactly as found.
     *
     * These tests write and delete public/index.html, and public/ on this
     * machine is not a fixture — it is the deployed appliance. The first
     * version of this file ran green and left the running dashboard answering
     * 503 to everybody, which was only noticed because the next command
     * happened to curl it. A test suite must not be able to take the product
     * down by passing.
     */
    private ?string $deployed = null;

    protected function setUp(): void
    {
        parent::setUp();

        $index = public_path('index.html');
        $this->deployed = file_exists($index) ? file_get_contents($index) : null;
    }

    private function withBuiltDashboard(string $html = '<!doctype html><title>QuakeVault</title>'): void
    {
        file_put_contents(public_path('index.html'), $html);
    }

    public function test_the_root_url_serves_the_dashboard(): void
    {
        $this->withBuiltDashboard();

        $this->get('/')
            ->assertOk()
            ->assertSee('QuakeVault', false);
    }

    public function test_a_client_side_route_serves_the_dashboard_rather_than_a_404(): void
    {
        // Somebody bookmarks the events page, or refreshes it. The server has
        // never heard of /events; the browser must still get the application.
        $this->withBuiltDashboard();

        foreach (['/events', '/thresholds', '/movement', '/health', '/users'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_the_api_is_not_swallowed_by_the_dashboard_route(): void
    {
        // The failure this test exists for: a catch-all that also matches /api
        // turns every unauthenticated call into a 200 with an HTML body. The
        // frontend would then parse the login page as JSON and report a
        // confusing error, and monitoring would report the appliance healthy.
        $this->withBuiltDashboard();

        $response = $this->getJson('/api/v1/sensor-health');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringNotContainsString('<!doctype html', $response->getContent());
    }

    public function test_it_says_the_dashboard_is_unbuilt_rather_than_showing_a_404(): void
    {
        // The cause is always the same and the fix is one command. A 404 would
        // send somebody hunting through routes for a bug that is not there.
        @unlink(public_path('index.html'));

        $this->get('/')
            ->assertStatus(503)
            ->assertSee('build-dashboard.sh');
    }

    public function test_the_dashboard_html_is_never_cached(): void
    {
        // index.html names the content-hashed bundles. A cached copy after an
        // upgrade points at files that no longer exist and the screen goes
        // white while the appliance runs perfectly underneath it.
        $this->withBuiltDashboard();

        // Asserted by meaning: Symfony reorders the directives and adds
        // `private`. Pinning the exact string would fail on a framework upgrade
        // that changed nothing about the behaviour.
        $header = $this->get('/')->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $header);
        $this->assertStringNotContainsString('public', $header);
    }

    protected function tearDown(): void
    {
        $index = public_path('index.html');

        if ($this->deployed === null) {
            @unlink($index);
        } else {
            file_put_contents($index, $this->deployed);
        }

        parent::tearDown();
    }
}
