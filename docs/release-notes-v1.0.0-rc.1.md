# 1.0.0-rc.1 — review candidate

Prepared 11 November 2026. **Not published. Publication is held pending the outstanding acceptance gates.** Package/vendor naming is provisional. This candidate is for review and isolated evaluation, not a statement that every declared runtime is certified.

## Included

- Package-owned Telescope-themed dashboard with compiled, versioned assets and self-hosted fonts; no consumer frontend build.
- Durable, encrypted exception capture, bounded sanitized evidence, idempotent ingestion, reconciliation and independent retention.
- Overview, inbox, retained occurrence details, trends and audited lifecycle actions.
- Encrypted notes, personal/shared saved views and optional host-directory assignments.
- Versioned alert rules, email and Slack notifications, signed webhooks, delivery history and recovery.
- Reviewed GitHub issue creation/linking and Needs review handling for uncertain creation.
- Setup and settings, authorization, deployment locks, operational observations, secret rewrap, retention and explicitly confirmed resumable purge.
- Keyboard skip-navigation focus correction found during release verification.

## Installation and upgrades

Install official Telescope as a production dependency and use a supported asynchronous database/Redis queue. Follow the README and operations guide for host gates, actors, scheduler, mailer and network policy. No destinations or enabled rules are created automatically.

For a replacement-package upgrade, back up the host database and encryption keys, install the reviewed candidate, run `php artisan exception-monitor:install`, inspect newly published migrations, then run `php artisan migrate`. Configuration is preserved; missing migration files publish by stable suffix. Restart long-lived workers. The complete schema is version 6. This candidate adds no migration over Phase 7.

The old dashboard-only prototype is unsupported and explicitly rejected. There is no automatic destructive conversion. A database restore must use matching application code, schema and encryption keys; rolling back package files alone is not a database rollback.

## Delivery and operational limits

Notifications have at-least-once recovery semantics. Provider acceptance does not prove inbox/channel receipt. Webhook receivers must deduplicate stable delivery IDs after verifying the timestamp and exact-body signature. Ambiguous GitHub creation is held for review and is never blindly recreated. Already-running external sends cannot be recalled by local cancellation or purge.

Capture depends on Telescope reportability, filters and source storage. Pruned/filtered/unmarked input cannot be reconstructed. The dashboard shows captured counts, not an application-wide failure rate. Evidence retention preserves identities and lifetime totals; installation purge is a separate, explicitly confirmed deployment operation.

## Known defect

A failed Artisan command is captured but can lose its command origin when Telescope filters out nonexception command entries. The command context is cleared before Laravel reports the rethrown exception. This blocks publication pending a fix and a regression covering exception-only source filtering.

## Review gates

The full 28-row acceptance report, exact tested versions, evidence and outstanding gates are maintained in the source checkout at `docs/release-verification-v1.0.0-rc.1.md` and the companion review bundle. Production database baselines, full crash-injection/provider contract coverage and the remaining UI state/accessibility checks must be closed before publication. Composer constraints alone do not certify older framework patches; the Laravel 13.0.0 dependency fixture was refused by current security policy. Keep host dependencies patched.

See `docs/operations-guide-v1.md` for deployment/recovery and `docs/webhook-contract-v1.md` for receiver verification and rotation. This archive has not been uploaded to a registry or tagged as a release.
