<?php

namespace TelescopeMonitor\Tests\Alerts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use TelescopeMonitor\Alerts\DeliverySender;
use TelescopeMonitor\Models\Activity;
use TelescopeMonitor\Models\AlertEvent;
use TelescopeMonitor\Models\Connection;
use TelescopeMonitor\Models\Delivery;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Reports\ReportBuilder;
use TelescopeMonitor\Reports\ReportDefinition;
use TelescopeMonitor\Reports\ReportScheduler;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Services\SettingsService;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class ScheduledReportsTest extends TestCase
{
    use CapturesExceptions;
    use \TelescopeMonitor\Tests\Fixtures\ReadsMail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorize();
        $this->travelTo(CarbonImmutable::parse('2026-11-16T05:00:00Z'));
        config(['telescope-monitor.alerts.allowed_environments' => ['testing', 'production']]);
        $i = app(InstallationRepository::class)->current();
        $i->created_at = CarbonImmutable::parse('2026-10-01T00:00:00Z');
        $i->save();
    }

    private function recipient(): string
    {
        return $this->postJson('/telescope-monitor/api/v1/alerting/email', ['client_key' => (string) Str::uuid(), 'name' => 'Report team', 'email' => 'reports@example.test', 'enabled' => true])->assertOk()->json('telescope_monitor_private.id');
    }

    private function definitions(array $extra = []): array
    {
        $recipient = $this->recipient();
        $defs = ReportDefinition::defaults();
        foreach ($defs as $kind => $v) {
            $defs[$kind] = array_replace($v, [
                'enabled' => true,
                'connections' => [$recipient],
                'environments' => ['testing'],
                'timezone' => 'Asia/Kolkata',
                'skip_empty' => false,
            ], $extra);
        }

        return $defs;
    }

    private function save(array $values, int $version = 0): void
    {
        $this->patchJson('/telescope-monitor/api/v1/settings/reports', ['version' => $version, 'values' => $values])->assertOk();
    }

    private function envelope(Delivery $d): array
    {
        return json_decode(Crypt::decryptString(AlertEvent::query()->findOrFail($d->event_id)->payload_ciphertext), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_defaults_validation_permissions_and_optimistic_settings_updates(): void
    {
        $this->getJson('/telescope-monitor/api/v1/settings/reports')->assertOk()->assertJsonPath('data.values.daily.enabled', false)->assertJsonPath('data.values.weekly.weekday', 1);
        $defs = $this->definitions();
        $this->save($defs);
        $this->patchJson('/telescope-monitor/api/v1/settings/reports', ['version' => 0, 'values' => $defs])->assertStatus(409);
        $bad = $defs;
        $bad['daily']['connections'] = [];
        $this->patchJson('/telescope-monitor/api/v1/settings/reports', ['version' => 1, 'values' => $bad])->assertStatus(422);
        $bad = $defs;
        $bad['weekly']['timezone'] = 'Mars/Olympus';
        $bad['daily']['environments'] = ['forbidden'];
        $this->patchJson('/telescope-monitor/api/v1/settings/reports', ['version' => 1, 'values' => $bad])->assertStatus(422);
        $this->authorize(true, false);
        $this->getJson('/telescope-monitor/api/v1/settings/reports')->assertForbidden();
        $this->postJson('/telescope-monitor/api/v1/reports/daily/preview', ['client_key' => (string) Str::uuid(), 'version' => 1])->assertForbidden();
    }

    public function test_disabled_drafts_with_empty_selections_are_valid(): void
    {
        $this->save(ReportDefinition::defaults());
        $this->assertTrue(app(ReportScheduler::class)->queue('daily')['skipped']);
    }

    public function test_due_reports_are_deduplicated_and_send_multipart_emails_through_existing_ledger(): void
    {
        $defs = $this->definitions();
        $this->save($defs);
        $this->capture('Checkout failed', at: CarbonImmutable::parse('2026-11-15T12:00:00Z'));
        $this->process();
        $this->artisan('telescope-monitor:reports')->assertExitCode(0);
        $this->artisan('telescope-monitor:reports')->assertExitCode(0);
        $deliveries = Delivery::query()->where('action', 'like', 'report_%')->get();
        $this->assertCount(2, $deliveries);
        foreach ($deliveries as $d) {
            $e = $this->envelope($d);
            $this->assertSame(1, $e['report']['occurrences']);
            $this->assertSame(1, $e['report']['new_groups']);
            $this->assertTrue($e['report']['comparison_available']);
            $this->assertTrue(app(DeliverySender::class)->send($d->id, $d->generation));
            $this->assertSame('sent', $d->fresh()->status);
        }
        $this->assertCount(2, $this->sentEmails());
        foreach ($this->sentEmails() as $message) {
            $this->assertStringNotContainsString('@endif', $message['html']);
            $this->assertStringNotContainsString('@if', $message['html']);
            $this->assertStringContainsString('Manage report preferences', $message['html']);
            $this->assertStringContainsString('Captured occurrences', $message['text']);
            $this->assertStringNotContainsString('PREVIEW', $message['subject']);
            $this->assertStringContainsString('reports@example.test', implode(',', $message['to']));
        }
        $this->getJson('/telescope-monitor/api/v1/deliveries')->assertOk();
    }

    public function test_time_weekday_and_independent_toggles_are_respected(): void
    {
        $defs = $this->definitions();
        $defs['daily']['time'] = '12:00';
        $defs['weekly']['weekday'] = 2;
        $this->save($defs);
        $this->assertTrue(app(ReportScheduler::class)->queue('daily')['skipped']);
        $this->assertTrue(app(ReportScheduler::class)->queue('weekly')['skipped']);
        $this->travelTo(CarbonImmutable::parse('2026-11-16T07:00:00Z'));
        $this->assertCount(1, app(ReportScheduler::class)->queue('daily')['deliveries']);
        $this->assertTrue(app(ReportScheduler::class)->queue('weekly')['skipped']);
        $this->travelTo(CarbonImmutable::parse('2026-11-17T05:00:00Z'));
        $this->assertCount(1, app(ReportScheduler::class)->queue('weekly')['deliveries']);
        $this->assertSame('2026-11-09T00:00:00+05:30', CarbonImmutable::parse($this->envelope(Delivery::query()->where('action', 'report_weekly')->firstOrFail())['report']['period_start'])->setTimezone('Asia/Kolkata')->toIso8601String());
    }

    public function test_previews_bypass_disabled_and_empty_period_suppression_are_labeled_and_idempotent(): void
    {
        $defs = $this->definitions(['enabled' => false, 'skip_empty' => true]);
        $this->save($defs);
        $body = ['client_key' => (string) Str::uuid(), 'version' => 1];
        $a = $this->postJson('/telescope-monitor/api/v1/reports/daily/preview', $body)->assertStatus(202)->json('telescope_monitor_private.deliveries');
        $b = $this->postJson('/telescope-monitor/api/v1/reports/daily/preview', $body)->assertStatus(202)->json('telescope_monitor_private.deliveries');
        $this->assertSame($a, $b);
        $d = Delivery::query()->firstOrFail();
        $this->assertSame('report_preview_daily', $d->action);
        $this->assertTrue($d->actor_required);
        $this->assertTrue(app(DeliverySender::class)->send($d->id));
        $message = $this->sentEmails()[0];
        $this->assertStringContainsString('[PREVIEW]', $message['subject']);
        $this->assertStringContainsString('actual captured data', $message['html']);
        $this->assertFalse(app(SettingsService::class)->group('reports')['values']['daily']['enabled']);
        $this->postJson('/telescope-monitor/api/v1/reports/weekly/preview', ['client_key' => (string) Str::uuid(), 'version' => 0])->assertStatus(409);
    }

    public function test_report_subscription_and_destination_changes_cancel_pending_work(): void
    {
        $defs = $this->definitions();
        $this->save($defs);
        app(ReportScheduler::class)->queue('daily');
        $d = Delivery::query()->firstOrFail();
        $defs['daily']['enabled'] = false;
        $this->save(['daily' => $defs['daily']], 1);
        $this->assertFalse(app(DeliverySender::class)->send($d->id));
        $this->assertSame('cancelled', $d->fresh()->status);
        app(ReportScheduler::class)->queue('weekly');
        $weekly = Delivery::query()->where('action', 'report_weekly')->firstOrFail();
        Connection::query()->whereKey($weekly->connection_id)->update(['enabled' => false]);
        $this->assertFalse(app(DeliverySender::class)->send($weekly->id));
        $this->assertSame('destination_unavailable', $weekly->fresh()->cancellation_reason);
    }

    public function test_skip_empty_counts_resolutions_as_activity_and_marks_empty_period_once(): void
    {
        $defs = $this->definitions(['skip_empty' => true]);
        $this->save($defs);
        $this->assertTrue(app(ReportScheduler::class)->queue('daily')['skipped']);
        $this->assertTrue(app(ReportScheduler::class)->queue('daily')['skipped']);
        $this->assertSame(1, AlertEvent::query()->where('event_type', 'report.daily')->count());
        $this->assertSame(0, Delivery::query()->count());
        $this->capture('Old issue', at: CarbonImmutable::parse('2026-11-01T12:00:00Z'));
        $this->process();
        $issue = Issue::query()->firstOrFail();
        Activity::query()->create([
            'public_id' => (string) Str::uuid(),
            'installation_id' => $issue->installation_id,
            'issue_id' => $issue->id,
            'event_type' => 'error.resolve',
            'actor_kind' => 'user',
            'subject_type' => 'issue',
            'subject_public_id' => $issue->public_id,
            'action_public_id' => (string) Str::uuid(),
            'occurred_at' => CarbonImmutable::parse('2026-11-12T12:00:00Z'),
            'metadata' => [],
            'expires_at' => now('UTC')->addDays(30),
        ]);
        $result = app(ReportScheduler::class)->queue('weekly');
        $this->assertFalse($result['skipped']);
        $r = $this->envelope(Delivery::query()->where('action', 'report_weekly')->firstOrFail())['report'];
        $this->assertSame(0, $r['occurrences']);
        $this->assertSame(1, $r['resolved']);
    }

    public function test_daily_boundaries_are_exact_for_half_hour_zone_and_exclude_tests_and_other_environments(): void
    {
        $definition = $this->definitions()['daily'];
        foreach (['2026-11-14T18:29:59Z', '2026-11-14T18:30:00Z', '2026-11-15T18:29:59Z', '2026-11-15T18:30:00Z'] as $at) {
            $this->capture('same error', at: CarbonImmutable::parse($at));
            $this->process();
        }
        $r = app(ReportBuilder::class)->build('daily', $definition, CarbonImmutable::now('UTC'));
        $this->assertSame(2, $r['occurrences']);
        $this->assertSame(2, array_sum(array_column($r['buckets'], 'count')));
        $this->assertCount(24, $r['buckets']);
        Issue::query()->update(['is_test' => true]);
        $this->assertSame(0, app(ReportBuilder::class)->build('daily', $definition, CarbonImmutable::now('UTC'))['occurrences']);
        Issue::query()->update(['is_test' => false, 'environment' => 'production']);
        $this->assertSame(0, app(ReportBuilder::class)->build('daily', $definition, CarbonImmutable::now('UTC'))['occurrences']);
    }

    public function test_dst_days_have_23_or_25_hourly_buckets(): void
    {
        $definition = $this->definitions(['timezone' => 'America/New_York'])['daily'];
        $builder = app(ReportBuilder::class);
        $this->assertCount(25, $builder->build('daily', $definition, CarbonImmutable::parse('2026-11-02T15:00:00Z'))['buckets']);
        $this->assertCount(23, $builder->build('daily', $definition, CarbonImmutable::parse('2026-03-09T15:00:00Z'))['buckets']);
    }

    public function test_incomplete_history_is_not_zero_and_does_not_suppress_empty_reports(): void
    {
        $this->save($this->definitions(['skip_empty' => true]));
        $i = app(InstallationRepository::class)->current();
        $i->created_at = now('UTC');
        $i->save();
        $this->assertFalse(app(ReportScheduler::class)->queue('daily')['skipped']);
        $d = Delivery::query()->firstOrFail();
        $r = $this->envelope($d)['report'];
        $this->assertFalse($r['complete']);
        $this->assertFalse($r['comparison_available']);
        $this->assertNull($r['previous_occurrences']);
        $this->assertNull($r['resolved']);
        $this->assertNull($r['regressions']);
    }

    public function test_previews_recheck_initiator_manage_access_and_delivery_policy(): void
    {
        $this->save($this->definitions());
        $this->postJson('/telescope-monitor/api/v1/reports/daily/preview', ['client_key' => (string) Str::uuid(), 'version' => 1])->assertStatus(202);
        $this->authorize(true, false);
        $d = Delivery::query()->firstOrFail();
        $this->assertFalse(app(DeliverySender::class)->send($d->id));
        $this->assertSame('initiator_unauthorized', $d->fresh()->cancellation_reason);
        $this->authorize();
        config(['telescope-monitor.alerts.enabled' => false]);
        $this->postJson('/telescope-monitor/api/v1/reports/weekly/preview', ['client_key' => (string) Str::uuid(), 'version' => 1])->assertStatus(409);
    }

    public function test_report_classes_are_redacted_and_html_escaped_and_frozen_retries_recheck_export_policy(): void
    {
        $this->save($this->definitions());
        $this->capture('Example', at: CarbonImmutable::parse('2026-11-15T12:00:00Z'));
        $this->process();
        Issue::query()->update(['exception_class' => '<img src=x onerror=alert(1)> REPORTSECRET']);
        config(['telescope-monitor.locked' => ['privacy.redact_patterns' => ['/REPORTSECRET/']]]);
        app(ReportScheduler::class)->queue('daily');
        $d = Delivery::query()->firstOrFail();
        $this->assertTrue(app(DeliverySender::class)->send($d->id));
        $html = $this->sentEmails()[0]['html'];
        $this->assertStringNotContainsString('REPORTSECRET', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img', $html);

        $this->app->bind(\TelescopeMonitor\Alerts\NotificationProvider::class, fn () => new class implements \TelescopeMonitor\Alerts\NotificationProvider
        {
            public function send(array $envelope, array $target): \TelescopeMonitor\Alerts\Outcome
            {
                return \TelescopeMonitor\Alerts\Outcome::retryable();
            }
        });
        $this->postJson('/telescope-monitor/api/v1/reports/daily/preview', ['client_key' => (string) Str::uuid(), 'version' => 1])->assertStatus(202);
        $preview = Delivery::query()->where('action', 'report_preview_daily')->firstOrFail();
        $this->assertTrue(app(DeliverySender::class)->send($preview->id));
        $this->assertSame('retrying', $preview->fresh()->status);
        config(['telescope-monitor.locked' => ['privacy.redact_patterns' => ['/REPORTSECRET/', '/onerror/']]]);
        $this->travel(10)->minutes();
        $this->assertFalse(app(DeliverySender::class)->send($preview->id));
        $this->assertSame('export_policy_changed', $preview->fresh()->cancellation_reason);
    }
}
