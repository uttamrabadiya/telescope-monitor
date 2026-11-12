<?php

namespace TelescopeMonitor\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use TelescopeMonitor\Models\Installation;

final class SourceMarker
{
    public function __construct(private CaptureKeys $keys, private Fingerprint $fingerprint) {}

    public function tags(Installation $installation, string $uuid, mixed $time, array $content, ?string $probe): array
    {
        $environment = app()->environment();
        if (strlen($environment) > 64 || $environment === '' || ! mb_check_encoding($environment, 'UTF-8')) {
            throw new CaptureFailure('environment_invalid');
        }
        $key = $this->keys->forInstallation($installation);
        $time = CarbonImmutable::parse($time)->utc()->format('Uu');
        $core = implode('|', [
            'em2c',
            $installation->public_id,
            $installation->generation,
            $installation->source_epoch,
            $time,
            $this->b64($environment),
            $key['id'],
        ]);
        $group = implode('|', [
            'em2g',
            (int) config('exception-monitor.capture.grouping_version', 1),
            $this->fingerprint->hash($environment, $content),
            $probe ?? '-',
        ]);
        $signature = $this->b64(hash_hmac('sha256', $uuid.'\0'.$core.'\0'.$group, $key['value'], true));
        if (strlen($core) > 255 || strlen($group.'|'.$signature) > 255) {
            throw new CaptureFailure('marker_too_large');
        }

        return [$core, $group.'|'.$signature];
    }

    public function read(Installation $i, string $uuid, array $tags, ?array $content = null): ?array
    {
        if (! Str::isUuid($uuid)) {
            return null;
        }
        if (array_filter($tags, fn ($tag) => is_string($tag) && str_starts_with($tag, 'em3|'))) {
            if ($content === null || ! ($m = app(SourceStamp::class)->read($uuid, $content, $tags))) {
                return null;
            }
            $group = $content;
            $group['file'] = $m['grouping_file'];
            return [
                'installation_id' => $i->id,
                'generation' => $i->generation,
                'source_epoch' => $i->source_epoch,
                'environment' => $m['environment'],
                'occurred_at' => $m['occurred_at'],
                'captured_at' => $m['captured_at'],
                'time_precision' => 'microsecond',
                'grouping_version' => $m['grouping_version'],
                'fingerprint_hash' => $this->fingerprint->hash($m['environment'], $group),
                'probe_id' => $m['probe_id'],
            ];
        }
        $core = array_values(array_filter($tags, fn ($t) => is_string($t) && str_starts_with($t, 'em2c|')));
        $group = array_values(array_filter($tags, fn ($t) => is_string($t) && str_starts_with($t, 'em2g|')));
        if (count($core) !== 1 || count($group) !== 1) {
            return null;
        }
        $c = explode('|', $core[0]);
        $g = explode('|', $group[0]);
        if (count($c) !== 7 || count($g) !== 5 || $c[1] !== $i->public_id || ! ctype_digit($c[2]) || ! Str::isUuid($c[3]) || ! ctype_digit($c[4]) || strlen($c[4]) !== 16 || ! ctype_digit($g[1]) || ! preg_match('/^[a-f0-9]{64}$/D', $g[2]) || ($g[3] !== '-' && ! Str::isUuid($g[3]))) {
            return null;
        }
        $key = $this->keys->byId($i->id, $c[6]);
        $body = implode('|', array_slice($g, 0, 4));
        if (! $key || ! hash_equals($this->b64(hash_hmac('sha256', $uuid.'\0'.$core[0].'\0'.$body, $key, true)), $g[4])) {
            return null;
        }
        $env = base64_decode(strtr($c[5], '-_', '+/'), true);
        if ($env === false || strlen($env) > 64 || $env === '') {
            return null;
        }
        $time = CarbonImmutable::createFromFormat('U.u', substr($c[4], 0, -6).'.'.substr($c[4], -6), 'UTC');
        if (! $time) {
            return null;
        }

        return [
            'installation_id' => $i->id,
            'generation' => (int) $c[2],
            'source_epoch' => $c[3],
            'environment' => $env,
            'occurred_at' => $time->utc()->format('Y-m-d H:i:s.u'),
            'time_precision' => 'microsecond',
            'grouping_version' => (int) $g[1],
            'fingerprint_hash' => $g[2],
            'probe_id' => $g[3] === '-' ? null : $g[3],
        ];
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
