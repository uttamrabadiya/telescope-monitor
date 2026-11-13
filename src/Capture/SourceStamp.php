<?php

namespace TelescopeMonitor\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use TelescopeMonitor\Telescope\StoredEntries;

/** Deployment-signed reportability proof. This class must never access package storage. */
final class SourceStamp
{
    public function __construct(private StoredEntries $source, private Fingerprint $fingerprint) {}

    public function validate(): void
    {
        foreach (array_merge([config('telescope-monitor.capture.signing_key') ?? config('app.key')], config('telescope-monitor.capture.signing_previous_keys', [])) as $key) {
            $this->key($key);
        }
    }

    public function tags(string $uuid, mixed $time, array &$content, ?string $probe): array
    {
        $environment = config('app.env');
        if (! is_string($environment) || $environment === '' || strlen($environment) > 64 || ! mb_check_encoding($environment, 'UTF-8')) {
            throw new CaptureFailure('environment_invalid');
        }
        $content['telescope_monitor_capture'] = [
            'version' => 3,
            'environment' => $environment,
            'captured_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'occurred_at' => CarbonImmutable::parse($time)->utc()->format('Y-m-d H:i:s.u'),
            'source' => $this->source->signature(),
            'grouping_version' => (int) config('telescope-monitor.capture.grouping_version', 1),
            'grouping_file' => $this->fingerprint->file((string) ($content['file'] ?? 'unknown')),
            'probe_id' => $probe,
        ];

        return [
            'tm3|'.hash_hmac('sha256', $this->body($uuid, $content), $this->key(config('telescope-monitor.capture.signing_key') ?? config('app.key'))),
        ];
    }

    public function read(string $uuid, array $content, array $tags): ?array
    {
        $proofs = array_values(array_filter($tags, fn ($t) => is_string($t) && (str_starts_with($t, 'tm3|') || str_starts_with($t, 'tm2c|') || str_starts_with($t, 'tm2g|'))));
        $m = $content['telescope_monitor_capture'] ?? null;
        if (! Str::isUuid($uuid) || count($proofs) !== 1 || ! preg_match('/^tm3\|[a-f0-9]{64}$/D', $proofs[0]) || ! is_array($m) || ($m['version'] ?? null) !== 3 || ! is_string($m['environment'] ?? null) || $m['environment'] === '' || strlen($m['environment']) > 64 || ! is_string($m['captured_at'] ?? null) || ! is_string($m['occurred_at'] ?? null) || ! is_string($m['grouping_file'] ?? null) || ! is_int($m['grouping_version'] ?? null) || $m['grouping_version'] < 1 || (($m['probe_id'] ?? null) !== null && ! Str::isUuid($m['probe_id']))) {
            return null;
        }
        $valid = false;
        $keys = array_merge([config('telescope-monitor.capture.signing_key') ?? config('app.key')], config('telescope-monitor.capture.signing_previous_keys', []));
        foreach ($keys as $key) {
            if (hash_equals('tm3|'.hash_hmac('sha256', $this->body($uuid, $content), $this->key($key)), $proofs[0])) {
                $valid = true;
                break;
            }
        }
        if (! $valid || ! hash_equals($this->source->signature(), $m['source'] ?? '')) {
            return null;
        }
        if ($m['grouping_version'] !== (int) config('telescope-monitor.capture.grouping_version', 1)) {
            // Do not advance the importer past a deployment/configuration mismatch.
            throw new CaptureFailure('source_grouping_version_unavailable');
        }
        $time = CarbonImmutable::createFromFormat('!Y-m-d H:i:s.u', $m['occurred_at'], 'UTC');
        if (! $time || $time->format('Y-m-d H:i:s.u') !== $m['occurred_at']) {
            return null;
        }

        return $m;
    }

    private function body(string $uuid, array $content): string
    {
        $selected = [];
        foreach ([
            'class',
            'message',
            'file',
            'line',
            'trace',
            'telescope_monitor_source',
            'telescope_monitor_origin',
            'telescope_monitor_route',
            'telescope_monitor_capture',
        ] as $key) {
            $selected[$key] = $content[$key] ?? null;
        }
        // Preserve verification of older proofs that predate replay capture.
        if (array_key_exists('telescope_monitor_reproduce', $content)) {
            $selected['telescope_monitor_reproduce'] = $content['telescope_monitor_reproduce'];
        }
        $json = json_encode($selected, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($json) > 2097152) {
            throw new CaptureFailure('source_stamp_too_large');
        }

        return "telescope-monitor:source:v3\0".$uuid."\0".$json;
    }

    private function key(mixed $key): string
    {
        if (is_string($key) && str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }
        if (! is_string($key) || strlen($key) < 32) {
            throw new CaptureFailure('source_signing_key_invalid');
        }

        return hash_hmac('sha256', 'telescope-monitor:source:v3', $key, true);
    }
}
