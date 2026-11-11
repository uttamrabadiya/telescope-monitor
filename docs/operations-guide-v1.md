# Operational maintenance guide v1

Phase 7 implements schema 6 and all 25 logical tables. This guide is for the administrator/deployment operator of one embedded Laravel installation. The package shares the host database/application/queues; use an external observer for independent offline detection.

## Read status first

Open Settings → System health and Operations & maintenance. Record the installation UUID before a deployment command. Configuration rows report what is configured. Processing rows show separate start, success and failure times, current backlogs/expired leases and up to ten recent bounded maintenance runs. A start is not completion; a recent local heartbeat does not prove all scheduled commands succeeded. Quiet traffic can leave a stale processing observation even while a worker is alive.

Deploy supervised capture/delivery workers using the configured queue connection/names. Keep queue visibility/retry time above worker timeout; the package's leases last 120 seconds. Run local heartbeat and recovery every minute, reconciliation every five minutes, snooze expiry every minute and unified retention hourly. Use host scheduling/supervision and non-overlapping maintenance runs. The dashboard shows exact configured worker command examples but cannot guarantee a future scheduled execution.

## Change retention

1. Open Retention and inspect the effective field source, deployment lock and minimum/maximum.
2. Enter draft limits. Before saving a reduction, preview impact. A capped result is a lower-bound count; an unavailable estimate is an error, never an invented zero.
3. Save using the current row version. Conflicts preserve the draft and require reload/review.
4. Run/schedule `exception-monitor:prune --limit=1000`; repeat bounded sweeps for a large backlog. Inspect actual counts/history afterward.

Evidence/tags, 48-hour five-minute metrics, hourly metrics, eligible activity, terminal delivery/attempt/event history and expired receipts/buffers are eligible. Active references, current leases and ambiguous GitHub creation remain protected. Captured lifetime counts and first/last seen do not decrement. UUID identity prevents replay from re-counting an expired snapshot. Notes, view definitions/preferences and linked GitHub associations remain independent of occurrence snapshot retention.

Saved changes retain monotonic cutoffs. Reducing a limit can make an existing detail explicitly expired before physical deletion; increasing later cannot restore it. Existing expiry caps are not extended retroactively. Deployment-enforced policy changes require worker reload and a sweep under the stricter policy before later relaxation. Run history is bounded operational metadata, not an unbounded error log.

## Whole-installation purge

Purge is an explicitly confirmed deployment action. It deletes error evidence, error summaries/lifecycle/assignment references, occurrences/UUID identities, counts, notes, activity, aggregates, local GitHub associations, capture inbox and delivery/event/attempt/support history. It does not delete host users, Telescope source, external GitHub tickets or accepted messages. It preserves installation identity/configuration/cutoffs, connections/credentials, alert rules, saved definitions/preferences, minimal idempotency receipts and the purge ledger.

```sh
php artisan exception-monitor:purge INSTALLATION_UUID --preview
php artisan exception-monitor:purge INSTALLATION_UUID --confirm=INSTALLATION_UUID --limit=500 --batches=1 --label=DEPLOYMENT_LABEL
```

Both UUID positions must identify the intended installation. Without exact confirmation, mutation is rejected. Preview is read-only and bounds family counts at 10,000, marking larger results as lower bounds.

The first committed step sets draining and prevents new package claims. Valid in-flight leases remain intact; their existing generation/token completion can still commit. The command does not force-recall an external request. If leases remain, status stays draining and no deletion/fence is advanced. Inspect and retry the same run after completion/expiry.

Once drained, an exclusive fence advances generation, records current source sequence/time protection, invalidates old work, resets setup verification and enters purging. Error-domain deletion proceeds leaf-first with foreign keys enabled. Every bounded step and its counts commit atomically; interruption rolls back only the current step. Concurrent resumers serialize and recognize an already completed run idempotently.

```sh
php artisan exception-monitor:purge INSTALLATION_UUID --resume=RUN_UUID --status
php artisan exception-monitor:purge INSTALLATION_UUID --confirm=INSTALLATION_UUID --resume=RUN_UUID --limit=500 --batches=100
```

A successful command exit can represent bounded progress, not whole-run completion. Read the returned state. Keep resuming until `completed` / `verified` and installation `active`. An error leaves the run resumable with a bounded safe code. No table is truncated, no foreign-key checking is disabled and no arbitrary command can be run from the dashboard.

Before the fence only, an explicitly confirmed abort is available:

```sh
php artisan exception-monitor:purge INSTALLATION_UUID --confirm=INSTALLATION_UUID --resume=RUN_UUID --abort
```

Abort is rejected after fencing/deletion begins; continue that run to verification. Aborted target generation numbers are not reused. Existing source markers retained during drain can be recovered after abort without historical notifications.

During maintenance, the dashboard exposes safe settings/status and CLI recovery guidance; error-domain data/mutations return a clear maintenance conflict. Old jobs, delayed old-generation source markers and stale claim completions cannot commit after fencing. Source events signed in the new generation after the cutoff can remain in Telescope and reconcile after resume, as history without automatic alerts. Filtering, missing markers, key loss, source pruning and boundary-time gaps remain coverage limits. Purge does not claim remote messages can be recalled.

After verification, generate a new current-generation capture proof and repeat setup reviews/tests or explicitly skip optional notifications. Old completion receipts do not manufacture new-generation setup verification. Minimal old receipts remain through their retention so replaying an old command key is fenced rather than interpreted as a new external action.

## Review source resets explicitly

A changed storage signature or backwards sequence stops reconciliation. Do not clear installation cutoffs manually.

```sh
php artisan exception-monitor:source-baseline INSTALLATION_UUID --preview
php artisan exception-monitor:source-baseline INSTALLATION_UUID --confirm=INSTALLATION_UUID
```

An explicit baseline creates a new source epoch, excludes every currently stored source sequence and advances/retains the time boundary. It preserves existing monitor data and stable installation/digest keys. Old-epoch jobs/markers cannot become new evidence; future reportable entries can resume capture. It is blocked during an active purge. A new capture proof is required for setup in the changed epoch.

## Host encryption-key rotation

Configure the host's current and previous keys according to [Laravel encryption](https://laravel.com/framework/docs/13.x/encryption#gracefully-rotating-encryption-keys). Keep recoverable backups; the package cannot decrypt without a valid current/previous key. The health check reports unreadable active revisions without deleting them.

```sh
php artisan exception-monitor:rewrap INSTALLATION_UUID secrets --confirm=INSTALLATION_UUID --limit=100 --after=0
```

Targets: secrets, inbox, evidence, events, deliveries and notes. Limits are 1–1,000 rows per call. Continue each target with its returned next_cursor while has_more is true. Failed rows remain untouched and make complete false / command failure; repair the host key and repeat that target from cursor zero. Do not retire a previous host key until all targets complete and active credentials/evidence/notes are readable. Retain keys required by other host data independently of this package.

Rewrapping preserves plaintext bytes, hashes, IDs, row/content versions and secret revisions. Frozen webhook/GitHub exports are not silently re-rendered. Transactions protect a row from concurrent logical editing; no provider I/O occurs. A purge/state/generation change stops further rewrap rows. Deployment-referenced credentials have no copied ciphertext to rewrap.

This is separate from webhook signing-key rotation: stage, distribute to the receiver, test, explicitly activate, honor the displayed 24-hour transition and let maintenance clear expired retiring material.

## Scope of verification

Destructive browser and standalone-host tests used isolated copies only. The standalone host contained production Composer dependencies without consumer Node/Testbench. The Phase 8 report records exact SQLite/PHP combinations, database qualification, native transport and real database/Redis queue evidence, plus an actual killed-worker recovery. Live account permissions/delivery, maintained production-engine baselines, broader crash permutations, long-lived application servers and production-volume behavior remain qualified or unverified. See [release verification](release-verification-v1.0.0-rc.1.md), including the known command-origin defect. Nothing is published by a maintenance or test command.


## Storage connection selection (post-candidate update)

Monitoring tables now inherit `telescope.storage.database.connection` by default, falling back to Laravel's default only when Telescope has no named connection. `EXCEPTION_MONITOR_DB_CONNECTION` selects a separately configured Laravel connection for package data. Host authentication, queue/cache storage and Telescope source reads keep their own connection settings. Invalid/unavailable named targets never trigger a silent fallback.

Configure before install; use `exception-monitor:install` followed by `exception-monitor:migrate`. The latter migrates package files only, on the selected connection and its migration ledger; it does not migrate host application tables there. New published files use a package subdirectory when targeting a different connection from the host default. Existing filenames are preserved. Retention, purge, transactions and after-commit scheduling target the monitoring connection consistently.

Changing connections is not data migration. Stop workers and plan/verify a full data plus migration-ledger transfer, or explicitly initialize a fresh installation. Do not use a connection change as a purge or an automatic adoption of another installation. Keep host encryption keys available, rebuild cached configuration and restart long-lived workers. Source/monitor/broker writes on different databases cannot share a distributed transaction; retained signed-source recovery has the documented availability/retention limits.
