# Today — connected daily canvas

The current review at [`brief/`](brief/README.md) restores the earlier two-column
layout the owner explicitly identified in `review/continuity-desktop.png`.
It replaces the rejected daily brief, including its timeline-only correction.
This joined-canvas layout remains available for comparison; further content
density changes await discussion.

Working UI preview, updated 2 October 2026. Open `/dev/design/today-next/` on the
prototype server. The owner approved retaining the agenda timeline and improving
desktop density with a connected practical surface. The accepted `today-c/`
baseline remains available.

## Composition

- Desktop gives the agenda and saved ideas roughly 40% of the width. Paperwork,
  city help and one immersive event strip use the remaining 60%.
- One compact desktop header contains the logo, top navigation and account/city
  controls. A small greeting/date line follows; review controls share that line.
  The ordinary agenda starts about 138px from the top at the reviewed desktop
  widths, replacing the previous roughly 312px stack. Tablet and phone headers
  put the same navigation in a compact second row.
- A compact Coming up section follows the agenda and saved ideas, with two dated
  sample personal plans. It opens the existing detail dialog and starts collapsed
  on phones. The month remains visible across the September/October boundary.
- Contextual Composer actions sit with the agenda, upcoming plans and Paperwork.
  They open the existing Composer, preserve its draft and add the relevant date
  and plans without sending. The previous promotional footer strip and duplicate
  departure/paperwork header summaries have been removed.
- An appointment owns its journey. Departure, arrival at the stop and the final
  walk remain distinct; the destination arrival includes that walk.
- Paperwork shows the next unfinished personal checklist, a progress indicator,
  registration and situation links. The links open the existing sample process
  directly, using the department's existing scenario switcher and snapshots.
- City help combines a sample forecast with relevant Services and Travel links.
- Quiet days collapse the short agenda area. An important event can lead when
  no more urgent personal item takes precedence. An active relevant delay wins
  over the editorial event.
- Phones stack the practical areas before a single swipeable event rail. The
  five-item top navigation and the existing Composer component remain in place.
- The owner's latest color direction balances a warm gray canvas and an ivory
  shared surface with cyan selections, journey details, date tiles and city/travel icons.
  Paperwork has a restrained lavender tint; orange actions and yellow warnings
  retain meaning. The existing Composer launcher also uses deep cyan. Secondary
  text is neutral gray; separators still fade. This supersedes the entirely
  neutral version and remains scoped to the Today review.
- The footer is one compact utility area with sample provenance and a comparison
  link. The closed Composer no longer reserves a full-width strip; local footer
  clearance protects its controls. Only an expanded Composer reserves space.
- **Pick up where you left off** extends the desktop composition below the
  overview. A Composer continuation sits beside compact saved journeys and
  service requests. Phones stack these in the same order. Only existing records
  appear: an unfinished proposal, department selections, an unsent message or
  the current conversation. Confirmed plans never masquerade as unfinished work.
- Saved for later now shows the shared saved collection, with exact event dates,
  direct details, a separate Plan action and View all. The fixed Language café
  example is removed. Updates from other open pages refresh these summaries.
- Travel links reopen a saved captured route or its boarding-stop timetable.
  Service rows reopen the recorded category, whose existing form restores kept
  requirements. Sample routes remain labelled; requests do not imply bookings.
- Fresh users get three compact starting actions. If there is only a Composer
  continuation, these actions sit beside it. No sample requests or saved journeys
  are inserted into the person's records to fill the layout.
- **Cohesion correction, 2 October:** the owner found the cards disconnected.
  Agenda, practical help, discovery and continuation now share one continuous
  surface. Remove the wide background channels and repeated section corners;
  use fading separators and align continuation with the same desktop columns.
  Saved journeys and requests follow the Paperwork/City help split on wide
  screens. Phones use a continuous flow with compact section spacing. Keep
  cyan journey details, lavender paperwork identifiers and immersive event
  images; the continuation has a subtle cyan tint rather than a separate card.

The review selector offers Everyday, Quiet day, Busy day, Route delay, Important
event, Delay + event and Travel unavailable. These are deterministic sample
scenarios for 30 September 2026, not live personal information.

## Reused interactions

- Shared detail dialog, Event sheet, fixed event-time planning, time picker and
  department links.
- Shared day gestures: swipe or double-tap reveals a compact red Delete action;
  a separate activation removes the activity, and Undo restores it. The dots
  menu provides the same removal action for keyboard users.
- Event cards support horizontal drag, native touch scrolling and keyboard
  arrows. Save and Plan remain separate controls.
- The contextual Composer entry retains the current prompt and draft, adds the
  exact sample appointment/date/journey context without scheduling it, and never
  sends automatically. Minimizing the shared workspace returns to this preview
  and its selected scenario.

## Prototype boundary

The scenario agenda and its temporary edits live in this page's session storage.
They do not overwrite the shared Composer agenda. The reused event planner still
reads that shared agenda; it does not include the isolated sample appointments.
This deliberate review separation must be replaced by one common day projection
when the approved frontend is integrated. Sample paperwork progress is also a
fixture, not a live projection of edits made in the department.

Save and explicit event proposals use the existing connected prototype store.
The new continuation and Saved summaries also read that store and the existing
Composer/Travel storage. This does not merge the isolated agenda into Composer.
Journey alternatives hand off to the existing Travel planner; they do not invent
live connections. Event imagery reuses the existing AI sample illustrations.
No backend, catalogue, legal content, booking, notification or production route
has been implemented by this preview.

## Verification

50 focused Node checks passed: the Today model, shared store, day gestures and
shared asset-version regressions. New preview files pass Prettier.

Browser review covered 320×740 and 390×844 phones, 1024×900 tablet and 1440×1000
desktop. The page has no horizontal overflow at the checked sizes. The first
agenda action is visible on the checked phone viewports, and the existing
Composer reserves space while expanded. Its closed launcher has local footer
clearance without reducing the scrolling viewport.

Verified in the browser:

- All seven scenario selections, including quiet layout, competing priority,
  busy agenda and missing travel data.
- Direct EU Blue Card process opening, with the existing 1/5 document record.
- Add a personal plan using the shared time picker; double-click reveal, Delete,
  Undo and dismissal of the removal notice.
- Actual left-drag reveal and right-drag dismissal of the agenda action.
- Event-rail drag reaches the second card without opening its details, and
  keyboard scrolling works.
- Shared Event About, Plan and Get there tabs; fixed start/end times and correctly
  fitted cover imagery at 320px.
- Contextual Composer handoff and return preserve the scenario and existing
  conversation without automatic sending.
- No browser console errors in the reviewed page.
- Compact-shell revision: 320×740 and 390×844 phones, 1000px and 1440px desktop;
  first agenda action visible, top navigation/account access, contextual prompts
  for the day/tomorrow/paperwork, no automatic sending, preserved existing text,
  and footer controls clear of the closed Composer.
- Neutral palette revision: Everyday, Quiet day and Route delay reviewed on
  desktop and a 390px phone viewport. Travel direction, warning chips and the
  existing Composer remain clear; no horizontal overflow was observed. Twenty
  sampled text/background pairs passed 4.5:1, with a lowest measured ratio of
  4.91:1 after deepening muted text and orange text links. This is a focused
  palette check, not a full accessibility audit.
- Cyan-balance correction: reviewed at 354px phone and 1309px desktop CSS
  widths, with no horizontal overflow. The first agenda action remains visible
  on the phone. Five affected text/background pairs passed 4.5:1; the lowest
  measured ratio was 4.67:1 for cyan selected navigation and date tiles.
- Continuation pass: 94 focused Today, Travel, shared store, gesture, journey
  session and asset-version checks pass. The new checks cover empty/confirmed
  state, proposal revisions, duplicate handoffs, saved occurrences, malformed
  records, unsent drafts and safe timetable entry. Browser checks covered
  returning and fresh users at 320px and 390px, and the desktop composition.
  Save/Unsave updates the collection and detail-sheet state; saved-place planning
  reuses its existing plan. Composer handoff preserves the proposal and omits
  automatic submission. The timetable shortcut opens the named stop and captured
  date; Services opens the corresponding category. No horizontal overflow or
  browser errors appeared in these checks.
- Cohesion correction: 18 focused Today checks and formatting pass. Browser
  review covered 320px and 390px phones and 1440px desktop, returning/fresh
  content and the quiet-day layout. Main and continuation columns align on
  desktop; sections flow continuously on phones. The first action stays visible,
  Composer retains the original proposal handoff, and there is no horizontal
  overflow or console error in the reviewed states.

The isolated review at `tests/fixtures/continuity.html` provides Returning user
and Fresh user states without reading or writing personal prototype storage.
Its Composer handoff is printed on the page for inspection; it never submits.

Review captures are in `review/`: desktop everyday, delay and quiet layouts,
mobile everyday and mobile discovery. The comparison gallery links to this
working preview alongside the retained design boards.
The superseded content-density captures use `*-content*.png` names. The latest
compact layout captures use `compact-*.png` names. The subsequent warm neutral
palette captures use `neutral-*.png` names.
The latest warm-canvas/cyan-accent review uses `balanced-*.png` names.
