# Travel workspace and colour balance

3 October 2026. Review preview:
http://127.0.0.1:8765/dev/design/departures-next/

The owner authorised changes after reviewing desktop composition and the balance
between cyan and soft peach. This pass affects the Travel prototype only.

## Changes

- Join the planner and smaller sample journey on one soft peach surface. The
  sample becomes supporting content instead of the largest, most colourful card.
- Keep route summary, choices and the full itinerary in the same workspace.
  Highlight the selected desktop route with cyan and a teal edge. Replace the
  large cyan time summary with a connected teal line.
- Put desktop stop/time controls beside the timetable, shortening the distance
  between direction and departure time. Phones retain a single stacked board.
- Preserve white canvas/header, orange actions and the approved peach values.
  Cyan remains on selections, transport badges and route cues. Nested containers
  share their parent's surface instead of creating extra colour bands.
- Keep existing request, sample, map and Composer behaviour. This is a visual
  refinement, not live routing or a deployment.

Implementation: local `workspace.css`, its versioned entry link, one shorter
sample heading and two timetable wrappers in `index.html`. The shared styles,
Travel's existing palette file and `app.js` retain their preceding checksums.

## Verification

- Existing focused Travel Node suite: **34 passed, 0 failed** under Node 22.23.1.
- Planner visually reviewed at 320, 390, 820, 1440 and 1584 CSS pixels. No
  horizontal overflow observed. At 320 and 390, the 44px Find a route button ends
  at 564px, inside the initial phone viewport.
- Final desktop planner and sample widths: 484px and 467px, within one surface.
- Timetable reviewed at 320, 390 and 1584. Phone rows remain 56px high; desktop
  stop controls no longer force the notices button onto a detached row.
- Route results reviewed at 390 and 1584. Phone choices measure approximately
  99px high after preserving their previous compact spacing.
- Exercised sample exploration, editable request/suggestions, time popover,
  timetable switching, individual departure details and mobile map/itinerary
  access. No saved journey, activity, Composer message or preview was created.
- Computed palette: white canvas `#ffffff`, peach workspace `#fff5ef`, cyan
  selection `#dff3f8`, orange action `#d9340a`. Sample route, selected itinerary
  container and its time summary have transparent backgrounds.
- Formatting and whitespace checks pass. The preview is returned to the
  original Plan a trip form (Merkenich Mitte, destination empty); viewport
  overrides are reset.

## Screenshots

- [Desktop planner, 1440 × 900](planner-desktop.png)
- [Desktop timetable, 1584 × 990](timetable-desktop.png)
- [Desktop route results, 1584 × 990](results-desktop.png)
- [Phone planner, 390 × 844](planner-mobile.png)
- [Phone timetable, 390 × 844](timetable-mobile.png)
- [Phone route results, 390 × 844](results-mobile.png)

The route-results screenshots show the current scroll position; the complete
itinerary remains available below. Timetables and sample routes still identify
their September capture dates and unavailable live data.
