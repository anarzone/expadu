# Friendly connected app implementation plan

**Goal:** Make the approved Expadu experience easy to understand and coherent across Today, Composer and its departments, then implement and verify the frontend.

**Architecture:** Keep existing shared Composer/workspace and planning services. The prototype's Today agenda gains an explicit adapter to the shared day instead of a second invisible agenda. Real React pages use current endpoint contracts; shared navigation provides a compact five-tab shell across departments.

**Tech stack:** Existing vanilla prototype; Laravel 13, Inertia 2, React 19, Tailwind 4 and Tabler in the real app. PHP 8.4.23 and Node 22.23.1 verified.

**Spec:** `docs/superpowers/specs/2026-10-03-friendly-connected-app.md`

## Constraints and execution decisions

- Preserve all prior user work and existing Composer. Backend features/data imports/deployment deferred.
- No automatic submission, replacement of another date's draft, invented legal/provider facts or unapproved imagery.
- Explicit owner authorization covers unattended decisions and review/fix cycles; no approval checkpoint needed.
- Work in current checkout because it contains the accepted untracked prototypes and existing unfinished integration. Do not reset, stage or commit unrelated files.
- Use the parallel-agent skill for independent Events, Travel and real Today/shell tasks. Root owns shared prototype planning, final integration and browser verification. Each worker owns separate files and reports interfaces before touching another owner's scope.

## Review focus

1. A plan added/edited on Today must be visible in event conflict checks without duplicating dates or replacing a proposal.
2. Changing time/place must not leave an old journey presented as current.
3. An unsent Composer request and another day's proposal survive contextual entry actions.
4. Missing photos, coordinates, hours or fees must never become confident claims or empty image frames.
5. Small phones, empty accounts, long titles and failed persistence must retain a clear next action and usable focus.

## Tasks

### 1. Shared prototype day and personal editing (root)

Files: `prototype/dev/design/today-next/{app.js,model.js}`, `brief/{content.js,enhancements.js,priorities.css,index.html,content-review.html}`, `connected/{handoffs.js,day-actions.js,store.js}` as necessary; focused Node tests beside existing tests.

- [x] Reproduce existing day disagreement and write regression tests for unified appointments, conflict checks, edit/undo and other-day preservation.
- [x] Add an adapter with explicit read/write boundaries, keeping sample scenario agendas separate from actual user records but exposing their current review context consistently to the prototype planner.
- [x] Implement edit through existing compact form and dots menu; invalidate journey when title/location/time edits affect it; protect fixed events.
- [x] Add optional first-use Composer starting actions and collapse duplicated empty continuations.
- [x] Make delay recovery direct and retain exact context.
- [x] Run focused day/content/shared planning tests and browser scenarios.

### 2. Events flow and real page (Events worker)

Files: `prototype/dev/design/events-next/` and `resources/js/pages/events.tsx`, event-specific components and relevant tests only. Coordinate any shared planning API changes with root.

- [x] Review approved prototype/current API and test event time/date preservation and proposal completion.
- [x] Add direct Review plan after selection, clearer pending feedback and purposeful controls.
- [x] Bring real Events frontend into the approved immersive image-first design using real approved media, compact filters, preserved hero/filter context and existing Composer handoff.
- [x] Verify empty/no-image/error/fixed-time behavior, scoped formatting/lint/type checks.

### 3. Travel flow and real page (Travel worker)

Files: `prototype/dev/design/departures-next/` and `resources/js/pages/timetable.tsx`, travel-specific components and relevant tests only.

- [x] Review plan/timetable modes and existing destination identity/time contracts.
- [x] Implement compact friendly Plan a trip / Timetable experience, clear direction/arrival, direct contextual recovery and connection to Composer with retained request.
- [x] Retain honest scheduled/live/sample status and actual route limitations; no synthetic routes or forced location prompt.
- [x] Verify narrow rows, 5–10 departures, route details, wrong-date prevention and scoped checks.

### 4. Real Today and shared app shell (Today worker)

Files: `resources/js/pages/dashboard.tsx`, `resources/js/layouts/app/app-sidebar-layout.tsx`, new shared app header and Today components, narrowly scoped CSS/tests. Do not edit Events/Travel/Composer implementation owned elsewhere.

- [x] Inspect current props, saved plan and workspace APIs; preserve existing working mutations and real data.
- [x] Implement compact five-tab top navigation (Today, Events, Paperwork, Travel, Services), removing standalone Places and default utility-page widgets rail from the shell while keeping account/settings/accessibility routes.
- [x] Implement Today priority and unified practical/discovery composition using existing data, friendly optional Composer entry, real plan continuation and honest empty/loading/error states.
- [x] Keep shared Composer interaction/layout, no replacement engine; coordinate any missing API need with root.
- [x] Verify current props/types, formatting/lint and focused behavior checks.

### 5. Cross-app consistency and places integration (root)

- [x] Read and request handoff from the places chat, then record/use verified field/coverage limitations.
- [x] Review Paperwork/Services, navigation names, optional onboarding and contextual Composer entry. Make necessary frontend refinements without inventing new guidance or backend capabilities.
- [x] Integrate agents and inspect all touched files. Resolve contract collisions and existing-draft preservation.
- [x] Run typecheck/build and focused regressions; start/reuse local app for real frontend review.

### 6. Independent review, fixes and final evidence (root)

- [x] Review first visit, busy/quiet day, edit/undo, event-to-plan, travel delay/timetable and no-photo/contextual place scenarios on mobile and desktop.
- [x] Perform a fresh integration review and fix material findings with regression coverage.
- [x] Re-run affected checks after fixes, save representative screenshots, update design decisions and final verification record.
- [x] Finish with tested scope and concrete remaining backend/data limitations, without claiming deployment.

## Progress

3 October: implementation and review completed across the approved prototype and real frontend. Places handoff received and integrated conservatively; no catalogue import or deployment. Browser review found and resolved shared-day cancellation, stale journey context, unsent text loss, utility navigation, time-picker races, manual Timetable entry and misleading Paperwork readiness. Final evidence and exact validation results are maintained in `docs/reviews/2026-10-03-friendly-connected/README.md`. Existing unrelated dirty work is preserved.
