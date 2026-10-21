# Connected Expadu design collection

Current cross-page reference: [Connected design decisions](design-decisions.md).
Read it before UI work; it consolidates the accepted shell, controls, place sheet,
planning, motion and page-specific decisions and identifies older alternatives.

Approved entry point: `http://127.0.0.1:8765/dev/design/today-c/`. Composer now opens `/dev/design/composer-studio/`; `/composer-next/` remains an earlier design for reference. The notes below describe the original department bridge; the Composer integration supersedes its saved-day workflow.

Current cross-page review: [Page review and connected journeys — 18 September 2026](product-design-review-2026-09-18.md). This records remaining refreshes, workflow gaps and the proposed integration order; it does not replace approved designs.

## Connected refresh — 18 September 2026

The approved work order has been implemented in this prototype. Events and Places have compact discovery controls, independent Save actions and a shared date/time review. Today uses the same planning review. Services keeps requests on the page and can propose an appointment the user has already arranged. All departments use the quiet Composer launcher.

A selected journey can be attached to a specific plan activity, with date/timing/staleness warnings. Department review links preserve context, and Back to Composer restores the conversation position. Bureaucracy appointment differences prompt an explicit review; same-day updates preserve identity and require the normal plan approval. Today’s progress and upcoming activity derive from department/plan records.

See the [implementation and verification notes](../../../../docs/superpowers/plans/2026-09-18-connected-departments.md) for boundaries and remaining service work. The older review/checks below describe the previous bridge; they are historical evidence, not additional checks performed in this pass.

## Interaction direction — 19 September 2026

Simple preferences stay contextual. Places now has three compact rows: search with an always-visible Saved toggle, aligned category tabs, then a quiet Area / Travel / Sort toolbar. The first result has priority; secondary controls use text and small icons rather than large pill surfaces. Each control opens its own anchored picker without dimming the page or shifting results. Only one opens at a time; outside interaction and Escape dismiss it. Scrollbar space and the result-caption height stay reserved so short result lists do not shift the shell or toolbar. Area choices update results immediately, one category is selected at a time (All clears the category), and Clear filters resets the discovery choices without erasing journey preferences.

Colours come from the approved shared shell tokens: pale canvas, white panels, black/dark control text, cyan selected filter surfaces, and orange for the active category and Add to plan. Muted text is reserved for supporting information. The separate cream/sage palette is removed. Place titles and Add to plan share a compact row, with photo attribution preserved below.

Starting point and transport mode have their own picker, independent of place filters. Done saves them to the shared journey preferences used by Directions. This captured preview has no calculated distances, live address search or opening-hours coverage, so it offers only catalogue neighbourhoods and As listed / Name A–Z sorting; it does not imply GPS, nearest or open-now results.

Mobile discovery prioritizes the first useful result over navigation and setup. Places uses compact text navigation, search, category shortcuts and the compact control rows; the promotional heading is visually omitted on mobile. Picker contents appear only on demand. Text and touch targets remain usable. Existing rich detail views are a separate pattern.

## Design decisions

- **Places:** a visual field guide with neighbourhood/category/search controls, photo attribution, useful detail sheets and a shared selection. Category artwork is a fallback, never a photograph of the place.
- **Events:** a date-led programme with occurrence-specific times, venue information, price uncertainty, category/free/search filters, detail sheets and local reminder previews. No single promoted event.
- **Services:** choose the kind of help, then keep language, area, insurance where relevant, and individual requirements. The current service directory is launch-gated; no fictional providers, ratings or availability are used to fill it.
- **Composer:** the approved continuous chat, with one active plan, explicit approval, history, Saved and My Plans panels. Department selections enter a proposal; confirmed days stay separate from bookmarks. See `../composer-studio/README.md` for the integrated behavior and checks.
- **Today C / Departures Next:** accepted visual layouts are preserved. A small shared bridge connects navigation, requests and kept items. Departures also hands a selected captured itinerary into Composer; event directions carry the event date and arrival time.
- **Bureaucracy:** A whole-plan Overview with three next actions, filtered action counts, multiple processes, dates and retained history. Connected process details and Paperwork use the same scoped state; a single unknown-date question can be answered or skipped. Built from a direct handoff with “Option B Bürokratiekarten”. Adult/dependent plans remain inaccessible in this preview; final authorisation policies and v2 backend actions are pending. A synthetic case uses exact historical catalogue copy, not newly authored legal guidance.

Geist, Expadu cyan `#05BADD`, orange `#FF3902`, readable action orange `#D9340A`, dark text, a pale canvas and restrained green/peach/yellow supporting surfaces connect the departments. Each department has a layout suited to its job. Detail controls use native dialogs and reduced-motion preferences are respected.

## What connects

`connected/model.js` owns the draft operations and exact occurrence identity. `store.js` persists one local draft, a separate saved snapshot, request text, requirements and preview reminders. It reads the latest state before each change, so department changes do not start new isolated plans.

- Typing on Today stays on Today. Submitting opens Composer with the same words and choices.
- Keeping a place/event retains its identity and details; adding the same occurrence twice does not duplicate it.
- Starting point and travel mode stay shared between discovery, Composer and directions.
- Events retain their date/time. Directions carry destination, date and arrival time into Departures.
- Captured itineraries retain their origin, destination, date, times and legs in Composer.
- Service requests retain their own requirements when other items are added.
- Bureaucracy sends only an explicitly selected, user-recorded appointment to Composer. Expiry dates, submission records, document state and other case details stay separate. Later case edits do not overwrite the saved day. Composer request text does not become confirmed case facts.
- Saved day is a copy. Later draft edits do not overwrite it. Restoring over unsaved changes asks explicitly.
- Overlapping event occurrences are flagged. Travel gaps and current availability are not fabricated.
- Exclusions, explicit limits and unverified quietness/sports goals do not silently turn into generic park recommendations.
- If persistence fails, the newest draft stays in memory and in-app navigation is blocked to prevent a misleading successful handoff.

## Evidence and limits

Inspected the rendered staging Places and Events pages on 8 September 2026, current page contracts in `resources/js/pages/{places,events,services,composer}.tsx`, place types, accepted prototype models, `prototype/dev/design/composer-first/report.md`, and the referenced Bureaucracy task.

Place names and areas come from captured Expadu content. Blücherpark / Innerer Grüngürtel and their existing Commons photo credits come from the June capture. The other four place listings and seven selected event occurrences come from the staging UI on 8 September. Event categories are display groupings for this preview. The first event's original title continues “De/Collecting Memories from Turtle Island”; the display title uses the captured leading exhibition title.

No Stadt Köln event images are reused. Event symbols are decorative UI icons. Travel distances based on the user's current location were deliberately not copied into this general preview. Public route examples retain the accepted Departures capture from 7 September.

This is a local interaction prototype, not a live recommendation engine, provider search, booking flow, reminder delivery system or legal service. Composer uses a small explicit response model. Places' map action opens the selected public place query in OpenStreetMap; this version does not implement a live in-app multi-pin map. Current availability, opening times, accessibility, sports access, admission rules and legal facts are not invented.

## Review checks

- Desktop visual review at 1365 px; mobile at 390 px. All four new departments checked at 320 px with no horizontal page overflow or browser errors.
- Today focus/typing stays in place; Enter opens Composer with the exact request.
- Place → Events → service brief → Composer preserves the combined selection.
- Event date selection and empty dates, detail sheet, and destination handoff checked.
- Selected public route → Composer preserves route/time context.
- Save → remove → inspect saved snapshot → explicit restore checked.
- Reordering through the edit sheet checked on mobile.
- Excluded parks / 30-minute limit remain explicit, with no false match.
- Review-only test choices cleared using the preview's reset control.

Run focused tests:

```sh
node --test prototype/dev/design/connected/tests/*.test.cjs prototype/dev/design/bureaucracy-next/tests/*.test.cjs prototype/dev/design/today-c/tests/model.test.cjs prototype/dev/design/departures-next/tests/journeys.test.cjs
```

Production Laravel/React files and unrelated working-tree changes are untouched. No deployment is involved.

### Place sheet and shared dates — 20 September

A place stays in one sheet with About, Plan a visit, and Get there sections.
Switching sections preserves unfinished time choices; the section back action
returns to the place, while the header close exits the sheet. Adding to a proposal
shows an inline receipt with Undo. The chosen day's confirmed activities, current
proposal and pending additions are reviewed in the sheet, without navigating to
Composer. Confirmation of the proposal remains Composer's existing action.

`date-control.js` / `.css` are the shared calendar control for connected planning
forms and Composer. They retain each form's input and change/submit handlers.
Directions in the place sheet keep the origin and mode in the shared travel
preferences; the explicit full-planner action carries the destination forward.
There are no live route results in the sheet, and the interface says so.
