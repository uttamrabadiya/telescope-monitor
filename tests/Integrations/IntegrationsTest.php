<?php

namespace TelescopeMonitor\Tests\Integrations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Alerts\DeliverySender;
use TelescopeMonitor\Integrations\Credentials;
use TelescopeMonitor\Integrations\DestinationPolicy;
use TelescopeMonitor\Integrations\DnsResolver;
use TelescopeMonitor\Integrations\HttpResult;
use TelescopeMonitor\Integrations\HttpTransport;
use TelescopeMonitor\Integrations\WebhookPayload;
use TelescopeMonitor\Models\Connection;
use TelescopeMonitor\Repositories\Contracts\DeliveryLedger;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class ControlledDns implements DnsResolver
{
    public array $ips = ['93.184.216.34'];

    public function addresses(string $host): array
    {
        return $this->ips;
    }
}
final class ControlledHttp implements HttpTransport
{
    public array $requests = [];

    public array $responses = [];

    public $during = null;

    public function request(string $method, array $destination, array $headers, string $body): HttpResult
    {
        $this->requests[] = compact('method', 'destination', 'headers', 'body') + ['transaction' => DB::transactionLevel(), 'recording' => Telescope::isRecording()];
        if ($this->during) {
            ($this->during)();
        }

        return array_shift($this->responses) ?? new HttpResult(200, 'ok');
    }
}
final class IntegrationsTest extends TestCase
{
    use CapturesExceptions;

    private ControlledDns $dns;

    private ControlledHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorize();
        config(['telescope-monitor.alerts.allowed_environments' => ['testing']]);
        $this->dns = new ControlledDns;
        $this->http = new ControlledHttp;
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->app->instance(HttpTransport::class, $this->http);
        $this->travelTo(CarbonImmutable::parse('2026-11-11T10:10:01Z'));
    }

    private function create(string $provider, array $extra = []): array
    {
        $base = ['provider' => $provider, 'name' => ucfirst($provider).' alerts', 'enabled' => true];
        if ($provider === 'github') {
            $base += ['owner' => 'acme', 'repo' => 'app', 'credential' => 'fine_grained_PRIVATE'];
        } else {
            $base['endpoint'] = $provider === 'slack' ? 'https://hooks.slack.com/services/T123/B123/PRIVATESECRET' : 'https://hooks.example.test/ingest';
        }

        return $this->postJson('/telescope-monitor/api/v1/integrations', ['client_key' => (string) Str::uuid()] + array_replace($base, $extra))->assertOk()->json('telescope_monitor_private');
    }

    private function command(string $id, string $action, array $v = [])
    {
        return $this->postJson('/telescope-monitor/api/v1/integrations/'.$id.'/'.$action, ['client_key' => (string) Str::uuid()] + $v);
    }

    private function rule(array $connections): void
    {
        $this->postJson('/telescope-monitor/api/v1/alerting/rules', [
            'client_key' => (string) Str::uuid(),
            'name' => 'Operations',
            'enabled' => true,
            'events' => ['error.created', 'error.regressed'],
            'conditions' => ['environments' => ['testing'], 'class' => null, 'origin' => null, 'tag' => null],
            'connections' => $connections,
            'threshold_count' => null,
            'window_seconds' => null,
            'cooldown_seconds' => 0,
        ])->assertOk();
    }

    private function send(): void
    {
        foreach (app(DeliveryLedger::class)->due(100) as $d) {
            app(DeliverySender::class)->send($d['id'], $d['generation']);
        }
    }

    private function issue(): string
    {
        $this->capture('secret=hidden token=PRIVATE');
        $this->process();

        return DB::table('telescope_monitor_issues')->value('public_id');
    }

    private function preview(string $issue, string $connection, array $extra = []): array
    {
        $v = array_replace([
            'connection' => $connection,
            'preview_key' => (string) Str::uuid(),
            'title' => 'Investigate this exception',
            'labels' => [],
        ], $extra);
        $p = $this->postJson('/telescope-monitor/api/v1/issues/'.$issue.'/github/preview', $v)->assertOk()->json('telescope_monitor_private');

        return $v + ['preview_digest' => $p['digest'], 'link_version' => $p['link_version']];
    }

    private function createTicket(string $issue, array $preview, ?string $key = null)
    {
        return $this->postJson('/telescope-monitor/api/v1/issues/'.$issue.'/github/create', ['client_key' => $key ?? (string) Str::uuid()] + $preview);
    }

    private function ticket(int $number = 42, array $extra = []): HttpResult
    {
        return new HttpResult(201, json_encode(array_replace(['number' => $number, 'html_url' => 'https://github.com/acme/app/issues/'.$number, 'state' => 'open'], $extra)));
    }

    public function test_slack_is_escaped_throttled_and_secrets_never_enter_resources_or_attempts(): void
    {
        $a = $this->create('slack');
        $this->rule([$a['id']]);
        $this->capture('<!channel> <@U123> & secret=TOPSECRET');
        $this->process();
        $this->send();
        $r = $this->http->requests[0];
        $body = json_decode($r['body'], true);
        $this->assertFalse($body['mrkdwn']);
        $this->assertFalse($body['link_names']);
        $this->assertSame('header', $body['blocks'][0]['type']);
        $this->assertStringContainsString('First seen', $r['body']);
        $this->assertStringContainsString('Open investigation', $r['body']);
        $this->assertStringNotContainsString('<!channel>', $r['body']);
        $this->assertStringContainsString('&lt;!channel&gt;', $r['body']);
        $this->assertFalse($r['recording']);
        $this->assertSame(0, $r['transaction']);
        $config = $this->getJson('/telescope-monitor/api/v1/integrations')->assertOk()->getContent();
        $this->assertStringNotContainsString('PRIVATESECRET', $config);
        $this->assertStringNotContainsString('TOPSECRET', json_encode(DB::table('telescope_monitor_delivery_attempts')->get()));
    }

    public function test_slack_context_freezes_on_retry_and_stricter_privacy_prevents_resending_it(): void
    {
        $a = $this->create('slack');
        $this->rule([$a['id']]);
        $this->issue();
        $evidence = DB::table('telescope_monitor_occurrence_evidence')->first();
        $payload = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($evidence->payload_ciphertext), true);
        $payload['execution'] = ['method' => 'GET', 'path_template' => '/widgets/{widget}', 'path' => '/widgets/PRIVATE-ID'];
        $json = json_encode($payload);
        DB::table('telescope_monitor_occurrence_evidence')->where('id', $evidence->id)->update([
            'payload_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString($json),
            'payload_digest' => hash('sha256', $json),
        ]);
        $this->http->responses = [new HttpResult(503), new HttpResult(503)];
        $this->send();
        $first = $this->http->requests[0]['body'];
        $this->assertStringContainsString('widgets', $first);
        $this->assertStringNotContainsString('PRIVATE-ID', $first);
        DB::table('telescope_monitor_occurrence_evidence')->delete();
        $this->travel(61)->seconds();
        $this->send();
        $this->assertSame($first, $this->http->requests[1]['body']);
        config(['telescope-monitor.locked' => ['privacy.retain_execution_context' => false]]);
        $this->travel(601)->seconds();
        $this->send();
        $this->assertCount(2, $this->http->requests);
        $this->assertSame('export_policy_changed', DB::table('telescope_monitor_deliveries')->value('cancellation_reason'));
    }

    public function test_destination_throttle_serializes_different_issues_and_waits_do_not_consume_attempts(): void
    {
        $a = $this->create('slack');
        $this->rule([$a['id']]);
        $this->capture('first');
        $this->process();
        $this->capture('second', null, null, \LogicException::class);
        $this->process();
        $this->send();
        $this->assertCount(1, $this->http->requests);
        $this->assertSame(1, DB::table('telescope_monitor_deliveries')->where('status', 'pending')->count());
        $this->assertSame(1, DB::table('telescope_monitor_delivery_attempts')->count());
        $this->travel(2)->seconds();
        $this->send();
        $this->assertCount(2, $this->http->requests);
    }

    public function test_provider_throttling_honors_retry_after_and_hides_response_body(): void
    {
        $a = $this->create('slack');
        $this->rule([$a['id']]);
        $this->http->responses = [new HttpResult(429, 'token=PRIVATE_PROVIDER', ['retry-after' => '600'])];
        $this->issue();
        $this->send();
        $d = DB::table('telescope_monitor_deliveries')->first();
        $this->assertSame('retrying', $d->status);
        $this->assertSame('2026-11-11 10:20:01.000000', $d->eligible_at);
        $this->assertStringNotContainsString('PRIVATE_PROVIDER', json_encode(DB::table('telescope_monitor_delivery_attempts')->get()));
        $this->assertSame(429, DB::table('telescope_monitor_delivery_attempts')->value('provider_status'));
    }

    public function test_webhook_retries_preserve_exact_bytes_and_logical_ids_but_refresh_timestamp_signature(): void
    {
        $a = $this->create('webhook');
        $this->rule([$a['id']]);
        $this->http->responses = [new HttpResult(503, 'private'), new HttpResult(204)];
        $this->issue();
        $this->send();
        $this->travel(61)->seconds();
        $this->send();
        [$first,$second] = $this->http->requests;
        $this->assertSame($first['body'], $second['body']);
        $this->assertSame($first['headers']['X-Telescope-Monitor-Delivery'], $second['headers']['X-Telescope-Monitor-Delivery']);
        $this->assertNotSame($first['headers']['X-Telescope-Monitor-Signature'], $second['headers']['X-Telescope-Monitor-Signature']);
        $this->assertTrue(WebhookPayload::verify($second['body'], $second['headers'], [$a['once']['key_id'] => $a['once']['secret']], now('UTC')->timestamp));
        $this->assertFalse(WebhookPayload::verify($second['body'].' ', $second['headers'], [$a['once']['key_id'] => $a['once']['secret']], now('UTC')->timestamp));
        $payload = json_decode($first['body'], true);
        $this->assertSame(1, $payload['schema_version']);
        $this->assertSame('error.created', $payload['event']);
        $this->assertNotEmpty($payload['event_id']);
        $this->assertNotEmpty($payload['application']['id']);
        $this->assertNotEmpty($payload['error']['id']);
        $this->assertStringNotContainsString('PRIVATE', $first['body']);
    }

    public function test_receiver_rejects_stale_timestamps_wrong_keys_and_mutated_signatures(): void
    {
        $e = ['event' => 'monitor.test', 'delivery_id' => (string) Str::uuid()];
        $h = WebhookPayload::headers('{"test":true}', $e, 'key', 'shared', 1000);
        $this->assertTrue(WebhookPayload::verify('{"test":true}', $h, ['key' => 'shared'], 1300));
        $this->assertFalse(WebhookPayload::verify('{"test":true}', $h, ['key' => 'shared'], 1301));
        $this->assertFalse(WebhookPayload::verify('{"test":true}', $h, ['different' => 'shared'], 1000));
        $h['X-Telescope-Monitor-Signature'] = 'sha256='.str_repeat('0', 64);
        $this->assertFalse(WebhookPayload::verify('{"test":true}', $h, ['key' => 'shared'], 1000));
    }

    public function test_signing_rotation_requires_staging_test_and_explicit_activation_and_shows_secret_once(): void
    {
        $a = $this->create('webhook');
        $key = (string) Str::uuid();
        $url = '/telescope-monitor/api/v1/integrations/'.$a['id'].'/key.stage';
        $p = ['client_key' => $key, 'version' => 1];
        $stage = $this->postJson($url, $p)->assertOk()->json('telescope_monitor_private');
        $this->postJson($url, $p)->assertOk()->assertJsonMissingPath('telescope_monitor_private.once');
        $this->command($a['id'], 'test', ['signing_key_id' => $stage['once']['key_id']])->assertStatus(202);
        $this->send();
        $r = $this->http->requests[0];
        $this->assertSame($stage['once']['key_id'], $r['headers']['X-Telescope-Monitor-Key-Id']);
        $this->assertTrue(json_decode($r['body'], true)['staged_key_test']);
        $c = Connection::query()->where('public_id', $a['id'])->first();
        $this->assertSame($a['once']['key_id'], app(Credentials::class)->target($c)['signing_key_id']);
        $this->command($a['id'], 'key.activate', ['version' => 2, 'key_id' => $stage['once']['key_id']])->assertOk();
        $this->assertSame($stage['once']['key_id'], app(Credentials::class)->target($c->fresh())['signing_key_id']);
        $this->travel(25)->hours();
        app(Credentials::class)->cleanup();
        $this->assertNull(DB::table('telescope_monitor_secrets')->where('key_id', $a['once']['key_id'])->value('ciphertext'));
    }

    public function test_url_policy_rejects_credentials_fragments_slack_lookalikes_and_unsupported_ports(): void
    {
        $this->assertSame('https://hooks.example.test/events%20queue', app(DestinationPolicy::class)->url('https://hooks.example.test/events%20queue', 'webhook')['url']);
        foreach ([
            'http://hooks.example.test/a',
            'https://user:secret@hooks.example.test/a',
            'https://hooks.example.test/a#x',
            'https://hooks.example.test:8443/a',
        ] as $url) {
            $this->postJson('/telescope-monitor/api/v1/integrations', ['client_key' => (string) Str::uuid(), 'provider' => 'webhook', 'name' => 'Bad', 'enabled' => true, 'endpoint' => $url])->assertUnprocessable();
        }
        $this->postJson('/telescope-monitor/api/v1/integrations', [
            'client_key' => (string) Str::uuid(),
            'provider' => 'slack',
            'name' => 'Bad',
            'enabled' => true,
            'endpoint' => 'https://hooks.slack.com.evil.test/services/T/B/S',
        ])->assertUnprocessable();
    }

    public function test_dns_rebinding_and_mixed_private_records_stop_requests_and_redirects_are_not_followed(): void
    {
        $a = $this->create('webhook');
        $this->rule([$a['id']]);
        $this->issue();
        $this->dns->ips = ['93.184.216.34', '127.0.0.1'];
        $this->send();
        $this->assertCount(0, $this->http->requests);
        $b = $this->create('webhook', ['endpoint' => 'https://another.example.test/ingest']);
        $this->dns->ips = ['93.184.216.34'];
        $this->command($b['id'], 'test')->assertStatus(202);
        $this->http->responses = [new HttpResult(302, '', ['location' => 'https://evil.test'])];
        $this->send();
        $this->assertCount(1, $this->http->requests);
        $this->assertSame('93.184.216.34', $this->http->requests[0]['destination']['ip']);
        $this->assertSame('failed', DB::table('telescope_monitor_deliveries')->orderByDesc('id')->value('status'));
    }

    public function test_private_destination_requires_exact_deployment_host_and_cidr_policy(): void
    {
        $this->dns->ips = ['10.12.3.4'];
        config(['telescope-monitor.integrations.webhooks.private_targets' => ['hooks.internal' => ['10.12.0.0/16']]]);
        $this->assertSame('10.12.3.4', app(DestinationPolicy::class)->pin('https://hooks.internal/x', 'webhook')['ip']);
        $this->expectException(\RuntimeException::class);
        app(DestinationPolicy::class)->pin('https://other.internal/x', 'webhook');
    }

    public function test_replacing_endpoint_never_redirects_existing_pending_work(): void
    {
        $a = $this->create('slack');
        $this->rule([$a['id']]);
        $this->issue();
        $b = $this->create('slack', ['endpoint' => 'https://hooks.slack.com/services/T2/B2/NEWSECRET', 'replaces' => $a['id'], 'replaces_version' => 1]);
        $this->send();
        $this->assertCount(0, $this->http->requests);
        $this->assertSame('cancelled', DB::table('telescope_monitor_deliveries')->value('status'));
        $this->assertNotSame($a['id'], $b['id']);
    }

    public function test_github_connection_check_is_read_only_and_does_not_claim_write_permissions(): void
    {
        $c = $this->create('github');
        $this->http->responses = [new HttpResult(200, '{"full_name":"acme/app"}'), new HttpResult(200, '[{"name":"bug"}]')];
        $this->command($c['id'], 'test')->assertStatus(202);
        $this->send();
        $this->assertSame(['GET', 'GET'], array_column($this->http->requests, 'method'));
        $this->assertDatabaseCount('telescope_monitor_linked_issues', 0);
        $this->assertSame(['bug'], json_decode(DB::table('telescope_monitor_connections')->value('options'), true)['known_labels']);
        $this->assertStringNotContainsString('fine_grained_PRIVATE', $this->getJson('/telescope-monitor/api/v1/integrations')->getContent());
    }

    public function test_manual_export_freezes_reviewed_bytes_and_duplicate_confirmation_reuses_reservation(): void
    {
        $c = $this->create('github');
        $issue = $this->issue();
        $p = $this->preview($issue, $c['id']);
        $this->assertDatabaseCount('telescope_monitor_deliveries', 0);
        $key = (string) Str::uuid();
        $result = $this->createTicket($issue, $p, $key)->assertOk()->json('telescope_monitor_private');
        $this->createTicket($issue, $p, $key)->assertOk();
        $this->createTicket($issue, $p)->assertOk();
        $this->assertDatabaseCount('telescope_monitor_linked_issues', 1);
        $this->assertDatabaseCount('telescope_monitor_deliveries', 1);
        $this->http->responses = [$this->ticket()];
        $this->send();
        $this->assertSame('linked', DB::table('telescope_monitor_linked_issues')->value('state'));
        $wire = json_decode($this->http->requests[0]['body'], true);
        $this->assertStringNotContainsString('PRIVATE', $wire['body']);
        $this->assertStringContainsString('telescope-monitor:', $wire['body']);
        $this->assertSame('Investigate this exception', $wire['title']);
        $this->assertSame(0, $this->http->requests[0]['transaction']);
        $this->assertFalse($this->http->requests[0]['recording']);
        $this->assertSame('sent', DB::table('telescope_monitor_deliveries')->value('status'));
    }

    public function test_changed_export_preview_is_rejected_without_a_delivery(): void
    {
        $c = $this->create('github');
        $issue = $this->issue();
        $p = $this->preview($issue, $c['id']);
        $p['title'] = 'Different unreviewed title';
        $this->createTicket($issue, $p)->assertConflict();
        $this->assertDatabaseCount('telescope_monitor_deliveries', 0);
    }

    public function test_lost_github_response_enters_review_and_missing_search_never_retries(): void
    {
        $c = $this->create('github');
        $issue = $this->issue();
        $this->createTicket($issue, $this->preview($issue, $c['id']))->assertOk();
        $this->http->responses = [new HttpResult];
        $this->send();
        $this->assertSame('needs_review', DB::table('telescope_monitor_deliveries')->value('status'));
        $this->assertSame(1, app(DeliveryLedger::class)->status()['needs_review']);
        $link = DB::table('telescope_monitor_linked_issues')->first();
        $this->travel(3)->minutes();
        $this->send();
        $this->assertCount(1, $this->http->requests);
        $this->http->responses = [new HttpResult(200, '[]')];
        $this->postJson('/telescope-monitor/api/v1/issues/'.$issue.'/github/inspect', ['client_key' => (string) Str::uuid(), 'link' => $link->public_id, 'version' => $link->row_version])->assertOk()->assertJsonPath('telescope_monitor_private.complete', false);
        $this->assertSame('needs_review', DB::table('telescope_monitor_linked_issues')->value('state'));
        $this->assertSame(['POST', 'GET'], array_column($this->http->requests, 'method'));
    }

    public function test_expired_creation_lease_never_issues_another_provider_write(): void
    {
        $c = $this->create('github');
        $issue = $this->issue();
        $this->createTicket($issue, $this->preview($issue, $c['id']))->assertOk();
        $d = DB::table('telescope_monitor_deliveries')->first();
        $claim = app(DeliveryLedger::class)->claim($d->id, 1, true);
        $this->assertNotNull($claim);
        $this->travel(121)->seconds();
        $this->send();
        $this->assertSame('needs_review', DB::table('telescope_monitor_linked_issues')->value('state'));
        $this->assertCount(0, $this->http->requests);
        $this->assertSame('uncertain', DB::table('telescope_monitor_delivery_attempts')->value('outcome'));
    }

    public function test_acceptance_followed_by_local_commit_failure_recovers_to_review_not_recreation(): void
    {
        $c = $this->create('github');
        $issue = $this->issue();
        $this->createTicket($issue, $this->preview($issue, $c['id']))->assertOk();
        $this->http->responses = [$this->ticket()];
        $armed = false;
        $this->http->during = function () use (&$armed) {
            $armed = true;
        };
        DB::listen(function ($q) use (&$armed) {
            if ($armed && preg_match('/^update [`"]telescope_monitor_deliveries[`"]/', $q->sql)) {
                $armed = false;
                throw new \RuntimeException('simulated outcome commit loss');
            }
        });
        try {
            $this->send();
            $this->fail('Expected simulated local commit loss');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated outcome commit loss', $e->getMessage());
        }$this->travel(121)->seconds();
        $this->send();
        $this->assertCount(1, $this->http->requests);
        $this->assertSame('needs_review', DB::table('telescope_monitor_linked_issues')->value('state'));
    }

    public function test_revoked_initiator_cancels_a_queued_manual_export(): void
    {
        $c = $this->create('github');
        $issue = $this->issue();
        $this->createTicket($issue, $this->preview($issue, $c['id']))->assertOk();
        $this->authorize(operate: false);
        $this->send();
        $this->assertCount(0, $this->http->requests);
        $this->assertSame('cancelled', DB::table('telescope_monitor_deliveries')->value('status'));
    }

    public function test_link_validation_rejects_pull_requests_and_unrelated_repositories(): void
    {
        $c = $this->create('github');
        $issue = $this->issue();
        $url = '/telescope-monitor/api/v1/issues/'.$issue.'/github/link';
        $this->postJson($url, [
            'client_key' => (string) Str::uuid(),
            'connection' => $c['id'],
            'version' => 0,
            'number' => 'https://github.com/other/repo/issues/1',
        ])->assertUnprocessable();
        $this->http->responses = [$this->ticket(42, ['pull_request' => ['url' => 'private']])];
        $this->postJson($url, ['client_key' => (string) Str::uuid(), 'connection' => $c['id'], 'version' => 0, 'number' => '42'])->assertConflict();
        $this->assertDatabaseCount('telescope_monitor_linked_issues', 0);
    }

    public function test_explicit_link_refresh_and_unlink_never_write_or_close_remote_tickets(): void
    {
        $c = $this->create('github');
        $issue = $this->issue();
        $this->http->responses = [$this->ticket()];
        $l = $this->postJson('/telescope-monitor/api/v1/issues/'.$issue.'/github/link', ['client_key' => (string) Str::uuid(), 'connection' => $c['id'], 'version' => 0, 'number' => '42'])->assertOk()->json('telescope_monitor_private');
        $this->http->responses = [new HttpResult(404)];
        $r = $this->postJson('/telescope-monitor/api/v1/issues/'.$issue.'/github/refresh', ['client_key' => (string) Str::uuid(), 'link' => $l['id'], 'version' => $l['version']])->assertOk()->json('telescope_monitor_private');
        $this->assertSame('unavailable', $r['state']);
        $this->assertSame('open', DB::table('telescope_monitor_linked_issues')->value('last_known_remote_state'));
        $this->postJson('/telescope-monitor/api/v1/issues/'.$issue.'/github/unlink', ['client_key' => (string) Str::uuid(), 'link' => $l['id'], 'version' => $r['version']])->assertOk();
        $this->assertSame(['GET', 'GET'], array_column($this->http->requests, 'method'));
        $this->assertSame('unlinked', DB::table('telescope_monitor_linked_issues')->value('state'));
    }

    public function test_opt_in_automatic_creation_only_for_live_new_errors_and_keeps_association_on_regression(): void
    {
        $c = $this->create('github', ['automatic' => true, 'environments' => ['testing']]);
        $issue = $this->issue();
        $this->assertDatabaseCount('telescope_monitor_linked_issues', 1);
        $this->http->responses = [$this->ticket()];
        $this->send();
        $row = DB::table('telescope_monitor_issues')->first();
        $this->postJson('/telescope-monitor/api/v1/issues/'.$issue.'/actions', ['client_key' => (string) Str::uuid(), 'version' => $row->state_version, 'action' => 'resolve'])->assertOk();
        $this->travel(2)->seconds();
        $this->capture('regressed');
        $this->process();
        $this->send();
        $this->assertCount(1, $this->http->requests);
        $this->assertDatabaseCount('telescope_monitor_linked_issues', 1);
    }

    public function test_runtime_credential_rotation_uses_current_secret_without_redirecting_repository(): void
    {
        $c = $this->create('github');
        $issue = $this->issue();
        $this->createTicket($issue, $this->preview($issue, $c['id']))->assertOk();
        $this->command($c['id'], 'secret.replace', ['version' => 1, 'credential' => 'new_PRIVATE_TOKEN'])->assertOk();
        $this->http->responses = [$this->ticket()];
        $this->send();
        $this->assertSame('Bearer new_PRIVATE_TOKEN', $this->http->requests[0]['headers']['Authorization']);
        $this->assertSame('https://api.github.com/repos/acme/app/issues', $this->http->requests[0]['destination']['url']);
    }

    public function test_viewers_cannot_configure_or_export_and_history_never_discloses_credentials(): void
    {
        $c = $this->create('github');
        $issue = $this->issue();
        $this->authorize(operate: false, manage: false);
        $this->command($c['id'], 'test')->assertForbidden();
        $this->postJson('/telescope-monitor/api/v1/issues/'.$issue.'/github/preview', ['connection' => $c['id'], 'preview_key' => (string) Str::uuid()])->assertForbidden();
        $body = $this->getJson('/telescope-monitor/api/v1/integrations')->assertOk()->getContent();
        $this->assertStringNotContainsString('fine_grained_PRIVATE', $body);
        $this->assertStringNotContainsString('ciphertext', $body);
    }

    public function test_recreation_requires_explicit_review_and_gets_a_new_marker_and_identity(): void
    {
        $c = $this->create('github');
        $issue = $this->issue();
        $old = $this->preview($issue, $c['id']);
        $this->createTicket($issue, $old)->assertOk();
        $this->http->responses = [new HttpResult];
        $this->send();
        $new = $this->preview($issue, $c['id']);
        $url = '/telescope-monitor/api/v1/issues/'.$issue.'/github/recreate';
        $this->postJson($url, ['client_key' => (string) Str::uuid()] + $new)->assertConflict();
        $this->postJson($url, ['client_key' => (string) Str::uuid(), 'confirm_no_ticket' => true] + $new)->assertOk();
        $this->http->responses = [$this->ticket(43)];
        $this->send();
        $this->assertDatabaseCount('telescope_monitor_deliveries', 2);
        $this->assertNotSame($old['preview_key'], $new['preview_key']);
        $this->assertSame((int) DB::table('telescope_monitor_deliveries')->where('public_id', $old['preview_key'])->value('id'), (int) DB::table('telescope_monitor_deliveries')->where('public_id', $new['preview_key'])->value('parent_delivery_id'));
        $this->assertSame(43, DB::table('telescope_monitor_linked_issues')->value('remote_number'));
        $this->assertSame(1, DB::table('telescope_monitor_activity')->where('event_type', 'integration.github.recreate')->count());
    }

    public function test_definitive_github_rejections_and_throttling_do_not_become_ambiguous_creation(): void
    {
        $c = $this->create('github');
        $issue = $this->issue();
        $this->createTicket($issue, $this->preview($issue, $c['id']))->assertOk();
        $this->http->responses = [new HttpResult(429, 'private', ['retry-after' => '900'])];
        $this->send();
        $this->assertSame('retrying', DB::table('telescope_monitor_deliveries')->value('status'));
        $this->assertSame('reserved', DB::table('telescope_monitor_linked_issues')->value('state'));
        $this->travel(901)->seconds();
        $this->http->responses = [new HttpResult(422, 'private')];
        $this->send();
        $this->assertSame('failed', DB::table('telescope_monitor_deliveries')->value('status'));
        $this->assertSame(2, DB::table('telescope_monitor_delivery_attempts')->count());
    }

    public function test_later_privacy_cancels_reviewed_github_export_before_provider_io(): void
    {
        $c = $this->create('github');
        $issue = $this->issue();
        $this->createTicket($issue, $this->preview($issue, $c['id'], ['title' => 'Investigate confidential']))->assertOk();
        config(['telescope-monitor.locked' => ['privacy.redact_patterns' => ['/confidential/']]]);
        $this->send();
        $this->assertCount(0, $this->http->requests);
        $this->assertSame('export_policy_changed', DB::table('telescope_monitor_deliveries')->value('cancellation_reason'));
    }

    public function test_webhook_selected_events_apply_during_planning_and_current_send_policy(): void
    {
        $c = $this->create('webhook', ['events' => ['error.regressed']]);
        $this->rule([$c['id']]);
        $this->issue();
        $this->assertDatabaseCount('telescope_monitor_deliveries', 0);
        $this->patchJson('/telescope-monitor/api/v1/integrations/'.$c['id'], [
            'client_key' => (string) Str::uuid(),
            'version' => 1,
            'name' => 'Webhook',
            'enabled' => true,
            'events' => ['error.created'],
        ])->assertOk();
        $this->capture('other', null, null, \LogicException::class);
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_deliveries', 1);
        $this->patchJson('/telescope-monitor/api/v1/integrations/'.$c['id'], [
            'client_key' => (string) Str::uuid(),
            'version' => 2,
            'name' => 'Webhook',
            'enabled' => true,
            'events' => ['error.regressed'],
        ])->assertOk();
        $this->send();
        $this->assertCount(0, $this->http->requests);
        $this->assertSame('cancelled', DB::table('telescope_monitor_deliveries')->value('status'));
    }

    public function test_deployment_references_are_allowlisted_and_not_copied_into_database_ciphertext(): void
    {
        config([
            'telescope-monitor.integrations.credentials' => ['repository-key' => ['purpose' => 'github_token', 'value' => 'DEPLOYMENT_PRIVATE']],
        ]);
        $c = $this->create('github', ['credential' => null, 'credential_reference' => 'repository-key']);
        $this->assertNull(DB::table('telescope_monitor_secrets')->where('secret_name', 'github_token')->value('ciphertext'));
        $issue = $this->issue();
        $this->createTicket($issue, $this->preview($issue, $c['id']))->assertOk();
        config(['telescope-monitor.integrations.credentials.repository-key.value' => 'ROTATED_PRIVATE']);
        $this->http->responses = [$this->ticket()];
        $this->send();
        $this->assertSame('Bearer ROTATED_PRIVATE', $this->http->requests[0]['headers']['Authorization']);
        $this->command($c['id'], 'secret.replace', ['version' => 1, 'credential' => 'new-controlled-token'])->assertOk();
        $this->travel(3)->minutes();
        app(Credentials::class)->cleanup();
        $this->assertSame('retired', DB::table('telescope_monitor_secrets')->where('deployment_reference', 'repository-key')->value('state'));
        $this->postJson('/telescope-monitor/api/v1/integrations', [
            'client_key' => (string) Str::uuid(),
            'provider' => 'github',
            'name' => 'Unknown',
            'enabled' => true,
            'owner' => 'acme',
            'repo' => 'other',
            'credential_reference' => 'ARBITRARY_ENV',
        ])->assertUnprocessable();
    }

    public function test_automatic_creation_excludes_synthetic_and_historical_reconciliation(): void
    {
        $this->create('github', ['automatic' => true, 'environments' => ['testing']]);
        $this->capture();
        DB::table('telescope_monitor_capture_inbox')->where('state', 'pending')->update(['mode' => 'reconciliation']);
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_linked_issues', 0);
        $this->capture(class: 'SyntheticClass');
        DB::table('telescope_monitor_capture_inbox')->where('state', 'pending')->update(['mode' => 'synthetic_test']);
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_linked_issues', 0);
    }

    public function test_expired_unattempted_or_definitively_failed_creation_releases_a_stale_reservation(): void
    {
        $c = $this->create('github');
        $issue = $this->issue();
        $this->createTicket($issue, $this->preview($issue, $c['id']))->assertOk();
        $this->travel(25)->hours();
        app(DeliveryLedger::class)->prune(100);
        $this->assertSame('unavailable', DB::table('telescope_monitor_linked_issues')->value('state'));
        $this->assertSame('cancelled', DB::table('telescope_monitor_deliveries')->value('status'));
        $this->createTicket($issue, $this->preview($issue, $c['id']))->assertOk();
        $this->http->responses = [new HttpResult(422)];
        $this->send();
        $this->assertSame('failed', DB::table('telescope_monitor_deliveries')->latest('id')->value('status'));
        $this->travel(25)->hours();
        app(DeliveryLedger::class)->prune(100);
        $this->assertSame('unavailable', DB::table('telescope_monitor_linked_issues')->value('state'));
        $this->assertCount(1, $this->http->requests);
    }
    public function test_adding_a_disconnected_repository_reconnects_it_with_the_new_token(): void
    {
        $first = $this->create('github');
        $this->command($first['id'], 'test')->assertStatus(202);
        $this->deleteJson('/telescope-monitor/api/v1/integrations/'.$first['id'], [
            'client_key' => (string) Str::uuid(),
            'version' => $first['version'],
        ])->assertOk();
        $this->assertSame('cancelled', DB::table('telescope_monitor_deliveries')->value('status'));
        $again = $this->create('github', ['credential' => 'replacement_TOKEN', 'name' => 'Reconnected repo']);
        $this->assertSame($first['id'], $again['id']);
        $this->assertDatabaseCount('telescope_monitor_connections', 1);
        $connection = Connection::query()->firstOrFail();
        $this->assertTrue($connection->enabled);
        $this->assertNull($connection->disconnected_at);
        $this->assertSame('Reconnected repo', $connection->display_name);
        $this->assertSame('replacement_TOKEN', app(Credentials::class)->target($connection)['token']);
        $this->assertSame('cancelled', DB::table('telescope_monitor_deliveries')->value('status'));
        $this->assertSame(1, DB::table('telescope_monitor_secrets')->where('state', 'active')->where('secret_name', 'github_token')->count());
        $this->command($again['id'], 'test')->assertStatus(202);
        $this->http->responses = [new HttpResult(200, '{"full_name":"acme/app"}'), new HttpResult(200, '[]')];
        $this->send();
        $this->assertSame('Bearer replacement_TOKEN', $this->http->requests[0]['headers']['Authorization']);
        $this->assertSame('sent', DB::table('telescope_monitor_deliveries')->orderByDesc('id')->value('status'));
    }

    public function test_enabling_a_disconnected_connection_clears_disconnected_status(): void
    {
        foreach (['github', 'slack', 'webhook'] as $provider) {
            $c = $this->create($provider);
            $disconnected = $this->deleteJson('/telescope-monitor/api/v1/integrations/'.$c['id'], [
                'client_key' => (string) Str::uuid(),
                'version' => $c['version'],
            ])->assertOk()->json('telescope_monitor_private');
            $this->patchJson('/telescope-monitor/api/v1/integrations/'.$c['id'], [
                'client_key' => (string) Str::uuid(),
                'version' => $disconnected['version'],
                'name' => 'Restored',
                'enabled' => true,
            ])->assertOk();
            $connection = Connection::query()->where('public_id', $c['id'])->firstOrFail();
            $this->assertTrue($connection->enabled);
            $this->assertNull($connection->disconnected_at);
        }
    }

}
