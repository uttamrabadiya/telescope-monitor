<?php

namespace TelescopeMonitor\Tests\Alerts;

use TelescopeMonitor\Alerts\Envelope;
use TelescopeMonitor\Alerts\SlackContext;
use TelescopeMonitor\Alerts\SlackPresentation;
use TelescopeMonitor\Tests\TestCase;

final class SlackPresentationTest extends TestCase
{
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
            'file' => 'app/Services/WidgetService.php',
            'line' => 42,
            'count' => '3',
            'occurred_at' => '2026-11-11T15:03:31Z',
            'url' => 'https://monitor.example.test/telescope-monitor/errors/demo?occurrence=sample',
            'slack_context' => [
                'reference' => 'DEMO-APP-1H',
                'timezone' => 'Asia/Kolkata',
                'first_seen_at' => '2026-11-11T14:00:00Z',
                'execution' => ['method' => 'GET', 'path_template' => '/widgets/{widget}'],
                'release' => 'v1.2.3',
            ],
        ], $extra);
    }

    public function test_message_contains_triage_context_and_occurrence_deep_link(): void
    {
        $payload = (new SlackPresentation)->payload($this->envelope());
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ([
            '🔴 New error · Demo App',
            'Widget not found',
            'WidgetService.php:42',
            'Captured occurrences',
            '20:33:31 IST',
            'First seen',
            'GET /widgets/{widget}',
            'v1.2.3',
            'DEMO-APP-1H',
            'Open investigation',
        ] as $expected) {
            $this->assertStringContainsString($expected, $json);
        }
        $action = array_values(array_filter($payload['blocks'], fn ($b) => $b['type'] === 'actions'))[0];
        $this->assertSame($this->envelope()['url'], $action['elements'][0]['url']);
        $this->assertStringContainsString('Widget not found', $payload['text']);
        $this->assertStringNotContainsString('Transport acceptance', $json);
    }

    public function test_untrusted_text_cannot_create_mentions_links_or_break_code_formatting(): void
    {
        $e = app(Envelope::class)->sanitize($this->envelope([
            'message' => '<!channel> <@U123> <https://evil.example.test|click> token=SLACK-SECRET',
            'file' => "app/`\n<!everyone>.php",
        ]));
        $payload = (new SlackPresentation)->payload($e);
        $json = json_encode($payload);
        $this->assertStringNotContainsString('SLACK-SECRET', $json);
        $this->assertStringNotContainsString('<!channel>', $json);
        $this->assertStringContainsString('&lt;!channel&gt;', $json);
        $this->assertSame('plain_text', $payload['blocks'][1]['text']['type']);
        $this->assertSame(2, substr_count($payload['blocks'][2]['text']['text'], '`'));
        $this->assertTrue($payload['blocks'][2]['text']['verbatim']);
        $this->assertFalse($payload['link_names']);
        $this->assertFalse($payload['unfurl_links']);
    }

    public function test_privacy_is_reapplied_to_frozen_context_and_only_safe_execution_fields_leave(): void
    {
        $context = $this->envelope()['slack_context'];
        $context['execution']['url'] = 'https://example.test?token=PRIVATE-QUERY';
        $context['execution']['headers'] = ['Cookie' => 'PRIVATE-COOKIE'];
        $context['user'] = ['email' => 'PRIVATE-USER'];
        $safe = app(SlackContext::class)->sanitize($context);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($safe));
        config(['telescope-monitor.locked' => ['privacy.retain_execution_context' => false]]);
        $e = app(Envelope::class)->sanitize($this->envelope(['slack_context' => $context]));
        $this->assertSame([], $e['slack_context']['execution']);
    }

    public function test_test_activity_and_legacy_envelopes_do_not_invent_incident_context(): void
    {
        $presentation = new SlackPresentation;
        $test = $presentation->payload($this->envelope(['test' => true, 'event' => 'monitor.test']));
        $json = json_encode($test);
        $this->assertStringContainsString('No real incident', $json);
        $this->assertStringNotContainsString('Captured occurrences', $json);
        $this->assertStringNotContainsString('/widgets/', $json);
        $resolved = $presentation->payload($this->envelope(['event' => 'error.resolved', 'label' => 'Resolved']));
        $this->assertStringContainsString('✅ Resolved', $resolved['blocks'][0]['text']['text']);
        $this->assertStringNotContainsString('/widgets/', json_encode($resolved));
        $legacy = $this->envelope();
        unset($legacy['slack_context']);
        $this->assertStringContainsString('UTC', json_encode($presentation->payload($legacy)));
    }

    public function test_long_unicode_content_and_invalid_links_obey_slack_limits(): void
    {
        $payload = (new SlackPresentation)->payload($this->envelope([
            'application' => str_repeat('界&', 120),
            'class' => str_repeat('界&', 512),
            'message' => str_repeat('界&', 1024),
            'file' => str_repeat('界&', 2048),
            'url' => 'javascript:alert(1)',
        ]));
        $this->assertLessThanOrEqual(150, mb_strlen($payload['blocks'][0]['text']['text']));
        foreach ($payload['blocks'] as $block) {
            $this->assertNotSame('actions', $block['type']);
            if (isset($block['text'])) {
                $this->assertLessThanOrEqual(3000, mb_strlen($block['text']['text']));
                $this->assertTrue(mb_check_encoding($block['text']['text'], 'UTF-8'));
            }
        }
        $this->assertStringNotContainsString('javascript:', json_encode($payload));
        $this->assertStringContainsString('Dashboard URL unavailable', $payload['text']);
    }
}
