# Telescope Monitor

A Composer package for a complete error-monitoring experience built on Laravel Telescope, using Telescope's storage connection by default and the host application's authentication, mailer, queue and scheduler.

## Current implementation status

The previous dashboard-only prototype has been archived and removed from the active source tree. Phases 1–7 are implemented. **Phase 8 has a review-only release candidate; publication is held until the remaining acceptance gates pass.** See the [release verification report](docs/release-verification-v1.0.0-rc.1.md) and [release notes](docs/release-notes-v1.0.0-rc.1.md).

Implemented now:

- Package discovery, safe migration/config publishing and prototype-schema detection.
- Stable installation identity and configuration revisions.
- Explicit host capability checks for viewing, operating and administration.
- A package-owned Vue 3/TypeScript dashboard with compiled assets and locally shipped Figtree fonts.
- Resumable setup position, editable general/privacy/retention preferences, deployment locks and stale-edit protection.
- Authorized, bounded redaction preview that does not persist sample text.
- Configuration diagnostics that distinguish configured, observed and unverified states.
- Durable encrypted capture inbox, signed reportability/grouping markers, asynchronous ingestion and replay-safe occurrence identities.
- Sanitized evidence independent of Telescope retention, event-time metrics, bounded recovery/reconciliation and evidence pruning.
- A real queued synthetic capture check in onboarding, with truthful pending, filtered, disabled and verified states.
- Overview/trends and a filtered, paginated error inbox, with test data excluded by default.
- Stable occurrence URLs, retained stack/context/activity, scoped insights, activity and explicit expired/unreadable states.
- Versioned, idempotent Resolve/Reopen/Ignore/Snooze/Unsnooze actions with actor audit, suppression cutoffs and bounded expiry maintenance.
- Encrypted plain-text notes with safe links, author edits, conflict recovery and body-removing tombstones.
- Personal/shared saved definitions, copy/rename/delete and per-actor favorites/order/defaults with private-view isolation.
- Optional host-directory assignments, unavailable-reference handling and assignment filtering; disabled until deployment configuration enables a provider.

- Typed alert rules for new errors, regressions, fixed UTC frequency thresholds and optional activity events.
- Canonical encrypted email destinations, sanitized previews and explicit queued synthetic tests.
- Transactional delivery intent, overlapping-rule coalescing, shared cooldown gates, bounded attempts, leases, recovery and reviewed resends.
- Filtered delivery history, frozen-payload detail, attempt outcomes and processing/recovery observations.

Slack, signed webhooks and GitHub Issues share the durable delivery system. Operational completion, retention previews and the explicitly confirmed deployment purge are implemented. No recipient or enabled rule is seeded automatically. The development preview uses the non-network array mailer.

The [phased plan](docs/implementation-plan-v1.md), [product specification](docs/product-specification-v1.md), [architecture](docs/technical-architecture-v1.md) and [schema](docs/database-schema-v1.md) define the intended v1 release. The approved [mockups](docs/design/mockups-v1/README.md) remain the visual reference.

## Requirements

The declared constraints are PHP 8.3+, Laravel 13 and Telescope 5.27+. Exact verified combinations and database qualifications are in the release verification report; constraints alone are not compatibility evidence. Install current security updates. The Laravel 13.0.0 minimum fixture was blocked by Composer advisories and is not certified. Consumers install compiled dashboard assets through Composer and need no Node.js installation or host frontend integration. The Composer name remains provisional and the package is not published.

## Install in a host application

Install and configure official Telescope as a production dependency first. Until this package is published, add this repository as a Composer path repository and require `telescope-monitor/telescope-monitor:@dev`.

```sh
php artisan exception-monitor:install
php artisan exception-monitor:migrate
```

The installer preserves existing configuration and skips a migration already published under its stable filename suffix. It does not migrate automatically. A database containing the old prototype tables without the replacement installation marker is rejected explicitly. Back up that host and review a conversion or choose a fresh database; the installer never drops those tables.

By default, monitoring routes use Laravel Telescope's own `Authorize` middleware and `Telescope::check($request)`. The package does not add Laravel's session-login `auth` middleware. With Laravel's default Telescope application provider, local access is allowed and other environments use `viewTelescope`. A custom `Telescope::auth(...)` callback is honored, including a callback that denies local access. Configure Telescope's existing access policy; no separate monitor login route is required.

```php
'middleware' => ['web'],
'authorization' => [
    'driver' => 'telescope',
    'allow_guest_jobs' => false,
],
```

All Telescope-authorized visitors have view, operate and manage capabilities in this default mode. Web sessions and CSRF protection remain enabled. Existing published configurations are preserved: remove `auth` from the monitoring middleware array to adopt this mode, then clear/rebuild configuration and route caches. Open `/exception-monitor/setup` directly.

Signed-in users retain their provider-backed identity. Authorized visits without a user use a stable **Telescope operator (shared)** identity; no host user record is created and nobody is signed in automatically. Guest notes and personal saved views belong to that shared identity, so authorized guests share their ownership. Existing account-owned private views remain with their original account.

Queued manual work re-resolves authenticated users and checks the current `viewTelescope` gate outside local. Local shared work rechecks Telescope access. A deployed request-only guest policy cannot be replayed inside a worker, so guest-initiated queued work is denied there by default. If the deployment deliberately trusts a shared operator for queued work, it can explicitly set `authorization.allow_guest_jobs` to true; disabling it revokes that shared worker grant. Custom HTTP callbacks for signed-in users should also supply an equivalent `viewTelescope` worker gate or a host actor/authorization adapter. Restart long-lived workers after deployment policy changes.

For applications needing separate viewer/operator/admin roles, opt into `authorization.driver = 'gates'` and define the existing capability gates:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewExceptionMonitor', fn ($user) => $user->can('view-operations'));
Gate::define('operateExceptionMonitor', fn ($user) => $user->can('operate-monitoring'));
Gate::define('manageExceptionMonitor', fn ($user) => $user->can('manage-monitoring'));
```

Replace the predicates with actual host permissions. Gate mode requires a resolvable authenticated actor, and each capability also requires view access. Add the host's login middleware if desired. Custom hosts can bind `TelescopeMonitor\Auth\ActorResolver`; the package does not require a particular User model. Unknown authorization drivers deny access.

## Database storage

Monitoring storage resolves in this order:

1. `exception-monitor.database.connection`, configured by `EXCEPTION_MONITOR_DB_CONNECTION`.
2. `telescope.storage.database.connection`.
3. Laravel's `database.default` when neither setting names a connection.

Leave the override unset to keep all monitoring tables alongside Telescope. To use a different database, define a **named Laravel connection** in `config/database.php`, for example `monitoring`, and set:

```dotenv
EXCEPTION_MONITOR_DB_CONNECTION=monitoring
```

This variable is a connection name, not a database name or URL. Credentials and the actual database name remain in the host's Laravel connection configuration. An unknown or invalid name fails; it never silently redirects writes to the primary database. Provision the database and permissions before installation; the package creates its tables, not the database server/catalog.

Configure storage before the first install, then run:

```sh
php artisan exception-monitor:install
php artisan exception-monitor:migrate
```

The dedicated migration command runs only the six published package migrations, recording them on the effective monitoring connection. For a connection different from the host default, new migrations publish under `database/migrations/exception-monitor` so ordinary host migration discovery does not run them accidentally. Already published filenames are preserved across both locations. `--pretend` and production `--force` are supported. Do not run all host migrations against the monitoring database.

Models, queries, transactions, after-commit callbacks, schema checks, retention and purge use the same effective connection. Package foreign keys remain within that database. Host users, sessions, cache and queue storage keep their existing configuration; the queue connection is a separate setting. Telescope source reads still use Telescope's own storage configuration even when the monitoring override points elsewhere.

Changing the connection selects a different installation; it does **not** copy existing history or relocate tables. Existing installations require a planned transfer of the complete package schema/data and migration ledger with workers stopped, or an explicitly fresh setup on the new connection. Preserve encryption keys. Rebuild cached configuration and restart workers after configuration changes. Do not drop old tables until a separately verified transfer is complete.

Separate databases do not provide a distributed transaction between Telescope source writes, monitoring records and queue broker writes. Durable receipts and recovery still apply, but source filtering/pruning, storage outages and missing signed markers remain capture limits. The same-database default minimizes those coordination concerns.

## Configuration

Publishable configuration lives in `config/exception-monitor.php`. The path, auth guard, middleware, capability names, queue selection and deployment locks are host-managed. Dashboard preferences are stored in package tables.

A deployment can lock a preference:

```php
'locked' => [
    'privacy.identify_users' => false,
    'retention.occurrence_days' => 14,
],
```

The UI identifies these values as deployment-managed. The canonical dashboard URL is derived from the trusted host `app.url` and package path and is locked. No request Host header supplies notification link configuration. Preference edits require the current version; stale edits return a conflict and preserve the browser draft.

For an alternate dashboard path, configure `EXCEPTION_MONITOR_PATH=operations/monitor`. Root, wildcard and traversal paths are invalid. The package excludes only its exact routes/descendants from Telescope collection and registers its fixed private-envelope redaction key without replacing host entry/batch filter callbacks.

Privacy preferences restrict capture before durable buffering and are checked again at ingestion. Retention sweeps must be scheduled explicitly. Infrastructure credentials remain host-managed. Email uses the host mailer and explicitly configured destinations; SMTP credentials remain host-managed; integration credentials use encrypted connection records.

## Capture operations

After an upgrade, rerun `exception-monitor:install` and `migrate` to publish only missing migrations. Restart long-lived workers after installing code or changing host deployment configuration.

Configure a host asynchronous **database or Redis** queue and run its worker:

```sh
php artisan queue:work --queue=exception-monitor-capture --timeout=60 --tries=1
```

Select a named connection with `exception-monitor.queues.connection`; pass the same connection to `queue:work`. Jobs contain identifiers only. Keep the queue's `retry_after` above the worker timeout; an inbox lease lasts 120 seconds. Host production supervision and scheduler remain required. Sync/null/unsupported drivers leave durable input pending and never run ingestion or a probe inside a monitored request.

Schedule bounded sweeps in the host application's console routes:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('exception-monitor:recover --limit=100')->everyMinute()->withoutOverlapping();
Schedule::command('exception-monitor:reconcile --limit=100')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('exception-monitor:prune --limit=1000')->hourly()->withoutOverlapping();
```

`recover` redispatches pending/retryable input and expired worker leases. `reconcile` advances an oldest-first source checkpoint plus a persisted 15-minute overlap cursor; only valid signed source markers can prove reportability. Recovery never invents legacy reportability or sends retroactive notifications. `reconcile --hours=24 --limit=1000` explicitly scans the oldest bounded page in that time window without moving the normal checkpoint. Limits are 1–10,000; history windows are 1–720 hours. Repeated historical requests cover the same page unless the window/limit changes; normal incremental recovery is the route for continued catch-up.

For deliberate local/admin operation without a worker, `exception-monitor:process --limit=100` processes a bounded set inline **only in that CLI command**. It does not execute pending queued synthetic probes; those need an asynchronous worker.

Processed/cancelled inbox bodies are removed after 24 hours. Pending/failed ciphertext expires after the occurrence retention period. Expired evidence is unavailable immediately and physically deleted by pruning. Durable UUID identities and lifetime counts survive evidence expiry; replay cannot recreate expired snapshots or count them again. Five-minute metrics expire after 48 hours; hourly metrics follow aggregate retention. Activity expires after 90 days; disabled event records expire after 30 days. Back up the host encryption keys: losing them makes retained ciphertext unreadable and prevents marker verification. See the operations guide for host-key rewrap and explicitly confirmed purge.

Grouping v1 uses environment, exception class, application-relative file and line, excluding the message. A host `capture.grouping_callback` receives only that replay-stable input set and returns a bounded string; increment `capture.grouping_version` when changing its policy. `capture.source_timezone` defaults to the host application timezone for Telescope source-window queries; package timestamps always use UTC.

Normal envelopes are at most 128 KiB; source/context reads, traces, logs, tags and overflow batches have explicit limits. Related request bodies/headers/cookies, trace arguments/objects, query bindings and arbitrary context are never copied. Queries retain connection/duration by default; optional sanitized SQL templates are described below. Default text redaction handles common credential patterns; application-specific secrets still need configured redaction patterns and appropriate Telescope policy. Source records are governed separately by the host.

A changed source database signature or backwards source sequence stops reconciliation for explicit epoch review; the package never silently resets identity. A source that was filtered, unmarked, pruned or unavailable before durable buffering cannot be reconstructed. Setup and system health expose pending/failed counts, oldest pending receipt, safe error code and actual processing/maintenance times. The overview and investigation UI consume these observations; email delivery has its own durable ledger and observations described below.

## Backfill existing Telescope exceptions

For retained exceptions that predate this package, use the explicit CLI importer. Choose the **original source environment**; legacy Telescope rows do not reliably prove it, and it is never guessed from the current app environment:

```sh
php artisan exception-monitor:backfill --environment=production --limit=100 --dry-run
php artisan exception-monitor:backfill --environment=production --limit=100
```

Replace `production` when appropriate. Default output is a readable page summary with candidate/import/duplicate/skip/pending/failure counts and copyable next commands. Dry runs distinguish importing the current page from previewing the next page, so previewed records are not accidentally skipped. Sequence IDs are cursors, not exception totals or a completion percentage. Continue the same snapshot with the printed command:

```sh
php artisan exception-monitor:backfill --environment=production --after=1234 --through=9876 --limit=100
```

The numbers above are examples; use the command printed by your run. Add `--json` for the original machine-readable result fields (`through`, `next_after`, `has_more` and counters); failures also return a structured JSON error and a nonzero exit status. Each invocation scans at most 1–1,000 source exceptions and may stop earlier at the existing source byte budget. Repeat pages safely after interruption. Dry run validates candidate selection without writing package data or generating keys; it does not preview payload text or guarantee every payload can subsequently be sanitized/encrypted.

The command sanitizes and encrypts evidence before buffering, then attempts ingestion inline in the CLI. A worker may claim buffered work concurrently; pending/retryable work uses the existing capture recovery path. Rows carrying package markers are skipped: use normal recovery/reconciliation for those. Malformed, oversized and future-dated rows are skipped with counts, never raw content. Source rows/tags are not changed, and normal reconciliation checkpoints are not advanced.

Imported occurrences have `historical_import` provenance and an explicit Historical import label. History-only groups show Historical until a live occurrence arrives. Original timestamps are preserved using `capture.source_timezone` (otherwise the app timezone); legacy reportability is marked unavailable. Current deployment release/source-link metadata is never retroactively attached. Imported history contributes to lifetime totals and retained event-time charts but cannot reopen/unsnooze an existing issue, generate retrospective alert events/deliveries/GitHub creation, or inflate a subsequent live alert's frequency count.

Identity is deduplicated by installation, source epoch and Telescope UUID, including repeated/concurrent runs and replay after evidence expiry. Totals count retained exception UUIDs; they do not reconstruct pruned history or multiply a row by denormalized family counters. Retention still applies from the original event time: very old records may have only a sanitized group summary and occurrence identity, with no retained snapshot. Purge generation/sequence/time fences prevent restoration of deliberately purged history. Disabled package capture, maintenance, source identity changes and unknown database connections are not bypassed. Explicit user actions on imported issues remain normal authorized actions and may notify according to current rules.

## Context, activity and optional SQL templates

The occurrence tab is **Context & activity**: execution metadata, descriptive request/job/command entries, grouped database measurements and related logs. It does not invent event timestamps or chronological ordering. Query measurements always have duration units when available; entries without SQL stay collapsed separately from readable templates. Empty tags are hidden and technical coverage is under Data availability.

SQL templates are **off by default**. Enable **Settings → Capture & privacy → Sanitized SQL templates** with execution metadata enabled. Alternatively, `capture.sql_templates` sets the deployment default; a saved privacy preference overrides that default. To prohibit retention, lock `privacy.retain_sql_templates` to false. Template output defaults to 2,048 bytes, controlled by `capture.sql_max_bytes` and capped at 4,096 bytes; input inspection is bounded at 16 KiB. Oversized statements are omitted entirely rather than partially retaining them.

Templates are generated before encrypted buffering. Supported string, numeric, hex, bit, boolean/null and named parameter values become placeholders; bindings and comments are not copied. A conservative lexer accepts a subset of single-statement SELECT/INSERT/UPDATE/DELETE SQL for mysql/mariadb/sqlite query drivers. Ambiguous double quotes, backslash-escape syntax, executable/nested comments, unusual identifiers, variables, dollar quoting, unsupported statements/drivers and multiple statements are omitted. Schema identifiers and function names remain visible; templates are diagnostic text, never executed. If configured redaction changes a template, it is omitted. This deliberately trades coverage for privacy when quoting cannot be interpreted safely. Reference syntax: [MySQL string literal modes](https://dev.mysql.com/doc/refman/8.4/en/string-literals.html) and [SQL lexical distinctions](https://www.postgresql.org/docs/current/sql-syntax-lexical.html).

Current policy is applied again during ingestion and evidence reads. Disabling retention hides previously stored templates; sanitized ciphertext follows ordinary retention rather than immediate physical erasure. Enabling later cannot reconstruct older snapshots whose SQL was never retained. Those show an explicit older-snapshot message and a Telescope link where original evidence may still exist. Exception/log messages continue using the existing application-specific redaction rules. Telescope's own source storage remains governed separately.

Restart capture/delivery workers after updating this code before testing new captures. No database migration is required. Existing envelopes without SQL/activity fields remain readable; older workers must be restarted to recognize new fields. No retrospective source enrichment or automatic rewrite of old evidence occurs.

## Investigation and lifecycle

`/exception-monitor/overview` is the dashboard landing page. The inbox at `/exception-monitor/errors` defaults to unresolved groups across observed environments, most recently seen first. Select a range (1h, 24h, 7d or 30d) for event-time counts; older unresolved groups remain visible with zero in-range occurrences until you explicitly filter first/last-seen dates. Lifetime totals are separate. Status, environment, origin, exact class, permitted tag, text and date filters are validated; unknown filters are rejected. Search covers sanitized class/message literally, and terms appear in the authenticated page URL/browser history. Page sizes are 25, 50 or 100, with stable ID tie-breaking.

Origin filtering finds groups with captured identity metadata, while tag filtering uses unexpired retained evidence. Displayed range counts cover all origins in each matching group; filtering a group does not manufacture origin-specific historical aggregates. Trends are captured-event counts, not a request error rate. Current buckets and retention/collection coverage are explicitly partial. Test groups require the Include test events control and remain labeled.

Opening a group selects its latest retained occurrence, or its latest identity if no evidence remains. The `occurrence` URL parameter selects a specific identity; a foreign occurrence returns 404. Changing it replaces all evidence panels together, cancels stale reads and preserves inbox filters through the back link. Evidence expiry/decryption failures retain the header/history, and activity/retained-data insights remain accessible. Insights only use unexpired retained records and never label an unavailable route/release/user category as zero impact. The original Telescope link can be stale after source pruning. Notes are available in Notes & activity; GitHub controls explain their later integration phase.

Lifecycle actions require `operate` as well as `view`. Every action carries the current `state_version` and a UUID idempotency key; count-only updates do not invalidate an operator edit. A stale state or key reused for a different canonical request returns 409 with permitted current metadata. Network retries reuse their pending key. Accepted changes commit state, receipt and actor activity together. Resolve records a persistent cutoff; ties/older captures preserve it. Reopen and unsnooze raise suppression at the actual action time, not a future deadline. Already-running external sends cannot be recalled. Destinations and rules require explicit configuration.

Snooze offers 1h, 24h, 7d or a future ISO timestamp with an explicit UTC offset. Stored times are UTC, displayed times follow the configured dashboard timezone, and explicit offsets avoid ambiguous daylight-saving input. Expiry is enforced on ingestion and lifecycle writes even without a scheduler. Reads show an expired snooze as unresolved without mutating data. Schedule bounded physical expiry:

```php
Schedule::command('exception-monitor:expire-snoozes --limit=100')->everyMinute()->withoutOverlapping();
```

The command accepts 1–10,000 rows, records each actual expiry once and sends no retrospective alerts. Error activity currently exposes the latest 100 records; retained insight categories are capped at 20 values.

Optional code-host links require deployment-managed immutable release metadata, captured when the exception is recorded:

```php
'capture' => [
    // Keep the other published capture options.
    'release' => env('EXCEPTION_MONITOR_RELEASE'), // An immutable commit/tag, not a moving branch.
    'source_link_template' => 'https://github.com/acme/store/blob/{release}/{file}#L{line}',
],
```

Templates must use HTTPS with a fixed host, contain `{release}` and `{file}`, and include no credentials or query string. Paths/releases are encoded; absolute/traversal/vendor source paths do not receive guessed links. Existing evidence is never backfilled from current deployment configuration, and no source preview reads today's files. Named route/template projections are only captured from the authoritative live HTTP route when host RequestWatcher policy permits; request payloads remain excluded. Restart long-lived workers when deployment configuration changes.

Phase 3 reused the capture schema; Phase 4 adds the collaboration migration and advances schema version to 3. Follow the ordinary install/migrate upgrade procedure and restart workers when updating the package.

## Notes and saved views

On upgrade, rerun `exception-monitor:install` and `migrate`; publishing adds only the missing collaboration migration and preserves existing configuration. This creates notes, saved_views and view_preferences and an assignment lookup index. Existing installation identity, capture data and settings remain intact.

Notes belong to an error across all occurrences. Viewers read notes; current operators add/edit their own notes; authors with operate or administrators can delete after confirmation. Bodies are plain text, normalized for line endings, privacy-redacted, limited to 10,000 Unicode characters and encrypted at rest. Safe credential-free HTTP(S) links open as anchors; HTML is literal text and mentions have no delivery behavior. Edits use row versions. A stale save retains the in-memory draft and offers reload/new-note recovery; leaving the issue prompts before discarding. Drafts never enter URLs/localStorage. Deleting clears ciphertext and retains metadata only. Notes outlive snapshot retention. Activity and notification snapshots contain note IDs, never note text or previous body revisions.

Saved views store a validated schema-versioned definition, sort and relative range rather than results. Viewers can manage their own personal definitions. Operators/administrators publish shared views; owners edit their shared definitions and administrators may moderate shared definitions. Administrators cannot inspect another user's personal view by guessing its ID. Changing loaded filters shows Modified until an explicit Update or Save a copy. Rename preserves filters, deletion changes no error data, and conflicts preserve typed names. Names are limited to 80 characters; deployment privacy redaction also applies to names/search text before persistence.

Favorites, order and one default reference are personal, versioned and limited to 100 shortcuts. Effective preferences recheck access and hide deleted, inaccessible or unsupported references. They do not grant access. The default applies to a bare inbox; explicit URL criteria override it. Unsupported schema/feature/tag definitions show Needs repair and cannot run until repaired or the relevant deployment support returns. Short view URLs hydrate the same validated filters into the visible controls. View lists and notes are paginated; supported definitions cannot contain SQL, arbitrary expressions, note content fields or selected-occurrence routing state.

## Optional host assignments

Assignments are **off by default**. Enable them in the host's published deployment configuration and supply a container-resolvable adapter:

```php
'assignments' => [
    'enabled' => true,
    'directory' => App\Monitoring\MonitoringDirectory::class,
],
```

The adapter implements `TelescopeMonitor\Collaboration\AssignmentDirectory`:

```php
use TelescopeMonitor\Collaboration\AssignmentDirectory;
use TelescopeMonitor\Data\Actor;

final class MonitoringDirectory implements AssignmentDirectory
{
    /** @return list<Actor> */
    public function search(string $query, int $limit): array
    {
        // Query your eligible monitoring operators; return at most $limit actors.
        // Each Actor has a stable non-recycled key and a display label.
    }

    public function find(string $key): ?Actor
    {
        // Recheck current eligibility. Return null for removed/ineligible identities.
    }
}
```

These method bodies are a host-adapter outline, not a runnable user model implementation. Use your own database/directory, restrict results to eligible operators, cap results and apply short timeouts to remote lookups. Keys are opaque, case-sensitive Unicode strings up to 191 characters; labels are at most 120 characters. Prefer the same stable namespace used by your ActorResolver. No package-owned user records or host-user foreign keys are created. Optional `actor_label` is a deployment callback for displaying resolved note authors; unresolved identities show Former user, and lookup failures show Identity unavailable.

Only an operator with view access can assign/clear. Directory eligibility is checked before the transaction; assignment never grants dashboard permission. No directory enumeration runs during exception capture or bootstrap. Lookup failure is visible, preserves the current reference and allows clearing without a provider lookup. A disabled provider hides controls and assignee filters while preserving references/history; re-enabling exposes them again. Reads do not silently clear removed users. Assignment increments issue state_version and records actor/previous/new metadata atomically, with external activity notifications available only through explicitly opted-in alert rules.

Inbox filters support Assigned to me, Unassigned, or a chosen eligible host actor. The canonical explicit filter is `assignee=actor:<opaque-key>`; `me` and `unassigned` are symbolic filters. Saved assignee filters require configured assignment support and otherwise enter repair state. Restart long-lived workers after deployment configuration changes.

For local UI verification only, `EXCEPTION_MONITOR_WORKBENCH_ASSIGNMENTS=1 vendor/bin/testbench serve --host=127.0.0.1 --port=8196` enables a development-only sample directory. The workbench adapter is excluded from consumer archives and refuses non-local environments. Normal package configuration remains disabled until the host explicitly configures it.

## Diagnostics

```sh
php artisan exception-monitor:diagnose
php artisan exception-monitor:diagnose --json
```

Nonzero exit means a warning, failure or unverified condition remains. This is expected until capture, worker processing and maintenance have been observed. A configured queue does not prove a worker is processing, and an empty overview does not prove the application has no errors.

## Development workbench

```sh
composer install
npm ci
npm run build
composer workbench:build
vendor/bin/testbench serve --host=127.0.0.1 --port=8195
```

Open `http://127.0.0.1:8195/exception-monitor/setup`. This uses a disposable SQLite database, a local-only demonstration actor, the array mailer and no seeded external integration connections. Integration tests use their selected transport; only the separately labeled controlled preview disables external HTTP. Its provider is not Composer-discovered by consumers and denies access outside local/workbench environments. Installation seeds no example errors. The local preview may contain explicitly labeled synthetic occurrences created during verification; normal investigation views exclude them by default.

To exercise Phase 5 in this workbench, run the capture and delivery workers in separate terminals:

```sh
php vendor/bin/testbench queue:work --queue=exception-monitor-capture --timeout=60 --tries=1
php vendor/bin/testbench queue:work --queue=exception-monitor-deliveries --timeout=45 --tries=1
```

Open `/exception-monitor/settings/email`, add an enabled test address, select it and confirm **Send synthetic test**. Check its outcome under `/exception-monitor/alerts/history`; the array mailer sends nothing outside this process. Build/preview/save rules at `/exception-monitor/alerts`. Automatic rules default to production, so a local capture will not trigger a production rule. You can run `php vendor/bin/testbench exception-monitor:recover-deliveries --limit=100` to exercise redispatch. Workbench addresses and rules are disposable fixtures, not installed host configuration.


Build tooling uses Node 22.12+ or a compatible current Node release. `npm run build` typechecks, builds versioned assets under `dist`, and copies dependency license notices. The release contains `dist`; consumers do not run npm.

## Verification

```sh
composer test
composer analyse
vendor/bin/pint --test
npm run typecheck
npm run test:unit
npm run build
```

Use a disposable MySQL/MariaDB database for concurrency checks; the suite creates and rolls back its own package and Telescope tables:

```sh
MONITOR_DB=mysql DB_PORT=3306 DB_DATABASE=telescope_monitor_test DB_PASSWORD=testing composer test
```

Forked settings, capture, lifecycle and collaboration concurrency tests require `pcntl`; SQLite skips those five checks. Capture tests cover real HTTP, sync/terminal jobs and console failures, source filters, synthetic probes, replay, rollback, expiry, malformed envelopes and recovery. Read [the replacement progress record](docs/replacement-progress.md) for measured checks and remaining gates.

## Prototype recovery

The local ignored `.archive/prototype-2026-11-11.tar.gz` contains the previous source, tests, development configuration, approved planning copies and an online backup of its disposable workbench SQLite database. Its sibling manifest records file hashes. Additional old Testbench-generated fixtures discovered during cleanup are preserved under `.archive/legacy-testbench`.

Restore an archive into a separate empty directory for inspection; do not unpack it over the replacement or approved documents. The archive, caches and IDE files are excluded from release archives. No host-application database or external service was reset during the cleanup.

## License

MIT. Not an official Laravel package.

## Email alerts and recovery (Phase 5)

After updating the package, run `php artisan exception-monitor:install` and `php artisan migrate` to publish the missing additive alerting migration. Existing configuration is preserved. This brings package storage to schema version 4 (23 physical tables).

Configure your host Laravel mailer, sender address and credentials using its normal mail configuration. The package uses `mail.default`, or the named mailer in `exception-monitor.alerts.mailer`. Configure that transport's connection/read timeout to at most 15 seconds. API/SMTP credentials remain in the host, never dashboard fields. Use `array` for local non-network checks; a successful array/log transport cannot prove external delivery.

Deployment configuration defaults to:

```php
'alerts' => [
    'enabled' => true,
    'allowed_environments' => ['production'],
    'mailer' => null, // Host default, or an explicit configured Laravel mailer.
    'max_cooldown_seconds' => 86400,
    'max_threshold_count' => 100000,
    'pending_seconds' => 86400,
],
```

Select **Settings → Email notifications**, add and enable each recipient, select its checkbox and explicitly confirm a synthetic test. Tests use the configured host transport and require current manage access both when queued and when a worker claims them. The UI polls for a bounded period; unfinished results remain available in **Alerts → Delivery history**. Sending a test does not create a rule or notify other recipients.

Create a rule under **Alerts**, choose events, allowed environments, optional exact class/origin/permitted tag, recipients and cooldown, preview, then explicitly enable and save. The starter draft is disabled and has no recipient. Automatic alerts default to production; add local/staging explicitly to the deployment allowlist if needed. A preview sends nothing. Saved changes use versions and preserve stale/unsaved drafts.

Run a supervised delivery worker in addition to capture processing:

```sh
php artisan queue:work --queue=exception-monitor-deliveries --timeout=45 --tries=1
```

The dispatcher supports Laravel database and Redis queues. Use the configured `exception-monitor.queues.connection` consistently with the worker. Keep queue `retry_after` above the 45-second worker timeout; claims/reservations last 120 seconds. Queue jobs contain identifiers only. Host mail listeners run under Telescope recording suppression. Sync/null/unsupported queues leave delivery intent pending; they never send automatically within a monitored request.

Add these bounded commands to the host's scheduler alongside capture maintenance:

```php
Schedule::command('exception-monitor:recover-deliveries --limit=100')->everyMinute()->withoutOverlapping();
Schedule::command('exception-monitor:prune-deliveries --limit=1000')->hourly()->withoutOverlapping();
```

For an explicit CLI processing pass with a controlled transport, use `php artisan exception-monitor:deliver --limit=100`. This command can send through the configured host mailer. Recovery only redispatches eligible work; a broker failure leaves the ledger recoverable. System health distinguishes observed processing/recovery from configuration and does not claim that workers remain alive.

Delivery rules and recovery behavior:

- One canonical email address is one destination: trim and lowercase the complete ASCII address, without plus/dot folding. Its encrypted target and fingerprint are immutable. Replacement disconnects the old destination and creates/reuses a distinct target; choose it explicitly in rules. Pending work is never redirected.
- Overlapping rules coalesce one occurrence/recipient intent and retain each supporting rule. New/regression takes precedence over a simultaneous threshold. Threshold windows are fixed 5/15/60-minute UTC buckets, count retained matching projections and fire on crossing. Reconciliation/history and synthetic capture checks do not generate automatic email.
- Claims recheck current installation generation, rules, target, state/snooze, privacy and required initiating permissions. Rule edits/deletion, recipient disconnection and lifecycle suppression can cancel pending work. A transport call already in flight cannot be recalled.
- Cooldown is shared by error/canonical recipient/event family. Waiting consumes no provider attempt. A regression can defer until cooldown ends; an ordinary threshold within cooldown is suppressed. Activity has distinct event-family eligibility, so a resolved notification remains meaningful while resolved.
- A retry cycle permits five provider attempts, with 60/300/900/3600-second backoff and a later provider Retry-After honored. Duplicate jobs and active leases do not consume attempts. Recovery is **at least once**: a crash after transport acceptance but before the local commit can duplicate an email. Unknown acceptance is recorded honestly.
- The sanitized logical payload freezes before the first attempt. Export excludes stack/context/request/user/note bodies. Unchanged retries reuse its delivery ID and frozen payload. A stricter privacy policy cancels incompatible retries; **Review and resend** requires confirmation of the current sanitized preview and creates a new delivery identity. Failed delivery retries are operator actions with durable audit receipts.
- History masks recipient addresses for viewers; only administrators configure full addresses. Provider errors are classified into safe codes without raw exception or credential logging. `sent` means transport acceptance, **not inbox receipt**. Retention removes terminal deliveries/attempts and then unreferenced expired events; pending work expires after its configured lifetime.

Real SMTP/API credentials, live inbox receipt and provider-specific behavior still require a host-owned acceptance check. Phase 6 extends this delivery boundary for Slack/webhooks and explicit GitHub issue workflows, described below.

## Slack, signed webhooks and GitHub Issues (Phase 6)

Run the package installer and migrations after updating. The additive integration migration creates linked-issue reservations, adds destination throttling and staged-test key references, and advances storage to **schema version 5 (24 physical tables)**. Configuration and existing published migration identities are preserved. PHP's **cURL extension** is now a Composer requirement for bounded HTTPS requests with TLS verification and destination-address pinning. Consumers still need no Node.js or Testbench.

Use **Integrations** for connection setup. Administrators create/enable, version/edit, disconnect or replace a target. Operators create/link GitHub tickets through an enabled repository; viewers can inspect safe metadata/history. Stored tokens and endpoint URLs are never redisplayed. Changing a target creates a distinct canonical connection and cancels eligible old work; rules do not silently gain the replacement. Label changes do not move destinations.

Use the existing `exception-monitor-deliveries` worker, recovery and pruning commands. There is one ledger, attempt budget and provider interface. HTTP requests have a five-second connect timeout, fifteen-second total request timeout, no hidden retries and no redirects. A GitHub read check can perform two bounded GET requests. Keep the 45-second worker timeout below queue visibility/retry time and the 120-second claim lease. DNS resolution uses the host resolver; its availability/timeouts remain host-managed. Requests suppress Telescope recording and never persist raw response bodies, Authorization headers or transport exceptions.

### Slack

Configure a display name and an incoming webhook URL of the supported shape `https://hooks.slack.com/services/…/…/…`. The URL is an encrypted credential. The display name is supplied by an administrator and is not independently verified Slack channel identity. Select Slack connections alongside email/webhooks in **Alerts**. Explicit synthetic tests queue a labeled message and expose its durable outcome.

Imported exception text is escaped, Slack markup/automatic mention parsing is disabled, and links/media do not unfurl. Claims serialize across all errors for one canonical Slack target and enforce at least one second between completed requests; provider throttling and later Retry-After are honored. Incoming-webhook acceptance is recorded separately from connection setup. OAuth, interactive Slack actions and GovSlack are outside this release. See the official [incoming-webhook contract](https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/) and [rate-limit guidance](https://docs.slack.dev/apis/web-api/rate-limits/).

### Signed webhooks

Configure an HTTPS endpoint, enabled state and allowed events, then select the connection in an alert rule. The connection's event selection and the rule must both permit an automatic event. Tests use `monitor.test` independently of automatic event selection. The endpoint is encrypted; only its safe origin is shown. Existing rule support, current event selection and export policy are rechecked before attempts.

The first signing secret is generated once. Copy it to the receiver and acknowledge it. Rotation is explicit: **Stage signing key → configure receiver → Test staged key → acknowledge the one-time key → Activate key**. Staging does not activate the key. A labeled test may use the staged key without changing normal delivery. A new credential action cannot overwrite an unacknowledged one-time secret. Lost one-time responses cannot retrieve a stored secret: stage another revision.

The sender switches new attempts to the active revision. The previous revision has a displayed 24-hour transition deadline; receivers should accept both key IDs during that interval, then remove the old key. In-flight requests can carry the prior key. Scheduled pruning clears expired retiring ciphertext/references in bounded batches while preserving metadata and currently active keys.

The [webhook receiver contract](docs/webhook-contract-v1.md) defines the UTF-8 JSON fields, exact-body HMAC headers, timestamp validation and transactional delivery-ID deduplication. Retries retain frozen body bytes, event ID and logical delivery ID while using a fresh attempt timestamp/signature and the current signing key. A stricter privacy policy cancels incompatible frozen work; it does not rewrite signed bytes under the same identity.

Network policy is deployment-owned:

```php
'integrations' => [
    'credentials' => [],
    'webhooks' => [
        'ports' => [443],
        'private_targets' => [
            // Explicit opt-in for an exact host AND its resolved address range.
            // 'hooks.internal.example' => ['10.20.0.0/16'],
        ],
    ],
],
```

Userinfo, fragments, unsupported schemes/ports and unapproved private/reserved addresses are rejected. Every attempt resolves A/AAAA records, validates all returned addresses against current policy and pins the selected address while verifying the original TLS hostname. Mixed public/private answers fail closed. Redirects, ambient proxies and connection reuse cannot move a credential-bearing request to another destination. Link-local metadata addresses are denied. The UI cannot override these rules. These controls follow the [OWASP SSRF guidance](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html); live DNS/TLS and deployment egress policy still require host acceptance checks.

### GitHub Issues

Configure a public GitHub owner/repository and a repository-scoped fine-grained token. The connection check queues read-only repository metadata and up to 100 existing labels; it creates no ticket and cannot conclusively prove write access. Creation requires the token's repository Issues write permission. Tokens can be replaced for the same repository; the repository cannot be redirected through token replacement. GitHub App authentication and Enterprise/custom API origins are deferred. Requests use `api.github.com` and the supported `2026-03-10` API contract. See [GitHub issue endpoints](https://docs.github.com/en/rest/issues/issues) and [provider failure/rate-limit guidance](https://docs.github.com/en/rest/using-the-rest-api/troubleshooting-the-rest-api).

From an error's **Linked issues** tab:

1. Choose Create GitHub issue, a configured repository, optional title and observed existing labels.
2. Preview the exact sanitized title/body, including a stable installation/error/operation marker and an investigation link. Context, stack traces and notes are excluded. This preview sends nothing.
3. Explicitly confirm. The preview's digest/current policy are rechecked, its bytes freeze, and one durable repository reservation prevents duplicate clicks/workers from scheduling independent creation.
4. Refresh local status or open the creation delivery. Validated acceptance stores the remote number, canonical URL and last-known state. Local Resolve/Ignore never closes/reopens a ticket.

**Link existing issue** validates a positive repository issue number or canonical `https://github.com/owner/repo/issues/number` URL by a read-only request. Unrelated repositories and pull requests are rejected. Multiple local errors can deliberately link the same ticket. Explicit refresh preserves last-known state when the provider is unavailable; unavailable does not mean closed. **Unlink locally** removes only the association after confirmation, never the external ticket, and retains its operation history.

A timeout, missing/invalid success response, server-side ambiguity, expired creation lease or lost local outcome commit becomes **Needs review**. Such creation never retries blindly. Check repository inspects a bounded page and never treats absence as proof of non-creation. Resolve it by linking an existing issue or reviewing a new attempt with an explicit, audited confirmation that no ticket exists. A new attempt receives a new ID/marker and a parent reference; it may still duplicate a remotely accepted issue. Definitive provider rejections and throttling use classified bounded retries, not ambiguous-creation recovery. User-requested writes reauthorize the initiator at worker execution.

Optional automatic creation is off by default. Enable it per connection and select explicit deployment-allowed environments. It handles new live error groups only, never reconciliation or synthetic captures. Repeated occurrences and regressions keep the existing association. A prior reservation or unlink tombstone is never implicitly recreated by capture.

Deployment-managed credentials use an allowlisted reference, not an arbitrary environment-variable name supplied by a request:

```php
'integrations' => [
    'credentials' => [
        'production-repository' => [
            'purpose' => 'github_token',
            'value' => env('MONITOR_REPOSITORY_TOKEN'),
        ],
        'oncall-slack' => [
            'purpose' => 'endpoint',
            'value' => env('MONITOR_SLACK_WEBHOOK'),
        ],
    ],
    'webhooks' => ['ports' => [443], 'private_targets' => []],
],
```

The dashboard selects an approved reference. Its value is loaded at execution, not copied into ciphertext or serialized into a job. A changed Slack reference cannot redirect old work because the runtime endpoint must still match its immutable target fingerprint. A GitHub reference may rotate its token for the same repository.

### Controlled local preview

This session's separate preview runs at `http://127.0.0.1:8197/exception-monitor/integrations`, with an isolated temporary SQLite fixture and explicitly controlled HTTP/DNS adapters. It labels itself **Controlled preview · no external requests are sent**. Its development provider and fixtures are excluded from consumer archives and deny access outside local/workbench environments. The normal workbench stays separate.

The preview already contains example connections and an error with linked/Needs review examples. Its worker uses `EXCEPTION_MONITOR_WORKBENCH_INTEGRATIONS=1`; `php vendor/bin/testbench workbench:integration-mode` under that flag confirms that external I/O is disabled. A normal production installation does not use these adapters. Real Slack delivery, webhook receiver processing, GitHub permissions and inbox receipt were not exercised against live accounts during this phase.

## Operations, retention and purge (Phase 7)

Update the package, run `php artisan exception-monitor:install`, then `php artisan migrate`. The additive operations migration delivers the final logical table, purge_runs, and irreversible retention cutoffs: **schema version 6 / 25 physical tables**. Fresh installation metadata also reflects the available schema. Existing configuration, migration identities and credentials are preserved.

The setup checklist now records real completion. It requires installation/recording readiness, reviewed application/privacy preferences, a recent current-generation synthetic capture with readable evidence, an actual accepted test on an enabled notification target **or an explicit skip**, and operations review. Tests use the actual queue/capture path; a synchronous queue does not manufacture success. GitHub read-only checks do not count as notification delivery. A webhook proof must match its currently active signing key. Optional notifications/assignments remain visible, and a passed connection test never silently enables an alert rule. Purge invalidates previous-generation setup proof; source rebaselining requires a new capture proof for the new epoch.

**Settings → Retention** shows effective values, value sources, locks and deployment bounds. A reduction requires a bounded impact preview in the UI before saving. Estimates are dated eligible-row counts, may be capped as “at least,” and are not a promise of deletion in one batch. The actual next scheduled prune is unknown inside the host; no future execution time is invented. **Settings → Operations & maintenance** shows starts, successes, failures, bounded run history, backlogs/expired leases, credential availability, source boundaries and CLI recovery guidance. A stale or start-only observation does not prove a living worker. A local heartbeat may be invoked manually and does not independently monitor the host.

Add the local heartbeat and unified retention sweep to the existing host scheduler:

```php
Schedule::command('exception-monitor:heartbeat')->everyMinute()->withoutOverlapping();
Schedule::command('exception-monitor:prune --limit=1000')->hourly()->withoutOverlapping();
```

Keep capture/delivery recovery, reconciliation and snooze expiry scheduled as previously documented. `prune` now covers the full retained graph; `prune-deliveries` remains available for only delivery/event cleanup. Maintenance is bounded by family and records real starts/outcomes/counters. Configuration checks are separate from these observations.

Defaults are occurrence evidence 30 days, completed delivery/attempt history 30 days, hourly aggregates 90 days and activity/security history 90 days. Five-minute trends retain a fixed 48 hours. Deployment limits can tighten supported ranges:

```php
'retention_limits' => [
    'occurrence_days' => ['min' => 1, 'max' => 30], // Supported 1–90.
    'delivery_days' => ['min' => 1, 'max' => 30],   // Supported 1–90.
    'aggregate_days' => ['min' => 30, 'max' => 180], // Supported 30–365.
    'activity_days' => ['min' => 1, 'max' => 90],  // Supported 1–365.
],
'operations' => [
    'freshness_seconds' => 300,
    'maintenance_seconds' => 3600,
    'preview_limit' => 10000,
],
```

The server enforces bounds and locks again under the configuration fence. Incompatible ranges fail visibly instead of substituting a weaker limit. Dashboard changes persist monotonic expiry cutoffs, so increasing retention cannot rehydrate previously expired evidence. A record's existing expiry remains a cap; a later increase applies to new records/buckets. When changing deployment-enforced limits, restart long-running workers and run retention under the stricter policy before subsequently relaxing that deployment policy. Saved database policy is reread by processing; correctness does not depend solely on cache invalidation.

Normal retention clears eligible evidence/tags, metrics, terminal delivery/attempt/event history, activity, receipts and expired buffer bytes. It preserves summaries/lifetime counts, minimal occurrence UUID identity, notes, saved views/preferences and GitHub associations. Pending references and uncertain GitHub reservations hold needed records; healthy live leases are not destroyed. Completed inbox cleanup does not invent failed captures, and delayed jobs cannot ingest an expired buffer. All tables can require repeated bounded runs after a long maintenance gap.

Purge and source rebaselining are **deployment commands, never dashboard Delete buttons**. Start with the read-only preview and read the complete scope in the [operations guide](docs/operations-guide-v1.md). This phase's destructive verification ran only against isolated copies; neither the normal workbench nor its earlier preview/database was purged.

```sh
php artisan exception-monitor:purge INSTALLATION_UUID --preview
php artisan exception-monitor:purge INSTALLATION_UUID --confirm=INSTALLATION_UUID --limit=500 --batches=1
php artisan exception-monitor:purge INSTALLATION_UUID --confirm=INSTALLATION_UUID --resume=RUN_UUID --limit=500 --batches=100
```

An incomplete run prints its status and stays draining/purging. Resume the same ID until verification completes. Old queued jobs/claim completions cannot commit through the generation fence. Source sequence/time boundaries prevent old Telescope rows from resurrecting data. Post-cutoff signed source entries can be recovered as history after resume, without historical alerts. Settings, credentials, alert rules, saved views/preferences, installation identity and minimal receipts/purge ledger survive; error-domain data is cleared. Telescope source and already accepted external messages/tickets remain external.

If Telescope storage resets or moves, inspect before explicitly rebaselining:

```sh
php artisan exception-monitor:source-baseline INSTALLATION_UUID --preview
php artisan exception-monitor:source-baseline INSTALLATION_UUID --confirm=INSTALLATION_UUID
```

Rebaselining changes the source epoch, excludes existing source rows and preserves prior time protection. It preserves existing monitor error data. It does not silently erase purge cutoffs or retroactively alert historical data.

For host-key rotation, configure the current `APP_KEY` and supported `APP_PREVIOUS_KEYS` first, then rewrap **all** encrypted targets in bounded pages. Keep previous keys until every target has completed, not just secrets. The command preserves plaintext/digests/logical IDs/revisions and reports unreadable ciphertext without blanking it. See [Laravel's encryption-key guidance](https://laravel.com/framework/docs/13.x/encryption#gracefully-rotating-encryption-keys).

```sh
php artisan exception-monitor:rewrap INSTALLATION_UUID secrets --confirm=INSTALLATION_UUID --limit=100 --after=0
```

Repeat for `inbox`, `evidence`, `events`, `deliveries` and `notes`; use each returned `next_cursor` while `has_more` is true. A failed row is preserved, the command fails visibly, and it must be retried from cursor zero after restoring the required key. This workflow is distinct from webhook signing-key rotation. The dashboard never edits host keys or .env. Restart workers after code/deployment changes, following [Laravel's worker deployment guidance](https://laravel.com/framework/docs/13.x/queues#queue-workers-and-deployment).

This session's separate operational preview is `http://127.0.0.1:8198/exception-monitor/settings/operations`. It uses a copied temporary SQLite fixture and controlled transports, selected only by the development flag `EXCEPTION_MONITOR_WORKBENCH_OPERATIONS=1`. Old fixture records were aged solely to exercise retention preview. No real provider requests were sent; no production or original workbench data was purged. The current release report records additional real queue/transport verification and the remaining compatibility, crash, UI and workload gates. Publication remains held.
