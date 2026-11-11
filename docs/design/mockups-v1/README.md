# Telescope Monitor visual mockups

Status: Main-screen visual direction accepted by the user  
Date: 10 November 2026

Six polished desktop mockups translate the approved wireframes into a shared Telescope-inspired light theme. These are static design images with illustrative data. They do not change or implement the application.

| Screen | Image | State shown |
| --- | --- | --- |
| Onboarding | [Open mockup](onboarding.png) | Step 4 of 8, with a successful synthetic capture |
| Overview | [Open mockup](overview.png) | Captured-error trends and unresolved errors |
| Error details | [Open mockup](details.png) | Unresolved regression, selected occurrence and retained stack |
| Alert rules | [Open mockup](rules-v2.png) | Draft production rule, destinations and notification preview |
| Integrations | [Open mockup](integrations.png) | Configured connections, Slack attention state, unconnected webhook |
| Settings | [Open mockup](settings.png) | Capture and privacy preferences with deployment-managed policy |

The alert-rule image named `rules-v2.png` is the selected revision. `rules.png` is retained as the initial generation; the revision corrects the example source line from 142 to 84.

## Shared visual direction

Use Telescope's light gray canvas and sidebar, compact application header, gray selected navigation row with indigo text/icons, white grouped surfaces, fine dividers and restrained corners. Figtree-style UI typography and monospace file/trace text follow the source reference. Purple is reserved for primary actions, active controls and relevant chart emphasis. Green and amber indicate meaningful success or connection-attention states.

| Reference token | Value |
| --- | --- |
| Primary | `#4040c8` |
| Canvas and sidebar | `#f3f4f6` |
| Content surfaces | `#ffffff` |
| Main text | `#111827` |
| Muted text | `#6b7280` |
| Dividers | `#e5e7eb` |
| Corners | Approximately 6 px |
| Target desktop viewport | 1440 × 1024 |

The product name remains provisional. The date anchor is 10 November 2026, with sample event times in UTC. All six screens use the same Acme Store example and core navigation. The generated raster sizes are recorded in [manifest.json](manifest.json); implementation measurements will follow the approved design system rather than treating generated pixels as source code.

## Review boundaries

This set establishes the six main desktop screen compositions and shared theme. The approved wireframes remain the interaction reference. Mobile visual variants, nested connection editors, notes/activity detail, and full failure-state mockups are not new deliverables in this six-screen set; they can follow the visual review. The product specification remains authoritative for permissions, configuration behavior and acceptance requirements.

No routes, views, styles, jobs, migrations, package configuration or existing application code were edited. No credentials were configured and no external issues or messages were created.

The images were produced with built-in image generation. Exact briefs and the single alert-preview text refinement are saved in [prompts.md](prompts.md).
