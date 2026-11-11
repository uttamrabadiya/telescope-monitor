# Telescope Monitor

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

Standalone Composer package inside each Laravel application, using official Telescope for capture and the host database, authentication, mailer, queue, and scheduler. No consumer Node or asset-build requirement. Laravel 13 and Telescope 5 remain the initial compatibility target. The approved architecture selects a package-owned Vue 3 and TypeScript dashboard with assets compiled at release time; the prototype's Blade implementation does not constrain the replacement.

## Users

Laravel developers investigating exceptions, operators triaging incidents, and administrators configuring monitoring, access, privacy, and integrations. Viewer, operator, and administrator capabilities map to host authorization rather than a package-owned user directory.

## Product Purpose

Provide a complete error-monitoring workflow: install, configure, capture, investigate, notify, create or link an external ticket, resolve, and detect regressions. First-class setup, settings, integration management, delivery history, and operational diagnostics are part of the product.

## Operating Context

One host application per installation, normally with separate deployments and data per environment. Capture depends on Telescope's enabled watchers, recording/filtering policy, and successfully stored reportable events. The package retains sanitized evidence independently of Telescope pruning. Authorization remains explicitly host-configured; the dashboard does not provide login or grant itself permissions.

## Capabilities and Constraints

Confirmed v1 scope: error overview and investigation, alert rules, editable settings, integrations, notes, saved views, and optional assignments. Initial integrations are email, GitHub Issues, Slack, and outbound webhooks. Performance monitoring, browser SDK/session replay, centralized monitoring, billing, and two-way ticket synchronization are deferred. Error trends are in scope; bulk status actions and automatic live refresh are deferred. Product and Composer names remain provisional.

The detailed replacement plan is [Telescope Monitor v1 Product Specification](docs/product-specification-v1.md). The user has accepted the main wireframes and Telescope-themed visual mockups. The accepted technical design is recorded in the [architecture](docs/technical-architecture-v1.md), [database schema](docs/database-schema-v1.md), and [phased plan](docs/implementation-plan-v1.md). Implementation is underway. The previous prototype has been verified and archived; the active code is the replacement foundation, durable capture and Phase 3 investigation and Phase 4 collaboration. See [implementation progress](docs/replacement-progress.md) for completed capabilities and remaining gates.

## Brand Commitments

The user requests a complete monitoring experience like Flare, built on Telescope. Flare is the reference for coherent investigation, settings, notification, and integration workflows. The accepted mockups use Telescope's light UI theme, Figtree-style typography, indigo accents, pale gray canvas/sidebar, and white grouped surfaces. Prototype styling does not constrain the replacement.

## Evidence on Hand

User-supplied initial specification, subsequent confirmed product decisions, replacement v1 specification, accepted main-screen wireframes, and accepted six-screen visual mockups. The previous workbench is archived; the replacement workbench starts without fabricated exception groups or metrics. The accepted replacement mockups are indexed in [the visual design folder](docs/design/mockups-v1/README.md); nested states and mobile visual variants still require review during implementation planning.

## Product Principles

- Preserve application error handling when monitor processing fails.
- Retain only investigation data, sanitized before persistence.
- Make configuration, investigation, delivery outcomes, and recovery understandable in the UI.
- Keep lifecycle and external actions explicit, authorized, and auditable.
- Ship complete user journeys without a consumer asset build.
