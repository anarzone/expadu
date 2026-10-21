# Travel: the combined design, mock first then app

Approved by the owner on 10 October 2026 from the "Travel directions" canvas
(https://claude.ai/artifact/Bso2EL9T2RUywJJT48SEet): rows 4–6 (phone) and 7–9
(desktop). Order: the Travel mock (`prototype/dev/design/departures-next/`)
first, then the Laravel/React Travel page.

## Rules that carry over

- Follow the mock's structure and design language: shared header, `connected/tokens.css`
  colours and roles, one breakpoint set (≤359, ≤700, 701–999, ≥1000, ≥1500),
  44px targets, the 1120px desktop column with underline view tabs, and
  hairline-divided columns instead of floating cards.
- `design-decisions.md` and `tokens.css` are not edited without the owner's
  approval; the mock's own README records the change.
- Data honesty: the mock uses only the sample day (`connected/sample-day.js`)
  and the captures it already has (4 Oct Neumarkt board, 7 Sept Merkenich and
  routes). Illustrative times stay on the canvas. Where the canvas showed one
  (tram 18 at 13:16, S11, replacement bus, bike, taxi), the mock says that
  way's times aren't in the sample.
- Mobile first: the first useful item and its action are visible at 390×844,
  nothing sits under the Composer button on the first screen, no sideways
  scroll, reduced motion respected.
- Commit each confirmed slice on its own; stage only its files; no push.

## Slices (mock)

1. **Nearby replaces Timetable.** The closest station (Neumarkt, 4 Oct capture)
   grouped by KVB boarding areas; line cards that open in place (phone) or in
   the side panel (desktop); filters (type, lines, leaving via, next N min,
   delayed, step-free) opening in place; stop picker with the Merkenich
   captures; map with area labels (phone List | Map, desktop beside the list).
   Desktop: tabs Nearby · Plan a trip. Phone: "Where to?" + Nearby | My day.
   Old `?view=timetable&stop=` links keep working.
2. **Your day and the next trip.** Desktop "Your day" row; the next-trip band
   from the sample day (13:14 tram 4, 12 min spare, Today's rule) with Other
   ways (the 13:24) and Later today; phone next-trip card.
3. **Plan a trip.** Where and when, what matters to you, "We checked today's
   conditions" (departures, KVB notices, weather, your day, lifts: each says
   what the sample has), options from the sample day re-ranked by preference,
   saved and watched.
4. **Trip mode (Moments).** Walk, at the stop, on board ("Nächster Halt"),
   get off, arrived; replaces the preview's step UI while keeping its session,
   resume bar and Composer hand-off.
5. **When things go wrong.** A footer Review menu: cancelled, missed, long wait
   (Merkenich capture), lift out of order, live times paused — each labelled
   sample, using only sample or captured times.
6. **My day (phone).** The day river.
7. README, tests, browser checks at 360/390/800/1440.

## Then the app

Map each slice onto `resources/js/pages/timetable.tsx` and the departures
components with real data (VRS GTFS-RT delays, KVB notices and lift outages,
boarding points per departure if the feed gives them), keeping the same
structure.
