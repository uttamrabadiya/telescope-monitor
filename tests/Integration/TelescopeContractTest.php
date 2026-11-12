<?php

namespace TelescopeMonitor\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\IncomingExceptionEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use TelescopeMonitor\TelescopeMonitorServiceProvider;
use TelescopeMonitor\Tests\Fixtures\FailingProbeJob;
use TelescopeMonitor\Tests\Fixtures\StorageProbeProvider;
use TelescopeMonitor\Tests\TestCase;

final class TelescopeContractTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TelescopeServiceProvider::class, StorageProbeProvider::class, TelescopeMonitorServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        StorageProbeProvider::$observations = [];
        Telescope::startRecording(false);
    }

    public function test_early_storage_callback_runs_and_late_falsey_chain_is_observed(): void
    {
        $lateRan = false;
        Telescope::afterStoring(function () use (&$lateRan) {
            $lateRan = true;

            return true;
        });
        $entry = $this->exceptionEntry(new RuntimeException('Source contract fixture'));
        $entry->recordedAt = Carbon::parse('2026-11-11 00:00:00.123456');
        Telescope::recordException($entry);
        Telescope::store(app(EntriesRepository::class));
        $observed = collect(StorageProbeProvider::$observations)->firstWhere('uuid', $entry->uuid);
        $this->assertNotNull($observed);
        $this->assertSame('123456', $observed['microseconds']);
        $this->assertFalse($lateRan, 'The inspected ModelWatcher returns null and short-circuits later callbacks.');
        $source = DB::table('telescope_entries')->where('uuid', $entry->uuid)->first();
        $this->assertStringNotContainsString('123456', $source->created_at);
    }

    public function test_entry_and_batch_filters_are_respected_by_the_storage_opportunity(): void
    {
        Telescope::filter(fn () => false);
        $entry = $this->exceptionEntry(new RuntimeException('Excluded entry'));
        Telescope::recordException($entry);
        Telescope::store(app(EntriesRepository::class));
        $this->assertFalse(DB::table('telescope_entries')->where('uuid', $entry->uuid)->exists());
        Telescope::$filterUsing = [];
        Telescope::filterBatch(fn () => false);
        $entry = $this->exceptionEntry(new RuntimeException('Excluded batch'));
        Telescope::recordException($entry);
        Telescope::store(app(EntriesRepository::class));
        $this->assertFalse(DB::table('telescope_entries')->where('uuid', $entry->uuid)->exists());
    }

    public function test_reportability_can_be_marked_while_the_live_throwable_exists(): void
    {
        Telescope::tag(fn ($entry) => $entry->isReportableException() ? ['monitor-contract:reportable'] : []);
        $reportable = $this->exceptionEntry(new RuntimeException('Reportable fixture'));
        $excluded = $this->exceptionEntry(new NotFoundHttpException('Nonreportable fixture'));
        Telescope::recordException($reportable);
        Telescope::recordException($excluded);
        Telescope::store(app(EntriesRepository::class));
        $this->assertTrue(DB::table('telescope_entries_tags')->where('entry_uuid', $reportable->uuid)->where('tag', 'monitor-contract:reportable')->exists());
        $this->assertFalse(DB::table('telescope_entries_tags')->where('entry_uuid', $excluded->uuid)->where('tag', 'monitor-contract:reportable')->exists());
    }

    public function test_private_envelopes_are_redacted_in_telescope_source_request_and_response(): void
    {
        Route::post('/source-private-proof', fn () => response()->json([
            'telescope_monitor_private' => [
                'generated_secret' => 'proof-secret-response',
            ],
        ]));
        $this->postJson('/source-private-proof', [
            'telescope_monitor_private' => [
                'token' => 'proof-secret-request',
                'sample_text' => 'proof-private-text',
            ],
        ])->assertOk();
        Telescope::store(app(EntriesRepository::class));
        $content = DB::table('telescope_entries')->where('type', 'request')->orderByDesc('sequence')->value('content');
        $this->assertNotNull($content);
        foreach (['proof-secret-response', 'proof-secret-request', 'proof-private-text'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
        $this->assertStringContainsString('********', $content);
    }

    public function test_own_paths_are_ignored_without_mutating_application_filter_callbacks(): void
    {
        $check = new \ReflectionMethod(Telescope::class, 'requestIsToApprovedUri');
        $this->assertFalse($check->invoke(null, Request::create('/telescope-monitor/settings/privacy')));
        $this->assertFalse($check->invoke(null, Request::create('/telescope-monitor')));
        $this->assertTrue($check->invoke(null, Request::create('/checkout')));
        $this->assertSame([], Telescope::$filterUsing);
        $this->assertSame([], Telescope::$filterBatchUsing);
    }

    public function test_real_http_exception_reporting_reaches_the_exception_watcher(): void
    {
        Route::get('/contract/http-failure', fn () => throw new RuntimeException('HTTP contract report'));
        $this->get('/contract/http-failure')->assertStatus(500);
        Telescope::store(app(EntriesRepository::class));
        $content = DB::table('telescope_entries')->where('type', 'exception')->orderByDesc('sequence')->value('content');
        $this->assertNotNull($content);
        $this->assertSame('HTTP contract report', json_decode($content, true)['message']);
    }

    public function test_real_console_kernel_reporting_reaches_the_exception_watcher(): void
    {
        // Testbench intentionally rethrows console errors; exercise Laravel's real reporting kernel.
        $kernel = new \Illuminate\Foundation\Console\Kernel($this->app, $this->app['events']);
        $kernel->command('contract:console-failure', fn () => throw new RuntimeException('Console contract report'));
        $status = $kernel->handle(
            new ArrayInput(['command' => 'contract:console-failure']),
            new BufferedOutput,
        );
        $this->assertSame(1, $status);
        Telescope::store(app(EntriesRepository::class));
        $content = DB::table('telescope_entries')->where('type', 'exception')->orderByDesc('sequence')->value('content');
        $this->assertNotNull($content);
        $this->assertSame('Console contract report', json_decode($content, true)['message']);
    }

    public function test_terminal_worker_ordering_requires_the_planned_bridge(): void
    {
        config(['telescope-monitor.capture.bridge_jobs' => false]);
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/orchestra/testbench-core/laravel/migrations');
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
            'queue.failed.driver' => 'null',
        ]);
        Queue::connection('database')->push(new FailingProbeJob);
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--tries' => 1,
        ]);
        $this->assertFalse(Telescope::isRecording());
        $this->assertSame(0, DB::table('telescope_entries')->where('type', 'exception')->count(), 'The prototype ordering gap is reproduced; do not claim capture readiness until the bridge is implemented.');
    }

    private function exceptionEntry(\Throwable $exception): IncomingExceptionEntry
    {
        return IncomingExceptionEntry::make($exception, [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => [],
        ]);
    }
}
