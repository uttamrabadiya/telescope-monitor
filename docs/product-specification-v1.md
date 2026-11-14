# Telescope Monitor v1 Product Specification

Status: Draft for product review  
Updated: 10 November 2026

Telescope Monitor is an error-monitoring product installed inside a Laravel application as a Composer package. It uses Telescope as its capture source and provides a complete workflow for setup, investigation, notification, issue tracking, and administration. Flare is the reference for the breadth and clarity of the experience; the product will have its own interface and implementation.

This specification replaces the earlier exception-dashboard-only scope. It describes the intended replacement product, not the capabilities of the existing prototype. The user has approved the main flows, visual direction and architecture and authorized a fresh implementation. The [progress record](replacement-progress.md) identifies implemented capabilities; this specification remains the full v1 contract.

## Confirmed product decisions

| Decision | v1 requirement |
| --- | --- |
| Deployment | One Composer package inside each monitored Laravel application |
| Capture source | Official Laravel Telescope |
| First release | Complete error investigation, alerts, settings, integrations, and operational diagnostics |
| Collaboration | Notes and saved views included; assignments optional |
| Integrations | Email, GitHub Issues, Slack, and outbound webhooks |
| Application services | Use the host database, authentication, mail configuration, queue, and scheduler |
| Installation | Consumers do not need Node.js or a frontend asset build |
| Development approach | Plan and design the replacement before writing more application code |

Numeric defaults, authentication mechanisms for integrations, and detailed interaction rules below are proposed v1 defaults for review. They make the product testable without treating every setting as an unanswered implementation decision.

## Purpose and success criteria

A developer should be able to install the package, verify capture, configure an alert, receive a notification, investigate the relevant occurrence, create or link a GitHub ticket, and manage the error through resolution and regression without discovering essential configuration in source files.

An administrator should understand what data is collected, who can access it, what leaves the application, how long it is kept, and whether delivery and maintenance are working.

The release is complete when these journeys work in a fresh supported Laravel application with real Telescope events and test destinations, including failure recovery. A polished inbox alone does not satisfy this definition.

## Scope and exclusions

| Included in v1 | Deferred beyond v1 |
| --- | --- |
| Setup wizard and persistent setup checklist | Central dashboard across applications |
| Error overview and occurrence trends | Organizations, invitations, subscriptions, or billing |
| Grouped error inbox and saved views | Request, query, and job performance dashboards |
| Occurrence investigation and bounded related context | Distributed tracing and profiling |
| Resolve, reopen, ignore, and timed snooze | Browser SDK, frontend errors, session replay, and web vitals |
| Notes and issue activity | Rich text documents, mentions, attachments, or chat |
| Optional assignments using host identities | Independent user directory or on-call rotations |
| Alert rules, destinations, tests, and delivery history | SMS, digests, quiet hours, and escalation schedules |
| GitHub ticket creation and linking | GitLab, Jira, and Linear adapters |
| Source repository links when correctly configured | Two-way GitHub state synchronization and PR automation |
| Editable settings and deployment locks | Automatic deployment configuration changes |
| Privacy controls, retention, and system health | Uptime monitoring or an independent external heartbeat service |

Error trends are part of v1. They describe captured exception occurrences, not application throughput, availability, or request error rates. Release/version metadata is an optional filter and source-link aid; a release-management module is deferred. Bulk status changes and automatic live refresh are deferred; ordinary pagination and an explicit refresh action are sufficient for v1.

## Audience and authorization

The product serves developers investigating production errors, operators triaging incidents, and administrators managing access and integrations. It uses authenticated host identities and host authorization callbacks. It does not create accounts, supply a login screen, or depend on a specific Eloquent User model.

The following capabilities describe the default permission model. The host maps its users and roles to these capabilities; it need not use these role names.

| Capability | Viewer | Operator | Administrator |
| --- | --- | --- | --- |
| Read errors, retained context, notes, and sanitized delivery history | Yes | Yes | Yes |
| Create, edit, and delete own personal saved views | Yes | Yes | Yes |
| Change issue status, snooze, add notes | No | Yes | Yes |
| Create shared saved views and edit own shared views | No | Yes | Yes |
| Create or link a GitHub ticket through an existing connection | No | Yes | Yes |
| Retry a failed notification to its configured destination | No | Yes | Yes |
| Assign issues when the host assignment provider is configured | No | Yes | Yes |
| Manage rules, connections, credentials, retention, and privacy | No | No | Yes |
| Send connection test messages | No | No | Yes |
| Moderate notes or edit/delete another user's shared view | No | No | Yes |
| Inspect detailed operational failures and configuration | No | No | Yes |

Every route, mutation, and destination test must enforce its capability on the server. Background delivery rechecks current installation policy. Queued user-requested external writes also require the initiating identity to remain authorized; an identity that cannot be resolved or has lost access produces a visible cancelled action. Automatic rules execute under the installation's authorized configuration rather than a former administrator's login session. The interface explains unavailable capabilities and does not rely on disabled controls for security. Production access is denied until authorization is explicitly configured. A guest-access workbench is development-only and is never enabled by consumer installation.

Host callbacks provide a stable actor identifier and, optionally, a display name. Personal views require this stable identifier. Notes and activity retain the actor reference when a user is removed and display “Former user” if the identity can no longer be resolved. Application-user impact identifiers and dashboard-operator identities are separate concepts.

## Deployment and environment model

Each installation monitors its host application. Separate deployments normally maintain separate monitoring data and settings. An environment selector filters environments actually present and authorized in that installation; it does not connect to another deployment or change the application's environment.

Each installation has a persistent generated identifier independent of its display name. Each event records that installation and its capture environment. An error group cannot merge occurrences across environments. The interface always shows the active application, environment selection, time range, and display timezone where those affect the result.

The initial implementation targeted Laravel 13. The current source expands
compatibility to tested Laravel 8–13 releases while retaining PHP 8.3 as the
minimum. See the [framework compatibility report](framework-compatibility.md) for
exact framework floors, database results and legacy dependency advisories.

## Product vocabulary and retained records

| Term | Meaning |
| --- | --- |
| Error | A durable group of similar captured exceptions in one environment |
| Occurrence | One uniquely identified captured exception event |
| Snapshot | Sanitized retained evidence for a particular occurrence |
| Timeline | Related captured events from the same request or execution batch |
| Alert event | An event eligible for rule evaluation, such as a new error or regression |
| Rule | Conditions and destinations determining which alert events produce notifications |
| Destination | An email address or a configured Slack/webhook connection |
| Delivery | Durable intent to send one logical notification or perform one external action |
| Linked issue | The association between a local error and a GitHub issue |
| Saved view | A named, versioned set of inbox filters, sort order, and relative time range |
| Activity | A chronological record of meaningful user and system changes |

The product owns error state, snapshots, notes, saved views, settings, alert rules, deliveries, and linked issues. Telescope UUIDs and batch identifiers are references, not the only retained investigation evidence. The schema and module layout will be designed after the product flows are approved.

## Capture and grouping

Capture must respect enabled Telescope watchers, recording state, entry and batch filters, and Laravel reportability. The package must not silently expand Telescope's collection policy. Telescope provides request, exception, log, query, job, and other watchers, and supports pruning of stored entries. These constraints must be visible during setup and investigation. [Laravel Telescope documentation](https://laravel.com/framework/docs/telescope)

The normalized ingestion path must handle HTTP, queued-job, and command exceptions. Telescope-specific behavior belongs in the capture adapter. Stored source entries can be replayed through the same idempotent ingestion path while source data exists. Events that Telescope never stored cannot be recovered by reconciliation.

Proposed grouping v1 uses a versioned fingerprint based on environment, exception class, application-relative file, and line. Messages are excluded so changing IDs do not fragment a group. Moving code can produce a new group. A documented host callback may override grouping; changing its policy requires an explicit version change. Automatic historical regrouping, merging, and splitting are deferred.

Required invariants:

- Replaying a Telescope UUID does not increment counts twice, even after its snapshot expires.
- Concurrent capture produces one group and accurate lifetime counts.
- First and last occurrence times reflect event time, including out-of-order ingestion.
- An older historical occurrence does not reopen an error resolved after that occurrence happened. Resolution records a sufficiently precise cutoff; timestamp ties and replay order must have deterministic tests.
- Routine repeated occurrences do not create repeated new-error alerts or GitHub tickets.
- Package failures preserve the host exception and do not recursively create monitor alerts.
- Monitor routes, delivery jobs, integration tests, and internal processing failures are excluded from automatic capture/alert loops. Explicit synthetic setup events remain labeled test data.
- Capturing a new/regression alert and its durable delivery intent is transactional.

An error created only by historical reconciliation is marked as recovered history. Reconciliation updates investigation data without retroactively sending alerts or automatically creating tickets. Existing durable deliveries from an earlier capture remain recoverable.

## Investigation data and privacy

Capture only the context necessary for investigation and permitted by the deployment. A source category may be unavailable because its watcher or filter was disabled; the UI must distinguish unavailable, excluded, truncated, and expired data.

| Data category | Proposed default | Limits or behavior |
| --- | --- | --- |
| Exception | Class, sanitized message, relative file, line | Message up to 16 KiB; explicit truncation indicator |
| Stack trace | File/line and available class/function names, with application frames emphasized | Up to 100 frames; no arguments or serialized objects |
| Origin | Web request, job, or command, with sanitized identifier | Preserve source type even if related context is absent |
| Request | Method, route/path without query string, status, available duration | No bodies, cookies, authorization headers, or response bodies |
| Job | Class, queue, available attempt/state metadata | No serialized job payload or arguments |
| Command | Command name and available status | No command arguments or options by default |
| Related logs | Sanitized message and level | Up to 20 entries; 2 KiB per message; no arbitrary context |
| Related queries | Available connection, duration, count, and safe query identity | Raw SQL and bindings excluded; do not assume stored SQL is safe |
| Timeline | Allowed related events in the same Telescope batch | Up to 100 events and 64 KiB of serialized related context |
| Tags | Deployment-allowlisted tags only | Up to 20 tags of 64 characters |
| Application user identity | Disabled | Optional stable pseudonymous identifier; email/name collection requires an explicit policy |
| Release identifier | Optional host-supplied value | Used only when actually captured; never inferred from current deployed files |

All sizes apply to UTF-8 serialized content. Hard deployment limits bound any editable settings. The package must sanitize before writing its own records, logs, notifications, or external payloads. Exception messages and log text may contain secrets even when structured request context is excluded.

The privacy screen provides redaction patterns, category controls, permitted-field allowlists, a test-text redaction preview, and a preview of an external notification payload. Preview actions never send data externally. Changing privacy settings affects future capture and exports. It does not claim to sanitize already-retained evidence retroactively.

Telescope's original storage has its own collection and retention policy. Changing package settings does not erase or redact Telescope's existing records. Users must receive a clear explanation of this distinction.

Source links are available only when repository and release mapping are configured. The interface must not present source from the current deployment as the historical source of an old occurrence. Persisted source excerpts, arbitrary attachments, and public share links are excluded from v1.

## Information architecture and shared UI behavior

The application navigation contains Overview, Errors, Alerts, Integrations, and Settings. System health is an administrator page under Settings, with a compact warning entry point elsewhere when intervention is required. Setup remains accessible after installation.

| Navigation area | Pages and panels |
| --- | --- |
| Overview | Time and environment controls, occurrence trend, new errors, regressions, actionable issues, delivery health |
| Errors | Inbox, built-in and saved views, error detail |
| Error detail | Occurrences, stack trace, context and timeline, notes and activity, linked issues |
| Alerts | Rules list, rule editor, destinations summary, delivery history, delivery detail |
| Integrations | Connections list, email setup, GitHub setup, Slack setup, webhook setup, connection diagnostics |
| Settings | General, capture and privacy, retention, access and assignments, system health, setup checklist |

The global shell provides the application identity, environment filter, current section, and contextual navigation. Time controls appear on analytical/list pages; occurrence timestamps include their timezone. Changes to filters update the URL, support browser history, and preserve state when opening and returning from an error. Search terms can appear in authenticated URLs/history, and the search UI explains this. Integration credentials, note bodies, redaction-test text, and raw investigation payloads must never be placed in URLs.

Core workflows must support keyboard navigation, visible focus, labeled controls, error recovery, and text alternatives to charts. Status must not depend on color alone. Design and test against WCAG 2.2 AA criteria without claiming conformance before verification. Test desktop, tablet, and phone layouts, including a 320 CSS-pixel width and long exception identifiers. Screenshots and sample data must be labeled as illustrative during design review.

No automatic live stream is required. Refresh is explicit and each relevant screen shows its data timestamp. Permission errors, missing setup, and unavailable capabilities have actionable states rather than broken controls or misleading zero counts.

## Setup and onboarding flow

An authenticated administrator entering an unconfigured installation sees setup. Other authorized users see the accessible portion of the application and a message identifying the administrator setup that remains required.

| Step | User action | Observable result |
| --- | --- | --- |
| Verify installation | Review package/Telescope versions, tables, watcher state, and access | Each check is passed, warning, failed, or unverified, with a remedy |
| Identify application | Confirm display name, URL, environment, and display timezone | Preview of links and timestamps |
| Review capture | Review context categories, redaction, exclusions, and deployment locks | Readable summary of retained and excluded data |
| Capture an example | Explicitly generate a synthetic test event in an allowed environment | Clearly labeled test error appears through the real capture path |
| Configure alerts | Add email recipient or another destination and review default rule | Rule summary with environment and cooldown |
| Test delivery | Review destination and confirm the test send | Result is recorded with a test label and next action on failure |
| Verify operations | Check worker processing, scheduler heartbeat, retention, and pending jobs | Actual observations distinguished from configuration checks |
| Finish | Open the inbox or overview | Checklist remains accessible and indicates incomplete optional steps |

Setup is resumable. A test exception must not produce an unhandled production request failure or include real customer data. Synthetic events are excluded from normal alert rules and automatic ticket creation; their destination tests are separate explicit actions. The inbox can show or hide them through a clearly labeled test-data filter.

The wizard must explain that a production environment filter can legitimately prevent a local test from becoming an automatic alert. It must not change APP_ENV, host authorization, Telescope filters, or deploy-owned settings automatically. Failed checks do not require restarting the entire wizard.

## Overview

The overview answers what is new, what has returned, what is happening frequently, and whether monitoring is working.

Proposed ranges are 1 hour, 24 hours, 7 days, and 30 days, with 24 hours selected initially. Content includes an occurrence trend, new groups in the range, regression events, frequently occurring unresolved errors, recent issue activity, and a compact delivery-health summary. Counts are scoped to the selected environment and range; lifetime counts are labeled separately.

Trend aggregates are retained independently from occurrence snapshots. Five-minute buckets support recent investigation; hourly aggregates support longer ranges. Display buckets are 5 minutes for a 1-hour range, 1 hour for 24 hours, 6 hours for 7 days, and 1 day for 30 days. They count uniquely accepted captured events and preserve event-time buckets during reconciliation. The UI identifies test data and partial coverage. A missing RequestWatcher or filtered successful requests must never turn into an invented request error rate.

No-data states distinguish a healthy configured installation with no captured errors from a disabled or unverified capture pipeline. Last captured event alone does not prove application health. Stale-data warnings consider configured heartbeats and available observations.

## Error inbox and saved views

Default inbox: unresolved, all permitted observed environments, most recently seen first. Rows show exception class, short sanitized message, file/line, status, origin, environment, occurrences in the selected range, lifetime count, and last seen. First seen is available in row details or a configurable column. Optional assignment appears only when configured. The occurrence-count range does not silently hide older unresolved errors; an explicit first/last-seen filter controls whether those groups are excluded from results.

Supported v1 filters are status, environment, origin, class, permitted tag, first/last seen range, and assignee when enabled. Text search covers class and sanitized message. Sort options are last seen, first seen, and occurrences in the selected range. Advanced search syntax, notes search, and user-created arbitrary query expressions are deferred.

Proposed pagination defaults to 25 rows, with 50 and 100 available. Filtering or sorting resets pagination. A no-results screen offers clear-filter actions and preserves the user's criteria until changed.

Built-in views are Unresolved, Resolved, Ignored, and Snoozed. Optional assignments add Assigned to me. Built-in views are not editable saved-view records.

Saved view behavior:

1. A user sets inbox filters, sort, and a relative time range, then selects Save view.
2. The dialog asks for a name of up to 80 characters and personal/shared visibility. Personal is the default; only operators and administrators can publish a shared view.
3. The view appears in the inbox view switcher with a personal/shared indicator. A user can favorite and reorder their own shortcuts without changing the shared definition.
4. Changing a loaded view marks it as modified. The user can update a view they own or save a copy. Filters do not silently overwrite the stored definition.
5. Owners can rename or delete their views; administrators can moderate shared views. Removing a view never changes errors.

Persist a versioned filter definition with relative ranges rather than a frozen result set. Unsupported filter fields after an upgrade produce a clear repair/reset state. Personal views are private; shared views are visible only to users already authorized to use this installation. Saved views do not grant data access.

## Error investigation flow

Opening an error shows the latest retained occurrence initially. Its header contains the class, sanitized message summary, status, environment, first/last seen, lifetime count, and applicable actions. Primary actions remain available while navigating detail sections.

| Section | Required behavior |
| --- | --- |
| Occurrences | Paginated list with timestamp, origin, captured release, and a stable selected occurrence |
| Stack trace | Full retained sanitized frames; application/vendor distinction; optional valid source links |
| Context and timeline | Available request/job/command metadata and allowed related events from that occurrence |
| Insights | Available route, origin, release, tag, and optional pseudonymous-user breakdowns over retained data |
| Notes and activity | Investigation notes and a chronological record of lifecycle and external actions |
| Linked issues | Existing GitHub association, current last-known state, create/link actions, and failures |

Selecting an occurrence updates its stable URL and all occurrence-specific panels together. A different occurrence must not leave the previous request or timeline visible. Returning to the inbox preserves filters and scroll position where practical.

Insights state their coverage period and only describe data actually captured. An expired or excluded user category is not shown as “zero affected users.” An expired occurrence URL preserves the error header and explains what expired, with a route to retained occurrences.

The Telescope link includes a notice that its source entry may be pruned. Package snapshots remain usable after source pruning. Missing context includes the cause when known and a link to authorized setup guidance; operators do not need to infer whether a blank panel is a collection failure.

## Issue lifecycle and snoozing

| Current state | Event or action | Result | Default alert behavior |
| --- | --- | --- | --- |
| No group | First newly captured occurrence | Unresolved group | New-error event |
| Unresolved | Another unique occurrence | Counts/times update | No repeat alert unless a threshold rule qualifies |
| Unresolved | Resolve | Resolved with a resolution cutoff | No alert by default |
| Resolved | A genuinely newer occurrence | Unresolved; regression activity | Regression event |
| Resolved | Historical replay older than resolution | Remains resolved; history/counts update | None |
| Unresolved | Snooze until a time | Snoozed; occurrences keep counting | Pending occurrence alerts cancelled |
| Snoozed | Another occurrence before expiry | Remains snoozed | None |
| Snoozed | Time expires | Unresolved | No retrospective alert; next qualifying occurrence follows rules |
| Snoozed | Unsnooze | Unresolved | No alert by default |
| Any state | Ignore | Ignored; occurrences keep counting | Pending occurrence alerts cancelled |
| Resolved or ignored | Reopen | Unresolved | No alert by default |

Resolve is offered for unresolved errors. A snoozed error can be unsnoozed and then resolved. Repeated identical actions are idempotent. Invalid or stale transitions produce a recoverable conflict, not a silent overwrite.

Snooze options are 1 hour, 24 hours, 7 days, and a future date/time, interpreted in the displayed timezone and stored in UTC. Snoozing until a release or occurrence count is deferred. Expiry is enforced both by maintenance and by ingestion so a delayed scheduler cannot extend suppression indefinitely.

Manual changes record actor, previous state, new state, and time. The UI explains that an already-running external send cannot be recalled. Local status changes never silently close a GitHub ticket in v1.

## Notes and optional assignments

Operators can add plain-text investigation notes up to 10,000 characters. Preserve line breaks and safely display links; raw HTML, attachments, mentions, and embedded media are excluded. Show author, creation time, and an edited indicator. Empty notes are rejected without losing typed content.

Authors can edit their own notes. Authors or administrators can delete a note after confirmation. Deletion removes its body and leaves an activity tombstone; the audit record does not duplicate deleted content. Concurrent edits return a conflict with a recovery path. Private note drafts are not placed in URLs, application logs, or alert payloads.

Notes remain with the error until explicit administrator data purge or removal of the installation's data. Optional note-added notifications contain the event and investigation link, not the note body. Notes do not become public because an error has a linked GitHub issue.

Assignments are disabled by default. A host adapter supplies eligible identities and display names without requiring a package-owned user table. Enabling assignments requires this provider and the relevant operator capability. Assign/unassign actions are audited and optional notification events. A removed or ineligible assignee is displayed as unavailable and can be cleared. Disabling assignments preserves existing references and hides assignment controls rather than silently deleting history.

## Alert rules and evaluation

Alerts are configured through the dashboard. Each rule has a name, enabled state, selected events, environment conditions, optional class/origin/tag filters, destinations, cooldown, and a readable summary. Administrators can duplicate, edit, disable, and delete rules. Deleting a rule removes its pending eligibility; a delivery shared with another matching rule is re-evaluated and only cancelled when no applicable rule remains. Completed history is retained.

Proposed events:

| Event | Default enabled | Semantics |
| --- | --- | --- |
| New error | Yes, in the starter rule | First newly captured group, excluding historical recovery and synthetic tests |
| Regression | Yes, in the starter rule | A newer occurrence automatically reopens a resolved error |
| Frequency threshold | No | First crossing of a configured count in a time window |
| Resolved or manually reopened | No | Explicit lifecycle activity |
| Ignored | No | Explicit ignore activity |
| Snoozed or unsnoozed | No | Explicit lifecycle activity; expiry is separately labeled |
| Note added | No | Metadata-only notice without the note body |
| Assigned or unassigned | No | Available only with assignments configured |

The starter rule targets production, new errors and regressions, and a 30-minute per-error/per-destination cooldown. It is not active until an administrator selects at least one destination and confirms it. A local test destination can be tested without enabling production-like automatic rules in local environments.

Threshold rules choose a positive count and a 5-minute, 15-minute, or 1-hour window. Use fixed UTC windows in v1, with the boundary explained in the editor. A crossing fires once per error/rule/window; remaining above the threshold does not repeatedly fire. Counts include unique captured occurrences, not duplicate replays. Historical reconciliation does not trigger threshold alerts.

Cooldown and arbitration requirements:

- Matching rules targeting the same destination must not duplicate the same logical alert. Record all matching rule references on the resulting delivery.
- New-error/regression events take priority over a simultaneous threshold event for the same error and destination.
- An eligible regression during cooldown remains pending until eligible. Ordinary repeats are not converted into additional deferred alerts.
- Before sending, recheck status, snooze, destination availability, environment policy, rule applicability, and whether the event was superseded.
- Resolve, ignore, and snooze cancel pending automatic occurrence alerts: new errors, regressions, and thresholds. Manual reopen does not resurrect cancelled alerts.
- Editing a rule or destination revalidates affected pending intent. A newly added recipient does not receive historical events automatically.

Activity notifications have event-specific eligibility: a resolved notification remains meaningful while the error is resolved and must not be cancelled by the occurrence-alert suppression policy. Activity events are deduplicated by their action identity; the editor states the cooldown scope for each event family.

The rule editor offers a payload preview and a test against selected example data. Previewing does not send. A test send clearly identifies its destination, includes a test label, and appears in delivery history. No “every occurrence” option is included in v1.

## Destinations and notification content

Email is delivered through the host mailer. Slack uses configured incoming webhook connections. Generic webhooks are independent destinations with a signed event contract. A rule can use more than one destination; email deliveries are tracked separately for each recipient.

Default external content is application name, environment, event type, exception class, short sanitized message, file/line, occurrence time, occurrence count when relevant, and a dashboard link. Do not include request bodies, user identities, stack traces, query text, arbitrary context, notes, or secret-bearing headers. Test messages use synthetic content.

A destination's configured state, last successful delivery, last test result, and current actionable error are distinct fields. A successful test confirms that test only; it does not prove that every future automatic delivery will succeed.

## GitHub Issues integration

Proposed v1 authentication is a repository-scoped fine-grained token stored encrypted, or supplied by deployment configuration. GitHub App installation authentication is deferred to a follow-up release. The connection records a display name, owner/repository, optional existing labels, and an enabled state. Creating GitHub issues with a fine-grained token requires repository Issues write permission. [GitHub issue endpoints](https://docs.github.com/en/rest/issues/issues#create-an-issue)

Connection setup validates the repository and available access without creating a ticket. The UI explains that read access checks cannot conclusively prove every write operation will succeed. Tokens are replaceable, never redisplayed, and omitted from application logs and error messages.

Manual export flow:

1. An operator selects Create GitHub issue from an error detail page.
2. A review panel shows the target repository, editable title, selected labels, and the exact sanitized body to be exported. The generated body contains summary metadata and a local investigation link, without retained context or notes.
3. The operator confirms creation. The UI shows a durable pending action and disables duplicate creation for that error/repository while it is pending.
4. Success saves the issue number and validated GitHub link. Repeated local clicks return the existing association.
5. The panel offers Open in GitHub and Link existing issue when appropriate. Linking validates a repository issue number or canonical URL and rejects pull requests or unrelated repositories.

An error has at most one linked ticket per configured repository in v1. Multiple local errors may link to the same GitHub issue when an operator deliberately chooses it. Unlinking removes only the local association after confirmation; it never deletes or closes the external issue. Link and unlink actions are audited.

Automatic creation is opt-in per connection and limited to new-error events in explicitly selected environments. A regression reuses an existing association and may notify through alert destinations; it does not create another GitHub ticket. Automatic creation does not export historical reconciliation or synthetic tests.

The last-known ticket state includes a checked-at timestamp. An authorized explicit refresh can update it. “Unavailable” must not be presented as “closed.” v1 does not automatically propagate local resolution to GitHub, resolve the local error when a GitHub ticket closes, reopen a ticket, or post recurring comments.

Creation has a special failure state: GitHub may have accepted a request before its response was lost. A timeout, expired creation lease, or otherwise ambiguous result becomes Needs review instead of blindly creating another issue. Recovery offers Check repository, Link existing issue, or an explicit confirmation that no ticket was created before another creation attempt. Ordinary provider rejections and rate limits follow their classified retry policy.

## Slack integration

An administrator adds a named connection and a Slack incoming webhook URL, stored as a secret. Multiple connections allow separate destinations for different alert rules. An incoming webhook posts JSON to its configured Slack destination; its URL is a credential. [Slack incoming webhooks](https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/)

Setup shows instructions, an encrypted credential field, a display label, and a test action. A human-readable channel label is administrator-supplied and must not be presented as a verified Slack channel identity unless independently verified. The webhook determines the destination; changing a text label does not move messages.

Messages show the event, application/environment, short exception summary, available count/time, and an authenticated-dashboard link. Imported exception text must not trigger unintended mentions or Slack formatting. Resolve/ignore buttons inside Slack and Slack OAuth installation are deferred.

Respect provider throttling and Retry-After instructions, and serialize or throttle sends to a destination as required. Rate-limited deliveries remain visible with a next attempt time. [Slack rate limits](https://docs.slack.dev/apis/web-api/rate-limits/)

## Outbound webhook integration

An administrator configures a destination name, HTTPS endpoint, selected events, and a signing secret. The UI supports secret rotation and a test send. Userinfo URLs, fragments, and arbitrary redirect following are prohibited. Private-network destinations require an explicit deployment allowlist; the application must also protect against redirect and DNS-based destination changes. The UI cannot override deployment network policy.

The v1 payload contract includes schema version `1`, stable event ID, stable logical delivery ID, event type, event time in UTC, application installation identifier/name, environment, error identifier, sanitized summary, and authenticated-dashboard URL. The same logical delivery ID is retained across retries so a receiver can deduplicate it. Bodies are UTF-8 JSON; retries preserve the logical event body, including its original event time.

Proposed versioned event names are `error.created`, `error.regressed`, `error.threshold_reached`, `error.resolved`, `error.reopened`, `error.ignored`, `error.snoozed`, `error.unsnoozed`, `error.snooze_expired`, `note.created`, and `assignment.changed`. Test messages use `monitor.test`. Delivery failures never recursively emit another delivery-failure webhook in v1.

| Header | Contract |
| --- | --- |
| Content-Type | `application/json` |
| X-Telescope-Monitor-Event | Versioned event name |
| X-Telescope-Monitor-Delivery | Stable logical delivery identifier |
| X-Telescope-Monitor-Timestamp | UTC Unix timestamp in seconds for this attempt |
| X-Telescope-Monitor-Key-Id | Identifier for the signing key; never the secret |
| X-Telescope-Monitor-Signature | `sha256=` followed by the lowercase hexadecimal HMAC SHA-256 |

The canonical signing input is the ASCII timestamp, a literal period, and the exact transmitted body bytes. Receivers reject timestamps more than five minutes from their clock, compute the expected HMAC using the identified shared secret, compare signatures in constant time, and deduplicate successful processing by delivery ID. Retries use a fresh attempt timestamp/signature and retain the original event identity. Receiver clock synchronization and a verification example are part of the integration documentation.

Secret rotation stages a new key identifier and secret, allows an explicitly labeled test with that key, and activates it after administrator confirmation. Newly generated secrets are shown once for receiver setup and cannot be retrieved afterward. The receiver may accept the previous key for a documented 24-hour transition; the sender signs new attempts with the active key. An in-flight request can still carry the previous key. The UI shows the transition deadline and does not silently activate an unreviewed staged key.

The delivery detail screen provides a redacted payload preview, attempt times, HTTP status, next retry, and a bounded sanitized failure reason. It never displays authentication headers or arbitrary response bodies. Sending is asynchronous; a slow destination cannot delay the original application request.

## Delivery history and reliability

The product persists delivery intent before dispatching a job. Jobs contain identifiers, not credentials, live Throwables, or entire request snapshots. Claims and expiring leases coordinate workers. A scheduled sweep recovers pending intent after broker or worker failures.

| Delivery state | User-facing meaning | Available action |
| --- | --- | --- |
| Pending | Waiting for queue, eligibility, or cooldown | Inspect reason |
| Processing | A worker currently owns the attempt | Inspect start/lease time |
| Retrying | A classified transient failure has a scheduled retry | Inspect next attempt |
| Sent | Transport accepted the operation | Inspect metadata |
| Cancelled | Disabled, superseded, no longer applicable, or expired | Inspect reason |
| Failed | Permanent rejection or exhausted retry budget | Correct configuration and explicitly retry if still applicable |
| Needs review | An external side effect may have occurred | Verify/link before an explicit retry |

“Sent” is transport acceptance, not proof that an email reached an inbox or a human read a message. Email, Slack, and generic webhooks have at-least-once delivery semantics where acceptance and worker persistence cannot be atomic. Absolute duplicate-free external delivery is not promised.

Proposed defaults are five actual delivery attempts with 1-minute, 5-minute, 15-minute, and 1-hour backoff. Provider Retry-After/reset values may require a later time and must not be shortened. Authentication/validation failures stop until corrected. GitHub creation ambiguity uses Needs review. Pending automatic notifications expire after 24 hours with a visible reason rather than sending obsolete incident messages indefinitely; an administrator may impose a shorter hard limit.

Cooldown does not consume delivery attempts. Disabling/deleting a rule removes that rule's eligibility; unsent automatic intent is cancelled when no remaining rule applies. Disconnecting a destination cancels its unsent work. Secret replacement for the same destination can unblock a retry after review; changing its repository or endpoint creates a new destination identity and must not redirect old pending work silently.

Delivery history filters include channel, rule, status, test/real, environment, error, and time. Detail shows the rule references, redacted destination, event, payload preview, attempts, and cancellation/failure reason. Manual retries are authorized, audited, revalidate current policy, and preserve logical identity where appropriate. External requests already in flight cannot be recalled.

## Settings ownership and persistence

Operational preferences are database-backed and editable in the dashboard. Infrastructure settings remain deployment configuration. Secret fields can be entered through the dashboard only when deployment policy allows it.

Effective precedence is: deployment-enforced value or hard limit, then an authorized saved database value, then the shipped default. A form shows the effective value and whether it is editable or locked. A disabled control includes the deployment setting responsible for the lock, without revealing secret values.

| Group | Dashboard controls | Proposed defaults and constraints |
| --- | --- | --- |
| General | Display name, canonical dashboard URL, display timezone, optional repository/release mapping | Name and URL seeded from host; timezone initially matches host; validated links |
| Capture | Package exclusions, allowed context categories, allowlisted tags, bounded event limits | Environment never changed from UI; host watcher/filter policy remains deployment-owned |
| Privacy | Redaction patterns, context allowlists, pseudonymous-user policy, redaction and export previews | Sensitive payload categories excluded; user impact disabled |
| Alerts | Rules, recipients, destinations, events, cooldowns, thresholds | Starter rule is production + new/regression + 30 minutes; inactive until configured |
| Integrations | Named connections, credentials, repository/endpoint, tests, enable/disable/disconnect | All external integrations start disconnected; automatic GitHub creation off |
| Retention | Evidence and history retention within deployment limits; next prune and preview | Defaults specified in the retention section |
| Access | Effective capability summary and host configuration guidance | Authorization mapping is host-managed; UI cannot grant itself access |
| Assignments | Effective provider status and eligible-identity availability | Disabled unless a host provider and permissions exist |
| System health | Checks, observations, backlog, maintenance results, remedies | Configuration checks distinguished from live evidence |

Every form has Save, validation errors adjacent to fields, clear success feedback, and protection against losing unsaved changes. Concurrent edits require a conflict response and reload/compare path. Invalid saves leave the last valid configuration active. Configuration changes take effect predictably for web and long-running workers; cache invalidation and credential rotation must be covered by integration tests.

Secret replacement uses an empty password-style field meaning “leave unchanged,” with explicit Replace and Remove actions. A masked field must never submit its mask as a new credential. Encryption uses the host key or a documented host secret provider. Decryption failure is an actionable configuration error; the package never discards a credential silently. Key rotation requires a documented re-encryption/reconnection procedure.

The dashboard does not edit .env, run arbitrary Artisan commands, change database drivers, provision mail providers, or automatically enable host Telescope watchers. Installation and health screens provide exact deployment guidance for these operations.

## Retention and deletion

| Record | Proposed default | Required preservation |
| --- | --- | --- |
| Occurrence snapshot and related context | 30 days | Clear the sensitive evidence without inflating counts on replay |
| Five-minute error trend aggregates | 48 hours | Preserve accurate recent buckets independently of snapshots |
| Hourly error trend aggregates | 90 days | Event-time counts remain correct after snapshot expiry |
| Completed delivery and attempt history | 30 days after completion | Linked GitHub associations are independent and remain |
| Security/settings audit and issue activity | 90 days | Do not duplicate secrets, note bodies, or full payloads in audit records |
| Error summary and lifecycle state | Until explicit administrator purge | Lifetime count, first/last seen, and sanitized summary retained |
| Notes, saved views, and GitHub links | Until explicit authorized removal or administrator purge | Independent of Telescope pruning |
| Minimal occurrence identity | Until explicit administrator purge | UUID identity prevents replay from counting an expired snapshot again |

Evidence expiry may remove detailed origin/user/release insights even while lifetime counts remain. The UI states the relevant coverage window. Reducing retention shows an estimate and the next pruning time before saving; the estimate can be unavailable and must never be invented. Increasing retention does not recover deleted evidence.

Pruning is independent of Telescope and operates in bounded batches. Pending work is handled by expiry/cancellation policy rather than deletion of its evidence while active.

Administrative purge is a deployment command requiring explicit confirmation of the installation identifier, not a dashboard Delete button. Its v1 scope is a whole-installation reset of error evidence, summaries, counts, notes, activity, linked-issue associations, aggregates, and delivery history. It cancels and invalidates pending work before deletion. It preserves configuration, connection credentials, saved-view definitions, installation identity, a minimal purge record, and an ingestion cutoff that prevents old Telescope source entries from being reconciled back into the product. New events after the cutoff can create new groups. External GitHub issues and already-delivered messages remain untouched. Selected-error or user-specific privacy erasure workflows require separate design and are deferred; ordinary retention is not a substitute for them.

## System health and operational flows

System health reports setup status, Telescope enabled/paused state where available, exception watcher configuration, effective capture policy, storage/migration status, last successful capture/ingestion, queue configuration and processing observations, scheduler/recovery/pruning heartbeats, pending/failed delivery counts, expired leases, and integration connection status.

Each row displays Passed, Warning, Failed, or Unverified, an observed-at time, and a specific remedy. A configured asynchronous queue is not proof that a worker is running. A recent scheduled heartbeat is not proof that every scheduled action succeeded. Missing heartbeats explain what could be unconfigured or delayed.

Administrators can request a bounded capture/queue self-test and inspect its result. A queue test must not expose credentials or send real alert messages. Tests have explicit timeouts and remain identifiable in history.

The package shares failure domains with its host database, application, and queue. If those services are unavailable, the dashboard or its alerts may also be unavailable. Independently detecting a completely offline application or worker requires an external observer and remains outside v1.

## Required UI journeys

| Journey | Required screen sequence | Completion evidence |
| --- | --- | --- |
| First install | Authorized entry → setup checks → privacy → capture test → destination/rule → delivery test → overview | A synthetic captured error and recorded test result |
| Investigate an alert | Authenticated deep link → error → selected occurrence → context/timeline | Stable occurrence selection and usable retained evidence |
| Triage an error | Inbox → error → resolve/ignore/snooze → activity | State and pending-alert behavior reflect the action |
| Handle a regression | Resolved error → newer capture → alert → error detail | Reopened status, regression activity, correct destination intent |
| Record investigation | Error → notes → add/edit/delete → activity | Correct authorship, safe rendering, body removal on delete |
| Save a workflow | Inbox filters → Save view → visibility → saved view → modify/update/copy | Definition persists and privacy is enforced |
| Assign optionally | Error → assignee picker → confirm → activity | Provider-validated identity and reversible assignment |
| Configure email | Integrations → Email → mailer checks → recipients → test | Actual test acceptance or actionable failure |
| Configure Slack | Integrations → add Slack → credential/label → test → rule selection | Secret protected and destination test recorded |
| Create a GitHub ticket | Error → export preview → confirm → pending → linked ticket | One association or explicit recoverable failure |
| Recover ambiguous creation | Needs review → inspect repository → link existing or confirm retry | No blind duplicate ticket creation |
| Connect a webhook | Integrations → destination/security → preview → test → rule selection | Valid signed test with stable logical identity |
| Recover failed notification | Alerts → delivery detail → fix configuration → retry | Audited attempt, current-policy validation, visible result |
| Change retention | Settings → retention estimate → save → scheduled prune → health | Expired evidence removed; summaries/counts remain correct |

Each journey must include loading, validation, permission, empty, unavailable, and failure states where applicable. Navigation and selected filters must survive an authentication round trip without allowing open redirects.

## Acceptance requirements

These identifiers are the minimum product-level acceptance matrix for implementation and release review.

| ID | Required observable result |
| --- | --- |
| SETUP-01 | A fresh supported host can install and complete setup without a consumer asset build |
| SETUP-02 | Repeated installation preserves consumer configuration and does not duplicate migrations |
| AUTH-01 | Read, operator, and administrator capabilities are enforced on every relevant server action |
| AUTH-02 | Private views, secrets, detailed operational failures, and assignment lookups cannot bypass access controls |
| CAP-01 | Real HTTP, queue, and command exceptions accepted by Telescope create correct errors and occurrences |
| CAP-02 | Disabled capture, excluded entries, batch filtering, and Laravel reportability are respected |
| CAP-03 | Concurrent ingestion and duplicate replay preserve one group, correct counts, and one logical alert intent |
| CAP-04 | Historical replay and out-of-order events preserve timestamps and do not create false regressions or retroactive alerts |
| DATA-01 | Privacy categories, bounds, redaction, and truncation indicators apply before package persistence and export |
| DATA-02 | Retained evidence remains usable after Telescope pruning; expired and missing context have accurate UI states |
| DATA-03 | Current deployed source is never misrepresented as historical release source |
| LIFE-01 | Resolve, reopen, ignore, snooze, expiry, and stale actions follow the lifecycle table |
| NOTE-01 | Notes enforce authorship, safe display, edit conflicts, deletion, and metadata-only external notifications |
| VIEW-01 | Personal/shared views persist versioned criteria and enforce owner, publication, and moderation permissions |
| ASSIGN-01 | Assignment controls are absent when unconfigured and validate identities/capabilities when enabled |
| ALERT-01 | New, regression, threshold, and opted-in activity events match rules without duplicate overlapping sends |
| ALERT-02 | Cooldown, cancellation, snooze, configuration changes, expiry, and retries recheck current applicability |
| ALERT-03 | Provider failures, rate limits, lost queue dispatch, expired worker leases, and manual retries produce bounded visible recovery |
| GITHUB-01 | Creation/linking stores one valid association, preserves previewed content policy, and rejects unrelated repositories/PRs |
| GITHUB-02 | Ambiguous creation enters Needs review; routine repeats/regressions do not create duplicate tickets |
| SLACK-01 | Destination tests and alerts protect the webhook secret, escape exception content, and honor throttling |
| WEBHOOK-01 | Signatures, timestamps, rotation, stable IDs, destination restrictions, and replay/deduplication guidance are verified |
| CONFIG-01 | Editable values, deployment locks, concurrent saves, credential replacement, and worker refresh behavior are consistent |
| RETAIN-01 | Pruning removes expired evidence while retaining replay identity, lifetime totals, notes, views, and links |
| RETAIN-02 | An explicitly confirmed installation purge invalidates pending work and prevents old source entries from restoring deleted errors |
| HEALTH-01 | Checks distinguish configured, observed, stale, failed, and unverified conditions without false health claims |
| UI-01 | All specified journeys work on desktop/tablet/phone and through keyboard navigation, including large/long data |
| SAFE-01 | Package capture, mail, integration, command, and test failures do not replace host errors or create recursive monitoring loops |

Database concurrency behavior must be tested on every database engine claimed as supported; SQLite-only results are insufficient. Provider contracts require controlled endpoint tests as well as HTTP fakes. Release testing must cover a fresh application, repeat installation, an upgrade/migration fixture, real queues, scheduler recovery, and source pruning. Live external test sends occur only to explicitly configured test destinations.

## Planning and implementation gates

1. Product review: agree this scope, proposed defaults, permissions, and deferred features.
2. Flow review: approve wireframes for setup, overview, inbox, detail, notes/views, alert rules/history, connections, and settings, including failure states.
3. Visual review: approve representative desktop/mobile mockups and reusable UI states. Existing prototype styling does not constrain the replacement.
4. Architecture review: select the UI stack, package interfaces, storage model, integration contracts, capture limits, and compatibility matrix. Preserve the no-consumer-build installation requirement.
5. Implementation: build vertical journeys with their tests and UI together. Setup, settings, delivery history, and health are release features rather than optional finishing work.
6. Release verification: satisfy the acceptance matrix and complete fresh-install/upgrade checks before publishing under a final vendor/name.

The existing source is not deleted or refactored during specification work. Before a fresh implementation, preserve it as a prototype snapshot and explicitly choose what, if anything, is reused. Reuse must serve the approved product model rather than carrying forward the old scope.

## Decisions to confirm during review

| Decision | Proposed starting point |
| --- | --- |
| Product and Composer identity | Keep Telescope Monitor as a working name until naming review |
| UI implementation stack | Decide after approved wireframes; ship compiled assets and no consumer Node requirement |
| GitHub authentication | Fine-grained token first; GitHub App authentication later |
| Slack authentication | Incoming webhook connections first; OAuth and interactive Slack actions later |
| Automatic ticket creation | Available but off by default; new-error events only |
| Grouping and snooze | Explicit file/line fingerprint v1 and timed snooze; advanced regrouping/release-based snooze later |
| Initial data limits and retention | Bounded defaults in this document; tune through production-volume testing before release |
| Host identities | Standard authenticated identifier plus optional display/assignment adapters; no package-owned users |

These decisions do not reopen the confirmed deployment model, error-first release scope, notes/saved views, optional assignments, or the four selected integrations.
