# Events design preview

Review at `http://127.0.0.1:8765/dev/design/events-next/`. It opens on the
preview's sample day, Wednesday 30 September 2026; the captured programme is at
`?date=2026-09-08` and is shown as past. Other dates can legitimately have no
sample events; this is not a live catalogue.

## Weathered past posters, and a calmer skyline — 8 October 2026

Owner request, after reviewing the skyline on the past day (8 September),
where labels piled on top of each other and "Further north/west" names
covered the city.

- **Past posters are weathered.** A day that has already happened hangs as
  sun-faded paper with creases and a stain, and each poster wears in its own
  way: a torn edge, a nicked side with a peeling corner, or a torn top
  (`data-wear`, from the event, so it always looks the same). The "Already
  taken down" strip wears the same way. Still readable; still no Save or
  Plan.
- **On the skyline, a past day's lights are out:** grey, unlit windows, a
  paler city and "These have already happened, so their lights are out."
- **Labels never pile up.** The poster facing you is named first, then the
  day's events in time order; a label that would cover another label or
  another light shortens to its time, or hides. A light always names itself
  on hover, focus and to screen readers. Checked at 390, 1024 and 1440 with
  the column on each of its posters.
- **Coming-up rings are quiet** until their poster faces you.
- **Places off the drawing are named under it**, in a row with the
  "Illustration · places approximate" note, never over the city; a column
  poster's appears only while that poster faces you.

`tests/wall-browser.cjs` checks the past day's posters are weathered and its
skyline has no overlapping labels; `tests/poster-art.test.cjs` checks the
wear is stable and only on past posters.

## Tilt your phone — 8 October 2026

Owner request. On a touch screen, tilting the phone makes the wall's posters
lean with it (with a moving highlight), the poster column lean, and the
skyline's layers shift: stars and the far city drift against the tilt, the
near city and its lights move with it. It follows how you hold the phone,
which slowly becomes the new rest, so holding it at any angle settles back
to still. A phone button beside Wall/Skyline turns it off and on (the choice
is remembered on this device). Phones that ask for motion access (iPhone)
start it from that button; others start it straight away. Never with reduced
motion. `tests/wall-browser.cjs` sends tilt readings and checks the posters
follow and the button stops it.

## The skyline answers the column — 8 October 2026

Owner request. In the Skyline view the column now stands beside the city, as
on the wall. Every poster on the column has a light on the skyline (the
day's events, and the later listings as rings until they face you); a
listing outside the drawing is named at the edge ("Further north: …").
Turning the column brightens the light of the poster facing you, smoothly as
it turns, and dims the others; tapping one of those lights turns the column
to its poster the short way round (bringing the column into view first on a
phone). Lights for the day's other events still open their details. The
featured pick isn't repeated as a card below the sky, and the count line
says "on the poster column" in both views. Beside the column the sky's title
sits above the drawing.

## The column turns, and the wall comes alive — 8 October 2026

The owner liked the poster wall and asked for it to be more interactive and
moving.

- **The column turns like a real Litfaßsäule.** It now carries everything
  coming up: the featured pick, then the later listings in the same city
  series (which moved off the wall). Drag or swipe sideways, use the arrows
  under it ("1 / 4") or the arrow keys. Posters sit a quarter turn apart;
  seen from the front, a poster at angle θ is centred at R·sin θ and narrowed
  to cos θ, so turning bends them round the column's sides. A flick turns one
  poster, a long drag lands on the nearest, and only the poster facing you
  can be used (the others are inert). Once a session the column turns a
  little and back to show it can; never with reduced motion.
- **A lamp under the cap** lights while an evening or night poster faces you.
- **Posters are pasted onto the wall** one after another as a day loads
  (360ms, 70ms apart), and turn over in depth.
- **With a mouse, a wall poster leans towards the pointer** and catches the
  light. Touch and reduced motion keep it still.
- **Your choices show on the posters.** Saving pins a poster with a push pin;
  once an event is in your plan for its day it gets a stamp: "In your plan",
  or "Added · not confirmed" while it's a proposal. A sample plan you didn't
  make isn't stamped.
- The week's notes (the 3 October holiday) stay pinned to the wall.

`tests/wall-browser.cjs` now also checks the column's four posters, that the
listings can't be planned, turning with the arrows and that only the front
poster is usable.

## On the wall: the Events page since 8 October 2026

The owner approved the poster wall and asked for it to replace Events
(8 October 2026). It was reviewed first as a separate page; `../events-wall/`
now forwards here. `tests/rail-height-browser.cjs` was removed with the poster
rows it checked; `tests/wall-browser.cjs` covers the wall.

The owner chose concept A (Poster Press) from the
[Events concepts canvas](https://claude.ai/artifact/9L2kfkt4VaeeRzQsR9BCGw)
and asked for concept B (Rhine tonight) to join it, with posters wherever
there's no picture.

### What it does

- **Every event is a poster set from its own listing.** No photograph and
  nothing added: title (and its English meaning), date, time, place,
  admission and category. The category picks the shape (Culture rings, Games
  checks, Outdoors waves, Language speech bubbles) and the hour picks the ink
  (before 17:00 yellow, until 20:00 purple, later night navy with stars; no
  time is plain paper). Unknowns read "Price not listed" or "Time not listed
  yet". Titles use Bricolage Grotesque as poster lettering
  (`fonts/` links to the self-hosted files in `public/fonts/`).
- **The poster column** (a Litfaßsäule) holds what's coming up: the
  featured pick and the later city listings. It stays put while you filter,
  as the owner decided for Events, and turns (see the section above). The
  chosen day's other events hang on the wall beside it.
- **Turn a poster over** (tap it) for the back: when and where, the quiet facts
  list (Admission, When to arrive, Language, Who it's for, Accessibility from
  the venue's listing, otherwise "Not listed"), "Fits your day" from the
  person's recorded appointment only, and Add to plan, Save and More about it.
  The turn is a 320ms half-turn, without motion when reduced motion is on;
  Escape turns it back.
- **Next in the city's calendar.** A captured listing names a later date in the
  same city series ("City listing for 6 October 2026 in the same series"), so
  those dates hang as paper posters. Their time and price aren't captured, so
  they say so; they can't be planned and link to the city's listing.
- **The week's notes.** A public holiday in the next seven days (3 October,
  German Unity Day) is pinned as a note, with the person's own plan that day.
- **Already taken down.** The captured 8 September programme, faded, with a
  link to that day. Its posters carry "Past" and no Save or Plan.
- **Skyline view** (Wall / Skyline switch, kept in the URL as `?view=skyline`).
  An illustrated Cologne skyline (the Kranhäuser, Groß St. Martin, the TV
  tower, the Dom and the Hohenzollern bridge) with the day's events as lights
  above their district, and the person's 14:00 appointment marked. Afternoon,
  Evening and Late repaint the sky and dim the other lights. Districts that
  aren't drawn (Chorweiler, Junkersdorf) are named at the edge. Below, the
  events as photo cards; an event with no usable picture gets its poster's art
  instead, labelled "Poster from the listing". The sample film night is one:
  its illustration showed mountains and a lake (flagged 7 October).
- The event sheet's cover follows the view: the poster's art on the wall
  (credited "Poster set by Expadu from the listing"), the picture on the
  skyline.

Filters, the week/month calendar, search and no-match messages, saving,
planning (Add to plan, "It isn't confirmed yet", Review plan in Composer),
Get there and the past-day rules are the Events page's own (`app.js`); `wall.js` swaps only how events are
drawn by replacing `ExpaduEventFestival`'s feature, render and image. The
Festival cards (`festival.js`) still draw Today's events and the Skyline view's
photo cards.

### Files

- `poster-art.js`: the poster generator (ink, shape, front, back, turn).
- `wall.js`: the column, the wall, the later listings, the past strip, the
  skyline and the view switch.
- `wall.css`: posters, column, wall, skyline and the phone layout.
- `fonts/`: links to the self-hosted Bricolage Grotesque in `public/fonts/`.

### Checks

- `node --test prototype/dev/design/events-next/tests/poster-art.test.cjs`:
  ink and shape rules, unknowns, separate Save and Plan, no actions on past
  or later listings, "Fits your day", escaping.
- `node prototype/dev/design/events-next/tests/wall-browser.cjs` (server on
  8765, or `BASE=`): at 390×844 the column poster's Add to plan and both filter
  rows are on the first screen with nothing under Composer; no sideways
  scroll at 390 or 1440; turning a poster over and back; three later listings
  without planning; Skyline lights, the poster card for the film night and a
  light opening the sheet; the 8 September posters without Save or Plan.
- Checked by eye at 390, 768, 1024 and 1440. Screenshots:
  `docs/reviews/2026-10-08-events-wall/`.

### Open for the owner

- Poster lettering uses Bricolage Grotesque, which is still undecided for the
  app's own titles; page titles here stay Geist.
- The holiday note's list (`wall.js`, `holidays`) is preview data for
  Germany in 2026, not a calendar service.

## Poster rows no taller than the featured poster — 7 October 2026

On the sample day the featured poster takes Street culture night, so "Let the
evening happen" held one card. It spanned the whole row and its 4:5 shape made
it 1,701px tall at 1440px (1,181px at 1024px): nearly two screens of one
picture. Row cards are now capped at the featured poster's 420px; a lone card
keeps its 4:5 poster shape (336 × 420) and two to four cards fill their
columns. Phones are unchanged (307 × 384). `tests/rail-height-browser.cjs`
checks one to four cards at 1440, 1024 and 390px. Evidence:
`docs/reviews/2026-10-07-app-in-colour/partb-events-*.png`.

Flagged, not changed: `photos/film.webp` (Cinema under the stars, Stadtgarten)
shows Alpine peaks and a lake behind the audience, so it reads as somewhere
else. It's labelled as an AI illustration of the activity, but a Cologne-like
scene would fit better: a park lawn under old trees at dusk, string lights, a
screen showing an abstract film still, a low city skyline with no mountains or
lake. That needs a new image with the same labelling; none is generated here.

## Quieter facts, clearer filters and messages — 7 October 2026

From the owner's Events review (items 7–12):

7. **Facts without alarm.** The big yellow "Price to check" and lavender
   "Entry: To be confirmed" tiles are gone. The sheet has one quiet list:
   Admission, When to arrive ("By the start time", "Any time while it's open"
   or "Not listed"), Language, Who it's for and Accessibility, with "Not listed"
   in soft text and one line asking to check those with the organiser. The
   reminder reads "Remind me before it starts · Reminders aren't sent in this
   preview". Cards say "Price not listed".
8. **No-match messages.** A search that finds nothing names the word and the
   day ("No events match “jazz” on Wed 30 Sept"), suggests another word, and
   offers Clear search. When the same search or filters match another day, it
   says so and links there (for example "chess" on 30 September points to
   8 September, which has already happened).
9. **Featured is "Coming up".** The featured poster stays independent of the
   chosen date and its caption now says "Coming up · sample programme, AI
   illustrations", so it doesn't read as that day's programme.
10. **One icon per category** on cards, chips and the event sheet (Culture is
    always the museum icon). The sample day's film night is Culture, like the
    captured film listing, so there's no separate Film chip.
11. **Long German titles** wrap at whole words. Only words of 13+ letters may
    hyphenate, keeping six letters on each side ("Feierabend-rundgang", never
    "Fei-erabendrundgang" or "Klima-wandel").
12. **Compact filters (owner rework).** Free, Saved, Travel and Sort are 44px
    icon buttons with tooltips and spoken names, so the search keeps the room
    (about 230px on a 390px phone). Category and Area are dropdowns: they name
    their filter ("Category", "Area") until something is chosen, show a chevron,
    and turn cyan with the chosen value (on phones the value replaces the
    icon). Two aligned rows fit at 375px and wider; on narrower phones long
    values shorten with an ellipsis. Desktop keeps its row, with "Area" and a
    chevron.

## Sample day, past listings and clearer details — 7 October 2026

From the owner's Events review (items 1–6):

1. **One sample day.** Events opens on Wednesday 30 September, the day Today and
   Composer use (`connected/sample-day.js`), with its two evening events. The
   captured 8 September programme is a past sample: "Past · 8 Sept" markers,
   no Save or Plan, a note above the results and "Past sample" beside the count.
   Their sheet keeps the same three tabs: About says it already happened, Plan
   explains why it can't be planned and links to upcoming events, and Get there
   gives directions to the venue only (from today's starting point, with no
   event date or arrival time). Empty dates name both days.
   The calendar's Today button goes to the sample day.
2. **What an event is.** Cards and the sheet show the English meaning of German
   titles. The sheet shows the city's description where Expadu's local catalogue
   has one, translated and labelled with the later listing in the same series
   it comes from, and a Good to know list: language, who it's for (ages) and
   accessibility, each "Not listed" when unknown. Search also matches English
   titles (for example "chess").
3. **Planning in plain words.** "Add to plan"; after adding, "Added to your
   plan. It isn't confirmed yet." The Museum Ludwig exhibition is a flexible
   visit within its opening hours. Get there starts from your chosen starting
   point, otherwise from where you'll be (after the Blue Card appointment, or
   the sample day's starting point) with a short reason.
4. **Starting point.** Districts, Neumarkt and listed venues are recognised
   (`distance.js`: district centres and venue points read from Expadu's local
   catalogue on 7 October 2026). Cards then show a straight-line distance, the
   count line says where it's measured from, and "Closest first" appears in
   Sort. Unrecognised places and the film venue (no captured location) show no
   distance and sort last. No travel times are claimed.
5. **Composer button.** With the shorter poster, no control sits under it on
   first load at 390×844, 375×812, 393×852, 820×1180, 1280×800, 1366×768 or
   1440×900. On a 375×667 phone the Saved filter button still starts beneath it.
6. **Featured once.** The featured poster shows upcoming events only and is
   shorter (about 380px on phones, 420px on desktop); while browsing, featured
   events are left out of the rails ("1 featured above"), while filtered
   results list every match.

Verification: Events 51 tests (6 new for these rules), connected 153, Today 44,
Composer 111, Paperwork 65, Travel 124, Places 14 and Today C 56 pass. Every
other page's default view at 390px and 1440px is pixel-identical to the previous
commit. Browser checks covered the flows above at phone and desktop sizes with
no console errors.

## Connected review and frontend — 3 October 2026

Selecting an event now ends with a direct **Review plan** action that retains its
date. Reopening a pending selection or proposal still describes it as a proposal;
it never becomes a confirmed day merely because the sheet was closed. Undo and
the separate Get there tab remain available. The listed schedule stays fixed.

The real `/events` page now uses the same immersive card direction, with a
separately loaded upcoming carousel that stays in place while results are
filtered. It reads existing occurrence APIs and their rights-filtered media.
Missing or failed media becomes a compact information card. Unknown prices,
finishes and venue coordinates remain explicit. Empty programmes do not claim
that all events have finished or promise more listings on another day.

Real selections pass `event`, the original ISO `start`, and its Berlin `date` to
Composer for review. This action neither submits a prompt nor changes a saved
plan. Composer owns the later explicit addition and confirmation. Device-only
bookmarks store occurrence IDs under the current account, labelled **Saved on
this device**; they are separate from Composer's saved plan and from reminders.
No new backend endpoint, catalogue import, deployment or sample image is involved.

Verification: the 45 prototype Events tests and four real presentation/handoff
tests pass; scoped ESLint and the application typecheck pass. Browser scenarios
are in `tests/Browser/events-connected.spec.ts` for the root integration review:
hero preservation during filtering, dated review without mutation, unknown
facts/media, feed retry, failed device saving and failed reminder persistence.
This note does not claim that those browser scenarios have run yet.

## Festival implementation for review — 29 September 2026

The owner rejected the split image/white-information card treatment and the hero
disappearing on filter changes, and prefers
[Concept B / Festival](../connected/concepts/events-festival-b.png). The Events
prototype now implements that direction: full-image cards, brush lettering on
the featured carousel, yellow date markers and separate compact Save and
calendar-plus actions. See the
[shared decisions](../connected/design-decisions.md#preferred-event-direction-concept-b--festival--29-september-2026).

Three explicitly chosen sample events form a swipeable featured programme.
Mouse dragging, arrows, dots and keyboard navigation work without autoplay.
The hero and selected slide stay in place while filters update results below.
The calendar belongs to that dated result section, not the independent featured
programme. The sample date remains 8 September; other dates do not invent events.

Collections use the desktop width and become horizontal rails with a visible
next card on phones. Filtered results keep the selected order. The first hero's
planning action clears the Composer button even on a short phone. The date
picker remains within the viewport when expanded to its month view.

The existing fixed-time planner, details, directions, proposal handoff and
swipe/double-tap Delete reveal remain connected. Today now shares the Festival
cards, photo treatment and frosted Save controls. The new app search entry is deferred; the existing Events
search remains. The separate logo task has not been applied to the app shell.

Photos are clearly labelled AI illustrations, not photographs of the actual
events or venues. They are prototype assets and do not bypass media approval;
approved healthy media with source and credit still takes priority. See
[asset provenance](photos/README.md).

Browser checks cover 320px, 393px and 1440px layouts, carousel drag/arrows,
retained hero state during filtering, Save/Saved, fixed-time planning, the day
Delete reveal and adding an event to the proposal. Review screenshots:
[phone](festival-mobile.png), [desktop](festival-desktop.png).

## Superseded event-first refinement — 29 September 2026

The owner approved the [researched discovery direction](../connected/event-discovery-research-2026-09-29.md).
This iteration was implemented for visual review and subsequently rejected.
The notes below describe that earlier iteration, not the current visual direction.
It changes the connected prototype, not the Laravel application.

One explicit editorial sample pick uses the page width, followed by afternoon
and evening collections. The calendar is an anchored week/month picker at all
sizes. Search, category, area, Free, Saved or a non-default sort switch to ordered
matching results without a feature. Reset returns to discovery. The controls stay
visible when the feature collapses. Filter state, dates, Back/Forward and empty-day
recovery retain the existing behavior.

Event details sit below the artwork. Save and the compact planning action are
independent. Original illustrations remain labelled samples; no unapproved
photographs or invented news items were added. Media with approved rights, active
health and attribution uses the shared image path, with a failed-image fallback.

Browser review covers phone, tablet and desktop sizes, separate Save/Plan actions,
fixed-time planning and the existing timeline Delete reveal. The Today, Events
and connected regression suites pass. No console errors were observed in the
reviewed pages. Final review screenshots: [phone](discovery-mobile.png) and
[desktop](discovery-desktop.png).

## Bureaucracy consistency pass — 28 September 2026

Events is the first review of the owner's Bureaucracy reference for proportions
and visual consistency. Changes are scoped to Events styles:

- 44px touch targets, 14px discovery labels and 18px control icons.
- Soft rails and white selected surfaces for category, calendar view, detail
  tabs, travel modes and visit suggestions; cyan still identifies chosen dates
  and filter options.
- A calmer shared page canvas, clearer event metadata and rounded cards.
- More readable detail labels, stable cover height and compact faded footers.
- Narrow-phone spacing keeps the default filter labels visible at 320px.

The existing gallery, artwork, occurrence data, single week/month calendar,
Composer connections and planning behavior are preserved. Bureaucracy, Places
and Departures are not changed by this pass. Existing motion and reduced-motion
rules remain in place; no decorative animation was added.

## Verification

- The 25 existing Events tests pass, covering dates, filtering, calendar
  navigation/dismissal, poster actions and swipes.
- Browser review at 320px, 393px and 1280px: no page overflow, default mobile
  labels fit, and the first event action fits in the phone viewport.
- Area, Free and search filtering work; Escape closes the travel picker and
  restores focus. The input uses its inset focus edge without an outer ring.
- Week/month views retain the chosen day. About, Plan to go and Get there keep
  the same 104px cover at the tested phone height. The three time suggestions
  remain in one row and update the form/summary without adding a proposal.

Review images: [phone](review-mobile.png), [desktop](review-desktop.png),
[plan](review-plan.png).

## Your day removal follow-up — 28 September 2026 (superseded below)

The review found that a provisional visit had a separate Clear action, which
skipped both the warning and the swipe interaction. It now uses the same
right-to-left inline confirmation as a planned activity: drag left or tap the
Remove icon, then choose Cancel or Remove. Cancel and Escape keep the times.
Confirming a preview removal clears only the unfinished time choice; confirming
removal of a planned activity keeps its guarded Undo action. Changing the
preview times invalidates an older removal confirmation.

Verified both entry paths on preview and planned rows in the memory-only Events
fixture, plus cancellation, confirmed removal, Undo and the single-line warning
at 320px. The connected/Events/asset checks pass (149 tests). The fixture tests
do not read or write the user's stored plans.

## Event timing and day cards — 28 September 2026

Planning now keeps the listed start/end by default and presents them as a compact
schedule, without time pickers or suggestions. Only an explicit visiting window
offers flexible attendance. The captured programme's unknown attendance policies
remain unknown; the UI no longer interprets them as permission to suggest shorter
visits. An unlisted finish asks for an expected end while preserving the start,
and the plan carries that finish as an estimate.

Your day uses soft pale cards, dark time labels and a small cyan Preview badge.
The purple outline and left border are removed. Planned activities and all new
previews retain their Remove action and swipe confirmation. Removing a fixed-time
preview dismisses that selection without clearing its listed schedule. Undo
restores it. A flexible preview still clears its chosen times after confirmation.

Verified scheduled-time planning, an estimated finish, retained arrival time,
and swipe/button cancellation in the memory-only fixture at phone widths,
including 320px. Fixed-event conflicts ask to review the day, not change the
event's start. The connected, Events and asset suites pass (154 tests).
Review: [scheduled event and day preview](review-fixed-time.png).

Fixed-preview follow-up: reproduced the missing gesture on SchachXperten, then
separated preview removal from time editing. Tests cover fixed, unknown and
default event policies, cancellation, confirmed dismissal, Undo and explicit
addition with the original schedule. The previous exclusion of fixed previews
was incorrect.

Verified a real left drag and the Remove button on SchachXperten, followed by
Cancel, confirmation, tab switching and Undo. Its 16:00–17:30 schedule remains
unchanged. The warning stays on one line at 320px, and Escape dismisses only the
warning. The connected, Events and asset suites pass (158 tests).
Review: [fixed-preview removal confirmation](review-fixed-preview-removal.png).

## Your day: swipe actions — 28 September 2026

The later Apple Notes reference replaces the full-width confirmation and permanent
trash control. Swipe left or double-tap an activity to slide it 64px aside and
reveal a red circular Delete action; the time column stays still and the activity
remains partly visible. Only tapping Delete removes it. Swipe right, tap the
activity or elsewhere in the sheet, or use Escape to close the action. Keyboard
and screen-reader users can activate the activity to reveal the same action.

Motion tracks the finger, settles over 300ms, and respects reduced motion. Short
or interrupted gestures restore the row, vertical scrolling stays native, and the
release click cannot delete anything. Fixed-time preview dismissal and planned
activity Undo retain their existing safeguards. No backend change is involved.

Verified in the memory-only fixture: single tap, double-tap, left swipe, swipe
back, tap-away, Escape, keyboard Space, deleting previews and planned activities,
and Undo. Reviewed at 320px, 393px and 1280px. The connected, Events and asset
checks pass (163 tests). Review: [compact swipe action](review-day-swipe-action.png).

## Date continuity — 29 September 2026

The selected programme date is retained in the URL. Choosing a date or viewing
the sample programme updates that date; refresh and browser Back/Forward restore
the corresponding programme without adding duplicate history entries. Unrelated
query parameters and filters remain intact. Regression tests and browser checks
cover sample-date recovery, reload and Back/Forward.

Today now opens this same event sheet for event details and planning. Artwork
paths resolve from both entry points, and fixed schedules, directions and day
actions share the same behavior.
