# Events design preview

Review the captured sample programme at
`http://127.0.0.1:8765/dev/design/events-next/?date=2026-09-08`.
Other dates can legitimately have no sample events; this is not a live catalogue.

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
