# Departures — Today C companion

Preview: http://127.0.0.1:8765/dev/design/departures-c/

## Inspected before designing

- Current Chrome tab: `https://app.staging.expadu.com/timetable`, inspected 7 September 2026, displayed capture time 23:15. Public stop and timetable information only; personal addresses, recent destinations and walking estimates were not copied into the prototype.
- `resources/js/pages/timetable.tsx` and the existing journey planner: mode-specific nearest-stop boards, source status, direction grouping, origin and destination entry, adaptive mode availability.
- `docs/product-spec-prompt.md`, Transit Page; `docs/recommendation-system.md`, Departure Boards; `prototype/dev/design/composer-first/report.md` for request continuity and honest data states. These contain earlier proposals; the user's accepted Today C styling governs this mockup.

## Findings and decisions

The current screen has useful departure, origin/destination and mode controls. Its split-flap lettering clips long destinations and repeats glyphs in the accessibility tree. The sidebar and unrelated widgets constrain the timetable. The current fallback recommendation calls a different line a “calmer bet” without checking that it serves the user's destination.

The prototype retains the service navigation, city selector, typography, cyan/orange palette and translucent Composer from Today C. It replaces animated letter tiles with whole destination names, simple line badges and waiting times alongside clock times. Large desktop screens show the two stop boards side by side; smaller screens stack them. Each board represents a real distinct stop, not a category of unrelated widgets.

Tram uses Merkenich Mitte; Bus uses Merkenich. All keeps them separate instead of placing bus departures beneath the tram stop name. Changing modes does not silently replace an explicitly selected stop. An incompatible selection offers a clear recovery. Modes without captured data are not invented.

Row details preserve the stop and show later services, including midnight rollover. Stop search covers the two captured stops. Journey entry requires an origin and destination and adds the request to Composer without erasing existing text or submitting automatically. No alternative route is claimed without route evidence. Network-wide notices remain available separately from the departure rows, with their uncertain relevance stated.

## Data limits

All departure values are frozen from the staging screen's 7 September, 23:15 view. Waiting times are calculated relative to that captured clock and never presented as a running live feed. Line colours here are the mockup's mode accents, not claimed official operator route colours. Platforms, stop sequences, personal walking times, route durations and interchange options are not fabricated.

Live stop search, geolocation and route results are not connected. The form-to-Composer handoff is interactive; route options require the real journey service. Account and other service surfaces link back to the existing Today concept. This is a separate review prototype; Today C and production code are unchanged.

## Verification

Focused tests cover stop identity, incompatible mode/stop selections, search misses, midnight times and requiring both journey endpoints. Browser checks cover mode and stop selection, later services, row details, trip handoff and mobile overflow.
