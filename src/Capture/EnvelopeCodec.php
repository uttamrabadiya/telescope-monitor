<?php

namespace TelescopeMonitor\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use TelescopeMonitor\Models\CaptureInbox;

final class EnvelopeCodec
{
    public function decode(CaptureInbox $item): array
    {
        if ($item->envelope_version !== 1) {
            throw new CaptureFailure('unsupported_envelope_version');
        }
        try {
            $json = Crypt::decryptString($item->payload_ciphertext ?? '');
        } catch (\Throwable) {
            throw new CaptureFailure('decryption_failed');
        }
        if (strlen($json) > 131072 || ! hash_equals($item->payload_digest ?? '', hash('sha256', $json))) {
            throw new CaptureFailure('envelope_integrity_failed');
        }
        $data = json_decode($json, true);
        if (! is_array($data) || ($data['version'] ?? null) !== 1) {
            throw new CaptureFailure('malformed_envelope');
        }
        if ($item->kind === 'batch_reference') {
            if (($data['batch_uuid'] ?? null) !== $item->batch_uuid || ! is_int($data['expected_count'] ?? null) || $data['expected_count'] < 1 || ! is_int($data['scanned_count'] ?? null) || $data['scanned_count'] < 0 || $data['scanned_count'] > $data['expected_count']) {
                throw new CaptureFailure('malformed_batch_reference');
            }

            return $data;
        }
        if ($item->kind !== 'exception_envelope') {
            throw new CaptureFailure('unknown_capture_kind');
        }
        if (array_diff(array_keys($data), [
            'version',
            'marker',
            'origin',
            'exception',
            'execution',
            'logs',
            'queries',
            'timeline',
            'tags',
            'impact',
            'coverage',
            'source',
        ])) {
            throw new CaptureFailure('malformed_envelope');
        }
        foreach (['marker', 'exception', 'execution', 'logs', 'queries', 'timeline', 'tags', 'coverage'] as $key) {
            if (! isset($data[$key]) || ! is_array($data[$key])) {
                throw new CaptureFailure('malformed_envelope');
            }
        }
        $marker = $data['marker'];
        foreach (['generation', 'source_epoch', 'environment', 'occurred_at', 'grouping_version', 'fingerprint_hash'] as $key) {
            if (($marker[$key] ?? null) != $item->$key) {
                if ($key === 'occurred_at' && is_string($marker[$key] ?? null)) {
                    try {
                        if (CarbonImmutable::parse($marker[$key], 'UTC')->equalTo($item->occurred_at)) {
                            continue;
                        }
                    } catch (\Throwable) {
                        throw new CaptureFailure('malformed_envelope');
                    }
                }
                throw new CaptureFailure('envelope_header_mismatch');
            }
        }
        $e = $data['exception'];
        if (array_diff(array_keys($e), ['class', 'message', 'file', 'line', 'trace']) || ! is_string($e['class'] ?? null) || ! is_string($e['message'] ?? null) || ! is_string($e['file'] ?? null) || ! is_int($e['line'] ?? null) || ! is_array($e['trace'] ?? null) || count($e['trace']) > 100) {
            throw new CaptureFailure('malformed_evidence');
        }
        foreach ($e['trace'] as $frame) {
            if (! is_array($frame) || array_diff(array_keys($frame), ['file', 'line', 'class', 'function'])) {
                throw new CaptureFailure('malformed_trace');
            }
        }
        if (! in_array($data['origin'] ?? null, ['web', 'job', 'command', 'unknown'], true) || array_diff(array_keys($data['execution']), ['method', 'path', 'controller', 'status', 'job_class', 'command', 'route_name', 'path_template'])) {
            throw new CaptureFailure('malformed_execution');
        }
        foreach (array_merge($data['logs'], $data['queries'], $data['timeline']) as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), [
                'type',
                'level',
                'message',
                'connection',
                'duration_ms',
                'sql_template',
                'sql_state',
                'method',
                'path',
                'status',
                'job_class',
                'command',
            ])) {
                throw new CaptureFailure('malformed_timeline');
            }
        }
        if (count($data['logs']) > 20 || count($data['timeline']) > 100 || count($data['tags']) > 20) {
            throw new CaptureFailure('evidence_bounds_exceeded');
        }

        foreach ($e['trace'] as $frame) {
            foreach ($frame as $key => $value) {
                if (($key === 'line' && (! is_int($value) || $value < 0)) || ($key !== 'line' && ! is_string($value))) {
                    throw new CaptureFailure('malformed_trace');
                }
            }
        }
        foreach ($data['execution'] as $key => $value) {
            if (($key === 'status' && ! is_int($value)) || ($key !== 'status' && ! is_string($value))) {
                throw new CaptureFailure('malformed_execution');
            }
        }
        foreach (array_merge($data['logs'], $data['queries'], $data['timeline']) as $entry) {
            foreach ($entry as $key => $value) {
                if ($key === 'status' ? (! is_int($value) || $value < 0 || $value > 599) : ($key === 'duration_ms' ? (! is_numeric($value) || ! is_finite((float) $value) || $value < 0) : ! is_string($value))) {
                    throw new CaptureFailure('malformed_timeline');
                }
            }
        }
        foreach (array_merge($data['queries'], $data['timeline']) as $entry) {
            if ((isset($entry['sql_template']) && (strlen($entry['sql_template']) > 4096 || ($entry['sql_state'] ?? null) !== 'retained')) || (($entry['sql_state'] ?? null) === 'retained' && ! isset($entry['sql_template'])) || (isset($entry['sql_state']) && ! in_array($entry['sql_state'], ['retained', 'disabled', 'unsupported', 'too_large'], true))) {
                throw new CaptureFailure('malformed_sql_template');
            }
        }
        foreach ($data['tags'] as $key => $value) {
            if (! is_string($key) || ! is_string($value) || strlen($key) > 64 || strlen($value) > 64) {
                throw new CaptureFailure('malformed_tags');
            }
        }
        foreach ($data['coverage'] as $key => $value) {
            if (! is_string($key) || ! in_array($value, ['available', 'excluded', 'unavailable', 'truncated', 'pseudonymous'], true)) {
                throw new CaptureFailure('malformed_coverage');
            }
        }
        if (($data['impact'] ?? null) !== null && (! is_array($data['impact']) || array_diff(array_keys($data['impact']), ['id', 'key_id']) || ! is_string($data['impact']['id'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $data['impact']['id']) || ! is_string($data['impact']['key_id'] ?? null) || ! Str::isUuid($data['impact']['key_id']))) {
            throw new CaptureFailure('malformed_impact');
        }
        if (count($data['queries']) > 100 || strlen($e['class']) > 512 || strlen($e['message']) > 16384 || strlen($e['file']) > 2048 || $e['line'] < 0) {
            throw new CaptureFailure('evidence_bounds_exceeded');
        }

        if (($data['source'] ?? null) !== null) {
            $source = $data['source'];
            if (! is_array($source) || ($source['version'] ?? null) !== 1 || ! is_string($source['release'] ?? null) || strlen($source['release']) > 191 || (isset($source['template']) && (! is_string($source['template']) || strlen($source['template']) > 2048)) || array_diff(array_keys($source), ['version', 'release', 'template'])) {
                throw new CaptureFailure('malformed_source_snapshot');
            }
        }

        return $data;
    }
}
