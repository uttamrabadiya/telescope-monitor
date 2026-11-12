# Telescope-source capture

This supersedes the original synchronous capture-inbox design and the intermediate schema/signing-read optimizations. The package's host hooks execute **zero package database queries**, including on exceptions and failures. This applies to HTTP requests, host Artisan commands, synchronous/asynchronous host jobs and scheduled host tasks. Telescope's own storage queries and host error-handling behavior remain Telescope/host responsibilities. Dashboard/API operations and explicit monitoring commands/workers intentionally access monitoring storage.

## Execution boundary

1. The Telescope tag callback checks eligibility/reportability in memory. Ordinary non-exception entries do not instantiate the capture pipeline.
2. For an eligible exception, it records bounded deployment metadata: environment, event/capture time, source connection signature, grouping version/application-relative file, optional bounded argument-free stack frames and release/route/origin and an explicitly identified synthetic probe. It HMAC-signs the UUID and selected exception/metadata fields with a deployment key. Grouping callbacks, settings, installation lookups, secret-table reads and queue dispatches do not run here. The signature contains no signing key.
3. Telescope's existing filtering and storage determine which rows persist. There is no package after-storing ingestion callback. No package inbox, receipt, observation, alert or delivery writes run in host execution. Signer failures fail open for the host and do not attempt a database/logging diagnostic fallback.
4. A separate importer verifies accepted source proofs, applies current privacy/redaction, computes grouping and writes bounded encrypted inbox envelopes. Existing processing then deduplicates occurrences, enforces current privacy/lifecycle/generation fences and plans alerts. Package evidence becomes independent of Telescope only after buffering succeeds.

## Run it in your application

No new migration is required. Deploy the code, refresh the configuration cache if applicable and restart long-running host queue/Octane processes so old callbacks are removed. Stop/restart any old monitoring processes as well.

Run a **separately supervised process**:

```sh
php artisan telescope-monitor:import --watch --process --limit=100 --sleep=2
```

`--process` processes a bounded set of due inbox records in the monitoring process, including retries; it works without an asynchronous capture queue. Without it, run the existing `telescope-monitor-capture` queue worker or explicit `telescope-monitor:process` command. When an asynchronous queue is configured, import may also dispatch capture jobs; duplicate claims remain safe. Queued synthetic capture checks still require the capture queue worker. Keep the separate delivery worker running for email/integrations.

A single deliberate pass is:

```sh
php artisan telescope-monitor:import --limit=100 --process
```

Alternatively, schedule that explicit monitoring command every minute with `withoutOverlapping()`. This intentionally runs monitoring queries in the dedicated monitoring command, not in unrelated application commands or tasks. Do not call the importer from request middleware, host job handlers, termination callbacks or synchronous dispatch. Watch mode handles termination signals where pcntl is available and exits nonzero on missing migrations; supervisors should restart it. Other failed passes report safe guidance and retry after the polling interval. Restart the importer explicitly after deployment; `queue:restart` only restarts Laravel queue workers.

Keep `telescope-monitor:recover` and retention maintenance for existing buffered work/probes. `telescope-monitor:reconcile` remains an explicitly non-alerting repair scan, and shares the source checkpoint with import. Normal ingestion should use `import`; running reconcile first can intentionally classify an unimported backlog as recovered instead of live.

## Signing and rotation

`telescope-monitor.capture.signing_key` / `TELESCOPE_MONITOR_CAPTURE_KEY` is optional. Null derives a domain-separated signing key from `APP_KEY`; no secret-table read is needed. An explicit key must contain at least 32 bytes, either raw or `base64:` encoded. Distribute the same configuration to all producers and importers. Keep keys in deployment secret storage, never the dashboard.

Configure previous keys explicitly during rotation:

```php
'capture' => [
    'signing_key' => env('TELESCOPE_MONITOR_CAPTURE_KEY'),
    'signing_previous_keys' => [/* previous deployment signing key(s) */],
],
```

Retain verification keys until all relevant Telescope source has been imported or aged out. If using APP_KEY fallback, preserve its old value in this signing allowlist as well as Laravel's encryption previous-key configuration. These serve different purposes. Removing a verification key makes its old proofs unverifiable; automatic capture never falls back to unsigned import. Invalid current key configuration is reported by the importer. If signing failed at capture, the Telescope exception remains available but cannot later gain automatic proof of reportability; explicit historical backfill remains a separate, non-alerting operation.

The new `tm3|…` proof binds the UUID and selected exception/metadata fields. Tampering or transplanting it invalidates the proof. Legacy `tm2c`/`tm2g` database-key proofs remain verifiable by the importer and are recovered with non-live semantics. Existing encrypted inbox items remain processable unchanged. Backfill skips both marker versions.

Grouping callbacks run only in the importer. Keep producer/importer grouping versions and configuration aligned and drain before changing grouping policy. A valid source proof with a different grouping version stops checkpoint advancement for explicit configuration repair; it is not silently regrouped.

## Recovery and purge

Import uses bounded oldest-first sequence pages and the persisted overlap scan. It advances the checkpoint only after the page is successfully handled; partial buffer writes survive and deduplicate on restart. Concurrent importers are safe through inbox uniqueness and transactionally checked cursor updates. Source identity changes or a backwards sequence require explicit source rebaselining. The finite overlap window still limits recovery of unusually late source commits; it is not an unlimited history guarantee.

The importer pauses while installation state is draining/purging and does not consume the checkpoint. Buffer insertion and processing retain their transactional generation/epoch fences. Source sequence floors and event/capture-time cutoffs exclude old source after purge/rebaseline. The signed capture time protects against a pre-purge exception committed later at a higher sequence, including when its event timestamp is ahead of the purge cutoff. Purge does not rotate the deployment signing key. Abort leaves retained signed rows recoverable.

Normal new proofs imported through `import` use live semantics; deduplication prevents re-alerting on replay. Reconcile/backfill and legacy-proof recovery do not create retrospective alerts. Synthetic probes require a valid current-generation monitoring receipt and remain excluded from ordinary error alerts.

## Retention and observations

Telescope is now the initial durable buffer. Configure its pruning/retention to exceed the longest expected importer outage and monitor importer progress. Source pruned before successful buffering cannot be reconstructed, and a gap cannot always be counted after source deletion. An unavailable monitoring database no longer affects host capture, but it delays all monitoring evidence/alerts until import recovers. Privacy settings apply at import, again at processing and on reads; they do not change Telescope's independent source retention/privacy policy or retroactively recover discarded data.

System health/operations expose the Telescope importer observation and source maximum/checkpoint/floor/cutoff. A past successful pass is not proof of a currently running process. Import latency includes the polling/scheduling interval and any backlog; alerts are then handled by the existing durable delivery system.
