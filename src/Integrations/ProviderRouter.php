<?php

namespace TelescopeMonitor\Integrations;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Alerts\Envelope;
use TelescopeMonitor\Alerts\HostEmailProvider;
use TelescopeMonitor\Alerts\NotificationProvider;
use TelescopeMonitor\Alerts\Outcome;

final class ProviderRouter implements NotificationProvider
{
    public function __construct(private HostEmailProvider $mail, private DestinationPolicy $policy, private HttpTransport $http) {}

    public function request(string $method, string $url, string $provider, array $headers = [], string $body = ''): HttpResult
    {
        $destination = $this->policy->pin($url, $provider);
        try {
            return Telescope::withoutRecording(fn () => $this->http->request($method, $destination, $headers + ['User-Agent' => 'Telescope-Monitor/1', 'Accept' => 'application/json'], $body));
        } catch (\Throwable) {
            return new HttpResult;
        }
    }

    public function github(string $method, array $target, string $path, ?array $body = null): HttpResult
    {
        return $this->request($method, 'https://api.github.com/repos/'.$target['owner'].'/'.$target['repo'].$path, 'github', ['Authorization' => 'Bearer '.$target['token'], 'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2026-03-10', 'Content-Type' => 'application/json'], $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function ticket(array $target, HttpResult $r): ?array
    {
        $j = $r->json();
        $n = $j['number'] ?? null;
        if ($r->status < 200 || $r->status >= 300 || ! is_int($n) || $n < 1 || isset($j['pull_request']) || ! in_array($j['state'] ?? null, ['open', 'closed'], true)) {
            return null;
        }
        $url = 'https://github.com/'.$target['owner'].'/'.$target['repo'].'/issues/'.$n;
        if (strtolower($j['html_url'] ?? '') !== strtolower($url)) {
            return null;
        }

        return ['number' => $n, 'url' => $url, 'state' => $j['state']];
    }

    private function retryAt(HttpResult $r): ?CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');
        $at = null;
        $after = $r->headers['retry-after'] ?? null;
        if (is_string($after) && ctype_digit($after)) {
            $at = $now->addSeconds(min(604800, (int) $after));
        } elseif (is_string($after)) {
            try {
                $at = CarbonImmutable::parse($after, 'UTC');
            } catch (\Throwable) {
            }
        }
        $reset = $r->headers['x-ratelimit-reset'] ?? null;
        if (is_string($reset) && ctype_digit($reset) && ($r->headers['x-ratelimit-remaining'] ?? null) === '0') {
            $resetAt = CarbonImmutable::createFromTimestamp((int) $reset, 'UTC');
            $at = $at ? $at->max($resetAt) : $resetAt;
        }

        return $at;
    }

    private function outcome(HttpResult $r, bool $creation = false): Outcome
    {
        if ($r->status === 429 || ($r->status === 403 && ($this->retryAt($r) !== null || ($r->headers['x-ratelimit-remaining'] ?? null) === '0'))) {
            return Outcome::remote('retryable', 'remote_throttled', $r->status, $this->retryAt($r) ?? CarbonImmutable::now('UTC')->addMinute());
        }
        if ($r->status >= 300 && $r->status < 500) {
            return Outcome::remote('permanent', 'remote_rejected', $r->status);
        }

        return Outcome::remote($creation ? 'uncertain' : 'retryable', $creation ? 'remote_acceptance_unknown' : 'remote_temporarily_unavailable', $r->status ?: null, $this->retryAt($r));
    }

    public function send(array $envelope, array $target): Outcome
    {
        if ($target['provider'] === 'email') {
            return $this->mail->send($envelope, $target);
        }
        try {
            if ($target['provider'] === 'github') {
                if ($envelope['test']) {
                    $r = $this->github('GET', $target, '');
                    if ($r->status !== 200) {
                        return $this->outcome($r);
                    }
                    $j = $r->json();
                    if (strtolower($j['full_name'] ?? '') !== $target['owner'].'/'.$target['repo']) {
                        return Outcome::remote('permanent', 'remote_rejected', 200);
                    }
                    $labels = $this->github('GET', $target, '/labels?per_page=100');
                    $names = [];
                    if ($labels->status === 200) {
                        foreach ($labels->json() as $label) {
                            if (is_array($label) && is_string($label['name'] ?? null)) {
                                $names[] = mb_substr($label['name'], 0, 100);
                            }
                        }
                    }

                    return Outcome::accepted(200, ['labels' => array_slice($names, 0, 100)]);
                }
                $r = $this->github('POST', $target, '/issues', ['title' => $envelope['github_title'], 'body' => $envelope['github_body'], 'labels' => $envelope['github_labels']]);
                $ticket = $this->ticket($target, $r);

                return $r->status === 201 && $ticket ? Outcome::accepted(201, $ticket) : ($r->status >= 200 && $r->status < 300 ? Outcome::remote('uncertain', 'remote_acceptance_unknown', $r->status) : $this->outcome($r, true));
            }
            if ($target['provider'] === 'webhook') {
                $body = $envelope['webhook_body'];
                $headers = WebhookPayload::headers($body, $envelope, $target['signing_key_id'], $target['signing_secret'], now('UTC')->timestamp);
            } else {
                $text = Envelope::text($envelope);
                $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
                $body = json_encode(['text' => $text, 'mrkdwn' => false, 'parse' => 'none', 'link_names' => false, 'unfurl_links' => false, 'unfurl_media' => false], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $headers = ['Content-Type' => 'application/json'];
            }
            $r = $this->request('POST', $target['url'], $target['provider'], $headers, $body);
            $accepted = $target['provider'] === 'slack' ? $r->status === 200 && trim($r->body) === 'ok' : $r->status >= 200 && $r->status < 300;

            return $accepted ? Outcome::accepted($r->status) : ($r->status >= 200 && $r->status < 300 ? Outcome::remote('permanent', 'remote_rejected', $r->status) : $this->outcome($r));
        } catch (ValidationException) {
            return Outcome::remote('permanent', 'destination_blocked');
        } catch (\Throwable) {
            return Outcome::remote($target['provider'] === 'github' && ! $envelope['test'] ? 'uncertain' : 'retryable', $target['provider'] === 'github' && ! $envelope['test'] ? 'remote_acceptance_unknown' : 'destination_unavailable');
        }
    }
}
