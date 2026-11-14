<?php

namespace TelescopeMonitor\Tests\Feature;

use TelescopeMonitor\TelescopeMonitorServiceProvider;
use TelescopeMonitor\Foundation\PackagePath;
use TelescopeMonitor\Services\InstallationService;
use TelescopeMonitor\Tests\TestCase;

final class InstallationTest extends TestCase
{
    public function test_repeat_install_preserves_configuration_and_migration_identity(): void
    {
        $database = $this->app->databasePath();
        $configuration = $this->app->configPath();
        $temporary = sys_get_temp_dir().'/monitor-foundation-install-'.bin2hex(random_bytes(8));
        mkdir($temporary.'/migrations', 0777, true);
        mkdir($temporary.'/config', 0777, true);
        $this->app->useDatabasePath($temporary);
        $this->useConfigDirectory($temporary.'/config');
        $this->app->register(TelescopeMonitorServiceProvider::class, true);
        try {
            $this->assertTrue(app(InstallationService::class)->publish());
            // Simulate an upgrade from a host that already published only Phase 1.
            unlink(glob($temporary.'/migrations/*_create_telescope_monitor_capture.php')[0]);
            unlink(glob($temporary.'/migrations/*_create_telescope_monitor_collaboration.php')[0]);
            unlink(glob($temporary.'/migrations/*_create_telescope_monitor_alerting.php')[0]);
            unlink(glob($temporary.'/migrations/*_create_telescope_monitor_integrations.php')[0]);
            unlink(glob($temporary.'/migrations/*_create_telescope_monitor_operations.php')[0]);
            file_put_contents($temporary.'/config/telescope-monitor.php', "<?php return ['consumer' => true];");
            $this->assertTrue(app(InstallationService::class)->publish());
            $this->assertSame("<?php return ['consumer' => true];", file_get_contents($temporary.'/config/telescope-monitor.php'));
            $this->assertCount(1, glob($temporary.'/migrations/*_create_telescope_monitor_foundation.php'));
            $this->assertCount(1, glob($temporary.'/migrations/*_create_telescope_monitor_capture.php'));
            $this->assertCount(1, glob($temporary.'/migrations/*_create_telescope_monitor_collaboration.php'));
            $this->assertCount(1, glob($temporary.'/migrations/*_create_telescope_monitor_alerting.php'));
            $this->assertCount(1, glob($temporary.'/migrations/*_create_telescope_monitor_integrations.php'));
            $this->assertCount(1, glob($temporary.'/migrations/*_create_telescope_monitor_operations.php'));
        } finally {
            $this->app->useDatabasePath($database);
            $this->useConfigDirectory($configuration);
            foreach (glob($temporary.'/migrations/*') as $path) {
                unlink($path);
            }
            unlink($temporary.'/config/telescope-monitor.php');
            rmdir($temporary.'/migrations');
            rmdir($temporary.'/config');
            rmdir($temporary);
        }
    }

    public function test_root_or_wildcard_prefix_cannot_exclude_the_entire_host(): void
    {
        foreach (['/', '*', 'one/../two', 'one/*'] as $path) {
            config(['telescope-monitor.path' => $path]);
            $this->assertFalse(app(PackagePath::class)->valid());
        }
        config(['telescope-monitor.path' => 'ops/monitor']);
        $this->assertTrue(app(PackagePath::class)->valid());
    }
}
