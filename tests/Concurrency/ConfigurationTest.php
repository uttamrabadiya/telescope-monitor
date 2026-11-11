<?php

namespace TelescopeMonitor\Tests\Concurrency;

use Illuminate\Support\Facades\DB;
use TelescopeMonitor\Foundation\ConfigurationConflict;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Services\SettingsService;
use TelescopeMonitor\Tests\TestCase;

final class ConfigurationTest extends TestCase
{
    public function test_concurrent_configuration_edits_commit_once_and_reject_stale_version(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL/MariaDB and pcntl.');
        }
        app(InstallationRepository::class)->current();
        $children = [];
        DB::disconnect();
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                try {
                    app(SettingsService::class)->save('general', ['display_name' => 'Concurrent '.$i], 0);
                    exit(0);
                } catch (ConfigurationConflict) {
                    exit(1);
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e->getMessage());
                    exit(2);
                }
            }
            $this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }
        $statuses = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[] = pcntl_wexitstatus($status);
        }
        sort($statuses);
        DB::purge();
        $this->assertSame([0, 1], $statuses);
        $this->assertSame(1, DB::table('exception_monitor_settings')->count());
        $this->assertSame(1, (int) DB::table('exception_monitor_settings')->value('row_version'));
        $this->assertSame(2, (int) DB::table('exception_monitor_installations')->value('config_revision'));
    }
}
