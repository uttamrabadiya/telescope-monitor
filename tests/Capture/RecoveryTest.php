<?php

namespace TelescopeMonitor\Tests\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\IncomingExceptionEntry;
use Laravel\Telescope\Telescope;
use RuntimeException;
use TelescopeMonitor\Capture\CaptureOperations;
use TelescopeMonitor\Jobs\ProcessCapture;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Repositories\Contracts\MetricBucketRepository;
use TelescopeMonitor\Telescope\StoredEntries;
use TelescopeMonitor\Telescope\StoredExceptionAdapter;
use TelescopeMonitor\Tests\TestCase;

final class RecoveryTest extends TestCase
{
    use CapturesExceptions;

    public function test_queue_outage_leaves_durable_input_for_later_dispatch(): void
    {
        config(['queue.default' => 'database']);
        Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('broker password=private'));
        $this->capture();
        $this->assertDatabaseCount('exception_monitor_capture_inbox', 1);
        $this->assertSame('pending', DB::table('exception_monitor_capture_inbox')->value('state'));
        // Replace the mocked facade with a fresh real dispatcher before faking it.
        Bus::swap(new Dispatcher($this->app));
        Bus::fake();
        $this->assertSame(1, app(CaptureOperations::class)->recover(100)['capture_jobs']);
        Bus::assertDispatched(ProcessCapture::class, 1);
    }

    public function test_failure_mid_transaction_rolls_back_counts_evidence_and_events_then_retries(): void
    {
        $this->capture();
        $original = app(MetricBucketRepository::class);
        $this->app->instance(MetricBucketRepository::class, \Mockery::mock(MetricBucketRepository::class)->shouldReceive('record')->andThrow(new RuntimeException('write outage'))->getMock());
        $this->process();
        $this->assertDatabaseCount('exception_monitor_occurrences', 0);
        $this->assertDatabaseCount('exception_monitor_issues', 0);
        $this->assertDatabaseCount('exception_monitor_occurrence_evidence', 0);
        $this->assertDatabaseCount('exception_monitor_alert_events', 0);
        $this->assertSame('retrying', DB::table('exception_monitor_capture_inbox')->value('state'));
        $this->app->instance(MetricBucketRepository::class, $original);
        $this->travel(2)->minutes();
        $this->process();
        $this->assertSame(1, (int) DB::table('exception_monitor_issues')->value('lifetime_count'));
        $this->assertDatabaseCount('exception_monitor_occurrences', 1);
    }

    public function test_processing_failures_are_bounded_and_do_not_recursively_capture(): void
    {
        $this->capture();
        $this->app->instance(MetricBucketRepository::class, \Mockery::mock(MetricBucketRepository::class)->shouldReceive('record')->andThrow(new RuntimeException('password=do-not-report'))->getMock());
        for ($i = 0; $i < 6; $i++) {
            $this->process();
            $this->travel(2)->hours();
        }
        $row = DB::table('exception_monitor_capture_inbox')->first();
        $this->assertSame('failed', $row->state);
        $this->assertSame(5, (int) $row->attempt_count);
        $this->assertSame('processing_failed', $row->last_error_code);
        $this->assertDatabaseCount('exception_monitor_capture_inbox', 1);
        $this->assertDatabaseCount('exception_monitor_issues', 0);
    }

    public function test_reconciliation_recovers_missing_inbox_rows_without_retroactive_alerts(): void
    {
        $this->capture();
        DB::table('exception_monitor_capture_inbox')->delete();
        $result = app(CaptureOperations::class)->reconcile(100, 24);
        $this->assertSame(1, $result['buffered']);
        $this->process();
        $this->assertSame('reconciliation', DB::table('exception_monitor_occurrences')->value('mode'));
        $this->assertTrue((bool) DB::table('exception_monitor_issues')->value('recovered_only'));
        $this->assertSame(0, (int) DB::table('exception_monitor_issues')->value('alert_generation'));
        $this->assertSame('disabled', DB::table('exception_monitor_alert_events')->value('planning_state'));
        $this->capture();
        $this->process();
        $this->assertSame(2, (int) DB::table('exception_monitor_issues')->value('lifetime_count'));
        $this->assertDatabaseCount('exception_monitor_alert_events', 1);
    }

    public function test_replay_after_pruning_does_not_rehydrate_or_recount_an_old_occurrence(): void
    {
        $uuid = $this->capture();
        $this->process();
        $source = app(StoredEntries::class)->exceptionByUuid($uuid);
        $this->travel(31)->days();
        app(CaptureOperations::class)->prune(100);
        app(StoredExceptionAdapter::class)->replayRows($source, 'reconciliation');
        $this->process();
        $this->assertSame(1, (int) DB::table('exception_monitor_issues')->value('lifetime_count'));
        $this->assertDatabaseCount('exception_monitor_occurrence_evidence', 0);
        $this->assertDatabaseCount('exception_monitor_occurrences', 1);
    }

    public function test_signature_cannot_be_transplanted_to_another_source_uuid(): void
    {
        $uuid = $this->capture();
        $row = app(StoredEntries::class)->exceptionByUuid($uuid)[0];
        $row['uuid'] = (string) Str::uuid();
        $this->assertSame(0, app(StoredExceptionAdapter::class)->replayRows([$row], 'reconciliation')['buffered']);
        $this->assertDatabaseCount('exception_monitor_capture_inbox', 1);
    }

    public function test_backfill_older_than_resolution_preserves_status_but_provably_newer_live_capture_regresses(): void
    {
        $this->capture(at: CarbonImmutable::now('UTC')->subHour());
        $this->process();
        $cutoff = CarbonImmutable::now('UTC');
        DB::table('exception_monitor_issues')->update([
            'status' => 'resolved',
            'resolved_at' => $cutoff->format('Y-m-d H:i:s.u'),
            'resolution_cutoff_at' => $cutoff->format('Y-m-d H:i:s.u'),
        ]);
        $this->capture(at: $cutoff->subMinutes(30));
        $this->process();
        $this->assertSame('resolved', DB::table('exception_monitor_issues')->value('status'));
        $this->capture(at: $cutoff->addSecond());
        $this->process();
        $this->assertSame('unresolved', DB::table('exception_monitor_issues')->value('status'));
        $this->assertSame(1, DB::table('exception_monitor_alert_events')->where('event_type', 'error.regressed')->count());
    }

    public function test_importer_replaces_inline_overflow_with_bounded_source_pages(): void
    {
        config(['exception-monitor.capture.inline_limit' => 2]);
        for ($i = 0; $i < 8; $i++) {
            $e = new RuntimeException('Overflow '.$i);
            Telescope::recordException(IncomingExceptionEntry::make($e, [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'file' => base_path('app/Overflow.php'),
                'line' => 9,
                'trace' => [],
            ]));
        }
        Telescope::store(app(EntriesRepository::class));
        $this->assertDatabaseCount('exception_monitor_capture_inbox', 0);
        for ($i = 0; $i < 5; $i++) {
            $this->importSource(2);
            $this->process();
        }
        $this->assertDatabaseCount('exception_monitor_occurrences', 8);
        $this->assertSame(8, (int) DB::table('exception_monitor_issues')->value('lifetime_count'));
        $this->assertSame(0, DB::table('exception_monitor_capture_inbox')->where('state', 'failed')->count());
    }

    public function test_pruning_completed_inbox_preserves_actual_capture_health_observations(): void
    {
        $this->capture();
        $this->process();
        $this->travel(2)->days();
        app(CaptureOperations::class)->prune(100);
        $this->assertDatabaseCount('exception_monitor_capture_inbox', 0);
        $status = app(CaptureInboxRepository::class)->status();
        $this->assertNotNull($status['last_received_at']);
        $this->assertNotNull($status['last_processed_at']);
        $this->assertSame(0, $status['pending']);
    }

    public function test_host_timezone_does_not_change_utc_ingestion_or_expiry(): void
    {
        $old = date_default_timezone_get();
        date_default_timezone_set('Asia/Kolkata');
        config(['app.timezone' => 'Asia/Kolkata']);
        try {
            $time = CarbonImmutable::now('UTC')->setMicrosecond(234567);
            $this->capture(at: $time);
            $this->process();
            $stored = DB::table('exception_monitor_occurrences')->value('occurred_at');
            $this->assertSame($time->format('Y-m-d H:i:s.u'), $stored);
            $this->assertDatabaseCount('exception_monitor_occurrence_evidence', 1);
            DB::table('exception_monitor_occurrence_evidence')->update(['expires_at' => $time->addMinutes(10)->format('Y-m-d H:i:s.u')]);
            app(CaptureOperations::class)->prune(100);
            $this->assertDatabaseCount('exception_monitor_occurrence_evidence', 1);
        } finally {
            date_default_timezone_set($old);
        }
    }
}
