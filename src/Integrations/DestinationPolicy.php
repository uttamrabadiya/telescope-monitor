<?php

namespace TelescopeMonitor\Integrations;

use Illuminate\Validation\ValidationException;

final class DestinationPolicy
{
    public function __construct(private DnsResolver $dns) {}

    public function url(string $url, string $provider): array
    {
        $p = parse_url($url);
        $host = strtolower($p['host'] ?? '');
        $host = trim($host, '[]');
        $port = $p['port'] ?? 443;
        if (! $p || strlen($url) > 2048 || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || ! $host || preg_match('/[\x00-\x20\\\\]/', $url) || ! preg_match('/^[a-z0-9.:\-]+$/D', $host) || str_ends_with($host, '.')) {
            $this->invalid();
        }
        if ($provider === 'slack' && ($host !== 'hooks.slack.com' || $port !== 443 || isset($p['query']) || ! preg_match('~^/services/[A-Za-z0-9]+/[A-Za-z0-9]+/[A-Za-z0-9]+$~D', $p['path'] ?? ''))) {
            $this->invalid();
        }
        if ($provider === 'github' && ($host !== 'api.github.com' || $port !== 443)) {
            $this->invalid();
        }
        if ($provider === 'webhook' && ! in_array($port, config('exception-monitor.integrations.webhooks.ports', [443]), true)) {
            $this->invalid();
        }
        $url = 'https://'.(str_contains($host, ':') ? '['.$host.']' : $host).($port !== 443 ? ':'.$port : '').($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '');

        return ['host' => $host, 'port' => $port, 'url' => $url, 'display' => 'https://'.(str_contains($host, ':') ? '['.$host.']' : $host).($port !== 443 ? ':'.$port : '')];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['target' => 'Use a permitted HTTPS destination without credentials, fragments or unsupported ports.']);
    }

    private function inRange(string $ip, string $cidr): bool
    {
        [$network,$bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $a = @inet_pton($ip);
        $b = @inet_pton($network);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }$bits = $bits === null ? strlen($a) * 8 : filter_var($bits, FILTER_VALIDATE_INT);
        if ($bits === false || $bits < 0 || $bits > strlen($a) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;

        return substr($a, 0, $bytes) === substr($b, 0, $bytes) && (! $rest || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (255 << (8 - $rest))) === 0);
    }

    public function pin(string $url, string $provider): array
    {
        $p = $this->url($url, $provider);
        $ips = $this->dns->addresses($p['host']);
        if (! $ips || count($ips) > 32) {
            throw new \RuntimeException('destination_unresolved');
        }
        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            foreach (['100.64.0.0/10', '198.18.0.0/15', '192.0.0.0/24', '192.0.2.0/24', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '::ffff:0:0/96', '2001:db8::/32', 'ff00::/8', '2002::/16', '2001::/32', '64:ff9b::/96', '64:ff9b:1::/48', 'fec0::/10'] as $cidr) {
                if ($this->inRange($ip, $cidr)) {
                    $public = false;
                }
            }
            $allowed = false;
            if ($provider === 'webhook') {
                foreach ((config('exception-monitor.integrations.webhooks.private_targets', [])[$p['host']] ?? []) as $cidr) {
                    if ($this->inRange($ip, $cidr)) {
                        $allowed = true;
                    }
                }
            }
            if ($this->inRange($ip, '169.254.0.0/16') || $this->inRange($ip, 'fe80::/10') || (! $public && ! $allowed)) {
                throw new \RuntimeException('destination_blocked');
            }
        }

        return $p + ['ip' => $ips[0]];
    }

    public function repository(string $owner, string $repo): array
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,38}$/D', $owner) || ! preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $repo) || in_array($repo, ['.', '..'], true)) {
            throw ValidationException::withMessages(['repository' => 'Enter a GitHub owner and repository name, not a URL.']);
        }

        return ['owner' => strtolower($owner), 'repo' => strtolower($repo)];
    }
}
