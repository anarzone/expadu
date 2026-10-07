# Version C — Today, together

Preview: `http://127.0.0.1:8765/dev/design/today-c/`

## Content restoration — 30 September 2026

The owner rejected removal of the overview and utility cards. The request was
for shorter copy and clearer actions, not a nearly empty Today page.

Restored the paperwork, connections and sample forecast overview; the saved-plan
or unconfirmed-proposal strip; and the journey, day and paperwork cards. The
paperwork controls retain access to every recorded process. Fresh users keep
useful entry points without fabricated progress or urgency.

The compact next action remains above discovery when there is a recorded
activity today or an active journey preview. Event photography, frosted controls
and the draggable supporting rail retain the approved Festival design.

Reviewed at 320px, 393px and 1440px, including the forecast, plan review and
paperwork navigation. Relevant regression checks pass.
[Restored overview](restored-overview.png).

The earlier removal of these sections is superseded. Short labels, compact
mobile rows, readable card colours and direct icon actions are the refinement.
All state remains in the connected prototype; this does not change the Laravel
application or add live services.

The isolated fixture uses memory storage only:
`tests/fixtures/today.html` (fresh), `?scenario=planned`, `?scenario=journey`,
and `?scenario=paperwork`. Explicit scenarios use a fixed review clock and never
seed the regular preview.

## Festival implementation for review — 29 September 2026

The owner rejected the split image/white-information card treatment and chose
to carry the implemented [Concept B / Festival](../connected/concepts/events-festival-b.png)
direction from Events into Today. Search is recorded as later work, and the B logo is a separate asset task.
See the [shared decisions](../connected/design-decisions.md#preferred-event-direction-concept-b--festival--29-september-2026).

Today now reuses Events' immersive photographic cards, brush lead title, yellow
date markers, translucent frosted Save buttons and orange calendar-plus actions.
One explicitly curated event leads, with two distinct supporting discoveries.
The supporting cards stack alongside it on desktop and form a swipeable rail on
phones. The lead adapts to the phone height so its planning action stays visible
in the first viewport. The optional welcome and personal overview follow.

Save, the fixed-time event planner and details/directions use the shared flows.
The all-events link retains the sample programme date. No autoplay, new search,
logo replacement or new imagery was introduced. Photography is labelled as AI
illustrations; approved healthy provider media still takes precedence with credit.

Reviewed at 320, 393, 820 and 1440px, including Save, fixed-time planning and the
event's venue/arrival context. All 214 relevant Today, Events, shared planning
and asset-version checks pass. Review: [phone](festival-mobile.png) and
[desktop](festival-desktop.png).

## Superseded event-first refinement — 29 September 2026

The owner approved the [researched discovery direction](../connected/event-discovery-research-2026-09-29.md).
This iteration was implemented for visual review and subsequently rejected.
The notes below describe that earlier iteration, not the current visual direction.
It changes the connected prototype, not the Laravel application.

One full-width event leads on phones, with two wider supporting cards in a
native swipe rail. Desktop gives the lead more space beside two supporting cards.
The shared header and Composer remain; the optional welcome, personal overview
and useful day/workflow actions follow discovery. There is no automatic event
rotation. All events opens the captured programme date, without inheriting an
unrelated day or old filter selection.

Event details sit below the artwork. Save and the compact planning action are
independent. Original illustrations remain labelled samples; no unapproved
photographs or invented news items were added. Media with approved rights, active
health and attribution uses the shared image path, with a failed-image fallback.

Browser review covers phone, tablet and desktop sizes, separate Save/Plan actions,
fixed-time planning and the existing timeline Delete reveal. The Today, Events
and connected regression suites pass. No console errors were observed in the
reviewed pages. Final review screenshots: [phone](discovery-mobile.png) and
[desktop](discovery-desktop.png).

## Historical selected composition (superseded by refinements above)

The user selected Today Blue’s header, service menu and “A little clarity for your day” panel, followed by Dayboard’s exact staggered desktop card placement. C combines them in that order, with no heading above the general card area, and retains the persistent Composer with the orange submit action.

- Header: original Expadu mark, centred city selector, My day, account, greeting and weather; five services directly below.
- Overview: one shared white panel for paperwork, connections and weather. Compact stacked rows on mobile make all three visible without swiping.
- General card area: no visible section title. It preserves B’s six-card staggered desktop arrangement: a tall left place, transit above a tall centre place, a tall right event card, and smaller day and bureaucracy cards. Future content may mix services, recommendations and discoveries; it is not restricted to places and events.
- Composer: a translucent, blurred surround lets the board show through; the input and orange submit button stay solid. An opaque fallback is used when blur is unsupported or reduced transparency is requested. Typing stays on Today. Submit opens its workspace; choices survive review, removal, explicit save and return. A saved-day strip appears only when relevant.

Colours are Expadu cyan `#05BADD`, orange `#FF3902`, accessible action orange `#D9340A`, ink `#211D15`, and the pale canvas `#F6FAFB`. Geist provides one consistent type system. Motion responds to opening, choosing and returning; reduced motion is respected.

## Density and hierarchy refinement

The header, service menu and overview keep their selected positions with tighter vertical spacing. Destinations, departure times, forecast labels and overview actions are larger. The transit card uses white with an exact-brand cyan edge and icon, and starts a route request rather than repeating the departures summary. The six card positions are unchanged. Route entry asks for a destination and adds it to the current Composer request without overwriting earlier text or submitting automatically. Selection feedback consistently distinguishes a draft from a saved day. Mobile service labels and sample notices are enlarged.

## Content and report grounding

Place names, neighbourhoods, photograph URLs and attribution come from `prototype/current/01-today.html`, captured on 26 June 2026. The preview prominently displays that date. Photography is credited on the page and in details. It is an archived app snapshot, not a current availability check.

At the user’s request, the overview restores the demonstration data from the selected Today Blue screenshot: 2 of 4 personal preparation steps, sample departures in 4 and 11 minutes, and a sample 21° / 20° / 18° forecast. These are labelled samples, not live or legal data. The checklist is named “Personal preparation” in both the overview and its detail sheet. The stop is Rathaus, taken from the captured timetable; its displayed line letters and departure times remain demo data. The checklist is interactive and its progress updates. Place records remain the dated app capture.

The [Composer audit](../composer-first/report.md) informs preserved requests, distinct service responses, explicit unknowns and exact selected-ID saves. The [Today needs report](../../../../docs/today-needs-engine.md) informs relevance and honest data states within the mixed card area. Later user choices govern the final layout.

## Verification

Nine focused model tests cover saves, draft independence, unsupported constraints, unknown IDs, service routing and preserving requests when adding a user-chosen destination. Browser review covers desktop, mobile, input focus, keep/remove/save/return, paperwork, transit and event date controls. The two original concepts remain separate and unchanged.

This is a clickable review mockup. Composer is scripted and live services are not connected. Shared plans and Bureaucracy answers use browser-local persistence. Production integration and user evaluation remain separate work.

## Optional welcome — 28 September

A small dismissible welcome offers settlement help inside Today. It never gates
entry, and disappears after dismissal or starting the person's own guide.
`?welcome=preview` reveals it for design review without clearing other state.
Its action opens Bureaucracy for the signed-in person, even if a family plan was
last selected there. The personal paperwork summary and links follow the same
rule; family browsing does not replace or empty Today's personal records.

This is the agreed UI direction. The actual Laravel account onboarding is not
changed by this prototype.

## Finishing pass — 29 September

Today now uses the current shared Events sheet for event details and planning.
Artwork, fixed schedules, directions, the day timeline and removal/Undo behave
the same as the Events entry point. Other detail types keep their existing flow.

On phones the overview uses tighter rows, the duplicate journey card is omitted,
and Places/Events discovery cards come first. A visible optional welcome replaces
only the empty personal paperwork invitation; existing records remain visible.
The accepted staggered desktop arrangement is preserved.

Reviewed at 320px, 393px and 1280px. At 393×852 the first two card actions are
visible with the fresh welcome open. The memory-only fixture at
`tests/fixtures/today.html` allows planning and removal checks without changing
the user's saved prototype data. Shared planning, artwork and Today tests pass.

The overview arrows use the shared action orange, with a pale orange circle on
phones. Hover and keyboard focus nudge the arrow 3px; touch has a subtle press
response. Reduced motion preserves colour feedback without movement.

## Contextual place ideas — 29 September

Places has left the main navigation. The approved desktop board is preserved,
while park/activity suggestions now lead with category, name and neighbourhood.
Photos support a card when available; missing/failed photos leave no empty media.
Activity suggestions include the captured pitches, table tennis, playground and
swimming spot. They open the shared place sheet with Save, Plan and Get there;
proposal approval remains in Composer. No live access or opening hours are implied.
