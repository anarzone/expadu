# Places in the connected design

Reference prototype: the owner approved removing the standalone Places page
from main navigation on 29 September 2026. The existing URL remains available for
comparison. Place content and actions continue through Today and Composer.

The integration below records the earlier 27 September design for EXP-73; it is
not a requirement to ship a separate Places department.

Start with [Connected design decisions](../connected/design-decisions.md).
That reference consolidates decisions previously spread across the connected,
Today C, Composer and department notes. `AGENTS.md` and `CLAUDE.md` now link to it.

## What was integrated

- The field-guide content model: useful collections with named places, cafés and
  restaurants, full-catalogue search, category/recorded-area filters, Saved and
  progressive results. Collection membership uses recorded evidence.
- The existing shell, navigation, Geist and shared colour tokens. Search, category,
  Area, Travel and Sort retain the accepted compact anchored controls. Page-local
  CSS changes content layout only.
- The shared About / Plan a visit / Get there sheet, shared date/time controls,
  visit timeline, proposal receipt and Undo. Saving remains independent from
  planning. Composer review and its return flow use the existing shared store.
- No-photo and failed-photo views collapse the media space. Recorded address,
  hours, amenities and source links remain usable. Existing approved sample
  photos have complete attribution and source/licence links.
- Exact coordinates and source identity pass to Departures with shared starting
  point/mode. Source records use its compact honest preview, not an unrelated
  captured itinerary. The prototype still cannot calculate live routes.

## Reused components

`connected/ui.js`, `shell-review.css`, `scroll-header.js`, `location-popover.*`,
`menu-colors.css`, `discovery-scale.css`, `place-sheet.*`, `date-control.*`,
`time-control.*`, `planning-ui.js`, `visit-timeline.js`, `handoffs.js`, `store.js`,
`composer-entry.*`, `loading.*` and the existing `places-next/filters.*`.

`places-guide/model.js` and `catalogue.json` remain the data/collection sources.
Their former header, side pane and native date/time controls are not adopted.
The previous Places source is preserved in
`archive/places-next-before-field-guide/`. That is a source archive, not a wired
independent preview.

## Evidence and boundaries

The 4,956 records and eight reviewed photos are the same prepared research
snapshot used by the earlier concept. They are not new imports, published app
inventory, a city census or increased photo coverage. Source identities remain
provisional. The 75% photo goal and data-quality tickets are not closed by this
presentation change. No Stadt Köln media was added or approved.

The live implementation contract remains in
[Places handoff](../places-guide/HANDOFF.md), subject to the shared-component
correction in this README and the central decisions reference. Port the content
model into the application's existing components and API; do not migrate the
bespoke prototype shell or publish sample IDs/browser-only persistence.

## Verification

Run focused models and shared interaction regressions:

```sh
node --test prototype/dev/design/places-next/tests/*.test.cjs prototype/dev/design/places-guide/tests/model.test.cjs prototype/dev/design/connected/tests/*.test.cjs
node prototype/dev/design/places-next/tests/verify-guide.cjs
```

The browser script uses isolated state against the existing port-8765 server.
It verifies actual shared component use, matching Events navigation geometry,
collections/search, optional-photo details, shared date/time input, proposal/Undo,
Composer handoff/return, exact destination handoff, failed-image recovery, mobile
widths, Today navigation and scroll restoration after a delayed catalogue load. Results and screenshots are under `tests/`.

This is the connected design prototype, not a deployment of the Laravel app.
