<?php

namespace TelescopeMonitor\Integrations;

final class WebhookPayload
{
    public static function bytes(array $e): string
    {
        return json_encode([
            'schema_version' => 1,
            'event_id' => $e['event_id'],
            'delivery_id' => $e['delivery_id'],
            'event' => $e['event'],
            'occurred_at' => $e['occurred_at'],
            'application' => ['id' => $e['installation_id'], 'name' => $e['application']],
            'environment' => $e['environment'],
            'error' => [
                'id' => $e['issue_id'],
                'class' => $e['class'],
                'message' => $e['message'],
                'file' => $e['file'],
                'line' => $e['line'],
                'count' => $e['count'],
            ],
            'dashboard_url' => $e['url'],
            'test' => $e['test'],
            'staged_key_test' => $e['staged_key_test'] ?? false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function headers(string $body, array $e, string $keyId, string $secret, int $timestamp): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-Telescope-Monitor-Event' => $e['event'],
            'X-Telescope-Monitor-Delivery' => $e['delivery_id'],
            'X-Telescope-Monitor-Timestamp' => (string) $timestamp,
            'X-Telescope-Monitor-Key-Id' => $keyId,
            'X-Telescope-Monitor-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ];
    }

    public static function verify(string $body, array $headers, array $keys, int $now): bool
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        $at = $h['x-telescope-monitor-timestamp'] ?? '';
        $id = $h['x-telescope-monitor-key-id'] ?? '';

        if (! is_string($at) || ! is_string($id) || ! is_string($h['x-telescope-monitor-signature'] ?? null)) {
            return false;
        }

        return ctype_digit((string) $at) && abs($now - (int) $at) <= 300 && isset($keys[$id]) && hash_equals('sha256='.hash_hmac('sha256', $at.'.'.$body, $keys[$id]), $h['x-telescope-monitor-signature']);
    }
}
