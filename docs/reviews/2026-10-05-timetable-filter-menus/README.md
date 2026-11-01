# Travel timetable filter menus — 5 October 2026

Replaced the native Line, Direction and Service notices selects on both Travel
preview entry pages with the shared anchored popup. The accepted page layout
and warm paper/Lagoon palette are preserved. These remain sample timetables.

## Behaviour

- 44px triggers and options; cream surface, cyan selection and one checkmark.
- Selection applies immediately and returns focus to its trigger.
- Direction choices use captured service IDs and identify the line when needed.
- Invalid or stale choices cannot apply an unavailable service.
- Arrow keys, Home/End, Escape and natural Tab exit work.
- Menus close on outside focus or pointer interaction. No blocking backdrop.
- Long lists are limited to 420px or the available viewport; only their list
  scrolls. The close button stays visible, and reopening reveals the selection.

## Verification

- Browser checked at 1440×1000, 390×844 and 320×740. No horizontal overflow;
  popup bounds stay within the viewport; smallest-phone choices remain 44px.
- Merkenich Mitte → Zollstock filters all three captured departures, including
  the overnight service. Service notices at Neumarkt filter to six flagged rows.
- Selected Sürth at the bottom of Neumarkt’s long list, opened a shorter menu,
  then reopened directions: selected row visible and page scroll unchanged.
- Main Travel entry page: Line 16 produces the single captured departure and
  retains keyboard focus. No browser console errors in that check.
- Five added workspace regressions; updated two existing filter interactions.
- Travel, connected, Composer and stop-discovery suites: 390 passed.
- Changed JS, CSS and HTML formatting checked; JS syntax checked.

## Screenshots

- [Desktop direction menu](desktop-direction.png)
- [Phone direction menu](mobile-direction.png)
- [Desktop service menu](desktop-service.png)
