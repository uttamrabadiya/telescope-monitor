# 1.0.0-rc.2

Release candidate of **telescope-monitor/telescope-monitor**, prepared on
13 November 2026 for isolated evaluation. Requires PHP
8.3+, Laravel 13.42+ and Telescope 5.27+ within the declared major versions.

## Added

- Operator-only Reproduce panel with copyable cURL and Artisan templates. Capture
  defaults to redacted values; exact routes/commands can opt into selected values
  or full supported payloads. Credentials remain excluded. Replay metadata is
  encrypted before Telescope storage, signed and restricted again on import/read.
  Host exception capture continues to issue no package database queries.
- GitHub repository discovery using a personal access token or deployment-managed
  credential reference, with bounded pagination and manual repository fallback.
- GitHub issue previews/exports with counts, first/last seen times, status, permitted
  release/execution context and bounded application stack frames. Optional context
  and stack export have independent deployment settings.
- Slack Block Kit notifications with event headings, source location, captured
  counts, application-timezone timestamps and occurrence-specific investigation
  links. Permitted execution/release context freezes at the first delivery attempt;
  current privacy restrictions are checked again on retries.
- Dashboard light/dark styling follows Telescope's `Telescope::night()` flag,
  including charts, forms, setup and investigation views.
- Scoped `QueuedTelescopeAuthorization::isWorkerContext()` helper for hosts whose
  Telescope authorization explicitly permits trusted monitoring workers.

## Fixed

- Reconnect disconnected integrations while preserving canonical connection
  identity/history and leaving cancelled work cancelled.
- Bound HTTP response buffering and reject oversized responses without returning
  partial JSON; report safe repository-discovery timeout/TLS/connectivity errors.
- Preserve parsed command invocation inputs for replay after exception reporting,
  including nested commands, boolean switches and safely quoted positional values.

## Upgrade from rc.1

No schema changes or new migrations are introduced; schema version 6 and its 25
package tables remain unchanged. Follow normal package migration/deployment steps.
Merge the new root `reproduce` configuration and `integrations.github` options into
any published configuration. Replay is enabled with redacted values by default.
See the [reproduce guide](reproduce.md) before opting into retained values.

Refresh cached configuration and restart host capture workers/Octane, the importer
and monitoring queue workers together. Older workers do not understand the new
optional replay evidence field. Capture fresh exceptions to verify the deployment;
older snapshots are not enriched retroactively. Deploy the new package before
adding a host authorization policy that calls the new worker-context helper.

Compiled dashboard assets and third-party licenses ship with the package; consumers
need no Node build. See the [configuration reference](configuration-reference.md)
for GitHub export controls, worker-policy examples and theme setup.

## Evaluation limits

Replay templates never execute automatically, cannot reconstruct database or
external-service state, and require review before use. Files, multipart/binary/XML
bodies and job payloads are not serialized; shell output targets POSIX shells.
Replay data is excluded from notification and GitHub exports.

This remains an RC. The GitHub release records CI evidence for the release
commit. Clean-host qualification, stable accessibility, supported-browser and
queue crash qualification remain open. See the [verification report](release-verification-v1.0.0-rc.2.md).
