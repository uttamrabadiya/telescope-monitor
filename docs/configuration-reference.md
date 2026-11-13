# Configuration reference

Start with the [installation and running guide](../README.md). This reference covers optional configuration and detailed behavior. All examples use the `telescope-monitor` package name.


## Dashboard access

By default, monitoring routes use Laravel Telescope's own `Authorize` middleware and `Telescope::check($request)`. The package does not add Laravel's session-login `auth` middleware. With Laravel's default Telescope application provider, local access is allowed and other environments use `viewTelescope`. A custom `Telescope::auth(...)` callback is honored, including a callback that denies local access. Configure Telescope's existing access policy; no separate monitor login route is required.

```php
'middleware' => ['web'],
'authorization' => [
    'driver' => 'telescope',
],
```

All Telescope-authorized visitors have view, operate and manage capabilities in this default mode. Web sessions and CSRF protection remain enabled. Existing published configurations are preserved: remove `auth` from the monitoring middleware array to adopt this mode, then clear/rebuild configuration and route caches. Open `/telescope-monitor/setup` directly.

Signed-in users retain their provider-backed identity. Authorized visits without a user use a stable **Telescope operator (shared)** identity; no host user record is created and nobody is signed in automatically. Guest notes and personal saved views belong to that shared identity, so authorized guests share their ownership. Existing account-owned private views remain with their original account.

Queued manual work re-resolves the saved user (or uses an actual guest context for a shared operator) and calls the current `Telescope::check()` policy. There is no separate guest-job opt-in. The legacy `allow_guest_jobs` setting is ignored and can be removed from published configuration. The worker scope supplies no browser cookies, authorization headers or invented loopback address, and restores the host request/auth/gate state afterward. Default local and gate-based policies work without a separate login; revoked/throwing policies fail closed before provider I/O. Request-only policies must explicitly support the marked `telescope-monitor.worker` context through the host's Telescope policy rather than relying on replayed browser credentials. Restart workers after policy/code changes.

### Request-restricted Telescope policies

If `viewTelescope` checks HTTP server fields, a queue worker has no address/port, session or browser credentials. Explicitly opt trusted monitoring workers into that policy using the package's scoped helper. For example, preserve an existing `127.0.0.1:8081` browser restriction:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use TelescopeMonitor\Auth\QueuedTelescopeAuthorization;

Gate::define('viewTelescope', function (?Authenticatable $user = null): bool {
    $request = request();

    if (app(QueuedTelescopeAuthorization::class)->isWorkerContext($request)) {
        return true;
    }

    return $request->server('SERVER_ADDR') === '127.0.0.1'
        && (string) $request->server('SERVER_PORT') === '8081';
});
```

The helper requires an active, package-owned isolated CLI authorization scope and the exact scoped request object. A header, query parameter, copied request or forged `telescope-monitor.worker` attribute cannot activate it. Scope is restored after successful or failed policy evaluation. No fake HTTP server values are supplied. This is an explicit host opt-in for trusted server workers; retain any per-user restrictions your worker policy also requires.

Install the package version containing this helper before deploying a gate that calls it, and restart monitoring queue workers/importers afterward. Previously cancelled checks remain cancelled; generate a new sample. Normal browser authorization and queued installation/generation checks remain enforced.

For applications needing separate viewer/operator/admin roles, opt into `authorization.driver = 'gates'` and define the existing capability gates:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewTelescopeMonitor', fn ($user) => $user->can('view-operations'));
Gate::define('operateTelescopeMonitor', fn ($user) => $user->can('operate-monitoring'));
Gate::define('manageTelescopeMonitor', fn ($user) => $user->can('manage-monitoring'));
```

Replace the predicates with actual host permissions. Gate mode requires a resolvable authenticated actor, and each capability also requires view access. Add the host's login middleware if desired. Custom hosts can bind `TelescopeMonitor\Auth\ActorResolver`; the package does not require a particular User model. Unknown authorization drivers deny access.


## Database storage

Monitoring storage resolves in this order:

1. `telescope-monitor.database.connection`, configured by `TELESCOPE_MONITOR_DB_CONNECTION`.
2. `telescope.storage.database.connection`.
3. Laravel's `database.default` when neither setting names a connection.

Leave the override unset to keep all monitoring tables alongside Telescope. To use a different database, define a **named Laravel connection** in `config/database.php`, for example `monitoring`, and set:

```dotenv
TELESCOPE_MONITOR_DB_CONNECTION=monitoring
```

This variable is a connection name, not a database name or URL. Credentials and the actual database name remain in the host's Laravel connection configuration. An unknown or invalid name fails; it never silently redirects writes to the primary database. Provision the database and permissions before installation; the package creates its tables, not the database server/catalog.

Configure storage before the first install, then run:

```sh
php artisan telescope-monitor:install
php artisan telescope-monitor:migrate
```

The dedicated migration command runs only the six published package migrations, recording them on the effective monitoring connection. For a connection different from the host default, new migrations publish under `database/migrations/telescope-monitor` so ordinary host migration discovery does not run them accidentally. Already published filenames are preserved across both locations. `--pretend` and production `--force` are supported. Do not run all host migrations against the monitoring database.

Models, queries, transactions, after-commit callbacks, schema checks, retention and purge use the same effective connection. Package foreign keys remain within that database. Host users, sessions, cache and queue storage keep their existing configuration; the queue connection is a separate setting. Telescope source reads still use Telescope's own storage configuration even when the monitoring override points elsewhere.

Changing the connection selects a different installation; it does **not** copy existing history or relocate tables. Existing installations require a planned transfer of the complete package schema/data and migration ledger with workers stopped, or an explicitly fresh setup on the new connection. Preserve encryption keys. Rebuild cached configuration and restart workers after configuration changes. Do not drop old tables until a separately verified transfer is complete.

Separate databases do not provide a distributed transaction between Telescope source writes, monitoring records and queue broker writes. Durable receipts and recovery still apply, but source filtering/pruning, storage outages and missing signed markers remain capture limits. The same-database default minimizes those coordination concerns.


## Configuration

Publishable configuration lives in `config/telescope-monitor.php`. The path, auth guard, middleware, capability names, queue selection and deployment locks are host-managed. Dashboard preferences are stored in package tables.

A deployment can lock a preference:

```php
'locked' => [
    'privacy.identify_users' => false,
    'retention.occurrence_days' => 14,
],
```

The UI identifies these values as deployment-managed. The canonical dashboard URL is derived from the trusted host `app.url` and package path and is locked. No request Host header supplies notification link configuration. Preference edits require the current version; stale edits return a conflict and preserve the browser draft.

For an alternate dashboard path, configure `TELESCOPE_MONITOR_PATH=operations/monitor`. Root, wildcard and traversal paths are invalid. The package excludes only its exact routes/descendants from Telescope collection and registers its fixed private-envelope redaction key without replacing host entry/batch filter callbacks.

Privacy preferences restrict capture before durable buffering and are checked again at ingestion. Retention sweeps must be scheduled explicitly. Infrastructure credentials remain host-managed. Email uses the host mailer and explicitly configured destinations; SMTP credentials remain host-managed; integration credentials use encrypted connection records.


## Context, activity and optional SQL templates

The occurrence tab is **Context & activity**: execution metadata, descriptive request/job/command entries, grouped database measurements and related logs. It does not invent event timestamps or chronological ordering. Query measurements always have duration units when available; entries without SQL stay collapsed separately from readable templates. Empty tags are hidden and technical coverage is under Data availability.

SQL templates are **off by default**. Enable **Settings → Capture & privacy → Sanitized SQL templates** with execution metadata enabled. Alternatively, `capture.sql_templates` sets the deployment default; a saved privacy preference overrides that default. To prohibit retention, lock `privacy.retain_sql_templates` to false. Template output defaults to 2,048 bytes, controlled by `capture.sql_max_bytes` and capped at 4,096 bytes; input inspection is bounded at 16 KiB. Oversized statements are omitted entirely rather than partially retaining them.

Templates are generated before encrypted buffering. Supported string, numeric, hex, bit, boolean/null and named parameter values become placeholders; bindings and comments are not copied. A conservative lexer accepts a subset of single-statement SELECT/INSERT/UPDATE/DELETE SQL for mysql/mariadb/sqlite query drivers. Ambiguous double quotes, backslash-escape syntax, executable/nested comments, unusual identifiers, variables, dollar quoting, unsupported statements/drivers and multiple statements are omitted. Schema identifiers and function names remain visible; templates are diagnostic text, never executed. If configured redaction changes a template, it is omitted. This deliberately trades coverage for privacy when quoting cannot be interpreted safely. Reference syntax: [MySQL string literal modes](https://dev.mysql.com/doc/refman/8.4/en/string-literals.html) and [SQL lexical distinctions](https://www.postgresql.org/docs/current/sql-syntax-lexical.html).

Current policy is applied again during ingestion and evidence reads. Disabling retention hides previously stored templates; sanitized ciphertext follows ordinary retention rather than immediate physical erasure. Enabling later cannot reconstruct older snapshots whose SQL was never retained. Those show an explicit older-snapshot message and a Telescope link where original evidence may still exist. Exception/log messages continue using the existing application-specific redaction rules. Telescope's own source storage remains governed separately.

Restart capture/delivery workers after updating this code before testing new captures. No database migration is required. Existing envelopes without SQL/activity fields remain readable; older workers must be restarted to recognize new fields. No retrospective source enrichment or automatic rewrite of old evidence occurs.


## Reproduce evidence

Context & activity includes an operator-only, collapsed Reproduce section. Deployment-managed `reproduce.default_mode` selects redacted/selected-values/full-payload capture globally; exact route/command policies override it. Credentials remain excluded. Replay evidence is encrypted before Telescope storage, sanitized again on import/processing/read, and follows occurrence evidence retention. No host capture database queries or additional migrations are introduced. See the [replay guide](reproduce.md) for configuration and supported payloads.

## Investigation and lifecycle

`/telescope-monitor/overview` is the dashboard landing page. The inbox at `/telescope-monitor/errors` defaults to unresolved groups across observed environments, most recently seen first. Select a range (1h, 24h, 7d or 30d) for event-time counts; older unresolved groups remain visible with zero in-range occurrences until you explicitly filter first/last-seen dates. Lifetime totals are separate. Status, environment, origin, exact class, permitted tag, text and date filters are validated; unknown filters are rejected. Search covers sanitized class/message literally, and terms appear in the authenticated page URL/browser history. Page sizes are 25, 50 or 100, with stable ID tie-breaking.

Origin filtering finds groups with captured identity metadata, while tag filtering uses unexpired retained evidence. Displayed range counts cover all origins in each matching group; filtering a group does not manufacture origin-specific historical aggregates. Trends are captured-event counts, not a request error rate. Current buckets and retention/collection coverage are explicitly partial. Test groups require the Include test events control and remain labeled.

Opening a group selects its latest retained occurrence, or its latest identity if no evidence remains. The `occurrence` URL parameter selects a specific identity; a foreign occurrence returns 404. Changing it replaces all evidence panels together, cancels stale reads and preserves inbox filters through the back link. Evidence expiry/decryption failures retain the header/history, and activity/retained-data insights remain accessible. Insights only use unexpired retained records and never label an unavailable route/release/user category as zero impact. The original Telescope link can be stale after source pruning. Notes are available in Notes & activity; GitHub actions are available through configured integrations.

Lifecycle actions require `operate` as well as `view`. Every action carries the current `state_version` and a UUID idempotency key; count-only updates do not invalidate an operator edit. A stale state or key reused for a different canonical request returns 409 with permitted current metadata. Network retries reuse their pending key. Accepted changes commit state, receipt and actor activity together. Resolve records a persistent cutoff; ties/older captures preserve it. Reopen and unsnooze raise suppression at the actual action time, not a future deadline. Already-running external sends cannot be recalled. Destinations and rules require explicit configuration.

Snooze offers 1h, 24h, 7d or a future ISO timestamp with an explicit UTC offset. Stored times are UTC, displayed times follow the configured dashboard timezone, and explicit offsets avoid ambiguous daylight-saving input. Expiry is enforced on ingestion and lifecycle writes even without a scheduler. Reads show an expired snooze as unresolved without mutating data. Schedule bounded physical expiry:

```php
Schedule::command('telescope-monitor:expire-snoozes --limit=100')->everyFiveMinutes()->withoutOverlapping();
```

The command accepts 1–10,000 rows, records each actual expiry once and sends no retrospective alerts. Error activity currently exposes the latest 100 records; retained insight categories are capped at 20 values.

Optional code-host links require deployment-managed immutable release metadata, captured when the exception is recorded:

```php
'capture' => [
    // Keep the other published capture options.
    'release' => env('TELESCOPE_MONITOR_RELEASE'), // An immutable commit/tag, not a moving branch.
    'source_link_template' => 'https://github.com/acme/store/blob/{release}/{file}#L{line}',
],
```

Templates must use HTTPS with a fixed host, contain `{release}` and `{file}`, and include no credentials or query string. Paths/releases are encoded; absolute/traversal/vendor source paths do not receive guessed links. Existing evidence is never backfilled from current deployment configuration, and no source preview reads today's files. Named route/template projections are only captured from the authoritative live HTTP route when host RequestWatcher policy permits; request payloads remain excluded. Restart long-lived workers when deployment configuration changes.

Use the current installation/migration procedure and restart workers after package updates.


## Notes and saved views

On upgrade, rerun `telescope-monitor:install` and `migrate`; publishing adds only the missing collaboration migration and preserves existing configuration. This creates notes, saved_views and view_preferences and an assignment lookup index. Existing installation identity, capture data and settings remain intact.

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

## Email alerts and delivery

Configure your host Laravel mailer, sender address and credentials using its normal mail configuration. The package uses `mail.default`, or the named mailer in `telescope-monitor.alerts.mailer`. Configure that transport's connection/read timeout to at most 15 seconds. API/SMTP credentials remain in the host, never dashboard fields. Use `array` for local non-network checks; a successful array/log transport cannot prove external delivery.

Deployment configuration defaults to:

```php
'alerts' => [
    'enabled' => true,
    'allowed_environments' => ['production'],
    'mailer' => null, // Host default, or an explicit configured Laravel mailer.
    'email' => [
        'include_context' => true,
        'include_stack_trace' => true,
        'max_frames' => 8,
        'allowed_tags' => [], // Explicit email export allowlist; empty by default.
    ],
    'max_cooldown_seconds' => 86400,
    'max_threshold_count' => 100000,
    'pending_seconds' => 86400,
],
```

Emails use a responsive Telescope-themed HTML template with a plain-text alternative. Subjects identify the application/error group, exception class and sanitized message, for example `DEMO-APP-1H - App\Exceptions\WidgetNotFoundException: Widget not found`. The email includes event time, captured count, first/last seen, exception location and available retained route template, job or command metadata. Application stack frames are bounded (eight by default, at most twelve); arguments and source snippets are never exported. Missing or expired evidence and older frozen deliveries fall back to the summary without inventing details. Synthetic tests are clearly labeled.

Email context freezes on the first delivery attempt; retries reuse it even if evidence later expires. Current privacy restrictions are rechecked before sending, and a stricter policy cancels an incompatible frozen delivery with `export_policy_changed`. Set `alerts.email.include_context` to `false` for summary-only email, or disable `include_stack_trace` separately. Tags require both `capture.allowed_tags` and the separate `alerts.email.allowed_tags`; only explicitly approved, non-sensitive tags should be allowed. Raw request URLs/query strings, headers, bodies, user identities, SQL and note text are excluded. Release metadata is included only when retained at capture.

For an existing installation, merge these optional email settings into the published configuration as needed, clear/rebuild the host configuration cache and restart long-running queue workers (`php artisan queue:restart`). The richer email needs no migration. Test through **Settings → Email notifications** using the configured host mailer. Browser checks cover desktop/mobile layout; Gmail/Outlook inbox rendering still needs a host-owned delivery check.

Select **Settings → Email notifications**, add and enable each recipient, select its checkbox and explicitly confirm a synthetic test. Tests use the configured host transport and require current manage access both when queued and when a worker claims them. The UI polls for a bounded period; unfinished results remain available in **Alerts → Delivery history**. Sending a test does not create a rule or notify other recipients.

Create a rule under **Alerts**, choose events, allowed environments, optional exact class/origin/permitted tag, recipients and cooldown, preview, then explicitly enable and save. The starter draft is disabled and has no recipient. Automatic alerts default to production; add local/staging explicitly to the deployment allowlist if needed. A preview sends nothing. Saved changes use versions and preserve stale/unsaved drafts.

Run a supervised delivery worker in addition to capture processing:

```sh
php artisan queue:work --queue=telescope-monitor-deliveries --timeout=45 --tries=1
```

The dispatcher supports Laravel database and Redis queues. Use the configured `telescope-monitor.queues.connection` consistently with the worker. Keep queue `retry_after` above the 45-second worker timeout; claims/reservations last 120 seconds. Queue jobs contain identifiers only. Host mail listeners run under Telescope recording suppression. Sync/null/unsupported queues leave delivery intent pending; they never send automatically within a monitored request.

Add these bounded commands to the host's scheduler alongside capture maintenance:

```php
Schedule::command('telescope-monitor:recover-deliveries --limit=100')->everyMinute()->withoutOverlapping();
Schedule::command('telescope-monitor:prune-deliveries --limit=1000')->hourly()->withoutOverlapping();
```

For an explicit CLI processing pass with a controlled transport, use `php artisan telescope-monitor:deliver --limit=100`. This command can send through the configured host mailer. Recovery only redispatches eligible work; a broker failure leaves the ledger recoverable. System health distinguishes observed processing/recovery from configuration and does not claim that workers remain alive.

Delivery rules and recovery behavior:

- One canonical email address is one destination: trim and lowercase the complete ASCII address, without plus/dot folding. Its encrypted target and fingerprint are immutable. Replacement disconnects the old destination and creates/reuses a distinct target; choose it explicitly in rules. Pending work is never redirected.
- Overlapping rules coalesce one occurrence/recipient intent and retain each supporting rule. New/regression takes precedence over a simultaneous threshold. Threshold windows are fixed 5/15/60-minute UTC buckets, count retained matching projections and fire on crossing. Reconciliation/history and synthetic capture checks do not generate automatic email.
- Claims recheck current installation generation, rules, target, state/snooze, privacy and required initiating permissions. Rule edits/deletion, recipient disconnection and lifecycle suppression can cancel pending work. A transport call already in flight cannot be recalled.
- Cooldown is shared by error/canonical recipient/event family. Waiting consumes no provider attempt. A regression can defer until cooldown ends; an ordinary threshold within cooldown is suppressed. Activity has distinct event-family eligibility, so a resolved notification remains meaningful while resolved.
- A retry cycle permits five provider attempts, with 60/300/900/3600-second backoff and a later provider Retry-After honored. Duplicate jobs and active leases do not consume attempts. Recovery is **at least once**: a crash after transport acceptance but before the local commit can duplicate an email. Unknown acceptance is recorded honestly.
- The sanitized logical payload freezes before the first attempt. Email may include bounded, privacy-permitted retained execution metadata and application stack frames; request payloads, user identities, SQL, source code and note bodies remain excluded. Other channel payloads are unchanged. Unchanged retries reuse its delivery ID and frozen payload. A stricter privacy policy cancels incompatible retries; **Review and resend** requires confirmation of the current sanitized preview and creates a new delivery identity. Failed delivery retries are operator actions with durable audit receipts.
- History masks recipient addresses for viewers; only administrators configure full addresses. Provider errors are classified into safe codes without raw exception or credential logging. `sent` means transport acceptance, **not inbox receipt**. Retention removes terminal deliveries/attempts and then unreferenced expired events; pending work expires after its configured lifetime.

Use an explicit host-owned test to verify real SMTP/API credentials and inbox receipt; transport acceptance alone does not prove delivery to an inbox.


## Slack, signed webhooks and GitHub Issues

Use **Integrations** for connection setup. Administrators create/enable, version/edit, disconnect or replace a target. Operators create/link GitHub tickets through an enabled repository; viewers can inspect safe metadata/history. Stored tokens and endpoint URLs are never redisplayed. Changing a target creates a distinct canonical connection and cancels eligible old work; rules do not silently gain the replacement. Label changes do not move destinations.

Use the existing `telescope-monitor-deliveries` worker, recovery and pruning commands. There is one ledger, attempt budget and provider interface. HTTP requests have a five-second connect timeout, fifteen-second total request timeout, no hidden retries and no redirects. A GitHub read check can perform two bounded GET requests. Keep the 45-second worker timeout below queue visibility/retry time and the 120-second claim lease. DNS resolution uses the host resolver; its availability/timeouts remain host-managed. Requests suppress Telescope recording and never persist raw response bodies, Authorization headers or transport exceptions.

### Slack

Configure a display name and an incoming webhook URL of the supported shape `https://hooks.slack.com/services/…/…/…`. The URL is an encrypted credential. The display name is supplied by an administrator and is not independently verified Slack channel identity. Select Slack connections alongside email/webhooks in **Alerts**. Explicit synthetic tests queue a labeled message and expose its durable outcome.

Imported exception text is escaped, Slack markup/automatic mention parsing is disabled, and links/media do not unfurl. Claims serialize across all errors for one canonical Slack target and enforce at least one second between completed requests; provider throttling and later Retry-After are honored. Incoming-webhook acceptance is recorded separately from connection setup. OAuth, interactive Slack actions and GovSlack are outside this release. See the official [incoming-webhook contract](https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/) and [rate-limit guidance](https://docs.slack.dev/apis/web-api/rate-limits/).

### Signed webhooks

Configure an HTTPS endpoint, enabled state and allowed events, then select the connection in an alert rule. The connection's event selection and the rule must both permit an automatic event. Tests use `monitor.test` independently of automatic event selection. The endpoint is encrypted; only its safe origin is shown. Existing rule support, current event selection and export policy are rechecked before attempts.

The first signing secret is generated once. Copy it to the receiver and acknowledge it. Rotation is explicit: **Stage signing key → configure receiver → Test staged key → acknowledge the one-time key → Activate key**. Staging does not activate the key. A labeled test may use the staged key without changing normal delivery. A new credential action cannot overwrite an unacknowledged one-time secret. Lost one-time responses cannot retrieve a stored secret: stage another revision.

The sender switches new attempts to the active revision. The previous revision has a displayed 24-hour transition deadline; receivers should accept both key IDs during that interval, then remove the old key. In-flight requests can carry the prior key. Scheduled pruning clears expired retiring ciphertext/references in bounded batches while preserving metadata and currently active keys.

The [webhook receiver contract](webhook-contract-v1.md) defines the UTF-8 JSON fields, exact-body HMAC headers, timestamp validation and transactional delivery-ID deduplication. Retries retain frozen body bytes, event ID and logical delivery ID while using a fresh attempt timestamp/signature and the current signing key. A stricter privacy policy cancels incompatible frozen work; it does not rewrite signed bytes under the same identity.

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

Enter a repository-scoped fine-grained personal access token, load its accessible repositories, and select a repository with Issues enabled. Repository discovery is a bounded, paginated read from github.com; it does not save the token until the connection is saved or prove Issues write access. Archived/disabled repositories are omitted. Deployment credential references and manual owner/repository entry remain available. GitHub organization policies may require token approval. The connection check queues read-only repository metadata and up to 100 existing labels; it creates no ticket and cannot conclusively prove write access. Creation requires the token's repository Issues write permission. Tokens can be replaced for the same repository; the repository cannot be redirected through token replacement. GitHub App authentication and Enterprise/custom API origins are deferred. Requests use `api.github.com` and the supported `2026-03-10` API contract. See [GitHub issue endpoints](https://docs.github.com/en/rest/issues/issues) and [provider failure/rate-limit guidance](https://docs.github.com/en/rest/using-the-rest-api/troubleshooting-the-rest-api).

From an error's **Linked issues** tab:

1. Choose Create GitHub issue, a configured repository, optional title and observed existing labels.
2. Preview the exact sanitized title/body, including a stable installation/error/operation marker and an investigation link. The export includes occurrence count, first/last seen UTC timestamps, status at export, and permitted retained release/execution details and bounded application stack frames. Request bodies, headers, cookies, SQL, user identities and notes are excluded. `integrations.github.include_context`, `include_stack_trace` and `max_frames` control this optional evidence independently of email; capture privacy still applies. Missing evidence falls back to the summary. Existing tickets are snapshots and are not automatically updated. This preview sends nothing.
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

## Dashboard theme

Monitor reads `Laravel\Telescope\Telescope::$useDarkTheme` when rendering the
page. Enable dark mode with `Telescope::night()` in the host Telescope service
provider. When the flag is `false` (the Telescope default), Monitor uses its light
palette. This applies to all dashboard routes, including setup, forms, charts and
investigation views. Reload the page after changing the host theme. No separate
Monitor configuration key, environment variable, or browser preference is used.
