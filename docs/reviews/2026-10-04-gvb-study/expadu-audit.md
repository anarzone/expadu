# Expadu Travel: independent source audit

4 October 2026. Research only. This audit reads the **static connected Travel prototype**, principally `departures-next/station.html`, `app.js`, `station-board.js`, `station-map.js` and `timetable.js`. Supporting journey, capture and bridge files were read where the entry page delegates behaviour. No application, prototype, staging data or configuration was changed. No fresh browser checks or application tests were run for this audit; the GVB comparison and browser observations are separate research evidence.

The prototype already provides a credible board-browsing flow. Its largest remaining traveller-task gap is selecting a **specific boarding place for a specific service**. The saved data supports candidate locations and a station-wide board; it does not support that final association. Improving visual emphasis alone cannot close this gap.

## Baseline and evidence boundaries

- `station.html` explicitly loads the October station capture and the station board/map before `app.js`. It also loads the shared Composer bridge and journey navigation. This is not the Laravel/React timetable. [Entry scripts, lines 13–45 and 89–101](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/station.html:13).
- The current entry defaults to **Neumarkt**, using the October capture when available. The saved capture is dated **4 October 2026, 22:19:08 Europe/Berlin**, with times reconstructed from rounded countdowns and `isLive: false`. The September journey examples remain separate. [Default state](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:7), [capture provenance](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/station-data.js:213).
- The station-wide review describes this current Neumarkt case: sixteen service entries, ten source stop records and eight map locations. Its recorded browser and test results are prior evidence, not checks rerun here. [Station-wide review](/Users/anar/Projects/Own/Startups/expadu-app/docs/reviews/2026-10-04-station-wide/README.md:5).
- The earlier filters review and one paragraph in the decision log still say the study starts across all sample stops; the earlier review also describes September-only data. Those statements no longer describe the current default. Resolve that documentation drift before using the notes as acceptance criteria. [Earlier review, lines 25 and 50](/Users/anar/Projects/Own/Startups/expadu-app/docs/reviews/2026-10-04-travel-filters/README.md:25), [decision log, lines 167–183](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/connected/design-decisions.md:167).
- The filters review distinguishes original `origin/staging` commit `89289db9641bb75a563e74b44be9b4717bd61b22` from subsequently modified local React files. Its source-based Live/Schedule labels, refresh and GPS/schedule progression are an integration reference. They are not capabilities demonstrated by this static capture. This audit did not independently re-audit that commit or the running application. [Baseline distinction](/Users/anar/Projects/Own/Startups/expadu-app/docs/reviews/2026-10-04-travel-filters/README.md:5).

Retain the accepted compact header, Travel palette, side-by-side planner and equally weighted station rows. None of the recommendations requires a palette or shell redesign. The later station-wide decision supersedes the featured-service composition. [Current Travel decisions](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/connected/design-decisions.md:100), [station-wide approval](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/connected/design-decisions.md:156).

## Existing strengths to preserve

- **Useful filtering without fabricated capabilities.** Available types derive from records, line chips are direct, optional filters have a count and clear action, and type changes preserve the selected station. Missing delay information is explicitly not evidence of an on-time service. [Type and filter derivation](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:697), [type-change handling](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:1343), [empty states](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:934).
- **Chronological, equally selectable departures.** Rows expose destination, time and captured delay; ties stay together across pagination. Missing dates are not populated by repeating a historical timetable. [Rows](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/station-board.js:37), [time window and ties](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/timetable.js:75).
- **Honest map semantics.** Co-located records are grouped; map copy calls them recorded stops and explicitly says the exact boarding point is not provided. Unreliable representative via stops are excluded from guidance. [Map detail](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/station-map.js:145), [via-stop restriction](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/station-board.js:52).
- **Map-free journey continuity already exists.** Map and Steps use the same route/progress, Steps does not mount a map, view preference persists separately, and session records retain step and boarding origin. Keep this behaviour; it is not a new improvement to propose. [View switching](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/navigation-ui.js:151), [session record](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/connected/journey-session.js:26).
- **Composer distinguishes requests from selected itineraries.** The shared bridge replaces the fallback handoff in `app.js`; generic requests carry travel choices, while itinerary handoffs carry route legs. Context checks prevent an unrelated sample from being attached to an activity. Starting or resuming a preview is separate from proposing a journey. [Bridge handoffs](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/connected/bridge.js:225), [context checks](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/journey-ui.js:44).

## Prioritised improvements

Priority indicates traveller impact and dependency order, not an instruction to implement. P1 items concern choosing or retaining the correct travel context; P2 items improve recovery and repeated use. “Integration gap” means the static preview deliberately does not provide a production capability.

### 1. P1 — Model station, boarding point and departure as distinct identities

**Task:** “Which side or platform do I need for this departure?”

The capture has multiple Neumarkt points serving the same line, all named Neumarkt. Every departure has `boardingPointId: null` and `platform: null`. Map matching uses only line and transport type; a selected direction is reduced to its line before being passed to the map. Choosing a marker cannot establish which departure boards there. The fallback list further removes the geographic distinction because its records repeat station name, mode and line chips. [Point records](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/station-data.js:18), [departure fields](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/station-data.js:221), [map matching](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/station-map.js:38), [selection passed to map](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:845).

**Improvement:** retain a station as the browsing scope, with separately identified boarding points beneath it. Where verified, show the point's direction, platform or entrance/landmark and connect a selected departure to that point. Where no association exists, keep “candidate stops” and an explicit unresolved boarding place; do not promote nearest coordinates or a shared line into a boarding assignment. Make the text alternative distinguish candidates using verified descriptors, not guessed names.

**Dependency:** a validated provider departure-to-point identity and station grouping contract, plus point descriptors. Current data cannot produce the verified assignment. Candidate-only wording and a distinct unknown state can be studied before integration.

### 2. P1 — Carry time provenance and service uncertainty through every handoff

**Task:** “Can I rely on this time now, and what changed?”

The board correctly says “Saved estimates · not live”, and estimated rows say “Estimated”. However, the richer capture fields are not represented uniformly downstream: the planner handoff copies the reconstructed date/time into a `depart` request, and the generic Composer request has no capture timestamp, time basis or selected-departure provenance. Thus the board's estimate can become an apparently exact requested time unless the user remembers the earlier context. [Board labels](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:879), [estimated row](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/station-board.js:59), [planner seed](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:1069), [Composer request fields](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/connected/bridge.js:234).

The capture also preserves `disrupted`, while the displayed row/detail and delay-only filter use `delayed`. That is a data-contract question to resolve, not proof that a relevant warning can safely be written from the flag alone. [Captured service fields](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/station-data.js:195), [rendering](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:802), [filter predicate](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/timetable.js:88).

**Improvement:** carry `captured / scheduled / realtime / stale / unavailable` evidence and its observation time with a departure or selected itinerary. Preserve “approximate captured time” when it becomes a request; let the person deliberately choose a new time. For eventual live integration, distinguish no service, no matching service, missing feed, expired data and unknown delay. Evaluate disruption relevance and cancellation data before deciding what to expose.

**Dependency:** time/source/status semantics and freshness rules from the actual feed integration. Keep the frozen study frozen; a refresh indicator must not simulate new information.

### 3. P1 — Add nearby-stop discovery with an equally usable manual origin

**Task:** “I am here; which nearby stop should I use?”

The stop picker searches a small captured catalogue by name and shows mode/lines. It has no position, search-area context, distance, walking approach or nearby ranking. This is an integration gap, not a broken geolocation feature. A newcomer who does not know the stop name cannot complete nearby discovery in the current study. [Search model](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/timetable.js:120), [picker contents](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:962).

**Improvement:** offer a compact “near this location” choice using either optional device location or a manually chosen place/area. Show station name, transport types, direction context and a trustworthy approach estimate when supported. Keep the chosen station stable while switching type or line. A denied, inaccurate or absent GPS position should lead directly to the same manual choice, with no permission loop. After selecting a station, resolve its boarding points using item 1.

**Dependency:** geocoded manual origins, accurate stop coordinates, clear distance semantics, and routing data if walking time/path is shown. Straight-line distance must not be presented as an accessible walk or proof the service can be caught.

### 4. P1 — Preserve the selected service as context when planning or asking Composer

**Task:** “Help me use this particular service in my day.”

For captured routes with a `routeId`, the prototype can retain the selected itinerary. The new station capture has no `routeId`. `useStop()` therefore seeds station/date/time, clears selection and focuses the destination; it does not retain the tapped line, destination or service ID. The UI honestly calls this “Plan from this stop”, so it is not a broken promise to reserve that service. It does leave the traveller's choice unavailable to later planning or Composer. [Planner seed](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:1069), [capture entry shape](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/station-data.js:221), [button wording](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:807).

**Improvement:** carry a compact, reviewable “consider this service” context: station, line, headsign, observed departure, provenance and unresolved boarding point. Distinguish this preference from a verified itinerary and from a generic request. Editing origin/time should invalidate or visibly reconsider incompatible service context. Keep the existing activity/date checks and separate “open Composer” navigation from “review this journey” transfer.

**Dependency:** item 2 and a versioned handoff record understood by both planner and Composer. Exact service routing also needs a provider trip/departure match; the captured headsign alone is insufficient. Preserve the current request-only fallback until that exists.

### 5. P2 — Make active direction and notice filters understandable when collapsed

**Task:** “Show my line in the right direction, and explain why other departures disappeared.”

Line chips are always visible. Direction is a destination-labelled service selector in the optional panel; `StationBoard.directionChoices()` currently returns no direct choices. When collapsed, a count communicates that filters exist but does not state the direction or notice condition. Direction filtering is by `serviceId`, while the map retains only line/type, so the user's narrowing is stronger in the list than on the map. [Direction implementation](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/station-board.js:64), [filter controls/count](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:740), [map selection](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:845).

**Improvement:** retain the compact line chips and optional panel, but show a short active summary such as a chosen headsign and captured-delay condition, with a clear action. When more than one line or station is in scope, label direction options with enough line/station context to remain unambiguous. Explain that the candidate map is only narrowed by line until point/direction data supports the same filter. Do not imply an “on time” filter from absent delay data.

**Dependency:** a stable definition of direction/headsign versus service/trip identity. This is primarily a prototype interaction refinement; truthful geographic narrowing depends on item 1. Validate with one line serving multiple directions and with an empty result.

### 6. P2 — Extend recovery beyond retained journey steps to station selection and map failure

**Task:** “Let me continue after switching views, losing the map or losing location.”

Journey Map/Steps already shares progress and preserves the active boarding origin. Location unavailable, connection lost and route changed are explicitly selectable **sample conditions**, not actual detections. The station map has a loading timeout and a recorded-stop list fallback. Its selected marker is internal to the mounted map; switching to List destroys the controller, so returning to Map cannot restore that specific marker selection. [Journey conditions](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/navigation-ui.js:227), [condition controls](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/navigation-ui.js:385), [map fallback](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/station-map.js:210), [controller destruction](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:823).

**Improvement:** keep journey continuity intact and add station/point selection to shared view state. Keep useful text present while the map loads or fails; the map-free candidate list needs the distinguishing information from item 1. Study real no-GPS/no-map recovery separately from the preview-condition menu. An unknown boarding point, an unavailable map and a missing service feed should each say what remains usable and provide a relevant next action.

**Dependency:** item 1 for useful point detail; later device/feed/map status integration for real detection. Test view switches, map failure, reload, denied location and a route change without resetting a user's step or silently replacing the itinerary.

### 7. P2 — Separate saved historical journeys from repeat-trip shortcuts

**Task:** “Get me to the same destination again, or reopen my regular stop and direction.”

Saving stores route IDs from the fixed sample catalogue on this device. The Saved list explicitly labels captured journeys and reopening restores the sample origin/destination. This is appropriate for reviewing an example, but it is not a repeat journey for a new date, nor a favourite stop/line/direction. The read state for timetable selection also starts afresh rather than loading a saved board preference. [Saved storage and rows](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:596), [reopening sample](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:1304), [initial board state](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/departures-next/app.js:7).

**Improvement:** offer a distinct repeat-trip shortcut that retains destination/origin and travel preferences but requests a fresh date/time and route. A favourite board can retain station plus optional line/direction without preserving an old countdown. Keep historical saved itineraries visibly dated and allow removal with the existing Undo. Do not label a locally saved sample as a current travel plan.

**Dependency:** stable location/station identities, explicit local-versus-account storage behaviour, and live or scheduled route lookup for a fresh journey. Prototype the distinction and unavailable-refresh state before connecting data.

## Research acceptance cases

Use these tasks for the next comparison or prototype review, without changing the accepted visual system:

1. Find a nearby station without knowing its name, then continue after declining GPS.
2. Select Neumarkt and a line served at several points; explain which boarding location is verified and which remains unknown.
3. Select a direction, collapse filters, switch Map/List, and recover an empty result without losing station/time context.
4. Read a saved estimate, send a service preference to Composer, and verify that its age, uncertainty and unresolved boarding place remain visible.
5. Move Map → Steps → another department → resume, preserving step and boarding origin; repeat with map unavailable.
6. Reopen a historical saved journey and separately request the same trip for a new date; neither should silently masquerade as the other.

This source audit establishes behaviour and data boundaries. It does not establish physical wayfinding accuracy, current Cologne service status, GVB equivalence or usability success with travellers. Those need provider evidence and observed task completion.
