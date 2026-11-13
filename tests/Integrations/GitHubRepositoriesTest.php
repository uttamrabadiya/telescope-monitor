<?php

namespace TelescopeMonitor\Tests\Integrations;

use Illuminate\Support\Facades\DB;
use TelescopeMonitor\Integrations\DnsResolver;
use TelescopeMonitor\Integrations\HttpResult;
use TelescopeMonitor\Integrations\HttpTransport;
use TelescopeMonitor\Tests\TestCase;

final class GitHubRepositoriesTest extends TestCase
{
    private object $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorize();
        config(['cache.default' => 'array']);
        $this->app->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $this->http = new class implements HttpTransport
        {
            public array $requests = [];
            public HttpResult $response;
            public function request(string $method, array $destination, array $headers, string $body): HttpResult
            {
                $this->requests[] = compact('method', 'destination', 'headers', 'body');
                return $this->response;
            }
        };
        $this->app->instance(HttpTransport::class, $this->http);
    }

    public function test_discovery_filters_ineligible_repositories_and_does_not_persist_credentials(): void
    {
        $repo = [
            'owner' => ['login' => 'Acme'],
            'name' => 'App',
            'private' => true,
            'archived' => false,
            'disabled' => false,
            'has_issues' => true,
        ];
        $this->http->response = new HttpResult(200, json_encode([$repo, array_replace($repo, ['archived' => true]), array_replace($repo, ['has_issues' => false])]));
        $response = $this->postJson('/telescope-monitor/api/v1/integrations/github/repositories', ['credential' => 'github_pat_PRIVATE']);
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('telescope_monitor_private.repositories.0.owner', 'acme')->assertJsonCount(1, 'telescope_monitor_private.repositories');
        $this->assertStringNotContainsString('github_pat_PRIVATE', $response->getContent());
        $this->assertSame('GET', $this->http->requests[0]['method']);
        $this->assertSame('https://api.github.com/user/repos?per_page=30&sort=full_name&page=1', $this->http->requests[0]['destination']['url']);
        $this->assertSame(1048576, $this->http->requests[0]['destination']['max_response_bytes']);
        $this->assertSame(0, DB::table('telescope_monitor_connections')->count());
        $this->assertSame(0, DB::table('telescope_monitor_secrets')->count());
    }

    public function test_pagination_is_locally_constructed_and_provider_errors_do_not_leak_response_bodies(): void
    {
        $this->http->response = new HttpResult(200, json_encode(array_fill(0, 30, [])), ['link' => '<https://evil.test>; rel="next"']);
        $this->postJson('/telescope-monitor/api/v1/integrations/github/repositories', ['credential' => 'private', 'page' => 2])->assertOk()->assertJsonPath('telescope_monitor_private.next_page', 3);
        $this->http->response = new HttpResult(401, 'provider-secret');
        $response = $this->postJson('/telescope-monitor/api/v1/integrations/github/repositories', ['credential' => 'private']);
        $response->assertUnprocessable()->assertJsonValidationErrors('credential');
        $this->assertStringNotContainsString('provider-secret', $response->getContent());
    }

    public function test_transport_failures_report_actionable_safe_messages(): void
    {
        foreach ([
            'response_too_large' => 'response limit',
            'timeout' => 'timed out',
            'tls' => 'TLS certificate',
            'connection_failed' => 'could not connect',
        ] as $failure => $expected) {
            $this->http->response = new HttpResult(failure: $failure);
            $this->postJson('/telescope-monitor/api/v1/integrations/github/repositories', ['credential' => 'private'])
                ->assertUnprocessable()->assertJsonValidationErrors('credential')
                ->assertSee($expected);
        }
    }

    public function test_discovery_requires_manage_access_and_validates_deployment_references(): void
    {
        $this->postJson('/telescope-monitor/api/v1/integrations/github/repositories', ['credential_reference' => 'unapproved'])->assertUnprocessable();
        $this->authorize(true, false);
        $this->postJson('/telescope-monitor/api/v1/integrations/github/repositories', ['credential' => 'private'])->assertForbidden();
        $this->assertCount(0, $this->http->requests);
    }
}
