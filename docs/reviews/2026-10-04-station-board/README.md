# Timetable station-board study

Working alternative: `/dev/design/departures-next/station.html?view=timetable`.
Baseline remains at `/dev/design/departures-next/?view=timetable`.

The owner rejected the earlier flat table. This alternative uses a featured next
captured service, direct direction choices for small sets, and chronological
later services within one desktop board. Phones use a compact featured departure
above the continuation. Existing cream/Lagoon tokens, header, Composer and
planning semantics remain shared.

Checked in Chrome at 1440 × 900, 390 × 844 and 320 × 740:

- Direction selection updates the next service and preserves keyboard focus.
- Many-direction stops retain line/direction selectors; long destinations wrap.
- Mobile details open the existing sheet and restore focus on close.
- Planning retains the chosen starting stop and exact departure time.
- A 23:59 start displays the next day's date beside the 00:23 departure without
  displacing its destination in the phone grid.
- Desktop details expand in context; no browser console errors were captured.

Automated verification: 214 Travel/connected tests pass, both startup tests pass,
shared header output matches, and touched code passes formatting checks.

The data is a labelled historical capture. No live routing, countdowns, platform
facts or new departures are implied. This composition is for owner review, not a
production rollout.
