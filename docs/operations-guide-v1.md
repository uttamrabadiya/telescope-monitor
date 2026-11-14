# Operational maintenance guide v1

Phase 7 implements schema 6 and all 25 logical tables. This guide is for the administrator/deployment operator of one embedded Laravel installation. The package shares the host database/application/queues; use an external observer for independent offline detection.

## Read status first

Open Settings → System health and Operations & maintenance. Record the installation UUID before a deployment command. Configuration rows report what is configured. Processing rows show separate start, success and failure times, current backlogs/expired leases and up to ten recent bounded maintenance runs. A start is not completion; a recent local heartbeat does not prove all scheduled commands succeeded. Quiet traffic can leave a stale processing observation even while a worker is alive.

Deploy supervised capture/delivery workers using the configured queue connection/names. Keep queue visibility/retry time above worker timeout; the package's leases last 120 seconds. Run local heartbeat and recovery every minute, reconciliation every five minutes, snooze expiry every minute and unified retention hourly. Use host scheduling/supervision and non-overlapping maintenance runs. The dashboard shows exact configured worker command examples but cannot guarantee a future scheduled execution.

## Change retention

1. Open Retention and inspect the effective field source, deployment lock and minimum/maximum.
2. Enter draft limits. Before saving a reduction, preview impact. A capped result is a lower-bound count; an unavailable estimate is an error, never an invented zero.
3. Save using the current row version. Conflicts preserve the draft and require reload/review.
4. Run/schedule `telescope-monitor:prune --limit=1000`; repeat bounded sweeps for a large backlog. Inspect actual counts/history afterward.

Evidence/tags, 48-hour five-minute metrics, hourly metrics, eligible activity, terminal delivery/attempt/event history and expired receipts/buffers are eligible. Active references, current leases and ambiguous GitHub creation remain protected. Captured lifetime counts and first/last seen do not decrement. UUID identity prevents replay from re-counting an expired snapshot. Notes, view definitions/preferences and linked GitHub associations remain independent of occurrence snapshot retention.

Saved changes retain monotonic cutoffs. Reducing a limit can make an existing detail explicitly expired before physical deletion; increasing later cannot restore it. Existing expiry caps are not extended retroactively. Deployment-enforced policy changes require worker reload and a sweep under the stricter policy before later relaxation. Run history is bounded operational metadata, not an unbounded error log.

## Whole-installation purge

Purge is an explicitly confirmed deployment action. It deletes error evidence, error summaries/lifecycle/assignment references, occurrences/UUID identities, counts, notes, activity, aggregates, local GitHub associations, capture inbox and delivery/event/attempt/support history. It does not delete host users, Telescope source, external GitHub tickets or accepted messages. It preserves installation identity/configuration/cutoffs, connections/credentials, alert rules, saved definitions/preferences, minimal idempotency receipts and the purge ledger.

```sh
php artisan telescope-monitor:purge INSTALLATION_UUID --preview
php artisan telescope-monitor:purge INSTALLATION_UUID --confirm=INSTALLATION_UUID --limit=500 --batches=1 --label=DEPLOYMENT_LABEL
```

Both UUID positions must identify the intended installation. Without exact confirmation, mutation is rejected. Preview is read-only and bounds family counts at 10,000, marking larger results as lower bounds.

The first committed step sets draining and prevents new package claims. Valid in-flight leases remain intact; their existing generation/token completion can still commit. The command does not force-recall an external request. If leases remain, status stays draining and no deletion/fence is advanced. Inspect and retry the same run after completion/expiry.

Once drained, an exclusive fence advances generation, records current source sequence/time protection, invalidates old work, resets setup verification and enters purging. Error-domain deletion proceeds leaf-first with foreign keys enabled. Every bounded step and its counts commit atomically; interruption rolls back only the current step. Concurrent resumers serialize and recognize an already completed run idempotently.

```sh
php artisan telescope-monitor:purge INSTALLATION_UUID --resume=RUN_UUID --status
php artisan telescope-monitor:purge INSTALLATION_UUID --confirm=INSTALLATION_UUID --resume=RUN_UUID --limit=500 --batches=100
```

A successful command exit can represent bounded progress, not whole-run completion. Read the returned state. Keep resuming until `completed` / `verified` and installation `active`. An error leaves the run resumable with a bounded safe code. No table is truncated, no foreign-key checking is disabled and no arbitrary command can be run from the dashboard.

Before the fence only, an explicitly confirmed abort is available:

```sh
php artisan telescope-monitor:purge INSTALLATION_UUID --confirm=INSTALLATION_UUID --resume=RUN_UUID --abort
```

Abort is rejected after fencing/deletion begins; continue that run to verification. Aborted target generation numbers are not reused. Existing source markers retained during drain can be recovered after abort without historical notifications.

During maintenance, the dashboard exposes safe settings/status and CLI recovery guidance; error-domain data/mutations return a clear maintenance conflict. Old jobs, delayed old-generation source markers and stale claim completions cannot commit after fencing. Source events signed in the new generation after the cutoff can remain in Telescope and reconcile after resume, as history without automatic alerts. Filtering, missing markers, key loss, source pruning and boundary-time gaps remain coverage limits. Purge does not claim remote messages can be recalled.

After verification, generate a new current-generation capture proof and repeat setup reviews/tests or explicitly skip optional notifications. Old completion receipts do not manufacture new-generation setup verification. Minimal old receipts remain through their retention so replaying an old command key is fenced rather than interpreted as a new external action.

## Review source resets explicitly

A changed storage signature or backwards sequence stops reconciliation. Do not clear installation cutoffs manually.

```sh
php artisan telescope-monitor:source-baseline INSTALLATION_UUID --preview
php artisan telescope-monitor:source-baseline INSTALLATION_UUID --confirm=INSTALLATION_UUID
```

An explicit baseline creates a new source epoch, excludes every currently stored source sequence and advances/retains the time boundary. It preserves existing monitor data and stable installation/digest keys. Old-epoch jobs/markers cannot become new evidence; future reportable entries can resume capture. It is blocked during an active purge. A new capture proof is required for setup in the changed epoch.

## Host encryption-key rotation

Configure the host's current and previous keys according to [Laravel encryption](https://laravel.com/framework/docs/13.x/encryption#gracefully-rotating-encryption-keys). Keep recoverable backups; the package cannot decrypt without a valid current/previous key. The health check reports unreadable active revisions without deleting them.

Laravel 8–10 do not provide the newer native previous-key API. For key rotation,
the host must bind an encrypter that reads ciphertext with the previous key and
writes with the new key before running rewrap. Monitor uses the host encrypter
binding throughout; it does not replace the application's encryption service.

```sh
php artisan telescope-monitor:rewrap INSTALLATION_UUID secrets --confirm=INSTALLATION_UUID --limit=100 --after=0
```

Targets: secrets, inbox, evidence, events, deliveries and notes. Limits are 1–1,000 rows per call. Continue each target with its returned next_cursor while has_more is true. Failed rows remain untouched and make complete false / command failure; repair the host key and repeat that target from cursor zero. Do not retire a previous host key until all targets complete and active credentials/evidence/notes are readable. Retain keys required by other host data independently of this package.

Rewrapping preserves plaintext bytes, hashes, IDs, row/content versions and secret revisions. Frozen webhook/GitHub exports are not silently re-rendered. Transactions protect a row from concurrent logical editing; no provider I/O occurs. A purge/state/generation change stops further rewrap rows. Deployment-referenced credentials have no copied ciphertext to rewrap.

This is separate from webhook signing-key rotation: stage, distribute to the receiver, test, explicitly activate, honor the displayed 24-hour transition and let maintenance clear expired retiring material.

## Scope of verification

Destructive browser and standalone-host tests used isolated copies only. The standalone host contained production Composer dependencies without consumer Node/Testbench. The Phase 8 report records exact SQLite/PHP combinations, database qualification, native transport and real database/Redis queue evidence, plus an actual killed-worker recovery. Live account permissions/delivery, maintained production-engine baselines, broader crash permutations, long-lived application servers and production-volume behavior remain qualified or unverified. See [release verification](release-verification-v1.0.0-rc.1.md), including the known command-origin defect. Nothing is published by a maintenance or test command.


## Storage connection selection (post-candidate update)

Monitoring tables now inherit `telescope.storage.database.connection` by default, falling back to Laravel's default only when Telescope has no named connection. `TELESCOPE_MONITOR_DB_CONNECTION` selects a separately configured Laravel connection for package data. Host authentication, queue/cache storage and Telescope source reads keep their own connection settings. Invalid/unavailable named targets never trigger a silent fallback.

Configure before install; use `telescope-monitor:install` followed by `telescope-monitor:migrate`. The latter migrates package files only, on the selected connection and its migration ledger; it does not migrate host application tables there. New published files use a package subdirectory when targeting a different connection from the host default. Existing filenames are preserved. Retention, purge, transactions and after-commit scheduling target the monitoring connection consistently.

Changing connections is not data migration. Stop workers and plan/verify a full data plus migration-ledger transfer, or explicitly initialize a fresh installation. Do not use a connection change as a purge or an automatic adoption of another installation. Keep host encryption keys available, rebuild cached configuration and restart long-lived workers. Source/monitor/broker writes on different databases cannot share a distributed transaction; retained signed-source recovery has the documented availability/retention limits.


## Historical Telescope backfill

`telescope-monitor:backfill --environment=<original-environment> --limit=100 --dry-run` inspects one bounded page of unmarked exceptions without package writes. Omit dry-run to sanitize/encrypt and import as historical records. Default output shows a readable summary and copyable import/continuation commands; `--json` retains machine-readable results. Resume using the printed command or JSON `next_after` and the same `through` ceiling; repeat safely after interruption. The source database is Telescope's configured connection and the destination uses the package's effective storage connection. No migration or additional table is required.

Only explicitly unmarked legacy rows are eligible; package-marked rows use normal recovery. Reportability and unsigned release/route provenance are not invented. Current privacy/bounds are enforced before buffering and again during ingestion. Original timestamps use the configured source timezone and govern evidence retention. Importing does not reset retention, reopen resolved issues, emit alerts, create GitHub tickets or contribute to live alert threshold counts. Normal later operator actions and live captures retain their existing behavior.

Purge/epoch/generation fences remain enforced. The cursor is separate from reconciliation and imports never rewrite Telescope source records/tags. Read the pending/failure counts and use existing capture recovery for durable unfinished work. An environment chosen incorrectly is not corrected by replay: UUID identities deduplicate across runs, so verify the source/environment before actual import. Full source/monitor/broker atomicity is not assumed when connections differ.


## Native Telescope authorization (post-candidate update)

Default `authorization.driver=telescope` delegates every monitoring shell/asset/API request to Laravel Telescope's `Authorize` middleware and `Telescope::check`. Published middleware should contain `web` without Laravel's separate session `auth` requirement. Default Telescope behavior permits local access and uses `viewTelescope` elsewhere; custom Telescope callbacks remain authoritative for HTTP access. Every approved visitor has monitoring administration rights in this mode. Opt into `driver=gates` to retain separate package capability gates.

Authenticated actors keep their host-provider identity. Guest-approved requests use a shared operator, not a created/impersonated User. Notes and personal views are shared among visitors with that identity. CSRF protection remains. Queued work re-resolves its saved account or uses a genuine guest scope, then evaluates current Telescope::check. The old allow_guest_jobs switch is ignored. Request/auth/gate context is isolated and restored, without browser credentials or spoofed network details. Current policy denial or failure cancels before provider I/O. Request-only policies must explicitly support the marked telescope-monitor.worker context in the host Telescope policy. Clear/rebuild route/config caches and restart workers after changing deployment policy. No previous personal ownership is rewritten.


## Context activity and SQL template retention

SQL templates are opt-in under Capture & privacy, require execution metadata, and can be deployment-locked off with `privacy.retain_sql_templates=false`. `capture.sql_templates` supplies the default and `capture.sql_max_bytes` bounds each template (default 2048, hard maximum 4096; inspected SQL input at most 16 KiB). Restart workers after updating the producer/codec before testing new captures; no migration is added.

The non-executing lexer masks supported literal forms, excludes bindings/comments, preserves ordinary schema identifiers and fails closed for ambiguous/unsupported/oversized SQL. It is not a general multi-dialect SQL parser. Current redaction and SQL privacy rules apply before buffering, again at ingestion and on evidence reads. Existing ciphertext expires through normal retention. Older snapshots cannot gain discarded SQL automatically; the UI explains this, separates missing-template measurements and links to Telescope evidence when available. Query counts/durations describe retained entries, which may be truncated; activity is grouped and has no invented chronology. SQL is never added to notification/GitHub export payloads by this feature. Telescope source retention/privacy remain independent.


## Rich error email

Email uses the Telescope palette, a responsive HTML table layout and a plain-text alternative, with no remote fonts or images. It includes the sanitized summary, stable application/group reference, event/count metadata and available retained execution context/application frames. It never reconstructs missing historical evidence from source files. Raw request URLs, payloads, headers, SQL, user identities, frame arguments and note text are excluded.

Deployment options under `telescope-monitor.alerts.email` are `include_context` (default true), `include_stack_trace` (true), `max_frames` (8, hard cap 12), and `allowed_tags` (empty). Email tags must also pass the capture allowlist. Review tag names/values before export. Turning off context preserves the summary. Retained context freezes on the first attempt; privacy restrictions are reapplied on retries and incompatible frozen exports cancel with `export_policy_changed` rather than changing silently. Missing/expired evidence degrades to the summary; other notification providers retain their existing payload contract.

No migration is required for this template update. Merge configuration overrides if desired, refresh the host config cache and restart queue workers. Use an array mailer for non-network checks; an explicit synthetic test uses the real configured host transport. Desktop/mobile browser verification is not certification of Gmail/Outlook inbox rendering.


## Telescope-source capture boundary

Host capture now performs zero package database reads or writes in requests, host commands, jobs and scheduled host tasks. The package only attaches deployment-signed metadata to eligible Telescope exceptions; Telescope supplies its existing storage. Run `php artisan telescope-monitor:import --watch --process --limit=100 --sleep=2` as a separate supervised process. Existing queue workers alone do not import new source. No new migration is required; restart host workers and the importer after deployment.

Telescope retention must cover importer outages. Evidence pruned before buffering cannot be recovered. Privacy settings apply during import/processing/read, independently of Telescope's source privacy. Importer checkpoints advance after successful bounded pages; duplicate imports are idempotent. Generation/epoch fences and source sequence/event/capture-time cutoffs protect purge and rebaseline, including delayed source commits. Explicit reconciliation and legacy-marker recovery remain non-alerting. The source signing key comes from deployment configuration (APP_KEY fallback); retain previous verification keys during rotation.

The full [Telescope-source capture guide](telescope-source-capture.md) defines configuration, failure modes, key rotation, supervision, polling latency and recovery limits. Installation/health checks remain database-backed; importer observations are visible in system operations.
