# Reproduce an exception

**Context & activity → Reproduce** offers a collapsed, copyable cURL or Artisan template for the selected occurrence. It never executes the template. Review the target environment, authentication and placeholders before running it; database state, permissions and external services can differ, and a replay may change application data.

## Capture modes

| Mode | What is retained |
| --- | --- |
| `redacted` | Default for routes and commands without an override. Parameter/body structure with value placeholders. Safe protocol headers and actual boolean command switches remain useful. |
| `selected-values` | Exact scalar values only for configured field paths; remaining values become `[REDACTED]`. |
| `full-payload` | Supported parsed values for one explicitly configured route or command, except credentials, sensitive fields, mandatory credential-pattern masking and capture limits. |

There is no global full-payload switch. Configure exact named routes (or `/`-prefixed URI templates for unnamed routes) and command names in the **root** `reproduce` array of `config/telescope-monitor.php`. Settings are deployment-managed so host capture never queries package settings or storage.

```php
'reproduce' => [
    'enabled' => true,
    'max_bytes' => 8192,
    'sensitive_fields' => ['body.customer.tax_id', 'options.internal-key'],
    'routes' => [
        'groups.settlements.store' => [
            'mode' => 'selected-values',
            'fields' => [
                'parameters.group',
                'body.amount',
                'body.currency',
                'body.items.*.amount',
                'query.page',
                'headers.x-request-id',
            ],
            'headers' => ['accept', 'content-type', 'x-request-id'],
        ],
        'imports.preview' => [
            'mode' => 'full-payload',
        ],
    ],
    'commands' => [
        'settlements:process' => [
            'mode' => 'selected-values',
            'fields' => ['arguments.group', 'options.limit'],
        ],
        'reports:debug' => [
            'mode' => 'full-payload',
        ],
    ],
],
```

Replace these illustrative names with actual route names, route parameter names and Symfony argument/option names. Selected field paths are case-sensitive; `*` matches nested path segments. Default retained headers are `accept` and `content-type`. Additional headers require a per-route allowlist; their values also follow the selected capture mode. Do not use this to retain credentials.

RequestWatcher must be enabled for HTTP replay capture; CommandWatcher must be enabled for command replay. Their source exclusions remain separate from this feature. The monitor's current `privacy.retain_execution_context=false` hides/excludes replay evidence during import, processing and reads. Current redaction patterns and stricter replay configuration are reapplied; relaxing policy cannot recover values that were never captured.

## Permanent exclusions and bounds

Transport headers such as Host, Content-Length, connection and compression headers are omitted so cURL can generate headers appropriate to the reconstructed body. Authorization/proxy-authorization, cookies, session identifiers, passwords, tokens, API/private keys, signatures and card-security fields are excluded regardless of mode or allowlist. Recognized credential strings, including Bearer/Basic authentication, JWTs and common provider keys, are masked inside retained strings. Add application-specific `sensitive_fields` for secret names the package cannot infer. An arbitrary opaque secret in an unrelated field cannot reliably be recognized automatically; review full-payload opt-ins carefully.

HTTP templates use the captured scheme/host, route template, permitted original scalar route parameters, parsed query values and supported JSON object/array or URL-encoded form fields. The URL and body are reconstructed from parsed values, not byte-identical wire data; duplicate parameters, original whitespace and transport signatures are not reproduced. JSON object/array shapes, booleans and nulls are retained within limits. Integers beyond JavaScript's safe integer range are replaced with placeholders to avoid silent precision loss.

Files, multipart/binary/XML bodies and serialized job payloads are not retained for replay. The panel lists manual body/file requirements. Jobs show an explicit unsupported state. Artisan templates use parsed argument/option values, which may include defaults; actual boolean/negated switches are preserved. Options precede a `--` separator so flag-like positional arguments remain arguments. Copy output is quoted for a POSIX shell; Windows shell quoting is not supported.

Limits are 8 KiB per replay record by default (configurable from 1–32 KiB), 200 traversed fields, six nesting levels and 2 KiB per string value. JSON body inspection is limited to four times the record budget. Large/unsupported fields become placeholders or omissions; an oversized record is omitted entirely with a visible explanation. Overall occurrence evidence limits still apply. Unknown routes, unavailable invocation inputs or malformed replay data never prevent ordinary exception capture.

## Storage, access and compatibility

Replay metadata is sanitized without database queries, encrypted with the host Laravel encrypter **before entering Telescope**, and bound into the source signature. Import verifies the proof, decrypts and reapplies policy before writing the encrypted inbox/evidence. Existing Telescope request/log/exception records have their own independent privacy policy and may contain raw data; this feature does not sanitize those records retroactively.

Only monitoring operators/admins receive replay data from the evidence and issue-detail APIs. Viewers receive a restricted state, including for redacted templates. Copying does not send the evidence to an external provider. Replay data is not added to email, Slack, webhook or GitHub export payloads.

Replay follows occurrence evidence retention and purge. No additional tables or migrations are required. Old snapshots remain readable and explain that replay evidence was not captured. They are never enriched from old raw Telescope request data or the current process. Source decryption failures degrade only replay availability when the exception proof remains valid.

After updating, merge the new configuration block if the published config already exists, refresh cached configuration and restart host workers/Octane, the importer and monitoring queue workers. Capture new exceptions to test changes. Older package workers do not understand the new optional envelope field; replace/restart all monitoring workers during deployment.

## Verify in your app

1. Enable selected fields for a test route; include allowed values and dummy credentials in its request. Confirm the template contains the allowed values and credential placeholders.
2. Switch that exact route to full payload and capture a **new** exception. Verify non-secret values remain exact and credentials remain excluded.
3. Compare operator and viewer access; a viewer must not receive replay data from either evidence API.
4. Capture a command with arguments, a dummy secret option and a boolean flag. Check the command template and supply fresh credentials manually.
5. Pause the importer and trigger the request/command. Telescope should retain the source with no `telescope_monitor_*` queries from host capture; import later and verify the template.
6. Open an old occurrence, an unsupported job and an oversized request. Each must explain its limitation without offering a fabricated complete replay.
