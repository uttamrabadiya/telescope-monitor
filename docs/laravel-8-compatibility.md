# Laravel 8 compatibility investigation

Date: 14 November 2026

Historical investigation before the compatibility changes. The current source
addresses these failures; see [current framework support](framework-compatibility.md).

Result: the current package does not run unchanged on Laravel 8. Removing its
direct Laravel requirement would permit unsupported installations rather than
provide compatibility. No production dependencies, lockfile or source were changed
for this experiment.

## Isolated environment

Copied the current package source, tests, migrations, configuration, routes,
resources and compiled assets to `/private/tmp/telescope-monitor-laravel8-compat`.
The fixture's Composer manifest retained the package's PHP `^8.3` and Telescope
`^5.27.0` constraints, changed Laravel to `^8.83`, and used Testbench `^6.48` with
PHPUnit `^9.6`. Development-only formatter and analysis dependencies were omitted.
Testbench 6 is the Laravel 8 testing harness according to its
[compatibility table](https://packages.tools/testbench#version-compatibility).

Resolved versions:

| Component | Version |
| --- | --- |
| PHP | 8.3.17 |
| Laravel | 8.83.29 |
| Telescope | 5.28.0 |
| Testbench | 6.48.0 (core 6.54.0) |
| PHPUnit | 9.6.39 |

Normal Composer resolution refused Laravel 8 due to security advisories. A second
installation used `--no-security-blocking` only for this disposable fixture, with
`--no-scripts`. The final audit reported seven advisories: six for Laravel and one
for Flysystem. This exception is not part of the package configuration.

## Observed failures

| Check | Result |
| --- | --- |
| Existing `InstallationTest`, unchanged package source | Fails during provider boot: undefined `TelescopeMonitorServiceProvider::publishesMigrations()`; 1 test, 0 assertions, 1 error |
| Same test after a fixture-only `publishesMigrations` → `publishes` substitution | Fails during route registration: `Illuminate\Routing\Route::whereIn` does not exist; 1 test, 0 assertions, 1 error |
| Direct Eloquent builder probe | `createOrFirst()` throws `BadMethodCallException`; installation and multiple repositories use it |
| Direct installation model probe | `getCasts()` exposes only the default `id` cast; `setup_state` JSON and `generation` remain strings instead of array/integer |

The model issue affects the `casts()` method declarations across 23 package
models. A route or publishing workaround alone would leave persistence behavior
incorrect. Replacing `createOrFirst()` also requires preserving duplicate handling
and transaction/concurrency behavior rather than a mechanical method rename.

The complete feature suite was not run: the application cannot finish booting.
These failures establish incompatibility, not an exhaustive list of backport work.
Tests ran against SQLite and PHP 8.3 only; no claim is made about other database
engines or older PHP. The package still requires PHP 8.3 independently of Laravel.

## Reproduction evidence

The fixture uses this command for the first test (absolute PHP path selects 8.3):

```sh
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit \
  tests/Feature/InstallationTest.php --stop-on-error --stop-on-failure
```

The PHPUnit 12 `cacheDirectory` XML attribute was removed from the fixture for
the second run to avoid a PHPUnit 9 configuration warning. No test assertion was
changed. The initial, unchanged-source failure log is
`/private/tmp/telescope-monitor-laravel8-installation-test.log`. Further evidence:

- `/private/tmp/telescope-monitor-laravel8-resolve.log`
- `/private/tmp/telescope-monitor-laravel8-install.log`
- `/private/tmp/telescope-monitor-laravel8-after-publish-shim.log`
- `/private/tmp/telescope-monitor-laravel8-api-probe.log`
- `/private/tmp/telescope-monitor-laravel8-audit.json`

The direct probe is saved as `probe.php` in the isolated fixture. The original
source remains in the workspace, while that fixture contains the one publishing
substitution described above.

## Dependency decision

Keep the direct Laravel constraint until the package's own supported framework
range is established by runtime tests. Telescope's broader constraint describes
Telescope's compatibility, not Monitor's. Even a successful Laravel 8 test would
not certify Laravel 9–12 automatically. Supporting additional majors requires a
version matrix and framework-specific test harnesses before removing or widening
the existing constraint.
