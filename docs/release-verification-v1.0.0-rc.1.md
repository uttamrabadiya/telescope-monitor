# Release verification — 1.0.0-rc.1

Updated 13 November 2026. Package name: **telescope-monitor/telescope-monitor**.
This report supersedes the 11 November local review candidate. The public
repository and Packagist registration now exist. The release target is an RC for
isolated evaluation; stable 1.0.0 qualification remains pending the gates below.

## Reassessment of the original blockers

| Original gate | Current disposition |
| --- | --- |
| Failed Artisan origin with exception-only filtering | Fixed. Match the exact input of the live Laravel kernel exception-reporting call after CommandFinished. Nested commands restore the outer context; later unrelated reports cannot inherit a finished command. OriginsTest reproduces both failures before the fix and verifies the corrected signed/imported evidence. |
| MySQL 8.4 / MariaDB 11.4 races | The latest pre-fix GitHub run failed four MariaDB checks; MySQL was cancelled by matrix fail-fast. Fixed stale repeatable-read snapshots in settings, metric counters and duplicate action receipts/occurrences. Local MariaDB 11.7.2: all 14 existing engine concurrency tests pass (114 assertions). Maintained-engine CI must pass on the release commit. |
| Secure minimum dependencies | Laravel floor raised from 13.0 to the tested 13.42.0. Telescope floor stays 5.27.0. Current locked Composer and npm audits report zero advisories. The custom formatter requires PHP CS Fixer 3.95.30+, now reflected in its development constraint. Lowest/latest dependency jobs added; these must pass before tagging. |
| Process-death recovery | Existing expired-lease, uncertain acceptance and GitHub commit-loss tests retained. Added SIGKILL checks before claim, during send and after synthetic provider acceptance. These exercise committed database claims and recovery with a clock-controlled lease expiry. They do not certify real SMTP/GitHub receipt or every database/Redis worker crash permutation. |
| UI failure/conflict/keyboard/accessibility coverage | Still a stable-release qualification gate. Existing screenshots and interactions are historical evidence, not a new complete accessibility certification. |
| Representative query profiles | No production throughput or latency claim. Engine profiling remains required before making such claims. |
| Name, licenses, archive and release documentation | Name finalized; MIT and bundled third-party licenses retained. README uses Packagist RC installation. Composer/Git archive inclusion rules aligned to ship compiled assets and linked consumer guides. Git archive verification and archive parity are release CI gates. |

## Current local checks

- PHP 8.3.17 / Laravel 13.42.0 / Telescope 5.27.0 / SQLite: 286 tests,
  1,639 assertions, 14 engine-only skips, zero failures before the new SIGKILL cases.
- Filtered command and host query isolation regressions: 12 tests, 48 assertions.
- Larastan level 5 passes; PHP CS Fixer passes on PHP 8.3.
- Vue/TypeScript production build passes and reproduces committed assets.
- Five frontend tests pass.
- Six new SIGKILL service-boundary tests pass on MariaDB 11.7.2 (48 assertions).
- Fresh Laravel 13.42.0 / Telescope 5.28.0 host installs from the Git distribution
  with `--no-dev`, discovers the provider, migrates 25 package tables and tolerates
  repeat installation. A real failed Artisan command under exception-only filtering
  is stored once, imported once and retains `command` origin.
- Git/Composer distribution manifests match exactly (254 files).
- Strict Composer validation and live locked dependency audits are required again
  on the final release tree.

CI is authoritative for the final commit. Do not describe a prepared workflow as
executed evidence. Record its run URL and results below after completion.

## Release CI

Pending execution on the prepared release commit. The workflow runs:

- PHP 8.3, 8.4 and 8.5 against the locked dependencies on SQLite;
- complete tests, including fork races and SIGKILL recovery, on MySQL 8.4 and
  MariaDB 11.4, with matrix fail-fast disabled;
- secure lowest/latest dependency resolution on PHP 8.3;
- strict package validation, Git/Composer archive checks and identical file manifests.

## Scope and remaining stable-release gates

The RC supports evaluation on PHP 8.3–8.5, Laravel 13.42+ and Telescope 5.27+
within their declared major versions. Database compatibility claims are limited
to the passing CI combinations. Keep dependencies patched. Composer ranges are
not a certificate for every older transitive dependency combination.

Before stable 1.0.0:

1. Complete long-data, conflict and nested error UI journeys with full keyboard,
   zoom, screen-reader and supported-browser evidence.
2. Complete database/Redis queue worker crash permutations, including GitHub
   creation and recovery around real transport boundaries. Service-boundary fake
   acceptance is deliberately distinguished from live provider verification.
3. Refresh a clean production host install/upgrade and sustained importer,
   scheduler, pruning and recovery evidence for the final stable tree.
4. Review the RC feedback and close any discovered defects. Profile maintained
   engines before making workload or latency claims.

There is no PostgreSQL, Octane/Swoole/RoadRunner, alternative queue driver,
physical-device or production SQLite throughput certification. Notifications
remain at-least-once; receivers must deduplicate webhook IDs. An uncertain GitHub
create goes to Needs review and must never be blindly recreated.

## Reproduction

Run `composer install`, `npm ci`, `composer test`, `composer analyse`,
`composer format:check`, `npm run test:unit`, `npm run build`,
`composer validate --strict`, `composer audit --locked` and `npm audit`.
Engine tests require a **disposable** `telescope_monitor_test` database:
`MONITOR_DB=mysql DB_PORT=<isolated-port> vendor/bin/phpunit`.
Fork tests require pcntl and the MySQL PDO driver; SIGKILL checks also require posix.

After committing the reviewed tree, build both distributions into new empty paths:

```bash
python3 scripts/prepare-release.py --version 1.0.0-rc.1 --output /tmp/tm-composer-rc1
python3 scripts/prepare-release.py --version 1.0.0-rc.1 --git-ref HEAD --output /tmp/tm-git-rc1
diff /tmp/tm-composer-rc1/FILES.sha256 /tmp/tm-git-rc1/FILES.sha256
```

The helper validates consumer member bytes, required guides, third-party licenses
and manifest-listed assets. It never tags, uploads or publishes. Consumer archives
exclude development fixtures, local review bundles, dependency locks and tooling.
Use only synthetic data in tests and public evidence, as required by CONTRIBUTING.md.
