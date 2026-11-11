<?php

namespace TelescopeMonitor\Tests\Feature;

use TelescopeMonitor\Tests\TestCase;

final class AlternatePathTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('exception-monitor.path', 'operations/monitor');
    }

    public function test_shell_json_assets_and_ignored_paths_follow_a_nondefault_prefix(): void
    {
        $this->authorize();
        $this->get('/operations/monitor/settings/privacy')->assertOk()->assertSee('data-api="/operations/monitor/api/v1"', false);
        $this->getJson('/operations/monitor/api/v1/bootstrap')->assertOk()->assertJsonPath('data.application.dashboard_url', 'https://example.test/operations/monitor');
        $this->getJson('/operations/monitor/api/v1/unknown')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->get('/exception-monitor')->assertNotFound();
        $this->assertContains('operations/monitor/*', config('telescope.ignore_paths'));
    }
}
