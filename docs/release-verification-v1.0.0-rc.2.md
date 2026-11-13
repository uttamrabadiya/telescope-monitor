# Release verification — 1.0.0-rc.2

Local verification recorded 13 November 2026 before the release commit. Package:
**telescope-monitor/telescope-monitor**. The GitHub release links CI evidence for
the release commit; the local results below are a preparation-time snapshot.
Prior RC evidence is historical and does not certify this tree. Stable 1.0.0
qualification remains pending.

## Local checks

- PHP 8.5.10 / Laravel 13.42.0 / Telescope 5.27.0 / SQLite: 320 tests,
  1,915 assertions, 20 engine-specific skips, zero failures.
- Frontend: all 9 unit tests pass, including literal POSIX quoting, JSON shape,
  command switches and unavailable/restricted replay states.
- `npm run build`: TypeScript and Vite production build pass on Node 24.12.0;
  generated asset names match the supplied local assets.
- PHPStan/Larastan passes with `vendor/bin/phpstan analyse --memory-limit=512M
  --debug`. Debug mode avoids a sandbox-restricted parallel-worker socket.
- `composer format:check -- --sequential` passes (0 files need changes).
  Sequential mode avoids the same sandbox socket restriction.
- Strict Composer metadata validation passes.
- Live `composer audit --locked` and `npm audit` report zero advisories.
  Registry access required running outside the network-restricted sandbox.

The tests cover replay capture/privacy/access/query isolation, Slack presentation
and retry privacy, GitHub export/discovery, bounded response buffering, integration
reconnection and worker authorization. These are synthetic local checks; no live
provider receipt, full browser/accessibility review or maintained-engine run was
performed during this release preparation.

## Distribution preparation

The Composer and Git export allowlists include the current release notes/report,
reproduce guide and synthetic Slack example linked from the README. The archive
helper requires release documents matching its `--version` argument and validates
consumer bytes, compiled assets and bundled licenses. The CI archive jobs target
`1.0.0-rc.2`. Local review bundles under `releases/` remain excluded from consumers
and Git tracking. Composer obtains package versions from release tags; no static
`version` field is added to composer.json.

A local Composer review archive was built successfully with 264 consumer files.
All member bytes, required guides, licenses and manifest-listed assets passed the
helper checks. Archive and per-file SHA-256 manifests accompany it.

## Release verification procedure

1. Review and commit the complete tree, including rebuilt `dist/` assets.
2. Run the release workflow for that exact commit: PHP 8.3/8.4/8.5 SQLite,
   MySQL 8.4, MariaDB 11.4, lowest/latest dependency resolution and audits.
3. Build Composer and Git archives from the committed tree and compare manifests:

   ```bash
   python3 scripts/prepare-release.py --version 1.0.0-rc.2 --output /tmp/tm-composer-rc2
   python3 scripts/prepare-release.py --version 1.0.0-rc.2 --git-ref HEAD --output /tmp/tm-git-rc2
   diff /tmp/tm-composer-rc2/FILES.sha256 /tmp/tm-git-rc2/FILES.sha256
   ```

4. For stable qualification, verify a clean disposable host install/upgrade, configuration merge, worker
   restart and new HTTP/Artisan captures. Check operator/viewer access and actual
   GitHub/Slack behavior using synthetic data.
5. After passing checks, create/push `v1.0.0-rc.2` and publish the GitHub release
   with these release notes and a link to successful CI for the release commit/tag.
   Verify Packagist exposes the new version.

The local working-tree Composer archive is a review artifact, not proof of Git
archive parity. Do not archive HEAD as rc.2 until these changes are committed.

## Stable-release gates

Carry forward rc.1's open gates: keyboard/zoom/screen-reader and browser coverage,
long-data/conflict/error journeys, database/Redis worker crash permutations at real
transport boundaries, clean production host upgrade and sustained importer,
scheduler/pruning/recovery qualification. Profile maintained engines before making
throughput or latency claims. No PostgreSQL, Octane/Swoole/RoadRunner, alternative
queue driver, physical-device or production SQLite throughput certification is
claimed. Notifications remain at-least-once and webhook receivers must deduplicate;
uncertain GitHub creation requires review.
