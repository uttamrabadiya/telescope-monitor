<?php

namespace TelescopeMonitor\Tests\Capture;

use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TelescopeMonitor\Capture\CaptureContext;
use TelescopeMonitor\Tests\Fixtures\FailingProbeJob;
use TelescopeMonitor\Tests\TestCase;

final class OriginsTest extends TestCase
{
    use CapturesExceptions;

    public function test_terminal_queued_job_is_captured_before_telescope_worker_flush(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/orchestra/testbench-core/laravel/migrations');
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
            'queue.failed.driver' => 'null',
        ]);
        Queue::connection('database')->push(new FailingProbeJob);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);
        $this->importSource();
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_occurrences', 1);
        $this->assertSame('job', DB::table('telescope_monitor_occurrences')->value('origin'));
    }

    public function test_retrying_worker_records_each_report_once_and_clears_context_for_next_operation(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/orchestra/testbench-core/laravel/migrations');
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
            'queue.failed.driver' => 'null',
        ]);
        Queue::connection('database')->push(new FailingProbeJob);
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 2, '--backoff' => 0, '--sleep' => 0]);
        Telescope::store(app(EntriesRepository::class));
        $this->importSource();
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_occurrences', 2);
        $this->assertSame(2, DB::table('telescope_monitor_occurrences')->where('origin', 'job')->count());
        $this->assertNull(app(CaptureContext::class)->job);
        $this->assertNull(app(CaptureContext::class)->probeId);
    }

    public function test_sync_job_failure_reported_by_host_is_captured_once(): void
    {
        try {
            Queue::connection('sync')->push(new FailingProbeJob);
        } catch (\Throwable $e) {
            report($e);
        }
        Telescope::store(app(EntriesRepository::class));
        $this->importSource();
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_occurrences', 1);
        $this->assertSame('job', DB::table('telescope_monitor_occurrences')->value('origin'));
    }

    public function test_console_kernel_exception_reaches_sanitized_evidence(): void
    {
        $kernel = new Kernel($this->app, $this->app['events']);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->command('phase2:failure', fn () => throw new \RuntimeException('password=console-secret'));
        $this->assertSame(1, $kernel->handle(new ArrayInput(['command' => 'phase2:failure']), new BufferedOutput));
        Telescope::store(app(EntriesRepository::class));
        $this->importSource();
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_occurrences', 1);
        $this->assertSame('command', DB::table('telescope_monitor_occurrences')->value('origin'));
        $this->assertStringNotContainsString('console-secret', DB::table('telescope_monitor_issues')->value('message'));
    }

    public function test_sync_origin_survives_filtered_related_job_records_without_leaking_context(): void
    {
        Telescope::filter(fn ($entry) => $entry->type !== 'job');
        try {
            Queue::connection('sync')->push(new FailingProbeJob);
        } catch (\Throwable $e) {
            report($e);
        }
        Telescope::store(app(EntriesRepository::class));
        $this->importSource();
        $this->process();
        $this->assertSame('job', DB::table('telescope_monitor_occurrences')->value('origin'));
        $this->assertNull(app(CaptureContext::class)->failedJobException);
        $this->capture('A later unrelated error');
        $this->importSource();
        $this->process();
        $this->assertSame('unknown', DB::table('telescope_monitor_occurrences')->orderByDesc('id')->value('origin'));
    }

    public function test_late_command_metadata_is_prioritized_over_a_large_related_query_batch(): void
    {
        for ($n = 0; $n < 150; $n++) {
            Telescope::recordQuery(IncomingEntry::make(['connection' => 'sqlite', 'time' => 1, 'sql' => 'select 1', 'bindings' => []]));
        }
        $kernel = new Kernel($this->app, $this->app['events']);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->command('phase4:many-queries', fn () => throw new \RuntimeException('Command failed'));
        $this->assertSame(1, $kernel->handle(new ArrayInput(['command' => 'phase4:many-queries']), new BufferedOutput));
        Telescope::store(app(EntriesRepository::class));
        $this->importSource();
        $this->process();
        $this->assertSame('command', DB::table('telescope_monitor_occurrences')->value('origin'));
        $payload = json_decode(Crypt::decryptString(DB::table('telescope_monitor_occurrence_evidence')->value('payload_ciphertext')), true);
        $this->assertSame('phase4:many-queries', $payload['execution']['command']);
    }
}
