# Places integration delivery — 27 September 2026

Reference for the EXP-73 work log. The ticket remains In Progress because it
covers the wider redesign and live migration separately.

The owner asked to integrate the approved field-guide idea into the current
connected design, using existing patterns instead of the previous concept's
bespoke visual choices.

Implemented at `http://127.0.0.1:8765/dev/design/places-next/`:

- Current shared shell, navigation geometry, colour tokens, typography,
  contextual discovery controls and Composer launcher.
- Evidence-based collections with real named choices, searchable source records,
  cafés/restaurants, categories, recorded areas, Save and pagination.
- The existing About / Plan a visit / Get there sheet with shared calendar,
  exact-time controls, day context, proposal/Undo and Composer review/return.
- Practical source facts and uncertainty, full optional-photo attribution,
  exact-coordinate map links and destination handoff. Missing/failed photos
  collapse without a blank hero or substitute art.
- A central design-decisions reference, linked from AGENTS.md, CLAUDE.md and the
  connected README. Prior concepts are identified as historical; the old Places
  source is preserved under `archive/places-next-before-field-guide/`.

Verification observed: 131 focused model/shared-interaction tests passed; 12
connected browser/data checks passed. These include desktop navigation geometry,
320/390/768px layouts, first useful content and action visibility, actual shared
planning components, saving without scheduling, tab draft retention, proposal and
Undo, Composer source-identity preservation and return, coordinate handoff,
photo-credit/failure recovery, catalogue retry, Today → Places navigation and
scroll restoration after a delayed catalogue response. Asset-version checks also
passed.
Evidence is saved in `tests/integration-verification.json` and screenshots.

The 4,956 records and eight reviewed images are unchanged research-snapshot
inputs, not new imported/published places or improved photo coverage. No Stadt
Köln media was approved. The earlier 75% photo objective and data-quality work
remain separate. This delivery changes the connected prototype, not the deployed
Laravel application.

Design reference: `prototype/dev/design/connected/design-decisions.md`.
Implementation reference: `prototype/dev/design/places-next/README.md`.
Production contract: `prototype/dev/design/places-guide/HANDOFF.md`, with the
current shared-component correction at its beginning.
