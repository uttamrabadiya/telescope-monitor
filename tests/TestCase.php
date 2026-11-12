<?php

namespace TelescopeMonitor\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use TelescopeMonitor\Auth\ActorResolver;
use TelescopeMonitor\Data\Actor;
use TelescopeMonitor\TelescopeMonitorServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [TelescopeServiceProvider::class, TelescopeMonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = getenv('MONITOR_DB') ?: 'sqlite';
        $app['config']->set('database.default', $driver);
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        if ($driver === 'mysql') {
            $app['config']->set('database.connections.mysql', [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'unix_socket' => getenv('MONITOR_DB_SOCKET') ?: '',
                'database' => getenv('DB_DATABASE') ?: 'telescope_monitor_test',
                'username' => 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
            ]);
        }
        $app['config']->set('telescope.storage.database.connection', $driver);
        $app['config']->set('telescope-monitor.middleware', ['web']);
        // Existing capability-specific domain tests exercise the opt-in gate mode.
        // TelescopeAuthorizationTest covers the default native Telescope policy.
        $app['config']->set('telescope-monitor.authorization.driver', 'gates');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        $app['config']->set('app.url', 'https://example.test');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('session.driver', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/telescope/database/migrations');
    }

    protected function setUp(): void
    {
        Telescope::$afterStoringHooks = [];
        Telescope::$entriesQueue = [];
        Telescope::$updatesQueue = [];
        Telescope::$filterUsing = [];
        Telescope::$filterBatchUsing = [];
        Telescope::$tagUsing = [];
        parent::setUp();
        // Migration/setup telemetry does not belong to the request or job under test.
        Telescope::$entriesQueue = [];
        Telescope::$updatesQueue = [];
        $this->app->bind(ActorResolver::class, fn () => new class implements ActorResolver
        {
            public function current(): ?Actor
            {
                return new Actor('testing:1', 'Test operator');
            }

            public function resolve(Actor $actor): ?Authenticatable
            {
                return $actor->key === 'testing:1' ? new GenericUser(['id' => 1]) : null;
            }
        });
    }

    protected function authorize(bool $operate = true, bool $manage = true): void
    {
        Gate::define('viewTelescopeMonitor', fn ($user) => true);
        Gate::define('operateTelescopeMonitor', fn ($user) => $operate);
        Gate::define('manageTelescopeMonitor', fn ($user) => $manage);
    }

    protected function tearDown(): void
    {
        Telescope::stopRecording();
        Telescope::$entriesQueue = [];
        parent::tearDown();
    }
}
