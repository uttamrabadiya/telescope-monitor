# 1.0.0-rc.1

First public release candidate of **telescope-monitor/telescope-monitor**, for
isolated evaluation before stable 1.0.0. Requires PHP 8.3+, Laravel 13.42+ and
Telescope 5.27+ within the supported major versions.

## Included

- Telescope exception importer, grouped errors, retained sanitized evidence,
  investigation context, trends and audited resolve/reopen/ignore/snooze actions.
- Notes, personal/shared saved views and optional host-directory assignments.
- Email and Slack alerts, signed webhooks and reviewed GitHub issue creation/linking.
- Durable delivery recovery; ambiguous GitHub creation enters Needs review.
- Setup, Telescope authorization, deployment locks, retention, secret rotation and
  explicitly confirmed maintenance operations.
- Compiled dashboard assets and bundled third-party licenses; no consumer Node build.

## Release preparation fixes

- Preserve command origin under exception-only Telescope filtering, restore nested
  command context and prevent unrelated later errors inheriting a command name.
- Fix stale repeatable-read settings, metric-counter and duplicate-receipt reads
  under MySQL/MariaDB concurrency.
- Align Composer/Git archives, include linked consumer guides, and validate archive
  contents and compiled assets in CI.
- Add lowest/latest dependency checks and process-death delivery recovery coverage.

## Install

```bash
composer require telescope-monitor/telescope-monitor:^1.0@RC
php artisan telescope-monitor:install
php artisan telescope-monitor:migrate
```

Install and configure Telescope first. Follow the README for the supervised importer,
queue workers, scheduler, authorization and independent Telescope source privacy.
No destinations or enabled alert rules are created automatically.

## Compatibility and evaluation limits

The schema is version 6 with 25 package tables. Configuration, commands and tables
use Telescope Monitor names. Earlier renamed prototypes have no automatic data
conversion or compatibility aliases. Back up application data and encryption keys
before any migration; a code rollback is not a database rollback.

Notifications have at-least-once recovery semantics; provider acceptance does not
prove inbox receipt. Webhook receivers must validate signatures and deduplicate IDs.
Filtered or pruned Telescope source cannot be reconstructed after the fact.

Full accessibility/cross-browser, queue crash permutations and final stable-host
qualification remain open. See [release verification](release-verification-v1.0.0-rc.1.md)
for current evidence and the gates before stable 1.0.0. No production throughput or
latency guarantee is made by this RC.
