# Release verification — 1.0.0-rc.3

Prepared 13 November 2026 for **telescope-monitor/telescope-monitor**. This is a release candidate, not stable 1.0.0 qualification.

## Functional commit evidence

Functional commit `e140f2054337ab37c58b9f2b3fc6a5a5b4c81d54` passed all eight jobs in [GitHub Actions run 43448330267](https://github.com/uttamrabadiya/telescope-monitor/actions/runs/43448330267): PHP 8.3/8.4/8.5 SQLite, MySQL 8.4, MariaDB 11.4, lowest/latest dependencies and package archive parity. This executed evidence supersedes the earlier unverified maintained-engine gate for that commit. Final release metadata is verified on its own commit; the GitHub release links that exact successful run before publication.

## Local preparation checks

- PHP 8.5.10 / locked dependencies / SQLite: 322 tests, 1,945 assertions, 20 engine-specific skips, zero failures/errors.
- All 9 frontend tests pass.
- PHPStan/Larastan, sequential PHP formatting and strict Composer metadata validation pass.
- TypeScript/Vite production build passes; generated assets remain unchanged.
- Git and Composer archives are checked for matching consumer bytes, compiled assets, required documentation and bundled licenses using `scripts/prepare-release.py`.

Tests verify the global replay mode and selected-fields fallback, exact route/command overrides, invalid-mode fallback, mandatory credential/sensitive-field exclusions, policy tightening, capture-time bounds and zero-query host capture.

## Final release procedure

Commit the reviewed release metadata, run the release workflow for that exact commit, then compare both distributions:

```bash
python3 scripts/prepare-release.py --version 1.0.0-rc.3 --output /tmp/tm-composer-rc3
python3 scripts/prepare-release.py --version 1.0.0-rc.3 --git-ref HEAD --output /tmp/tm-git-rc3
diff /tmp/tm-composer-rc3/FILES.sha256 /tmp/tm-git-rc3/FILES.sha256
```

Publish `v1.0.0-rc.3` as a GitHub prerelease only after final-commit CI and archive checks pass. Include the release notes, verification evidence and checksums, then verify Packagist exposes the tag. Never describe a queued workflow or a working-tree archive as final-commit evidence.

## Remaining stable qualification

Carry forward rc.2's open browser/keyboard/zoom/screen-reader and long-data/conflict/error journeys, live-provider acceptance checks, database/Redis crash permutations at real transport boundaries, clean production host upgrades and sustained importer/scheduler/pruning/recovery evidence. Profile representative maintained engines before making workload or latency claims. No PostgreSQL, Octane/Swoole/RoadRunner, physical-device or production SQLite throughput certification is claimed. Notifications remain at-least-once and webhook receivers must deduplicate; uncertain GitHub creation must not be blindly retried.
