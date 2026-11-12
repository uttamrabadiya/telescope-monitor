# Historical prototype verification

This record applies only to the archived dashboard-only prototype. Its counts and completed features do not describe the active replacement.

## Local verification on 10 November 2026

Implemented and checked with PHP 8.5.10, Laravel 13.42.0, Telescope 5.27.0, Testbench 11.3.0 and PHPUnit 12.5.43. Composer's development platform targets PHP 8.3, selecting compatible Symfony 7.4 dependencies.

- SQLite: 22 passing tests, 83 assertions; one MySQL/MariaDB-only concurrency test intentionally skipped.
- MariaDB 11.7.2: all 23 tests passed, 94 assertions. The concurrency case runs six independent processes, producing one issue, 31 unique occurrences from shared and distinct UUIDs, and two recipient delivery intents.
- Larastan level 5: no errors.
- Laravel Pint: passed.
- Composer validation: passed.
- Clean Laravel 13 application: installed the package via a local Composer path repository; automatic provider discovery, Telescope installation, package publishing and migrations passed. Captured a real reportable exception, verified redaction, rendered the detail view, sent a new alert through the array mailer, resolved the issue, captured it again, and verified reopening and a sent regression alert. No external email was sent.
- Repeat installation: retained a consumer configuration sentinel byte for byte, published no duplicate migration, and reported nothing to migrate.
- Playwright: 1440px desktop inbox and 390px mobile detail; real search submission, empty search state, detail navigation, Resolve and Reopen passed. Mobile page overflow checks were false on inbox and detail. An initial favicon 404 was removed by supplying an inline favicon declaration. No application JavaScript is required.
- Impeccable mechanical detector: no findings. Independent UI reviewer: ship, no required fixes. Reviewed populated desktop inbox and mobile detail captures; design tokens and component documentation are in DESIGN.md and .impeccable/design.json.

Local dashboard preview: http://127.0.0.1:8191/telescope-monitor while the development server is running. Demo content is synthetic and the workbench has no email recipients.

The full GitHub Actions matrix is configured but has not run remotely. MySQL 8, PHP 8.3/8.4 runtime jobs and the lowest-dependency matrix remain CI checks. Actual mail-provider delivery has not been exercised. The Composer name remains provisional and no release has been published.
