---
name: FDCP Calendar System
description: Soft-pastel booking and events calendar for FDCP staff; neutral chrome, color carried by content.
colors:
  canvas: "oklch(0.958 0.003 95)"
  panel: "oklch(0.993 0.002 95)"
  ink: "oklch(0.22 0.008 90)"
  ink-pill: "oklch(0.24 0.012 90)"
  ink-pill-label: "oklch(0.985 0.002 95)"
  muted-surface: "oklch(0.945 0.004 95)"
  muted-ink: "oklch(0.43 0.012 90)"
  hairline: "oklch(0.907 0.004 95)"
  input-stroke: "oklch(0.885 0.005 95)"
  mint-accent: "oklch(0.938 0.035 155)"
  mint-accent-ink: "oklch(0.31 0.045 155)"
  destructive: "oklch(0.55 0.18 27)"
  success: "oklch(0.58 0.11 160)"
  warning: "oklch(0.66 0.14 65)"
  warning-ink: "oklch(0.5 0.115 62)"
  night-field: "oklch(0.235 0.028 258)"
  night-panel: "oklch(0.275 0.03 257)"
  night-blue: "oklch(0.57 0.18 254)"
  night-warning-ink: "oklch(0.83 0.13 75)"
  event-lemon: "#EFD95F"
  event-apricot: "#F5CD7E"
  event-lavender: "#BFA8F5"
  event-pink: "#F5A6D8"
  event-sky: "#86C6F5"
  event-mint: "#86DBAE"
  event-coral: "#F7A79B"
  event-violet: "#B29AF5"
  event-unset: "#d4d8dd"
typography:
  headline:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 600
    lineHeight: "2rem"
    letterSpacing: "-0.025em"
  title:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "15px"
    fontWeight: 600
    letterSpacing: "-0.025em"
  body:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: "1.25rem"
  body-dense:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "13px"
    fontWeight: 400
  label:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    fontFeature: "tnum"
  ruler:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "11px"
    fontWeight: 400
    fontFeature: "tnum"
rounded:
  hatch-label: "3px"
  block: "6px"
  sm: "6px"
  md: "8px"
  lg: "10px"
  xl: "12px"
  tile: "14px"
  panel: "1.1rem"
  pill: "9999px"
spacing:
  row-y: "12px"
  section-gap: "24px"
  page-x-sm: "16px"
  page-x-md: "24px"
  page-x-lg: "32px"
  panel-inset: "8px"
components:
  button-primary:
    backgroundColor: "{colors.ink-pill}"
    textColor: "{colors.ink-pill-label}"
    typography: "{typography.body-dense}"
    rounded: "{rounded.pill}"
    padding: "0 14px"
    height: "32px"
  button-quiet:
    backgroundColor: "transparent"
    textColor: "{colors.ink}"
    typography: "{typography.body-dense}"
    rounded: "{rounded.pill}"
    padding: "0 14px"
    height: "32px"
  button-quiet-hover:
    backgroundColor: "{colors.muted-surface}"
  button-danger:
    backgroundColor: "{colors.destructive}"
    textColor: "{colors.ink-pill-label}"
    rounded: "{rounded.pill}"
    padding: "0 14px"
    height: "32px"
  icon-button:
    textColor: "{colors.muted-ink}"
    rounded: "{rounded.pill}"
    size: "32px"
  filter-tab:
    textColor: "{colors.muted-ink}"
    typography: "{typography.body-dense}"
    rounded: "{rounded.pill}"
    padding: "0 10px"
    height: "28px"
  filter-tab-active:
    backgroundColor: "{colors.ink-pill}"
    textColor: "{colors.ink-pill-label}"
  input-text:
    backgroundColor: "{colors.canvas}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "8px 12px"
  main-panel:
    backgroundColor: "{colors.panel}"
    rounded: "{rounded.panel}"
  popover:
    backgroundColor: "{colors.panel}"
    rounded: "{rounded.xl}"
    padding: "16px"
    width: "320px"
  empty-note:
    backgroundColor: "{colors.muted-surface}"
    rounded: "{rounded.xl}"
    padding: "16px"
  event-block:
    typography: "{typography.label}"
    rounded: "{rounded.block}"
    padding: "0 8px"
---

# Design System: FDCP Calendar System

## Overview

**Creative North Star: "The Quiet Call Sheet"**

A working calendar for a government film agency: a warm-gray canvas, one floating white panel, near-black ink, and pastel color that belongs only to the things being scheduled. Chrome stays neutral so a room's or event's hue is the loudest thing on screen, and it always means something. The film heritage stays quiet: frames, lanes, a now-line, never spectacle.

Density is office-grade. Rows are 13-14px Poppins with tabular times, sections are divided by hairlines rather than boxed, and the primary action is a black pill (a blue pill at night). Light is the default working context. Dark is "night glass": a slate-navy field with blue glows, frosted panels, and the same pastel hues turned into tinted glass.

Motion is calm and short (150-200ms color and opacity, a 0.8s eased entrance on auth only) and every animation is gated on `prefers-reduced-motion`; frosted glass drops to an opaque panel under `prefers-reduced-transparency`.

**Key Characteristics:**
- Neutral chrome, pastel content: color is data, not decoration.
- One floating rounded panel over a soft brand wash.
- Pills for every action; the filled one decides.
- Hairline-ruled sections and rows instead of nested cards.
- Tabular numerals for every time, count, and date.
- Two themes that share structure: paper by day, night glass by dark.

## Colors

A near-achromatic warm-gray system (hue 90-95, chroma under 0.015) with a pastel event palette as the only saturated color in light mode.

### Primary
- **Pill Ink** (ink-pill): the primary action, selected day, active filter tab, active nav pill. In dark it becomes **Night Blue** (night-blue), the single accent for actions, today, and the active nav item.

### Tertiary
- **Pastel event hues** (event-lemon, event-apricot, event-lavender, event-pink, event-sky, event-mint, event-coral, event-violet): the preset palette for event types, calendars, rooms, and vehicles. Rendered as a soft vertical wash (the hue mixed 84% with white at top, settling into itself) with hue-matched dark ink computed from luminance. **Unset Gray** (event-unset) stands in when a resource has no color.
- **Mint Accent** (mint-accent / mint-accent-ink): the shadcn accent slot, used for subtle hover and highlight states in menus.

### Neutral
- **Warm Canvas** (canvas): page background and input fill.
- **Paper Panel** (panel): the main panel, popovers, dialogs.
- **Ink** (ink): body text and headings.
- **Muted Surface** (muted-surface): hover fills, empty-state notes, selected strip day.
- **Muted Ink** (muted-ink): secondary text, counts, hour ruler, icons at rest.
- **Hairline** (hairline): every section rule, row divider, and lane gridline.
- **Input Stroke** (input-stroke): inset ring on text fields.

### Status
- **Destructive** (destructive): reject buttons, conflict outlines, blocking errors.
- **Success** (success): healthy status dot, empty-queue check.
- **Warning** (warning): status dot fills only. **Warning Ink** (warning-ink; night-warning-ink in dark) is the amber darkened to hold 4.5:1 as text: urgent lead times, overlaps, capacity notes.

### Named Rules
**The Content Owns Color Rule.** Chrome (navigation, headers, buttons, rules) is neutral ink on gray. Saturated color appears only when it names an event, a calendar, or a resource, or reports status.

**The Ink-Not-Fill Warning Rule.** Amber as text always uses warning-ink; the brighter warning is a dot or bar fill, never a label color.

## Typography

**Display Font:** none; the system has no display face.
**Body Font:** Poppins (with ui-sans-serif, system-ui, sans-serif)

**Character:** One geometric sans at small, even sizes. Hierarchy comes from weight (400/500/600) and muted color, not from size jumps.

### Hierarchy
- **Headline** (600, 24px, tight tracking): page titles; on the admin desk, the date itself is the title.
- **Title** (600, 15px, tight tracking): section titles, sentence case, followed by a muted 14px tabular count. The calendar toolbar title runs at 18px.
- **Body** (400, 14px/20px): primary row text and form fields; item names at 500.
- **Body Dense** (400, 13px): row metadata, pills, filter tabs, notes. The working size of the product.
- **Label** (500, 12px, tabular): event block text, legends.
- **Ruler** (400, 11px, tabular): hour ruler ticks and weekday initials; 10px 600 only for the now-line time flag.

### Named Rules
**The Tabular Time Rule.** Every time, count, date number, and duration uses tabular numerals, in 24-hour `HH:mm–HH:mm` with an en dash.

**The Sentence Case Rule.** Titles and labels are sentence case. No uppercase tracked labels above headings.

## Layout

The app shell is a transparent sidebar over a fixed brand wash, with the main content in one opaque rounded panel inset 8px from the viewport (4px on the sidebar side). Page content sits in a centered column up to 1400px, padded 16px / 24px / 32px at mobile / sm / lg, with 24px between top-level sections.

Sections are stacked vertically and separated by a full-width hairline with 16px above the title row and 12px below it. Lists are ruled rows (hairline top and bottom, dividers between) with 12px vertical padding and 8px horizontal. Wide rows become a four-column grid at md (what, when, who, decide) with actions right-aligned; below md they stack.

Schedules are ruled lanes: a fixed name column (10.5rem, 13rem at lg) and a time track with hourly hairline gridlines on a faint muted fill, under an 11px hour ruler. Below sm, lanes collapse into a ruled list per resource type. Desktop-first; everything holds at 390px.

## Elevation & Depth

Flat by default. Depth comes from the one floating panel, tonal fills, and hairlines. Shadows are soft, low, and cool-tinted; nothing is lifted on hover. In dark, depth is glass: frosted panels (blur 24px) over glowing blue fields, with 1px inner highlights.

### Shadow Vocabulary
- **Panel rest** (`box-shadow: 0 1px 2px oklch(0.3 0.03 250 / 0.06), 0 0 0 1px oklch(0.3 0.03 250 / 0.04)`): the main panel in light.
- **Popover** (`box-shadow: 0 12px 32px -12px oklch(0.25 0.02 250 / 0.35), 0 0 0 1px var(--border)`): floating detail cards.
- **Active nav glow** (`box-shadow: 0 8px 22px -8px color-mix(in oklch, var(--sidebar-primary) 80%, transparent), inset 0 1px 0 oklch(1 0 0 / 0.22)`): the active nav pill glowing in its own ink.
- **Night panel** (`box-shadow: inset 0 1px 0 oklch(1 0 0 / 0.06), 0 0 0 1px oklch(0.9 0.02 255 / 0.1), 0 24px 60px -24px oklch(0.12 0.04 262 / 0.7)`): the frosted main panel in dark.

### Named Rules
**The One Panel Rule.** There is one raised surface per screen, the main panel. Content inside it is ruled, not boxed; only popovers and dialogs float above it.

**The Glass At Night Rule.** Frosted glass is the dark theme's material and is limited to the main panel, dialogs, menus, and month tiles. Light mode has no glass except the sidebar nav rail.

## Shapes

Soft and consistent. Every action is a full pill (9999px), including nav items, filter tabs, and icon buttons. Containers step up in radius with size: event blocks 6px, fields 8px, base 10px, popovers and empty notes 12px, dark month tiles 14px, the main panel 1.1rem. Borders are 1px hairlines, usually drawn as inset rings so they never shift layout. Focus is a 2px ring at 60% ring color, offset 2px from the panel on filled pills.

## Components

### Buttons
Pills, all 32px tall, 13px 500, 14px side padding, 14px icons with 6px gap.
- **Shape:** full pill (9999px).
- **Primary:** Pill Ink fill, light label; hover drops to 85% opacity. One per row or form; it carries the decision (Approve, Create).
- **Quiet:** transparent with an inset hairline ring and ink at 80%; hover fills Muted Surface. Secondary actions (Reject, Cancel, Today).
- **Danger:** Destructive fill; only as the confirm step of a destructive action, never at rest in a list.
- **Icon button:** 32px round, muted ink, hover fills Muted Surface. Steppers and toggles.
- **Disabled:** 45% opacity, no pointer events.

### Chips / Filter tabs
- **Style:** 28px pills, 13px, muted ink with a 70%-opacity tabular count.
- **State:** active tab is the Pill Ink fill; inactive hovers to Muted Surface. Exposed as `aria-pressed` toggles.

### Cards / Containers
- **Corner Style:** main panel 1.1rem; popovers 12px.
- **Background:** Paper Panel; empty-state notes use Muted Surface at 50% with 16px padding.
- **Shadow Strategy:** see Elevation; nothing inside the panel carries a shadow.
- **Border:** hairline, as inset ring.

### Inputs / Fields
- **Style:** Warm Canvas fill, inset 1px Input Stroke ring, 8px radius, 8px 12px padding, 14px text. Compact date fields are 32px pills with the same ring.
- **Focus:** ring thickens to 2px at 60% ring color.
- **Error:** ring turns Destructive; message below in 13px Destructive.

### Navigation
Sidebar nav is a floating rail (24px radius, translucent white, soft shadow) holding compact 36px pill items (28px round icon well, 13px labels) in a 14rem sidebar. Active item is a solid Pill Ink pill with its own glow; in dark it is Night Blue. Collapses to an 80px rail of 36px icon circles.

### Event wash (signature)
Events and approved bookings render as a pastel vertical wash with hue-matched dark ink, 6px radius, 12px 500 text, a tabular time prefix shown only when the block is wide enough. In dark the wash becomes tinted glass (the hue at 24% over the panel with a 1px hue ring); timed month chips drop the fill for a 2px color tick.

### Hold (pending) block
A pending booking uses the resource's own hue, hatched at 135deg (2px stripe, 5px gap) over a 22% tint, with a 1px hue ring; labels sit on a flat 3px-radius patch so stripes never cross text. The same hatch in neutral ink marks waiting time in aggregate bars. Conflicts add a 2px Destructive outline. Readable without a legend and in grayscale.

### Ruled section (admin desk)
A hairline across the page, then a title row: 15px 600 title with muted count left, controls right, minimum 32px tall. Body is ruled rows. Hovering or focusing a queue row spotlights its block on the schedule (others drop to 35%, the target gets a 2px ink ring).

### Now-line
A 2px ink vertical rule across the schedule with a 10px tabular time flag on an ink tab (4px radius).

### Booking section (Boardroom, Carpool)
Drawn around the things being booked: one ruled row per room or vehicle, never several resources squeezed into one day column. The header reads as two lines. The context line (13px, muted) carries the type, a resource filter, a live "N of M free now" with a status dot, and on the right whatever waits on the viewer (a quiet "Review" pill with an ink count chip for reviewers, the same as the header's, and "Your bookings" for requesters). The period line makes the date the 24px headline (it opens a date picker), with steppers and Today beside it; the view switch (Day, Week, Month) is a muted segmented track whose active segment is the panel color, so the ink pill stays reserved for the one primary action ("Book a room", "Book a vehicle").
- **Day:** hour lanes over office hours (07:00–19:00, stretched to fit), 40px blocks with title over `HH:mm–HH:mm · who` ("You" for the viewer's own). Elapsed time is veiled; hovering open time shows a dashed one-hour ghost, and a click books that resource at that time.
- **Week:** seven cells per row, each with a 6px office-hours occupancy strip (neutral solid booked, neutral hatch waiting) above up to three chips; trips over several days read `08:00 →` and `→ 17:00`.
- **Narrow containers** (under 42rem, decided by the schedule's own width): the focused day as a list per resource, with a seven-day strip and tappable "Free HH:mm–HH:mm" gaps.
- Both open on Week by default; a view the viewer picks is remembered per type and role. Other people's bookings are neutral gray for staff, so the viewer's own keep their hue.

## Do's and Don'ts

### Do:
- **Do** keep chrome neutral and let event, calendar, and resource hues carry all saturated color.
- **Do** make the one deciding action a Pill Ink pill (Night Blue in dark); everything else is quiet.
- **Do** separate sections with hairlines and rows with dividers inside the single main panel.
- **Do** set every time, count, and date in tabular numerals, 24-hour, en dash ranges.
- **Do** use warning-ink for amber text and reserve warning for dots and bars.
- **Do** mark pending holds with the 135deg hatch in the resource hue, approved with the solid wash, conflicts with a 2px Destructive outline.
- **Do** gate every animation on `prefers-reduced-motion` and drop glass under `prefers-reduced-transparency`.

### Don't:
- **Don't** use vivid yellow, AI-purple gradients, or neon-on-dark as chrome (confirmed anti-references in PRODUCT.md).
- **Don't** put glass on light-mode surfaces beyond the nav rail.
- **Don't** introduce a display face, uppercase eyebrow labels above headings, or size jumps larger than the 24px headline.
- **Don't** raise content inside the main panel with shadows or nest cards within it.
- **Don't** use raw FDCP brand colors (blue, magenta) for chrome such as badges, links, or banners.
