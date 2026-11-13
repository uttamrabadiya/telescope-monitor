---
name: Telescope Monitor
description: Telescope light interface for the Phase 1 monitoring foundation.
colors:
  primary: "#4040c8"
  primary-hover: "#3333b5"
  canvas: "#f3f4f6"
  surface: "#fff"
  text: "#111827"
  muted: "#626b7a"
  nav-text: "#4b5563"
  section-text: "#374151"
  border: "#e5e7eb"
  control-border: "#cfd4df"
  telescope-control-border: "#d1d5db"
  telescope-control-hover: "#9ca3af"
  control-border-hover: "#a6adbd"
  panel-border: "#dde0e8"
  shell-border: "#d8dbe2"
  divider: "#dce0e8"
  nav-hover: "#e9eaf0"
  nav-active: "#e2e4eb"
  section-active: "#eeedff"
  subtle-surface: "#f7f8fa"
  button-hover: "#f9fafb"
  policy-bg: "#efedff"
  policy-border: "#d6d0ff"
  policy-text: "#60658a"
  policy-tag: "#ddd8ff"
  policy-icon-divider: "#c8c1f7"
  focus: "#7777df"
  success-bg: "#eef7f0"
  success-border: "#c9dece"
  success-text: "#22683a"
  warning-bg: "#fff8eb"
  warning-border: "#f2d4a4"
  warning-text: "#92400e"
  danger-bg: "#fff1f2"
  danger-border: "#f1bec3"
  danger-text: "#a51d32"
  error-text: "#b91c1c"
  badge-border: "#d1d5df"
  passed-bg: "#dff4e9"
  passed-text: "#047857"
  failed-bg: "#fee2e2"
  warning-badge-bg: "#fff2db"
  avatar-bg: "#dfe2e8"
  app-name-divider: "#cfd3dc"
  task-border: "#d8dde7"
  step-border: "#a7aebf"
  step-active: "#ebecf5"
  summary-border: "#e0e3e9"
  selection-bg: "#dcdcff"
  selection-text: "#202084"
  scrollbar: "#c6cbd5"
typography:
  headline:
    fontFamily: "Figtree, sans-serif"
    fontSize: "32px"
    fontWeight: 600
    lineHeight: 1.25
    letterSpacing: "-0.025em"
  title:
    fontFamily: "Figtree, sans-serif"
    fontSize: "24px"
    fontWeight: 600
    lineHeight: 1.4
  body:
    fontFamily: "Figtree, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "Figtree, sans-serif"
    fontSize: "15px"
    fontWeight: 500
  help:
    fontFamily: "Figtree, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.6
rounded:
  control: "5px"
  container: "6px"
  round: "50%"
spacing:
  space-4: "4px"
  space-6: "6px"
  space-7: "7px"
  space-8: "8px"
  space-10: "10px"
  space-12: "12px"
  space-14: "14px"
  space-16: "16px"
  space-18: "18px"
  space-20: "20px"
  space-22: "22px"
  space-24: "24px"
  space-28: "28px"
  space-30: "30px"
  space-34: "34px"
  space-40: "40px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.surface}"
    rounded: "{rounded.container}"
    padding: "9px 16px"
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.container}"
    padding: "9px 16px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.control}"
    padding: "10px 13px"
  navigation-active:
    backgroundColor: "{colors.nav-active}"
    textColor: "{colors.primary}"
    rounded: "{rounded.container}"
    padding: "11px 16px"
  settings-navigation-active:
    backgroundColor: "{colors.section-active}"
    textColor: "{colors.primary}"
    rounded: "{rounded.control}"
    padding: "11px 14px"
  policy-tag:
    backgroundColor: "{colors.policy-tag}"
    textColor: "{colors.primary}"
    rounded: "{rounded.control}"
    padding: "2px 10px"
  panel:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.container}"
  policy-notice:
    backgroundColor: "{colors.policy-bg}"
    textColor: "{colors.text}"
    rounded: "{rounded.container}"
    padding: "16px 20px"
---
# Design System: Telescope Monitor

## Overview

**Creative North Star: "Telescope Light"**

The accepted Telescope light direction uses Figtree, indigo actions, a pale gray application canvas and white grouped work surfaces. The interface gives settings, host policy and operational readiness clear labels and generous separation. It uses icons as functional cues; there are no photographic or illustrative assets.

This document records the implemented Phase 1 foundation only: the Vue application shell, setup, persisted preferences, privacy preview, access information and configuration health. Overview reports readiness. Errors, alert rules, integrations, exception capture, evidence pruning and assignments are not connected in this build. Their navigation and explanatory states are not evidence of working workflows.

The source of truth is `resources/css/app.css`, `resources/js/App.vue` and the current settings components. The approved visual reference is `docs/design/mockups-v1/settings.png`; the reviewed desktop and mobile implementation captures are `foundation-settings-reviewed-desktop.png` and `foundation-settings-reviewed-mobile.png` from the current design review. Review disposition: ship for Phase 1 only. PRODUCT.md describes broader product intent and still contains historical implementation status; this document does not promote that intended scope to shipped behavior.

**Key Characteristics:**

- Telescope light palette and locally bundled Figtree.
- Persistent application navigation with a distinct settings section rail.
- Flat white work surfaces, modest radii and restrained borders.
- Explicit host-managed policy, draft state and unavailable capabilities.

## Colors

The palette combines cool neutral surfaces with a single indigo action family. The frontmatter records the literal source values; component custom properties are defined on `.tm-root` (`--primary`, `--border`, `--surface`, `--muted`).

### Primary

- **Telescope Indigo** (`primary`): primary buttons, selected navigation text, checkbox accent, caret and brand mark. `primary-hover` darkens primary buttons.
- **Policy Lilac** (`policy-bg`, `policy-border`, `policy-tag`): identifies deployment policy. `section-active` highlights the current settings section.

### Neutral

- **Cool Canvas** (`canvas`): page shell, sidebar and disabled inputs. **White Surface** (`surface`): settings work area, panels and editable inputs.
- **Ink** (`text`): headings, labels and primary content. **Readable Slate** (`muted`): helper text, footer and unavailable-state details. Navigation uses `nav-text` or `section-text`.
- Border tokens distinguish controls, panels, shell separators and policy notices. Active main navigation uses a gray fill with indigo text; active settings navigation uses lilac.

Muted text is deliberately `#626b7a`, adjusted from the approved mockup's `#6b7280` to achieve approximately 4.89:1 contrast on the `#f3f4f6` canvas. Preserve that accessibility correction.

Success, warning and danger notice tokens communicate results and failures with accompanying text. Passed, failed, warning and unverified health badges carry literal labels; configuration checks must not imply verified runtime processing.

## Typography

Figtree is used throughout with `sans-serif` fallback. Latin weights 400, 500 and 600 are imported locally through `@fontsource/figtree` in `resources/js/main.ts` and bundled with release assets. There is no separate display or icon font. Heroicons render as SVG.

- Page heading: 32px / 1.25, weight 600, tracking −0.025em; 26px at 600px and below.
- Settings section heading: 24px / 1.4, weight 600; 21px on mobile. Base h2 is 21px; h3 is 18px.
- Body: 16px / 1.5, weight 400. Page subtitles use 16px, reduced to 14px on mobile.
- Buttons: 15px / 1.4, weight 500; 14px on mobile. Inputs remain 16px.
- Field labels: 15px / weight 500; 17px in desktop settings above 1150px and 14px on mobile.
- Help: 14px / 1.6. Field help, validation and unsaved labels use 13px; footer uses 12px, reduced to 11px on mobile.
- Desktop settings checkbox labels use 18px; default checkbox labels use 16px and mobile uses 14px.

## Layout

The application header is 64px tall. Above 1150px, the main grid has a 256px sidebar and flexible content. Main content has 30px top, 24px horizontal and 22px bottom padding. Settings spans the content width as a white surface with 28px 24px 40px padding, a 220px section rail, 24px gap and a vertical divider. The form body is capped at 1100px. The settings surface has a 610px minimum height; it is not a floating card.

At 1150px and below, the application sidebar becomes 205px and the settings rail 165px with 20px gap. At 850px and below, main navigation becomes a wrapping horizontal strip, settings becomes one column with a wrapping section nav and bottom divider, and main gutters become 20px. At 600px and below, the application nav is hidden until the labeled menu toggle is opened; it then stacks vertically. Main gutters become 16px; application name and avatar are hidden. Settings section links continue wrapping, fields stay full width and actions wrap.

Desktop excluded-evidence categories form two equal columns with a divider. Smaller layouts wrap them naturally. Setup uses a step rail beside content, becoming wrapped steps above stacked content on mobile. Tables use a horizontal scroll wrapper. Form controls and text regions use `min-width:0` to fit the flexible shell.

Spacing is practical rather than a uniform mathematical scale: field groups use 22px vertical margins; notice and checkbox blocks use 18–25px separation; actions use 12px gaps and a 28px top margin (40px in large-screen settings).

## Elevation & Depth

Depth comes primarily from the pale canvas, white work surface, tonal selected states and one-pixel borders. The `.panel` shadow is `0 2px 3px rgb(17 24 39 / .025)`; settings itself has no shadow. Policy notices are tinted, bordered inline regions. There are no floating decorative cards or animated surface lifts.

The keyboard focus treatment is a 3px solid `focus` outline with 3px offset. Loading indicators rotate for 1 second linearly and infinitely; reduced-motion preference disables the rotation. Other state changes are immediate: no transition durations or easing tokens are implemented.

## Shapes

Controls, badges and settings links use 5px corners. Buttons, panels, notices and main navigation use 6px corners. Brand mark, avatar and setup-step markers are circles. Borders are predominantly 1px solid. Native checkboxes retain platform appearance with indigo accent; textareas resize vertically.

## Components

### Buttons

Primary saves use indigo fill with white text, 9px 16px padding, 6px radius and 40px minimum height (42px on mobile). Hover darkens the fill. Secondary actions are white with control borders; hover uses a pale fill and stronger border. Disabled buttons show a not-allowed cursor and 0.55 opacity. Text actions are underlined indigo links. Icon buttons have 6px padding and 20px SVGs. All interactive controls retain the global visible focus outline.

### Inputs and checkbox rows

Text fields, selects and textareas use white backgrounds, control borders, 5px corners, 10px 13px padding and 42px minimum height. Textareas have 120px minimum height, or 130px in large-screen settings. Labels are stacked above controls. Deployment-locked fields use gray fills, muted text and lock explanations. Validation text is red; invalid display-name inputs have red borders. Checkbox targets are 18px by default and 22px in large-screen settings.

### Navigation

Main navigation pairs outline icons with labels; active rows use gray fill and indigo text. Hover uses `nav-hover`. Settings links use lilac active fill with medium-weight indigo text. The mobile toggle exposes `aria-expanded`, switches between menu and close icons and closes on navigation. A skip link becomes visible on focus. Settings draft guards apply on route leave, route update and browser unload.

### Policy notice and tags

The privacy form's lilac deployment-policy notice combines a lock icon, a compact policy tag, host-managed title and explanatory copy. Above 1150px the icon has a right divider and the text is 15px; mobile aligns the icon to the top, wraps the title and reduces supporting text to 13px. Excluded evidence is listed below the textarea with lock icons. Tags are status labels, not interactive filters.

### Panels, notices and health rows

Panels group setup, health and unavailable-state content. Health tables use pale header rows, 13px 16px cell padding and horizontal dividers, with text-bearing status badges. Notices use contextual tint, border and text colors plus status or alert semantics. The shell handles loading, bootstrap failure with retry, migration warnings and dismissible save feedback.

### Settings states and privacy preview

General, privacy and retention forms fetch current values, preserve drafts on failed saves, show field errors and provide explicit save actions. Saving changes the button label and disables it. A successful response refreshes the bootstrap and reports “Preferences saved.” Version conflicts expose a reload action that confirms before replacing the draft. “Unsaved changes” and navigation/unload confirmation protect edits.

Privacy preview is an optional inline section with draft-pattern input, an Apply preview action and a preformatted, wrapping live result region. It uses the current unsaved patterns. It does not save the sample. Retention copy states that pruning becomes available when capture is connected. Access information remains host-managed; assignments are explicitly unavailable.

## Do's and Don'ts

### Do:

- Do preserve the pale canvas, white work surfaces and indigo action hierarchy.
- Do use the implemented muted text token for helper text on both canvas and white surfaces.
- Do retain visible focus, form labels, save feedback and unsaved-draft protection.
- Do label readiness and unavailable capabilities honestly in Phase 1.

### Don't:

- Don't use the archived prototype sidecar as the current visual authority.
- Don't present stored retention or privacy preferences as proof that capture or pruning is operating.
- Don't replace host-managed policy with controls that imply the dashboard can broaden Telescope capture.
- Don't introduce decorative imagery or a new font family into this established foundation.


## Shared layout correction — 11 November 2026

The user supplied the installed Telescope interface as the authority for the shared container, page header and controls. This supersedes the full-width shell measurements above without changing the established light palette or product behavior.

The shell uses a centered 1440px maximum container with 16px horizontal gutters, an 88px desktop header, 210px navigation and a 30px content gap. At intermediate widths the sidebar becomes 180px with a 24px gap; below 850px navigation wraps horizontally and below 600px it uses the existing menu toggle. Mobile gutters are 12px. The top bar contains the product brand and environment; the application-name preview label and actor avatar are omitted. Host actor authorization remains unchanged.

Each route renders inside one white 6px-radius page surface with Telescope's soft downward shadow. Shared page titles are 20px inside its header, with a divider separating the body. Settings no longer uses negative margins to escape its container. Setup's outer panel is flattened into the page surface. Feedback and operational warnings remain outside the card, with the quiet footer below it.

Text fields and single selects use 38px height, 6px 12px padding, 16px Figtree, 4px corners and Telescope gray borders (#d1d5db; hover #9ca3af). Search fields use pill corners, matching Telescope's header search treatment. Textareas retain their readable minimum height; multiple selects retain at least 112px rather than collapsing to a single row. Checkbox/radio sizing is 16px. Disabled, invalid and visible keyboard-focus states remain explicit.

The whole application stylesheet detector also reports existing tab underlines and historical palette/type advisories. The 3px/4px borders are selected navigation indicators, not card accent borders. The two new control gray values and the page shadow intentionally follow the user's Telescope reference; they are not an invitation to restyle unrelated components.

## Telescope dark theme — 13 November 2026

The dashboard follows the host's `Laravel\Telescope\Telescope::$useDarkTheme`
flag on the server-rendered root element. `Telescope::night()` enables the dark
palette without adding a Monitor setting. The light palette and layout remain
unchanged. The source of truth for both palettes is `resources/css/app.css`.

Dark mode follows Telescope's gray-900 canvas (`#111827`), gray-800 surfaces
(`#1f2937`), gray-100 body text (`#f3f4f6`) and gray-400 helper text (`#9ca3af`).
Indigo links and active states use `#a5b4fc`; filled primary controls retain
`#4040c8` and white text. Borders, notices, status badges, code evidence, chart
fills/lines, focus, text selection, dropdowns and native controls use themed
colors. Theme selection is independent of OS preferences and local storage.
