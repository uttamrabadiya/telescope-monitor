<?php

namespace TelescopeMonitor\Tests\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use TelescopeMonitor\Alerts\NotificationProvider;
use TelescopeMonitor\Alerts\Outcome;
use TelescopeMonitor\Capture\CaptureFailure;
use TelescopeMonitor\Capture\HistoricalBackfill;
use TelescopeMonitor\Operations\PurgeOperations;
use TelescopeMonitor\Operations\RetentionSweep;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Tests\TestCase;

final class BackfillTest extends TestCase
{
    use CapturesExceptions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorize();
        $this->travelTo(CarbonImmutable::parse('2026-11-11T10:10:10Z'));
        config(['exception-monitor.alerts.allowed_environments' => ['testing']]);
        $this->app->bind(NotificationProvider::class, fn () => new class implements NotificationProvider
        {
            public function send(array $envelope, array $target): Outcome
            {
                throw new \RuntimeException('Backfill must not send');
            }
        });
    }

    private function source(): Builder
    {
        return DB::connection(config('telescope.storage.database.connection'))->table('telescope_entries');
    }

    private function legacy(array $content = [], string $time = '2026-11-11 10:10:01', ?string $uuid = null, ?string $batch = null): string
    {
        $uuid ??= (string) Str::uuid();
        $this->source()->insert(['uuid' => $uuid, 'batch_id' => $batch ?? (string) Str::uuid(), 'type' => 'exception', 'family_hash' => null, 'content' => json_encode(array_replace(['class' => 'RuntimeException', 'message' => 'Legacy failure', 'file' => base_path('app/Services/Payment.php'), 'line' => 42, 'trace' => []], $content)), 'created_at' => $time, 'should_display_on_index' => true]);

        return $uuid;
    }

    private function backfill(int $after = 0, int $limit = 100, ?int $through = null, bool $dry = false): array
    {
        return app(HistoricalBackfill::class)->run('testing', $after, $limit, $through, $dry);
    }

    private function rule(array $extra = []): void
    {
        $connection = $this->postJson('/exception-monitor/api/v1/alerting/email', ['client_key' => (string) Str::uuid(), 'name' => 'Never sent', 'email' => 'fixture@example.test', 'enabled' => true])->assertOk()->json('exception_monitor_private.id');
        $this->postJson('/exception-monitor/api/v1/alerting/rules', array_replace(['client_key' => (string) Str::uuid(), 'name' => 'Configured before import', 'enabled' => true, 'events' => ['error.created', 'error.regressed', 'error.threshold_reached'], 'conditions' => ['environments' => ['testing'], 'class' => null, 'origin' => null, 'tag' => null], 'connections' => [$connection], 'threshold_count' => 2, 'window_seconds' => 300, 'cooldown_seconds' => 0], $extra))->assertOk();
    }

    public function test_legacy_evidence_is_sanitized_historical_and_independent_of_source_pruning(): void
    {
        $batch = (string) Str::uuid();
        $uuid = $this->legacy(['message' => 'password=LEAK-PASSWORD token=LEAK-TOKEN', 'trace' => [['file' => base_path('app/Caller.php'), 'line' => 12, 'args' => ['LEAK-ARG'], 'object' => 'LEAK-OBJECT']], 'user' => ['id' => 7, 'email' => 'LEAK-EMAIL'], 'context' => ['secret' => 'LEAK-CONTEXT'], 'exception_monitor_source' => ['version' => 1, 'release' => 'UNVERIFIED-RELEASE'], 'exception_monitor_origin' => ['kind' => 'job', 'job_class' => 'UNVERIFIED-ORIGIN']], batch: $batch);
        $this->source()->insert(['uuid' => (string) Str::uuid(), 'batch_id' => $batch, 'type' => 'request', 'family_hash' => null, 'content' => json_encode(['uri' => '/checkout?token=LEAK-QUERY', 'method' => 'POST', 'headers' => ['Authorization' => 'LEAK-HEADER'], 'payload' => ['password' => 'LEAK-BODY']]), 'created_at' => '2026-11-11 10:10:01', 'should_display_on_index' => true]);
        $before = $this->source()->where('uuid', $uuid)->value('content');
        $this->assertSame(1, $this->backfill()['imported']);
        $this->assertSame($before, $this->source()->where('uuid', $uuid)->value('content'));
        $o = DB::table('exception_monitor_occurrences')->first();
        $this->assertSame('historical_import', $o->mode);
        $this->assertSame('2026-11-11 10:10:01', substr($o->occurred_at, 0, 19));
        $payload = Crypt::decryptString(DB::table('exception_monitor_occurrence_evidence')->value('payload_ciphertext'));
        $this->assertStringNotContainsString('LEAK-', $payload);
        $this->assertStringNotContainsString('UNVERIFIED-', $payload);
        $data = json_decode($payload, true);
        $this->assertSame('web', $data['origin']);
        $this->assertNull($data['source']);
        $this->assertSame('unavailable', $data['coverage']['reportability']);
        $this->assertTrue((bool) DB::table('exception_monitor_issues')->value('recovered_only'));
        $this->assertDatabaseCount('exception_monitor_alert_events', 0);
        $this->source()->delete();
        $this->assertDatabaseCount('exception_monitor_occurrence_evidence', 1);
    }

    public function test_repeat_pages_and_replay_after_evidence_pruning_never_recount_or_rehydrate(): void
    {
        for ($n = 0; $n < 3; $n++) {
            $this->legacy(['message' => 'Legacy '.$n]);
        }
        $first = $this->backfill(limit: 2);
        $this->assertTrue($first['has_more']);
        $this->assertSame(2, $first['imported']);
        $this->assertSame(1, $this->backfill($first['next_after'], 2, $first['through'])['imported']);
        $this->assertSame(3, $this->backfill()['duplicates']);
        $this->assertSame(3, (int) DB::table('exception_monitor_issues')->value('lifetime_count'));
        $this->assertSame(3, (int) DB::table('exception_monitor_metric_buckets')->where('resolution', '1h')->sum('occurrence_count'));
        $this->travel(31)->days();
        app(RetentionSweep::class)->run(100);
        $this->assertDatabaseCount('exception_monitor_occurrence_evidence', 0);
        $this->assertSame(3, $this->backfill()['duplicates']);
        $this->assertDatabaseCount('exception_monitor_occurrences', 3);
        $this->assertDatabaseCount('exception_monitor_occurrence_evidence', 0);
    }

    public function test_dry_run_does_not_write_keys_health_inbox_source_or_domain_data(): void
    {
        $this->legacy();
        config(['exception-monitor.locked' => ['privacy.identify_users' => true]]);
        $snapshots = [];
        foreach (['installations', 'secrets', 'health_checks', 'capture_inbox', 'issues', 'occurrences'] as $table) {
            $snapshots[$table] = DB::table('exception_monitor_'.$table)->get()->toJson();
        }
        $source = $this->source()->get()->toJson();
        $result = $this->backfill(dry: true);
        $this->assertSame(1, $result['eligible']);
        $this->assertSame(0, $result['imported']);
        foreach ($snapshots as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table('exception_monitor_'.$table)->get()->toJson());
        }
        $this->assertSame($source, $this->source()->get()->toJson());
    }

    public function test_import_does_not_plan_alerts_or_inflate_future_live_thresholds(): void
    {
        $this->rule();
        for ($n = 0; $n < 3; $n++) {
            $this->legacy();
        }
        $this->assertSame(3, $this->backfill()['imported']);
        $this->assertDatabaseCount('exception_monitor_alert_events', 0);
        $this->assertDatabaseCount('exception_monitor_deliveries', 0);
        $this->assertDatabaseCount('exception_monitor_linked_issues', 0);
        $this->capture('First live');
        $this->process();
        $this->assertDatabaseCount('exception_monitor_deliveries', 0);
        $this->capture('Second live');
        $this->process();
        $this->assertDatabaseCount('exception_monitor_deliveries', 1);
        $this->assertSame('error.threshold_reached', DB::table('exception_monitor_alert_events')->value('event_type'));
        $this->assertFalse((bool) DB::table('exception_monitor_issues')->value('recovered_only'));
    }

    public function test_import_never_reopens_a_resolved_issue_even_if_source_time_is_after_resolution(): void
    {
        $this->rule();
        $this->capture();
        $this->process();
        $issue = DB::table('exception_monitor_issues')->first();
        $this->postJson('/exception-monitor/api/v1/issues/'.$issue->public_id.'/actions', ['client_key' => (string) Str::uuid(), 'version' => $issue->state_version, 'action' => 'resolve'])->assertOk();
        $version = DB::table('exception_monitor_issues')->value('state_version');
        $events = DB::table('exception_monitor_alert_events')->count();
        $deliveries = DB::table('exception_monitor_deliveries')->count();
        $this->travel(2)->minutes();
        $this->legacy(time: '2026-11-11 10:11:00');
        $this->assertSame(1, $this->backfill()['imported']);
        $this->assertSame('resolved', DB::table('exception_monitor_issues')->value('status'));
        $this->assertSame($version, DB::table('exception_monitor_issues')->value('state_version'));
        $this->assertSame($events, DB::table('exception_monitor_alert_events')->count());
        $this->assertSame($deliveries, DB::table('exception_monitor_deliveries')->count());
        $this->assertSame(0, DB::table('exception_monitor_metric_buckets')->sum('regression_count'));
    }

    public function test_marks_malformed_rows_and_large_payloads_as_skipped_and_makes_cursor_progress(): void
    {
        $this->legacy(['message' => ['not', 'text']]);
        $this->legacy(['trace' => [['file' => ['bad'], 'line' => 2]]]);
        $marked = $this->legacy();
        DB::table('telescope_entries_tags')->insert(['entry_uuid' => $marked, 'tag' => 'em2c|not-a-trusted-marker']);
        $this->legacy(['message' => str_repeat('x', 140000)]);
        $this->legacy(time: '2026-11-11 10:10:01');
        config(['exception-monitor.capture.max_source_bytes' => 131072]);
        $result = $this->backfill();
        $this->assertSame(5, $result['scanned']);
        $this->assertSame(3, $result['skipped_invalid']);
        $this->assertSame(1, $result['skipped_marked']);
        $this->assertSame(1, $result['imported']);
        $this->assertFalse($result['has_more']);
    }

    public function test_purge_fences_block_old_and_late_old_source_rows(): void
    {
        $this->legacy(time: '2026-11-11 10:00:00');
        $this->backfill();
        $i = app(InstallationRepository::class)->current();
        $purge = app(PurgeOperations::class);
        $run = $purge->begin($i->public_id, $i->public_id, 'backfill-test')['run']['id'];
        for ($n = 0; $n < 10; $n++) {
            $result = $purge->resume($i->public_id, $i->public_id, $run, 100, 100);
            if ($result['run']['state'] === 'completed') {
                break;
            }
        }
        $this->assertSame('completed', $result['run']['state']);
        $this->legacy(time: '2026-11-11 10:01:00');
        $this->assertSame(0, $this->backfill()['imported']);
        $this->assertDatabaseCount('exception_monitor_occurrences', 0);
    }

    public function test_command_validates_options_and_reports_a_resumable_page(): void
    {
        $this->legacy();
        $this->assertSame(2, Artisan::call('exception-monitor:backfill'));
        $this->assertSame(0, Artisan::call('exception-monitor:backfill', ['--environment' => 'testing', '--dry-run' => true]));
        $result = json_decode(trim(Artisan::output()), true);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['eligible']);
        $this->assertSame(0, Artisan::call('exception-monitor:backfill', ['--environment' => 'testing']));
        $this->assertDatabaseCount('exception_monitor_occurrences', 1);
    }

    public function test_source_timezone_and_retention_are_respected_without_guessing_release_data(): void
    {
        config(['exception-monitor.capture.source_timezone' => 'Asia/Kolkata', 'exception-monitor.capture.release' => 'CURRENT-HEAD']);
        $this->legacy(time: '2026-11-11 15:39:00');
        $this->legacy(time: '2020-01-01 00:00:00');
        $this->assertSame(2, $this->backfill()['imported']);
        $this->assertSame('2026-11-11 10:09:00', substr(DB::table('exception_monitor_occurrences')->orderBy('id')->value('occurred_at'), 0, 19));
        $this->assertDatabaseCount('exception_monitor_occurrence_evidence', 1);
        $this->assertNull(json_decode(Crypt::decryptString(DB::table('exception_monitor_occurrence_evidence')->value('payload_ciphertext')), true)['source']);
    }

    public function test_disabled_capture_and_maintenance_are_not_bypassed(): void
    {
        config(['exception-monitor.capture.enabled' => false]);
        $this->expectException(CaptureFailure::class);
        $this->backfill();
    }

    public function test_separate_telescope_source_and_monitoring_connections_are_respected(): void
    {
        $original = config('telescope.storage.database.connection');
        config(['exception-monitor.database.connection' => config('database.default'), 'database.connections.history_source' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true], 'telescope.storage.database.connection' => 'history_source']);
        $migration = require __DIR__.'/../../vendor/laravel/telescope/database/migrations/2018_08_08_100000_create_telescope_entries_table.php';
        $migration->up();
        try {
            $this->legacy();
            $this->assertSame(1, $this->backfill()['imported']);
            $this->assertDatabaseCount('exception_monitor_occurrences', 1);
            $this->assertFalse(DB::connection('history_source')->getSchemaBuilder()->hasTable('exception_monitor_occurrences'));
        } finally {
            $migration->down();
            config(['exception-monitor.database.connection' => null, 'telescope.storage.database.connection' => $original]);
        }
    }

    public function test_source_epoch_mismatch_and_maintenance_stop_imports(): void
    {
        $this->legacy();
        $i = app(InstallationRepository::class)->current();
        $i->state = 'draining';
        $i->save();
        try {
            $this->backfill();
            $this->fail('Maintenance must block imports.');
        } catch (CaptureFailure $e) {
            $this->assertSame('backfill_requires_active_installation', $e->getMessage());
        }
        $i->state = 'active';
        $i->source_signature = 'different-source';
        $i->save();
        try {
            $this->backfill();
            $this->fail('A changed source must require review.');
        } catch (CaptureFailure $e) {
            $this->assertSame('source_epoch_review_required', $e->getMessage());
        }
        $this->assertDatabaseCount('exception_monitor_occurrences', 0);
    }

    public function test_failure_output_never_exposes_grouping_callback_input_or_raw_errors(): void
    {
        $this->legacy();
        config(['exception-monitor.capture.grouping_callback' => fn () => throw new CaptureFailure('LEAK-RAW-SECRET')]);
        $this->assertSame(1, Artisan::call('exception-monitor:backfill', ['--environment' => 'testing']));
        $this->assertStringNotContainsString('LEAK-', Artisan::output());
        $this->assertDatabaseCount('exception_monitor_occurrences', 0);
        $this->assertSame(2, Artisan::call('exception-monitor:backfill', ['--environment' => 'testing', '--limit' => 1001]));
    }

    public function test_invalid_source_timezone_fails_without_advancing_or_importing(): void
    {
        $this->legacy();
        config(['exception-monitor.capture.source_timezone' => 'not-a-timezone']);
        $this->assertSame(1, Artisan::call('exception-monitor:backfill', ['--environment' => 'testing']));
        $this->assertStringContainsString('invalid_source_timezone', Artisan::output());
        $this->assertDatabaseCount('exception_monitor_occurrences', 0);
    }
}
