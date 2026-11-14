# Release verification — 1.0.0-rc.4

Prepared 14 November 2026 for **telescope-monitor/telescope-monitor** from the
current working tree, based on `e55a778`. No source commit, release tag, upload,
publication or remote workflow dispatch was performed during preparation.
This is a release candidate, not stable 1.0.0 qualification.

## Executed candidate checks

| Check | Result |
| --- | --- |
| PHP 8.5.10 / locked dependencies / SQLite | 343 tests, 2,099 assertions, 20 engine-specific skips, zero failures/errors |
| Laravel 8.83.29 / Telescope 5.19.0 / PHP 8.3.17 / SQLite | 343 tests, 2,099 assertions, 20 skips, zero failures/errors |
| Laravel 9.52.23 / Telescope 5.19.0 / PHP 8.3.17 / SQLite | 343 tests, 2,099 assertions, 20 skips, zero failures/errors |
| Laravel 10.50.4 / Telescope 5.19.0 / PHP 8.3.17 / SQLite | 343 tests, 2,099 assertions, 20 skips, zero failures/errors |
| Laravel 11.57.1 / Telescope 5.19.0 / PHP 8.3.17 / SQLite | 343 tests, 2,099 assertions, 20 skips, zero failures/errors |
| Laravel 12.72.0 / Telescope 5.19.0 / PHP 8.3.17 / SQLite | 343 tests, 2,099 assertions, 20 skips, zero failures/errors |
| Laravel 13.42.0 / Telescope 5.19.0 / PHP 8.3.17 / SQLite | 343 tests, 2,099 assertions, 20 skips, zero failures/errors |
| Frontend | All 24 tests, TypeScript and Vite production build pass |
| Static analysis and style | Serial PHPStan/Larastan and the full sequential PHP formatting check pass |
| Composer metadata | Strict validation passes |
| Dependency audit | No advisories or abandoned packages in the locked development Composer set; npm audit reports zero vulnerabilities |
| Consumer archive installation | Candidate preflight ZIP installed with runtime dependencies only in a disposable Laravel 13.42.0 application |
| Fresh consumer setup | Installer, seven package migrations, 25 package tables, both report indexes, report view discovery and disabled-report scheduling checks pass |

The six framework runs used isolated existing dependency fixtures refreshed with
this candidate's source, tests, resources, routes, configuration and migrations;
all pinned Telescope exactly to 5.19.0. These are new candidate regression runs,
not the earlier 331-test compatibility results. They use SQLite; the historical
MySQL results in [framework compatibility](framework-compatibility.md) are not
claimed as new candidate database-engine evidence.

The fresh consumer used a local Composer package repository pointing to the ZIP,
with no package development dependencies, no path symlink and no consumer Node
build. It used a dedicated disposable SQLite database. The seven migration records
include the new index-only migration; the package's logical schema remains version
6 with 25 tables. Its dependency lock and fixture scripts are retained in the local
review bundle. A final sealed-ZIP smoke check is recorded beside that bundle.

The production build emits Vite's warning for a JavaScript chunk slightly above
500 kB before gzip. This is a build warning, not a failed check or a performance
qualification. No representative production workload claim is made.

## Scheduled report evidence

Twelve report tests cover defaults and version conflicts, authorization, allowed
recipients/environments, independent schedules, calendar windows, half-hour zones,
23/25-hour DST days, deduplication, suppression, resolution-only activity,
incomplete history, preview labeling/idempotency, destination/subscription changes,
HTML escaping, redaction and stricter export policy on frozen retries.

The actual daily and weekly Blade templates were rendered with synthetic data.
Browser checks covered the settings form on desktop/mobile, saving both schedules,
recipient-confirmed previews, and delivery history reaching transport acceptance
with the array mailer. Sample HTML captures include daily volume and paired weekly
comparison bars. No external mail was sent and no live inbox/client compatibility
is claimed. Evidence images and local check logs are in
`releases/1.0.0-rc.4/evidence/` in the development checkout, outside the consumer ZIP.

## Distribution checks

`scripts/prepare-release.py` verifies archive member safety, byte identity with
source, required release documentation, compiled asset references and bundled
licenses. The local review bundle contains the Composer ZIP, consumer-member
manifest, SHA-256 checksums and a local Composer repository definition.

A disposable Git repository snapshots the candidate working files to exercise
`.gitattributes` without staging or committing the user's checkout. Its Git
archive is compared with the Composer consumer manifest. This is working-snapshot
distribution parity, **not final release-commit evidence**. The bundle records the
snapshot identifier, base commit, archive digest and executed check results.
Development-only design notes, test caches, tests, workbench, dependencies and
release evidence are excluded from consumer distributions.

## Final publication procedure

1. Review the full pending source scope, commit the intended rc.4 changes and
   confirm a clean checkout. Rebuilt `dist/` assets and new source files belong
   in that commit; the ignored `releases/` review bundle does not.
2. Run the configured release workflow for that exact source commit, including
   maintained MySQL 8.4/MariaDB 11.4, PHP 8.3–8.5, all framework/Telescope jobs,
   dependency resolution and package parity. Do not substitute queued jobs or
   historical runs for successful final-commit results.
3. Rebuild and compare both distributions from that clean commit:

```bash
python3 scripts/prepare-release.py --version 1.0.0-rc.4 --output /tmp/tm-composer-rc4-final
python3 scripts/prepare-release.py --version 1.0.0-rc.4 --git-ref HEAD --output /tmp/tm-git-rc4-final
diff /tmp/tm-composer-rc4-final/FILES.sha256 /tmp/tm-git-rc4-final/FILES.sha256
```

4. Tag `v1.0.0-rc.4` and publish a GitHub prerelease only after exact-commit CI
   and archive checks pass. Attach release notes, verification evidence and
   checksums, then verify Packagist exposes the tag.

## Remaining qualification

Final-commit CI and maintained-engine reruns, a production-like rc.3→rc.4 host
upgrade, live email clients/providers, database/Redis crash permutations at actual
transport boundaries, wider browser/keyboard/zoom/screen-reader/conflict journeys,
and sustained importer/scheduler/pruning/recovery evidence remain open. The local
consumer check is a fresh-install smoke, not production upgrade qualification.
No PostgreSQL, Octane/Swoole/RoadRunner, physical-device or production SQLite
throughput certification is claimed. Notifications remain at least once; uncertain
GitHub creation must not be blindly retried.
