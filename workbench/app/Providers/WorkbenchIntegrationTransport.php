<?php

namespace Workbench\App\Providers;

use TelescopeMonitor\Integrations\DnsResolver;
use TelescopeMonitor\Integrations\HttpResult;
use TelescopeMonitor\Integrations\HttpTransport;

final class WorkbenchIntegrationTransport implements DnsResolver, HttpTransport
{
    public function addresses(string $host): array
    {
        return ['93.184.216.34'];
    }

    public function request(string $method, array $destination, array $headers, string $body): HttpResult
    {
        if (! app()->environment('local', 'workbench')) {
            return new HttpResult;
        }
        $url = $destination['url'];
        if (str_contains($url, 'api.github.com')) {
            if (! preg_match('~/repos/([^/]+)/([^/?]+)(.*)$~', $url, $m)) {
                return new HttpResult(404);
            }
            $repo = $m[1].'/'.$m[2];
            $tail = $m[3];
            if ($tail === '') {
                return new HttpResult(200, json_encode(['full_name' => $repo]));
            }
            if (str_starts_with($tail, '/labels')) {
                return new HttpResult(200, '[{"name":"bug"},{"name":"needs-triage"}]');
            }
            if ($method === 'POST' && $m[2] === 'uncertain') {
                return new HttpResult;
            }
            $ticket = ['number' => 42, 'html_url' => 'https://github.com/'.$repo.'/issues/42', 'state' => 'open'];

            return new HttpResult($method === 'POST' ? 201 : 200, json_encode(str_contains($tail, '?') ? [] : $ticket));
        }
        if (str_contains($url, 'REJECTED')) {
            return new HttpResult(429, 'controlled throttle', ['retry-after' => '300']);
        }

        return new HttpResult(str_contains($url, 'hooks.slack.com') ? 200 : 204, 'ok');
    }
}
