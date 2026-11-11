<?php

namespace TelescopeMonitor\Tests\Capture;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Capture\CaptureProbes;
use TelescopeMonitor\Jobs\CaptureProbe;
use TelescopeMonitor\Tests\TestCase;

final class ProbeTest extends TestCase
{
    use CapturesExceptions;

    public function test_real_probe_is_idempotent_synthetic_and_never_creates_alert_events(): void
    {
        $this->authorize();
        config(['queue.default' => 'database']);
        Bus::fake();
        $key = (string) Str::uuid();
        $a = app(CaptureProbes::class)->request($key);
        $b = app(CaptureProbes::class)->request($key);
        $this->assertSame($a['id'], $b['id']);
        Bus::assertDispatched(CaptureProbe::class, 1);
        app(CaptureProbes::class)->execute($a['id'], 1);
        $this->process();
        $this->assertSame('completed', app(CaptureProbes::class)->status($a['id'])['state']);
        $this->assertSame('synthetic_test', DB::table('exception_monitor_occurrences')->value('mode'));
        $this->assertDatabaseCount('exception_monitor_alert_events', 0);
        app(CaptureProbes::class)->execute($a['id'], 1);
        $this->process();
        $this->assertDatabaseCount('exception_monitor_occurrences', 1);
    }

    public function test_probe_respects_paused_recording_and_revoked_access(): void
    {
        $this->authorize();
        $a = app(CaptureProbes::class)->request((string) Str::uuid());
        Telescope::stopRecording();
        app(CaptureProbes::class)->execute($a['id'], 1);
        $this->assertSame('recording_disabled', app(CaptureProbes::class)->status($a['id'])['state']);
        Telescope::startRecording(false);
        $b = app(CaptureProbes::class)->request((string) Str::uuid());
        $this->authorize(manage: false);
        app(CaptureProbes::class)->execute($b['id'], 1);
        $this->assertSame('cancelled', app(CaptureProbes::class)->status($b['id'])['state']);
        $this->assertDatabaseCount('exception_monitor_capture_inbox', 0);
    }

    public function test_filtered_probe_does_not_bypass_host_policy_or_leak_trust_to_following_exception(): void
    {
        $this->authorize();
        Telescope::filter(fn () => false);
        $a = app(CaptureProbes::class)->request((string) Str::uuid());
        app(CaptureProbes::class)->execute($a['id'], 1);
        $this->assertSame('filtered_or_unstored', app(CaptureProbes::class)->status($a['id'])['state']);
        Telescope::$filterUsing = [];
        $this->capture();
        $this->process();
        $this->assertSame('live', DB::table('exception_monitor_occurrences')->value('mode'));
    }

    public function test_capture_api_enforces_manage_and_view_and_private_evidence_response(): void
    {
        $this->authorize(manage: false);
        $this->postJson('/exception-monitor/api/v1/capture/probes', ['client_key' => (string) Str::uuid()])->assertForbidden();
        $this->capture();
        $this->process();
        $id = DB::table('exception_monitor_occurrences')->value('public_id');
        $this->getJson('/exception-monitor/api/v1/capture/occurrences/'.$id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('exception_monitor_private.evidence_state', 'available');
        $this->getJson('/exception-monitor/api/v1/capture/occurrences/'.Str::uuid())->assertNotFound();
    }
}
