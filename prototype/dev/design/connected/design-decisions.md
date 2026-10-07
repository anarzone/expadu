# Connected Expadu design decisions

Current reference, consolidated 2 October 2026. Owner: Anar. Tracked in EXP-73.

Read this before changing a connected page. This records decisions already made
in the design work; it is not a new visual direction. Later explicit owner
decisions take precedence. Keep the implementation examples and this record in
sync when a decision changes.

## Which design is current

- **Today:** the latest working UI review is `today-next/brief/`: the restored
  two-column reference explicitly identified in the owner's screenshot below,
  with the later content and cohesion refinements recorded here.
  `today-next/` retains the joined-canvas experiment for comparison.
  `today-c/` remains the preserved shared shell and interaction reference. Its
  earlier staggered dashboard is restored at the owner's explicit request.
  The paperwork, connections and weather overview leads on a quiet day; a
  recorded activity today or active journey preview can appear above it. The
  mixed board follows, with journey/day/paperwork cards and three immersive
  event cards. Phones put the practical cards before one swipeable events row.
- **Connected departments:** `events-next/`, `bureaucracy-next/`,
  `departures-next/` and `services-next/`, connected to Today and Composer.
- **Places — approved 29 September:** remove the standalone Places destination
  from main navigation for the first version. Places remain shared content in
  Composer discovery/Saved/planning, event venues and journeys. Today's
  discovery prioritises events over places, below the personal overview.
  Keep the shared place details, Save, Plan and directions actions. Cards must
  work without photography; a missing or failed photo must not reserve a large
  placeholder or exclude a record. Use category, name, neighbourhood and recorded
  facts, with unknown access/hours made clear.
- `places-next/` and `places-guide/` remain reference prototypes at their existing
  URLs. Normal navigation no longer points to them. Retaining this work does not
  mean retaining a separate discovery page in the first release. No catalogue,
  saved record, backend route or data pipeline is removed by this UI decision.
- Root `dev/design/` experiments, `today-blue/`, `departures-c/`, archived mockups,
  and the older v4 document in `docs/design-system-prompt.md` are historical
  references. Do not treat an old palette or screenshot as newer than Today C.
- These are connected prototypes. The Laravel application and its data/persistence
  migration have a separate implementation boundary.

Serve previews with `python3 prototype/serve.py` from the repository root (port
8765). The plain Python server's small pending-connection queue dropped styles
and scripts during parallel loads, making one design appear as several broken
variants. The shared server and `prototype/tests/test_preview_server.py` retain
the connection fix; evidence is in `docs/reviews/2026-10-04-travel-loading/`.

## Today: connected daily canvas — updated 2 October 2026

**Header alignment — owner request, 3 October:** after the palette pass, apply
the approved Today brief's compact header to the same five active previews.
Desktop uses one 72px row for brand, five navigation links and city/Saved/account
utilities. Phones use a compact utility row above the five labelled tabs; only
the tabs stay visible as the page scrolls. Keep at least 44px touch controls,
cyan selection and the approved Phosphor icons. Use Paperwork and Travel naming;
Paperwork's page heading matches its tab. The shared opt-in `today-header`
assets preserve Saved navigation and Composer's toolbar placement. Account is a
small, dismissible preview popover; the city picker continues to identify demo
choices. Today itself, archived alternatives and the real app remain unchanged.
Browser evidence is in `docs/reviews/2026-10-03-header/`.

**Header startup reliability — 4 October:** active Today, Events, Paperwork,
Travel, Services and Composer entry pages include the approved header in their
initial HTML. Do not replace it with the legacy shell during startup. Runtime
enhancement binds controls and updates counts without rearranging the header.
Maintain its generated blocks through `node
prototype/dev/design/connected/prerender-headers.cjs`; `--check` detects stale
output. Icons come from the existing approved set. Desktop and phone dimensions
remain identical before and after initialization. Evidence and reproduction
steps are in `docs/reviews/2026-10-04-header-startup/`.

**Menu state correction — owner review, 3 October:** changing pages must not
change menu typography or shift the links. Keep label colour, weight, size and
spacing constant; cyan background identifies the current page. Remove the press
scale from navigation. Match Today's content-sized desktop links and equal-width
phone links, including the Paperwork label. Use the same Geist fallback stack.
This small state correction also applies to Today's menu, without changing its
page composition.

**Palette alignment — owner request, 3 October (colours superseded on 6 October
by "Design tokens and one palette"):** the approved Today brief was
the visual reference. Match its warm-gray canvas (`#ece9e4`), ivory surfaces
(`#fcfaf6`), quiet ivory controls (`#f4f0e9`) and header (`#f6f3ee`) across the
active Events, Paperwork, Travel, Services and Composer previews. Keep cyan
selections, orange actions, lavender accents and photographic overlays. This is
a colour-only pass: existing page composition, sizing, content and interaction
remain. The shared opt-in `today-palette.css` leaves Today and archived
alternatives unchanged. This supersedes the earlier instruction to keep the
other department palettes unchanged; real-app design implementation is still
being reviewed page by page.

**Foreground contrast follow-up — owner review, 3 October:** matching the canvas
alone is insufficient. Use the shared neutral text and divider tokens for
captions, placeholders and list rows; teal for transport symbols and selection;
darker orange for small text actions on pale surfaces. Travel's planner,
timetable and journey workspace must share the same ivory surfaces and cyan
states. Keep photographic overlays and meaningful status colours distinct.
The opt-in palette now covers these roles; contrast measurements and desktop/
phone checks are recorded in `docs/reviews/2026-10-03-palette/`.

**Travel warm paper & Lagoon — owner review, 4 October (adopted for every page on 6 October):** following a review of
the local app's actual colours in `resources/css/app.css`, apply its cream
neutrals to Travel only: canvas `#faf6ee`, header and raised surfaces `#fffdf8`,
small inset controls `#f1ebdf`, primary text `#211d15`, secondary text `#6e6657`
and dividers `#e6ddcb`. Keep Lagoon cyan selections (`#def5f8` / `#00768b`) and
the darker orange action (`#c23b0e`). This supersedes the white/cool neutral
values below, while preserving the approved side-by-side layout, compact route
rows, interactions and startup stability. Large sections stay on the canvas;
the darker beige is limited to controls. Palette review evidence is in
`docs/reviews/2026-10-04-cream-palette/`.

**Travel input refinement — owner reference, 4 October:** the From/To group uses
the local app reference's near-white cream (`#fffdf8`) and subtle warm border
(`#e6ddcb`). Keep both rows on one surface with a plain divider. Following the
owner's latest correction, remove the input underline; use the active field label
and caret to identify focus. Do not outline or fill the entire row. The swap
control stays transparent at rest, on hover and on press; use teal icon feedback
instead of a background tile. It
retains its 44px target and shows keyboard focus. Disable spelling/autocorrection
for both place-name fields. Text, transport accents, layout and mode selections
stay intact.

**Travel suggestions — owner Maps reference, 4 October:** attach the suggestions
directly below the cream input group as one surface. Use compact 48px rows with
a muted place/transport icon, a regular-weight name and quieter inline details;
truncate long text and omit trailing arrows. Identify the sample catalogue once
below the list. Switching From/To refreshes suggestions for that input; keep
keyboard selection, Escape dismissal and focus return working.

**Travel timetable filter menus — owner correction, 5 October:** Line,
direction and service notices use compact app-owned anchored menus, replacing
browser-native dropdowns. Keep the existing warm paper/Lagoon tokens, 44px
choices, cyan selected rows and a checkmark. Long lists scroll inside the menu
with the close control visible; reopening reveals the selected choice. Choices
apply immediately without changing the captured stop or time. Support keyboard
arrows, Home/End, Escape with focus return, and outside dismissal. Filter options
remain derived from the current captured services; delay notices never imply
live status. This applies to both Travel entry pages. Evidence:
`docs/reviews/2026-10-05-timetable-filter-menus/`.

**Travel timetable usability — earlier iteration, 4 October:** keep the warm
paper/Lagoon palette and the trip planner's side-by-side layout. The timetable
uses one cohesive departure board with the selected stop and time above it,
compact line/direction filters derived from captured services, chronological rows
and explicit midnight dates. Show the captured date/time instead of calling the
frozen sample clock “Now”; one-hour controls identify their interval. Stop search
receives focus when opened. Historical, unrelated network examples live under
sample information rather than a stop-level warning badge.

Desktop departures expand inline; phones use a compact sheet. Show later times
only when captured and retain historical delay warnings in details. Planning
from a departure starts at the boarding stop and keeps the selected service:
remove its initial approach walk consistently from results, navigation previews,
resume state and Composer. Original sample/saved templates retain their original
starting point. Editing the origin clears the boarding context. These changes
apply to the connected preview, not the Laravel application.

**Timetable station-board study — owner requested a more creative revision, 4 October:**
the owner rejected the earlier table-like composition. `departures-next/station.html?view=timetable`
is a separate working alternative for review. Keep the existing cream/Lagoon palette
and shell; feature the earliest matching captured departure, with its direction as
prominent as its clock. Two or three directions are directly selectable; larger
sets use compact filters. Later departures form a chronological continuation beside
the featured service on desktop and below it on phones. Midnight dates, captured
delay warnings, missing data and exact boarding-time handoffs stay intact. The
baseline page remains available until this composition is reviewed.

**Station-wide revision — owner approval, 4 October:** the later staging audit
supersedes the featured-service composition above. On `station.html?view=timetable`,
show equally weighted departure rows, keep simultaneous times together across
pagination, and preserve the station when changing transport type. Use direct line
chips, optional direction/delay filters and expandable details. A saved Neumarkt
capture (4 October, 22:19) provides a busy interchange case; label its rounded
estimates and never advance them as live. Optional Map keeps line selection in
sync and shows actual KVB candidate stop locations; no per-departure platform or
boarding-point assignment is available. Keep the baseline `index.html` intact.
See the Travel README for data boundaries and provenance.

**Travel filters and map-free journey preview — owner request, 4 October:**
reviewed the original `origin/staging` code at commit `89289db9641bb75a563e74b44be9b4717bd61b22`,
not the subsequently modified local React pages. Its adaptive departure types,
live-source labels and GPS/schedule trip progress are the behavior reference. The
earlier study started with all captured stops; the station-wide revision now
starts at the captured Neumarkt interchange. Available departure types stay directly accessible
(All / Tram / Bus); Line, Direction and captured Delay notices are optional inline
filters. Do not offer types or accessibility/crowding filters unsupported by the
records. Type changes reset incompatible line/direction choices; a chosen clock
stays intact. Missing delay data must not be presented as “on time”.

Journey previews now have Map / Steps views over the same itinerary and progress.
Changing view, minimizing and resuming retain the current step and boarding origin.
Steps works without loading a map. This remains a manually advanced captured
preview: no live GPS, vehicle positions, service updates or automatic rerouting
are connected here. The real app's existing live feeds and GPS/schedule progress
remain a separate integration boundary. See the Travel README and
`docs/reviews/2026-10-04-travel-filters/` for verification and limitations.

**GVB study applied to Travel — owner approval, 4 October:** refine the separate
station preview without changing the approved palette or baseline composition.
The stop chooser has Search, Nearby, Recent and Saved views; recent/saved records
come from actual choices. Nearby uses recorded coordinates, a manual sample area
or explicitly requested location. Its current coverage is one geocoded stop out
of four captured stops, not a live city-wide search.

Keep active direction and notice filters visible when their controls close.
List/Map retains the selected service, candidate stop and each view's scroll
position; a compact mobile map keeps a departure summary visible and expands on
request. A candidate map point is not an assigned platform. Carry line, direction,
approximate departure and capture provenance into the planner and Composer, and
restore them on return. Clear incompatible service preferences when origin, time
or mode changes. Repeating a saved trip starts a fresh request instead of replaying
the old journey. Existing Map/Steps previews remain manually advanced sample
journeys. Live tracking, fresh routing and exact boarding assignments remain data
integration work. Evidence: `docs/reviews/2026-10-04-travel-refinement/README.md`.

**Travel White & Lagoon — earlier owner-approved, 4 October:** the owner selected
**White & Lagoon** and **Side by side** from the ten-direction `travel-lab/`
study. This supersedes all Travel gray/ivory/peach trials below. Use white
`#ffffff` for page, header and route rows; `#f7fafa` for quiet input groups;
`#202a2d` primary text; `#536469` secondary text; `#00768b` travel cues;
`#def5f8` selections; `#c23b0e` orange actions; and `#d9e3e5` dividers.
Keep it local to Travel until the owner approves a wider rollout.

At 900px and wider, keep the editable trip form on the left and compact route
options on the right, divided by a subtle vertical line. Journey details expand
inside each row; Map, saved journeys and the existing Composer handoff remain
available. The form stays visible after desktop searches. Phones stack the form
and results, then use an editable summary after search. Underlined Plan a trip /
Timetable controls replace the large segmented view rail. Short route-line,
disclosure and save feedback respects reduced motion. Historical samples remain
labelled, and exploring one preserves the original request. Timetable stop
controls sit beside departure rows on desktop. Evidence is in
`docs/reviews/2026-10-04-travel-lagoon/`. The gallery remains available for reference;
the implemented preview is `departures-next/`.

**Travel surface review — owner request, 3 October:** after reviewing the repeated
light/dark bands and researching colour roles, trial a light continuous canvas
and header (`#faf9f6`) with gently darker functional groups (`#efede8`). Nested
From/To fields and travel-mode rails share their planner's surface. Cyan identifies
selected controls; orange actions and existing semantic colours remain. Avoid
adding another neutral fill just because content is nested. Keep this in Travel's
local `soft-palette.css`, replacing its earlier lighter-card trial. Composition,
data and interactions remain intact. This is authorised for Travel review only;
it does not approve rollout to Today or other pages. Evidence and research links
are in `docs/reviews/2026-10-03-travel-palette/`.

**Travel tone correction — owner review, 3 October:** the `#efede8`
functional groups were rejected as too dark. Keep the surface roles and layout,
but lighten the groups to near-white `#f6f5f2`, hover/press to `#f0efeb` and
dividers to `#e3e0d9`. The continuous `#faf9f6` page/header and cyan/orange accents
remain. Travel only; screenshots are in the review's `lighter-surfaces/` folder.

**Travel brightness — earlier owner review, 3 October:** brighten both neutral
tones and increase their separation without returning to darker gray groups.
Page/header now use white (`#ffffff`); groups use a less creamy near-white
(`#f7f7f5`), with `#f1f1ef` hover/press and `#e3e3e0` dividers. Keep the same
surface roles, layout and cyan/orange accents. Travel only; evidence is in the
review's `white-canvas/` folder.

**Travel soft peach — approved owner choice, 3 October:** after comparing Soft
peach, Cyan mist and Current neutral, the owner selected Soft peach. Use white
(`#ffffff`) for the continuous page/header, soft peach (`#fff5ef`) for functional
groups, `#ffebde` for hover/press and `#eadbd2` for dividers. Nested fields retain
their shared surface. Keep cyan selections and travel cues, orange actions, text,
layout and interactions. The sheet footer fades into the same peach surface.
This supersedes Travel's neutral-tone trials above and remains local to Travel's
`soft-palette.css`; rollout to other pages is a separate decision. Review evidence
is recorded in `docs/reviews/2026-10-03-travel-palette/soft-peach/`.

**Travel workspace and colour balance — owner request, 3 October:** keep the
approved white canvas and soft peach, and make the desktop planner and smaller
sample journey one connected workspace. Reduce the sample's visual priority;
the form remains the primary action. Route results use the same surface for the
summary, comparison list and full itinerary. The selected desktop route uses cyan;
its time summary uses a connected teal line instead of a second large cyan fill.
Reserve cyan elsewhere for selections, transport badges and route cues. Desktop
timetables place stop/time controls beside shorter departure rows; phones stack
the same content with 56px rows and approximately 99px journey choices. This
supersedes the earlier separated planner/sample composition and cyan sample/time
panels on Travel only. Existing journeys, Composer handoffs and sample labels
remain. Local `workspace.css` and minimal entry markup implement the review;
shared styles and other pages are unchanged. Evidence is in
`docs/reviews/2026-10-03-travel-workspace/`.

**Owner correction — exact reference restoration, 2 October:** the daily
brief and its subsequent timeline-only correction were rejected. The owner
supplied the exact earlier image, retained as
[`continuity-desktop.png`](../today-next/review/continuity-desktop.png).
Restore that composition at [`today-next/brief/`](../today-next/brief/README.md):
the rounded agenda column contains the full appointment/journey, Saved for later
and Coming up; Paperwork/City help and two event cards form the right column.
Below them, Pick up where you left off contains the cyan Composer continuation
beside the existing requests or starting actions. Keep its compact shared header,
colors, content and interactions. This supersedes both the daily-brief layout
and the joined-canvas treatment for this active preview. Restoring a line inside
a different composition does not satisfy the requested restoration.

**Content improvements approved, 2 October:** enrich that exact composition with
a compact three-row stop timetable, a same-area idea around an existing plan,
three smaller event listings below the immersive photography, and the actual next
decision in unfinished Composer work. Keep only one relevant paperwork action.
Retain upcoming plans, saved journeys and service requirements with their real
dates and state. Stop selection, saving and disclosure choices work inline;
the shared Composer and planning/confirmation boundary remain intact. Captured
departures keep their capture date. Additional design events are labelled
fictional samples. City updates use official source links until a verified feed
is connected; do not fill the area with invented news. Implemented only in
`today-next/brief/`, with focused tests and desktop/phone review documented in its
README. Other design alternatives remain preserved.

**Latest cohesion refinement, 2 October:** focus on empty areas and disconnected
modules while keeping the restored two-column composition and full timeline.
Use one ivory workspace with faded separators and purposeful cyan/lavender
transitions. Remove the wide gutters and repeated enclosing corners. Composer
continues directly below the personal column; saved journeys, requests and city
sources follow the discovery column. Keep the same column widths throughout,
and size each continuation to its content instead of stretching it to its
neighbour. Compact the forecast beside City help and keep the footer close to
the actual content. This refines the restored reference's surfaces and lower
placement, not its appointment/journey grouping or shared Composer component.
Applied only to `today-next/brief/`; prior alternatives remain available.

**Design guide — 6 October:** foundations, components and the live mock
designs are collected in [`guide/`](../guide/README.md)
(<http://127.0.0.1:8765/dev/design/guide/>). Update it with any change to the
rules below.

**Design tokens and one palette — owner approval, 6 October:**
`connected/tokens.css` is the single source for colour roles, type, weights,
spacing, radius, elevation and motion; `today-palette.css` and Today's
`style.css` alias it. The accepted Travel "warm paper & Lagoon" colours are now
the palette for every page: canvas `#faf6ee`, header and surfaces `#fffdf8`,
quiet/inset `#f1ebdf`, hover `#e9e0cf` (added for touch feedback), lines
`#e6ddcb`, text `#211d15`, supporting text `#6e6657`, Lagoon selections
`#def5f8` / `#00768b` and the darker orange action `#c23b0e` (hover `#a9320b`).
All text pairs meet 4.5:1; form-field borders (`#8a8277`) meet 3:1. Visible
hard-coded colours in the shared header, Discovery and Travel now use tokens;
Composer's own controls were finished on 7 October (below). The Today mock's scales were applied with
`tools/normalize-scales.mjs --apply`: 29 font sizes → the 10-step scale plus
two fluid ranges, 21 radii → 8/12/16/24 + round, fake 550/650 weights →
600/700 (identical rendering). New CSS uses the tokens, never raw values; only
the four loaded Geist weights exist.

**Shared scales, components and guide sections — 6 October:** the approved
scales now cover the department mocks and shared shell too
(`tools/normalize-scales.mjs --set departments`): every page renders only the
four Geist weights and the 8/12/16/24/round radii, and text uses the scale
(new steps `--text-display-sm` 32px and `--text-display-lg` 52px). Poster
titles and dates in `events-next/festival.css` keep their expressive sizes as
illustration. `@font-face` weights stay numeric (variables are invalid there).
On phones the city choice is plain text on every page, as on Today. Today's
components moved into `connected/core-components.css` and
`connected/day-components.css` (pixel-identical across all 20 Today captures);
the guide renders them from those files. One breakpoint set was applied on 7 October (below).

**One breakpoint set — owner approval, 7 October:** every page uses small
phone ≤359, phone ≤700, tablet 701–999 and desktop ≥1000, in CSS and in
`matchMedia` (`tools/normalize-breakpoints.mjs` mapped 93 queries and 5 script
checks). 390px phones and 1440px desktop stayed pixel-identical; only
in-between widths moved (for example Travel is stacked up to 999px and
narrow phones' rules now cover all phones). Narrow-desktop fixes became
intrinsic instead of extra breakpoints: the Events poster row uses an
auto-fit grid with a 260px minimum, the Events filter row wraps by itself,
and Today's 1000–1199px refinement was removed. The only other width rule is
≥1500px, which caps content width.

**Composer hand-offs land on what you asked — owner request, 7 October (in review):**
whatever a page hands to Composer (Travel's trip help, a chosen route, an
event's Review plan, Paperwork step help, a service request) is the newest
entry and is scrolled into view, not the top of an earlier conversation. A
Travel trip reads as your request ("Help with my trip from Neumarkt to Mülheim,
arriving by 14:00 on Wed 30 Sept") with its details in words; a selection
Composer can't add yet (another day's plan is unfinished) says so in the
conversation. "When should I leave for my 14:00?" answers from the
appointment's trip with Today's rule. Today's departures board shows the stop
at the sample day's clock, like Travel's timetable.

**Composer: rich answers, coloured starters and Your day — owner request, 7 October (in review):**
from the App in colour concept. On desktop, Your day sits beside the
conversation (the tram to take now, the appointment, today's plan, the visa
deadline from your records, the area after the appointment, tonight, coming
up); tapping one asks about it, and it hides while My Plans, Saved or History
is open. Phones show the day's facts as one row above the starters. Starters
use their section's colour and only appear for what the day has. Answers lead
with a short verdict, then their own block: the appointment trip as steps
with Open in Travel; your own checklist's ready count linked to Paperwork
(no guidance); event posters (labelled AI illustrations); step-free access
from the event's or venue's listing, otherwise "Not listed". Composer keeps
the cream palette. The records now live in `connected/sample-day.js` for
Today and Composer. Details are in `composer-studio/README.md`.

**Travel review fixes and one timetable — owner request, 7 October (in review):**
the Timetable tab is the Neumarkt station board; Neumarkt is one stop that
opens on the sample day with its line 4 and offers the 4 October capture as the
full board (`station.html` forwards). Bus badges and map markers no longer use
the action orange; the board says once that its times are estimates, and times
are no heavier than the section title. The captured example follows the travel
mode; Composer help appears once there's a trip, and "No route available"
offers Ask Composer and the start's departures. Walking time is aligned
choices, the switches' "off" is light, and on phones nothing sits fully under
the floating Composer button. The To field suggests the day's places; stops in
the place layer link to Around here. The shared Composer field inherits the
page font and its close button has its own surface. Details are in
`departures-next/README.md`.

**Paperwork follows the v2 backend rules — owner request, 7 October (in review):**
submitting moves a task to Submitted without finishing its step; Update
progress offers only the changes the backend accepts (waiting only after a
submission, completion only when every step is finished, blocked steps can't
be finished) with its labels; "I don't know" and "Skip for now" are different
answers; the person switch lists only people you can access; Review opens a
read-only persona preview with no reset; and Address registration shows the
§17(1) BMG legal deadline with its source, its date unknown without a move-in
date. Details are in `bureaucracy-next/README.md`.

**Place details — owner request, 7 October (in review):** with the places
pipeline's new tags, restaurants and fast food always show their cuisine
("Italian · Pizza", or "Cuisine not listed"); food places show outdoor seating
and one diet chip only when recorded, never implying "no"; libraries show
Wi-Fi when recorded; and sheets say "Sources disagree" when they do. Woods,
lakes and nature areas join parks as "Parks & nature" with "about" distances
and no hours or address slot. Composer answers "somewhere green" and "a quick
bite" nearby. Sample data: 59 places from the 7 October local export. Details
are in `around-here/README.md`.

**Travel on the sample day — owner request, 7 October (in review):** Travel
opens on Wednesday 30 September, 13:10, with the trip Today shows. "Your next
trip" leads the planner with the tram to take now: Today's rule for official
appointments (ten minutes in hand) picks the 13:14 over the planned 13:24, and
See both trams compares them. Today's hand-off and a typed Neumarkt → Mülheim
open straight on both options ("12 min to spare", "Only 2 min to spare");
Mülheim is a suggested destination and Cycle/Walk say they aren't in the
sample. Details, preview, saved journeys and Composer hand-offs keep the trip's
date and place. The timetable opens at Köln Neumarkt on the sample day with
only line 4, says so and links to the stop's 7 September capture. The trip is
scheduled sample data from `connected/sample-day.js`; the 7 September routes
stay as a captured example with their date, so other pages are unchanged. The
station board study keeps its 4 October capture. Details are in
`departures-next/README.md`.

**Paperwork on the sample day — owner request, 7 October (in review):**
Paperwork opens on the Blue Card plan Today shows, with Today's 14:00
appointment and the sample day's dates (fresh start stays under Review). The
overview starts with this week's recorded dates — today's appointment and the
visa expiring tomorrow in amber — and the submission step says so. These are
the person's own dates, never computed legal deadlines. The header shows the
person's name instead of a second avatar, "Your situation" instead of
"Details" and an Overview/Documents switch; scenario and reset controls live
in a footer Review menu. Details are in `bureaucracy-next/README.md`.

**Places as a layer — owner request, 7 October (in review):** following the
places data review, places stay out of the navigation and appear where a need
comes up: Composer answers "near" questions with up to three real places and
Show on map; Today lists everyday places listed as open when the next plan
ends; the Events sheet takes accessibility from the venue's listing; and an
"around here" view lists one kind near a point beside a map. Cards are
photo-free: a map picture, the kind, name, area, straight-line distance, listed
hours and wheelchair access only when recorded; unknowns read "Not listed" and
listed hours are never a live check. Sample data: 47 real places from the local
catalogue export (OpenStreetMap). Details are in `around-here/README.md`.

**Events polish — owner request, 7 October (in review):** event facts are
one quiet list (Admission, When to arrive, Language, Who it's for,
Accessibility) with unknowns as soft "Not listed" instead of coloured tiles;
no-match messages name the search and day and point to another day that
matches; the featured poster is captioned "Coming up"; each category has one
icon everywhere; long German titles wrap at whole words. Filters stay
compact (owner rework): Free, Saved, Travel and Sort are icon buttons, the
search keeps the room, and Category and Area are named dropdowns with a
chevron that turn cyan when set. Details are in `events-next/README.md`.

**Events on the sample day — owner request, 7 October (in review):** Events
opens on Wednesday 30 September with the sample day's two evening events, the
same ones Today and Composer show. The captured 8 September programme stays as
a past sample: its cards say "Past", have no Save or Plan, its sheet says it
already happened, and other dates point to both. Featured events are upcoming
only and appear once while browsing (filtered results still list every match);
the featured poster is shorter, so the filters show on the first screen and no
control sits under the Composer button on common phones or laptops. Event
details add the English meaning of German titles, the city's description where
Expadu has one (labelled with its source listing) and a Good to know list where
language, ages and accessibility say "Not listed" when unknown. Planning says
"Add to plan" and "It isn't confirmed yet"; exhibitions are flexible visits
within opening hours; Get there starts from where you'll be (for example after
the Blue Card appointment). A recognised starting point (a Cologne district,
Neumarkt or a listed venue) shows straight-line distances on cards and offers
"Closest first"; unknown places show no distance. Details are in
`events-next/README.md`.

**Composer polish — owner request, 7 October (in review):** new replies scroll
into view above the input; Composer's Quick look, Add appointment and Try
Composer are bottom sheets on phones (drag down to close); Quick look shows the
listing's facts and exactly what it lacks, with Add to plan; earlier plan
versions live in Version history instead of repeating in the chat; My Plans
marks Today and has one date control; approval wording is "Ask me first" /
"Make small edits for me"; the start screen offers starters from your day.
Details are in `composer-studio/README.md`.

**Composer helpful replies and one sample day — owner request, 7 October (in review):**
Today and Composer share one sample day, `connected/sample-day.js` (Wednesday
30 September 2026, 13:10, its appointment, coming plans and evening events);
Today's model reads it rather than its own copy. Captured listings (Events,
Travel) keep their capture dates and stay labelled. Composer answers everyday
questions with sample data or an honest gap plus suggestions, never the same
fallback twice; the department Composer bar sends what its page shows
(`connected/page-context.js`, shown as a removable "About: …"); journey and
plan requests keep the details given; questions about plans never change them;
past listings can't be added. Every other page's default view stayed
pixel-identical. Details are in `composer-studio/README.md`.

**Today sheets, details and Composer requests — owner request, 7 October (in review):**
Today's journey, plan details, forecast and Add a plan open as a bottom sheet
on phones (drag down to close) and a compact centred dialog on larger screens,
like the Event sheet, with a light scrim and no blur. The journey sheet leads
with the leave-by time and lists each step; plan details add What to bring,
the journey, booking status and Directions/Edit/Remove. Each screen has at
most one contextual Composer action besides the launcher. Composer requests
from Today are written in natural language with named dates and clear when the
sample day changes; Composer's sample responder reads named dates and routes
journey and planning requests before paperwork words. `connected/discovery.css`
moved to the shared palette too. Every page's default view stayed
pixel-identical; details are in `today-next/brief/README.md`.

**Composer on the shared palette — owner approval, 7 October:** Composer and
the department Composer bar had kept their blue-grey palette. Their colours now
come from `tokens.css` by role (`tools/composer-colours.py`, 345 declarations,
plus the shell's bar and focus colours): greys and lines are the cream
neutrals; selected, added and confirmed states use cyan (including **Add to
plan** and **Added**); fixed appointments use lavender; overlap notes amber;
errors coral. Composer's name is ink beside its
teal mark; answers are ink. The toast matches Travel's (light surface, ink text,
orange action). Photo overlays, category illustration tiles, the loading spark
and the teal Composer launcher keep their colours. Files shared with older
previews carry fallbacks. Every other page stayed pixel-identical at 390px and
1440px.

Fixed in the same pass:
- The Events, Paperwork and Services toast showed white text on the light
  surface; it now uses ink text and an orange action.
- Composer's toast sits above the input, which grows when a plan exists (it
  covered the plan row); on department pages it rises above the open Composer
  bar.
- Opening **Edit time** keeps the control in place and opens the editor below
  it, instead of moving it after Swap place and Remove.
- On desktop the Saved, My Plans and History panel starts below the header, so
  the city, Saved and account controls stay usable.
- On desktop the Composer bar's name and Minimize align with the 800px
  conversation column.

**Colour roles — owner approval, 6 October:** one job per colour, so colour
says what something is and what can be tapped. This consolidates the shared
colour language below; it supersedes the cyan wash on the Composer
continuation and the per-section Composer prompts (2 October). Values are the
`today-palette.css` tokens.

| Role | Colour | Use for | Never for |
| --- | --- | --- | --- |
| Content | `--color-text` `#211d15` | Titles, data, text links (with an arrow when they leave the page) | — |
| Supporting | `--color-text-soft` `#6e6657` | Details, labels, capture notes, secondary links | Primary actions |
| Action | `--color-action` `#c23b0e` | The one main action in a section (e.g. Add a plan), event Plan buttons | Ordinary links, status dots, decoration |
| Selection & travel | `--color-select-bg` `#def5f8`, `--color-select-text` `#00768b` | Selected/current state, line badges, the journey card, travel icons, the Composer mark | Section backgrounds, cards that are not travel |
| Paperwork | lavender `#efe7fb`, violet `#67409a` | Paperwork icons, the progress indicator, the "What to bring" row | Text links and other copy |
| Attention | amber `#fff1d6` / `#ffe49a`, ink `#694207` | Personal deadlines, tight timings, delays | Decoration |
| Conflict | coral `#fff0eb`, ink `#a2300e` | Not enough time, a plan that cannot work | Ordinary errors in copy |

- Sections share one ivory surface and are separated by faded hairlines, not
  by individual tints.
- At most one orange action per section. Secondary actions are outlined in ink.
- Data (temperatures, departure times) is never heavier than its section title.
- Composer is reached from its floating launcher and one contextual "Need a
  hand?" where help is genuinely needed, not a sparkle on every section.

**Connected day — owner approval, 6 October:** following the end-user UX
review, Today joins the person's records instead of placing them side by side.
This supersedes "quiet days start with local essentials" below.

- A personal deadline from the user's own records within three days leads the
  page as one compact, tappable strip (for example "D visa expires tomorrow").
  It uses the record's wording and the reviewed task title; it never adds
  consequences, fees or rules.
- An appointment recorded from Paperwork shows "What to bring" with the
  checklist's ready count and links to it.
- The nested journey shows when to leave (including the walk to the stop) and
  the time to spare. Official appointments need at least 10 minutes, other
  plans 5; when the margin is tighter, offer an earlier service of the same
  journey if one is still reachable.
- Consecutive plans in different places show the travel between them: fits,
  tight, not enough time (with the earliest workable start), or not checked.
- A delay that makes the person late opens two or three alternatives inline,
  each with leave and arrival times; opening one continues in Travel.
- With nothing in the plan, ideas lead (phones and the desktop right column),
  framed by time of day and weather. The heading says "Ideas for this
  afternoon" and "Nothing in your plan yet", never that the person is free.
- All of this is sample data in the mock and stays labelled as such.

**Mobile priorities — approved 2 October:** assess Today by the person's current
need, not by stacking whole desktop columns. Imminent commitments/departures and
relevant travel changes lead, retaining the full nested journey and recovery
actions. Due personal reminders and unfinished proposals for today may lead
next; future or undated drafts remain secondary. A suitable important event may
lead when no urgent personal need takes precedence. Quiet days start with local
essentials; missing plans must not be presented as proof of free time. First
visits must not assume appointments, a residency checklist or required onboarding.

On phones, put compact city/paperwork information and worthwhile discovery ahead
of Saved, future plans and older Composer work. Keep one compact paperwork
continuation, optional timetable details and the five-tab menu; let utility chrome
scroll away. Completed/past plans remain available in Earlier today, with Undo
for completion. Do not suggest expired or overlapping events or duplicate the
same contextual event. Saved records and draft dates remain intact. Keep the
page stable while reading, saving or writing, rather than automatically
reshuffling modules. The ordinary desktop two-column composition remains the
reference. Implemented in `today-next/brief/` only; see its README for labelled
scenarios, verification and the separate real-app integration boundary.

The owner approved developing the agenda-led direction after requesting a fuller
desktop composition. The working preview is now at
[`today-next/`](../today-next/README.md), linked from the comparison gallery.

- Keep the timeline and its nested journey as one appointment group.
- On desktop, use roughly 40% for the agenda/saved ideas and 60% for practical
  help and discovery. Join Paperwork and City help into a continuous surface;
  show a next personal action rather than filling space with navigation cards.
- Use one horizontal event strip. Phones put practical content first on a
  normal day; quiet days close unused space. A designated important event may
  lead, but a relevant personal interruption or imminent appointment wins.
- Reuse the approved Event sheet, time controls, detail dialog and Composer.
  Keep the top menu, purposeful symbols and faded separators. The working
  preview uses the approved Paperwork and Travel navigation names.
- Retain swipe/double-tap reveal, explicit Delete and Undo. An unavailable
  journey cannot imply punctuality; destination arrival includes the final walk.
- The owner's subsequent header/footer comparison supersedes the large cyan
  greeting and orange Composer invitation strip. Use one compact desktop header,
  a small greeting/date line, and immediate useful content. Keep top navigation
  in place; smaller screens use two compact header rows. Search remains deferred.
- Keep dated upcoming personal plans below the agenda, collapsed initially on
  phones. Put Composer prompts beside their relevant day, upcoming or Paperwork
  section. The existing Composer component stays intact; only its expanded state
  reserves viewport space. End with compact utility/provenance information and
  local clearance for the floating launcher.
- **Balanced palette — latest owner correction, 2 October:** keep warm gray
  and ivory as the large canvas/card surfaces in `today-next/`, but avoid making
  everything gray or cream. Restore cyan to the active navigation, nested
  journey, route connector, small date tiles, travel/city icons and Composer
  launcher. Paperwork has a restrained lavender tint; orange actions and yellow
  warnings retain their purpose. This supersedes the entirely neutral treatment.
  Other department references stay unchanged. Preserve the compact composition
  and existing Composer layout/interactions.
- Keep all useful sections. Sample records remain labelled; no live news or
  legal requirements are invented to fill the space.
- **Lower-page continuity — approved 2 October:** add Pick up where you left off
  below the daily canvas. Read existing unfinished Composer plans/messages,
  saved journeys and kept service requirements. Use a Composer summary
  beside compact Travel/Services rows on desktop; stack on phones. Show the
  actual next action and preserve the original draft, date and confirmation
  boundary. Replace the fixed Saved idea with the shared collection. Empty
  collections use compact starting actions, never fabricated personal records.
  Keep the existing Composer and event rail. Timetables remain captured samples;
  saved service requirements do not imply a booking.
- **Earlier cohesion correction — superseded by the exact reference above:** the owner found the separated cards too
  modular. Join the agenda, practical sections, events and continuation on one
  ivory surface with fading separators. Remove wide background channels and
  repeated outer corners. Align the lower area with the agenda/practical columns;
  on wide screens saved journeys and requests follow the Paperwork/City help
  alignment. Keep cyan journey details, lavender identifiers and event imagery.
  Phones retain one continuous reading order. This refines visual grouping, not
  the existing content, actions or Composer component.

This is an interactive design preview, not the Laravel implementation. Its
isolated sample agenda is not yet the shared Composer day projection, and its
paperwork summary is a labelled fixture. See the README for the integration
boundary and observed checks. The shared default Today route and earlier
`today-c/` composition remain unchanged pending review.

## Today: fresh appointment/journey reference — 1 October 2026

The owner prefers Final icon balance (v9) and requested a fresh render with four
specific corrections. The new review images are in
[`concepts/today-modern-2026-09-30/fresh-v10/`](concepts/today-modern-2026-09-30/fresh-v10/README.md).
They are generated from a blank canvas rather than repeatedly editing the v9
raster. v9 remains alongside them in the comparison gallery.

- One appointment owns its nested journey. Keep the large appointment time and
  title as the anchor; Line 4 is subordinate travel context, not a second
  timeline item. Normal and delay states share this grouping.
- In the delay state, show Alternatives and an adjacent contextual Composer
  button on white surfaces with orange outlines. Put this recovery row below
  the cyan journey strip with a soft separator, inside the same appointment
  group. This supersedes the filled orange Alternatives treatment.
- Join departure and arrival with a cyan directional line and arrowhead. It
  describes travel direction, not a separate opening action.
- City help uses a plain heading. A sun belongs to weather/Forecast only.

Keep the existing Composer component. The new contextual recovery entry retains
the associated appointment/route context and the current draft; nothing sends
automatically. Existing route-detail and global entry remain available.
Save/Plan separation, direct process opening, useful identifiers, saved-versus-
confirmed distinction and gesture reveal before removal remain as agreed.
The raster boards remain visual references. The later working prototype is
described above; the application migration is still separate.

## Today: modern concept and compact menu — 30 September 2026

The owner prefers the new assistant-led visual direction in
[the modern Today concept](concepts/today-modern-2026-09-30/README.md). It keeps
roughly 70% practical day/city-assistant information and 30% event discovery,
with connected agenda/journey information, direct paperwork rows and adaptive
priority for a relevant route interruption or exceptional event.

- Keep the menu in its existing position above the content. No sidebar or
  replacement bottom navigation.
- Keep the current Composer design and interactions.
- The owner chose **Paperwork** instead of Bureaucracy and **Travel** instead of
  Departures in navigation. The menu order is Today, Events, Paperwork, Travel,
  Services.
- The selected mobile treatment keeps all five labels visible: 20px outlined
  icons, regular-weight 12px labels, a 48–52px row and at least 44px touch areas.
  Use a soft cyan selected pill without a second underline. These are design
  targets that need measurement in an approved working prototype.
- The latest six desktop/mobile views and interaction sheet are in
  `concepts/today-modern-2026-09-30/final-v9/`. Earlier concepts are retained.
- The review separates saved ideas from confirmed agenda entries, separates city
  tools from Paperwork and restores one horizontal event row on mobile. Keep room
  for the current Composer.
- **Latest owner correction: keep useful identifiers and creation symbols.**
  Restore the small document/form symbol beside City registration and a simple
  orange plus beside Add a plan. Keep one leading train/mode symbol beside
  journey times across normal and delay states. These identify content or an
  action; they do not add separate opening buttons or focus stops.
- **Keep direct opening.** Tap/click the
  paperwork row, route strip or event card to open its content directly. Do not
  add information/map/folder controls just to open them, or replace those symbols
  with repeated text buttons. Dots menus are for secondary actions, not every row.
- A planned appointment has one neutral dots menu: Details and Remove. Its body
  keeps swipe/double-tap deletion gestures. Menu Remove reveals the same compact
  red action; a separate activation removes, with Undo. Do not navigate on the
  first body tap and thereby break the double-tap. Ordinary content opens on one
  tap without waiting to detect a second.
- Keep separate bookmark Save and calendar-plus Plan controls, labelled navigation,
  meaningful status, weather and city tools. Repeat an icon consistently for the
  same meaning. Remove decorative heading symbols and the redundant signpost next to
  Alternatives; keep that recovery action visible on mobile.
- Keep one global Composer launcher. The route strip opens route details, where
  contextual Composer access preserves route context, draft and conversation
  without an automatic send. The existing Composer component stays unchanged.
- Exact Phosphor SVGs, licence and source manifests remain in `action-icons-v7/icons/`;
  the new dots asset is in `direct-actions-v8/icons/`. Keep only symbols required by
  the latest rules, including the restored train, file-text and plus symbols, not
  the superseded opening controls. Leave the logo for a separate discussion.
  See `final-v9/action-rules.md`.

This is the preferred visual direction and naming decision, not a completed
migration of the connected prototypes or Laravel application. Today C remains
the existing interaction reference until implementation is authorised.

## Today: adaptive layouts for review — 30 September 2026

The owner requested three layout examples: an important event first, a relevant
route delay leading inside the overview, and another ordinary-day option.
They are implemented separately at `today-c/adaptive/`, with a review switcher
and labelled sample signals. The accepted `today-c/` page is preserved.

The examples retain the original mixed board and shared controls. Everyday leads
with the overview; the event version promotes a single existing card and removes
its duplicate below; the delay version enlarges and promotes connections inside
the overview. Phones show the highest-priority item first, with practical content
and one remaining events row below. No useful category is removed.

The proposed priority is a relevant, active route interruption, then a designated
important event today, then the normal overview. Expired or unrelated signals
do not lead. This is a reviewable presentation rule, not a connected live service
or final approval of all ranking rules. The switcher is only for comparing the
examples. See [the previews and validation](../today-c/adaptive/README.md).

## Today: restore the earlier staggered dashboard — 30 September 2026

The owner rejected both the removal of useful content and the subsequent
generic arrangement. When asked which baseline to restore, they chose the
**earlier staggered Today dashboard**, rather than the later Festival lead and
supporting cards composition. This correction supersedes those Today layouts.

- Keep the paperwork/connections/weather overview, the relevant saved-plan or
  proposal strip, and journey/day/paperwork cards. They remain available on fresh
  and quiet days; an empty personal record must not empty the main page.
- Shorten labels and supporting copy. Use the shared icon actions and compact
  mobile rows, with readable text and the existing orange arrow feedback.
- A compact next action may lead for a recorded activity today or an active
  journey preview. Preserve exact dates, destinations and resume context.
- Restore the original six-card staggered desktop placement. Keep the current
  event photography, frosted Save and calendar-plus actions in its tall cards.
  This does not restore standalone Places or its previous discovery content.
- On phones, show the overview and practical actions first, then one draggable
  row containing all three events. Do not split discovery into a large lead and
  a second "More good plans" section.
- Keep the optional, dismissible welcome. Show actual recorded paperwork state
  and explicitly labelled sample weather; do not invent deadlines or live alerts.
- Keep all paperwork process cards accessible through their controls, with each
  card opening the account holder's exact process and appropriate view.

**Direction carried into the adaptive review above:** Today should answer what matters to the
person today. The owner wants content priority to reflect their agenda and
exceptional relevant news; ordinary events should not always lead. Composer
provides day/plan context. The proposed priority rules and "Your day" overview
label are review alternatives, not a newly implemented news service.

Review evidence and isolated scenarios: [Today notes](../today-c/README.md).

## Preferred event direction: Concept B / Festival — 29 September 2026

The owner rejected the implemented split image/white-information cards and the
hero disappearing on filter changes. After reviewing two visual concepts, the
owner said they are leaning towards **B / Festival**, and specifically liked its
search treatment and logo. B is the preferred direction for the next visual
pass; the generated board is a concept, not an approved implementation of every
header, navigation or content detail.

Reference: [Concept B / Festival](concepts/events-festival-b.png). The imagery is
AI-generated and the event is sample content, not an actual event photograph.

- Build the next event design around an immersive image surface, expressive
  poster composition and vivid photography or suitable event artwork. Integrate
  titles and essential facts into the image with a readable, fading backdrop.
  The separate large white information block is superseded.
- Prefer consistent compact icon actions for Save and planning. Keep event
  names, dates, times and venue information readable. The oversized text CTA is
  superseded; Save and planning must still remain distinct actions.
- Save controls over event images use translucent frosted glass, including hover
  and the cyan saved state. Avoid opaque white circles over the photography.
- Keep the hero present when filters are used. Refine the results underneath
  without collapsing the featured area or jumping the page. The Events Festival
  implementation now retains the featured programme and its current slide.
  Its date badges are independent of the calendar for the results below.
- **Deferred search decision:** add a search bar/entry point later, taking the
  compact magnifying-glass treatment in B as the visual reference. Its precise
  placement, search scope, results and relationship to Composer still need
  design. This records future work; it does not replace the existing Events
  search or authorise backend search work now.
- **Logo asset task:** recreate the orange ribbon-like Expadu mark and wordmark
  visible in B in a separate chat, as reviewable editable assets. The asset
  creation request does not itself replace the current logo across the app.
- Preserve the shared palette and interaction foundations, mobile usability,
  fixed event times, Composer handoffs and the agreed Delete reveal. Review the
  next composition visually before treating the page design as complete.

This decision supersedes the split-card and disappearing-hero layout documented
below. Events and Today now have coded Festival implementations for review; see
the [Events notes](../events-next/README.md) and [Today notes](../today-c/README.md).
Today reuses the photographic cards, brush lead title, frosted Save and
calendar-plus action within its restored staggered board. All three event
cards share one horizontal rail on phones. The latest Today hierarchy above
supersedes its previous lead-and-support composition and removed-content pass.
The generated photographic scenes are labelled illustrations. The existing
media approval gate, shared shell and current logo remain in place.

## Superseded event-first implementation — 29 September 2026

The owner initially approved exploring the researched direction after rejecting
the first attempt to replace place tiles with small event posters, then rejected
the resulting visual implementation. The notes below describe the current code
for historical context; the Concept B decision above governs the next revision.
See [the five-platform research](event-discovery-research-2026-09-29.md).

- Today leads with one selected sample event and two supporting discoveries.
  Phones show a full-width lead followed by a swipeable rail with a visible next
  card. Desktop uses one larger lead beside two supporting cards. The personal
  overview, optional welcome and day/workflow links remain below discovery.
- Events uses the main content width for an explicit editorial pick, followed by
  compact discovery controls and time-based collections. A feature must come
  from catalogue configuration; being first in the current sort is not a reason
  to promote a record. The current sample pick is Kunst zum Mit-Machen.
- Searching, filtering and changing the sort display matching cards in the chosen
  order without a promotional lead. Calendar and date context remain available.
  There is one on-demand week/month calendar at every viewport size.
- Standard event cards put the title, date/time, venue and admission below the
  artwork. Save and the compact plus action are separate. The original art is
  retained and labelled sample; approved healthy media with credit can replace
  it. No reference-site photography or fictional event/news records were imported.
- Shared details, fixed schedules, date history, directions, Composer review and
  swipe/double-tap Delete reveal remain unchanged. Today’s all-events link opens
  the sample occurrence date and consumes its discovery reset only once.

Review images: [Today phone](../today-c/discovery-mobile.png),
[Today desktop](../today-c/discovery-desktop.png),
[Events phone](../events-next/discovery-mobile.png),
[Events desktop](../events-next/discovery-desktop.png).

## Shared decisions and their references

**Journey guidance — 29 September:** prepare the Departures prototype for a
future active journey. Use a map with a compact, draggable itinerary on mobile
and an itinerary beside the map on desktop. Keep the existing compact route
comparisons and stop timetable. Starting guidance is separate from proposing a
journey in Composer. A shared resume bar preserves the preview's route and step
when visiting Composer or another connected department. Location unavailable,
connection lost and changed-route states belong in this design; the prototype
must identify them as samples. Current map lines connect public stop/place
centres and must remain labelled illustrative, not street directions. GPS, live
vehicles, live routing and backend integration remain deferred. See
[Departures review notes](../departures-next/README.md#map-and-journey-preview--29-september-2026).

| Decision                        | Apply it                                                                                                                                                                                                                                                          | Reference                                                                                                               |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| One app shell                   | Reuse `#shell`, `connected/ui.js`, `shell-review.css`, `scroll-header.js`, `location-popover.*` and `menu-colors.css`. Keep the same brand, city/weather context, Saved action and department navigation geometry.                                                | [Connected notes](README.md), [Today C](../today-c/README.md)                                                           |
| Shared colour language          | Geist; Travel's cream canvas, warm off-white surfaces, dark primary text, cyan selected surfaces, orange actions (one palette since 6 October, `tokens.css`). Use the actual shared variables and components. Muted colour is supporting information, not every control. Do not invent a page-specific palette or blue-accent system. | [Connected interaction direction, 19 September](README.md#interaction-direction--19-september-2026), `shell-review.css` |
| One page heading                | Page titles belong in content, not a second header. Utility pages have no widgets rail. Today alone has its greeting and overview.                                                                                                                                | Project `AGENTS.md`, [Today C](../today-c/README.md)                                                                    |
| Compact mobile discovery        | Search and the first useful item take priority. Reveal optional choices on demand. Keep titles compact; text, targets and first-item actions remain usable.                                                                                                       | [Connected interaction direction](README.md#interaction-direction--19-september-2026), `places-next/filters.css`        |
| Contextual preferences          | Category, area, starting point/mode and sort are separate compact controls. Use the existing anchored pickers, one open at a time; outside interaction and Escape dismiss. Do not replace them with a large expanding form or a new modal.                        | Same 19 September decision; `places-next/app.js`, `filters.css`                                                         |
| Selection colours carry meaning | Selected picker rows use cyan; retain the approved contextual filter styles. Orange is used for actions; category selections use the existing cyan surface. Reuse these styles rather than choosing accents per page.                                             | `places-next/filters.css`, `connected/discovery.css`                                                                    |
| A single place sheet            | About, Plan a visit and Get there live inside the shared sheet. Sections preserve unfinished input. Section navigation stays inside the sheet; closing returns to the triggering place and browsing context.                                                      | [Place sheet decision, 20 September](README.md#place-sheet-and-shared-dates--20-september), `place-sheet.*`             |
| Shared dates and times          | Use `date-control.*`, `time-control.*`, `wheel-motion.js` and the shared visit-planning mount. Keep 24-hour input, exact-minute editing, keyboard support and reduced motion. Do not substitute native browser pickers in a new page.                             | [Composer notes](../composer-studio/README.md), [Connected dates](README.md#place-sheet-and-shared-dates--20-september) |
| Save and planning are different | Save keeps an idea without time or reminders. Add to plan starts a visit/proposal; confirmation remains explicit in Composer. Preserve source identity, date, time and Undo.                                                                                      | [Composer](../composer-studio/README.md), `handoffs.js`, `planning-ui.js`                                               |
| Composer is a shared workspace  | It supports questions, discovery and planning. Reuse the quiet department launcher and approved input surface, shared Saved/My Plans/History, and return context. Typing alone must not navigate or submit.                                                       | [Composer](../composer-studio/README.md), `composer-entry.*`, `today-c/composer-controls.*`                             |
| Planning stays contextual       | Review the chosen day's activities in the shared sheet. Do not create a new independent draft or silently replace confirmed plans when switching departments.                                                                                                     | [Connected refresh](README.md#connected-refresh--18-september-2026), `visit-timeline.js`, `day-actions.js`              |
| Directions retain context       | Starting point and mode are shared. Carry exact destination identity/coordinates and, when applicable, event date/arrival time. A sample itinerary is not a route to an arbitrary selected place.                                                                 | [Connected journeys](README.md#what-connects), `departures-next/README.md`                                              |
| Consistent feedback and loading | Immediate feedback for local choices; no artificial wait for saving. Use `loading.*` for real asynchronous work, delayed 140 ms to avoid flashing. Keep existing content visible. Use the shared motion helpers and reduced-motion behavior.                      | [Composer loading](../composer-studio/README.md#shared-loading-motion), `loading.*`, `motion.*`                         |
| Honest content and media        | Unknown opening, price, access or travel remains unknown. Media needs approved rights and active health, with attribution. No invented venues, ratings, availability or legal facts.                                                                              | Project `AGENTS.md`; [Connected evidence](README.md#evidence-and-limits)                                                |

## Menu interaction refinement — 28 September 2026

Today Blue is the owner's reference for this menu refinement: a light hover
surface and a smooth underline. This scoped reference does not replace Today C
as the accepted page composition.

- Keep the previously approved section colours constant. Hover, keyboard focus
  and the selected page use an underline matching their icon. Hover uses a
  lighter tint; the current page keeps its persistent highlight.
- Above 1050px, align icons beside their labels in a 58px row. Keep the compact
  stacked icon-and-label arrangement on phones and tablets.
- Use a restrained inset keyboard focus ring so it stays visible inside the
  mobile navigation. Hover and focus must not change item size or shift content.
- Share these rules across the connected pages. Respect reduced motion and
  keep touch feedback independent of hover.

Checked all six departments at 1280px and 393px, including matching item sizes
and icon/underline colours. Bureaucracy was also checked at 320px: navigation
scrolls within its row without widening the page. Hover and keyboard focus were
checked in the browser; the six focused asset/header checks passed. References:
[desktop](menu-desktop-review.png), [phone](menu-mobile-review.png).

## Bureaucracy proportions, Events pilot — 28 September 2026

The owner chose Bureaucracy as the reference for control sizes, spacing,
typography, icon proportions and the overall ease of reading. This extends
beyond filters. Events is the first page to refine and review; Departures follows
after that review. Do not change Bureaucracy or Places as part of this pilot.

- Events uses 44px touch targets, 14px control labels and 18px control icons.
  Related choices use Bureaucracy's 4px padded, 14px rounded rail with a white
  selected surface and a quiet shadow. Date and filter selections retain cyan.
- Keep category, area, travel and sort as independent contextual controls.
  The compact mobile row remains; optional preferences do not become a large form.
- Restore the shared pale page canvas, keep the original event artwork, and
  retain purposeful lavender/yellow accents in the detail cards. Improve card
  text hierarchy without changing the accepted calendar/gallery composition.
- Detail tabs and travel modes share the softer selection treatment. Time
  suggestions stay inline. The cover keeps its stable height, and the footer
  remains compact with a fading edge. Keyboard focus stays inside controls.

This is a prototype refinement for owner review, not a backend or data change.
The sample occurrence dates, uncertainty, saved ideas and Composer handoffs
remain unchanged. See [Events review notes](../events-next/README.md).

**Your day removal — revised 28 September:** follow the owner's Apple Notes
reference: a left swipe or double-tap slides the activity aside by 64px to reveal
one red circular Delete icon at the trailing edge. Keep the time column still
and the activity partly visible. There is no permanent trash icon, full-row
notification or separate Cancel/Remove text bar. Neither gesture deletes anything;
only tapping the revealed Delete action does. Swiping back, tapping the activity
or elsewhere in the sheet, and Escape close the action. Keyboard and screen-reader
activation of the row reveal the same action without an extra visible control.
This applies to all new previews, including fixed-time events, and planned
activities. The action follows the finger, settles gently, respects reduced
motion, and cannot be triggered by the release of the reveal gesture. Deleting a
flexible preview clears its unfinished time choice; confirming a fixed-time
preview removes it from the timeline without changing the event's listed times.
Undo restores the fixed preview, and it stays removed across tab/input changes
until restored or explicitly added to the proposal. Planned-activity removal
retains Undo. Resetting an unsaved edit remains a separate action.

**Events keep their listed times by default:** show start/end as a compact
schedule summary, not editable fields or suggested one-hour visits. Only an
explicit `window` attendance policy permits flexible visit times and suggestions.
Unknown policy is not evidence of flexibility; keep the listing's uncertainty
visible. A missing finish asks only for an expected end, clearly marked as an
estimate in the preview and handoff. Do not infer flexibility from a long event
duration or the event title.

**Events day cards:** use a pale, borderless surface, dark time labels and a
small cyan Preview badge. Remove the purple outline, coloured left rule and
orange preview time. Reserve warning colour for actual overlaps. Keep the shared
swipe, confirmation, focus and Undo behavior.

## Page decisions to preserve

- **Today C:** selected shared header, the conditional next action and Festival
  discovery described above, persistent Composer and compact Continue rows. The earlier
  six-card staggered composition is historical.
  Onboarding is optional by default: a small dismissible welcome inside Today
  offers settlement help without blocking entry. It opens the person's own
  Bureaucracy guide. Today keeps showing their own records after family browsing.
- **Place content:** contextual discovery in Composer, with useful
  facts ahead of optional photography. The shared About / Plan a visit / Get there
  sheet remains available from Today. Composer retains its contextual detail
  preview, planning actions and Saved collection, including map/directions links.
  The former standalone discovery design remains a historical reference.
- **Events:** event-first discovery and a date-led filtered programme, with exact
  occurrence identity, retained dates, source-based price uncertainty and planning/reminder
  actions clearly separated from a booking or delivered notification.
- **Bureaucracy:** whole-plan overview, contextual next actions, scoped process
  history and user-recorded appointments. The 28 September guided-entry update
  resumes optional orientation in Bureaucracy or Composer with the same sample
  answers. Skip stays unanswered; an explicit unknown is distinct. Topic browsing
  remains available, and orientation never starts a process or changes the sample
  assessment. See [the guided orientation boundary](../bureaucracy-next/README.md#guided-orientation--28-september).
  Follow-up answers use one inline shared surface: reuse known details, explicit
  confirmation, separate correction and real-change choices, retained history,
  and deliberate conflict review. Family views identify the person and access
  scope; answers and readiness stay separate. Composer retains that exact
  person and draft. Authorised, view-only, pending and removed family states are
  prototype fixtures; production permissions and fact assessment stay backend-owned.
  The subsequent fresh-start increment introduces the page before asking arrival,
  purpose and citizenship questions, pauses after three, and seeds no personal
  facts or tasks. **Your situation** is shared with Composer. Life changes are
  reported, reviewed and confirmed; related answers are checked without silently
  changing permits or completing tasks. Fresh, returning and existing sample
  scenarios stay separate. See [fresh and returning journeys](../bureaucracy-next/README.md#fresh-start-and-returning-situation--28-september).
  Unknown facts stay unanswered; verified
  guidance and human review gates remain authoritative.
- **Departures:** the owner requested a complete mobile-first refinement on
  28 September, following the Bureaucracy proportions and Events review. Use
  Plan a trip / Timetable views, compact anchored controls, shared date/time
  pickers, route comparisons and a consistent timeline detail with a fading
  footer. Preserve the captured route data and the Composer connection. Return
  from sample exploration to the exact original request; never attach an old
  sample to a different activity/date. The 29 September review adds chronological
  56px timetable rows, date/time browsing, individual departure selection and
  boarding-stop links that preserve the journey. Mobile route options are about
  98px tall. Composer retains the separate requested arrival time and preferences,
  including when the same itinerary is selected again. See the [Departures refinement](../departures-next/README.md).
  The new iteration is ready for review; the future section name is undecided.
- **Services:** keep requirements on-page. No fictional providers or availability
  to fill missing coverage. A planned appointment is not a service booking.

See [the cross-page review](product-design-review-2026-09-18.md) for historical
gaps and [the connected implementation notes](../../../../docs/superpowers/plans/2026-09-18-connected-departments.md)
for the later integration. Neither overrides newer explicit decisions above.

## Earlier finishing pass — 29 September 2026 (Today layout superseded above)

- Today opens the same Event sheet as Events, including fixed schedules, shared
  directions and the day timeline's swipe/double-tap Delete reveal and Undo.
  Mobile uses compact overview rows and brings the first discovery actions into
  view. The optional welcome replaces the duplicate empty paperwork invitation
  while visible; it never hides existing personal records. Preserve the approved
  staggered desktop composition.
- Services keeps area and language visible and optional preferences in a compact
  inline disclosure. Asking Composer and retaining requirements are separate
  actions. An appointment must already be arranged: collect its actual date and
  time with the shared controls, then review it in Composer. Do not suggest
  leisure-style times or imply provider search/booking is connected. See the
  [Services flow notes](../services-next/README.md).
- Events keeps the selected date in its URL, including sample-programme recovery,
  refresh, Back and Forward. Filtering does not silently reset it.
- Composer's Saved-panel return control uses rounded, inset keyboard focus.
  Connected mobile navigation reveals the selected page after fonts finish
  loading so late layout changes do not leave the active item offscreen.

This earlier consistency pass changed the connected prototype only. The later
29 September Places decision above supersedes its exclusion from that pass.
Walkthroughs and real-app integration remain separate work.

## Contextual places — 29 September 2026 (Today layout superseded above)

The five navigation items are Today, Events, Bureaucracy, Departures and Services.
Keep each section's icon, hover and underline colours attached to its page ID,
not its position. The mobile layout uses five appropriately sized columns, with
horizontal scrolling only on the narrowest screens.

Today keeps its approved staggered desktop board. Its activity deck includes
records without photographs; the park deck uses a supporting photo when present.
Composer discovery and Saved use a compact information-led place card. Failed
images collapse rather than leaving blank media. Neither view claims verified
hours or access. Directions carry the selected place identity; place sheets show
the compact planner introduction instead of an unrelated sample itinerary.

Verification: the connected Node regression suite and formatting checks pass.
Browser checks cover the five section destinations/selected states, 320px and
393px phone layouts, and the retained 1280px desktop board. In isolated storage,
Today place details, Save, visit proposal and destination handoff work; Composer
Saved, full details, planning and replacement preserve the selected place and
time. The prototype remains a sample UI, not a live catalogue or route service.

## Deferred: app-wide walkthroughs — 28 September 2026

Build one shared walkthrough system as a final task after the main app pages are
ready. On a person's first visit to a page, introduce its important areas and
controls in context, step by step. Remember visits per page so normal return
visits do not repeat the walkthrough. This covers the whole app, including
Composer, rather than only Bureaucracy.

Implementation is deferred. Current page work should not add separate tours.
The optional Bureaucracy situation questions remain a separate flow from learning
how to use the interface.

## What this does not decide

- The photo-optional presentation does not achieve or waive the earlier 75%
  photo-coverage goal. Dataset quality, rights and canonical identity still need
  their own evidence in the data tickets.
- The design snapshot is not the production catalogue. Record counts, live
  availability and staging/production state must be reported separately.
- The optional welcome and answer/family previews originally left the Laravel
  onboarding gate unchanged. The 3 October real-app implementation below removes
  that gate while retaining authentication and email verification.
- Moving a pattern into a shared file does not authorize a new visual direction
  on every other page. Keep changes scoped and preserve accepted compositions.

## How to keep the record useful

When a decision changes, update this reference with the date, new rule and page
example, and mark the old alternative as historical. Add a concise note to EXP-73
and the existing work-log page. Keep implementation details in the relevant
handoff, not in ordinary user-facing controls. Before completing UI work, compare
the shell and shared controls with an existing department at desktop and phone
widths, then verify its actual connected interactions.

## Connected frontend implementation — 3 October 2026

The owner authorised unattended implementation and a second end-user review across the app. The approved Today brief remains the prototype reference; no new alternative composition replaces it. The real frontend now has the five top tabs Today, Events, Paperwork, Travel and Services, with Composer retaining its dedicated workspace. `fullWidth` content does not remove department navigation.

Onboarding is optional at entry. Contextual links carry a request for review, never automatic sending or permission to replace a plan. Personal edits, event occurrences and journeys keep their selected date and identity. Unsent messages and another day's proposal survive handoffs. Fixed event times must remain exact, including short events; unknown end times require checking. Changed locations/times invalidate old journeys. Removing or completing an activity must not resurrect an older sample through another screen or overwrite newer work from another tab.

Places remain contextual, photos optional, and uncertain fields explicit. The places investigation reports 12,292 candidates in its local export and very low approved photo coverage; this is not deployed data. The frontend supports the optional evidence/routing contract, nullable coordinates and honest missing-photo states. Legacy raw place-photo fields cannot bypass approved publication. Do not import the separate readiness worktree wholesale.

Travel supports manual starting points without GPS and retains a chosen route when browsing Timetable. The real board is the existing current nearby-stop feed, not the prototype's arbitrary captured date/stop scenarios. Paperwork coverage determines readiness messaging; no-question does not mean a complete reviewed case. Services prepares requests while provider search remains unconnected.

Implementation, test evidence and remaining backend/data boundaries: `docs/reviews/2026-10-03-friendly-connected/README.md` in the repository root. The full-app walkthrough remains deferred.

## Travel: optional Composer help — 5 October 2026

The owner requested a small, closable Composer element instead of prominent
review buttons repeated on every item. Travel now gives Journey preview, Save,
map and itinerary controls priority. One compact helper sits beside the planner
and follows the route the person explicitly inspects; merely returning a first
result does not select it for Composer. Changing the request clears that route
context. Dismissal lasts for the tab session, preserves the trip and returns
keyboard focus to its controls. The global Composer entry remains available.

This change applies to both Travel prototype entry points, including the station
preview. It does not change the Composer workspace or deploy the Laravel app.
Evidence: `docs/reviews/2026-10-05-travel-composer-hierarchy/README.md`.

**Timetable return correction — 5 October:** “Back to your journey” returns to
the originating surface. Inline route details stay inline, retaining the expanded
route, request, scroll and focus. A timetable opened from the map/steps preview
returns to that same preview state. Back must not launch a new preview, reset
inspection or restore a preview belonging to a changed journey request.
