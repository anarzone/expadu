# Travel

Preview: http://127.0.0.1:8765/dev/design/departures-next/

Travel uses the shared warm cream palette (`connected/tokens.css`, accepted 6
October 2026); the White & Lagoon composition below is the layout it kept.

Start from the repository root with `python3 prototype/serve.py`. Use this shared
entry point rather than `python3 -m http.server`: its larger pending-connection
queue prevents styles and scripts being dropped during parallel loads. The
loading regression and before/after evidence are in
`docs/reviews/2026-10-04-travel-loading/`.

## Nearby replaces Timetable — 10 October 2026 (owner-approved design)

The owner approved the combined Travel design on the "Travel directions"
canvas (rows 4–9). This first slice brings in **Nearby**; the plan for the
rest is in `docs/superpowers/plans/2026-10-10-travel-combined.md`.

- **Travel opens on Nearby**, the closest station. Tabs are Nearby · Plan a
  trip. A trip hand-off (`from`, `to`, `route`, `service`) still opens Plan a
  trip; Today's `?view=timetable&stop=` opens Nearby on that stop, and an
  unknown stop keeps Neumarkt.
- **By boarding area.** Neumarkt's ten KVB point records form three areas:
  points that list a shared line belong together (1·7·9, 3·4·16·18,
  136·146). The capture doesn't say which point a departure uses, so a line
  belongs to its area, never to one point, and the map says so.
- **Line cards** show both directions with rounded minutes from the 4 Oct
  22:19 capture, and delays as recorded. Phones open a line in place; desktops
  show it beside the list with the map. Pin keeps a line first in its area
  (this browser only).
- **Filters open in place:** type, lines, leaving via (the first stop after
  the station, from the capture), next 10/30/60 min, delayed and step-free.
  Lift status isn't in the preview, so step-free says it hides nothing yet.
- **Map:** List | Map on phones, beside the list on desktop. One label per
  area; labels move aside rather than cover each other. Without the map, the
  areas stay choosable.
- **Stop picker:** Neumarkt and the 7 Sept Merkenich captures, each with its
  own clock. Stops without recorded points say so instead of showing a map.
- **Line colours:** KVB's, darkened where white text needs 4.5:1 (as approved
  on the canvas); unlisted lines use the travel colour, buses grey. The colour
  roles in `design-decisions.md` still describe cyan line badges; that entry
  waits for the owner.
- **Removed:** the station board, its time paging and filter menus
  (`station-board.js`, `stop-discovery.js` and their styles are no longer
  loaded). `timetable.js` stays for the service hand-off and Today's brief.

- **Your next trip leads Nearby** (moved from Plan a trip). Today's rule
  still picks the 13:14 (12 min spare) over the planned 13:24 (2 min). Phones
  show a compact card; desktops add the route line, the shared sample weather
  (`events-next/sample-weather.js`, labelled) and Later today, where each
  evening plan opens its way there. **I'm on my way** starts the trip's
  step-by-step preview; **Other ways** lists the 13:24 and says bike or walk
  times aren't in the sample, with "Compare them in Plan a trip".

Data: `nearby.js` (model, `tests/nearby.test.cjs`), `nearby-ui.js` (view),
`nearby.css`. No times, platforms or lift states are invented. Browser check:
`tests/nearby-browser.cjs` at 390 and 1440px (360 and 800px checked by hand).

## The captured example says it's from late at night — 7 October 2026

Travel opens on the sample day at 13:10, but "Explore a captured example"
lists 23:37–00:01 trams. There is no daytime capture of that trip, so the
times stay as captured and the line under the heading now says why, beside
them: "Köln Neumarkt → Museum Ludwig · captured Mon 7 Sept at 23:37. These
are that night's times, not today's." It reads the capture's own date and
clock (`JourneyModel.capture`). Evidence:
`docs/reviews/2026-10-07-app-in-colour/partb-travel-captured-*.png`.

## One timetable and the review fixes — 7 October 2026 (in review)

From the owner's Travel review (items 3 and 5–16):

- **One timetable.** The Timetable tab is the Neumarkt station board (line
  filters, the stop map, all 16 captured departures). Neumarkt is one stop: it
  opens on the sample day with the appointment trip's line 4, and "See the Sun
  4 Oct capture" shows the full board. `station.html` forwards here, keeping
  its query. The Merkenich captures stay in the stop picker.
- **Captured example follows the mode.** Cycle and Walk show the capture's
  cycling and walking options instead of the trams.
- **Composer help.** The box appears once there's a trip to help with; a
  request without a route offers "Ask Composer" in its own empty state. The
  Composer field no longer falls back to a typewriter font, and its close
  button has its own surface so it never doubles up with a ✕ behind it.
- **Colours.** Bus badges use the travel colour like trams (the icon tells
  them apart); the orange "Plan from here" link is gone with the old board
  (each departure's details offer "Plan from this stop").
- **Station board.** "Estimated" no longer repeats under every time (the
  header says "Saved estimates" once) and times are no heavier than the
  section title. The earlier/later buttons read "Earlier" and "Later"; on
  phones "Sample" is an info button so it doesn't look like a cut-off tab.
- **Preferences.** Walking time is four aligned choices instead of a select,
  and the switches' "off" is light.
- **Stop map.** Taller on phones (220px) so boarding areas don't pile up; its
  credit starts collapsed.
- **No route.** "No route available" offers Ask Composer, the starting stop's
  departures when it is one, and the captured example.
- **Your day.** The To field suggests the sample day's places: Mülheim (the
  14:00 appointment), Rheinauhafen (18:30) and Stadtgarten (20:00).
- **Places nearby.** A stop that is also a point in the place layer
  (Neumarkt, Wiener Platz) links to Around here, from the timetable and from
  the trip's "Get off at Wiener Platz" step.

## Sample day — 7 October 2026 (in review)

Travel opens on the shared sample day (`connected/sample-day.js`): Wednesday 30
September 2026, 13:10, the day Today, Composer, Events and Paperwork show.

- **Your next trip** leads the planner (on desktop, across both columns): the
  14:00 Blue Card appointment in Mülheim, the tram to take and when to leave.
  Today's rule decides it: an official appointment needs ten minutes in hand,
  so the planned 13:24 (two minutes to spare) gives way to the 13:14 (leave
  now, twelve minutes). **See both trams** opens them the way Today's hand-off
  does.
- Today's hand-off (`?from=Neumarkt&to=Mülheim&date=2026-09-30&arrive=14:00&activity=…`)
  and a typed Neumarkt → Mülheim open straight on the options: "12 min to
  spare" and "In your plan · … Only 2 min to spare". Mülheim is a suggested
  destination. Cycle and Walk say the sample only has the tram.
- Journey details, the map preview (Neumarkt to the Wiener Platz area,
  illustrative), saved journeys, the resume bar and Composer hand-offs use the
  trip's own date and place.
- **Timetable** opens at Köln Neumarkt on the sample day. Only the trip's line
  4 departures (13:14 and 13:24, towards Schlebusch) are in the sample; the
  board says so and links to the stop's 7 September capture.
- Data: the trip comes from `connected/sample-day.js` (line, times, walks; the
  direction from the 4 October Neumarkt capture; Wiener Platz from the place
  layer). No stops between Neumarkt and Wiener Platz are recorded, so none are
  shown. Without the sample day loaded, Travel behaves as before.
- The 7 September routes stay as **Explore a captured example** with their own
  date and clock ("Captured clock · 7 Sep, 23:37"). Other pages that read them
  (Today, Events, place sheets) are unchanged. The station board study
  (`station.html`) keeps its 4 October capture.

Verification: Travel 133, connected 159, Events 53, Composer 112, Today 44,
Today C 56, Paperwork 65 and Places 14 tests pass. Browser checks at 360, 390,
800 and 1440px cover the first screen, See both trams, Today's hand-off, details,
the map preview and its resume bar, the timetable and its capture link, Mülheim
suggestions, Cycle, saving and the Composer hand-off. Every other page's default
view is pixel-identical.

## Station-wide board — 4 October 2026

Review `?view=timetable` (`station.html` now forwards to it). Following the staging API/data audit, this
study replaces the featured next-departure card with one chronological station
board. Simultaneous departures have equal rows and pagination keeps a tied time
group together. The default is Neumarkt; switching Tram/Bus preserves the station.
Line chips, optional direction/delay filters and disclosure details remain compact.

Only this study loads `station-data.js`: a frozen public staging capture from
4 October 2026 at 22:19:08 Europe/Berlin, 16 line/destination services and 10 KVB
stop records. Times are approximate, reconstructed from rounded countdowns, and
labelled saved estimates. These are not live updates. The existing September
captures and `index.html` remain available.

Map adds a stop-location panel beside the board on desktop and above it on phones.
Line selection is shared with the list. Co-located records form eight markers.
Matching markers are candidate locations serving that line, never a verified
boarding assignment. Staging drops boarding point IDs/platforms from departure
responses; parent-station fields are empty in its current GTFS database. No
platform numbers, departure-to-point joins or live vehicles are invented here.
The map has a recorded-stop list fallback. Representative GTFS via stops are
retained in the capture for audit but omitted from this UI because they do not
reliably identify the selected departure's route.

The cream/Lagoon palette, shared header, planner and Composer remain. Current
capture details say “Plan from this stop”; no captured service reservation or
exact journey match is implied. Staging APIs/data were read, not modified.

## Approved workspace — 4 October 2026

The owner chose **White & Lagoon** and the **Side by side** desktop composition
from `travel-lab/`. This replaces the previous peach workspace on Travel only.
The page and header are white, input groups use a very pale cool tint, cyan marks
selection and transit badges, and orange marks the primary action.

At 900px and wider the trip form stays on the left and route comparisons stay on
the right, including after searching. Journey details expand within their row;
Map opens the existing navigation workspace, and Save / Composer retain their
existing behaviour. Phones stack the form above the options and collapse a
submitted form to an editable summary. The timetable keeps compact departure
rows with stop/time controls beside them on desktop.

The gallery's three route examples use the existing 7 September capture. Their
sample label and date remain explicit. Opening an example preserves the prior
request, including an unfinished destination, and offers **Back to your request**.
Short disclosure, underline, route-line and save animations respect reduced
motion. The shared header layout and Composer design are retained.

Changes are local to the static Travel prototype, with isolated browser fixtures
matching the actual page. Other departments and the Laravel app remain untouched.
Browser evidence and focused test results are recorded in
`docs/reviews/2026-10-04-travel-lagoon/` in the repository root.

Today now links to the existing timetable with `?view=timetable&stop=<id>`.
The stop must exist in the captured catalogue; an unknown identifier retains the
default stop. The board uses that stop's captured clock and Sample label, never
the current day with repeated historical departures. Existing saved-route links
continue to use the `route` parameter.

## Timetable and density — 29 September 2026

The scenario review replaces **At a stop** with **Timetable**. The same stop view
supports a quick departure check and choosing a date/time, with one-hour earlier
and later controls. Each chronological row is one selectable departure; its detail
lets the person select another captured time in that direction and plan from that
exact stop, date and time. Midnight receives the actual next date.

Mobile timetable rows start at 56px and grow only when their content needs it.
Journey comparison cards are about 98px at 320px width. Ten timetable rows load at
a time, with more available on demand; the current captures contain at most six
per stop. A 393px phone shows all six initial Merkenich Mitte departures.

Boarding stops in a journey open this same timetable at the vehicle's boarding
time. **Back to your journey** preserves the itinerary and requested arrival time.
Composer receives that request and its walking/change/delay preferences alongside
the selected itinerary; choosing it again updates its requirements without
replacing the person's words or other selections.

Only captured departures within 24 hours of the chosen time are shown. Other
dates have an explicit missing-capture state, not a repeated sample or a claim
that no services run. Neumarkt entries come from the existing journey capture;
the timetable does not add invented departures.

## Refinement — 28 September 2026

The owner's next milestone after Events is a complete mobile-first Departures pass,
including inner screens. Bureaucracy supplies the proportions: 44px touch targets,
14px control labels, 18px icons, and a 4px padded segmented rail with a white
selection. The shared header, navigation, Composer launcher and approved colour
system remain in place. Naming can be revisited later.

- **Plan a trip / At a stop (now Timetable):** two clear views, without stacking a planner and all
  stop filters above the first useful departure. The initial journey form pairs
  with a clearly labelled sample route on desktop and stacks on mobile.
- **Planner:** compact, connected From/To fields; swap; transit/cycle/walk; separate
  contextual time and preference controls. Suggestions support keyboard use.
  Searching reduces the form to an editable journey summary.
- **Route comparison:** actual departure/arrival times, proportional leg bars,
  walking minutes and specific trade-offs. Desktop keeps the selected itinerary
  next to its options; phones open a detail sheet. Selection stays consistent
  when resizing between these layouts.
- **Route details:** a cyan time summary and a readable walking/boarding/alighting
  timeline. Save and Use in Composer sit in a fading footer, with no hard divider.
  The last content can scroll fully above it. The native dialog owns focus and
  outside/Escape dismissal.
- **Stop board:** one compact stop picker, clear directions and scheduled times,
  followed by touch-sized later departures. Overnight services identify the next
  day. No simulated live countdown. Stop details can seed a new journey origin.
- **Contextual choices:** time, preferences, sorting and stop search are anchored,
  nonblocking panels; outside interaction and Escape dismiss them. Scheduling
  uses the shared calendar, time wheel and exact-minute entry from Composer.
- **Saved / preview:** saved journeys reopen their exact route; bookmarking can
  be reversed with Undo. Step previews retain progress when closed and resumed,
  offer previous/next controls, and ask before replacing a different preview.
- **Composer:** retain the request's destination, date, arrival target, mode,
  preferences, activity and preceding activity. Existing words are not replaced.
  An unsupported live request remains editable. Exploring the sample offers
  **Back to your request**, restoring every travel choice. A sample from another
  day or destination cannot be attached to that activity; an overnight route
  cannot be represented as arriving on its departure date.

Changes remain in the static prototype and its shared navigation bridge. The
Laravel/React implementation, backend, other department layouts and earlier
`departures-c` / Today experiments are unchanged. This iteration is ready for the
owner's design review, not a production release.

## Captured data and limits

Stop departures were captured from the staging UI on 7 September 2026 at 23:15:
Merkenich Mitte (tram) and Merkenich (bus).

Public-origin routes were captured on 7 September at 23:37:
**Köln Neumarkt → Museum Ludwig**. Five transit options (9, 18, 1, 7, 16), one
cycling option and one walking option. Boarding directions, intermediate stops,
alighting points and clock times come from that capture. Rounded durations can
differ by one minute from clock arithmetic for cycling/walking.

These remain samples. No live routing, GPS, rerouting, platform verification,
ticket coverage, fare advice, notifications or new geography is invented.
Saved routes live in this browser; the step preview is manually advanced.
Delay notices are captured flags whose current relevance is unknown. Other
requests can be handed to the existing Composer prototype, not a live route API.

The existing contracts in `resources/js/pages/timetable.tsx`,
`resources/js/components/departures/journey-planner.tsx`, the shared journey
handoffs, and `connected/design-decisions.md` remain the implementation references.

## Map and journey preview — 29 September 2026

Selecting a captured journey now opens a map workspace. On phones the map sits
behind a compact, draggable itinerary; expanding it reveals the complete route
without losing the current step. On desktop the itinerary stays beside the map.
The map can be panned, zoomed, recentered, or returned to its whole-route view.
Inspecting a stop does not advance the journey.

**Start preview** begins a manually advanced session. Previous/next steps show
the remaining captured time, remaining stops on the transit leg, and walking
still ahead. Starting, minimizing and finishing are separate from **Use in
Composer**, which retains the existing proposal handoff. A different route asks
before replacing an active preview; ending it also has a compact confirmation.

The shared resume bar appears in Composer, Today, Events, Bureaucracy, Services
and Departures only when a preview exists. Its route, step and selected sample
condition survive a page change or reload. This state has its own local storage
key and never creates or changes a proposal. Places is excluded from this
extension while its product direction remains under review.

The options menu demonstrates location unavailable, connection lost and a route
change awaiting review. These are explicitly sample states: there is no GPS,
vehicle tracking, live timetable, automatic rerouting or permission request.
If the map cannot load, the itinerary remains usable. Reduced motion skips map
flights and sheet animations; the handle also works by tapping or keyboard.

The map reuses the vendored MapLibre library and OpenFreeMap Positron style,
with provider attribution kept visible. Dotted lines connect public place
centres; they are **illustrative connections, not street or rail directions**.
No street geometry or station entrance is invented. Coordinates were checked
29 September against [Neumarkt station](https://en.wikipedia.org/wiki/Neumarkt_station),
[Heumarkt station](https://en.wikipedia.org/wiki/Heumarkt_station),
[Appellhofplatz](https://www.wikidata.org/wiki/Q962522),
[Dom/Hbf](https://www.wikidata.org/wiki/Q1521670), and the existing Museum Ludwig
catalogue record `osm:node/633480736`. A real implementation must replace the
sample session, location and geometry with verified routing/location inputs.

### Verification

```sh
node --test --test-reporter=spec prototype/dev/design/departures-next/tests/*.test.cjs prototype/dev/design/connected/tests/{handoffs,model,store,date-control,time-control,motion,journey-session}.test.cjs
```

Focused tests cover route identity, sorting, strict filters, midnight boundaries,
step generation, saved-data validation, date labels, safe activity associations,
restoring an original request, preview replacement, and detail parity after a
mobile selection. Timetable tests also cover chronology, pagination, absent
captures, selectable overnight departures, and preserving journey requirements
while browsing a boarding stop. Browser checks use `tests/fixtures/departures.html` with an
in-memory store; fixture handoffs show their payload on the page without changing
the user's plans. Responsive checks cover 320px, 393px and 1280px.

`tests/fixtures/navigation.html` and the Composer `tests/fixtures/journey.html`
use a separate, session-scoped QA namespace. They allow real navigation between
the pages while protecting the user's saved ideas and proposals. Checks cover
resuming at the same step, both handle gestures, stop-to-timetable navigation,
preview conditions, replacement/end confirmations and map/itinerary inspection.

## Connected frontend pass — 3 October 2026

The shared section is now called **Travel**. The accepted prototype keeps its
compact Plan a trip / Timetable layouts, sample captures and manually advanced
preview. Its Composer action now says **Review in Composer**. Matching contextual
requests carry a supplied canonical destination ID and facility coordinates;
missing coordinates stay unknown and unrelated samples cannot inherit them.

The real React Travel screen now uses the same two views and compact route
comparison. Its timetable shows eight chronological departures before More,
with individual direction details and honest scheduled/live source labels.
The existing API currently chooses the nearest available stop; arbitrary stop
and historical timetable browsing remain prototype capabilities. No extra
routing endpoint, sample route or permission prompt was added.

Real journey requests retain their exact destination, origin, requested date,
arrival minute and originating activity in the URL and the review handoff.
Changing the day detaches the old activity; browsing Timetable preserves the
selected itinerary. The existing shared calendar now supports exact-minute
choices. Failed route searches offer a retry or a starting point, and failed
journey saves keep their view open. Composer receives a review context, not an
automatic submission or confirmation.

Focused checks: the Travel prototype suite and `tests/Frontend/travel-request.test.mjs`
cover the preserved target, invalid coordinates, wrong-day association and
separate requested arrival. `tests/Frontend/trip-request.test.mjs` covers HTTP
failure and refreshed persistence. Browser regressions in
`tests/Browser/departures.spec.ts` cover view switching, contextual arrival,
refresh and a failed journey save; the parent integration run owns browser
and responsive verification.

## Departure filters and Map / Steps — 4 October 2026

The station alternative starts with **All sample stops**, with each departure's
boarding stop visible. **All / Tram / Bus** select available captured transport
types. **Filters** opens Line, Direction and Delay notices in place; active choices
remain applied when it closes. The baseline also receives these controls while
retaining its initial Merkenich Mitte stop. A selected clock is preserved across
type changes. Missing delay flags do not mean services are on time.

Journey preview now offers **Map / Steps**. Both views share the same current step,
boarding origin and manual progress. Steps opens without mounting a map, and the
view preference survives reload independently of the active preview. Minimizing,
resuming, ending and Composer handoff keep their existing behavior.

The original reference is **`origin/staging` at
`89289db9641bb75a563e74b44be9b4717bd61b22`**, refreshed from the remote on
4 October 2026. Read these paths with `git show origin/staging:<path>`;
the current working files contain later design changes and are not the baseline:

- `resources/js/pages/timetable.tsx`: adaptive All/Tram/Bus/Train controls,
  split-flap departure rows, two direction lanes when supplied, six initial
  rows and More/Fewer; realtime-source labels and 30-second polling.
- `resources/js/components/departures/journey-planner.tsx`: transport toggles
  (Tram/U-Bahn/S-Bahn/Bus/Ferry when present), Best/Fastest/Fewest changes/Direct/
  Least walking, Leave now/Depart at/Arrive by, Today/Tomorrow and five-minute
  time choices. The original main planner has no Transit/Cycle/Walk tab row.
- `resources/js/hooks/use-trip-progress.ts`: foreground GPS/schedule-based
  personal progress, next-stop guidance and persistent journey resume.
- `resources/js/components/journey/journey-map.tsx`: planned geometry and
  endpoint markers, not live vehicle or user position tracking.

Live feeds and GPS guidance have **not** been newly wired into this static
prototype. The new Map/Steps toggle shares manually advanced preview progress;
it does not turn captured data into live tracking. No Laravel/React application files were reverted or modified during this pass.

The existing local-only review login is `http://127.0.0.1:8011/dev/review`;
open it once in a browser, then visit `/timetable` in that browser. This does not
change staging or production authentication. The prototype on port 8765 needs no
login.
