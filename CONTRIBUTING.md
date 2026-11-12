# Contributing

Use synthetic application data in tests, examples, screenshots and documentation.
Use generic names such as `Demo App`, `WidgetService` and `WidgetNotFoundException`,
and reserved domains such as `example.test`. Create exception messages, routes,
stack frames and SQL examples specifically for the behavior being tested.

Do not copy application source, production exceptions, stack traces, database
exports, customer identifiers or deployment details into this repository, even
after removing credentials. Keep private integration checks and their evidence
outside this repository; add a synthetic regression here for the same behavior.

## Development checks

Install locked dependencies with `composer install` and `npm ci`. Run
`composer test`, `composer analyse`, `composer format:check`, `npm run test:unit`
and `npm run build` before submitting a change. Commit the rebuilt `dist/` assets.
Use disposable databases for engine tests; the suite creates and rolls back tables.

The release workflow checks PHP 8.3–8.5, SQLite, MySQL 8.4 and MariaDB 11.4,
lowest/latest dependency resolution, and the consumer archive. Security reports
belong in the private channel described in [SECURITY.md](SECURITY.md).
