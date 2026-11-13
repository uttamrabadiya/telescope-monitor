# 1.0.0-rc.3

Release candidate of **telescope-monitor/telescope-monitor**, prepared on 13 November 2026. Requires PHP 8.3+, Laravel 13.42+ and Telescope 5.27+ within the declared major versions.

## Added

- Global `reproduce.default_mode` for all captured routes and commands. Set it directly or use `TELESCOPE_MONITOR_REPRODUCE_MODE` through the published configuration entry.
- Global `reproduce.fields` fallback for selected-values capture; exact route/command policies override the defaults.
- Tests for global capture, exact policy precedence, invalid-mode fallback, credential exclusions, sensitive fields, stricter read-time restrictions and zero-query host capture.

## Upgrade from rc.2

No migrations, schema changes or new frontend build requirements for consumers. Merge these entries into the root `reproduce` array of an existing published configuration:

```php
'default_mode' => env('TELESCOPE_MONITOR_REPRODUCE_MODE', 'redacted'),
'fields' => [],
```

The shipped default remains redacted. To retain supported non-credential values globally, set `TELESCOPE_MONITOR_REPRODUCE_MODE=full-payload`. Exact route/command mode policies still take precedence. Credential and configured sensitive-field exclusions, privacy restrictions, operator-only access, encryption, watcher requirements and capture limits remain enforced. Headers remain independently allowlisted. Job payloads remain unsupported.

Refresh cached configuration and restart host workers/Octane, the importer and monitoring queue workers. Capture new exceptions to see newly permitted values; previously redacted snapshots cannot be reconstructed. See the [reproduce guide](reproduce.md).

## Verification and scope

The functional commit passed all eight release-verification CI jobs, including maintained MySQL/MariaDB combinations and dependency-resolution jobs. Local PHP, frontend, analysis, formatting, metadata and build checks pass. See the [verification report](release-verification-v1.0.0-rc.3.md); the GitHub release links successful CI for the final release commit.

This remains a prerelease. Stable qualification still requires broader browser/accessibility journeys, live-provider and queue crash qualification, clean-host upgrade and sustained operational evidence. Notification delivery remains at-least-once; uncertain GitHub creation requires review.
