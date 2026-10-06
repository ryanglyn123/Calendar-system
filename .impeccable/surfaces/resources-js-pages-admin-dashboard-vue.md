---
version: 1
slug: "resources-js-pages-admin-dashboard-vue"
primary_target: "resources/js/pages/admin/Dashboard.vue"
related_targets: ["app/Services/Admin/AdminDashboard.php"]
---

# Admin desk (/admin)

Mode: Operate. Users: board-room and vehicle approvers (daily, several times a day), division admins (sign-ups), super admins (everything). Job: clear decisions waiting on me, see how rooms and vehicles are used today and on busy days ahead, notice account/announcement/system issues. Panels render only for people allowed to act on them. Constraints: inherits the app world (PRODUCT.md), no stock starter-kit cards/PageHeader, light default + night-glass dark, desktop-first but works at 390px.

## Direction contract

THESIS: The admin desk is FDCP's daily call sheet: one dated sheet saying what waits on your signature, who has which room or vehicle when, and which days ahead are heavy. It refuses the category default of KPI tiles over a chart card over a table card.

OWN-WORLD: The app's world unchanged (Poppins, warm-gray canvas, white panel, near-black pills, pastel resource hues with hue-matched ink, night glass in dark). Call-sheet grammar on top: hairline-ruled sections instead of cards, sentence-case section titles with a muted count, tabular times, the schedule as ruled lanes under an hour ruler, pending holds hatched in the resource hue, approved bookings as solid washes, conflicts outlined in destructive red. No card grid, no big-number tiles.

STORY: An approver opens the desk, sees the date and the decisions waiting, approves a clean request in one click or rejects inline with a reason, sees clashes before deciding, then scans today's lanes and jumps to a busy day from the two-week strip. Admins with wider permissions read the notes at the foot (people, announcements, activity).

FIRST VIEWPORT: Top-left the date as the page title (2xl semibold) with "Admin desk" beneath; top-right a system-status line when the user may see health. Then "Waiting on you" as ruled rows (filter tabs by kind, five rows then expand) with Approve as the black pill at the row's end. Below, "Schedule" with its own day stepper, lanes grouped by resource type, a now-line on today.

FORM: Call sheet, position 6 of 7 on the ranked list (1 queue+board split, 2 board-first dispatch, 3 triage inbox, 4 morning briefing, 5 two-week horizon, 6 call sheet, 7 signal ledger). Seed key d0dcdd68. Signature interaction: hovering or focusing a queue row lights its hold on the schedule; the two-week strip is the schedule's date scrubber.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
