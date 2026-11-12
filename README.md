<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/logo-dark.svg">
    <img src="art/logo.svg" width="440" height="96" alt="Telescope Monitor">
  </picture>
</p>

<p align="center">Turn Telescope exceptions into an error-monitoring workflow, inside your Laravel application.</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777BB4" alt="Requires PHP 8.3 or newer">
  <img src="https://img.shields.io/badge/Laravel-13-FF2D20" alt="Requires Laravel 13">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-4040C8" alt="MIT license"></a>
</p>

<p align="center">
  <a href="#installation">Installation</a> ·
  <a href="#running-in-production">Running in production</a> ·
  <a href="#your-first-error">Your first error</a> ·
  <a href="#configuration">Configuration</a> ·
  <a href="#documentation">Documentation</a>
</p>

Telescope Monitor groups exceptions, preserves sanitized investigation evidence, and helps your team resolve errors and receive useful alerts. It uses your application's Telescope access policy, mailer and queue configuration. The dashboard ships with compiled assets: no frontend setup is needed in your app.

**Release candidate:** available on [Packagist](https://packagist.org/packages/telescope-monitor/telescope-monitor). Use the explicit RC constraint below for evaluation. Verified combinations and remaining stable-release gates are recorded in the [verification report](docs/release-verification-v1.0.0-rc.1.md).

## What you get

| Investigate | Respond | Connect |
| --- | --- | --- |
| Grouped errors, occurrences and trends | Resolve, reopen, ignore and snooze | Email and Slack alerts |
| Stack frames, request/job context and activity | Notes, saved views and optional assignments | Signed webhooks |
| Optional sanitized SQL templates | New-error, regression, frequency and activity rules | Create or link GitHub Issues |

**Zero monitoring database queries in host capture.** Hooks prepare signed metadata in memory. Telescope performs its existing storage work; a separate importer handles monitoring reads, writes, sanitization and alert planning. The monitoring dashboard and explicit monitoring commands intentionally query storage.

```text
Your app → Telescope storage → Importer → Error dashboard
                                      ↘ Delivery queue → Notifications
```

Telescope is the initial durable buffer. Keep its records long enough for the importer to catch up; evidence becomes independent of Telescope after successful buffering.

## Requirements

| Dependency | Requirement |
| --- | --- |
| PHP | 8.3+ with cURL, mbstring and your database PDO driver |
| Laravel | 13.42+ within the supported 13.x series |
| Laravel Telescope | 5.27+ within the supported 5.x series, enabled with ExceptionWatcher |
| Queue | Laravel database or Redis queue for notifications and queued setup checks |
| Background processes | A supervised importer, queue workers and Laravel's scheduler |

These are Composer constraints; verified combinations and remaining compatibility gates are documented in the release report. Node.js is needed only when developing this package.

## Installation

### 1. Install Telescope first

If Telescope is already installed and recording exceptions, continue to step 2. Otherwise, follow [Laravel's Telescope installation guide](https://laravel.com/docs/13.x/telescope#installation) and configure it for your environment. It must be available as a production dependency when monitoring production.

Telescope's filters still decide what is stored. An exception filtered out by Telescope cannot be imported automatically. For production, see [retain context only for failed batches](#retain-context-only-for-failed-batches) to keep related queries and logs without persisting successful executions.

### 2. Add this package with Composer

Run this from your Laravel application directory:

```bash
composer require telescope-monitor/telescope-monitor:^1.0@RC
```

The RC stability flag applies only to this package. Consumers do not need a custom repository or a frontend build. For local package development, use a Composer path repository pointing at your checkout.

### 3. Choose storage and install

By default, package tables use Telescope's database connection, falling back to Laravel's default connection. To use a separate database, define a named connection in `config/database.php` and set this **before migrating**:

```dotenv
TELESCOPE_MONITOR_DB_CONNECTION=monitoring
```

Leave it unset to inherit Telescope's connection. `monitoring` is a Laravel connection name, not a database name; provision the database and permissions first.

```bash
php artisan telescope-monitor:install
php artisan telescope-monitor:migrate
```

Use `php artisan telescope-monitor:migrate --force` in production. The installer publishes configuration and missing package migrations; the migration command runs only those package migrations on the selected connection. You do not need to publish or build frontend assets.

<details>
<summary>Moving from the earlier pre-release name?</summary>

This pre-release uses a clean naming reset. Configuration, environment variables, commands, routes, queues, capture markers and all 25 package tables use Telescope Monitor names. There are no compatibility aliases or automatic data conversion.

Stop old monitoring processes, back up existing data, and prepare a fresh package schema and `config/telescope-monitor.php`. Review/remove the old package's published migration files before publishing the renamed migrations; update host schedules and queue names. Remove stale configuration/route caches and restart host workers. Do not reset a shared application database to perform this change. Existing monitoring history is not automatically transferred.

</details>

### 4. Open the dashboard

```text
https://your-app.test/telescope-monitor/setup
```

Access follows your existing Telescope authorization policy. No separate monitoring login is added. With Laravel's default Telescope policy, local access is permitted and non-local access uses `viewTelescope`.

All Telescope-authorized visitors receive monitoring view, operate and manage access by default. Anonymous authorized visitors share one operator identity, including ownership of personal views and notes. See [access configuration](docs/configuration-reference.md#dashboard-access) for separate roles and worker authorization.

## Running in production

Run the following processes from your application's directory, using Supervisor or your existing process manager. Use the same deployment configuration and signing/encryption keys in all processes.

### Importer

```bash
php artisan telescope-monitor:import --watch --process --limit=100 --sleep=2
```

The importer handles bounded source pages, processes due inbox records, then waits two seconds between passes. Initial backlogs may take multiple passes. Increase `--limit` deliberately when catching up.

<details>
<summary>Example Supervisor configuration for the importer</summary>

Replace the application path, PHP executable and user with your deployment values. The log directory must exist and be writable.

```ini
[program:telescope-monitor-importer]
directory=/var/www/your-app
command=/usr/bin/php artisan telescope-monitor:import --watch --process --limit=100 --sleep=2
user=www-data
autostart=true
autorestart=true
startsecs=5
stopsignal=TERM
stopasgroup=true
killasgroup=true
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=/var/www/your-app/storage/logs/telescope-monitor-importer.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
```

Supervise the queue workers below as separate programs too. Restart the importer explicitly on deployment; `php artisan queue:restart` only restarts Laravel queue workers.

</details>

### Queue workers

Run each command in a separate process:

```bash
php artisan queue:work --queue=telescope-monitor-capture --timeout=60 --tries=1
php artisan queue:work --queue=telescope-monitor-deliveries --timeout=45 --tries=1
```

| Worker | Purpose |
| --- | --- |
| `telescope-monitor-capture` | Executes queued synthetic capture checks and dispatched capture work. Ordinary inbox processing is also handled by the importer's `--process` option. |
| `telescope-monitor-deliveries` | Sends eligible email, Slack, webhook and GitHub operations. |

Use a database or Redis queue, not `sync`. Workers inherit the host queue connection unless `telescope-monitor.queues.connection` selects another one; pass that connection to `queue:work` as well. Keep its `retry_after`/visibility timeout above the worker timeout. For the standard Laravel database queue, `DB_QUEUE_RETRY_AFTER=180` is a starting value if all workers using that connection have shorter timeouts.

Keep your application's ordinary workers unchanged. The delivery ledger manages its own retries, which is why these workers use `--tries=1`.

### Scheduler and retention

Add these to `routes/console.php` (or your application's existing scheduler), keeping unrelated application schedules:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('telescope-monitor:recover --limit=100')
    ->hourly()->withoutOverlapping();

Schedule::command('telescope-monitor:recover-deliveries --limit=100')
    ->everyMinute()->withoutOverlapping();

Schedule::command('telescope-monitor:expire-snoozes --limit=100')
    ->everyFiveMinutes()->withoutOverlapping();

Schedule::command('telescope-monitor:prune --limit=1000')
    ->hourly()->withoutOverlapping();

Schedule::command('telescope-monitor:prune-deliveries --limit=1000')
    ->hourly()->withoutOverlapping();

Schedule::command('telescope:prune --hours=24')
    ->hourly()->withoutOverlapping();
```

Ensure the server runs Laravel's scheduler every minute:

```cron
* * * * * cd /var/www/your-app && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

- **Capture recovery is a safety net:** `--process` already handles due inbox work; scheduled recovery also redispatches stranded synthetic checks.
- **Delivery recovery is needed:** it queues eligible retries, deferred notifications and expired processing claims. The delivery worker does the sending.
- **Pruning is bounded:** large cleanup backlogs may need repeated passes. The general prune includes delivery cleanup; the separate delivery prune is available for targeted maintenance.
- **Telescope retention is separate:** this example keeps source data for 24 hours, including exceptions. Replace duplicate pruning schedules; `--keep-exceptions` would retain exceptions indefinitely. Increase the window if importer outages can last longer. Source pruned before import cannot be recovered.

Do not also schedule import when Supervisor runs it. `reconcile` is a manual, non-alerting repair scan, not the normal ingestion schedule. Heartbeat is optional and is omitted here.

Prefer a scheduler instead of Supervisor? Replace watch mode with `Schedule::command('telescope-monitor:import --limit=100 --process')->everyMinute()->withoutOverlapping();`. Use one ingestion approach.

## Your first error

1. Start the importer and both queue workers, then finish `/telescope-monitor/setup`.
2. Review **Settings → Capture & privacy** and **Retention**. Run the synthetic capture check; its test occurrence is excluded from the normal inbox.
3. Trigger an exception using an existing application test command or a development-only route. Confirm that Telescope stored it, then check **Errors** after the importer catches up.
4. Configure your Laravel mailer, add an enabled recipient under **Settings → Email notifications**, and send an explicit synthetic delivery test.
5. Create and enable an alert rule, select the matching environment and recipient, and trigger its event. Automatic rules allow `production` by default; add other environments to `alerts.allowed_environments` when needed.
6. Check **Alerts → Delivery history**. `Sent` means the transport accepted the message; confirm actual inbox receipt separately.

A new Telescope entry is an **occurrence**, not necessarily a new error group. Default grouping uses environment, exception class, application-relative file and line. Changing only the message does not create a new group. For a regression test, resolve a group and trigger that same exception again.

### Check capture isolation and recovery

Pause the importer, trigger a test exception and inspect Telescope. The exception should be stored without any `telescope_monitor_*` queries from the host capture path; it will not reach the monitor until import runs. Resume the importer or run:

```bash
php artisan telescope-monitor:import --limit=100 --process
```

Repeat the pass: the same source UUID must not increase the occurrence count. Run this test within your Telescope retention window.

### Import older Telescope history

Unmarked exceptions from before installation require explicit backfill:

```bash
php artisan telescope-monitor:backfill --environment=production --limit=100 --dry-run
php artisan telescope-monitor:backfill --environment=production --limit=100
```

Use the original source environment, and follow the printed continuation commands for further pages. Historical imports are sanitized and deduplicated; they do not trigger retrospective alerts. Missing or expired evidence cannot be reconstructed. [Backfill and recovery details](docs/telescope-source-capture.md).

## Configuration

The published file is `config/telescope-monitor.php`. Common deployment settings are:

| Setting | Default / behavior |
| --- | --- |
| `TELESCOPE_MONITOR_ENABLED` | `true`; enables the package |
| `TELESCOPE_MONITOR_PATH` | `telescope-monitor` |
| `TELESCOPE_MONITOR_DB_CONNECTION` | Inherits Telescope's connection, then Laravel's default |
| `TELESCOPE_MONITOR_CAPTURE_KEY` | Optional deployment signing key; otherwise derives from `APP_KEY` |
| `TELESCOPE_MONITOR_RELEASE` | Optional release identifier captured with new exceptions |
| `alerts.allowed_environments` | `['production']` |
| `queues.connection` | Host queue connection |

Configure application identity, privacy, retention and recipients through the dashboard. Access policy, database/queue connections, infrastructure credentials, signing keys and deployment locks remain host-managed. Keep `APP_URL` correct for dashboard links in notifications.

SQL templates are off by default. Enable **Settings → Capture & privacy → Sanitized SQL templates** when needed. Supported values and bindings are excluded; identifiers remain visible, and unsupported SQL is omitted. Package privacy is enforced during import, processing and reads; it does not configure Telescope's independent source privacy.

Use **Integrations** to configure Slack, signed webhooks and GitHub Issues, then choose notification destinations in alert rules. GitHub creation/linking is explicit by default. Optional assignments require a host directory adapter and are disabled until configured.

See the [configuration reference](docs/configuration-reference.md) for role gates, separate databases, deployment locks, SQL behavior, assignments, email settings and integration contracts. Keep previous signing/encryption keys during rotation as described in the [capture](docs/telescope-source-capture.md#signing-and-rotation) and [operations](docs/operations-guide-v1.md) guides.

### Retain context only for failed batches

Telescope can collect relevant entries in memory, then persist them only when the same execution batch contains a reportable exception or failed job. This keeps successful production batches out of Telescope storage while retaining useful failure context. See [Telescope batch filtering](https://laravel.com/framework/docs/13.x/telescope#filtering).

In `app/Providers/TelescopeServiceProvider.php`, **replace the existing entry-filter registration** with the following entry and batch filters inside `register()`. Keep your existing sensitive-request hiding, authorization and other provider setup. Do not append this alongside the old restrictive entry filter: every registered filter must pass, and a batch filter cannot restore entries already discarded.

```php
use Illuminate\Support\Collection;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

// Inside your existing register() method:
$isLocal = $this->app->environment('local');

Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
    return $isLocal || in_array($entry->type, [
        'exception',
        'request',
        'job',
        'command',
        'query',
        'log',
    ], true);
});

Telescope::filterBatch(function (Collection $entries) use ($isLocal) {
    return $isLocal || $entries->contains(
        fn (IncomingEntry $entry) =>
            $entry->isReportableException() ||
            $entry->isFailedJob()
    );
});
```

In `config/telescope.php`, merge these entries into the existing `watchers` array, preserving any application-specific exclusions and other watcher options. The namespace import is `use Laravel\Telescope\Watchers;`.

```php
'watchers' => [
    Watchers\ExceptionWatcher::class => true,
    Watchers\RequestWatcher::class => [
        'enabled' => true,
        'size_limit' => 64,
    ],
    Watchers\JobWatcher::class => true,
    Watchers\CommandWatcher::class => [
        'enabled' => true,
    ],
    Watchers\QueryWatcher::class => [
        'enabled' => true,
        'ignore_packages' => true,
        'slow' => 100,
    ],
    Watchers\LogWatcher::class => [
        'enabled' => true,
        'level' => 'info',
    ],
],
```

`slow => 100` labels slow queries; it does not restrict collection to slow queries. Log level `info` includes informational breadcrumbs and higher-severity messages; use `error` for less collection, or `debug` only when that extra detail is needed. Disabled watchers, ignored paths and entries below the configured log level cannot be recovered by batch filtering.

| Production execution | Retained in Telescope |
| --- | --- |
| Successful batch without a reportable exception or failed job | No batch entries |
| Batch containing a reportable exception or failed job | Available exception, request/job/command, query and log entries |
| Local environment | All entries from enabled watchers, subject to their exclusions |

A caught exception that is never reported cannot trigger this filter. To also retain HTTP 500+ responses without a reportable exception, add `$entry->isFailedRequest()` to the batch predicate. This setup intentionally replaces the earlier policy that retained scheduled-task or monitored-tag batches independently of failures.

**Storage reduction does not remove instrumentation overhead.** Watchers still collect entries in memory during execution, and very large failed batches can still produce substantial writes. Keep the [24-hour pruning schedule](#scheduler-and-retention), monitor actual volume, and avoid enabling unrelated watchers unnecessarily.

**Telescope source privacy is separate from monitor privacy.** Retained failure batches can contain raw SQL, bindings, request data or log context. Keep Telescope's sensitive-data exclusions and avoid logging secrets. The importer sanitizes what enters the monitor, not Telescope's own tables. Enable **Settings → Capture & privacy → Sanitized SQL templates** if you want readable SQL templates in the monitor; otherwise retained queries provide measurements without SQL text. Monitor evidence limits can truncate large batches.

After deploying these changes, refresh cached configuration and restart long-running host workers/Octane processes so the new filters and watcher settings take effect. Test one successful execution and one reported failure: only the failure batch should remain in Telescope in production, then appear with available context after import.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Error is in Telescope but not the monitor | Importer is running, source has a supported signed marker, configuration matches, and the initial backlog has been imported. Use backfill for older unmarked rows. |
| Error is absent from Telescope | Telescope/ExceptionWatcher is enabled and host filters accept the exception. |
| Capture check stays pending | Asynchronous queue is configured and the capture worker is listening to the correct connection/queue. |
| No notification | Enabled rule, matching event/environment, enabled destination, delivery worker, cooldown and delivery-history reason. |
| Delivery remains retrying | Keep `recover-deliveries` scheduled and the delivery worker running. |
| Access denied or synthetic delivery unauthorized | Current Telescope policy must authorize both the browser and the documented monitoring worker context. |
| Source identity/sequence warning | Inspect source storage and use the explicit rebaseline workflow; do not reset checkpoints or purge history as a quick fix. |

```bash
php artisan telescope-monitor:diagnose
php artisan schedule:list
```

After code or deployment configuration changes, refresh cached configuration as appropriate, restart host queue/Octane processes, and restart the importer. The installer preserves existing configuration: merge new settings deliberately.

## Documentation

| Guide | Covers |
| --- | --- |
| [Configuration reference](docs/configuration-reference.md) | Access, storage, privacy, investigation, collaboration and integrations |
| [Capture and importer](docs/telescope-source-capture.md) | Signing, process boundaries, recovery, key rotation and purge safety |
| [Operations guide](docs/operations-guide-v1.md) | Retention, maintenance, purge and encryption-key rotation |
| [Webhook receiver contract](docs/webhook-contract-v1.md) | Payloads, signatures and receiver deduplication |
| [Release verification](docs/release-verification-v1.0.0-rc.1.md) | Tested environments, acceptance evidence and remaining release gates |
| [Architecture](docs/technical-architecture-v1.md) | Design decisions and storage boundaries |

## Developing the package

In the package checkout, use Node.js 22.12+ (CI uses Node 24):

```bash
composer install
npm ci
composer workbench:build
php vendor/bin/testbench serve --host=127.0.0.1 --port=8195
```

Open `http://127.0.0.1:8195/telescope-monitor/setup`. Run importer/workers through `php vendor/bin/testbench` in place of `php artisan`. The workbench uses development configuration and the non-network array mailer; consumers do not install Node.js or Testbench.

```bash
composer test
composer analyse
composer format:check
npm run typecheck
npm run test:unit
npm run build
```

Use only a disposable database for engine/concurrency tests; the suite creates and rolls back package and Telescope tables. See [verification notes](docs/release-verification-v1.0.0-rc.1.md) for the engine matrix and limitations. Do not commit credentials or real exception data in examples, fixtures or screenshots.

## License

Telescope Monitor is available under the [MIT license](LICENSE). The README logo reuses the dashboard's [Heroicons sparkle mark](dist/licenses/heroicons.txt).
