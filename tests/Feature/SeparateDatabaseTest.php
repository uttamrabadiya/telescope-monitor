<?php

namespace TelescopeMonitor\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use TelescopeMonitor\Alerts\DeliverySender;
use TelescopeMonitor\TelescopeMonitorServiceProvider;
use TelescopeMonitor\Operations\PurgeOperations;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Services\InstallationService;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class SeparateDatabaseTest extends TestCase
{
    use CapturesExceptions;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'sqlite');
        foreach (['monitor', 'source'] as $name) {
            $app['config']->set('database.connections.'.$name, $app['config']->get('database.connections.sqlite'));
        }
        $app['config']->set('telescope-monitor.database.connection', 'monitor');
        $app['config']->set('telescope.storage.database.connection', 'source');
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach ([
            'source' => __DIR__.'/../../vendor/laravel/telescope/database/migrations',
            'monitor' => __DIR__.'/../../database/migrations',
        ] as $connection => $path) {
            Artisan::call('migrate', ['--database' => $connection, '--path' => realpath($path), '--realpath' => true, '--force' => true]);
        }
        DB::connection('sqlite')->getSchemaBuilder()->create('host_records', fn (Blueprint $t) => $t->string('name'));
        DB::connection('sqlite')->table('host_records')->insert(['name' => 'untouched']);
    }

    private function assertHostIsolated(): void
    {
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertSame(['untouched'], DB::connection('sqlite')->table('host_records')->pluck('name')->all());
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('telescope_monitor_installations'));
        $this->assertFalse(DB::connection('source')->getSchemaBuilder()->hasTable('telescope_monitor_installations'));
    }

    public function test_unset_override_inherits_telescope_storage_without_using_the_host_database(): void
    {
        config(['telescope-monitor.database.connection' => null]);
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'source',
            '--path' => realpath(__DIR__.'/../../database/migrations'),
            '--realpath' => true,
            '--force' => true,
        ]));
        $this->capture('Inherited Telescope storage');
        $this->process();
        $this->assertSame(1, DB::connection('source')->table('telescope_monitor_occurrences')->count());
        $this->assertSame(0, DB::connection('monitor')->table('telescope_monitor_occurrences')->count());
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('telescope_monitor_installations'));
        $this->assertSame(['untouched'], DB::connection('sqlite')->table('host_records')->pluck('name')->all());
        $this->assertSame('sqlite', DB::getDefaultConnection());
    }

    public function test_missing_telescope_setting_uses_the_laravel_default(): void
    {
        config(['telescope-monitor.database.connection' => null, 'telescope.storage.database.connection' => null]);
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'sqlite',
            '--path' => realpath(__DIR__.'/../../database/migrations'),
            '--realpath' => true,
            '--force' => true,
        ]));
        $this->assertSame('ready', app(InstallationRepository::class)->storageState());
        $this->assertSame(6, app(InstallationRepository::class)->current()->schema_version);
        $this->assertSame(1, DB::connection('sqlite')->table('telescope_monitor_installations')->count());
        $this->assertSame(['untouched'], DB::connection('sqlite')->table('host_records')->pluck('name')->all());
    }

    public function test_invalid_inherited_connection_does_not_silently_use_the_host(): void
    {
        config(['telescope-monitor.database.connection' => null, 'telescope.storage.database.connection' => 'source_typo']);
        try {
            app(InstallationRepository::class)->storageState();
            $this->fail('An invalid inherited connection must fail closed.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('source_typo', $e->getMessage());
        } finally {
            config(['telescope-monitor.database.connection' => 'monitor', 'telescope.storage.database.connection' => 'source']);
        }
        $this->assertHostIsolated();
    }

    public function test_capture_lifecycle_collaboration_delivery_and_purge_use_only_monitor_storage(): void
    {
        $this->authorize();
        $this->capture('Separate storage capture');
        $this->process();
        $this->assertSame(1, DB::connection('monitor')->table('telescope_monitor_occurrences')->count());
        $this->assertSame(1, DB::connection('source')->table('telescope_entries')->where('type', 'exception')->count());
        $issue = DB::connection('monitor')->table('telescope_monitor_issues')->first();
        $this->postJson('/telescope-monitor/api/v1/issues/'.$issue->public_id.'/actions', ['client_key' => (string) Str::uuid(), 'version' => $issue->state_version, 'action' => 'resolve'])->assertOk();
        $this->assertSame('resolved', DB::connection('monitor')->table('telescope_monitor_issues')->value('status'));
        $this->postJson('/telescope-monitor/api/v1/issues/'.$issue->public_id.'/notes', ['client_key' => (string) Str::uuid(), 'telescope_monitor_private' => ['body' => 'Investigated in separate storage.']])->assertOk();
        $connection = $this->postJson('/telescope-monitor/api/v1/alerting/email', ['client_key' => (string) Str::uuid(), 'name' => 'Array fixture', 'email' => 'fixture@example.test', 'enabled' => true])->assertOk()->json('telescope_monitor_private.id');
        $this->postJson('/telescope-monitor/api/v1/alerting/tests', ['client_key' => (string) Str::uuid(), 'connections' => [$connection]])->assertStatus(202);
        $delivery = DB::connection('monitor')->table('telescope_monitor_deliveries')->first();
        $this->assertTrue(app(DeliverySender::class)->send($delivery->id, $delivery->generation));
        $this->assertSame('sent', DB::connection('monitor')->table('telescope_monitor_deliveries')->value('status'));
        $this->assertHostIsolated();
        foreach (glob(__DIR__.'/../../src/Models/*.php') as $file) {
            if (basename($file) !== 'Model.php') {
                $class = 'TelescopeMonitor\\Models\\'.basename($file, '.php');
                $class::query()->count(); // An incorrectly routed model fails: host tables do not exist.
            }
        }
        $installation = app(InstallationRepository::class)->current();
        $purge = app(PurgeOperations::class);
        $purge->baseline($installation->public_id, $installation->public_id);
        $run = $purge->begin($installation->public_id, $installation->public_id, 'isolated-test')['run']['id'];
        for ($page = 0; $page < 10; $page++) {
            $result = $purge->resume($installation->public_id, $installation->public_id, $run, 100, 100);
            if ($result['run']['state'] === 'completed') {
                break;
            }
        }
        $this->assertSame('completed', $result['run']['state']);
        $this->assertSame(0, DB::connection('monitor')->table('telescope_monitor_occurrences')->count());
        $this->assertSame(1, DB::connection('source')->table('telescope_entries')->where('type', 'exception')->count());
        $this->assertHostIsolated();
    }

    public function test_transactions_and_after_commit_are_on_the_monitor_connection(): void
    {
        $called = false;
        Database::connection()->beginTransaction();
        Database::connection()->table('telescope_monitor_settings')->insert([
            'installation_id' => app(InstallationRepository::class)->current()->id,
            'group_key' => 'rollback_fixture',
            'values' => '{}',
            'values_schema_version' => 1,
            'row_version' => 1,
        ]);
        Database::connection()->afterCommit(function () use (&$called) {
            $called = true;
        });
        DB::connection('sqlite')->transaction(fn () => DB::connection('sqlite')->table('host_records')->count());
        $this->assertFalse($called);
        Database::connection()->rollBack();
        $this->assertFalse($called);
        $this->assertSame(0, Database::connection()->table('telescope_monitor_settings')->where('group_key', 'rollback_fixture')->count());
        Database::connection()->transaction(function () use (&$called) {
            Database::connection()->afterCommit(function () use (&$called) {
                $called = true;
            });
            $this->assertFalse($called);
        });
        $this->assertTrue($called);
        $this->assertHostIsolated();
    }

    public function test_unknown_connection_never_falls_back_to_the_primary_database(): void
    {
        config(['telescope-monitor.database.connection' => 'monitor_typo']);
        try {
            app(InstallationRepository::class)->storageState();
            $this->fail('An unknown connection must fail closed.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('monitor_typo', $e->getMessage());
        } finally {
            config(['telescope-monitor.database.connection' => 'monitor']);
        }
        $this->assertHostIsolated();
    }

    public function test_installer_and_migration_command_keep_host_migrations_out_of_monitor_storage(): void
    {
        $oldDatabase = $this->app->databasePath();
        $oldConfig = $this->app->configPath();
        $temporary = sys_get_temp_dir().'/monitor-separate-install-'.bin2hex(random_bytes(8));
        mkdir($temporary.'/migrations', 0777, true);
        mkdir($temporary.'/config', 0777, true);
        $this->app->useDatabasePath($temporary);
        $this->app->useConfigPath($temporary.'/config');
        $this->app->register(TelescopeMonitorServiceProvider::class, true);
        config([
            'database.connections.fresh_monitor' => config('database.connections.monitor'),
            'telescope-monitor.database.connection' => 'fresh_monitor',
        ]);
        try {
            file_put_contents($temporary.'/migrations/2099_01_01_000000_host_only.php', '<?php throw new RuntimeException("Host migration must not run");');
            $service = app(InstallationService::class);
            $this->assertTrue($service->publish());
            $this->assertTrue($service->publish());
            $this->assertCount(6, glob($temporary.'/migrations/telescope-monitor/*.php'));
            $this->assertCount(1, glob($temporary.'/migrations/*.php'));
            $this->assertSame(0, Artisan::call('telescope-monitor:migrate', ['--force' => true]));
            $this->assertSame(6, DB::connection('fresh_monitor')->table('migrations')->count());
            $this->assertSame(0, Artisan::call('telescope-monitor:migrate', ['--force' => true]));
            $this->assertSame(6, DB::connection('fresh_monitor')->table('migrations')->count());
            $this->assertSame('ready', app(InstallationRepository::class)->storageState());
            $this->assertSame(6, app(InstallationRepository::class)->current()->schema_version);
            $this->assertHostIsolated();
        } finally {
            config(['telescope-monitor.database.connection' => 'monitor']);
            $this->app->useDatabasePath($oldDatabase);
            $this->app->useConfigPath($oldConfig);
            foreach (glob($temporary.'/migrations/telescope-monitor/*.php') as $file) {
                unlink($file);
            }
            foreach (glob($temporary.'/migrations/*.php') as $file) {
                unlink($file);
            }
            foreach (glob($temporary.'/config/*.php') as $file) {
                unlink($file);
            }
            rmdir($temporary.'/migrations/telescope-monitor');
            rmdir($temporary.'/migrations');
            rmdir($temporary.'/config');
            rmdir($temporary);
        }
    }
}
