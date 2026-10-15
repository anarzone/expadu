# Travel

Preview: http://127.0.0.1:8765/dev/design/departures-next/

Travel uses the shared warm cream palette (`connected/tokens.css`, accepted 6
October 2026); the White & Lagoon composition below is the layout it kept.

Start from the repository root with `python3 prototype/serve.py`. Use this shared
entry point rather than `python3 -m http.server`: its larger pending-connection
queue prevents styles and scripts being dropped during parallel loads. The
loading regression and before/after evidence are in
`docs/reviews/2026-10-04-travel-loading/`.

## The trip follows you — 15 October 2026

Owner-agreed on the Travel directions canvas, row 19 ("why user needs to
click buttons to confirm something and why that many times?").

- One tap starts it (Nearby's "I'm on my way"). After that nothing asks to
  be confirmed: in the app your location (while the page is open), the
  timetable and a nudge one stop before you get off move it on. Each step
  says how it knows ("We'll move on when the tram leaves with you").
- "Not right?" corrects it: I missed the tram (the sample day switches to
  the 13:24, amber, "Missed the 13:14"), I'm somewhere else (Plan a trip to
  the same place, from your location), Stop the trip (asks first).
- The ring is Nearby's: minutes to the next thing, plain while there's
  time, amber when it's leaving or it's the next stop, coral when the
  13:14 is cancelled (Review menu).
- One "where am I": the bar and "2 of 4" in the top bar, "Arrived" at the
  end; the step tag, "Current preview step" and the extra sample notes are
  gone ("Sample · not live" once, in the top bar).
- Your way is a line of stations, a row for each part; waiting and riding
  are both the tram's row, marked "You're here". Getting off is the end of
  the ride, not a row of its own.
- At the stop: what's on the tram's front, "Trams 3, 16 and 18 stop here
  too: take the 4", All departures at Neumarkt, and the boarding points.
- Desktop: the step | your way | a smaller map whose caption follows the
  step (your way goes under the step below 1280px). Phones: the way under
  the step, no Itinerary to open.
- The dashed sample clock (13:10 · 13:12 · 13:28 · 13:41 · 13:44 · 13:48)
  stands in for time and location while reviewing; it's built from each
  trip's own steps, so the evening's tram 18 then bus 133 works too.
- A way you're only looking at (before "I'm on my way") keeps its overview.

## Your next thing as one line — 15 October 2026

Owner-agreed on the Travel directions canvas: option B (rows 17–18), after
"pink means something urgent" (the old card was tinted with tram 4's colour).

- Colour says how much it matters, never which line: neutral while there's
  time, amber when it's time to go or you missed it (sample), coral when it
  won't work (sample). Line colours stay on the badge, the strip and My
  day's rail; the appointment's dot on that rail is ink, not orange.
- Top of Nearby: a ring counting down to leaving, "Leave now · Blue Card
  appointment, 14:00", the way in one sentence, "I'm on my way" and an
  arrow that opens the way as a strip of stations (each name under its dot)
  with the other ways. On phones and in a narrow Nearby the button goes
  under the line.
- Missed or cancelled 13:14 (Review menu): "Take the 13:24" with the reason
  in the line's colour. "I'm on my way" takes it; there's no Switch step.
- Nothing planned yet: the same line offers the next place on My day with
  "Plan the way". Desktop's three-column My day card is gone (it repeated
  Today's places in the panel).
- My day shows the same line on the day's rail, without the destination
  (the appointment comes next) or the arrow.
- Station notices (a long wait, a lift out, paused times) use the same
  look: the icon in a ring, the colour on the edge.
- "Later today" left the card; it's in My day and Today's places.
- The trip page (after "I'm on my way"), fixed the same day: on desktop it
  spans the whole frame again (it had stayed in Nearby's column beside an
  empty strip), the "Journey preview" link back to it hides while it's
  open, and its count and bar follow the five steps the whole journey lists
  ("1 of 5", not "2 of 6" with a hidden "Get ready").

## Plan a trip's panel beside Nearby — 14 October 2026

Owner-agreed on the Travel directions canvas, row 16 (after options A–C in
rows 13–15 and a first build that was reverted).

- Desktop: no Nearby / Plan a trip tabs. Plan a trip is a 420px panel on the
  left (sticky, its own scroll) and Nearby fills the rest. Bigger (top right
  of the panel, once there are options) grows it smoothly to two thirds,
  with the form beside the options; Nearby keeps a third.
- Every size: at rest the panel is "Where to?" with Favourites and today's
  places; typing where to opens From above it, as Google Maps does. On
  desktop a picked place (today's or a suggestion) shows its ways at once.
- One Saved: the header's bookmark (saved ideas). Travel's own Saved
  journeys bookmark is gone; a way goes to My day with "Add to plan" (it was
  "Save this plan"; the step after it says "Added to your plan").
- A favourite fills the "Where to?" line (owner, 14 Oct 2026), as today's
  places do, the stop you're near too: its result says it's a 4-minute walk
  (sample) and offers its departures. Home and Work without an address turn
  the line into "Home address" (as Google Maps does): what you type or pick
  there is saved on this device and fills it. My day's "Add home" opens it.
- ★ Favourites (`favourites.js`, on this device): the star beside a stop's
  name in Nearby adds it; favourites lead the panel's start, with Home and
  Work. Neumarkt is starred as the sample. The star is ink, not amber,
  because amber means "watch out" in the labels.
- Desktop controls are sized for a mouse (52px rows, 38px When switch); the
  air between groups stays. `hub.css` holds the layout; `app.js`
  `syncPanel()` the panel's stage and size.
- Stations as line strips, not a map: each boarding group is a card, and
  each line runs through the stop's dot with where it goes, via which stops
  and when at each end (`nearby-ui.js` `card()`); "Change here" sits between
  the groups. A narrow column (a phone, or Nearby beside a big panel) folds a
  strip into two rows. A picked line opens in place at every size, and on
  desktop the stop's map is under the strips instead of beside them.
- Phones keep "Where to?" at the top (never a bottom input): tapping it
  unfolds Plan a trip down from the field, and its top arrow (now a fold-up
  chevron, "Fold Plan a trip away") folds it back up into it (`slide.css`
  `travel-unfold` / `travel-fold`, view transitions; reduced motion fades).

## One When control, one frame — 14 October 2026

- Plan a trip asked "when" twice: the When row (Leave now / Leave at /
  Arrive by), then the time under it opened an older popover with the same
  three choices again, one of them called "Depart at". The time now opens
  a date and time picker titled after the row's choice ("Be there by" or
  "Leave at"), as the approved canvas board has it. Trip summaries say
  "Leave at" too.
- The Nearby / Plan a trip tabs no longer sit higher on one view: a view
  left with its tabs showing comes back at the top; a view scrolled further
  down is still remembered.
- Travel's column was 1120px, the narrowest of the sections. Every section
  now shares `../connected/page-frame.css` (1440px column, 32px sides from
  701px up).

## The evening from any district, now or back — 13 October 2026

Owner request (with Events' Get there). The evening's trips now come from
Neumarkt, Mülheim and every Cologne district, for leaving now (the sample
day's 13:10) as well as arriving by the start, and the way back from the
venue after it ends (`../connected/sample-journeys.js` and
`../connected/journeys/`, captured with `../tools/capture-journeys.py`).

- `journeys.js` keeps a trip there (its "now" and "arrive by" options)
  and a separate way back per start and event; Leave now shows the 13:10
  options, Arrive by the ones that make the start, Leave at any that
  leave after the time. A district's journeys load when a request names
  it (Plan a trip shows "Finding the ways" meanwhile, and Events'
  hand-off waits for them).
- Leaving now reads "4 h 46 min early"; a way back "8 min after it ends",
  as in Get there.
- My day shows the real way to the evening from the appointment's area
  ("Leave 17:41 → there 18:14 · from Mülheim, line 18, then 133") and, by
  Add home, the way back to Neumarkt, where the sample day starts.
- S-Bahn and regional trains are named Train (badges, Show the way); a
  trip that starts with a bus shows the bus.

## The evening's events can be planned — 13 October 2026

Owner request (with Events' Get there). Plan a trip to Rheinauhafen (the
18:30 Street culture night) or Stadtgarten (the 20:00 cinema) from
Mülheim or Neumarkt now shows real scheduled journeys: what a local MOTIS
planner answered from the VRS timetable for the sample day
(`../connected/sample-journeys.js`, captured 13 Oct with
`../tools/capture-journeys.py`), with changes, bike and walk. Events'
Get there shows the same ones, and its Open in Travel lands here.

- `journeys.js` turns them into Travel's routes (one trip per start and
  event, matched by From and To); a route's steps name each line, so a
  change reads "Board line 18 … Board line 133".
- They keep their real shapes: the map and Show the way draw the way the
  timetable gives (`navigation-model.js`), captioned "The way from the
  VRS timetable · scheduled, not live", and each step sits where it
  happens. A trip with a change says so ("Line 18, then 133 · 1 change").
- They count as the sample day (the To heading, the weather on the way,
  My day); Departures in "We checked" names the timetable. Bike and walk
  arrive five minutes before the start.
- A line's vehicle comes from the journey when it knows it (bus 133).

## Labels — 10 October 2026 (owner-approved, canvas row 10)

What people mustn't miss is a label: an icon and a word, never colour alone,
with strength by how much it changes the trip (`labels.js`, `labels.css`).

- Which line: the line badge carries its vehicle (tram or bus symbol); the
  trip and Plan's options say the word too ("Tram 4").
- When: "Now" is a filled pill in the line's colour with a slow blink (still
  with reduced motion); destinations read as directions ("→ Buchheim").
- It won't work: filled coral (Cancelled, Lift out of order, 1 notice).
- Watch out: amber with an icon ("2 min late" everywhere,
  Only 2 min spare, showers likely, a long wait as a strip along the card).
- Not sure: a dashed outline (Not live · 22:19, Lifts unknown, Either of 2
  boarding points · not confirmed, Scheduled time · not live, Sample).
- Good to go: quiet (Best for you, 12 min spare, No change).

Line cards (owner chose B, 11 Oct 2026, canvas row 11): two cards side by
side, one row per direction inside: → destination in full, then the time
("Now" bold in the line's colour) and what changed. No watermark number.

One strong label per card; a label sits next to what it changes. Paused
times say "not live" once, on the banner and the station, not on every card.

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
- **Removed:** the station board, its time paging and filter menus, stop
  discovery, the mode tabs and the preferences menu, with their files
  (`station-board.js`, `stop-discovery.js`, `station-board.css`,
  `station-refinement.css`, `stop-discovery.css`) and the ~280 rules in
  `style.css`, `workspace.css`, `soft-palette.css` and `station-context.css`
  that only styled them. `timetable.js` stays for the service hand-off and
  Today's brief; `station-map.js` stays for its MapLibre loader.

- **Your next trip leads Nearby** (moved from Plan a trip). Today's rule
  still picks the 13:14 (12 min spare) over the planned 13:24 (2 min). Phones
  show a compact card; desktops add the route line, the shared sample weather
  (`events-next/sample-weather.js`, labelled) and Later today, where each
  evening plan opens its way there. **I'm on my way** starts the trip's
  step-by-step preview; **Other ways** lists the 13:24 and says bike or walk
  times aren't in the sample, with "Compare them in Plan a trip".

- **Plan a trip** follows the approved flow: "Where are you heading?" with
  From/To (suggestions and swap kept), **When** (Leave now · Leave at ·
  Arrive by, with the existing date and time picker) and **What matters to
  you** (Fewer changes and Less walking re-rank; Avoid delays hides captured
  delays; Step-free says lift status isn't in the preview). The other column
  starts with **Today's places**: picking one arrives by its start. Saved
  places say they aren't in the preview; the captured example is a link.
  Results say **what we checked** (departures, notices, weather, your day,
  lifts: each with what the sample has), put **Best for you** first, and open
  an option in place with **Save this plan** and Journey preview. Bike and
  walk are **Other ways** (the captured example has them; the sample day says
  their times aren't in it). Saving shows **Plan saved** with a reminder
  switch and "We keep watching it", both saying the preview doesn't send or
  watch. Mode tabs and the preferences menu are gone; `?mode=` still works.
  Avoid broken lines from the canvas became Avoid delays: the preview has
  delay flags, not line outages.

- **Trip moments.** "I'm on my way" (and Start preview) walk the trip one
  moment at a time, as approved: walk to the stop (with Nearby's boarding
  area and what the front of the tram says), at the stop (the departure time,
  no countdown), on board (Nächster Halt, a buzz switch that says it isn't
  sent in the preview), walk out (Ausgang), and arrived (minutes early, What
  to bring). The main button says the step's action ("I'm at the stop", "I'm
  on board", …). The preview's session, Map/Steps, conditions, resume bar and
  stop timetable link are unchanged (`trip.css`, `navigation-ui.js`).

- **When things go wrong** sits in the footer's **Review** menu (as on
  Paperwork), and `?scenario=` opens one directly. Every situation is labelled
  a sample and uses only sample or captured times:
  - *Your tram is cancelled:* a Change of plan (cause, consequence, „Fällt
    aus“) whose way out is the real 13:24; other lines' times aren't in the
    sample, so it says so instead of showing the canvas's illustrative tram
    18. Taking it switches the trip and says why. Plan a trip shows the
    notice and drops the 13:14.
  - *You didn't set off in time:* the 13:24 to take, bike/walk and taxi
    without times, and how it was noticed.
  - *Late at night:* Merkenich Mitte's 7 Sept capture with each direction's
    next tram after ("then 23:53").
  - *A lift is out of order:* Step-free leads the filters and is on; the
    area without a step-free way in comes first, hidden with its warning.
  - *Live times paused:* clock times marked not live, from 22:19.

- **Phones** get the approved bar: **Where to?** (opens Plan a trip, which
  has its own back row with Saved journeys) and **Nearby | My day**. Desktops
  keep the tabs; the sample note is in the footer on both.
- **My day** (phones; `?view=day`) is the day as a river: now near Neumarkt,
  the trip (its steps and Start this trip), the 14:00 appointment with What
  to bring, the free afternoon with the shared sample weather by the hour,
  the way to the evening's first plan, the two evening plans with their
  overlap choice (the other is dimmed, "Skipping this one"), and Getting
  home. Under a cancelled or missed 13:14 it offers the switch to the 13:24.
  The band's **My day** button opens it too.

Data: `nearby.js` (model, `tests/nearby.test.cjs`), `nearby-ui.js` (view),
`nearby.css`, `plan.css`, `trip.css`, `day.css`. No times, platforms or lift
states are invented.
Browser checks: `tests/nearby-browser.cjs`, `tests/plan-browser.cjs` and
`tests/review-browser.cjs` at 390 and 1440px (360 and 800px checked by hand).

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
