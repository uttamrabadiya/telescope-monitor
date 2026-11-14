<?php

namespace TelescopeMonitor\Tests\Capture;

use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Telescope;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TelescopeMonitor\Tests\Fixtures\FailingProbeJob;
use TelescopeMonitor\Tests\TestCase;

final class HostQueryIsolationTest extends TestCase
{
    use CapturesExceptions;

    private function hostOnly(callable $work): void
    {
        $active = true;
        $queries = [];
        DB::connection()->beforeExecuting(function ($sql) use (&$active, &$queries) {
            if ($active && preg_match('/telescope_monitor_|sqlite_(schema|master)|information_schema|pg_catalog|pragma/i', $sql)) {
                $queries[] = $sql;
            }
        });
        try {
            $work();
            Telescope::store(app(EntriesRepository::class));
        } finally {
            $active = false;
        }
        $this->assertSame([], $queries, 'Host execution attempted monitoring queries.');
        $this->assertDatabaseCount('telescope_monitor_capture_inbox', 0);
        $this->assertDatabaseCount('telescope_monitor_occurrences', 0);
        $this->assertGreaterThan(0, DB::table('telescope_entries')->where('type', 'exception')->count());
        $this->importSource();
        $this->process();
        $this->assertGreaterThan(0, DB::table('telescope_monitor_occurrences')->count());
    }

    public function test_http_exception_has_zero_monitoring_queries_until_separate_import(): void
    {
        Route::get('/zero-query-error', fn () => throw new \RuntimeException('HTTP monitor isolation'));
        $this->hostOnly(fn () => $this->get('/zero-query-error')->assertStatus(500));
    }

    public function test_artisan_exception_has_zero_monitoring_queries_until_separate_import(): void
    {
        $kernel = new Kernel($this->app, $this->app['events']);
        if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
            $kernel->rerouteSymfonyCommandEvents();
        }
        $kernel->command('host:zero-query-error', fn () => throw new \RuntimeException('Console monitor isolation'));
        $this->hostOnly(fn () => $this->assertSame(1, $kernel->handle(new ArrayInput(['command' => 'host:zero-query-error']), new BufferedOutput)));
    }

    public function test_failed_async_job_has_zero_monitoring_queries_until_separate_import(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/orchestra/testbench-core/laravel/migrations');
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
            'queue.failed.driver' => 'null',
        ]);
        Queue::connection('database')->push(new FailingProbeJob);
        $this->hostOnly(fn () => Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]));
    }

    public function test_scheduled_host_command_has_zero_monitoring_queries_until_separate_import(): void
    {
        Artisan::command('host:scheduled-error', function () {
            report(new \RuntimeException('Scheduled monitor isolation'));
        });
        app(\Illuminate\Console\Scheduling\Schedule::class)->command('host:scheduled-error')->everyMinute();
        // Run the scheduled command in-process so its SQL can be observed by this test.
        $this->hostOnly(fn () => Artisan::call('host:scheduled-error'));
    }
}
