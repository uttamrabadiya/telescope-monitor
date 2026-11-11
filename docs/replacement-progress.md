# Replacement implementation progress

Updated: 11 November 2026

The user approved the product scope, wireframes, six visual mockups and technical architecture, then authorized cleanup and a fresh implementation. The current delivery includes the replacement foundation, durable capture, investigation, Phase 4 collaboration and Phase 5 email alerting plus Phase 6 integrations and Phase 7 operations. It is not the complete v1 release.

## Cleanup and preservation

- Verified `.archive/prototype-2026-11-11.tar.gz` against a SHA-256 manifest for 78 files, including the previous implementation, tests, configuration, planning copies and an online SQLite backup.
- Removed the old active source, views, routes, migrations, tests, development workbench and generated analysis/test caches after verification.
- Preserved approved product/design/planning documents and mockups, license and user IDE configuration.
- Archived the previous installer-generated Testbench migration and the disposable workbench database it recreated; removed that stale fixture from Testbench and initialized a genuinely fresh workbench.
- Stopped the earlier prototype server. The replacement preview uses port 8195.
- Host-application databases and external services were not reset or configured. The package is not published.

Restore archives into a separate empty directory for inspection, not over the replacement checkout.

## Implemented foundation (Phase 1)

The new package uses a Vue 3/TypeScript dashboard, Vite-built distributable assets, self-hosted Figtree and Heroicons. It does not import the host frontend or require Node on the consumer application.

The first four physical tables are installations, settings, health_checks and action_receipts. Phase 2 adds nine tables as described below; the remaining logical schema belongs to later feature phases. Current behavior includes stable installation identity/configuration revision, explicit host actors/capabilities, safe publishing, prototype-schema detection, general/privacy/retention preferences, server-enforced locks, edit conflicts, resumable setup position, bounded private redaction preview, manifest-allowlisted assets, real request-forgery protection and truthful configuration/health states.

Investigation is delivered in Phase 3 below; alert and integration areas remain unavailable until their phases. Phase 2 connects capture and actual health observations. The workbench contains explicitly labeled synthetic captures created for browser verification, including the Phase 3 investigation fixture; no fabricated production groups or metrics, integration credentials or automatic notifications were seeded.

## Source contract evidence

The executable integration fixtures verify behavior of the installed Telescope 5.27 dependency:

- An early storage callback runs; a callback registered after the inspected ModelWatcher can be skipped by falsey callback traversal.
- Live recorded timestamps preserve microseconds, while Telescope's persisted source timestamp can be coarser.
- Entry and batch filters exclude entries from storage opportunities.
- Reportability can be marked while the live Throwable is available, without guessing from its later class name.
- Package private-envelope redaction protects both source request and source response content.
- Runtime path exclusions follow the final configured base path without replacing application filter callbacks.
- Terminal worker reporting flushes/stops recording before the final exception report in the inspected path. The negative contract fixture keeps this gap reproducible with the bridge disabled; Phase 2 adds positive terminal and retrying worker fixtures with the bridge enabled.

Real HTTP and console-kernel reporting fixtures complement the source-entry probes. Their result is included in the final verification run. Phase 2 now supplies context/volume/replay experiments and generation/epoch/cutoff fences. Full destructive purge coordination and external-provider recovery remain later gates.

## Verification scope

Backend verification covers default-deny reads/assets, viewer/admin separation, persistence, canonical scalar types, stale edits, deployment locks, invalid settings, unknown routes, alternate route prefixes, missing/prototype schema handling, safe repeat installation, source contracts, bounded preview and real request-forgery middleware. The MariaDB test additionally forks independent admin edits and verifies exactly one committed version and one rejected stale edit.

Browser verification uses Playwright because a Browser skill/plugin is unavailable in this session. Verified the real settings save across reload, redaction preview without storing sample text, unsaved-route protection, mobile navigation, and no horizontal overflow at 320/390px. The shared shell/privacy screen was compared with the approved mockup at its native 1487 × 1058 dimensions. No browser console errors were observed.

A fresh Laravel 13 application installed an extracted production archive without package development dependencies or consumer node_modules. It discovered the provider, published/migrated the foundation, rendered the compiled shell under `operations/monitor`, resolved an authenticated host model through its provider contract, and persisted general preferences. The fixture resolved Telescope 5.28; the development lock remains Telescope 5.27.

The archive check excludes the prototype, vendor, node_modules, workbench, IDE/cache/QA files and design documentation, while including compiled assets and dependency license notices. No archive has been published.

## Phase 1 local verification results (historical)

| Check | Result |
| --- | --- |
| SQLite suite | 26 tests, 119 assertions; one MariaDB/MySQL-only fork test intentionally skipped |
| MariaDB 11.7.2 suite | All 26 tests passed, 125 assertions, including independent concurrent admin edits |
| Larastan level 5 | Passed |
| Pint | Passed |
| Vue/TypeScript typecheck and production build | Passed |
| Composer metadata validation | Passed |
| Production archive hygiene and source/asset hash match | Passed |
| Fresh no-dev Laravel host, alternate prefix and host actor | Passed without consumer node_modules or Testbench |
| Browser settings, redaction, draft protection and mobile navigation | Passed; no console errors observed |
| Phase 1 visual review | Ship; both required fixes scored resolved |

These are local results on PHP 8.5.10 and Laravel 13.42.0. The full remote CI matrix has not run.

## Visual review

A fresh reviewer accepted the Phase 1 foundation after two fixes:

- Local setup-step changes now consult the mounted form's dirty guard before changing step or checkpoint. Cancelling preserves both draft and step; browser verification confirmed this.
- Muted canvas text changed from the reference's `#6b7280` to `#626b7a`, measuring 4.89:1 on `#f3f4f6`.

The review disposition applies to the implemented foundation only. The approved remaining screens and full product acceptance matrix are not claimed complete.

Intentional visual differences are live local environment/actor values, truthful runtime footer/readiness information, the accessibility contrast adjustment, real validation/loading/success states, and unimplemented capabilities explicitly unavailable. There are no artificial production error counts.

## Implemented surface fidelity ledger

The accepted reference is `docs/design/mockups-v1/settings.png`. The final browser comparison used the same 1487 × 1058 desktop viewport and a separate 390px mobile capture, with view_image inspection of both reference and render. This ledger covers the implemented settings/privacy surface and shared chrome, not the unimplemented five-screen workflows.

| Comparison point | Result |
| --- | --- |
| Header and primary navigation | Preserved application header, icon metaphors, gray navigation rail, indigo active row and bottom setup entry |
| Layout and grouping | Preserved page hierarchy, secondary settings navigation, white form surface, policy callout and grouped fields |
| Typography | Self-hosted Figtree with explicit heading/control/body scales and monospace only where relevant |
| Palette and geometry | Preserved indigo primary, light gray canvas, white surfaces, fine rules and restrained corners; muted canvas color adjusted for contrast |
| Visible copy | Main heading, subtitle, field labels and primary actions match; live environment/runtime footer replace illustrative labels/date |
| Form behavior | Server persistence, validation, locks, stale-version conflicts, private preview and unsaved-draft protection verified |
| Responsive behavior | 320/390px layouts show no horizontal overflow; mobile navigation and form actions remain reachable |

The fresh reviewer returned ship for Phase 1 after its two material findings were fixed. No additional material mismatch remained in that scoped review. Temporary screenshots are kept in the task-owned QA location and ignored browser output, not in the release artifact.

## Phase 2 durable capture

Delivered one additive migration for capture_inbox, issues, occurrences, occurrence_evidence, evidence_tags, metric_buckets, activity, alert_events and secrets. Installation identity remains stable; schema version advances to 2. Installation secrets use the approved secrets table early because signed capture identity needs a stable encrypted key. Action receipts gain public_id and generation for fenced, addressable synthetic probes. The connection foreign key on secrets is deferred until integrations exist; no new logical table was invented.

The adapter signs reportability and grouping while the live Throwable exists, registers its storage callback before Telescope's ModelWatcher, and preserves host recording/watchers/filters. The terminal-job bridge supplies the missing pre-flush report without a second host error report. HTTP, sync jobs, retrying/terminal workers and real console-kernel reporting are covered. Internal package errors and jobs are excluded; only the exact trusted probe Throwable gets synthetic permission, with administrator authorization checked again in its worker. Host pause/disable/filter policy is respected.

A bounded encrypted inbox commit precedes advisory dispatch. Only database/Redis asynchronous drivers are eligible; sync/null/unsupported drivers leave durable input for deliberate CLI processing. Queue jobs carry identifiers. Claims use 120-second leases and token fencing, transactions lock installation then inbox then group, and failures have five bounded attempts with backoff. First-group insertion uses insert-if-absent followed by a current row lock, avoiding MariaDB's concurrent no-op upsert conflict. Occurrence UUID identity, counts, metrics, activity and disabled event intent commit atomically. Duplicate work and replay after ciphertext/evidence expiry cannot recount or rehydrate an old occurrence. Synthetic groups are separate and create no automatic alert events; historical events have disabled planning and cannot produce retroactive delivery.

Evidence excludes arguments/objects, request bodies/cookies/headers, SQL/bindings and arbitrary context. Common credentials and deployment patterns are redacted before package persistence, with current stricter privacy applied again at ingestion. Per-user impact is off by default and uses a separate encrypted HMAC key if enabled. Evidence is capped at 128 KiB; traces at 100 frames; related logs at 20 × 2 KiB; tags at 20 × 64 bytes; normalized related context at 64 KiB. Live decoding reads at most 2 MiB of source JSON, including at most 1 MiB of context; source replay reads a bounded 2 MiB page by default. Oversized individual source values can be unavailable rather than copied. A page reaching its aggregate byte budget stops at a saved cursor and resumes later rather than quarantining valid rows beyond that boundary. Overflow uses a batch reference, 100-row pages, progress and expected-count bookkeeping. Source pruning before or between pages produces a safe visible failure rather than invented completion.

Retained evidence survives Telescope pruning. Physical evidence, tag and processed-inbox pruning are bounded, while lifetime summaries and the minimal UUID ledger remain. UTC microseconds are preserved independently of host timezone; model writes, eligibility/equality predicates and retention predicates all use the same representation. Event-time bucket counts remain correct for out-of-order first occurrences. Source database signatures, source epochs, generation, purge timestamps and sequence floors fence stale work. Sequence rollback or changed database identity requires explicit review; this milestone does not offer a destructive epoch-reset or purge UI.

Recovery includes advisory redispatch, oldest-first incremental reconciliation, and a persisted rotating 15-minute overlap window. Only marked, verifiable source rows are recoverable. Historical scan flags are explicitly bounded, not an unbounded source export. Commands and scheduler examples are in [README](../README.md#capture-operations). Receipt/processing observations persist after completed inbox rows are pruned; token/fence losers do not publish a false processing success. Onboarding runs a real asynchronous synthetic probe; health exposes actual pending/failed counts, oldest pending receipt, safe failure codes and processing/reconciliation/pruning observations. A past success is never presented as proof of a currently running worker.

The following description records the Phase 2 boundary; Phase 3 adds investigation/lifecycle below. Retained execution includes sanitized request path/method/controller or job/command identity when present. Unobserved origins remain unknown even when reconciliation runs in a console. Named-route/template and immutable release/source mapping projections remain null when no authoritative snapshot exists; raw paths are not mislabeled as route templates. Email, Slack, webhooks and GitHub delivery remain inactive.

## Phase 2 verification

Final verification on the local PHP 8.5.10 / Laravel 13.42 environment:

| Check | Result |
| --- | --- |
| Final SQLite suite | 67 tests, 270 assertions; 2 database/pcntl-specific fork tests explicitly skipped |
| Complete MariaDB 11.7.2 suite | 65 tests, 287 assertions; both fork tests passed |
| Final MariaDB bounds/origin checks after pagination fix | 13 tests, 38 assertions; passed, including the additional byte-budget recovery case and real Symfony command events |
| Final MariaDB health/recovery/concurrency checks | 11 tests, 60 assertions; passed after persistent observation and claim-result changes |
| Larastan level 5 and Pint | Passed |
| Vue/TypeScript and production build | Passed |
| Composer metadata validation | Passed |
| No-dev host additive upgrade and capture smoke | Passed on Telescope 5.28, without consumer Node/Testbench |
| Real queued onboarding probe, desktop/mobile browser checks | Passed, no console warnings/errors |

The full MariaDB suite preceded the final byte-budget pagination and console-origin refinements; the targeted 13-test run verifies those changes, followed by 11 health/recovery/concurrency checks after the last health observation correction. SQLite reran the complete 67-test suite afterward. Coverage includes source filters/watchers, malformed/versioned/decryption failures, transaction rollback, broker outage, expired claims, generation/epoch fencing, source pruning mid-batch, 128 KiB bounds, privacy exclusions, separate source connection, replay identity, timezone-safe retention and real execution origins. SQLite deliberately skips the two forked row-locking tests; MariaDB runs both, including six independent workers contending on first-group creation and duplicate inbox claims, followed by eligible retry recovery.

A separate no-dev Laravel host upgraded from Phase 1 using the new additive migration, rendered compiled assets under `operations/monitor`, and verified durable buffering, explicit ingestion and sanitized retained evidence without consumer Node/Testbench. It uses Telescope 5.28; development tests use 5.27. The installer test also simulates a previously published foundation migration and proves only the missing capture migration is added.

Local controlled SQLite experiment inputs (PHP 8.5.10, Laravel 13.42, Telescope 5.27):

| Experiment | Observation |
| --- | --- |
| 100 exceptions in one accepted batch | Buffer 0.253 s; ingestion complete in 0.690 s; peak process memory 48.5 MiB |
| 1,000 exceptions in one accepted batch, including overflow | Buffer 1.230 s; ingestion complete in 6.477 s; peak 72.5 MiB; no failed items |
| 10,000-row identity ledger | Seeded existing identities plus the captured volume; replaying 100 existing envelopes and a no-op prune took 0.298 s; counts stayed 10,000; peak 74.5 MiB |

These are bounded experiment observations, not supported throughput or latency claims. The 10,000-row fixture seeds old identities; it does not claim 10,000 full captures were timed. These measurements include the final source-gap bookkeeping and persistent capture observations. Final release profiling still needs representative production databases and retained-evidence distributions.

Browser QA used Playwright because the Browser plugin is unavailable. At `http://127.0.0.1:8195/exception-monitor/setup`, the actual sequence Test capture → Generate sample event → queued probe → retained occurrence displayed “Capture verified.” Page identity, meaningful content, no framework overlay, zero warning/error console messages, screenshot inspection and the target interaction passed. Desktop 1487 × 1058 and mobile 390 × 844 preserved the existing Telescope theme; mobile document width was exactly 390 px. Temporary screenshots are outside release source. Health navigation, the updated retention explanation, and real maintenance commands were also exercised. Retention settings currently determine new evidence/bucket expiry; previews and retroactive existing-data administration remain Phase 7. This is a scoped capture/setup verification, not approval of unimplemented investigation screens.

Remaining verification: remote CI and the full declared runtime matrix, actual Redis broker operation (database worker used locally), Octane-specific lifecycle behavior, representative production load, destructive purge administration and the full v1 acceptance matrix. No external messages were sent, no host production database was reset and no release was published.

## Phase 3 investigation and lifecycle

Delivered server-backed overview, error inbox and occurrence detail screens in the existing Telescope light theme. The landing route now opens overview. Queries and mutations live behind repository interfaces, with authenticated, no-store private API responses and safe storage-failure messages. No consumer frontend build or new database migration is required; schema version remains 2.

The inbox supports built-in states, environment, origin, exact class, literal class/message search, deployment-permitted tags and explicit UTC first/last-seen dates. Default sorting is last seen, with first-seen/count sorting and stable descending ID ties. Pagination is 25/50/100. Selected count ranges never hide older unresolved groups implicitly. Origin filters use durable captured identity metadata, tags use unexpired evidence, and group range totals remain all-origin totals as stated in the UI. Unknown filters return validation errors.

Overview uses event-time metric buckets with 12 × 5m, 24 × 1h, 28 × 6h or 30 × 1d display buckets. Counts/new groups/regressions use the selected environment/test scope. Test data is excluded by default. The current bucket and retention/collection limitations are explicit; no request error rate is invented. The chart has keyboard-addressable points and a readable data table. Attention rows link to investigation; recent activity identifies both the action and affected sanitized error; health uses real capture observations and does not claim an active worker from past success.

Details select the latest unexpired retained occurrence initially, with a stable UUID URL and paginated identity history. Explicit selection is constrained to the current issue/installation. Every evidence panel derives from that selected response; cancelled/stale reads cannot restore older panels. Application/vendor frames are distinguished, vendor frames can be expanded, and excluded/unavailable/truncated/expired/unreadable states are explicit. Retained origin/route/release/tag/user insights state their coverage and do not infer missing categories as zero impact. Activity remains accessible when the selected evidence expires. The activity response is bounded to the latest 100 records; insight categories to 20 values. Notes and linked GitHub UI explain their later phases.

Capture now snapshots an optional deployment-managed immutable release/code-host template, and authoritative route/name template only when the live HTTP route and host RequestWatcher policy allow it. Existing records never inherit current release or route data. Source links are derived exclusively from the frozen mapping, require fixed-host HTTPS without credentials/query strings, and encode release/path components. Absolute/traversal/vendor paths are not guessed as repository files. No code preview reads today's deployed source. Original Telescope links warn that the source may have been pruned.

Lifecycle actions require operate+view, expected state_version and a UUID action key. Installation → receipt → issue locking preserves the approved order. Canonical keyed digests reject key reuse with different requests. Receipt, state and actor activity commit atomically; repeated receipt delivery returns the accepted result. Count-only ingestion leaves state_version alone. Suppression/resolution cutoffs persist across reopen/unsnooze; early unsnooze uses the actual action time. First/regression event intents pending planning are cancelled on resolve/ignore/snooze. External delivery remains disabled until Phase 5.

Snooze supports 1h/24h/7d or an explicit-offset future time. Ingestion, lifecycle writes and `exception-monitor:expire-snoozes --limit=100` enforce expiry. Reads expose expired snoozes as unresolved without GET writes. Maintenance is bounded, idempotent and creates no retrospective alerts. Custom timestamps are normalized to UTC; database UTC observations are also parsed explicitly in the browser instead of being shifted by the browser's local timezone.

## Phase 3 verification

Final local verification:

| Check | Result |
| --- | --- |
| SQLite complete suite | 85 tests, 383 assertions; 3 MySQL/MariaDB+pcntl fork checks explicitly skipped |
| MariaDB 11.7.2 complete suite | 83 tests, 409 assertions; all three concurrency checks passed |
| Final MariaDB investigation checks | 17 tests, 113 assertions, including the two later regression/RequestWatcher cases and final read projections |
| Frontend UTC regression | Passed under Asia/Kolkata with SQL UTC, explicit-offset ISO and microsecond inputs |
| Larastan level 5 / Pint | Passed |
| Composer metadata / final archive hygiene | Passed; compiled assets, source and collaboration migration matched, with development/QA files excluded |
| Vue/TypeScript and production build | Passed |
| Composer metadata and archive source/asset hygiene | Passed after final archive verification |
| No-dev Laravel host | Inbox, detail API, alternate-prefix deep link, compiled assets and lifecycle transaction passed without consumer Node/Testbench |

The complete MariaDB run preceded the final two additional tests; the 17-test targeted run verifies the final investigation changes. SQLite reran the complete 85-test suite. The full local SQLite suite, row-locking MariaDB suite, PHPStan/Pint, TypeScript/build and a frontend UTC regression test have been exercised. Added cases cover range shapes, stable pagination, literal search, invalid filters, foreign occurrence IDs, evidence expiry/source pruning, immutable/unsafe mappings, host RequestWatcher policy, query/lifecycle authorization, canonical receipts, stale edits, transaction rollback, cutoff/regression behavior, snooze expiry and competing/duplicate lifecycle actions.

Browser verification used Playwright because a Browser plugin/skill is unavailable. The flow exercised overview search → filtered inbox → selected occurrence → Resolve/Reopen/Snooze/Unsnooze/Ignore, and return to the preserved filter. A simulated lost response after commit proved retry used the same key/version. A deliberately delayed occurrence request followed by rapid route navigation proved the latest selection won. Desktop 1487 × 1058 and mobile 390 × 844 were captured for overview/inbox/details after loading settled; document width matched each viewport. Tables/chart scroll within their own containers on mobile. No console warnings/errors or framework overlays were observed. All example occurrences were explicitly labeled synthetic events in the disposable local workbench; they remain excluded by default.

The accepted references are `docs/design/mockups-v1/overview.png` and `details.png`, at their native 1487 × 1058 size; manifest/prompts preserve provenance. This is an existing-world extension under the preserved system, not a new concept selection or measured comp-reproduction pipeline. The surface contract is `.impeccable/briefs/phase3-investigation.md`. `view_image` compared the accepted references with final captures. The detector ran once: thick-border findings refer to selected-tab underlines, while font advisory entries overlap existing documented scales. No detector-driven visual-world changes were made.

| Comparison point | Verification |
| --- | --- |
| Shell, palette and icons | Preserved pale gray/white surfaces, existing Figtree/Heroicons, indigo selection and accessible muted text |
| Overview structure | Preserved controls → flat metrics → trend → attention table; added required activity and truthful health |
| Detail structure | Preserved back/status/actions/tabs, retained trace and summary/occurrence rail |
| Type and code treatment | Existing hierarchy and real monospace frame paths; bounded wrapping/clamping and vendor disclosure |
| Copy and controls | Reference headings retained; Search errors replaces Phase 4 Save view; Insights and later-phase explanations are intentional |
| Data and responsive behavior | Real synthetic labels/counts/times replace illustration; stacked mobile detail and local table/chart scrolling |

The independent finish reviewer accepted the existing-world fidelity and returned fix for three bounded items. After correction it scored provenance, affected-error activity labels and search URL/history disclosures all resolved; disposition ship applies to those scored fixes. It did not certify pixel-level measured reproduction or full v1 completion. The documenter checked PRODUCT/DESIGN/sidecar, the surface contract, source and six final captures: no system/token change was required. It preserved the incumbent token files and reported historical Phase 1 capability prose as pre-existing drift rather than repairing it unasked. Query-based links now expose only one current status/tab to assistive technology, and overflow regions are keyboard focusable; these attribute-only corrections do not change the visual system.

Remote CI, the complete PHP/framework/database matrix, Redis runtime operation, Octane-specific behavior and representative production-volume profiling remain outstanding. Full collaboration, external-provider delivery and destructive purge acceptance remain later phases. No external issue/message was sent and no package was published.

## Phase 4 collaboration

Delivered one additive migration for encrypted notes, saved views and actor view preferences, plus an issue assignment index. Schema version is 3; 16 logical tables are now physically delivered. Publishing preserves existing host configuration and migration identities. Notes have no snapshot-retention expiry; deletion clears their body and leaves a minimal tombstone. No host User table or FK is introduced.

The collaboration repository owns transactional writes behind a small interface. Host identity presentation and the optional assignment directory remain separate. Mutations use installation → receipt → issue/resource locks and a keyed canonical request digest. Note/view versions and one actor preference row prevent stale overwrites. Current ACLs are checked before receipt replay. Case-sensitive keyed actor hashes avoid MySQL collation accidentally merging identities. Cached labels/availability are limited to the current request using weak request identity; reused applications do not reuse stale eligibility.

Notes support plain text up to 10,000 Unicode characters, preserved line breaks, current privacy redaction, encrypted storage and safe credential-free HTTP(S) anchors. HTML remains literal and mentions have no platform behavior. Authors with operate can edit; authors/operators and administrators may remove text as authorized. Body revisions are not copied into audit/receipts/events. Note-added intent is metadata-only and disabled for delivery. Private drafts remain in memory across occurrence-panel changes and data refresh, are absent from URLs/localStorage, survive conflicts, and prompt before issue navigation/discard. Unreadable/deleted states are explicit.

Personal views are private even to administrators. Shared views are visible to dashboard viewers and editable by their eligible owner or an administrator. Save/copy/update/rename/delete, Modified state, personal visibility default, favorites/order/default and schema repair are connected to real APIs. Definitions contain only permitted filters, a sort and a relative range. Unknown/unsupported definitions do not execute. Short links populate the same visible filter state. Effective preferences recheck every reference and trim inaccessible/deleted/unsupported views without granting access or rewriting other actors' data. Bare-inbox defaults and explicit URL overrides are tested.

Assignments are deployment-disabled by default and require a host adapter. Eligibility queries are bounded and run only in dashboard operations, never the capture path or bootstrap. New assignments validate eligibility before opening a transaction. Removed/ineligible/down directory states preserve references and allow clear; disable/re-enable does not delete history. Assignment updates state_version and actor metadata atomically; no user account or dashboard permission is created. The optional development directory is local-only and excluded from consumer archives.

The wider suite exposed an origin-classification gap when the related-context budget filled with queries before entrypoint metadata. Live/replayed context now prioritizes accepted request/job/command headers within the existing bounds. A sync failure additionally retains exact-Throwable job context until reporting, then clears it; unrelated errors cannot inherit it. Fixtures discard migration setup telemetry before exercising a request/job. Real console, filtered-job and 150-query tests verify this behavior without expanding Telescope collection or guessing arbitrary replay origin.

## Phase 4 verification

| Check | Result |
| --- | --- |
| Complete SQLite suite | 106 tests, 517 assertions; 5 engine/pcntl fork cases explicitly skipped |
| Complete MariaDB 11.7.2 suite | 106 tests, 567 assertions; all 5 fork cases passed |
| Frontend unit tests | 3 passed: safe/literal note links, canonical saved definitions and UTC timestamp parsing |
| TypeScript / production build | Passed |
| Larastan level 5 / Pint | Passed |
| Composer metadata / final archive hygiene | Passed; compiled assets, source and collaboration migration matched, with development/QA files excluded |
| Additive clean-host upgrade | Passed: schema 3, encrypted notes/tombstones, private views/preferences and host assignment adapter on a no-dev Laravel host without consumer Node/Testbench |

Coverage includes note encryption/redaction, idempotent submissions, author/moderator ACLs, note/body tombstones, parent scope, evidence expiry, unreadable notes, private-view isolation, shared moderation, definition validation/repair, relative ranges, stale edits, preference order/default/version/access trimming, actor case sensitivity, provider down/removal/disable/re-enable/clear, explicit actor filter aliases, generation fences and transaction rollback. Forked collaboration tests verify duplicate note creation produces one note/activity/disabled intent and competing view/preference edits accept one version. Existing capture/lifecycle/configuration suites remain included.

Browser QA used Playwright because a Browser plugin/skill is unavailable. Verified note create/edit with timestamps, literal HTML and safe anchors, redaction, stale-edit draft preservation, in-memory panel navigation, controlled confirmation cancellation/discard, empty localStorage and no draft URL. The native dialog tool proved unreliable, so controlled confirmation responses tested the actual UI guard branches. An intentional HTTP409 appeared during the stale-edit test; there were no unhandled Vue exceptions. Personal/shared creation, Modified without implicit overwrite, copy, favorites/default, short-link hydration, eligible assignment and the disabled preview were exercised. No external message or issue was sent.

Visual verification used 1487 × 1058 desktop and 390 × 844 mobile, with full-page captures after content settled. `view_image` compared the final notes/assignment and saved-editor captures with the accepted details/settings references. Figtree, shell, palette, controls, flat grouping and source monospace were preserved. The mobile assignment moves above long activity, and the editor remains an inline form. These are local adaptations under `.impeccable/briefs/phase4-collaboration.md`, not a new world or measured pixel reproduction. The single detector run reported inherited selected-tab/source-monospace warnings; no new system rule was invented to suppress them.

The independent finish reviewer returned ship with no material fixes. The documenter checked the sources and four captures and confirmed that no token/system documentation change was needed; historical Phase 1 capability prose remains acknowledged pre-existing drift. Existing DESIGN.md and its token sidecar were preserved.

Remaining validation is the full remote runtime matrix, Redis runtime use, Octane end-to-end operation and representative production-volume profiling. Directory adapters need host-owned eligibility/timeouts; the development adapter proves the package journey, not connectivity to a real external directory. Full rule/delivery/integration and purge acceptance remain later phases. Nothing was published.

## Phase 5 email alerting

Added schema version 4 with seven additive tables: connections, alert_rules, rule_connections, deliveries, delivery_rules, delivery_attempts and notification_gates (23 physical tables total). Connection address secrets use the existing encrypted secrets table with a connection foreign key. Installation upgrades preserve prior published migration filenames and host configuration.

Rule evaluation and coalesced delivery intent execute inside existing capture/lifecycle/collaboration transactions. Current conditions include event, deployment-allowed environment, exact exception class, retained origin/permitted tag, fixed 5/15/60-minute UTC threshold and cooldown. New/regression occurrence events take priority over a simultaneous threshold while every supporting rule is retained. Reconciliation, history and synthetic capture checks never automatically export email. Existing disabled event rows are not retroactively planned.

Each trimmed/lowercased ASCII email address has a keyed canonical identity and encrypted immutable target. Replacing/disconnecting never redirects pending intent or implicitly adds historical recipients. Sender claims use installation → issue → recipient/event-family gate → delivery locking. Claims revalidate current configuration, rules, issue eligibility, target identity and initiating permissions. Provider I/O occurs outside database transactions with Telescope recording suppressed. Shared cooldown waits consume no attempts; different recipients remain independent.

The durable ledger owns 120-second leases, bounded attempts/cycles, safe classified outcomes, exponential backoff, expiry, recovery and reference-safe pruning. It freezes a sanitized logical payload before sending; stricter export policy cancels an incompatible retry and requires a newly reviewed identity. Manual retry/resend and explicit synthetic batches use durable actor-bound receipts. A lost outcome commit after transport acceptance becomes uncertain, recoverable work: at-least-once delivery can duplicate email and never promises exactly-once receipt.

The Telescope Light UI now includes rule editing/preview, email destinations and synthetic checks in settings/setup, filtered history and delivery details with frozen payload and attempts. Rule/email drafts survive conflicts and navigation can be cancelled before discarding. History masks recipient addresses for viewers. Overview/system health show real delivery observations; integrations links available email configuration while clearly deferring GitHub/Slack/webhooks. SMTP/API credentials and transport timeouts remain host-owned.

## Phase 5 verification

| Check | Result |
| --- | --- |
| Complete SQLite suite | 138 tests, 740 assertions; seven engine/pcntl cases explicitly skipped |
| Complete MariaDB 11.7.2 suite | 138 tests, 800 assertions; all seven fork cases passed; final broker/recovery-health regression also passed (one test, seven assertions) |
| Frontend unit tests | Three passed |
| TypeScript / production assets | Passed |
| Larastan level 5 / Pint | Passed |
| Composer metadata / archive hygiene | Passed; 191 archive entries, source/dist byte equality, alerting migration included, QA/development/database files excluded |
| Additive no-dev host | Passed: schema 4, encrypted canonical destination, live automatic intent, no sync inline send, explicit array-mail acceptance and redacted frozen detail without Node/Testbench |

Thirty alerting tests plus two new fork cases cover rule coalescing, canonical targets, filtered fixed thresholds and event-time ordering, historical exclusion, frozen retries, bounded backoff/Retry-After, policy changes/reviewed resend, lifecycle/activity eligibility, shared gates, version edits/deletion, target replacement and unreadable secrets, actor revocation, broker outage, generation fences, expiry/pruning, provider calls outside transactions and a database outcome failure after successful acceptance. Redaction is stable when already-redacted placeholders are processed again, while custom spanning patterns still redact surrounding sensitive content. Migration validation caught and corrected a MySQL identifier-length limit on the gate foreign key.

Browser verification exercised a saved enabled production rule, server-side preview, explicit test confirmation, the actual database queue/worker with the host array mailer, history/detail, and rule draft leave/cancel/discard. No external message was sent. Overview/system-health checks also showed real pending/failed counts and processing/recovery observations. Browser console contained no errors or warnings. A separate no-dev production-dependency Laravel host verified live capture through automatic intent and transport acceptance, including current encryption/redaction and alternate dashboard prefix.

Visual checks inspected final full-page rules/email/history/detail captures at 1440 × 1000 and 390 × 844, with supplemental integration-entry captures. The single detector run reported incumbent tab underline/monospace warnings and historical type-ramp advisories. Fresh independent review returned **ship** with no material fixes, including the supplemental integration entry. The documenter confirmed existing tokens/system were preserved; DESIGN.md and its sidecar remain unchanged, including acknowledged older capability prose. This is an ordinary extension, not a measured pixel reproduction.

Live SMTP/API connectivity, inbox receipt, Redis runtime use, the remote PHP/database matrix, Octane and production-volume profiling remain host/release acceptance work. No package was published. The [README email guide](../README.md#email-alerts-and-recovery-phase-5) documents deployment settings, workers, scheduler commands and delivery guarantees.

## Phase 6 Slack signed webhooks and GitHub Issues

Implemented all three providers behind the existing notification interface and durable ledger. Added the linked_issues repository reservation table, connection-wide Slack transport gates and selected-key test references; storage is schema 5 / 24 physical tables. cURL is a Composer platform requirement. Lock metadata was refreshed without changing installed package versions.

HTTP transport resolves/validates every A/AAAA address at each attempt, pins a permitted IP while preserving TLS hostname verification, disables redirects/ambient proxies/reuse, bounds response bodies and classifies responses without exposing raw request/credential/response errors. Slack endpoints and the public GitHub API origin/path are restricted separately from generic HTTPS webhook policy. Private webhook access requires an exact deployment host and CIDR range. Runtime secret references are purpose-allowlisted; endpoint fingerprints are rechecked so credential updates cannot redirect old work.

Slack notifications escape imported text, disable markup/automatic mentions/unfurls and share one transport reservation across all local errors for a canonical destination. Webhooks use a versioned fixed JSON contract, immutable body bytes/IDs, per-attempt timestamps and exact-body HMAC SHA-256. Connection event selection intersects alert rules. Key lifecycle is generated-once initial key, staged revision, explicitly selected staged-key test, administrator activation and a 24-hour receiver transition. Maintenance clears expired retiring material/references in bounded batches. Ordinary credentials are never redisplayed.

GitHub read checks inspect repository metadata and up to 100 existing labels without asserting write permission. Manual creation previews and freezes exact sanitized title/body/labels and a stable marker. One issue/repository reservation prevents concurrent independent creation. Current actor/target/export policy is checked at execution. Accepted responses validate repository, number, canonical URL and issue type; pull requests/unrelated repositories cannot be linked. Existing links, explicit refresh, local-only unlink and optional new-live-error automation are connected. Regression/history/synthetic paths never create repeated automatic tickets.

Unknown GitHub acceptance, expired creation ownership or lost local outcome commits become Needs review instead of another automatic POST. Bounded repository inspection never treats absence as proof. Link existing or an explicitly audited no-ticket confirmation plus a fresh reviewed operation resolves uncertainty. A recreated operation receives a new ID/marker and parent-delivery reference. Definitive throttles/rejections retain bounded classified recovery. Maintenance releases an expired, unattempted or definitively failed reservation for a newly reviewed operation without sending another request. Needs review is visible in connection counts, history, details and monitoring health.

UI additions preserve the approved Telescope Light shell: flat integration rows, protected inline connection editor, one-time key notice, signing-key controls and exact-export/Needs review forms in Linked issues. Email/Slack/webhook destinations share rule editing and history filters. Drafts and keys stay out of URLs/localStorage; provider checks and transport outcomes remain separate. Full operational onboarding/retention/purge completion remains Phase 7.

## Phase 6 verification

| Check | Result |
| --- | --- |
| Complete SQLite suite | 169 tests, 948 assertions; nine engine/pcntl cases explicitly skipped |
| MariaDB 11.7.2 integration contracts | 28 tests / 192 assertions passed, plus final parent-reference, review-count, deployment-reference retirement and reservation-expiry regressions |
| MariaDB concurrency and upgrade checks | All nine process-race cases and fourteen foundation/installation cases passed; scoped engine checks, not a claimed full MariaDB rerun |
| Frontend unit tests | Three passed |
| TypeScript / production build | Passed |
| Larastan level 5 / Pint | Passed |
| Additive no-dev host | Passed: schema 5, reviewed GitHub reservation/association, exact signed webhook, no sync inline external write, no open transport transaction/recording, no Node/Testbench |
| Composer metadata / consumer archive | Passed; 209 entries, exact final source/dist bytes, integration migration included, developer/QA/database files excluded |

Coverage includes canonical endpoint identity, escaped Slack content/throttles, independent target support, exact signatures/clock windows/body mutation, immutable retry bytes, staged testing/activation/retirement, URL/redirect/private/mixed DNS policy, current deployment credentials, actor revocation, exact preview digest, duplicate/concurrent reservation, automatic exclusions/regression reuse, remote PR/repository validation, explicit link/refresh/unlink, and lost-response/expired-lease/lost-commit Needs review behavior. A crash-injection fixture was corrected to recognize both SQLite and MySQL SQL quoting; all integration contracts then passed on MariaDB. Existing capture/lifecycle/collaboration/email regressions remain included in the complete SQLite suite.

Browser checks used a separate local controlled transport/database fixture on port 8197. No live Slack, webhook or GitHub request was sent. Post-acknowledgement activation also displayed the receiver transition deadline; localStorage remained empty. Saved all provider connection types, queued a read-only repository check and staged-key test through the real database worker, acknowledged one-time key display, preview-confirmed a controlled GitHub creation to linked state, and held an ambiguous result for read-only inspection / guarded new-attempt review. Stored credentials were not redisplayed; normal creation disabled behind an existing reservation. The normal workbench was not redirected to controlled HTTP adapters.

Visual review inspected two bounded desktop/mobile rounds at 1487 × 1058 and 390 × 844 for integrations, editor, keys, exact preview and Needs review. The single detector reported established tab/monospace warnings and historical type/radius advisories. Independent review found one material same-page one-time-secret guard gap; mutation actions are now blocked until acknowledgement. Fresh captures and browser probes confirmed the key remained visible and actions re-enabled only after acknowledgement. The focused verdict scored that fix resolved and returned ship. The documenter confirmed existing-world continuity, preserving DESIGN.md/sidecar and their acknowledged historic scope drift; one local indigo checkbox accent differs slightly from the normative token without changing the system.

The [README](../README.md#slack-signed-webhooks-and-github-issues-phase-6) and [receiver contract](webhook-contract-v1.md) describe setup, deployment references, network policy, signing/rotation/deduplication, current permissions and uncertainty recovery. Remaining acceptance includes live provider credentials/permissions and delivery, representative DNS/TLS/egress environments, Redis, Octane, the full remote runtime matrix and production-volume profiling. Nothing was published.

## Phase 7 operational completion retention and purge

Added the final logical purge_runs table and persisted installation retention cutoffs, completing 25 physical tables at schema 6. Fresh installation metadata is derived when the installation is created. Configuration/command compatibility and published migration suffixes remain additive.

Retention now has a shared effective policy, deployment bounds/locks and a dated bounded impact preview. Saved changes remember irreversible expiry boundaries; selected evidence, projections/insights and metrics honor current limits before sweeping. A later increase cannot silently rehydrate expired evidence. New activity uses its configured history retention. Bounded graph cleanup preserves lifetime summaries, counts, notes, views/preferences, links and UUID replay identity, respects active references and healthy leases, and removes eligible delivery/event/receipt history. Terminal buffer byte cleanup preserves successful outcome metadata instead of inventing failed captures; delayed jobs reject expired buffers.

Operational journals separate starts, successes and failures, retain ten bounded safe histories and ignore stale-generation observations. Health adds source coverage, complete schema, active-key availability, dated worker/recovery/retention observations, expired leases and maintenance state. Missing/stale/start-only/quiet-worker observations remain qualified; local heartbeat is not independent availability proof. Dashboard configuration reads and processing enforce current saved revisions without depending on process cache invalidation.

Purge is deployment-only with exact installation confirmation and read-only preview. It drains valid leases without cancelling their ownership, fences generation/source boundaries, invalidates unsent work and clears the error-domain graph leaf-first with foreign keys enabled. Each bounded step/count commits atomically; interrupted or concurrent resumes use the same run. Verification is required before active state resumes. Preserved configuration includes settings, credentials, alert rules, views/preferences, installation identity and minimal receipts/ledger. Old jobs/completions and source generations cannot resurrect data. Retained post-cutoff new-generation source can reconcile as history without alerts; drain abort is allowed only before fencing. Explicit source baseline changes epoch/excludes current source while retaining time protection and existing monitor data.

Host-key maintenance rewraps all encrypted targets in bounded pages while preserving plaintext bytes/digests/IDs/revisions and unreadable ciphertext. Credential reference/key health is visible; secrets never appear in journal histories. The deployment guide distinguishes host encryption rotation from webhook signing-key rotation.

Setup now has reviewed application/privacy/operations state, a current-generation/current-epoch readable capture proof, a current enabled target/signing-key delivery proof or explicit notification skip, and persisted Finish. Purge resets proof; old receipts do not imply successful new-generation setup. The UI distinguishes reviewed/verified/skipped, preserves drafts/conflicts, exposes effective limits/estimates and read-only CLI purge/source/key guidance under the existing Telescope Light direction.

## Phase 7 verification

| Check | Result |
| --- | --- |
| Complete SQLite suite | 202 tests, 1,168 assertions; twelve engine/pcntl cases skipped explicitly |
| MariaDB 11.7.2 operational/install/concurrency scope | 54 scoped tests exercised the graph, setup, key, retention and install contracts; all non-race checks passed; a terminal concurrent-resume race was found, fixed and rerun |
| Final MariaDB resumer/history checks | Four tests / 20 assertions passed, including all three new process-race cases and configured activity history; all twelve current fork cases passed across scoped runs |
| Frontend unit tests / TypeScript / production assets | Passed |
| Larastan level 5 / Pint / Composer metadata | Passed |
| Isolated no-dev Laravel host | Passed: schema 6 upgrade, actual capture proof, setup finish, all encrypted-target rewraps, bounded retention and verified resumable purge with preserved settings/keys/views, without Node/Testbench or provider I/O |
| Consumer archive | Passed; 228 entries, exact source/dist bytes, operations migration and both operator guides included, developer/QA/databases excluded |

Tests cover exact confirmation/preview no mutation, drain lease preservation/current completion, expired/stale completion fencing, no open provider transaction, every deletion step/resume with foreign keys, configuration/key/view/receipt preservation, rollback/count consistency after injected deletion failure, post-cutoff recovery without historical alerts, abort restrictions and drain-marker recovery, source reset/baseline/time protection, irreversible expiry/current reads, counts/notes/views/link/UUID preservation, a capped 152-row retention fixture, hard limits/locks/incompatible-range diagnostics/stale edits, healthy buffer leases, delayed expired buffers, maintenance histories and safe errors, stale/quiet-worker states, key loss/all-target rewrap/unreadable preservation, current signing-key proof and setup reset/ACLs. Existing phases remain in the complete SQLite suite.

Browser QA used a separate controlled copy on port 8198. It completed setup through the real database queue/worker, reloaded the persisted finish state, explicitly skipped notifications, previewed/saved retention, observed an actual evidence/metric removal and journal counters, then ran confirmed bounded purges only on the copied fixture. Settings/views/connections survived; error-domain rows verified empty. A source event retained during purge recovered as history without alerts. The previous capture proof was invalidated and a fresh generation proof completed setup again. Original workbench/prior preview and standalone-host original databases were not purged. No live external provider request was sent.

Visual QA inspected desktop1487 × 1058 and mobile390 × 844 for completed setup, retention impact, operations/history and purging guidance. One initial fix batch separated skipped/reviewed/verified labels, aligned mobile checklist rows, removed a redundant maintenance banner and improved deployment disclosures/configured command guidance. Final captures were opened/validated; an accidental focused skip-link capture was recaptured. The single detector reported incumbent tab/monospace warnings plus historical type/radius advisories. Fresh independent review returned ship with no material findings; the documenter confirmed continuity and preserved DESIGN.md/sidecar, including acknowledged historic scope drift.

The [README](../README.md#operations-retention-and-purge-phase-7) and [operations guide](operations-guide-v1.md) describe defaults/limits, scheduling, purge confirmation/drain/resume/abort, source reset and host-key rotation. Remaining acceptance is explicitly Phase 8: full remote PHP/Laravel/Telescope/database matrix, Redis/Octane, live provider permissions/delivery, source and volume profiling, and final release identity/artifact review. Nothing was published.

## Next implementation gates

1. Complete Phase 8 release acceptance across the declared compatibility, provider, queue and volume matrix.
2. Review the final package identity/release artifact before any separately authorized publication.

The [approved phased plan](implementation-plan-v1.md) remains the roadmap. Completion of Phase 7 is not completion of the Phase 8 release acceptance gate.


## Phase 8 — release verification / review candidate

Review candidate `1.0.0-rc.1` is prepared locally; publication remains held. The complete current acceptance disposition is in [release verification](release-verification-v1.0.0-rc.1.md): 21 passing implementation rows and 7 partial rows. This is not completion of the v1 release gate.

PHP 8.3.17, 8.4.27 and 8.5.10 each passed 202 SQLite tests / 1,168 assertions, with 12 engine-specific skips. The complete local MariaDB 11.7.2 suite passed 202 tests / 1,260 assertions with zero skips, including the 12 independent-process cases. This older engine is compatibility evidence only; MySQL 8.4 and maintained MariaDB 11.4 CI targets are prepared but unexecuted. The Laravel 13.0.0 fixture was refused by Composer security policy and was not certified.

The run exercised fresh no-dev zip installation and completed setup, repeat publication/migration identity, a declared local Phase 7 archive upgrade with retained-row/config-byte comparison, actual database/Redis queue probes, real Redis outage/restart, native TLS/HMAC/SMTP checks, controlled Slack/GitHub HTTPS forwarding and an actual SIGKILL after SMTP acceptance. After the actual 120-second lease, normal recovery reused the logical ID and recorded uncertain then accepted attempts. Live accounts were not contacted.

Desktop/tablet/phone main routes and representative lifecycle/note/view/settings flows were checked. A skip-link focus defect was fixed in the shared main region and verified; compiled assets were rebuilt. Build/unit/type/static/format checks and current dependency audits passed. The build helper prepares only local review files, compares archive bytes/manifest assets with source and excludes development/private content.

One product blocker was reproduced in a fresh host: failed Artisan exceptions are captured with unknown origin when nonexception command telemetry is filtered. The expected-failing fixture and precise cause are included in review evidence. This requires a provable command/throwable association and regression before publication. Remaining runtime/engine, crash permutation, UI accessibility/state and production workload gates are listed explicitly in the report.

The generated review bundle lives in `releases/1.0.0-rc.1/` (ignored/excluded from consumer archives), with ZIP/checksums/manifests, explicit local Composer metadata, notes/report and sanitized evidence. There is no Git tag or registry publication. Source/test/build hashes and dependency locks bind provenance because this workspace has no Git repository.


## Post-candidate shared layout correction

The user's Telescope screenshot feedback is implemented in current source: centered 1440px container, page titles inside the white page card, no top-right actor avatar or preview-name label, and shared compact controls with pill search fields. Desktop, tablet and phone browser checks cover the main routes and settings; build/typecheck and frontend unit tests pass. The sealed 1.0.0-rc.1 archive remains unchanged and does not include these later UI changes. A subsequent reviewed candidate must be built after the remaining corrections and release blockers are resolved.


The alert environment list box is replaced by a compact checkbox disclosure with single/multiple/empty summaries, keyboard and outside-pointer dismissal, and a 44px mobile trigger. Browser checks cover actual preview integration plus browser-only multi-environment payload fixtures and emulated touch, without saving rules or changing deployment allowlists. Native single selects now share an inset chevron; placeholder colors and invalid-hover borders stay consistent. Build/typecheck and the three frontend unit tests pass. These remain post-rc.1 changes.


## Post-candidate configurable database storage

The user accepted Telescope storage as the default: explicit monitoring connection → Telescope storage connection → host default when unset. `EXCEPTION_MONITOR_DB_CONNECTION` optionally names a separate host-defined Laravel connection. All package models, raw queries, transactions, commit callbacks, schema checks, retention and purge share this selection. Telescope source queries and host identities/queue storage remain independently configured.

New `exception-monitor:migrate` scopes migration files and the migration ledger to the selected database; nondefault installations publish under a package subdirectory. Existing published migration suffixes remain stable. No database is provisioned and no existing data is moved automatically. Documentation explains first installation, configuration caching/worker restart and explicit data-transfer requirements.

Verification: 209 SQLite tests / 1,230 assertions, with 12 engine-specific skips; static analysis and formatting pass. Seven storage tests cover separate host/source/monitor connections, explicit override, Telescope inheritance, default fallback, invalid names, atomic rollback/commit callbacks and isolated install/migration behavior. A no-dev Laravel host shared a separate selected database between Telescope and all 25 package tables, captured an occurrence and left existing primary history and the host default unchanged, using its older published migrations. No consumer Node/Testbench was present. Production engine baselines remain subject to the existing release gates; sealed rc.1 is unchanged.


## Post-candidate historical Telescope backfill

Implemented `exception-monitor:backfill` with required original environment, bounded source sequence pages, fixed ceiling/resume cursor and a no-write dry run. It imports unmarked exception UUIDs through sanitization/encrypted buffering and inline CLI ingestion, retaining ordinary recovery for pending work. Signed/invalid package markers are left to the normal recovery path; source rows, tags and reconciliation checkpoints are not rewritten.

Historical occurrences and history-only groups are labeled in the UI. Reportability is explicitly unavailable, current deployment source/release metadata is not invented, and original event timestamps/retention are preserved. Repeated/concurrent imports and replay after evidence expiry deduplicate by source epoch/UUID. Existing lifecycle state is preserved; no historical alert event, delivery or automatic GitHub issue is planned. Historical imports are excluded from subsequent live frequency counts.

Verification: full SQLite suite 224 tests / 1,312 assertions with 13 engine-specific skips. MariaDB 11.7.2 backfill/capture concurrency scope passed 15 tests / 109 assertions; final malformed/timezone edge checks passed 2 tests / 8 assertions. Three real backfill processes imported the same 12 UUIDs with exactly 12 occurrences and no alert intent. Static analysis, Pint and the Vue/TypeScript build pass. An isolated workbench command imported a fixture without source changes or new delivery intent; desktop and phone historical labels render without overflow or console errors. The sealed rc.1 archive remains unchanged.


## Native Telescope route authorization

At the user's request, the default monitoring authorization driver is now `telescope`, delegating HTTP access to Laravel Telescope's built-in Authorize middleware and Telescope::check. Fresh middleware uses web without the separate Laravel session-auth requirement. Separate capability gates remain opt-in via driver=gates. Authenticated users retain their host identity; authorized anonymous visits use an explicitly shared operator identity without creating/signing in a host User. CSRF protection remains active.

Queued real-user actions re-resolve the user and check viewTelescope; local shared actions recheck Telescope access. Deployed guest-only queued work fails closed unless the host explicitly grants allow_guest_jobs. An approved HTTP visitor cannot authorize a different saved actor. Documentation explains shared notes/view ownership and worker policy.

Verification: full SQLite suite 232 tests / 1,351 assertions with 13 engine-specific skips before adding two extra authorization regressions; the final 10-test authorization scope passes. Static analysis passes. An anonymous Laravel HTTP-kernel dashboard request returned 200 with assets and no signed-in user. No extra login route or user-account mutation was introduced.
