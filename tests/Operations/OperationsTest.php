<?php

namespace TelescopeMonitor\Tests\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Alerts\DeliverySender;
use TelescopeMonitor\Alerts\NotificationProvider;
use TelescopeMonitor\Alerts\Outcome;
use TelescopeMonitor\Capture\CaptureObservation;
use TelescopeMonitor\Capture\CaptureOperations;
use TelescopeMonitor\Capture\CaptureProbes;
use TelescopeMonitor\Capture\CaptureProcessor;
use TelescopeMonitor\Capture\EvidenceReader;
use TelescopeMonitor\Foundation\ConfigurationConflict;
use TelescopeMonitor\Integrations\IntegrationManagement;
use TelescopeMonitor\Models\Connection;
use TelescopeMonitor\Models\HealthCheck;
use TelescopeMonitor\Models\LinkedIssue;
use TelescopeMonitor\Operations\KeyRewrap;
use TelescopeMonitor\Operations\MaintenanceFailure;
use TelescopeMonitor\Operations\OperationalStatus;
use TelescopeMonitor\Operations\OperationJournal;
use TelescopeMonitor\Operations\PurgeOperations;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Operations\RetentionSweep;
use TelescopeMonitor\Repositories\Contracts\CollaborationRepository;
use TelescopeMonitor\Repositories\Contracts\DeliveryLedger;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Services\HealthService;
use TelescopeMonitor\Services\SettingsService;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class MaintenanceTransport implements NotificationProvider
{
    public array $calls = [];

    public $during = null;

    public function send(array $e, array $target): Outcome
    {
        $this->calls[] = ['transaction' => DB::transactionLevel(), 'recording' => Telescope::isRecording()];
        if ($this->during) {
            ($this->during)();
        }

        return Outcome::accepted();
    }
}
final class OperationsTest extends TestCase
{
    use CapturesExceptions;

    private MaintenanceTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorize();
        config(['telescope-monitor.alerts.allowed_environments' => ['testing']]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11T10:10:01Z'));
        Telescope::startRecording(false);
        $this->mail = new MaintenanceTransport;
        $this->app->instance(NotificationProvider::class, $this->mail);
    }

    private function installation()
    {
        return app(InstallationRepository::class)->current();
    }

    private function captureIssue(?CarbonImmutable $at = null): object
    {
        $this->capture('Account failed token=PRIVATE', at: $at);
        $this->process();

        return DB::table('telescope_monitor_issues')->latest('id')->first();
    }

    private function destination(): string
    {
        return $this->postJson('/telescope-monitor/api/v1/alerting/email', ['client_key' => (string) Str::uuid(), 'name' => 'On call', 'email' => 'oncall@example.test', 'enabled' => true])->assertOk()->json('telescope_monitor_private.id');
    }

    private function rule(string $id): void
    {
        $this->postJson('/telescope-monitor/api/v1/alerting/rules', [
            'client_key' => (string) Str::uuid(),
            'name' => 'New errors',
            'enabled' => true,
            'events' => ['error.created'],
            'conditions' => ['environments' => ['testing']],
            'connections' => [$id],
            'threshold_count' => null,
            'window_seconds' => null,
            'cooldown_seconds' => 0,
        ])->assertOk();
    }

    private function begin(): array
    {
        $i = $this->installation();

        return app(PurgeOperations::class)->begin($i->public_id, $i->public_id);
    }

    private function resume(string $run, int $limit = 1, int $batches = 1): array
    {
        $i = $this->installation();

        return app(PurgeOperations::class)->resume($i->public_id, $i->public_id, $run, $limit, $batches);
    }

    private function complete(string $run, int $limit = 2): array
    {
        for ($n = 0; $n < 100; $n++) {
            $status = $this->resume($run, $limit, 1);
            if ($status['run']['state'] === 'completed') {
                return $status;
            }
        }$this->fail('Purge did not complete within its bounded fixture.');
    }

    private function collaborations(object $issue): array
    {
        $collab = app(CollaborationRepository::class);
        $note = $collab->command('note.create', $issue->public_id, (string) Str::uuid(), ['body' => 'Private operational note']);
        $view = $collab->command('view.create', null, (string) Str::uuid(), [
            'name' => 'My saved triage',
            'visibility' => 'personal',
            'filters' => ['status' => 'unresolved'],
            'sort' => 'last_seen',
            'range' => '7d',
        ]);
        $prefs = $collab->preferences();
        $collab->command('preferences', null, (string) Str::uuid(), [
            'version' => $prefs['version'],
            'shortcuts' => [['ref' => $view['id'], 'favorite' => true, 'position' => 0]],
            'default_view_ref' => $view['id'],
        ]);

        return compact('note', 'view');
    }

    public function test_fresh_installation_metadata_tracks_the_complete_schema(): void
    {
        DB::table('telescope_monitor_installations')->delete();
        $i = $this->installation();
        $this->assertSame(0, (int) $i->schema_version);
        $this->assertSame('ready', app(\TelescopeMonitor\Repositories\Contracts\InstallationRepository::class)->storageState());
        $this->assertSame(6, (int) $i->fresh()->schema_version);
        $this->assertTrue(app(OperationalStatus::class)->schema()['ready']);
    }

    public function test_purge_requires_exact_installation_confirmation_and_preview_is_read_only(): void
    {
        $this->captureIssue();
        $i = $this->installation();
        $before = DB::table('telescope_monitor_issues')->count();
        $this->artisan('telescope-monitor:purge', ['installation' => $i->public_id, '--preview' => true])->assertSuccessful();
        $this->assertDatabaseCount('telescope_monitor_purge_runs', 0);
        $this->artisan('telescope-monitor:purge', ['installation' => $i->public_id])->assertFailed();
        $this->assertSame($before, DB::table('telescope_monitor_issues')->count());
        $this->assertSame('active', $this->installation()->state);
    }

    public function test_draining_preserves_live_claims_and_allows_their_current_generation_completion(): void
    {
        $this->rule($this->destination());
        $this->captureIssue();
        $d = DB::table('telescope_monitor_deliveries')->first();
        $claim = app(DeliveryLedger::class)->claim($d->id, 1, true);
        $run = $this->begin()['run']['id'];
        $this->assertSame('draining', $this->resume($run)['run']['state']);
        $this->assertNull(app(DeliveryLedger::class)->claim($d->id, 1, true));
        $this->assertSame('processing', DB::table('telescope_monitor_deliveries')->value('status'));
        $this->assertTrue(app(DeliveryLedger::class)->finish($d->id, $claim['token'], Outcome::accepted()));
        $this->assertSame('purging', $this->resume($run)['run']['state']);
        $this->complete($run);
    }

    public function test_expired_external_claim_is_fenced_and_stale_completion_cannot_record_acceptance(): void
    {
        $this->rule($this->destination());
        $this->captureIssue();
        $d = DB::table('telescope_monitor_deliveries')->first();
        $claim = app(DeliveryLedger::class)->claim($d->id, 1, true);
        $run = $this->begin()['run']['id'];
        $this->travel(121)->seconds();
        $this->resume($run);
        $this->assertSame(2, $this->installation()->generation);
        $this->assertFalse(app(DeliveryLedger::class)->finish($d->id, $claim['token'], Outcome::accepted()));
        $this->assertNull(DB::table('telescope_monitor_deliveries')->value('sent_at'));
        $this->complete($run);
        $this->assertFalse(app(DeliverySender::class)->send($d->id, 1));
        $this->assertCount(0, $this->mail->calls);
    }

    public function test_provider_io_is_not_under_a_purge_transaction_and_draining_waits_for_it(): void
    {
        $this->rule($this->destination());
        $this->captureIssue();
        $run = null;
        $this->mail->during = function () use (&$run) {
            $run = $this->begin()['run']['id'];
            $this->assertSame('draining', $this->resume($run)['run']['state']);
        };
        $d = DB::table('telescope_monitor_deliveries')->first();
        $this->assertTrue(app(DeliverySender::class)->send($d->id, 1));
        $this->assertSame([['transaction' => 0, 'recording' => false]], $this->mail->calls);
        $this->assertSame('sent', DB::table('telescope_monitor_deliveries')->value('status'));
        $this->complete($run);
    }

    public function test_every_purge_step_resumes_and_preserves_configuration_views_credentials_and_receipt_fences(): void
    {
        $destination = $this->destination();
        $this->rule($destination);
        $issue = $this->captureIssue();
        $collab = $this->collaborations($issue);
        $settings = app(SettingsService::class)->group('general');
        app(SettingsService::class)->save('general', ['display_name' => 'Preserved application'], $settings['version']);
        $secrets = DB::table('telescope_monitor_secrets')->pluck('ciphertext', 'id')->all();
        $identity = $this->installation()->public_id;
        LinkedIssue::query()->create([
            'public_id' => (string) Str::uuid(),
            'installation_id' => $issue->installation_id,
            'issue_id' => $issue->id,
            'connection_id' => DB::table('telescope_monitor_connections')->value('id'),
            'repository_fingerprint' => str_repeat('a', 64),
            'repository_owner' => 'acme',
            'repository_name' => 'app',
            'state' => 'linked',
            'operation_public_id' => (string) Str::uuid(),
            'remote_number' => 42,
            'remote_url' => 'https://github.com/acme/app/issues/42',
        ]);
        $run = $this->begin()['run']['id'];
        $seen = [];
        for ($n = 0; $n < 100; $n++) {
            $s = $this->resume($run, 1, 1);
            $seen[] = $s['run']['step'];
            if ($s['run']['state'] === 'completed') {
                break;
            }
        }
        $this->assertSame('completed', $s['run']['state']);
        foreach (PurgeOperations::TABLES as $table) {
            $this->assertContains($table, $seen);
        }
        foreach (PurgeOperations::TABLES as $table) {
            $this->assertSame(0, DB::table('telescope_monitor_'.$table)->count(), $table);
        }
        $this->assertSame($identity, $this->installation()->public_id);
        $this->assertSame('Preserved application', app(SettingsService::class)->group('general')['values']['display_name']);
        $this->assertSame($secrets, DB::table('telescope_monitor_secrets')->pluck('ciphertext', 'id')->all());
        $this->assertDatabaseCount('telescope_monitor_connections', 1);
        $this->assertDatabaseCount('telescope_monitor_alert_rules', 1);
        $this->assertDatabaseCount('telescope_monitor_saved_views', 1);
        $this->assertDatabaseCount('telescope_monitor_view_preferences', 1);
        $this->assertGreaterThan(0, DB::table('telescope_monitor_action_receipts')->count());
        $this->assertSame($collab['view']['id'], app(CollaborationRepository::class)->preferences()['default_view_ref']);
    }

    public function test_interrupted_delete_rolls_back_its_step_and_counts_then_can_resume(): void
    {
        $this->captureIssue();
        $run = $this->begin()['run']['id'];
        for ($n = 0; $n < 30; $n++) {
            $s = $this->resume($run, 1);
            if ($s['run']['step'] === 'occurrences') {
                break;
            }
        }$before = DB::table('telescope_monitor_occurrences')->count();
        $armed = true;
        DB::listen(function ($q) use (&$armed) {
            if ($armed && preg_match('/^delete from [`"]telescope_monitor_occurrences[`"]/', $q->sql)) {
                $armed = false;
                throw new \RuntimeException('private database diagnostic');
            }
        });
        try {
            $this->resume($run, 1);
            $this->fail('Expected injected failure');
        } catch (MaintenanceFailure $e) {
            $this->assertSame('purge_step_failed', $e->getMessage());
        }$this->assertSame($before, DB::table('telescope_monitor_occurrences')->count());
        $this->assertSame('occurrences', app(PurgeOperations::class)->status($run)['run']['step']);
        $this->assertStringNotContainsString('private database diagnostic', json_encode(DB::table('telescope_monitor_purge_runs')->get()));
        $this->assertSame('completed', $this->complete($run)['run']['state']);
    }

    public function test_post_cutoff_source_events_can_be_recovered_without_old_data_or_alerts(): void
    {
        $this->rule($this->destination());
        $old = $this->captureIssue();
        $oldInbox = DB::table('telescope_monitor_capture_inbox')->value('id');
        $run = $this->begin()['run']['id'];
        $this->resume($run);
        $this->travel(2)->seconds();
        $uuid = $this->capture('New post-cutoff exception', class: \LogicException::class, import: false);
        $this->assertSame(1, DB::table('telescope_monitor_issues')->count());
        $this->complete($run);
        $this->assertFalse(app(CaptureProcessor::class)->process($oldInbox, 1));
        app(CaptureOperations::class)->reconcile(100);
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_issues', 1);
        $this->assertSame($uuid, DB::table('telescope_monitor_occurrences')->value('source_uuid'));
        $this->assertDatabaseCount('telescope_monitor_deliveries', 0);
        $this->assertTrue((bool) DB::table('telescope_monitor_issues')->value('recovered_only'));
        $this->assertNotSame($old->public_id, DB::table('telescope_monitor_issues')->value('public_id'));
    }

    public function test_abort_is_only_available_before_the_generation_fence(): void
    {
        $this->captureIssue();
        $i = $this->installation();
        $a = $this->begin()['run']['id'];
        $aborted = app(PurgeOperations::class)->abort($i->public_id, $i->public_id, $a);
        $this->assertSame('aborted', $aborted['run']['state']);
        $this->assertSame(1, $this->installation()->generation);
        $b = $this->begin()['run']['id'];
        $this->resume($b);
        $this->assertSame(3, $this->installation()->generation);
        $this->expectException(MaintenanceFailure::class);
        app(PurgeOperations::class)->abort($i->public_id, $i->public_id, $b);
    }

    public function test_maintenance_blocks_domain_reads_and_mutations_but_exposes_safe_operations(): void
    {
        $this->captureIssue();
        $this->begin();
        $this->getJson('/telescope-monitor/api/v1/issues')->assertStatus(409);
        $this->postJson('/telescope-monitor/api/v1/alerting/tests', ['client_key' => (string) Str::uuid(), 'connections' => [(string) Str::uuid()]])->assertStatus(409);
        $this->patchJson('/telescope-monitor/api/v1/settings/general', ['version' => 0, 'values' => ['display_name' => 'Wrong']])->assertStatus(409);
        $this->getJson('/telescope-monitor/api/v1/operations')->assertOk()->assertJsonPath('data.state', 'draining');
        $this->getJson('/telescope-monitor/api/v1/bootstrap')->assertOk()->assertJsonPath('data.installation.state', 'draining');
    }

    public function test_source_reset_requires_explicit_rebaseline_and_preserves_purge_time_protection(): void
    {
        $this->captureIssue();
        $run = $this->begin()['run']['id'];
        $this->complete($run);
        $old = $this->installation();
        $cutoff = $old->purged_through_at->toISOString();
        DB::connection(config('telescope.storage.database.connection'))->table('telescope_entries')->delete();
        $this->assertSame('failed', app(OperationalStatus::class)->source()['state']);
        $this->artisan('telescope-monitor:source-baseline', ['installation' => $old->public_id])->assertFailed();
        $this->artisan('telescope-monitor:source-baseline', ['installation' => $old->public_id, '--confirm' => $old->public_id])->assertSuccessful();
        $current = $this->installation();
        $this->assertNotSame($old->source_epoch, $current->source_epoch);
        $this->assertGreaterThanOrEqual($cutoff, $current->purged_through_at->toISOString());
        $this->assertSame(0, (int) $current->source_sequence_floor);
        $this->travel(2)->seconds();
        $this->captureIssue();
        $this->assertDatabaseCount('telescope_monitor_issues', 1);
    }

    public function test_retention_reduction_expires_reads_immediately_and_increase_never_rehydrates(): void
    {
        $issue = $this->captureIssue(CarbonImmutable::now('UTC')->subDays(10));
        $occ = DB::table('telescope_monitor_occurrences')->value('public_id');
        $this->assertSame('available', app(EvidenceReader::class)->read($occ)['evidence_state']);
        $settings = app(SettingsService::class)->group('retention');
        app(SettingsService::class)->save('retention', ['occurrence_days' => 1], $settings['version']);
        $this->assertSame('expired', app(EvidenceReader::class)->read($occ)['evidence_state']);
        $this->travel(3)->days();
        app(SettingsService::class)->save('retention', ['occurrence_days' => 30], 1);
        $this->assertSame('expired', app(EvidenceReader::class)->read($occ)['evidence_state']);
        $this->assertDatabaseCount('telescope_monitor_occurrence_evidence', 1);
        app(RetentionSweep::class)->run(1);
        $this->assertDatabaseCount('telescope_monitor_occurrence_evidence', 0);
        $this->assertDatabaseCount('telescope_monitor_occurrences', 1);
        $this->assertSame(1, DB::table('telescope_monitor_issues')->value('lifetime_count'));
    }

    public function test_bounded_retention_preserves_counts_notes_views_links_and_replay_identity(): void
    {
        $issue = $this->captureIssue();
        $this->collaborations($issue);
        $this->capture('second occurrence');
        $this->process();
        $this->capture('third occurrence');
        $this->process();
        LinkedIssue::query()->create([
            'public_id' => (string) Str::uuid(),
            'installation_id' => $issue->installation_id,
            'issue_id' => $issue->id,
            'connection_id' => Connection::query()->create([
                'public_id' => (string) Str::uuid(),
                'installation_id' => $issue->installation_id,
                'provider' => 'github',
                'display_name' => 'Repository',
                'target_fingerprint' => str_repeat('b', 64),
                'target_public' => ['owner' => 'acme', 'repo' => 'app'],
                'enabled' => false,
                'options' => [],
            ])->id,
            'repository_fingerprint' => str_repeat('b', 64),
            'repository_owner' => 'acme',
            'repository_name' => 'app',
            'state' => 'linked',
            'operation_public_id' => (string) Str::uuid(),
            'remote_number' => 42,
        ]);
        $uuid = DB::table('telescope_monitor_occurrences')->value('source_uuid');
        $this->travel(100)->days();
        $stats = app(RetentionSweep::class)->run(1);
        foreach ($stats as $n) {
            $this->assertLessThanOrEqual(1, $n);
        }$this->assertDatabaseCount('telescope_monitor_notes', 1);
        $this->assertDatabaseCount('telescope_monitor_saved_views', 1);
        $this->assertDatabaseCount('telescope_monitor_linked_issues', 1);
        $this->assertDatabaseCount('telescope_monitor_occurrences', 3);
        $this->assertDatabaseCount('telescope_monitor_occurrence_evidence', 2);
        $this->assertSame(0, DB::table('telescope_monitor_capture_inbox')->where('state', 'failed')->count());
        app(CaptureOperations::class)->reconcile(100, 3000);
        $this->process();
        $this->assertSame(3, DB::table('telescope_monitor_issues')->value('lifetime_count'));
        $this->assertSame($uuid, DB::table('telescope_monitor_occurrences')->value('source_uuid'));
        $detail = $this->getJson('/telescope-monitor/api/v1/issues/'.$issue->public_id)->assertOk()->json('telescope_monitor_private');
        $this->assertSame('expired', $detail['selected']['evidence_state']);
        $this->assertSame('3', $detail['occurrences']['total']);
    }

    public function test_retention_preview_is_bounded_non_destructive_and_has_no_invented_schedule(): void
    {
        $this->captureIssue(CarbonImmutable::now('UTC')->subDays(5));
        $version = app(SettingsService::class)->group('retention')['version'];
        $p = $this->postJson('/telescope-monitor/api/v1/retention/preview', ['values' => ['occurrence_days' => 1]])->assertOk()->json('data');
        $this->assertSame(1, $p['counts']['occurrence_days']['eligible_count']);
        $this->assertStringContainsString('no future run is guaranteed', $p['next_prune']);
        $this->assertSame($version, app(SettingsService::class)->group('retention')['version']);
        $this->assertDatabaseCount('telescope_monitor_occurrence_evidence', 1);
    }

    public function test_deployment_limits_and_locks_are_enforced_at_the_service_and_api(): void
    {
        config(['telescope-monitor.retention_limits.occurrence_days' => ['max' => 7]]);
        $this->patchJson('/telescope-monitor/api/v1/settings/retention', ['version' => 0, 'values' => ['occurrence_days' => 8]])->assertUnprocessable();
        $this->assertSame(7, app(SettingsService::class)->group('retention')['values']['occurrence_days']);
        config(['telescope-monitor.locked' => ['retention.occurrence_days' => 3]]);
        $this->patchJson('/telescope-monitor/api/v1/settings/retention', ['version' => 0, 'values' => ['occurrence_days' => 2]])->assertUnprocessable();
        $this->assertSame(3, app(RetentionPolicy::class)->effective()['occurrence_days']);
    }

    public function test_long_lived_policy_reads_use_current_saved_revision_without_cache_invalidation(): void
    {
        $reader = app(RetentionPolicy::class);
        $this->assertSame(30, $reader->effective()['occurrence_days']);
        app(SettingsService::class)->save('retention', ['occurrence_days' => 2], 0);
        $this->assertSame(2, $reader->effective()['occurrence_days']);
        $this->expectException(ConfigurationConflict::class);
        app(SettingsService::class)->save('retention', ['occurrence_days' => 3], 0);
    }

    public function test_live_capture_buffer_payload_is_not_removed_by_retention(): void
    {
        $this->capture();
        DB::table('telescope_monitor_capture_inbox')->update([
            'state' => 'processing',
            'lease_until' => CarbonImmutable::now('UTC')->addMinutes(2)->format('Y-m-d H:i:s.u'),
            'received_at' => CarbonImmutable::now('UTC')->subDays(40)->format('Y-m-d H:i:s.u'),
        ]);
        app(RetentionSweep::class)->run(1);
        $this->assertNotNull(DB::table('telescope_monitor_capture_inbox')->value('payload_ciphertext'));
        $this->assertSame('processing', DB::table('telescope_monitor_capture_inbox')->value('state'));
    }

    public function test_maintenance_history_separates_started_success_failed_and_safe_error_data(): void
    {
        $journal = app(OperationJournal::class);
        $journal->run('retention', fn () => ['evidence' => 1]);
        $first = HealthCheck::query()->where('check_key', 'retention')->first();
        $this->travel(5)->minutes();
        try {
            $journal->run('retention', function () {
                throw new \RuntimeException('token=PRIVATE SECRET');
            });
            $this->fail('Expected failure');
        } catch (MaintenanceFailure) {
        }$last = $first->fresh();
        $this->assertTrue($last->last_success_at->equalTo($first->last_success_at));
        $this->assertGreaterThan($last->last_success_at, $last->last_started_at);
        $this->assertNotNull($last->last_failure_at);
        $this->assertCount(2, $last->sanitized_details['history']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($last));
    }

    public function test_stale_start_and_quiet_worker_do_not_look_currently_running(): void
    {
        app(CaptureObservation::class)->start('capture_worker', ['generation' => 1]);
        $this->travel(10)->minutes();
        $rows = collect(app(OperationalStatus::class)->observations())->keyBy('key');
        $this->assertSame('warning', $rows['capture_worker']['state']);
        $this->assertFalse($rows['capture_worker']['fresh']);
        $this->assertNull($rows['capture_worker']['last_success_at']);
        $this->artisan('telescope-monitor:heartbeat')->assertSuccessful();
        $rows = collect(app(OperationalStatus::class)->observations())->keyBy('key');
        $this->assertTrue($rows['heartbeat']['fresh']);
        $this->assertStringContainsString('does not prove every', $rows['heartbeat']['detail']);
    }

    public function test_key_loss_is_visible_without_deleting_credentials_or_replacing_capture_identity(): void
    {
        $this->destination();
        $before = DB::table('telescope_monitor_secrets')->pluck('ciphertext', 'id')->all();
        Crypt::swap(new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
        $this->getJson('/telescope-monitor/api/v1/operations')->assertOk()->assertJsonPath('data.credentials.state', 'failed');
        $this->assertSame($before, DB::table('telescope_monitor_secrets')->pluck('ciphertext', 'id')->all());
    }

    public function test_setup_requires_current_capture_reviews_and_explicit_notification_choice(): void
    {
        $this->postJson('/telescope-monitor/api/v1/setup/finish')->assertStatus(409);
        $i = $this->installation();
        foreach (['application', 'privacy', 'operations'] as $ack) {
            $this->patchJson('/telescope-monitor/api/v1/setup', ['step' => 7, 'acknowledge' => $ack, 'config_revision' => $this->installation()->config_revision])->assertOk();
        }$this->patchJson('/telescope-monitor/api/v1/setup', ['step' => 7, 'notification_choice' => 'skip'])->assertOk();
        $probe = app(CaptureProbes::class)->request((string) Str::uuid());
        app(CaptureProbes::class)->execute($probe['id'], 1);
        $this->process();
        $state = $this->getJson('/telescope-monitor/api/v1/setup/status')->assertOk()->json('data');
        $this->assertTrue($state['requirements']['capture']);
        $this->postJson('/telescope-monitor/api/v1/setup/finish')->assertOk()->assertJsonPath('data.complete', true);
        $run = $this->begin()['run']['id'];
        $this->complete($run);
        $this->assertFalse(app(OperationalStatus::class)->setup()['complete']);
        $this->assertFalse(app(OperationalStatus::class)->setup()['requirements']['capture']);
    }

    public function test_viewers_and_operators_cannot_change_operational_administration(): void
    {
        $this->authorize(manage: false);
        $this->getJson('/telescope-monitor/api/v1/operations')->assertForbidden();
        $this->postJson('/telescope-monitor/api/v1/retention/preview', ['values' => ['occurrence_days' => 1]])->assertForbidden();
        $this->postJson('/telescope-monitor/api/v1/setup/finish')->assertForbidden();
    }

    public function test_large_retention_preview_caps_counts_and_sweeps_only_a_bounded_page(): void
    {
        $issue = $this->captureIssue(CarbonImmutable::now('UTC')->subDays(5));
        $o = (array) DB::table('telescope_monitor_occurrences')->first();
        $e = (array) DB::table('telescope_monitor_occurrence_evidence')->first();
        unset($o['id'],$e['id']);
        DB::transaction(function () use ($o, $e) {
            for ($n = 0; $n < 151; $n++) {
                $copy = $o;
                $copy['public_id'] = (string) Str::uuid();
                $copy['source_uuid'] = (string) Str::uuid();
                $id = DB::table('telescope_monitor_occurrences')->insertGetId($copy);
                DB::table('telescope_monitor_occurrence_evidence')->insert(array_replace($e, ['occurrence_id' => $id]));
            }
        });
        DB::table('telescope_monitor_issues')->where('id', $issue->id)->update(['lifetime_count' => 152]);
        config(['telescope-monitor.operations.preview_limit' => 100]);
        $preview = app(RetentionSweep::class)->preview(['occurrence_days' => 1]);
        $this->assertSame(100, $preview['counts']['occurrence_days']['eligible_count']);
        $this->assertTrue($preview['counts']['occurrence_days']['at_least']);
        app(SettingsService::class)->save('retention', ['occurrence_days' => 1], 0);
        $stats = app(RetentionSweep::class)->run(10);
        $this->assertSame(10, $stats['evidence']);
        $this->assertDatabaseCount('telescope_monitor_occurrence_evidence', 142);
        $this->assertDatabaseCount('telescope_monitor_occurrences', 152);
        $this->assertSame(152, DB::table('telescope_monitor_issues')->value('lifetime_count'));
    }

    public function test_a_delayed_capture_job_cannot_ingest_an_expired_buffer(): void
    {
        $this->capture();
        DB::table('telescope_monitor_capture_inbox')->update(['received_at' => CarbonImmutable::now('UTC')->subDays(40)->format('Y-m-d H:i:s.u')]);
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_issues', 0);
        $this->assertSame('buffer_retention_expired', DB::table('telescope_monitor_capture_inbox')->value('last_error_code'));
        $this->assertNull(DB::table('telescope_monitor_capture_inbox')->value('payload_ciphertext'));
    }

    public function test_aborting_drain_allows_recovery_of_its_retained_source_markers(): void
    {
        $this->captureIssue();
        $run = $this->begin()['run']['id'];
        $this->capture('During drain', import: false);
        $this->assertSame(1, DB::table('telescope_monitor_occurrences')->count());
        $i = $this->installation();
        app(PurgeOperations::class)->abort($i->public_id, $i->public_id, $run);
        app(CaptureOperations::class)->reconcile(100);
        $this->process();
        $this->assertSame(2, DB::table('telescope_monitor_occurrences')->count());
        $this->assertDatabaseCount('telescope_monitor_deliveries', 0);
    }

    public function test_host_key_rewrap_preserves_plaintext_digest_revisions_and_unreadable_values(): void
    {
        $this->rule($this->destination());
        $issue = $this->captureIssue();
        $this->collaborations($issue);
        $d = DB::table('telescope_monitor_deliveries')->first();
        $claim = app(DeliveryLedger::class)->claim($d->id, 1, true);
        app(DeliveryLedger::class)->finish($d->id, $claim['token'], Outcome::permanent());
        $before = [];
        foreach (KeyRewrap::TARGETS as $target => [$suffix,$column]) {
            $before[$target] = DB::table('telescope_monitor_'.$suffix)->whereNotNull($column)->get()->mapWithKeys(fn ($row) => [$row->id => Crypt::decryptString($row->{$column})])->all();
        }
        $keys = DB::table('telescope_monitor_secrets')->pluck('key_id', 'id')->all();
        $digest = DB::table('telescope_monitor_deliveries')->value('payload_digest');
        $new = new Encrypter(str_repeat('n', 32), 'AES-256-CBC');
        if (version_compare($this->app->version(), '11.0.0', '>=')) {
            $new->previousKeys([str_repeat('t', 32)]);
        } else {
            $new = new \TelescopeMonitor\Tests\Fixtures\RotatingEncrypter(str_repeat('n', 32), 'AES-256-CBC', new Encrypter(str_repeat('t', 32), 'AES-256-CBC'));
        }
        Crypt::swap($new);
        $i = $this->installation();
        foreach (KeyRewrap::TARGETS as $target => $_) {
            $after = 0;
            for ($n = 0; $n < 20; $n++) {
                $result = app(KeyRewrap::class)->run($i->public_id, $i->public_id, $target, 1, $after);
                $this->assertSame(0, $result['failed']);
                $after = $result['next_cursor'];
                if (! $result['has_more']) {
                    break;
                }
            }
        }
        Crypt::swap(new Encrypter(str_repeat('n', 32), 'AES-256-CBC'));
        foreach (KeyRewrap::TARGETS as $target => [$suffix,$column]) {
            $after = DB::table('telescope_monitor_'.$suffix)->whereNotNull($column)->get()->mapWithKeys(fn ($row) => [$row->id => Crypt::decryptString($row->{$column})])->all();
            $this->assertSame($before[$target], $after);
        }
        $this->assertSame($keys, DB::table('telescope_monitor_secrets')->pluck('key_id', 'id')->all());
        $this->assertSame($digest, DB::table('telescope_monitor_deliveries')->value('payload_digest'));
        $this->assertSame(1, DB::table('telescope_monitor_notes')->value('row_version'));
        $this->assertSame('available', app(EvidenceReader::class)->read(DB::table('telescope_monitor_occurrences')->value('public_id'))['evidence_state']);
    }

    public function test_failed_key_rewrap_never_blanks_the_original_ciphertext(): void
    {
        $this->destination();
        $row = DB::table('telescope_monitor_secrets')->first();
        DB::table('telescope_monitor_secrets')->where('id', $row->id)->update(['ciphertext' => 'intentionally unreadable']);
        $i = $this->installation();
        $this->artisan('telescope-monitor:rewrap', ['installation' => $i->public_id, 'target' => 'secrets', '--confirm' => $i->public_id, '--limit' => 100])->assertFailed();
        $this->assertSame('intentionally unreadable', DB::table('telescope_monitor_secrets')->where('id', $row->id)->value('ciphertext'));
        $this->assertSame('warning', DB::table('telescope_monitor_health_checks')->where('check_key', 'key_rewrap')->value('state'));
    }

    public function test_setup_delivery_verification_uses_the_current_active_signing_key(): void
    {
        $service = app(IntegrationManagement::class);
        $c = $service->command('connection.create', null, (string) Str::uuid(), ['provider' => 'webhook', 'name' => 'Webhook', 'enabled' => true, 'endpoint' => 'https://hooks.example.test/events']);
        $stage = $service->command('key.stage', $c['id'], (string) Str::uuid(), ['version' => 1]);
        $test = $service->command('test', $c['id'], (string) Str::uuid(), ['signing_key_id' => $stage['once']['key_id']]);
        $d = DB::table('telescope_monitor_deliveries')->first();
        app(DeliverySender::class)->send($d->id);
        $this->assertFalse(app(OperationalStatus::class)->setup()['delivery_verified']);
        $service->command('key.activate', $c['id'], (string) Str::uuid(), ['version' => 2, 'key_id' => $stage['once']['key_id']]);
        $this->assertTrue(app(OperationalStatus::class)->setup()['delivery_verified']);
    }

    public function test_new_activity_uses_the_effective_history_retention(): void
    {
        app(SettingsService::class)->save('retention', ['activity_days' => 120], 0);
        $issue = $this->captureIssue();
        $expected = CarbonImmutable::now('UTC')->addDays(120)->format('Y-m-d H:i:s.u');
        $this->assertSame($expected, DB::table('telescope_monitor_activity')->where('issue_id', $issue->id)->value('expires_at'));
        app(CollaborationRepository::class)->command('note.create', $issue->public_id, (string) Str::uuid(), ['body' => 'Retained note']);
        $this->assertSame($expected, DB::table('telescope_monitor_activity')->where('event_type', 'note.added')->value('expires_at'));
    }

    public function test_incompatible_deployment_limits_fail_visibly_instead_of_being_weakened(): void
    {
        config(['telescope-monitor.retention_limits.aggregate_days' => ['max' => 7]]);
        $health = collect(app(HealthService::class)->inspect())->keyBy('key');
        $this->assertSame('failed', $health['retention_limits']['state']);
        $this->expectException(MaintenanceFailure::class);
        app(RetentionPolicy::class)->effective();
    }
}
