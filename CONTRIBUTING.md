# Contributing

Use synthetic application data in tests, examples, screenshots and documentation.
Use generic names such as `Demo App`, `WidgetService` and `WidgetNotFoundException`,
and reserved domains such as `example.test`. Create exception messages, routes,
stack frames and SQL examples specifically for the behavior being tested.

Do not copy application source, production exceptions, stack traces, database
exports, customer identifiers or deployment details into this repository, even
after removing credentials. Keep private integration checks and their evidence
outside this repository; add a synthetic regression here for the same behavior.
