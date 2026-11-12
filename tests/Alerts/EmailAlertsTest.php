<?php

namespace TelescopeMonitor\Tests\Alerts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Alerts\DeliverySender;
use TelescopeMonitor\Alerts\HostEmailProvider;
use TelescopeMonitor\Alerts\NotificationProvider;
use TelescopeMonitor\Alerts\Outcome;
use TelescopeMonitor\Repositories\Contracts\AlertEventRepository;
use TelescopeMonitor\Repositories\Contracts\DeliveryLedger;
use TelescopeMonitor\Services\RedactionService;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class FakeMailProvider implements NotificationProvider
{
    public array $calls = [];

    public array $outcomes = [];

    public $during = null;

    public function send(array $envelope, array $target): Outcome
    {
        $this->calls[] = [
            'envelope' => $envelope,
            'target' => $target,
            'transaction' => DB::transactionLevel(),
            'recording' => Telescope::isRecording(),
        ];
        if ($this->during) {
            ($this->during)();
        }

        return array_shift($this->outcomes) ?? Outcome::accepted();
    }
}
final class EmailAlertsTest extends TestCase
{
    use CapturesExceptions;

    private FakeMailProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorize();
        config(['exception-monitor.alerts.allowed_environments' => ['testing']]);
        $this->provider = new FakeMailProvider;
        $this->app->instance(NotificationProvider::class, $this->provider);
        $this->travelTo(CarbonImmutable::parse('2026-11-11T10:10:01Z'));
    }

    private function recipient(string $email = 'oncall@example.test'): string
    {
        return $this->postJson('/exception-monitor/api/v1/alerting/email', ['client_key' => (string) Str::uuid(), 'name' => 'On call', 'email' => $email, 'enabled' => true])->assertOk()->json('exception_monitor_private.id');
    }

    private function definition(array $connections, array $extra = []): array
    {
        $base = [
            'name' => 'Testing errors',
            'enabled' => true,
            'events' => ['error.created', 'error.regressed'],
            'conditions' => ['environments' => ['testing'], 'class' => null, 'origin' => null, 'tag' => null],
            'connections' => $connections,
            'threshold_count' => null,
            'window_seconds' => null,
            'cooldown_seconds' => 0,
        ];
        if (isset($extra['conditions'])) {
            $extra['conditions'] = array_merge($base['conditions'], $extra['conditions']);
        }

        return array_replace($base, $extra);
    }

    private function rule(array $connections, array $extra = []): string
    {
        return $this->postJson('/exception-monitor/api/v1/alerting/rules', ['client_key' => (string) Str::uuid()] + $this->definition($connections, $extra))->assertOk()->json('exception_monitor_private.id');
    }

    private function sendAll(): int
    {
        $count = 0;
        foreach (app(DeliveryLedger::class)->due(100) as $row) {
            if (app(DeliverySender::class)->send($row['id'], $row['generation'])) {
                $count++;
            }
        }

        return $count;
    }

    private function delivery(): object
    {
        return DB::table('exception_monitor_deliveries')->orderByDesc('id')->first();
    }

    private function action(string $kind): void
    {
        $issue = DB::table('exception_monitor_issues')->first();
        $this->postJson('/exception-monitor/api/v1/issues/'.$issue->public_id.'/actions', ['client_key' => (string) Str::uuid(), 'version' => (int) $issue->state_version, 'action' => $kind])->assertOk();
    }

    public function test_overlapping_rules_and_canonical_recipient_create_one_durable_delivery_without_inline_send(): void
    {
        $a = $this->recipient('ONCALL@example.test');
        $this->assertSame($a, $this->recipient('oncall@example.test'));
        $this->rule([$a]);
        $this->rule([$a], ['name' => 'Second support']);
        $this->capture('token=private-value');
        $this->process();
        $this->assertDatabaseCount('exception_monitor_connections', 1);
        $this->assertDatabaseCount('exception_monitor_deliveries', 1);
        $this->assertDatabaseCount('exception_monitor_delivery_rules', 2);
        $this->assertCount(0, $this->provider->calls);
        $this->assertSame('pending', $this->delivery()->status);
        $this->assertSame(1, $this->sendAll());
        $this->assertSame('sent', $this->delivery()->status);
        $this->assertSame(1, (int) $this->delivery()->attempt_count_total);
        $this->assertSame(0, $this->provider->calls[0]['transaction']);
        $this->assertFalse($this->provider->calls[0]['recording']);
        $this->assertSame('oncall@example.test', $this->provider->calls[0]['target']['email']);
        $this->assertStringNotContainsString('private-value', json_encode($this->provider->calls));
        $this->assertStringNotContainsString('never-copy', json_encode($this->provider->calls));
        $this->assertSame(0, $this->sendAll());
    }

    public function test_threshold_is_filtered_fixed_window_crossing_and_new_event_has_priority(): void
    {
        config(['exception-monitor.capture.allowed_tags' => ['team']]);
        Telescope::tag(fn () => ['team:payments']);
        $a = $this->recipient();
        $this->rule([$a]);
        $this->rule([$a], [
            'events' => ['error.threshold_reached'],
            'threshold_count' => 1,
            'window_seconds' => 300,
            'conditions' => ['origin' => 'unknown', 'tag' => 'team:payments'],
        ]);
        $this->capture();
        $this->process();
        $this->assertDatabaseCount('exception_monitor_deliveries', 1);
        $this->assertSame('error.created', DB::table('exception_monitor_alert_events')->where('id', $this->delivery()->event_id)->value('event_type'));
        $this->assertDatabaseCount('exception_monitor_delivery_rules', 2);
        $this->capture();
        $this->process();
        $this->assertDatabaseCount('exception_monitor_deliveries', 1);
        $this->assertSame(1, DB::table('exception_monitor_alert_events')->where('event_type', 'error.threshold_reached')->count());
    }

    public function test_threshold_counts_out_of_order_live_events_and_does_not_use_unfiltered_metrics(): void
    {
        $a = $this->recipient();
        $this->rule([$a], [
            'events' => ['error.threshold_reached'],
            'threshold_count' => 2,
            'window_seconds' => 900,
            'conditions' => ['origin' => 'web'],
        ]);
        $this->capture();
        $this->capture();
        $this->process();
        $this->assertDatabaseCount('exception_monitor_deliveries', 0);
        $rule = DB::table('exception_monitor_alert_rules')->first();
        $this->patchJson('/exception-monitor/api/v1/alerting/rules/'.$rule->public_id, ['client_key' => (string) Str::uuid(), 'version' => 1] + $this->definition([$a], ['events' => ['error.threshold_reached'], 'threshold_count' => 4, 'window_seconds' => 900]))->assertOk();
        $this->capture(at: CarbonImmutable::now('UTC')->subMinute());
        $this->process();
        $this->capture(at: CarbonImmutable::now('UTC')->subMinutes(2));
        $this->process();
        $this->assertDatabaseCount('exception_monitor_deliveries', 1);
    }

    public function test_historical_recovery_and_synthetic_probe_do_not_create_automatic_intent(): void
    {
        $a = $this->recipient();
        $this->rule([$a], ['events' => ['error.created', 'error.threshold_reached'], 'threshold_count' => 1, 'window_seconds' => 300]);
        $this->capture();
        DB::table('exception_monitor_capture_inbox')->update(['mode' => 'reconciliation']);
        $this->process();
        $this->assertDatabaseCount('exception_monitor_deliveries', 0);
        $this->capture(class: 'SyntheticClass');
        DB::table('exception_monitor_capture_inbox')->where('state', 'pending')->update(['mode' => 'synthetic_test']);
        $this->process();
        $this->assertDatabaseCount('exception_monitor_deliveries', 0);
    }

    public function test_failed_attempt_retries_same_logical_id_and_body_with_bounded_backoff(): void
    {
        $a = $this->recipient();
        $this->rule([$a]);
        $this->provider->outcomes = [Outcome::retryable(), Outcome::accepted()];
        $this->capture();
        $this->process();
        $id = $this->delivery()->public_id;
        $this->sendAll();
        $this->assertSame('retrying', $this->delivery()->status);
        $cipher = $this->delivery()->payload_ciphertext;
        $this->assertSame(0, $this->sendAll());
        $this->travel(61)->seconds();
        $this->sendAll();
        $this->assertSame('sent', $this->delivery()->status);
        $this->assertSame($id, $this->delivery()->public_id);
        $this->assertSame($cipher, $this->delivery()->payload_ciphertext);
        $this->assertSame($this->provider->calls[0]['envelope'], $this->provider->calls[1]['envelope']);
        $this->assertDatabaseCount('exception_monitor_delivery_attempts', 2);
    }

    public function test_retry_after_is_not_shortened_and_five_attempts_exhaust_the_cycle(): void
    {
        $a = $this->recipient();
        $this->rule([$a]);
        $this->provider->outcomes = [
            Outcome::retryable(CarbonImmutable::now('UTC')->addMinutes(10)),
            Outcome::retryable(),
            Outcome::retryable(),
            Outcome::retryable(),
            Outcome::retryable(),
        ];
        $this->capture();
        $this->process();
        $this->sendAll();
        $this->travel(2)->minutes();
        $this->assertSame(0, $this->sendAll());
        for ($n = 0; $n < 5; $n++) {
            $this->travel(2)->hours();
            $this->sendAll();
        }$this->assertSame('failed', $this->delivery()->status);
        $this->assertSame(5, (int) $this->delivery()->attempt_count_total);
        $this->assertCount(5, $this->provider->calls);
    }

    public function test_privacy_is_reapplied_before_first_attempt_and_changed_frozen_export_requires_review(): void
    {
        $a = $this->recipient();
        $this->rule([$a]);
        $this->capture('Account ALPHA failed');
        $this->process();
        config(['exception-monitor.locked' => ['privacy.redact_patterns' => ['/ALPHA/']]]);
        $this->provider->outcomes = [Outcome::retryable()];
        $this->sendAll();
        $this->assertStringNotContainsString('ALPHA', json_encode($this->provider->calls[0]['envelope']));
        config(['exception-monitor.locked' => ['privacy.redact_patterns' => ['/Account/', '/ALPHA/']]]);
        $this->travel(61)->seconds();
        $this->sendAll();
        $this->assertSame('cancelled', $this->delivery()->status);
        $this->assertSame('export_policy_changed', $this->delivery()->cancellation_reason);
        $this->assertCount(1, $this->provider->calls);
    }

    public function test_resolve_cancels_occurrence_alert_but_resolved_activity_remains_sendable(): void
    {
        $a = $this->recipient();
        $this->rule([$a], ['events' => ['error.created', 'error.resolved']]);
        $this->capture();
        $this->process();
        $this->action('resolve');
        $this->assertSame(1, DB::table('exception_monitor_deliveries')->where('status', 'cancelled')->count());
        $this->assertSame(1, $this->sendAll());
        $this->assertSame('error.resolved', $this->provider->calls[0]['envelope']['event']);
    }

    public function test_regression_waits_for_shared_destination_cooldown_without_consuming_an_attempt(): void
    {
        $a = $this->recipient();
        $this->rule([$a], ['cooldown_seconds' => 1800]);
        $this->capture();
        $this->process();
        $this->sendAll();
        $this->action('resolve');
        $this->travel(1)->seconds();
        $this->capture();
        $this->process();
        $this->assertSame(0, $this->sendAll());
        $this->assertSame('pending', $this->delivery()->status);
        $this->assertSame(0, (int) $this->delivery()->attempt_count_total);
        $this->travel(31)->minutes();
        $this->assertSame(1, $this->sendAll());
        $this->assertSame('error.regressed', $this->provider->calls[1]['envelope']['event']);
    }

    public function test_rule_deletion_keeps_other_support_and_new_recipients_do_not_receive_history(): void
    {
        $a = $this->recipient();
        $one = $this->rule([$a]);
        $two = $this->rule([$a]);
        $this->capture();
        $this->process();
        $this->deleteJson('/exception-monitor/api/v1/alerting/rules/'.$one, ['client_key' => (string) Str::uuid(), 'version' => 1])->assertOk();
        $b = $this->recipient('second@example.test');
        $this->patchJson('/exception-monitor/api/v1/alerting/rules/'.$two, ['client_key' => (string) Str::uuid(), 'version' => 1] + $this->definition([$a, $b]))->assertOk();
        $this->assertDatabaseCount('exception_monitor_deliveries', 1);
        $this->assertSame(1, $this->sendAll());
        $this->assertSame('oncall@example.test', $this->provider->calls[0]['target']['email']);
    }

    public function test_disconnect_and_expiry_cancel_without_provider_attempt(): void
    {
        $a = $this->recipient();
        $this->rule([$a]);
        $this->capture();
        $this->process();
        $this->deleteJson('/exception-monitor/api/v1/alerting/email/'.$a, ['client_key' => (string) Str::uuid(), 'version' => 1])->assertOk();
        $this->sendAll();
        $this->assertSame('cancelled', $this->delivery()->status);
        $this->assertCount(0, $this->provider->calls);
    }

    public function test_expired_worker_lease_is_uncertain_and_duplicate_claim_is_not_stolen(): void
    {
        $a = $this->recipient();
        $this->rule([$a]);
        $this->capture();
        $this->process();
        $d = $this->delivery();
        $claim = app(DeliveryLedger::class)->claim($d->id, $d->generation, true);
        $this->assertNotNull($claim);
        $this->assertNull(app(DeliveryLedger::class)->claim($d->id, $d->generation, true));
        $this->travel(121)->seconds();
        $this->sendAll();
        $this->assertSame('sent', $this->delivery()->status);
        $this->assertSame(2, (int) $this->delivery()->attempt_count_total);
        $this->assertSame('uncertain', DB::table('exception_monitor_delivery_attempts')->orderBy('id')->value('outcome'));
        app(DeliveryLedger::class)->finish($d->id, $claim['token'], Outcome::permanent());
        $this->assertSame('sent', $this->delivery()->status);
    }

    public function test_preview_does_not_write_and_explicit_test_is_durable_synthetic_and_reauthorized(): void
    {
        $a = $this->recipient();
        $this->postJson('/exception-monitor/api/v1/alerting/preview', ['definition' => $this->definition([$a])])->assertOk();
        $this->assertDatabaseCount('exception_monitor_deliveries', 0);
        $key = (string) Str::uuid();
        $p = ['client_key' => $key, 'connections' => [$a]];
        $op = $this->postJson('/exception-monitor/api/v1/alerting/tests', $p)->assertStatus(202)->json('exception_monitor_private.id');
        $this->postJson('/exception-monitor/api/v1/alerting/tests', $p)->assertStatus(202);
        $this->assertDatabaseCount('exception_monitor_deliveries', 1);
        $this->authorize(manage: false);
        $this->sendAll();
        $this->assertSame('cancelled', $this->delivery()->status);
        $this->assertSame('initiator_unauthorized', $this->delivery()->cancellation_reason);
        $this->assertCount(0, $this->provider->calls);
    }

    public function test_explicit_retry_is_audited_reuses_identity_and_rejects_stale_support(): void
    {
        $a = $this->recipient();
        $r = $this->rule([$a]);
        $this->provider->outcomes = [Outcome::permanent()];
        $this->capture();
        $this->process();
        $this->sendAll();
        $d = $this->delivery();
        $key = (string) Str::uuid();
        $this->postJson('/exception-monitor/api/v1/deliveries/'.$d->public_id.'/retry', ['client_key' => $key])->assertOk();
        $this->postJson('/exception-monitor/api/v1/deliveries/'.$d->public_id.'/retry', ['client_key' => $key])->assertOk();
        $this->sendAll();
        $this->assertSame('sent', $this->delivery()->status);
        $this->assertSame($d->public_id, $this->delivery()->public_id);
        $this->assertSame(2, (int) $this->delivery()->retry_cycle);
        $this->assertSame(1, DB::table('exception_monitor_activity')->where('event_type', 'delivery.retry')->count());
    }

    public function test_broker_failure_leaves_pending_intent_and_no_raw_provider_error_is_logged(): void
    {
        $a = $this->recipient();
        $this->rule([$a]);
        config(['queue.default' => 'database']);
        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('password=BROKER-SECRET'));
        $this->capture();
        $this->process();
        $this->assertSame('pending', $this->delivery()->status);
        $this->assertStringNotContainsString('BROKER-SECRET', json_encode(DB::table('exception_monitor_health_checks')->get()));
        $this->assertCount(0, $this->provider->calls);
        $this->artisan('exception-monitor:recover-deliveries')->assertSuccessful();
        $this->assertSame('warning', DB::table('exception_monitor_health_checks')->where('check_key', 'delivery_recovery')->value('state'));
    }

    public function test_provider_io_is_outside_transaction_and_inflight_acceptance_remains_honest_after_resolution(): void
    {
        $a = $this->recipient();
        $this->rule([$a]);
        $this->capture();
        $this->process();
        $this->provider->during = fn () => $this->action('resolve');
        $this->sendAll();
        $this->assertSame('sent', $this->delivery()->status);
        $this->assertSame('resolved', DB::table('exception_monitor_issues')->value('status'));
        $this->assertSame(0, $this->provider->calls[0]['transaction']);
    }

    public function test_note_notification_exports_only_summary_never_note_text(): void
    {
        $a = $this->recipient();
        $this->rule([$a], ['events' => ['note.created']]);
        $this->capture('Incident summary');
        $this->process();
        $issue = DB::table('exception_monitor_issues')->value('public_id');
        $this->postJson('/exception-monitor/api/v1/issues/'.$issue.'/notes', ['client_key' => (string) Str::uuid(), 'exception_monitor_private' => ['body' => 'PRIVATE-NOTE-CONTENT']])->assertOk();
        $this->sendAll();
        $this->assertCount(1, $this->provider->calls);
        $this->assertStringNotContainsString('PRIVATE-NOTE-CONTENT', json_encode($this->provider->calls));
        $this->assertSame('note.created', $this->provider->calls[0]['envelope']['event']);
    }

    public function test_configuration_permissions_validation_versions_and_masked_history(): void
    {
        $this->authorize(operate: false, manage: false);
        $this->getJson('/exception-monitor/api/v1/alerting')->assertForbidden();
        $this->getJson('/exception-monitor/api/v1/deliveries')->assertOk();
        $this->authorize();
        $a = $this->recipient();
        $this->postJson('/exception-monitor/api/v1/alerting/rules', ['client_key' => (string) Str::uuid()] + $this->definition([$a], ['conditions' => ['environments' => ['other']]]))->assertUnprocessable();
        $this->rule([$a]);
        $this->capture();
        $this->process();
        $this->sendAll();
        $this->authorize(manage: false);
        $this->getJson('/exception-monitor/api/v1/deliveries')->assertOk()->assertDontSee('oncall@example.test')->assertJsonPath('exception_monitor_private.items.0.destination', 'o***@example.test');
    }

    public function test_host_email_provider_uses_array_transport_and_multipart_safe_content(): void
    {
        config(['mail.default' => 'array', 'exception-monitor.alerts.mailer' => 'array']);
        $this->app->bind(NotificationProvider::class, HostEmailProvider::class);
        $a = $this->recipient();
        $this->rule([$a]);
        $this->capture('Gateway token=private-value');
        $this->process();
        $this->sendAll();
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages->last()->getOriginalMessage();
        $this->assertSame('oncall@example.test', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('Open investigation:', $message->getTextBody());
        $this->assertStringNotContainsString('private-value', $message->getTextBody());
        $this->assertStringContainsString('Telescope Monitor', $message->getHtmlBody());
        $this->assertStringNotContainsString('private-value', $message->getHtmlBody());
    }

    public function test_reviewed_resend_requires_current_digest_and_creates_a_linked_new_identity(): void
    {
        $a = $this->recipient();
        $this->rule([$a]);
        $this->provider->outcomes = [Outcome::retryable()];
        $this->capture('Customer ALPHA failed');
        $this->process();
        $this->sendAll();
        $old = $this->delivery();
        config(['exception-monitor.locked' => ['privacy.redact_patterns' => ['/ALPHA/']]]);
        $this->travel(61)->seconds();
        $this->sendAll();
        $this->assertSame('export_policy_changed', $this->delivery()->cancellation_reason);
        $url = '/exception-monitor/api/v1/deliveries/'.$old->public_id;
        $preview = $this->postJson($url.'/review')->assertOk();
        $this->assertStringNotContainsString('ALPHA', $preview->json('exception_monitor_private.text'));
        $this->postJson($url.'/resend', ['client_key' => (string) Str::uuid(), 'preview_digest' => str_repeat('0', 64)])->assertConflict();
        $new = $this->postJson($url.'/resend', ['client_key' => (string) Str::uuid(), 'preview_digest' => $preview->json('exception_monitor_private.digest')])->assertOk()->json('exception_monitor_private.id');
        $this->assertNotSame($old->public_id, $new);
        $this->assertSame((int) $old->id, (int) $this->delivery()->parent_delivery_id);
        $this->sendAll();
        $this->assertSame('sent', $this->delivery()->status);
        $this->assertStringNotContainsString('ALPHA', json_encode($this->provider->calls[1]['envelope']));
    }

    public function test_failed_freeze_or_target_decryption_is_visible_without_an_attempt(): void
    {
        $a = $this->recipient();
        $this->rule([$a]);
        $this->capture();
        $this->process();
        DB::table('exception_monitor_secrets')->where('secret_name', 'email_address')->update(['ciphertext' => 'broken']);
        $this->sendAll();
        $this->assertSame('failed', $this->delivery()->status);
        $this->assertSame('payload_or_target_unreadable', $this->delivery()->last_error_code);
        $this->assertSame(0, (int) $this->delivery()->attempt_count_total);
        $this->assertDatabaseCount('exception_monitor_delivery_attempts', 0);
        $this->assertNull($this->delivery()->payload_frozen_at);
    }

    public function test_generation_fence_does_not_recreate_a_notification_gate(): void
    {
        $a = $this->recipient();
        $this->rule([$a]);
        $this->capture();
        $this->process();
        DB::table('exception_monitor_installations')->increment('generation');
        $this->sendAll();
        $this->assertSame('cancelled', $this->delivery()->status);
        $this->assertSame('installation_fenced', $this->delivery()->cancellation_reason);
        $this->assertDatabaseCount('exception_monitor_notification_gates', 0);
        $this->assertCount(0, $this->provider->calls);
    }

    public function test_pruning_expires_pending_work_and_protects_referenced_events(): void
    {
        $a = $this->recipient();
        $this->rule([$a]);
        $this->capture();
        $this->process();
        $this->travel(25)->hours();
        app(DeliveryLedger::class)->prune(100);
        $this->assertSame('cancelled', $this->delivery()->status);
        $this->assertSame('expired', $this->delivery()->cancellation_reason);
        $this->assertCount(0, $this->provider->calls);
        DB::table('exception_monitor_alert_events')->update(['expires_at' => CarbonImmutable::now('UTC')->subMinute()->format('Y-m-d H:i:s.u')]);
        app(AlertEventRepository::class)->prune(100);
        $this->assertDatabaseCount('exception_monitor_alert_events', 1);
        $this->travel(31)->days();
        app(DeliveryLedger::class)->prune(100);
        $this->assertDatabaseCount('exception_monitor_deliveries', 0);
        $this->assertDatabaseCount('exception_monitor_alert_events', 0);
    }

    public function test_two_email_recipients_have_independent_gates(): void
    {
        $a = $this->recipient();
        $b = $this->recipient('backup@example.test');
        $this->rule([$a, $b], ['cooldown_seconds' => 1800]);
        $this->capture();
        $this->process();
        $this->assertSame(2, $this->sendAll());
        $this->assertCount(2, $this->provider->calls);
        $this->assertDatabaseCount('exception_monitor_notification_gates', 2);
    }

    public function test_rule_support_recheck_does_not_depend_on_configuration_cache_invalidation(): void
    {
        $a = $this->recipient();
        $r = $this->rule([$a]);
        $this->capture();
        $this->process();
        $sender = app(DeliverySender::class);
        DB::table('exception_monitor_alert_rules')->where('public_id', $r)->update(['enabled' => false]);
        $d = $this->delivery();
        $this->assertFalse($sender->send($d->id, $d->generation));
        $this->assertSame('cancelled', $this->delivery()->status);
        $this->assertCount(0, $this->provider->calls);
    }

    public function test_actor_revocation_cancels_an_operator_retry_before_transport(): void
    {
        $a = $this->recipient();
        $this->rule([$a]);
        $this->provider->outcomes = [Outcome::permanent()];
        $this->capture();
        $this->process();
        $this->sendAll();
        $d = $this->delivery();
        $this->postJson('/exception-monitor/api/v1/deliveries/'.$d->public_id.'/retry', ['client_key' => (string) Str::uuid()])->assertOk();
        $this->authorize(operate: false);
        $this->sendAll();
        $this->assertSame('initiator_unauthorized', $this->delivery()->cancellation_reason);
        $this->assertCount(1, $this->provider->calls);
    }

    public function test_rule_edit_version_and_target_replacement_preserve_old_history_identity(): void
    {
        $a = $this->recipient();
        $r = $this->rule([$a]);
        $this->capture();
        $this->process();
        $old = $this->delivery();
        $replacement = $this->postJson('/exception-monitor/api/v1/alerting/email', [
            'client_key' => (string) Str::uuid(),
            'name' => 'Replacement',
            'email' => 'new@example.test',
            'enabled' => true,
            'replaces' => $a,
            'replaces_version' => 1,
        ])->assertOk()->json('exception_monitor_private.id');
        $this->assertNotSame($a, $replacement);
        $this->assertSame('cancelled', $this->delivery()->status);
        $this->assertSame($old->connection_id, $this->delivery()->connection_id);
        $this->assertCount(0, $this->provider->calls);
        $this->patchJson('/exception-monitor/api/v1/alerting/rules/'.$r, ['client_key' => (string) Str::uuid(), 'version' => 1] + $this->definition([$replacement]))->assertOk();
        $this->patchJson('/exception-monitor/api/v1/alerting/rules/'.$r, ['client_key' => (string) Str::uuid(), 'version' => 1] + $this->definition([$replacement]))->assertConflict();
        $this->assertDatabaseCount('exception_monitor_deliveries', 1);
    }

    public function test_redaction_is_stable_across_default_custom_and_spanning_patterns(): void
    {
        $redactor = app(RedactionService::class);
        $value = 'token=private-value ALPHA';
        $patterns = ['/ALPHA/', '/REDACTED/'];
        $once = $redactor->text($value, $patterns);
        $this->assertSame($once, $redactor->text($once, $patterns));
        $this->assertSame('token=[REDACTED] [REDACTED]', $once);
        $this->assertSame('[REDACTED]', $redactor->text('BEGIN [REDACTED] hidden END', ['/BEGIN.*END/']));
    }

    public function test_runtime_target_identity_cannot_redirect_a_frozen_destination(): void
    {
        $a = $this->recipient();
        $this->rule([$a]);
        $this->capture();
        $this->process();
        DB::table('exception_monitor_secrets')->where('secret_name', 'email_address')->update(['ciphertext' => Crypt::encryptString('other@example.test')]);
        $this->sendAll();
        $this->assertSame('failed', $this->delivery()->status);
        $this->assertCount(0, $this->provider->calls);
        $this->assertDatabaseCount('exception_monitor_delivery_attempts', 0);
    }

    public function test_lost_local_commit_after_provider_acceptance_recovers_with_honest_at_least_once_semantics(): void
    {
        $a = $this->recipient();
        $this->rule([$a]);
        $this->capture();
        $this->process();
        $once = true;
        DB::listen(function ($query) use (&$once) {
            if ($once && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'exception_monitor_deliveries') && in_array('sent', $query->bindings, true)) {
                $once = false;
                throw new \RuntimeException('Simulated outcome-commit loss');
            }
        });
        try {
            $this->sendAll();
            $this->fail('Expected simulated local commit loss');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated outcome-commit loss', $e->getMessage());
        }
        $this->assertSame('processing', $this->delivery()->status);
        $this->assertCount(1, $this->provider->calls);
        $id = $this->delivery()->public_id;
        $this->travel(121)->seconds();
        $this->sendAll();
        $this->assertSame('sent', $this->delivery()->status);
        $this->assertSame($id, $this->delivery()->public_id);
        $this->assertCount(2, $this->provider->calls);
        $this->assertSame($this->provider->calls[0]['envelope'], $this->provider->calls[1]['envelope']);
        $this->assertSame('uncertain', DB::table('exception_monitor_delivery_attempts')->orderBy('sequence_number')->value('outcome'));
    }
}
