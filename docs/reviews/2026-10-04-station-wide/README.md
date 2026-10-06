# Station-wide timetable review

Preview: http://127.0.0.1:8765/dev/design/departures-next/station.html?view=timetable

The station study now uses one chronological board, with equal rows for simultaneous departures. Tram/bus filters preserve the selected station. Line chips, optional direction/delay filters, per-departure details, and an optional stop-location map share the same selection context.

The Neumarkt sample is a frozen running-staging capture from 4 October 2026 at 22:19:08 Europe/Berlin. Its 16 service entries use estimates reconstructed from rounded countdowns. Ten stop records form eight map locations. The UI does not infer a departure's platform or claim live vehicle tracking. Candidate stop locations are labelled accordingly.

## Verification

- All 80 focused Travel tests passed, including time ties across pagination, independent departure details, mixed-mode filtering, station retention and map marker grouping.
- Shared-header prerender check and formatting check passed.
- Header and Travel startup checks passed. The local preview-server burst test passed with loopback networking enabled.
- Chrome checked at 1440 × 1000, 390 × 844 and 320 × 740. No horizontal page overflow at the phone sizes.
- Exercised tram/bus/line filters, empty results and recovery, show-more pagination, desktop details, mobile detail sheet, map/list switching, map marker selection and line handoffs. No browser console errors in the final desktop check.

Evidence: `desktop-map.png`, `mobile-list.png`, `mobile-map.png`.

This remains a local design prototype. Staging APIs/data and the Laravel/React app were not changed for this revision.
