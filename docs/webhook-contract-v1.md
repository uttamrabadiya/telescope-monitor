# Signed webhook contract v1

This package sends a bounded, sanitized UTF-8 JSON body. The version 1 field order is deterministic; the exact body bytes freeze before the first provider attempt. Receivers must verify the raw bytes before decoding. Do not pretty-print, normalize Unicode, trim or re-encode before HMAC verification.

| Field | Meaning |
| --- | --- |
| schema_version | Integer 1 |
| event_id / delivery_id | Stable event UUID and logical delivery UUID; distinct destinations have distinct deliveries |
| event | Versioned event name, or monitor.test |
| occurred_at | Original event UTC timestamp, retained across retries |
| application.id / application.name | Installation UUID and sanitized display name |
| environment | Captured environment |
| error.id | Local error UUID; null for an explicit connection test |
| error.class / message / file / line | Sanitized bounded summary; no stack, request context, user identity or note body |
| error.count | Captured count as a decimal string |
| dashboard_url | Authenticated local investigation/setup URL |
| test / staged_key_test | Explicit synthetic-test and selected staged-key-test flags |

Supported automatic event names: error.created, error.regressed, error.threshold_reached, error.resolved, error.reopened, error.ignored, error.snoozed, error.unsnoozed, error.snooze_expired, note.created and assignment.changed. Assignment notifications require host assignment support. monitor.test is a separately authorized explicit test. Delivery failure never recursively generates a failure webhook.

| Header | Value |
| --- | --- |
| Content-Type | application/json |
| X-Telescope-Monitor-Event | Body event name |
| X-Telescope-Monitor-Delivery | Body logical delivery UUID |
| X-Telescope-Monitor-Timestamp | Unix UTC seconds for this attempt |
| X-Telescope-Monitor-Key-Id | Public identifier for the signing secret |
| X-Telescope-Monitor-Signature | sha256= followed by lowercase hexadecimal HMAC SHA-256 |

Signing input is ASCII timestamp + a literal period + the exact transmitted body. Verify the identified shared secret in constant time, reject a clock difference greater than 300 seconds, then validate the JSON schema and matching event/delivery headers. Keep receiver clocks synchronized. The helper `TelescopeMonitor\Integrations\WebhookPayload::verify($rawBody, $headers, $keysById, time())` implements exact-body/timestamp/signature checking; the receiver still owns schema validation and durable deduplication.

A minimal independent PHP verification core is:

```php
$raw = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_TELESCOPE_MONITOR_TIMESTAMP'] ?? '';
$keyId = $_SERVER['HTTP_X_TELESCOPE_MONITOR_KEY_ID'] ?? '';
$signature = $_SERVER['HTTP_X_TELESCOPE_MONITOR_SIGNATURE'] ?? '';
// Server-owned active/transition key map; never accept a secret from the payload.
$secret = $receiverKeys[$keyId] ?? null;
$expected = is_string($secret)
    ? 'sha256='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret)
    : '';
if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300
    || !is_string($secret) || !hash_equals($expected, $signature)) {
    http_response_code(403);
    exit;
}
$event = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
// Validate v1 shape, event name, UUIDs, and matching event/delivery headers.
```

Signature validation is not replay protection by itself. Create a unique receiver receipt on delivery_id. In one database transaction, insert/lock that receipt, apply local business changes and mark processing successful. A duplicate successful delivery returns 2xx without repeating those changes. Roll back the receipt if local processing fails so the sender can retry. For further external side effects, commit a receiver-owned outbox in that same transaction and process it durably; do not pretend a receipt and a second network request are atomic.

Return 2xx only after committing durable acceptance. Transient failures may return 429/5xx with Retry-After. Definitive 3xx/4xx responses are not followed with credentials. Do not log shared secrets, Authorization values or raw sensitive request bodies.

Retries retain event ID, delivery ID and original body bytes, but use a fresh attempt timestamp/signature and the active signing-key revision. Record the delivery ID, rather than a signature or timestamp, for deduplication. Keep successful receipts long enough for configured retry/manual-resend policy; a reviewed resend deliberately has a new logical ID and can represent a second intentional processing action.

Rotation stages a key without activating it. Configure the receiver to accept its new key ID, send a labeled staged-key test, and activate explicitly in the dashboard. Retain the previous key through the displayed 24-hour transition, then remove it from the receiver's accepted key map. An in-flight request can carry the old key. Expired key material is cleared by sender maintenance; receiver key retirement remains the receiver's responsibility.

The sender's durable notification transport is at least once. A lost response/local outcome commit can duplicate a message; receiver deduplication is required. GitHub creation uses a separate Needs review uncertainty policy and is not part of this webhook contract.
