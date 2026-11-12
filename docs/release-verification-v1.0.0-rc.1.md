# Release verification — 1.0.0-rc.1

Prepared 11 November 2026. **Disposition: REVIEW ONLY / HOLD PUBLICATION.** This is a local candidate, not a published or tagged release. Package/vendor naming remains provisional. The Phase 8 exit gate is not yet satisfied; the table below deliberately distinguishes passing implementation evidence from incomplete release coverage.

## Runtime and build evidence

| Combination/check | Result |
| --- | --- |
| PHP 8.3.17 / Laravel 13.42.0 / Telescope 5.27.0 / SQLite | 202 tests, 1,168 assertions, 12 intentional engine-specific skips |
| PHP 8.4.27 / Laravel 13.42.0 / Telescope 5.27.0 / SQLite | 202 tests, 1,168 assertions, 12 intentional engine-specific skips |
| PHP 8.5.10 / Laravel 13.42.0 / Telescope 5.27.0 / SQLite | 202 tests, 1,168 assertions, 12 intentional engine-specific skips |
| PHP 8.5.10 / Laravel 13.42.0 / Telescope 5.28.0 / fresh host | Production zip discovery, schema 6 / 25 tables, completed setup with current queued capture proof and explicit notification skip, real host sessions/gates and compiled UI; no consumer node_modules or Testbench |
| MariaDB 11.7.2 / PHP 8.5.10 / locked framework dependencies | 202 tests, 1,260 assertions, zero failures/errors/skips; all 12 real fork cases executed |
| MySQL 8.4 / maintained MariaDB 11.4 baseline | Not executed locally; no Docker/MySQL 8.4 runtime available. CI targets prepared, not run remotely |
| Laravel 13.0.0 minimum fixture | Composer refused resolution because of security advisories; security blocking was not disabled. Minimum is not certified |
| Frontend | Vue/TypeScript production build and 3 Node tests pass; final build byte reproducibility checked |
| Quality/security | Larastan level 5, Pint, strict Composer validation pass; Composer locked audit and npm audit report zero known advisories at this run |

The available MariaDB 11.7.2 is compatibility evidence only. It does not certify a maintained baseline. CI now targets MySQL 8.4 LTS and MariaDB 11.4 LTS rather than the former MySQL 8.0 / MariaDB 11.7 pair. MySQL 8.0 has reached end of life; lifecycle sources: [MySQL release notes](https://dev.mysql.com/doc/relnotes/mysql/8.0/en/), [MySQL release model](https://dev.mysql.com/doc/refman/8.4/en/mysql-releases.html), [MariaDB maintenance policy](https://mariadb.org/about/).

No PostgreSQL, alternative queue driver, Octane/Swoole/RoadRunner, physical-device or cross-browser compatibility claim is made by this run. Declared Composer ranges are not a certificate for every dependency combination. Production SQLite throughput is not certified.

Build tools: Node 24.12.0, npm 11.7.0, Composer 2.10.3 and Python 3.13.2. Build dependency locks, license inventory and audit output are retained in companion evidence. The root npm project has no package version; npm’s SBOM command refused that metadata, so the bundle includes a clearly labeled lock-derived production dependency inventory rather than claiming an attested SBOM.

## Acceptance matrix

Current row disposition: **21 passing implementation rows, 7 partial rows**. This is not an all-green release verdict.

“Pass” means the named implementation assertions passed on the tested stack; all rows remain subject to the database/runtime and release-wide gates below. “Partial” means an additional observable part of that requirement remains unverified. Existing tests were rerun for this candidate; prior screenshots alone were not used as current execution evidence.

| ID | Status | Current evidence and remaining qualification |
| --- | --- | --- |
| SETUP-01 | Pass | Fresh Composer production-zip host, package discovery and completed setup with current queued capture proof/explicit notification skip; rendered onboarding and no consumer build. Prerequisites also covered by OperationsTest and ProbeTest |
| SETUP-02 | Pass | Real repeat install preserves exact config bytes and six stable migration suffixes; Phase 7 archive → candidate Composer upgrade preserves retained rows; InstallationTest additive publication and prototype rejection |
| AUTH-01 | Pass | FoundationTest, RequestProtectionTest, CollaborationTest, EmailAlertsTest, IntegrationsTest and OperationsTest enforce viewer/operator/admin capabilities and CSRF |
| AUTH-02 | Pass | Personal view isolation, masked credentials/history, operational administration and assignment-directory capability tests |
| CAP-01 | **Partial / defect** | Real HTTP and terminal database job capture with web/job origins. A failed Artisan exception is captured but has unknown origin under exception-only Telescope filtering; see blocker below. Existing OriginsTest passes because its batch retains command entries |
| CAP-02 | Pass | CaptureTest, TelescopeContractTest and ProbeTest verify filters, batch exclusion, reportability and disabled behavior |
| CAP-03 | Partial | Replay/idempotency assertions pass on SQLite. Full fork suite runs on local MariaDB; production MySQL 8.4 / maintained MariaDB concurrency gates remain open |
| CAP-04 | Pass | RecoveryTest and EmailAlertsTest verify historical/out-of-order timestamps, suppression and no retroactive intent |
| DATA-01 | Pass | BoundsTest, CaptureTest and integration export tests verify privacy before persistence, bounded evidence, truncation/redaction and sanitized frozen payloads |
| DATA-02 | Pass | RecoveryTest, InvestigationTest and OperationsTest retain snapshots independently of Telescope, with explicit missing/expired/unreadable evidence states |
| DATA-03 | Pass | InvestigationTest source-link policy and rendered occurrence warning: current filesystem source is never presented as historical source |
| LIFE-01 | Pass | InvestigationTest lifecycle/version/receipt/expiry assertions; browser Resolve → Reopen and audit activity verified |
| NOTE-01 | Pass | CollaborationTest authorship/conflicts/deletion, EmailAlertsTest metadata-only notification; browser plain-text script content remains literal |
| VIEW-01 | Pass | CollaborationTest ownership/publication/moderation and versioned definitions; browser personal view save, canonical URL and reload verified |
| ASSIGN-01 | Pass | CollaborationTest configured/unconfigured and eligible directory/capability behavior; no production host directory invented |
| ALERT-01 | Pass | EmailAlertsTest event priority, threshold windows, coalescing and opted-in activities |
| ALERT-02 | Pass | EmailAlertsTest and OperationsTest current applicability, cooldown, cancellation, expiry and frozen policy rechecks |
| ALERT-03 | Partial | Real database/Redis capture workers, actual Redis stop/restart/recovery, native SMTP acceptance and controlled SIGKILL after provider acceptance; broader kill-before-claim/during-I/O/Redis-delivery crash permutations remain open |
| GITHUB-01 | Pass | IntegrationsTest preview/creation/link/repository/PR validation; adapter read-check and canonical issue-42 response verified through real HTTPS test forwarding |
| GITHUB-02 | Partial | IntegrationsTest verifies lost response, local commit failure and expired create lease enter Needs review without another write; process-death injection for GitHub creation still open |
| SLACK-01 | Pass | IntegrationsTest secret/escaping/throttle semantics; native HTTPS receiver independently observed escaped mention text, mrkdwn=false and parse=none through test-only forwarding |
| WEBHOOK-01 | Pass | IntegrationsTest exact bytes, fresh timestamps, rotation, mixed DNS/rebinding and allowlists; native TLS endpoint verified HMAC, stable ID dedup, 429/Retry-After, no redirect and wrong-host certificate rejection |
| CONFIG-01 | Partial | Settings/config revisions, locks, current worker policy, credential rotation and stale writes pass; maintained engine race matrix still required |
| RETAIN-01 | Pass | OperationsTest and RecoveryTest preserve UUID/count/note/view/link state while expiring/removing evidence; bounded normal sweeps and monotonic retention |
| RETAIN-02 | Partial | OperationsTest interrupted/resumable purge, source epoch/generation fences and stale completion tests; full engine matrix still required |
| HEALTH-01 | Pass | OperationsTest and FoundationTest distinguish configured, actual observations, stale starts, failures and unverified work; no invented supervisor/scheduler health |
| UI-01 | Partial | Seven routes at desktop/tablet/phone, persisted settings/views, note, lifecycle and mobile navigation; keyboard skip-link defect fixed and verified. Full nested failure/conflict/long-data keyboard journeys, screen reader, zoom and cross-browser coverage remain open |
| SAFE-01 | Pass | Capture/Recovery/Origins/EmailAlerts/Integrations/Operations tests preserve host error behavior, suppress own transport failures and prevent recursive capture; real HTTP still returns its original 500 |

## Runtime exercises

The fresh fixture uses host sessions and an explicit testing-only host provider for its user/gates. Package authentication and production defaults were not bypassed. All fixture mutations use disposable databases; normal workbench/consumer databases were not purged.

Database and Predis-backed Redis queue probes completed using real Laravel workers. With the disposable Redis broker stopped, a pending receipt survived; recovery after the actual two-minute redispatch cutoff completed it. The earlier recovery correctly dispatched nothing. This proves capture dispatch recovery, not every delivery/Redis crash permutation.

Native cURL used a process-scoped temporary CA and TLS loopback receiver. DNS/host files and system trust were unchanged. The production destination policy denied loopback until an exact test-host/CIDR allowlist was supplied. The receiver independently verified raw HMAC bytes/timestamp, recognized a repeated delivery identity, returned 429 with Retry-After and 302 with an unfollowed redirect. A wrong certificate hostname was rejected. Native host SMTP accepted a synthetic message at a loopback receiver. No recipient inbox claim is made.

An actual SIGKILL at a test-only barrier after native SMTP acceptance left the durable row processing. After the actual 120-second lease expired, recovery retried the same logical delivery ID, recorded the first attempt as uncertain/worker_lease_expired and the second as accepted. The receiver accepted two messages, demonstrating honest at-least-once behavior. No clock or lease timestamps were adjusted.

Slack/GitHub adapter contracts used a test-only transport that forwarded validated original descriptors, headers and bodies to that native TLS receiver. This is real socket/serialization/response evidence; it does not verify upstream DNS, account permissions, tokens, actual Slack receipt or GitHub repository writes. No live account request was sent. The adapter’s `X-GitHub-Api-Version: 2026-03-10` header is a currently supported version in [GitHub’s API version policy](https://docs.github.com/en/rest/about-the-rest-api/api-versions). GitHub read checks made GETs only; the synthetic create returned and validated a canonical association.

The preserved Phase 7 zip was assigned **local-only fixture version 0.0.7**, then upgraded through Composer to the candidate. This is not a claim of a historical public 0.0.7 release. Installation row, issues, occurrences, encrypted notes, saved views, settings, secrets and configuration bytes were compared before/after. No migration is added over Phase 7; earlier additive migration publication and unsupported prototype detection are covered by the suite.

A 100-report local SQLite diagnostic measured host Telescope storage plus a bounded ingestion page and peak PHP memory. Exact timings are in `capture-profile.json`. It is not representative production throughput or contention evidence; no latency promise is made.

## Rendered QA

Browser plugin not available; Playwright fallback used. Local fresh host: `http://127.0.0.1:8200/telescope-monitor/`. Title: Telescope Monitor. Viewports: 1440×1000, 768×1024 and 390×844. Setup, overview, inbox, error details, alert rules, integrations and general settings showed meaningful content, no framework overlay and no page-wide horizontal overflow. Screenshot evidence is in the companion bundle. Empty destinations/notifications were kept truthful; no fabricated production alerts were enabled.

Verified interactions: resolve/reopen with control/state change and activity; add a plain-text note containing literal script syntax and safe link; save/reload a personal view; save/reload settings; open mobile navigation. Keyboard Enter on the skip link initially failed to transfer focus. Adding `tabindex=-1` to the main region corrected it: focus becomes `tm-main`, then Tab advances to General. Mechanical detector returned no findings. No application console errors/warnings were observed in the exercised journeys. This is Chromium/emulated viewport evidence; it does not certify complete keyboard, screen-reader, zoom or physical-device coverage.

## Open gates before publication

1. **Fix console origin under source filtering.** `CommandFinished` clears the live command before Laravel reports its rethrown exception. If Telescope excludes nonexception command entries, capture persists the exception with origin unknown. Reproduced in a fresh host outside Laravel testing mode as well as in testing mode. Preserve a provable association without leaking it across nested/subsequent commands, and add a regression with exception-only filtering. This is a product defect, not an environment-only gap.
2. Run the complete suite and real fork races on MySQL 8.4 and maintained MariaDB 11.4, on the CI PHP baseline; retain exact engine versions and results. Prepared CI is not executed CI.
3. Verify a secure dependency floor and the intended minimum/latest Laravel/Telescope combinations. The refused Laravel 13.0.0 fixture remains failed resolution evidence, not a test pass. Locked dependency audits are clean, which does not certify older allowed versions.
4. Complete process-death coverage before claim, during provider I/O, and after external acceptance for both queue candidates and GitHub Needs review. The local SMTP acceptance/kill exercise covers one representative ambiguous-acceptance path only.
5. Complete supporting UI failure/conflict/long-data and full keyboard journeys on all three viewport classes; add screen-reader/zoom/cross-browser evidence appropriate to the final supported-browser policy.
6. Record representative production-engine capture/claim/prune query profiles before any workload/latency claim. The SQLite diagnostic is deliberately modest.
7. Final vendor/name, license attribution, candidate notes, compatibility scope and artifact review. Live-account destination verification is optional deployment-specific work and requires explicitly configured test accounts; it has not been silently substituted by mocks or SMTP acceptance.

The candidate should not be published merely because the currently runnable checks passed. Close the required gaps or explicitly revise the release support/scope before approving publication.

## Artifact and reproduction

The companion directory is `releases/1.0.0-rc.1/`: production Composer zip, `SHA256SUMS`, per-member `FILES.sha256`, artifact metadata, explicit local Composer repository metadata, this report, release notes and selected sanitized evidence. `SHA256SUMS` identifies the exact archive; zip timestamps are not claimed reproducible. `FILES.sha256` binds the consumer file bytes. This workspace has no Git repository/tag identity; provenance is bound by consumer member hashes, verification-source hashes and dependency lock hashes. The build helper compares every archive member against source, checks manifest-listed assets and rejects development/private paths.

Recreate into a new empty directory with `python3 scripts/prepare-release.py --version 1.0.0-rc.1 --output /private/tmp/monitor-review-new`. It invokes Composer archive only, never uploads, tags or publishes. Developer prerequisites: Python 3 and Composer. Build first using the locked Node dependencies; consumers need neither.

Primary reproducible commands: `composer install`, `npm ci`, `npm run build`, `npm run test:unit`, `composer test`, `composer analyse`, `vendor/bin/pint --test`, `composer validate --strict`, `composer audit --locked`, `npm audit`. Database suite: isolated database `telescope_monitor_test`, `MONITOR_DB=mysql DB_PORT=<isolated-port> vendor/bin/phpunit`. The tests recreate package/source schema; never point this command at a real application database. Fork cases require pcntl and the MySQL PDO driver.

Native receiver, fresh-host, crash and browser harnesses live in the companion evidence as test-only fixtures; they are excluded from the Composer archive. Test accounts, databases, private TLS keys, .env files and consumer data are not release payloads. Dependency lock snapshots and audit output in the review bundle describe build provenance and are excluded from consumer installation.
