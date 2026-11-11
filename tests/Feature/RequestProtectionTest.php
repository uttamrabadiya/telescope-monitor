<?php

namespace TelescopeMonitor\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use TelescopeMonitor\Tests\Fixtures\StrictRequestForgery;
use TelescopeMonitor\Tests\TestCase;

final class RequestProtectionTest extends TestCase
{
    public function test_mutations_require_real_request_forgery_protection(): void
    {
        $this->authorize();
        $this->app->bind(PreventRequestForgery::class, StrictRequestForgery::class);
        $this->withSession(['_token' => 'expected-csrf-token']);
        $this->patchJson('/exception-monitor/api/v1/settings/general', [
            'version' => 0,
            'values' => ['display_name' => 'Rejected'],
        ])->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', 'expected-csrf-token')->patchJson('/exception-monitor/api/v1/settings/general', [
            'version' => 0,
            'values' => ['display_name' => 'Accepted'],
        ])->assertOk()->assertJsonPath('data.values.display_name', 'Accepted');
    }

    public function test_redaction_preview_is_authorized_bounded_and_does_not_persist_sample(): void
    {
        $this->authorize();
        $this->postJson('/exception-monitor/api/v1/privacy/preview', [
            'exception_monitor_private' => [
                'text' => 'token=private-value password="another value" special@example.test',
                'patterns' => ['~special@example\\.test~'],
            ],
        ])->assertOk()->assertJsonPath('exception_monitor_private.text', 'token=[REDACTED] password=[REDACTED] [REDACTED]');
        $this->assertDatabaseCount('exception_monitor_settings', 0);
        $this->postJson('/exception-monitor/api/v1/privacy/preview', [
            'exception_monitor_private' => ['text' => str_repeat('x', 16385)],
        ])->assertUnprocessable();
        $this->authorize(manage: false);
        $this->postJson('/exception-monitor/api/v1/privacy/preview', [
            'exception_monitor_private' => ['text' => 'private'],
        ])->assertForbidden();
    }
}
