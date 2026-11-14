# Alert rules design verification

Date: 2026-11-13

## Visual targets

Accepted image concepts, under `/Users/uttam/.codex/generated_images/01a1d5ac-b23e-70d3-9dc1-1bf9ef7da3cd/`:

- Empty state: `exec-43ff6ac5-0e57-4c21-9b1b-d735e6e75b31.png`.
- Rules list (option 1): `exec-d7b275ed-0581-4ad8-b4cf-4ae767016eba.png`.
- Guided creation (image 3): `exec-c3ebc97e-edeb-4df2-a005-7849e85ec2bf.png`.

Implementation: http://127.0.0.1:8195/telescope-monitor/alerts

Screenshots are saved in `.playwright-mcp/alert-rules/` (local, ignored browser evidence):

- `empty-desktop.png`
- `list-desktop.png`
- `events-desktop.png`
- `events-mobile.png`
- `conditions-mobile.png`

## Comparison setup

Desktop CSS viewport reported by the browser: 1440 × 1024. Mobile: 390 × 844.
The browser's existing zoom/density is 1.2; viewport overrides of 1728 × 1229 and
468 × 1013 produced these CSS viewports. Explicit full-viewport screenshot clips
were used because default screenshot capture cropped the enlarged viewport.
Desktop evidence is 1440 × 1024 pixels and mobile evidence is 390 × 844 pixels.
Generated source images are 1487 × 1058 pixels. Source and implementation images
were opened together in paired comparison inputs. Compare the content regions
and hierarchy with the application's incumbent max-width shell retained;
this is an implementation in the existing app, not a replacement of its shell.
The existing viewport was restored after validation.

## Findings

No remaining actionable P0/P1/P2 findings in the tested states.

- Typography: existing self-hosted Figtree and application text/control sizes
  retained. Mockups have a larger overall display scale; production keeps the
  established app density. Event titles, descriptions, labels, and actions
  remain distinct and readable. Long values wrap within their column.
- Spacing/layout: accepted empty-state grouping, table hierarchy, four-step
  navigation, event choices, summary rail, and action footer implemented.
  Mobile stacks the summary and retains all step and footer controls. Table
  overflow is contained in a labeled, keyboard-focusable scrolling region.
- Tokens/colors: existing light/dark CSS variables reused for surfaces, borders,
  indigo controls, selection, helper text, and semantic status badges.
- Assets: existing brand and Heroicons used; no replacement bitmap UI or
  placeholder illustrations. The bell and event icons match the intended roles.
- Copy/content: accepted empty-state copy and step labels retained. Actual
  configuration controls the table data. Test rules remain drafts because no
  destination is configured in this isolated preview.
- Focused regions: step tracker, event rows, mobile footer, and table controls
  were checked through screenshots and DOM geometry. Additional cropped images
  were unnecessary because the full screenshots show these regions clearly.

## Comparison history

1. Initial desktop wizard check found a P2 overflow in the step tracker: the
   final flex item had zero basis and its label extended beyond the container.
   Fixed its basis to `auto`. Final desktop and mobile screenshots show all four
   steps within the content width; DOM measurements show no page overflow in
   the wizard (1440/1440 and 390/390 respectively).
2. Matched the list search input to its parent control by overriding the global
   search input border/radius. Final list screenshot shows a single control.
3. Final paired comparisons of empty, list, and Events screenshots against the
   accepted images found no further P0/P1/P2 differences. Existing app shell
   dimensions, compact typography, and selected-event tint are intentional.

## Functional validation

- Empty state has one Create rule action and no search, filter, or table chrome.
- Created a draft through Events → Conditions → Destinations → Review → Save.
- Missing environment prevents advancing; restoring a choice permits progress.
- No-destination setup explains how to add one or save a draft.
- Enabling without an available destination is rejected before persistence.
- Sanitized notification preview renders and confirms nothing was queued/sent.
- Successful save returns to the list and reflects the persisted draft.
- Edited and saved a rule; duplicated it and saved a threshold-only rule with
  ten occurrences in a fifteen-minute window.
- Search produces a distinct no-match state; Clear filters restores the list.
- Mobile forward navigation and Cancel work; footer actions remain visible.
- Browser console: no captured warnings/errors in the checked flow.
- Production build/typecheck passed; frontend tests 12/12; existing alert backend
  tests 30/30 with 221 assertions; `git diff --check` passed.
- Mechanical design detector flags incumbent CSS warnings and type-ramp
  advisories. New styles deliberately reuse the existing application scale and
  tokens; no new literal colors or thick accent borders were introduced.

## Limits and follow-up

Live external deliveries were not sent. Delete confirmation, concurrent-edit
conflicts, a fully connected enabled rule, and a dedicated dark-mode screenshot
were not exercised in this browser pass; their backend behavior was preserved.
Existing unsaved-change guards remain in place for Cancel, internal navigation,
and browser unload.

Implementation checklist: complete. Two synthetic draft rules are left in the
isolated workbench database for preview. Empty-state screenshots were captured
by temporarily hiding these fixtures, then restoring them.

## Actions menu regression fix

The initial list used inline disclosure content, which expanded the table row.
Replaced it with a body-mounted native popover positioned with `position: fixed`
and aligned to the ellipsis button. Native light dismissal and top-layer rendering
avoid clipping by the horizontally scrolling table. Position updates on scroll
and resize, clamps to the viewport, and flips above the anchor when needed.

Verified in the in-app browser:

- Row heights before and after opening are identical: 84.32px and 83.91px.
- Keyboard Down moves from Edit rule to Duplicate; Escape closes the menu and
  restores focus to its trigger.
- Clicking outside dismisses the menu.
- Edit rule opens the existing editor and Cancel returns to the list.
- At 390 × 600 CSS pixels the popup stays inside the viewport (left 186.17px,
  right 362.16px, top 326.91px, bottom 456.56px).
- No captured browser warnings/errors. Production typecheck/build passed.

Desktop evidence: `.playwright-mcp/alert-rules/actions-desktop.png`.
Mobile evidence: `.playwright-mcp/alert-rules/actions-mobile.png`.

final result: passed

# Errors list redesign — option 1

Date: 2026-11-13

## Source and rendered evidence

Accepted source: `/Users/uttam/.codex/generated_images/01a1d5ac-b23e-70d3-9dc1-1bf9ef7da3cd/exec-13e6c567-bc14-4501-a557-6752c46d1f7f.png`
(1487 × 1058 pixels).

Implementation: http://127.0.0.1:8195/telescope-monitor/errors

Browser screenshots in `.playwright-mcp/errors-list/`:

- `desktop.png`: final default list, 1440 × 1024 pixels.
- `mobile.png`: mobile list, 390 × 844 pixels.
- `views-mobile.png`: final mobile dropdown bounds, 390 × 844 pixels.

CSS viewport measurements were 1440 × 1024 and 390 × 844. Existing browser zoom
was 1.2, so native viewport overrides were 1728 × 1229 and 468 × 1013, with full
screenshot clips to avoid capture cropping. The viewport override was reset after
verification. Source and rendered desktop images were opened together in paired
comparison inputs, before and after fixes. Content regions were compared while
retaining the incumbent application shell, maximum width, and global type scale.
No separate region crops were needed: complete screenshots and DOM geometry show
the toolbar, table, and popup bounds.

## Findings and comparison history

- No remaining actionable P0/P1/P2 findings in verified states.
- The accepted hierarchy is implemented: one status-tab row, one search/filter/
  sort/views toolbar, four default table columns, grouped range/lifetime counts,
  source metadata, relative last-seen times, and pagination.
- Fonts/typography: retained self-hosted Figtree, existing app title scale and
  control sizes; semibold error titles, subdued metadata, and tabular counts
  distinguish the scan priorities. Mockup display scale is larger than the
  existing app, intentionally preserving product-wide density in production.
- Layout/spacing: error rows begin around 391 CSS pixels from the viewport top
  at the desktop target. Mobile controls wrap; the table scrolls inside its
  labeled focusable region. Document width equals viewport width at both sizes.
- Colors/tokens: reused themed surfaces, borders, helper text, primary links,
  statuses, and focus tokens. All button/input corners remain .25rem.
- Assets: existing brand and Heroicons; no generated bitmap UI or placeholders.
- Copy/data: actual workbench errors and counts replace the illustrative mock
  data. Full existing coverage and privacy explanations are retained. Status
  badges appear per row in the All view; regression/test/history badges remain
  available in specific status views.
- Initial mobile check found a P2 saved-view dropdown overflow (left edge
  -26.52px). Changed the panel to fixed positioning, clamped to the viewport,
  repositioned on scroll/resize, and flipped above when space below is limited.
  Final mobile capture measures left 12px, right 312px within a 390px viewport.
- Also corrected the initial blank origin selector to show Any origin and
  returned keyboard focus to Filters after closing its panel. Removed an
  invisible submit-button tab stop; native Enter-to-search was then verified.

## Functional verification

- Enter-to-search matches a single Payment or Inventory error; clearing restores
  all three sample groups. Search owns a single focus ring around icon/input.
- Saved a personal `Payment errors preview` view; its stored filters were applied
  and the result list was correct. Built-in view selection restores the list.
- Advanced Origin=job yields a clear zero-result state and an active-filter count.
  Reset advanced filters restores the three sample groups.
- Resolved status yields an empty state; All shows each row's lifecycle badge.
- Environment, relative range, and sort update the query and table range heading.
- Opening an investigation and returning preserves status, environment, range,
  and sort in the list URL.
- Saved-view dropdown dismisses on Escape/outside click and remains inside the
  mobile viewport. Advanced filters are available on mobile with a single-column
  layout.
- Final browser console: no captured warnings/errors.
- Production build and typecheck passed; frontend unit tests 12/12; investigation
  and collaboration backend tests 34/34 (241 assertions); diff whitespace clean.
- Mechanical detector: no Vue findings. Existing shared-CSS warnings and type
  ramp advisories do not require changing unrelated product surfaces.

## Limits and accepted differences

The workbench contains only three groups, so multi-page navigation and the
optional assignment/tag configurations were not exercised in the browser pass.
Backend tests cover the existing investigation/collaboration contracts. Destructive
saved-view actions were preserved without deleting user data. The Overview keeps
its compact table mode. A new personal test view remains in the isolated preview
for inspection. Mobile horizontal table scrolling is intentional; there is no
whole-page horizontal overflow. No dark-mode screenshot was taken in this pass.

Implementation checklist: complete.

final result: passed

# Tailwind dashboard migration

Date: 2026-11-13
Scope: the complete dashboard; notification email templates are unchanged.

## Visual truth and evidence

The existing implementation is the visual target. The original 73 KB stylesheet
was preserved outside the workspace for the comparison. A temporary local-only
reference server applied that stylesheet to the same populated dashboard so
comparisons used identical data, markup, fonts, authorization, and route states.
The reference route, provider flags, temporary stylesheet link, and both temporary
servers were removed after verification. The normal preview remains available at
http://127.0.0.1:8195/telescope-monitor/errors.

Before/after screenshots and computed-style JSON are saved under
`.playwright-mcp/tailwind/before/` and `.playwright-mcp/tailwind/after/`.
Desktop files cover errors, overview, alerts, integrations, settings, setup,
delivery history, system health, operations, investigation, privacy settings,
email settings, and retention. These local comparison artifacts are ignored by Git.

Desktop CSS viewport: 1440 × 1024. Mobile target: 390 × 844. The browser's native
1.2 scale required 1728 × 1229 and 468 × 1013 capture rectangles; viewport overrides
were reset after verification. Complete desktop image pairs were opened together
for comparison; full screenshots and element geometry made additional crops
unnecessary. Dynamic timestamps naturally advance between captures.

## Implementation and fidelity

- Tailwind CSS 4.3.5 is compiled through its official Vite integration. The CSS
  entry contains framework/source/variant directives and theme configuration only.
- All 31 Vue files use complete Tailwind utility strings through shared `ui()`
  recipes. Semantic DOM hooks are retained independently from utility merging.
  There are no dashboard style blocks, authored style attributes/bindings, runtime
  CSS mutations, or SVG presentation styles outside Tailwind utilities.
- Typography: self-hosted Figtree, original weights, sizes, line heights, and
  inheritance are retained. Explicit font sizes use utilities that preserve
  independently specified line heights.
- Spacing/layout: current page shell, grids, control sizes, table structure,
  pagination, panels, and responsive breakpoints are preserved. All button/input
  radii use the .25rem theme radius. Circular decorative marks retain 50% radii.
- Colors/tokens: the original light palette is registered in Tailwind theme
  tokens. Tailwind variable utilities on the shell apply the Telescope-selected
  dark palette. Native control color-scheme, focus, selection, scrollbar, state,
  chart, and disabled-field colors remain themed.
- Assets: existing Heroicons and local font assets are preserved. Chart fill,
  stroke, stroke width, and text anchoring are Tailwind utilities. No raster UI
  substitutions or placeholder assets were introduced.
- Copy/product behavior: existing content, route/query semantics, capability
  gates, data handling, and backend APIs are preserved. Menu placement now uses
  native CSS anchors/popovers through Tailwind utilities, with viewport fallbacks.

## Comparison history and findings

1. Initial comparisons identified shorthand/font cascade differences: inherited
   button font settings overrode explicit sizes/weights, and font-size merging
   discarded caption line heights. Normalized the utility recipes and added
   regression coverage for explicit typography and semantic-hook preservation.
2. Corrected disabled-control specificity, directional spacing, status borders,
   theme-variable references, and context-specific heading values.
3. Final default-state computed comparisons showed parity on all 13 routes for
   visible colors, typography, spacing, sizes, borders, and layout properties
   checked. Sub-pixel differences under 2px in layout measurements and SVG/text
   geometry were normalized. Remaining differences are the 8px native-menu
   placement margin and equivalent `end`/`flex-end` values on delivery filters.
4. Mobile checks confirmed no new layout drift. The rules table retains the
   original horizontally scrolling behavior, including the browser's reported
   scrollable extent. An initial overflow concern was compared against the
   original stylesheet and confirmed to be existing table behavior. Closed
   popover anchors are isolated to their open state.

No actionable P0/P1/P2 visual findings remain in the verified surfaces.
Mechanical design detector: zero findings.

## Interaction and responsive verification

- Desktop navigation and all primary route layouts loaded successfully.
- Error search submitted on Enter and clearing restored all three sample groups.
- Alert menu positions align to their trigger without expanding table rows.
  On mobile the measured popup bounds were left 186.17px / right 362.16px inside
  a 390px viewport. Escape closes the menu and restores trigger focus.
- Saved-view popup uses centered mobile placement; measured bounds were 45px to
  345px inside a 390px viewport. Existing view selection/management remains intact.
- Alert builder progresses through Events, Conditions, Destinations, and Review.
  Environment selection, keyboard dismissal, and Cancel work.
- Mobile navigation opens and selecting Errors closes it correctly.
- Light mobile captures cover overview, errors, alerts, integrations, settings,
  setup, saved views, the rule menu, and the rule builder.
- Dark desktop captures cover overview, errors, alerts, integrations, and settings;
  dark mobile captures cover errors and overview. Canvas/text computed values are
  rgb(17,24,39) and rgb(243,244,246), matching the original dark palette.
- Runtime checks found zero inline style attributes in dashboard-owned surfaces
  and zero inline styles on the teleported rule menus. A browser-injected comment
  overlay is outside the application and was excluded from this audit.
- Browser console: no captured errors or warnings in the verified interactions.

## Final checks and practical limits

- Frontend: 17 tests passed, including full-source styling/variable audits and
  regressions for utility merging, UI hooks, typography, and existing behavior.
- PHP: standard full suite passed, 322 tests / 1,945 assertions / 20 skips.
- Production Vue/TypeScript/Vite build passed without CSS warnings.
- `git diff --check` passed. Tailwind and tailwind-merge license notices are
  included in compiled distribution assets and required by the release verifier.
- Stylesheet source is framework/theme configuration; no handwritten selector
  fallback, `@apply` wrapper, component stylesheet, or inline coordinate fallback
  remains in the dashboard. Future source checks enforce this boundary.
- Framework dependencies retain their own generated/font-registration CSS.
  Notification email CSS is outside the agreed dashboard-only scope.
- Combined compressed CSS/JS assets are approximately 31 KB larger than the
  pre-migration build, from generated utility selectors and the shared recipes/
  merge helper. Consumers still receive compiled local assets and need no Node
  or Tailwind installation.
- The existing optional PHP skips and browser states requiring configured live
  integrations remain limits of this pass; no external notifications were sent.

Implementation checklist: complete.

final result: passed


# Shared 38px control height

Date: 2026-11-14

The Tailwind `--spacing-control: 38px` token now controls single-line inputs,
buttons, link-buttons, icon buttons, menu buttons, selects, and dropdown triggers.
Removed the larger mobile, empty-state and form minimum-height overrides. Search
wrappers own the 38px border box and their inner input fills the available space.
Checkbox/radio marks and multiline textarea/multi-select controls retain their
existing sizing. All styling remains Tailwind utilities.

Browser measurements found no control-height mismatches (0.2px tolerance for
browser scaling) on overview, errors, alerts, general settings, integrations,
delivery history, privacy, and retention. The alert-builder environment dropdown
and navigation buttons also measured 38px. Mobile checks covered alerts, errors,
overview, and settings, with zero mismatches. The original viewport was restored.

Evidence: `.playwright-mcp/controls/height-38.png`.
Production typecheck/build passed; all 17 frontend tests passed; whitespace check
passed. The style contract also checks that the shared control token stays 38px.

final result: passed

# View preferences UX

Date: 2026-11-14

Renamed the user-facing Personal shortcuts entry to View preferences. Replaced the
inline, individually auto-saving controls with a native modal dialog containing:

- An explicit Default error view selector.
- An ordered Favorite views list with add, remove, up, and down actions.
- Save changes and Cancel. Draft edits remain local until one versioned save.

The settings are independent of the current error filters. Favorite membership is
independent of the default view. Removing a favorite does not delete its saved
view. Available saved views can be loaded in additional catalog pages. Version
conflicts offer a reload action. The dialog uses Tailwind utilities, themed colors,
38px controls and .25rem control corners; the old inline styling recipes were
removed.

Evidence: `.playwright-mcp/view-preferences/desktop.png`, `configured.png`, and
`mobile.png`. The original inline UI and the new dialog were inspected in the
in-app browser. The configured capture shows temporary test preferences; the
original default and empty favorites were restored after verification.

Verified in the browser:

- Adding a favorite and cancelling leaves the saved preferences unchanged.
- Adding two favorites, reordering them, selecting a default, and saving persists
  all choices together. Reloading reopened the chosen default and restored the
  saved favorite order.
- Removing favorites and restoring the default saves correctly.
- Reordering retains keyboard focus on the moved row. Removing/adding returns
  focus to the add selector; closing returns focus to Saved views.
- At a 390 × 844 viewport the configured dialog measured 358 × 748px, entirely
  within the viewport. Its content width was 356px, with no horizontal overflow.
  All 12 controls in that state measured exactly 38px high. The viewport override
  was reset after verification.
- Cancel, close, and native Escape discard the draft. No captured browser
  warnings or errors remained in the tested flow.

Typography, spacing, separators, focus and state colors follow the existing
Telescope theme; the dialog groups default selection and favorite ordering into
separate sections. Footer actions remain visible while the body scrolls on small
screens. No actionable P0/P1/P2 findings remain in the verified states.

Validation: production typecheck/build passed; 20 frontend tests passed; 17
collaboration backend tests passed (128 assertions); whitespace check passed.
The frontend tests cover draft isolation, explicit payload/version/order, boundary
moves, and independence of default selection from favorites, alongside the full
Tailwind-only styling and theme-token audits. Multi-page catalogs and live
concurrent edits were not exercised in this browser pass.

final result: passed

# Shared dashboard table format

Date: 2026-11-14

Standardized all eight table definitions using shared `$table`, `$th`, `$td` and
row-hover Tailwind recipes. The Errors page is the visual baseline. Removed 57
page-specific table/cell chrome overrides while retaining column sizing, scroll
minimums, metadata hierarchy, and the existing data/actions.

The improved header is a unified, subtly outlined canvas-tinted bar: 56px height,
13px semibold labels, consistent padding, middle alignment, and .25rem outside
corners. Secondary range labels use 12px normal text. Bodies share 15px base text,
20px vertical gutters, row dividers, and a quiet hover tint; mobile vertical gutters
are 16px and horizontal gutters are 12px. Every table now has a descriptive hidden
caption and column-header scopes. Overview counts use the explicit Occurrences
header with the selected range underneath.

Browser validation covered Errors, alert rules, delivery history, health checks,
operational observations, access capabilities, chart data, and retention preview.
Every checked desktop header measured 56px, with 13px/600 text, 8px 16px padding,
and the same background. Mobile checks on Errors, rules, health, and access measured
56px and 8px 12px padding. A temporary assignment-enabled preview verified the
additional Assignee column; its focused table uses a 960px scroll minimum to keep
all five headers readable. That preview was stopped afterward.

Evidence: `.playwright-mcp/tables/errors.png`, `alerts.png`, `alerts-history.png`,
`settings-health.png`, `settings-operations.png`, `settings-access.png`,
`errors-mobile.png`, and `assignee-mobile.png`. Native table semantics, domain
columns, numeric alignment, and the shared 38px control size remain intact. All
styles are Tailwind utilities and both theme palettes use the existing theme
variables. The current viewport was restored and the normal Errors preview left
open. No captured browser warnings/errors occurred in this pass.

Validation: 21 frontend tests and production typecheck/build passed. A new source
audit enforces shared table/cell recipes, captions, and header scopes across all
Vue files. Whitespace check passed. This presentation-only change did not alter
backend APIs; no new dark-theme screenshot was taken in this pass.

final result: passed

# Refined Telescope table headers

Date: 2026-11-14

Applied the selected refined Telescope direction to the shared table recipes:
40px headers, 14px semibold labels, 8px 20px desktop padding, a flat gray background,
and one subtle bottom divider. Removed the rounded outline. Mobile horizontal
padding remains 12px. Body horizontal gutters match the header, and count/time
columns now align both labels and values to the right. Descriptive columns remain
left-aligned. Error headers show Occurrences (24h), or the selected range, on one
line in both the inbox and overview. Automatic table layout accommodates intrinsic
header widths, including optional assignment columns.

Verified all table definitions in the browser: Errors, rules, delivery history,
health, operations, access, chart data, and retention preview. Every measured
desktop header was 40px, 14px/600, with 8px 20px padding. Mobile Errors, rules, and
delivery headers also measured 40px with 8px 12px padding. Count/time alignment was
checked against the corresponding cells. No captured browser warnings/errors.
The viewport override was restored and the Errors page left open.

Evidence: `.playwright-mcp/tables/refined-errors.png`. The earlier comparison
reference is `.playwright-mcp/tables/telescope-reference.png`.

Validation: all 21 frontend tests and production typecheck/build passed;
`git diff --check` passed. Styling remains Tailwind-only; controls remain 38px.

final result: passed

# Triage overview with grouped bars

Date: 2026-11-14

Implemented selected option 1 and the requested bar-chart revision. Visual target:
`/Users/uttam/.codex/generated_images/01a1d5ac-b23e-70d3-9dc1-1bf9ef7da3cd/exec-17fe1cc8-05bd-4aae-922d-dfbd998d8ef1.png`.
Compared the target and rendered desktop screenshot together. Preserved the four
flat summary metrics, chart/origin split, three-row attention table and bottom
observations strip. Used the existing shell typography, shared table headers and
controls. Deliberate changes: explicit complete-interval comparison wording,
coverage labels, neutral capture observation icon, expandable chart data and
recent activity. Production values come from retained data, not mockup numbers.

Evidence in `.playwright-mcp/dashboard/`:
- `overview-desktop.png`: existing dummy preview with unavailable prior history.
- `overview-comparison.png`: isolated richer dummy fixture, paired current/prior bars.
- `overview-mobile.png`: full mobile comparison page at 390px wide.
- `overview-empty-mobile.png`: empty last-hour chart with current open groups.

Browser checks: desktop 1440×1100 and mobile 390×844, internal chart/table scrolling,
no page-wide mobile overflow, all measured controls 38px, origin drill-down,
chart data disclosure, current partial row's previous value unavailable, 1h/24h/
7d/30d filter projections, relative dates and native exact-time titles. No captured
console warnings/errors. Reset viewport and left the richer isolated preview at
`http://127.0.0.1:8286/telescope-monitor/overview`; original preview remains on 8195.
Both use dummy data; no notifications sent. Fresh dark-mode screenshot was not
captured in this pass; new colors use the existing theme variables.

Validation: production typecheck/build passed; 24 frontend tests passed; full PHP
suite passed (327 tests, 1,978 assertions, 20 environment-dependent skips); serial
PHPStan passed; targeted formatting check and `git diff --check` passed. The normal
parallel PHPStan command could not bind its local worker socket in the sandbox,
so the serial debug invocation was used successfully. Design detector reported
only intentional dashboard type sizes, now documented in DESIGN.md. Tailwind-only
source constraints remain covered by the frontend suite.

final result: passed
