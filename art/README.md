# Brand assets

The README wordmark uses the dashboard's indigo palette and existing sparkle mark. `logo.svg` is for light backgrounds; `logo-dark.svg` is for dark backgrounds. SVG text uses a system-font fallback and requires no remote assets.

The sparkle mark comes from Heroicons, copyright Tailwind Labs, Inc., and is covered by the [MIT license](../dist/licenses/heroicons.txt). It is the same icon used by the package dashboard. These assets do not copy Laravel Telescope's wordmark.

## Product showcase

`showcase/` contains screenshots of the current dashboard running against an isolated SQLite workbench with synthetic data, plus notification previews. They are product captures, not the concept images in `docs/design/mockups-v1/`.

- `dashboard.png`, `investigation.png`, `alert-rules.png`, `integrations.png`: local dashboard captures at 1440 CSS pixels wide. The rule editor and integrations show their unconfigured state.
- `email.png`: actual `resources/views/mail/exception.blade.php` rendered with `docs/examples/email-notification.json`.
- `slack.png`: an offline illustration generated from `docs/slack-notification-example.json`. It is not a Slack client screenshot or an exact Slack renderer.

To regenerate the notification HTML after installing development dependencies, run `php scripts/render-showcase-notifications.php /tmp/telescope-monitor-showcase`. Open the generated HTML files locally and capture them at 900 CSS pixels wide, with the full email visible. Use Slack's Block Kit Builder for an authoritative Slack preview. The script does not invoke notification transports.

Keep all future captures synthetic, use reserved example domains, and review the visible content before committing. See the [product tour](../docs/showcase.md) for visitor-facing captions.
