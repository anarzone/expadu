# Travel implementation and review — 5 October 2026

## Environment and scope

Actual Laravel/Inertia application at `http://127.0.0.1:8011/timetable`, using an isolated local review account. Base revision `c386427d`, plus the existing shared uncommitted working tree and this implementation. No commit, push or deployment was performed. The accepted `departures-next` planner/station prototypes were references only; no sample itinerary or departure was installed in the actual app.

The shared pale canvas, light surfaces, cyan selections and orange primary actions are preserved. Root owns the shared header and the other main pages.

## Implemented

- Desktop uses a wide side-by-side request/results workspace. Phones retain a compact editable trip summary, with modes and the first route visible early. At 320px the mode choices fit, and schedule labels remain on one line.
- Current inputs, schedule, sorting and optional filters remain distinct controls. Compact Radix direction/line/service menus replace operating-system select popups. Touch targets and visible keyboard focus are retained.
- Editing a confirmed place label makes the old route and Save action unavailable until a place is confirmed. An unresolved starting point cannot silently be used with the previous coordinates. Existing request cancellation guards remain in place.
- Route detail preserves the selected preview through timetable browsing and a refresh using an account-scoped, exact-request session snapshot with a 30-minute maximum age. Returning restores the same detail and focus. Preview restoration never starts a trip. Started journeys continue to use the server-owned active-trip record.
- Steps/Map shows actual provider geometry when supplied, otherwise an explicit known-locations-only state. Active journey maps reuse the existing foreground GPS watcher, with the validated fresh position supplied by `useTripProgress`. Stale, hidden, disabled or mismatched-route fixes disappear. Itinerary lines are not presented as vehicle tracking. Maps have touch-sized zoom controls and a position action when an actual fix exists.
- The station board uses one combined board with equal chronological rows for simultaneous services. Transport filters do not switch to another mode-specific nearest station. Individual absolute service timestamps and identities take priority; legacy countdowns are explicitly approximate. Tied departure groups stay together when expanding the list.
- Line, direction, service-notice filters and expanded rows retain stable state across polling. Last-good responses stay visible after failed updates and are labelled stale. A historical source name alone cannot produce a Live label; observation age is checked while the page remains open.
- Nearby results involving different stop areas say **Nearby departures** and show the actual provider stop name per row. An optional map uses provider stop-area coordinates, with a persistent textual list and an explicit distinction from boarding platforms and vehicle positions.
- Composer help is small, optional and dismissible. Route selection, Start/End, stop timetable, filters and Save remain dedicated actions.
- Travel's independent automatic, manual and following location controls now consume the same reactive sharing preference as the shared hooks. Opt-out aborts location confirmation, stops watches and rejects late callbacks. Manual starting-point selection cancels competing GPS work. Stale or inaccurate device positions cannot silently replace a chosen point.

## Saved journeys

Added `SavedJourney`, a migration, authenticated account-scoped API, form validation and reusable `SavedJourneys` / `SaveJourneyButton` components. The collection appears in the empty Travel workspace and in Composer's existing global Saved panel.

Saves contain a reusable request: title, origin/destination names and coordinates, a canonical destination reference when supplied, and transport mode. They contain no itinerary, departure clock, activity or countdown. Opening a save performs a fresh `/api/journey` lookup. Same-account repeated saves upsert one request using a unique request fingerprint; another account cannot read or remove it. Native writes use the shared acknowledged-write helper and show failures rather than marking a save successful prematurely.

Only `2026_10_05_122352_create_saved_journeys_table.php` was migrated on the local review app. The normal deployment migration remains required in deployed environments.

## Verification evidence

Runtime checked: Herd PHP 8.4.23, Node 22.23.1.

- Full TypeScript check passed.
- Combined production frontend build passed, including the shared logout cleanup changes. Existing font-resolution and large-chunk warnings remain; they did not prevent the build.
- Scoped ESLint, Prettier, PHP Pint and whitespace checks passed.
- Saved-journey API suite: **5 tests, 23 assertions passed**. Covers persistence, idempotence, account isolation, invalid coordinates/nested fields, stale itinerary rejection and distinct requests.
- Focused timetable/preview/saved-request Node tests: **9 passed**. Including shared trip-progress tests: **19 passed**.
- Existing Travel browser suite: **25 passed, 1 skipped** across desktop/mobile. The skipped case depends on live board availability for that initial account/location; the deterministic simultaneous-service test and separate real Neumarkt check cover board rendering.
- Focused launch browser suite: **14 passed** across desktop and mobile. Covers layout and 320px fit, preview/refresh/return, optional help, unresolved labels, tied services and station filters, map toggle, real Saved API persistence/fresh lookup/removal, and opt-out despite a granted browser permission.
- Shared logout privacy check on the combined build: **2 passed** (desktop and mobile).

Browser-test routes/departures are controlled fixtures to exercise specific states. Saved journey tests use the real local authenticated API and remove the test save afterward. User browser tabs were not modified.

### Real local feed and map check

Opened an actual request from Köln Neumarkt to Museum Ludwig with Timetable selected. The local provider returned current departures, exact service rows and two real Neumarkt stop-area markers. The OpenFreeMap style returned HTTP 200. Ten departures were visible initially, with more available. No JavaScript page errors occurred and the 390px page did not overflow.

- `travel-station-real-map-desktop.png`: real feed and map at 1440px.
- `travel-station-real-map-phone.png`: same flow at 390px.
- `travel-desktop.png`, `travel-phone.png`: controlled route comparison at desktop / narrow phone.
- `travel-station-desktop.png`, `travel-station-phone.png`: controlled simultaneous-service board.

The screenshot QA persona control is local review tooling, not production navigation.

## Remaining release verification and capability boundaries

- Physical iOS/Android GPS accuracy, permission revocation and app suspension still need device verification. Browser foreground location assistance is implemented; reliable background tracking is not guaranteed.
- This work does not add provider vehicle coordinates or infer boarding platforms. Unknown platform information stays explicit. Provider stop names may themselves contain a platform name; those labels are preserved as supplied.
- Stop-map coordinates identify stop areas, including distinct areas with the same public name. They must not be treated as exact entrances or line-to-platform geometry.
- The real local feed check is an observation, not staging/production provider certification. Credentials, feed coverage, cold fallback response time and deployed cache behavior require environment checks.
- Existing push reminders follow the selected itinerary's scheduled times; automatic re-timing after live delays and automatic rerouting were not added.
- See `travel-backend.md` for timestamp/fallback, individual-departure and GPS validation work and its remaining external checks.

Travel is implemented and locally reviewed. These results do not constitute a production launch or blanket acceptance of untested device/provider conditions.
