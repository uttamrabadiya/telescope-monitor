# Framework compatibility

The compatibility matrix below was recorded on 14 November 2026 before the
scheduled-report feature was added. The changes are included in the prepared
1.0.0-rc.4 candidate; final-candidate checks and their exact scope are recorded in
[the release verification report](release-verification-v1.0.0-rc.4.md). PHP remains `^8.3`; framework
support does not imply support for PHP 7.x, 8.0, 8.1 or 8.2.

The current Telescope minimum is **5.19.0**, verified across Laravel 8–13.
See [minimum-version verification](#telescope-519-minimum-verification) below.

## Framework matrix

Each environment uses its own Composer dependencies, application, and database.
Every row runs the complete suite; no feature tests are excluded for older Laravel.

| Laravel floor | Testbench | PHPUnit | SQLite / PHP 8.3.17 | MySQL 8.2 / PHP 8.3.17 |
| --- | --- | --- | --- | --- |
| 8.83.29 | 6.48.0 | 9.6.39 | 331 tests, 1,988 assertions; 20 engine-specific skips | 331 tests, 2,150 assertions; no skips |
| 9.52.23 | 7.58.0 | 9.6.39 | 331 tests, 1,988 assertions; 20 engine-specific skips | 331 tests, 2,150 assertions; no skips |
| 10.50.4 | 8.39.0 | 10.5.67 | 331 tests, 1,988 assertions; 20 engine-specific skips | 331 tests, 2,150 assertions; no skips |
| 11.57.1 | 9.18.0 | 11.5.58 | 331 tests, 1,988 assertions; 20 engine-specific skips | 331 tests, 2,150 assertions; no skips |
| 12.72.0 | 10.12.0 | 11.5.58 | 331 tests, 1,988 assertions; 20 engine-specific skips | 331 tests, 2,149 assertions; no skips |
| 13.42.0 | 11.3.0 | 12.5.44 | 331 tests, 1,988 assertions; 20 engine-specific skips | 331 tests, 2,150 assertions; no skips |

The framework floors are the tested patch releases, not every historical patch
of those majors. Telescope 5.28.0 and Doctrine DBAL 3.10.7 were used across the
matrix. Laravel 8.83.29 also passed all 331 SQLite tests / 1,988 assertions against
the previous Telescope 5.27.0 floor, with the same 20 engine-specific skips.
The development checkout remains on Laravel 13.42.0. Its existing CI also
checks PHP 8.3, 8.4 and 8.5, and the MySQL 8.4 / MariaDB 11.4 database targets.
New CI jobs cover all six framework majors on PHP 8.3 with SQLite and MySQL 8.4.
Local MySQL 8.2 results do not claim those remote jobs have already executed.

## Implementation

- All 23 models use the `$casts` property, supported across the framework range.
- Migration publishing uses the native API on Laravel 11+ and `publishes()` on
  older hosts. Route constraints and validated request access use common APIs.
- Laravel 8/9 use a package Eloquent builder for insert-first duplicate handling.
  It uses a savepoint inside an existing transaction, recognizes unique conflicts
  for the supported database engines, reads the matching row from the writer,
  and rethrows non-unique or unrelated conflicts. Laravel 10+ uses native behavior.
- Jobs compose the four original queue traits instead of the newer combined trait.
- Laravel 8's SwiftMailer handoff is observed through its sent event and failed
  recipient list; a cancelled send remains unaccepted. Other versions use the
  Symfony Mailer result. The observer holds messages weakly and performs no queries.
- Signed exception origin metadata takes precedence over enclosing worker-command
  metadata. Older failed commands clear only the invocation being reported.
- Legacy SQLite schema alteration uses DBAL 3. Rollback temporarily disables and
  restores SQLite foreign-key enforcement around table rebuilds. MySQL foreign-key
  handling remains native. SQLite versions whose grammars ignore added foreign
  keys do not attempt to drop those nonexistent constraints.

The tests retain authorization, CSRF, sanitization, deduplication, delivery,
retention, purge, key rewrap, worker ordering and zero-query capture checks. Test
adapters provide the appropriate queue table, strict CSRF middleware, mail body
reader and encryption reader for each framework. Older Testbench console-output
mocks are disabled only for the command-output tests. PHPUnit 9 data providers use
docblocks alongside modern attributes; assertions are preserved.

Laravel 8–10 hosts need a dual-key encrypter binding for rotation because those
frameworks do not provide the newer native previous-key API. Rewrap continues to
use the host's encryption contract. See the [operations guide](operations-guide-v1.md).

## Composer behavior

Telescope supplies `laravel/framework` transitively. Monitor no longer repeats
that runtime requirement. Its `conflict` constraint excludes framework patches
below the tested floors and majors outside 8–13. Laravel 13 remains a development
dependency for the workbench. A clean Laravel 8 consumer resolved the package
through a path repository without including Monitor's development dependencies.

DBAL `^3.10` is a runtime dependency for older schema operations. This constrains
consumers to DBAL 3; an application requiring DBAL 4 needs a separate compatibility
decision. Carbon's compatible DBAL type adapter is selected by Composer.

Composer's default security policy blocks the older framework combinations.
The isolated Laravel 8–11 test jobs use `--no-security-blocking` to measure runtime
compatibility; this is not configured globally or in the shipped package.
Observed install audits reported seven advisories across two packages for Laravel
8, seven in one package for Laravel 9, five in one package for Laravel 10 and 11,
and none for the tested Laravel 12 and 13 sets. Compatibility does not resolve
those upstream advisories.

## Reproducing the matrix

Run the following in a disposable checkout for each major from 8 through 13:

```sh
php scripts/configure-framework-tests.php 8
composer update --no-interaction --prefer-dist --no-scripts --no-security-blocking
php vendor/bin/phpunit --log-junit framework-results.xml
```

Use `--no-security-blocking` only for the legacy compatibility experiment;
Laravel 12 and 13 resolve under the normal security policy. The configuration
script pins the matching framework, Testbench and PHPUnit versions, omits the
separate analysis tool, and preserves the rest of the package dependencies. It
rewrites the checkout's Composer manifest, so do not run it against an application
or the primary development checkout.

For database concurrency tests, point `MONITOR_DB=mysql`, `DB_DATABASE` and
`MONITOR_DB_SOCKET` (or `DB_HOST`/`DB_PORT`) at a dedicated disposable database.
The tests create and roll back their schema and must not target application data.

Local fixtures are `/private/tmp/telescope-monitor-framework-{8..13}`. Logs and
JUnit results are `/private/tmp/telescope-framework-<major>-tests.log`,
`/private/tmp/telescope-framework-<major>-results.xml`, and corresponding
`-mysql-tests.log` / `-mysql-results.xml` files. The completed MySQL run used a dedicated,
socket-only server with test-only durability settings; these tests do not certify
server-crash or power-loss durability.


## Additional checks

The main development checkout also passed all 331 tests / 1,988 assertions on PHP
8.5.10 (20 SQLite engine-specific skips). Static analysis, the full formatting
check, strict Composer validation and diff whitespace checks passed. The release
archive verifier passed, including the framework conflict metadata and this
report. No package publication or remote CI run was performed. The dedicated
MySQL test server was shut down after verification.

## Telescope 5.19 minimum verification

The dependency floor is now `laravel/telescope: ^5.19.0`. Tests pin **exactly
5.19.0**, rather than resolving a newer release through a caret constraint. The
existing framework compatibility changes required no further runtime changes.

| Laravel | PHP | SQLite | MySQL 8.2 |
| --- | --- | --- | --- |
| 8.83.29 | 8.3.17 | 331 tests / 1,988 assertions; 20 engine-specific skips | 331 tests / 2,150 assertions; no skips |
| 9.52.23 | 8.3.17 | 331 tests / 1,988 assertions; 20 engine-specific skips | 331 tests / 2,149 assertions; no skips |
| 10.50.4 | 8.3.17 | 331 tests / 1,988 assertions; 20 engine-specific skips | 331 tests / 2,150 assertions; no skips |
| 11.57.1 | 8.3.17 | 331 tests / 1,988 assertions; 20 engine-specific skips | 331 tests / 2,150 assertions; no skips |
| 12.72.0 | 8.3.17 | 331 tests / 1,988 assertions; 20 engine-specific skips | 331 tests / 2,150 assertions; no skips |
| 13.42.0 | 8.3.17 | 331 tests / 1,988 assertions; 20 engine-specific skips | 331 tests / 2,149 assertions; no skips |

A separate Laravel 13.42.0 / PHP 8.5.10 fixture resolved Symfony Console and HTTP
Kernel 8.1.11, Symfony VarDumper 8.1.10 and Telescope 5.19.0. Its complete SQLite
suite also passed 331 tests / 1,988 assertions with 20 engine-specific skips.
Composer resolved that combination under its normal security policy. The
PHP 8.3.17 Laravel 12/13 sets also reported no Composer advisories; older framework
sets retain the upstream advisories described above.

CI now covers both Telescope 5.19.0 and the latest allowed release across every
Laravel major on SQLite and MySQL. An additional PHP 8.5 / Laravel 13 / Telescope
5.19.0 job exercises the newer Symfony dependency combination. These jobs are
configured locally; no remote CI execution has been claimed.

To reproduce the pinned floor, use a disposable checkout:

```sh
php scripts/configure-framework-tests.php 8 5.19.0
composer update --no-interaction --prefer-dist --no-scripts --no-security-blocking
php vendor/bin/phpunit --log-junit telescope-519-results.xml
```

Use the normal Composer policy for Laravel 12/13. For the PHP 8.5 fixture, use
major 13 and set `composer config platform.php 8.5.0` before resolving. Omitting
the optional Telescope argument (or passing `latest`) uses the package's allowed
release range. The script rejects invalid major/version arguments before writing.

Fixtures are `/private/tmp/telescope-monitor-519-laravel-<major>` and
`/private/tmp/telescope-monitor-519-laravel-13-php85`. Evidence is retained in
`/private/tmp/telescope-519-<major>-sqlite-results.xml` and corresponding
`-sqlite-tests.log`, `-mysql-results.xml`, `-mysql-tests.log` and `-install.log`
files. PHP 8.5 evidence uses `/private/tmp/telescope-519-13-php85-results.xml` and
`-tests.log`. The database fixture uses a dedicated socket-only server with
non-durable test settings; it does not test database-server or power-loss failure.

All Telescope 5.19 MySQL runs finished with zero failures and zero skips. The
disposable database server was shut down after verification. Composer validation,
configuration round-trip checks, formatting, workflow YAML validation and package
archive verification passed. No release was published.

## rc.4 candidate regression repeat

The candidate source, including scheduled reports and its index migration, was
rerun against every Laravel floor above with Telescope pinned to 5.19.0 and PHP
8.3.17. Each complete SQLite run passed **343 tests / 2,099 assertions**, with 20
engine-specific skips and zero failures/errors. The locked development PHP 8.5.10
suite has the same result. These new runs supersede the earlier 331-test SQLite
baseline for candidate regression coverage; MySQL entries above remain historical.
See [rc.4 verification](release-verification-v1.0.0-rc.4.md) for the executed scope,
local review bundle, fresh consumer smoke and pending final-commit CI gates.
