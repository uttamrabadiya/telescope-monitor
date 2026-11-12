<?php

namespace TelescopeMonitor\Tests\Alerts;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use TelescopeMonitor\Alerts\EmailContext;
use TelescopeMonitor\Alerts\EmailPresentation;
use TelescopeMonitor\Alerts\Envelope;
use TelescopeMonitor\Alerts\HostEmailProvider;
use TelescopeMonitor\Alerts\DeliverySender;
use TelescopeMonitor\Alerts\NotificationProvider;
use TelescopeMonitor\Alerts\Outcome;
use Illuminate\Support\Facades\Crypt;
use TelescopeMonitor\Models\Delivery;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class RichEmailTest extends TestCase
{
    use CapturesExceptions;
    private function envelope(array $extra = []): array
    {
        return array_replace([
            'test' => false,
            'event' => 'error.created',
            'label' => 'New error',
            'application' => 'Demo App',
            'environment' => 'production',
            'class' => 'App\\Exceptions\\WidgetNotFoundException',
            'message' => 'Widget not found',
            'file' => 'app/Services/V1/WidgetService.php',
            'line' => 215,
            'count' => '3',
            'occurred_at' => '2026-11-11T15:03:31Z',
            'url' => 'https://monitor.example.test/telescope-monitor/errors/issue?occurrence=occurrence',
            'delivery_id' => (string) Str::uuid(),
            'email_context' => [
                'version' => 1,
                'reference' => 'DEMO-APP-1H',
                'timezone' => 'Asia/Kolkata',
                'occurrence_id' => (string) Str::uuid(),
                'first_seen_at' => '2026-11-11T14:00:00Z',
                'last_seen_at' => '2026-11-11T15:03:31Z',
                'snapshot_state' => 'available',
                'execution' => ['method' => 'GET', 'path_template' => '/api/v1/catalogs/{id}/widgets/{widgetId}'],
                'frames' => [
                    [
                        'file' => 'app/Services/V1/WidgetService.php',
                        'line' => 215,
                        'class' => 'App\\Services\\V1\\WidgetService',
                        'function' => 'showWidget',
                    ],
                ],
                'omitted_frames' => 45,
                'tags' => [],
            ],
        ], $extra);
    }

    public function test_multipart_email_has_telescope_theme_rich_context_and_project_issue_subject(): void
    {
        config(['mail.default' => 'array']);
        $e = app(Envelope::class)->sanitize($this->envelope());
        $this->assertSame('accepted', app(HostEmailProvider::class)->send($e, ['email' => 'fixture@example.test'])->kind);
        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('DEMO-APP-1H - App\\Exceptions\\WidgetNotFoundException: Widget not found', $message->getSubject());
        $html = $message->getHtmlBody();
        foreach ([
            'Telescope Monitor',
            '#4040c8',
            '#f3f4f6',
            'New error group',
            'Application stack',
            'Retained execution context',
            '/api/v1/catalogs/{id}',
            '20:33:31 IST',
            '45 additional retained frames',
            'View error',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringContainsString('Open investigation:', $message->getTextBody());
        $this->assertStringContainsString('showWidget', $message->getTextBody());
        $this->assertStringNotContainsString('SENTRY', $html);
        $this->assertStringNotContainsString('fonts.googleapis', $html);
    }

    public function test_html_subject_and_links_do_not_allow_injection_or_unsafe_urls(): void
    {
        config(['mail.default' => 'array']);
        $e = app(Envelope::class)->sanitize($this->envelope([
            'message' => '<img src=x onerror=alert(1)> token=EMAIL-SECRET',
            'class' => "RuntimeException\r\nBcc: victim@example.test",
            'url' => 'javascript:alert(1)',
        ]));
        $this->assertSame('accepted', app(HostEmailProvider::class)->send($e, ['email' => 'fixture@example.test'])->kind);
        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertStringNotContainsString('<img src=x', $message->getHtmlBody());
        $this->assertStringContainsString('&lt;img', $message->getHtmlBody());
        $this->assertStringNotContainsString('EMAIL-SECRET', $message->getHtmlBody());
        $this->assertStringNotContainsString('javascript:', $message->getHtmlBody());
        $this->assertStringNotContainsString('javascript:', $message->getTextBody());
        $this->assertStringNotContainsString("\n", $message->getSubject());
        $this->assertCount(1, $message->getTo());
    }

    public function test_context_obeys_current_privacy_and_explicit_email_tag_allowlist(): void
    {
        config([
            'telescope-monitor.capture.allowed_tags' => ['browser', 'tenant'],
            'telescope-monitor.alerts.email.allowed_tags' => ['browser'],
            'telescope-monitor.locked' => ['privacy.retain_stack_trace' => false, 'privacy.retain_execution_context' => false],
        ]);
        $context = $this->envelope()['email_context'];
        $context['tags'] = ['browser' => 'Android 16', 'tenant' => 'PRIVATE-TENANT'];
        $context['execution']['url'] = 'https://example.test/user/PRIVATE-ID?token=PRIVATE-TOKEN';
        $context['frames'][0]['args'] = ['PRIVATE-ARG'];
        $context['user'] = ['email' => 'PRIVATE-EMAIL'];
        $context['queries'] = [['sql' => 'PRIVATE-SQL']];
        $safe = app(EmailContext::class)->sanitize($context);
        $this->assertSame([], $safe['frames']);
        $this->assertSame([], $safe['execution']);
        $this->assertSame(['browser' => 'Android 16'], $safe['tags']);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($safe));
        config(['telescope-monitor.alerts.email.include_context' => false]);
        $disabled = app(EmailContext::class)->sanitize($context);
        $this->assertSame([], $disabled['tags']);
        $this->assertSame('excluded', $disabled['snapshot_state']);
    }

    public function test_synthetic_and_older_frozen_envelopes_render_without_invented_context(): void
    {
        $e = $this->envelope(['test' => true, 'event' => 'monitor.test', 'email_context' => []]);
        $data = app(EmailPresentation::class)->data($e);
        $html = view('telescope-monitor::mail.exception', ['mail' => $data])->render();
        $this->assertStringContainsString('Synthetic delivery test', $html);
        $this->assertStringNotContainsString('Application stack', $html);
        $this->assertStringNotContainsString('>Tags<', $html);
        $this->assertStringStartsWith('[TEST]', app(EmailPresentation::class)->subject($e));
        unset($e['email_context']);
        $e['test'] = false;
        $html = view('telescope-monitor::mail.exception', ['mail' => app(EmailPresentation::class)->data($e)])->render();
        $this->assertStringContainsString('Detailed stack evidence is unavailable', $html);
        $this->assertStringNotContainsString('Retained execution context', $html);
    }

    public function test_application_frames_are_bounded_and_never_copy_arguments_or_code(): void
    {
        $context = $this->envelope()['email_context'];
        $context['frames'] = array_fill(0, 100, [
            'file' => 'app/Service.php',
            'line' => 1,
            'class' => 'App\\Service',
            'function' => 'handle',
            'args' => ['PRIVATE-ARG'],
            'code' => 'PRIVATE-CURRENT-SOURCE',
        ]);
        $context['execution']['path'] = '/users/PRIVATE-ID';
        $context['execution']['headers'] = ['Authorization' => 'PRIVATE-TOKEN'];
        $safe = app(EmailContext::class)->sanitize($context);
        $this->assertCount(8, $safe['frames']);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($safe));
    }
    private function capturedDelivery(): Delivery
    {
        $this->authorize();
        config(['telescope-monitor.alerts.allowed_environments' => ['testing']]);
        $connection = $this->postJson('/telescope-monitor/api/v1/alerting/email', ['client_key' => (string) Str::uuid(), 'name' => 'Rich fixture', 'email' => 'fixture@example.test', 'enabled' => true])->assertOk()->json('telescope_monitor_private.id');
        $this->postJson('/telescope-monitor/api/v1/alerting/rules', [
            'client_key' => (string) Str::uuid(),
            'name' => 'Rich fixture rule',
            'enabled' => true,
            'events' => ['error.created'],
            'conditions' => ['environments' => ['testing'], 'class' => null, 'origin' => null, 'tag' => null],
            'connections' => [$connection],
            'threshold_count' => null,
            'window_seconds' => null,
            'cooldown_seconds' => 0,
        ])->assertOk();
        $this->capture('Rich fixture failure');
        $this->process();
        $evidence = DB::table('telescope_monitor_occurrence_evidence')->first();
        $payload = json_decode(Crypt::decryptString($evidence->payload_ciphertext), true);
        $payload['execution'] = ['method' => 'GET', 'path_template' => '/groups/{id}', 'path' => '/groups/PRIVATE-ID'];
        $json = json_encode($payload);
        DB::table('telescope_monitor_occurrence_evidence')->where('id', $evidence->id)->update(['payload_ciphertext' => Crypt::encryptString($json), 'payload_digest' => hash('sha256', $json)]);
        return Delivery::query()->firstOrFail();
    }

    public function test_rich_context_freezes_with_the_first_attempt_and_is_not_refetched_on_retry(): void
    {
        $delivery = $this->capturedDelivery();
        $provider = new class implements NotificationProvider
        {
            public array $calls = [];
            public function send(array $envelope, array $target): Outcome
            {
                $this->calls[] = $envelope;
                return count($this->calls) === 1 ? Outcome::retryable() : Outcome::accepted();
            }
        };
        $this->app->instance(NotificationProvider::class, $provider);
        $sender = app(DeliverySender::class);
        $this->assertTrue($sender->send($delivery->id, $delivery->generation));
        $this->assertSame('/groups/{id}', $provider->calls[0]['email_context']['execution']['path_template']);
        $this->assertStringNotContainsString('PRIVATE-ID', json_encode($provider->calls[0]));
        $this->assertNotEmpty($provider->calls[0]['email_context']['frames']);
        DB::table('telescope_monitor_occurrence_evidence')->delete();
        $this->travel(61)->seconds();
        $this->assertTrue($sender->send($delivery->id, $delivery->generation));
        $this->assertSame($provider->calls[0], $provider->calls[1]);
    }

    public function test_stricter_privacy_cancels_a_frozen_rich_email_without_another_provider_call(): void
    {
        $delivery = $this->capturedDelivery();
        $provider = new class implements NotificationProvider
        {
            public int $calls = 0;
            public function send(array $envelope, array $target): Outcome
            {
                $this->calls++;
                return Outcome::retryable();
            }
        };
        $this->app->instance(NotificationProvider::class, $provider);
        $sender = app(DeliverySender::class);
        $sender->send($delivery->id, $delivery->generation);
        config(['telescope-monitor.locked' => ['privacy.retain_stack_trace' => false]]);
        $this->travel(61)->seconds();
        $this->assertFalse($sender->send($delivery->id, $delivery->generation));
        $this->assertSame(1, $provider->calls);
        $this->assertSame('export_policy_changed', $delivery->fresh()->cancellation_reason);
    }

}
