# Today — connected two-column reference

Latest owner correction, 2 October 2026. `/dev/design/today-next/brief/` now
restores the composition in the owner's screenshot, also saved at
[`../review/continuity-desktop.png`](../review/continuity-desktop.png).
The daily brief and its timeline-only correction were rejected.

**7 October:** the Check departures board shows Köln Neumarkt at the sample
day's clock (line 4 at 13:14 and 13:24, "Sample day · 13:10"), matching the
journey card and Travel's timetable; stops without a sample day keep their
capture. The sample day now loads before Travel's data.

`index.html` reuses the parent Today renderer and shared interactions.
`reference.css` restores the earlier proportions; the later cohesion pass below
refines its section boundaries without removing its content:

- Agenda with nested travel, Saved for later and Coming up on the left.
- Paperwork/City help above two event cards on the right.
- Cyan Composer continuation and existing requests or starting actions below.
- Existing shared header, saved records and Composer context retained. The
  preview keeps its own agenda session key and Today navigation destination.

Desktop was compared with the supplied 1440px reference; the 390px phone keeps
the agenda action in its first viewport. Eighteen existing day/continuity checks,
syntax and formatting pass. Current captures:
[desktop](review/reference-restored-desktop.jpg) and
[mobile](review/reference-restored-mobile.jpg).

## Sheets, richer details and Composer requests — 7 October 2026

Review items 7–10.

- **Sheets (`brief/sheets.css`):** the journey, plan details, forecast and Add
  a plan open as a bottom sheet on phones (slides up, drag the top edge down to
  close) and a 520px dialog on larger screens, like the Event sheet. Light
  scrim, no blur; Escape and the close button work and focus returns.
- **Journey sheet:** leave-by time with minutes to spare, then walk → line →
  get off → walk with their times. A tight connection offers the earlier
  service; a delayed one lists the alternatives; otherwise "If you miss it"
  points to Travel. Only sample data is shown: no platforms or next services
  are invented.
- **Plan details:** date, time and place, status, What to bring, a getting-there
  row (leave by, or "About 10 min late" when delayed), booking status and
  Directions, Edit and Remove. Remove closes the sheet and shows the inline
  delete on the card.
- **One Composer entry per screen:** besides the floating launcher, each screen
  offers at most one contextual Composer action; the reminder's "Review with
  Composer" replaces "Plan tomorrow" on the preparing day.
- **Natural requests:** Composer is pre-filled with, for example, "Help me plan
  Thursday 1 October. I already have Language class in Ehrenfeld at 18:00." or
  "How do I get to Mülheim by 14:00 on Wednesday 30 September? It's for my Blue
  Card appointment." A request Today wrote is cleared when you switch sample
  day; anything you typed stays. Composer's sample responder now reads named
  dates and puts journey and planning requests before paperwork words, so each
  request reaches the right reply.

## Colour roles — 6 October 2026

`brief/hierarchy.css` (loaded last) applies the "Colour roles" table in
`connected/design-decisions.md`: one ivory surface with hairline separators
instead of section tints; ink text links with arrows; orange only for the main
action; cyan for selection and travel; lavender only for the paperwork progress
indicator; lighter weights for the forecast and departure times. The per-section
Composer sparkles are removed (the floating launcher remains), the Composer
continuation lists plans by time, and its generic "ready to review" line is
dropped because the card's status already says so.

## Connected day — 6 October 2026

Items 1–6 of the end-user UX review, approved by the owner. Their styles now
live in `../../connected/day-components.css` (moved from `brief/connections.css`); the logic is in `brief/content.js` (`deadline`, `leaveBy`,
`connections`) and the rendering in `../app.js`.

- **Deadline strip:** the sample Blue Card record now carries the Paperwork
  preview's visa expiry (1 Oct). One day ahead it leads every non-first-visit
  scenario and opens the checklist.
- **Appointment ↔ paperwork:** the sample appointment is recorded from
  Paperwork (`process: 'blue-card'`) and shows "What to bring · 1 of 5
  documents ready". The Paperwork preview still shows no recorded appointment;
  align it when that page is revised.
- **Leave by:** 13:20 for the 13:24 (4 min walk to the stop). 2 min to spare is
  too tight for an official appointment, so it offers the 13:14.
- **Plan links:** sample neighbourhood estimates; Busy day shows two fits, and
  adding a plan too soon after another shows the conflict and earliest start.
- **Alternatives:** Route delay and Delay + event open three sample options in
  place.
- **Free day:** Quiet day and Later in the day lead with ideas.

The review key is now `expadu-today-brief-review-v2`, so earlier sample edits do
not carry over. `prototype/serve.py` now sends `Cache-Control: no-cache`, so an
edited preview never shows a stale page. Tests: 44 pass across the four suites.

## Approved content improvements — 2 October 2026

The owner approved enriching this restored composition. The active preview now
loads `content.js`, `enhancements.js` and `enhancements.css` as an optional layer;
the parent and earlier alternatives do not load it.

- City help includes three captured departures, an inline stop selector,
  expandable direction details and the matching Travel timetable. Every board
  retains its actual capture date and time; it never claims to be live today.
- A compact idea beside the agenda matches a plan's area. Saved places take
  precedence. Events must match the date, follow the anchor appointment and avoid
  other known appointment overlaps. No walking distance is invented.
- The two immersive photos remain, followed by three compact sample listings
  with shared Save and Plan actions. Additional event identities are explicitly
  fictional, separate from the captured catalogue.
- Composer shows its real outstanding decision and original plan date. Long
  unsent messages expand inline. Continuing preserves the existing proposal;
  adding an event on another day still requires resolving that proposal first.
- One personal paperwork action remains alongside compact service shortcuts.
  Saved journeys and service requirements continue in the lower section only
  when present. Empty collections do not invent unfinished work.
- City updates link to the official city and transport sources while live
  updates are unavailable. A future supplied feed must carry verified, dated
  entries from the configured source hosts; no news has been fabricated.
- Saving updates Saved for later and the contextual idea immediately. The stop
  selection stays in place. Short press/disclosure motion respects reduced motion.

The isolated browser fixture is `content-review.html`; its Returning/Fresh links
use in-memory stores and cannot overwrite the owner's saved records. Verification
covers 320px, 390px, 1024px and 1440px layouts, stop changes, immediate saving,
fixed event times, proposal protection, delete/Undo, delay recovery, unavailable
travel and event-versus-delay priority. Thirty focused model/timetable checks
pass. Captures: [desktop](review/content-desktop.jpg),
[lower content](review/content-desktop-lower.jpg),
[phone](review/content-mobile.jpg).

## Empty areas and cohesion — 2 October 2026

The owner requested a focused pass on empty space and disconnected modules.
The active preview now loads `cohesion.css` and opts into natural column flow.
The parent preview and other alternatives retain their existing layouts.

- One ivory workspace replaces the wide background gutters and repeated outer
  card corners. Faded separators and subtle cyan/lavender transitions retain
  recognizable sections without making each one a separate tile.
- Composer follows Saved and Coming up in the left column. Saved journeys,
  requests and city sources follow discovery in the right column. The lower
  content uses the same column widths instead of starting a second grid.
- Composer actions follow the summary immediately; they are no longer pushed
  down to match the height of the neighbouring panel. The forecast sits beside
  City help, and the footer follows the workspace with a small clearance.
- The full appointment timeline, nested journey, useful content and shared
  dialogs remain. Phones use a continuous reading order; upcoming plans still
  start collapsed on a fresh phone load. Empty collections remain honest.
- Saving refreshes only the changed continuation content, preserving an open
  draft disclosure and the selected departure stop.

Browser review covered 320px, 390px, 1024px and 1440px widths, plus quiet, busy,
important-event and competing-delay states. The everyday desktop workspace
with the owner's existing records ends about 268px earlier than the separated
layout, without removing content. No horizontal page overflow or console errors
were observed. Save, expanded draft retention and continuing the original
3 October proposal were verified in the isolated fixture. The 30 focused checks
still pass.

Current captures: [desktop](review/cohesion-desktop.jpg),
[lower content](review/cohesion-desktop-lower.jpg),
[phone](review/cohesion-mobile.jpg).

## Mobile priorities and user intent — 2 October 2026

The owner approved reviewing Today by what a person needs, as well as appearance.
The active preview now uses `TodayContent.priority` and `priorities.css`:

- An imminent recorded commitment or departure leads, with its full nested
  journey and recovery actions. All upcoming activities today remain available
  in the timeline. Completed and expired activities move into Earlier today;
  Mark as done has Undo and the existing delete gesture/Undo still works.
- A due, user-recorded preparation reminder or an unfinished proposal for today
  may lead when no imminent commitment takes precedence. An undated message or
  future proposal remains lower down. A passed draft start asks for a time review.
- An eligible important event can lead; past, already-started fixed events and
  events overlapping recorded activities are not new Today suggestions. This is
  an overlap check, not a claim that travel or admission is feasible.
- Quiet days start with local essentials and say “No plans added for today.”
  The page does not infer free time from missing records. The first-visit sample
  has no assumed appointments, checklist or upcoming plans; settling-in help is
  optional. Its connected Saved/Composer state is never cleared by a scenario.
- On phones, essentials follow the active agenda, then a relevant local idea
  and event discovery. Older Composer work, Saved and future plans follow.
  Paperwork is one compact continuation, not a large progress display. Captured
  stop times are initially behind Check departures, with their actual date.
- The utility header scrolls away, leaving the compact five-tab menu. Original
  elements move at the mobile breakpoint so keyboard and visual order agree.
  Saving or typing does not reshuffle the reading position; priority is evaluated
  on entry, scenario changes and explicit agenda actions.
- Expired saved events stay in the shared collection and View all count, but are
  omitted from the Today shortlist. A same-area event appears once, beside its
  relevant appointment rather than again in the compact discovery list.

The ordinary desktop composition retains its two columns and joined surface.
Quiet desktop scenarios put city essentials ahead of the compact empty agenda.
New preview choices: First visit, Reminder due and Later in the day. For an
isolated pending-today decision use `content-review.html?view=quiet&content=today`;
`content=returning` retains the separate 3 October proposal, and
`view=first-visit&content=empty` is the clean first-visit case. Test fixtures use
memory storage; none of these samples change the real app or create live records.

Verification covers the priority rules, phone reading order, delay recovery,
Composer context/proposal preservation, completion Undo, double-tap Delete/Undo,
stop selection, Save without reordering and keyboard event browsing. The focused
day/content/continuity/timetable suite has 40 passing checks. The wider live-data,
backend and shared-agenda integration boundaries above remain unchanged.

Phone scenarios were checked at 320px, with 390px/430px reading-order reviews
and 1024px/1440px desktop comparisons. No horizontal page overflow or browser
errors were observed. Captures: [everyday phone](review/priorities-mobile.png),
[quiet phone](review/priorities-quiet-mobile.png) and
[desktop](review/priorities-desktop.png).

## Preserved experiment files

The files `app.js`, `presentation.js`, `style.css`, `test.html` and presentation
tests below preserve the rejected daily-brief experiment; the active index no
longer loads them. The parent preview retains its joined-canvas styling. New
content improvements above are scoped to the active restored preview.

## Historical daily-brief experiment

Working review, 2 October 2026. Open `/dev/design/today-next/brief/` on port 8765.
The owner asked to try the first proposed direction, with app-like interaction:
a daily brief, a varied middle and a personal ending. This is an alternative for
review; `today-next/` and `today-c/` remain preserved.

## Composition and behavior

- **Timeline restored after owner review, 2 October:** show every recorded
  plan in chronological order, with the orange connected line, prominent time,
  title, location and status. Its journey stays nested within the appointment.
  Do not hide useful details to make the page feel lighter. A compact forecast
  accompanies the timeline. A quiet day offers a starting point. A relevant delay outranks an
  important event; the important-event example promotes the discovery section.
- Immersive events share the middle with contextual Composer prompts, one
  personal paperwork reminder and compact city actions. Mobile puts the useful
  actions ahead of the single event rail on an ordinary day. A designated event
  can lead when no imminent plan or relevant disruption takes priority.
- For later brings upcoming plans, shared saved ideas and unfinished Composer
  work together. Existing saved journeys and service requirements appear only
  when present. Empty collections stay compact and do not invent records.
- Keep the compact top menu, connected ivory surface, cyan journey/selection
  color, orange actions and restrained yellow/lavender accents. There are no
  repeated enclosing cards around every section or large footer reservations.
- Event cards support desktop dragging, native horizontal touch scrolling,
  keyboard arrows and position buttons. Saves immediately update their button,
  shared count and For later collection. Position controls have 44px hit areas.
- Add a plan updates the visible timeline and returns focus to
  that plan's options. Swipe/double-tap reveals the compact red Delete action;
  deletion is a separate activation with Undo. Dots offer a keyboard alternative.
- Delay recovery expands inline. Contextual Composer entries retain the existing
  message and exact date/journey context; nothing sends automatically. The shared
  Composer, event sheet, detail dialog and time controls are reused.
- Press feedback and short disclosure/image/control transitions respect reduced
  motion. There is no automatic carousel or repeated decorative movement.

## Preview boundary

This is UI work with labelled samples, not a new backend. The agenda, forecast,
events and paperwork overview use the existing Today review data. Agenda edits
stay in a separate preview session; they do not overwrite the shared Composer
agenda. Saving and explicit event proposals use the existing connected store.
The original prototype's integration boundary remains: the reused event planner
reads the shared agenda, not these isolated sample appointments. All dates,
unavailable times and simulated changes remain explicit.

## Verification

24 focused checks passed across the existing day/continuity models and the new
presentation regressions. Preview files pass JavaScript syntax and formatting
checks. Browser review covered 320×740, 390×844, 1024×900 and 1440×1000. No page
horizontal overflow was observed. The first plan action is visible in the
initial phone viewport.

The initial brief was verified using the isolated `test.html` fixture:
double-tap reveal/Delete/Undo, adding a plan on a quiet day, delay alternatives,
uncertain travel, important-event/competing priorities, real pointer dragging,
event position controls, immediate saved collection updates, fixed event times,
and protection of an unfinished proposal for another day. Composer preserved an
existing typed message and appended day context without sending. No console
errors were observed. Test interactions do not modify the user's saved records.

Review captures: [desktop](review/desktop.jpg), [mobile](review/mobile.jpg).

The owner rejected removing the timeline as a solution to the lack of density.
Content ideas are being discussed separately; adding substantive travel,
Composer and city content is not implemented or approved by this restoration.

Timeline restoration: all 24 focused checks still pass. Browser review covered
the everyday layout at 1440px and 390px, all three visible busy-day plans, and
the delay state at 320px. Corrected the narrow delay row by stacking the old
and revised times; its contents now fit its 220px width. No console errors were
observed. Latest captures: [restored desktop](review/timeline-restored-desktop.jpg)
and [restored mobile](review/timeline-restored-mobile.jpg).
