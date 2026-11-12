<?php

namespace TelescopeMonitor\Tests\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\IncomingExceptionEntry;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Capture\CaptureFailure;
use TelescopeMonitor\Capture\SourceMarker;
use TelescopeMonitor\Operations\PurgeOperations;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Tests\TestCase;

final class TelescopeImporterTest extends TestCase
{
    use CapturesExceptions;

    public function test_explicit_import_command_processes_bounded_live_source_and_deduplicates_restart(): void
    {
        for ($n = 0; $n < 3; $n++) {
            $this->capture(import: false);
        }
        $this->artisan('exception-monitor:import', ['--limit' => 2, '--process' => true])->assertSuccessful();
        $this->assertDatabaseCount('exception_monitor_occurrences', 2);
        $this->artisan('exception-monitor:import', ['--limit' => 2, '--process' => true])->assertSuccessful();
        $this->artisan('exception-monitor:import', ['--limit' => 2, '--process' => true])->assertSuccessful();
        $this->assertDatabaseCount('exception_monitor_occurrences', 3);
        $this->assertSame(3, DB::table('exception_monitor_occurrences')->where('mode', 'live')->count());
        $this->assertSame(1, DB::table('exception_monitor_alert_events')->where('event_type', 'error.created')->count());
        $this->assertSame((int) DB::table('telescope_entries')->max('sequence'), (int) DB::table('exception_monitor_installations')->value('source_sequence_checkpoint'));
    }

    public function test_interrupted_page_does_not_advance_checkpoint_and_restart_deduplicates_buffered_rows(): void
    {
        $this->capture(import: false);
        $this->capture(import: false);
        $original = app(CaptureInboxRepository::class);
        $calls = 0;
        $mock = \Mockery::mock(CaptureInboxRepository::class);
        $mock->shouldReceive('enqueue')->andReturnUsing(function ($attributes) use (&$calls, $original) {
            if (++$calls === 2) {
                throw new \RuntimeException('private storage failure');
            }
            return $original->enqueue($attributes);
        });
        $this->app->instance(CaptureInboxRepository::class, $mock);
        try {
            $this->importSource();
            $this->fail('Expected interrupted import.');
        } catch (\TelescopeMonitor\Operations\MaintenanceFailure $e) {
            $this->assertSame('maintenance_failed', $e->getMessage());
        } finally {
            $this->app->instance(CaptureInboxRepository::class, $original);
        }
        $this->assertSame(0, (int) DB::table('exception_monitor_installations')->value('source_sequence_checkpoint'));
        $this->assertDatabaseCount('exception_monitor_capture_inbox', 1);
        $this->importSource();
        $this->process();
        $this->assertDatabaseCount('exception_monitor_occurrences', 2);
        $this->assertSame(2, (int) DB::table('exception_monitor_issues')->value('lifetime_count'));
    }

    public function test_tampered_source_payload_cannot_reuse_a_valid_proof(): void
    {
        $bad = $this->capture(import: false);
        $content = json_decode(DB::table('telescope_entries')->where('uuid', $bad)->value('content'), true);
        $content['message'] = 'Forged replacement';
        DB::table('telescope_entries')->where('uuid', $bad)->update(['content' => json_encode($content)]);
        $good = $this->capture(import: false);
        $this->importSource();
        $this->process();
        $this->assertDatabaseCount('exception_monitor_occurrences', 1);
        $this->assertSame($good, DB::table('exception_monitor_occurrences')->value('source_uuid'));
    }

    public function test_previous_deployment_key_can_verify_retained_source_after_rotation(): void
    {
        $old = config('app.key');
        $uuid = $this->capture(import: false);
        config([
            'exception-monitor.capture.signing_key' => str_repeat('n', 32),
            'exception-monitor.capture.signing_previous_keys' => [$old],
        ]);
        $this->importSource();
        $this->process();
        $this->assertSame($uuid, DB::table('exception_monitor_occurrences')->value('source_uuid'));
    }

    public function test_signer_failure_has_no_database_fallback_and_keeps_telescope_exception(): void
    {
        config(['exception-monitor.capture.signing_key' => 'invalid']);
        $uuid = $this->capture(import: false);
        $this->assertTrue(DB::table('telescope_entries')->where('uuid', $uuid)->exists());
        $this->assertSame(0, DB::table('telescope_entries_tags')->where('entry_uuid', $uuid)->where('tag', 'like', 'em3|%')->count());
        $this->assertDatabaseCount('exception_monitor_capture_inbox', 0);
        $this->expectException(CaptureFailure::class);
        $this->expectExceptionMessage('source_signing_key_invalid');
        $this->importSource();
    }

    public function test_grouping_callback_is_deferred_to_importer_and_mismatched_version_blocks_checkpoint(): void
    {
        $calls = 0;
        config([
            'exception-monitor.capture.grouping_callback' => function ($input) use (&$calls) {
                $calls++;
                return json_encode($input);
            },
        ]);
        $this->capture(import: false);
        $this->assertSame(0, $calls);
        config(['exception-monitor.capture.grouping_version' => 2]);
        try {
            $this->importSource();
            $this->fail('Expected grouping version mismatch.');
        } catch (CaptureFailure $e) {
            $this->assertSame('source_grouping_version_unavailable', $e->getMessage());
        }
        $this->assertSame(0, (int) DB::table('exception_monitor_installations')->value('source_sequence_checkpoint'));
        config(['exception-monitor.capture.grouping_version' => 1]);
        $this->importSource();
        $this->assertGreaterThan(0, $calls);
    }

    public function test_old_database_signed_markers_remain_recoverable_without_live_alert_semantics(): void
    {
        $i = app(InstallationRepository::class)->current();
        $uuid = (string) Str::uuid();
        $content = ['class' => 'RuntimeException', 'message' => 'Legacy', 'file' => __FILE__, 'line' => 1, 'trace' => []];
        $tags = app(SourceMarker::class)->tags($i, $uuid, CarbonImmutable::now('UTC'), $content, null);
        DB::table('telescope_entries')->insert([
            'uuid' => $uuid,
            'batch_id' => (string) Str::uuid(),
            'type' => 'exception',
            'content' => json_encode($content),
            'created_at' => now(),
        ]);
        foreach ($tags as $tag) {
            DB::table('telescope_entries_tags')->insert(['entry_uuid' => $uuid, 'tag' => $tag]);
        }
        $this->importSource();
        $this->process();
        $this->assertSame('reconciliation', DB::table('exception_monitor_occurrences')->value('mode'));
        $this->assertSame('disabled', DB::table('exception_monitor_alert_events')->value('planning_state'));
    }

    public function test_delayed_commit_after_purge_cannot_resurrect_a_pre_purge_stamp_even_with_future_event_time(): void
    {
        $this->capture();
        $this->process();
        $exception = new \RuntimeException('Delayed host commit');
        $entry = IncomingExceptionEntry::make($exception, ['class' => $exception::class, 'message' => $exception->getMessage(), 'file' => __FILE__, 'line' => 1, 'trace' => []]);
        $entry->recordedAt = CarbonImmutable::now('UTC')->addHour();
        Telescope::recordException($entry);
        $this->travel(2)->seconds();
        $i = app(InstallationRepository::class)->current();
        $purge = app(PurgeOperations::class);
        $run = $purge->begin($i->public_id, $i->public_id)['run']['id'];
        for ($n = 0; $n < 10; $n++) {
            $state = $purge->resume($i->public_id, $i->public_id, $run, 1000, 100);
            if ($state['run']['state'] === 'completed') {
                break;
            }
        }
        $this->assertSame('completed', $state['run']['state']);
        Telescope::store(app(EntriesRepository::class));
        $this->importSource();
        $this->process();
        $this->assertDatabaseCount('exception_monitor_occurrences', 0);
        $this->assertDatabaseCount('exception_monitor_deliveries', 0);
        $this->travel(2)->seconds();
        $fresh = $this->capture(import: false);
        $this->importSource();
        $this->process();
        $this->assertSame($fresh, DB::table('exception_monitor_occurrences')->value('source_uuid'));
    }

    public function test_pause_during_drain_does_not_consume_the_source_checkpoint(): void
    {
        $this->capture(import: false);
        $i = app(InstallationRepository::class)->current();
        DB::table('exception_monitor_installations')->update(['state' => 'draining']);
        $this->assertTrue($this->importSource()['paused']);
        $this->assertSame(0, (int) DB::table('exception_monitor_installations')->value('source_sequence_checkpoint'));
        DB::table('exception_monitor_installations')->update(['state' => 'active']);
        $this->importSource();
        $this->process();
        $this->assertDatabaseCount('exception_monitor_occurrences', 1);
    }
}
