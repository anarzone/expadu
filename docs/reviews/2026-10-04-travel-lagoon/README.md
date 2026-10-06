# Travel — White & Lagoon, 4 October 2026

Owner decision: “lagoon option” and “side by side design choice”, following the
10-direction Travel study. Applied only to `prototype/dev/design/departures-next/`.

## Implemented

- White canvas/header/route rows; very pale cool input grouping; lagoon route and
  selection accents; orange primary actions. Uses the selected gallery values.
- Desktop: editable planner left, compact route options right. The form remains
  alongside results after a search. A thin divider connects the workspace.
- Phone: stacked form, then editable journey summary and compact route list after
  searching. Timetable retains compact departure rows. Desktop stop controls sit
  beside the board.
- One inline route disclosure at a time, with full boarding/walking details,
  Map and Review in Composer. Collapsed panels are inert and hidden from assistive
  technology; controls retain explicit expanded state.
- Underline/disclosure transitions, route-line reveal and save feedback; existing
  reduced-motion handling retained, with matching CSS overrides for new effects.
- Existing navigation map, saved journeys, schedules, preferences and Composer
  handoffs retained. Ask Composer opens the existing composer when no destination
  is chosen. Historical examples stay labelled and never become live routes.

## Verification

100 focused Node tests passed (37 Travel tests plus 63 shared handoff, store,
model, calendar, time, motion and journey-session tests). JavaScript syntax and
Prettier checks passed for all changed HTML/CSS/JS/test files.

Browser checks: 1440px and 1280px desktop, 390px and 320px phones. No horizontal
page overflow in the tested planner/results/timetable views. Desktop retains its
form after searching; mobile collapses it and can reopen it for editing.

Exercised sample route expansion/collapse, switching details, Map and return,
Save and Saved journeys, timetable, and Composer handoff. The handoff preserved
“Keep my existing request” and the exact captured Line 9 journey (see JSON).
A request to arrive on 19 September at 14:00 correctly produced an unavailable
live-route state. Exploring the 7 September capture and returning restored its
original endpoints and 19 September arrival requirement.

Text contrast checks all exceed 4.5:1 (see `contrast.json`). New motion uses
220ms disclosure/selection and 320ms route-line reveal; reduced-motion rules remove
these effects. No page runtime errors were reported during the final review.

## Evidence

- `desktop.png`: actual Travel prototype at 1440 × 900.
- `phone.png`: identical, isolated browser fixture at 390 × 844.
- `composer-handoff.json`: visible QA proof of the existing handoff contract.
- `contrast.json`: chosen token values and measured text contrast.
- `before/`: local snapshots of the four implementation files before this change.

The ten-option gallery is preserved at `/dev/design/travel-lab/`. The real Laravel
application, Today and other departments were not edited by this implementation.
