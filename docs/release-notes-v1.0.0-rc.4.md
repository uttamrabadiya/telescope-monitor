# 1.0.0-rc.4

Release candidate of **telescope-monitor/telescope-monitor**, prepared on
14 November 2026. Requires PHP 8.3+, Laravel at the declared 8–13 patch floors,
and Telescope 5.19+ within 5.x. This is a local release candidate preparation;
publication and final-commit CI are separate steps.

## Added

- Daily and weekly email reports with independent enable toggles, existing email
  recipients, explicit environment filters, local delivery time and timezone,
  weekly delivery weekday, and skip-empty behavior. Configure **Settings →
  Scheduled reports**, also linked from Alerts.
- Daily triage emails with occurrences, new groups, regressions, hourly trends,
  resolution progress and the highest-volume currently unresolved errors. Weekly
  emails add previous-week comparison, aging backlog and recurring priorities.
- Administrator-confirmed report previews using actual captured data, clearly
  labeled with `[PREVIEW]`. Reports reuse encrypted snapshots, durable delivery,
  retry/history controls, current subscription checks and deployment policy.
- `telescope-monitor:reports` prepares due reports. Exact local calendar boundaries
  handle timezone offsets and daylight-saving changes; incomplete history is
  labeled rather than treated as zero. Concurrent ticks are deduplicated.
- A triage overview with paired current/previous occurrence bars, origin breakdown,
  an attention list and capture/delivery observations. Comparisons use complete
  intervals and explain unavailable retention or installation history.
- Saved-view preferences for the default error view and ordered favorites, with
  atomic versioned updates and a keyboard-accessible preferences dialog.

## Changed and fixed

- Framework compatibility across Laravel 8–13, with Telescope's minimum lowered
  to 5.19.0. Runtime Laravel constraints are enforced through tested patch-floor
  conflicts; the workbench retains Laravel 13 as a development dependency.
- Doctrine DBAL `^3.10` supports legacy SQLite schema operations. Consumers that
  require DBAL 4 must resolve that dependency constraint before upgrading.
- Portable model casts, migration publishing and connection handling, Eloquent
  duplicate handling, original queue traits and Laravel 8 SwiftMailer acceptance.
- Correct exception origin handling across nested commands, jobs and supported
  frameworks, with zero-query host capture retained.
- Dashboard styling uses Tailwind utilities and shared controls/tables throughout,
  retaining Telescope's light/dark palettes. Refined headers, filters, dropdown
  menus, relative timestamps and timezone-correct chart labels improve triage.
- Email charts use fixed table layouts so hourly and paired weekly bars retain
  consistent column widths in the HTML template.

## Upgrade from rc.3

This release adds an **index-only migration** for report occurrence/activity date
ranges. Schema version 6 and the 25 package tables remain unchanged. Existing
migration filenames remain stable; apply the new migration to existing hosts:

```bash
composer require telescope-monitor/telescope-monitor:1.0.0-rc.4
php artisan telescope-monitor:install
php artisan telescope-monitor:migrate --force
```

The install command publishes missing migrations and preserves existing published
configuration. The report preferences use existing storage and require no new
root configuration keys. Daily and weekly delivery both start disabled.

Add the report command alongside the existing recovery and maintenance schedules:

```php
Schedule::command('telescope-monitor:reports')
    ->everyMinute()->withoutOverlapping();
```

Keep the importer, existing `telescope-monitor-deliveries` worker and
`recover-deliveries` schedule running. Refresh cached configuration and restart
long-running application processes, the importer and monitoring queue workers
after upgrading. Consumers do not need Node.js or a dashboard asset build.

Daily reports cover the previous local calendar day. Weekly reports cover the
previous Monday–Sunday week, even if delivered on another weekday. Late runs
catch up only on the scheduled local day; missed days are not backfilled. Empty
periods are suppressed only with complete coverage, and unresolved backlog alone
does not count as activity. Previews bypass enable/skip-empty toggles without
changing subscriptions. See the [configuration reference](configuration-reference.md#scheduled-daily-and-weekly-email-reports).

Laravel 8–11 runtime compatibility does not remove upstream dependency advisories.
The published package does not disable Composer security checks. Older hosts also
need the documented dual-key encrypter binding for key rotation; see
[framework compatibility](framework-compatibility.md) and the
[operations guide](operations-guide-v1.md).

## Verification and scope

See the [candidate verification report](release-verification-v1.0.0-rc.4.md) for
executed checks and the exact remaining gates. Report previews use synthetic data
and the local array mailer; no live inbox/client certification is implied.
Notifications remain at least once, and uncertain GitHub creation requires review.
This candidate does not claim stable-release qualification or final-commit CI.
