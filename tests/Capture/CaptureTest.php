<?php

namespace TelescopeMonitor\Tests\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\IncomingExceptionEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Watchers\ExceptionWatcher;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use TelescopeMonitor\Capture\CaptureOperations;
use TelescopeMonitor\Capture\CaptureProcessor;
use TelescopeMonitor\Capture\EvidenceReader;
use TelescopeMonitor\Jobs\ProcessCapture;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Tests\TestCase;

final class CaptureTest extends TestCase
{
    use CapturesExceptions;

    public function test_capture_is_durable_encrypted_and_never_processed_inline_on_sync_queue(): void
    {
        Bus::fake();
        $this->capture();
        $this->assertDatabaseCount('telescope_monitor_capture_inbox', 1);
        $this->assertDatabaseCount('telescope_monitor_issues', 0);
        $row = DB::table('telescope_monitor_capture_inbox')->first();
        $this->assertSame('pending', $row->state);
        $this->assertStringNotContainsString('private-value', $row->payload_ciphertext);
        $payload = Crypt::decryptString($row->payload_ciphertext);
        $this->assertStringNotContainsString('private-value', $payload);
        $this->assertStringNotContainsString('never-copy', $payload);
        $this->assertStringNotContainsString('opaque-123', $payload);
        Bus::assertNotDispatched(ProcessCapture::class);
        $this->assertSame(1, $this->process()['processed']);
        $this->assertDatabaseCount('telescope_monitor_issues', 1);
    }

    public function test_repeated_capture_and_source_replay_count_unique_occurrences_once(): void
    {
        $first = $this->capture('Order 100 failed');
        $this->capture('Order 200 failed');
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_issues', 1);
        $this->assertSame(2, (int) DB::table('telescope_monitor_issues')->value('lifetime_count'));
        $this->assertDatabaseCount('telescope_monitor_alert_events', 1);
        $this->assertSame('disabled', DB::table('telescope_monitor_alert_events')->value('planning_state'));
        app(CaptureOperations::class)->reconcile(100, 24);
        $this->process();
        $this->assertSame(2, (int) DB::table('telescope_monitor_issues')->value('lifetime_count'));
        $this->capture(class: \LogicException::class);
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_issues', 2);
        $this->assertDatabaseCount('telescope_monitor_occurrences', 3);
    }

    public function test_microseconds_and_out_of_order_first_seen_metrics_are_preserved(): void
    {
        $later = CarbonImmutable::now()->startOfHour()->addMinutes(20)->setMicrosecond(123456);
        $earlier = $later->subHour()->setMicrosecond(654321);
        $this->capture(at: $later);
        $this->process();
        $this->capture(at: $earlier);
        $this->process();
        $issue = DB::table('telescope_monitor_issues')->first();
        $this->assertStringContainsString('654321', $issue->first_seen_at);
        $this->assertStringContainsString('123456', $issue->last_seen_at);
        $this->assertSame(1, (int) DB::table('telescope_monitor_metric_buckets')->where('resolution', '1h')->sum('new_group_count'));
        $this->assertSame(2, (int) DB::table('telescope_monitor_metric_buckets')->where('resolution', '1h')->sum('occurrence_count'));
    }

    public function test_filtering_and_nonreportable_exceptions_do_not_buffer(): void
    {
        Telescope::filter(fn () => false);
        $this->capture();
        $this->assertDatabaseCount('telescope_monitor_capture_inbox', 0);
        Telescope::$filterUsing = [];
        Telescope::filterBatch(fn () => false);
        $this->capture();
        $this->assertDatabaseCount('telescope_monitor_capture_inbox', 0);
        Telescope::$filterBatchUsing = [];
        $exception = new NotFoundHttpException;
        Telescope::recordException(IncomingExceptionEntry::make($exception, ['class' => $exception::class, 'message' => '404', 'file' => __FILE__, 'line' => 1]));
        Telescope::store(app(EntriesRepository::class));
        $this->assertDatabaseCount('telescope_monitor_capture_inbox', 0);
    }

    public function test_stricter_current_privacy_is_applied_before_evidence_persistence(): void
    {
        $this->capture();
        config([
            'telescope-monitor.locked' => [
                'privacy.retain_stack_trace' => false,
                'privacy.retain_execution_context' => false,
                'privacy.retain_related_logs' => false,
            ],
        ]);
        $this->process();
        $payload = json_decode(Crypt::decryptString(DB::table('telescope_monitor_occurrence_evidence')->value('payload_ciphertext')), true);
        $this->assertSame([], $payload['exception']['trace']);
        $this->assertSame([], $payload['execution']);
        $this->assertSame('excluded', $payload['coverage']['trace']);
    }

    public function test_source_pruning_does_not_remove_evidence_and_retention_does_not_remove_identity(): void
    {
        $uuid = $this->capture();
        $this->process();
        $occurrence = DB::table('telescope_monitor_occurrences')->first();
        DB::table('telescope_entries')->delete();
        $this->assertSame('available', app(EvidenceReader::class)->read($occurrence->public_id)['evidence_state']);
        $this->travel(31)->days();
        $this->assertSame('expired', app(EvidenceReader::class)->read($occurrence->public_id)['evidence_state']);
        app(CaptureOperations::class)->prune(100);
        $this->assertDatabaseCount('telescope_monitor_occurrence_evidence', 0);
        $this->assertDatabaseCount('telescope_monitor_occurrences', 1);
        $this->assertSame(1, (int) DB::table('telescope_monitor_issues')->value('lifetime_count'));
    }

    public function test_expired_claim_is_recovered_and_fresh_claim_is_not_stolen(): void
    {
        $this->capture();
        $row = DB::table('telescope_monitor_capture_inbox')->first();
        DB::table('telescope_monitor_capture_inbox')->where('id', $row->id)->update(['state' => 'processing', 'claim_token' => (string) Str::uuid(), 'lease_until' => now()->addMinutes(2)]);
        $this->assertFalse(app(CaptureProcessor::class)->process($row->id));
        $this->travel(3)->minutes();
        $this->assertTrue(app(CaptureProcessor::class)->process($row->id));
        $this->assertSame('completed', DB::table('telescope_monitor_capture_inbox')->value('state'));
    }

    public function test_undecryptable_and_unknown_envelopes_are_quarantined_without_raw_errors(): void
    {
        $this->capture();
        $row = DB::table('telescope_monitor_capture_inbox')->first();
        DB::table('telescope_monitor_capture_inbox')->where('id', $row->id)->update(['payload_ciphertext' => 'not-a-cipher']);
        $this->assertFalse(app(CaptureProcessor::class)->process($row->id));
        $this->assertSame('decryption_failed', DB::table('telescope_monitor_capture_inbox')->value('last_error_code'));
        $this->capture('Another occurrence');
        $last = DB::table('telescope_monitor_capture_inbox')->orderByDesc('id')->first();
        DB::table('telescope_monitor_capture_inbox')->where('id', $last->id)->update(['envelope_version' => 999]);
        $this->assertFalse(app(CaptureProcessor::class)->process($last->id));
        $this->assertSame('unsupported_envelope_version', DB::table('telescope_monitor_capture_inbox')->where('id', $last->id)->value('last_error_code'));
        $this->assertDatabaseCount('telescope_monitor_occurrences', 0);
    }

    public function test_new_generation_and_epoch_fence_stale_work(): void
    {
        $this->capture();
        DB::table('telescope_monitor_installations')->increment('generation');
        $this->process();
        $this->assertSame('cancelled', DB::table('telescope_monitor_capture_inbox')->value('state'));
        $this->assertDatabaseCount('telescope_monitor_occurrences', 0);
    }

    public function test_disabled_capture_or_exception_watcher_does_not_buffer(): void
    {
        config(['telescope.watchers.'.ExceptionWatcher::class => false]);
        $this->capture();
        $this->assertDatabaseCount('telescope_monitor_capture_inbox', 0);
        config(['telescope.watchers.'.ExceptionWatcher::class => true, 'telescope-monitor.capture.enabled' => false]);
        $this->capture();
        $this->assertDatabaseCount('telescope_monitor_capture_inbox', 0);
    }

    public function test_untrusted_synthetic_fields_cannot_opt_into_probe_capture(): void
    {
        $e = new RuntimeException('Ordinary host failure');
        Telescope::recordException(IncomingExceptionEntry::make($e, [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => __FILE__,
            'line' => 1,
            'trace' => [],
            'probe_id' => (string) Str::uuid(),
            'is_test' => true,
            'mode' => 'synthetic_test',
        ]));
        Telescope::store(app(EntriesRepository::class));
        $this->importSource();
        $this->process();
        $this->assertSame('live', DB::table('telescope_monitor_occurrences')->value('mode'));
        $this->assertFalse((bool) DB::table('telescope_monitor_issues')->value('is_test'));
    }

    public function test_buffer_write_failure_preserves_source_and_reconciliation_can_recover(): void
    {
        $contract = CaptureInboxRepository::class;
        $original = app($contract);
        $mock = \Mockery::mock($contract);
        $mock->shouldNotReceive('ready');
        $mock->shouldReceive('enqueue')->andThrow(new RuntimeException('Infrastructure failure'));
        $this->app->instance($contract, $mock);
        $uuid = $this->capture(import: false);
        try {
            $this->importSource();
            $this->fail('Expected importer storage failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('maintenance_failed', $e->getMessage());
        }
        $this->assertTrue(DB::table('telescope_entries')->where('uuid', $uuid)->exists());
        $this->assertDatabaseCount('telescope_monitor_capture_inbox', 0);
        $this->app->instance($contract, $original);
        app(CaptureOperations::class)->reconcile(100);
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_occurrences', 1);
        $this->assertSame('reconciliation', DB::table('telescope_monitor_occurrences')->value('mode'));
    }

    public function test_real_http_exception_reaches_retained_evidence(): void
    {
        Route::get('/payment-failure', fn () => throw new RuntimeException('HTTP token=hidden'));
        $this->get('/payment-failure')->assertStatus(500);
        Telescope::store(app(EntriesRepository::class));
        $this->importSource();
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_occurrences', 1);
        $this->assertStringNotContainsString('hidden', DB::table('telescope_monitor_issues')->value('message'));
    }
}
