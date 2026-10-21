# Travel: departure filters and Map / Steps

4 October 2026. Connected prototype only; no React/Laravel files changed.

## Correct baseline

The initial local-app comparison was not sufficient because the working React
pages contained uncommitted redesigns. Refreshed `origin/staging` and re-read
original source at `89289db9641bb75a563e74b44be9b4717bd61b22` using `git show`.
No checkout, revert, commit, push or deployment was performed.

Original staging provides adaptive All/Tram/Bus/Train tabs, direction lanes,
six initial split-flap rows, source-based Live/Schedule labels and 30-second
refresh. Main journey planning provides transport toggles and Best/Fastest/
Fewest changes/Direct/Least walking preferences. Its live text guidance advances
from foreground GPS or scheduled time; its route map does not track live user
or vehicle positions. Current local Transit/Cycle/Walk tabs, shared calendar and
expandable timetable rows are not evidence of the original design.

## Changes

- Direct All/Tram/Bus controls, limited to types present in captured records.
- Optional inline Line, Direction and Delay notices; active-count indicator,
  keyboard focus retention, clear/reset and honest empty results.
- Station study starts across captured stops and names every boarding stop.
- Map / Steps uses one journey and progress state; Steps can open without a map.
- View preference and trip progress survive minimizing and reload separately.
- Selecting a later departure outside the rendered page reveals it, opens its
  details and scrolls it into view without resetting the filters or clock.
- Updated entry asset versions; shared header and Composer startup retained.

## Verification

- 224 Travel and connected Node tests passed before the final focus-only change;
  focused Travel checks rerun afterward (see recorded command output).
- Two Python startup tests and prerendered-header consistency check passed.
- Formatting checks passed for affected scripts, styles, markup and tests.
- Chrome desktop 1440×1000: type changes, optional filters, delayed Bus empty
  recovery and exact overnight departure expansion checked.
- Chrome 390×844 and 320×740: first departure/action visible, optional filters
  usable, no horizontal page overflow at 320 (305px content plus scrollbar).
- Chrome mobile Map → Steps → Map: next action remains step 2. Minimize, reload
  and resume returns to Steps at step 2. Desktop Steps and Map layout checked.
- The existing local review login was verified in a fresh HTTP session and
  Chrome; `/dev/review` opened `/dashboard`, then `/timetable`, without sign-in.
- No browser console errors were returned for the refreshed timetable.

## Limits

The preview still uses captured September data and manual progress. Live GPS,
vehicle positions, routing updates and automatic rerouting are not integrated.
Rail/accessibility/crowding filters are not offered without data. Real-app feed
and GPS integration remains outstanding. UI review does not certify live travel
accuracy.

Screenshots: `desktop.png`, `mobile.png`, `map-mobile.png`, `steps-mobile.png`.
