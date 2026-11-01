# Expadu: page review and connected journeys

Reviewed 18 September 2026. Scope: the approved Today C design and its linked department prototypes, not the Laravel application. This is a review and proposed work order; it does not approve new designs or implement them.

## Recommendation

Preserve Today C, Composer, Bureaucracy and Departures as the accepted foundations. Refresh Places and Events. Services needs its remaining workflow designed, beyond collecting a search brief. Define the connections before changing individual pages, then validate them together after the page work.

The palette, typography and department-specific layouts already give the app a common identity. The larger inconsistency is behavior: the same bookmark or planning intention currently means different things on different pages.

## Page inventory

| Surface | Decision | Work needed |
| --- | --- | --- |
| Today C | Keep; integration pass | Retain the daily overview, cards and quiet Composer launcher. Derive relevant progress and next journeys from the same records as the departments. The top sample checklist and sample departures are not yet a unified personal overview. |
| Composer | Keep; extend supported interactions | Preserve continuous conversation, proposals, approvals, version history, minimize/resume and manual editing. Complete event, route and service interactions inside this model. |
| My Plans | Keep; integration pass | Include travel between activities, uncertainty and changes from linked appointments/events. Keep saved plans separate from external bookings. Weekly planning is explicitly later. |
| Saved | Keep; extend deliberately | Match bookmark behavior throughout the app. Decide how saved journeys/providers join the collection as those workflows become available. Saved items remain unscheduled. |
| Bureaucracy | Keep; integration pass | Preserve Overview, Paperwork, process state and source coverage. Connect only explicitly selected appointment details or relevant service needs. Do not transfer the whole case into chat. |
| Departures | Keep; integration pass | Preserve route search, stop boards, route details and saved trips. Connect a chosen journey to a particular activity/day, with return navigation and clear stale-route behavior. Update the surrounding Composer entry to the approved pattern. |
| Places | Refresh | Preserve the visual field guide, search and categories. Replace legacy plus/draft semantics with Save and Add to plan. Reduce mobile controls above results; align detail actions and Composer entry with Today. |
| Events | Refresh plus scheduling design | Preserve the date-led programme. Compact the mobile header/filter area, align Save/Add to plan, and design occurrence selection, attendance time, reminders and route context together. |
| Services | Complete/rework the workflow | Current UI stops at a requirements form. Design results, provider details, contact/external booking, no coverage, and recording an already-confirmed appointment. Use only supported provider data; do not fill the directory with fictional results. |
| Account/preferences, city and notification controls | Define remaining scope | The Today account sheet currently offers My day, city and preview information. A finished settings journey is not established by that sheet. Decide where language, travel preferences, notification permission, approval mode and history controls belong. |

## Findings, ordered by impact

### 1. Shared actions still follow different product models — high

Today and Composer distinguish bookmarks from planning. Places still renders a large plus, an “In my draft” filter and “Add to draft” in details. Events also uses “Add to draft.” These actions operate on a pending selection, not on the Saved collection.

Use the approved distinction everywhere: **Save** keeps an item for later; **Add to plan** proposes a scheduled activity for a clearly identified day. Adding several items should remain possible without repeatedly leaving the list. Confirmation and Undo must act on the same plan regardless of entry point.

Evidence: `places-next/app.js`, `events-next/app.js`, `connected/content-ui.js`, compared with `today-c/slides.js` and `composer-studio/integration.js`.

### 2. A handoff is not yet a complete connected journey — high

The prototype already has useful connections. In the browser, an event's Directions action opened Departures with its venue, date and arrival time intact. Source inspection also shows route and service details reaching Composer, and explicit Bureaucracy appointment sharing.

However, imported routes and service briefs currently become context cards. They do not become travel legs attached to a day's activities or completed service requests. The context cards' Review links return to generic department URLs without the original item-specific parameters. A person should be able to review the exact route or brief and return to the same conversation and plan.

Agree for each handoff: which item, which occurrence/day, what changes, what remains only a request, what needs approval, and where Back returns.

Evidence: `connected/bridge.js`, `composer-studio/integration.js`, `composer-studio/conversation-view.js`.

### 3. Event listing times and attendance times need separate meaning — high

The catalogue displays an exhibition at 10:00–18:00, and Composer currently imports events as fixed scheduled intervals. The design must distinguish a fixed session from an opening/visiting window; the title alone is not evidence of which applies.

For an explicitly documented visiting window, ask when the person wants to attend and for how long, within that window. For a fixed session, keep its occurrence time. When the listing does not establish the distinction or an end time, show the uncertainty and request the missing detail. Route arrival should use the planned attendance time, with any chosen buffer.

This avoids interpreting every long listing window as a commitment for the entire day while preserving real fixed-time constraints.

### 4. Services needs an outcome beyond keeping requirements — high

The current page collects area, language, insurance where relevant and notes, then sends them to Composer. It correctly states that the provider directory is being prepared. That honesty should remain.

Design both supported outcomes: find and inspect an available provider, or keep a request when coverage is absent. Define contact/external booking versus an appointment the user has already confirmed. A saved request is not a booking, and adding an appointment to a plan must not imply that the provider has accepted it.

Bureaucracy can offer a relevant Services entry when reviewed process content supports it; it should carry only user-approved requirements. Provider selection must not mark a bureaucracy task complete.

### 5. Mobile discovery spends too much space before useful results — moderate

At 390×844, Events stacks navigation, a three-line heading, dates, search, starting point, travel modes and filters before the first result; the older Composer dock covers part of the remaining viewport. Places has a similar stack, with its first image low on the screen. Services shows six category tiles before most of the selected form.

Use a shorter page heading, compact primary controls and a secondary filter sheet where helpful. Show selected filters as concise removable choices. Ask for a starting point when it affects the task, without making it dominate initial discovery. Reuse the approved compact Composer launcher across departments.

Do not flatten every department into the same layout: Places benefits from imagery; Events from dates and times; Services from requirements and provider evidence.

### 6. Navigation and status vocabulary need one final pass — moderate

The reviewed departments still mix Saved, My day and draft language, and some have account access while others do not. Departures also has a separate Saved trips collection. Places rendered an old `/composer-next/` destination during this review even though current shared source points to Studio; this discrepancy needs a cache/served-asset check, not a guessed cause.

Keep one consistent header, Saved entry and Composer entry. Ensure all active links use approved screens. Define how saved trips are exposed without confusing them with confirmed plans. Keep preview/sample labels accurate as departments are connected.

## Connections to design

| Journey | Already present | Remaining design |
| --- | --- | --- |
| Event → Departures → Composer → My Plans | Venue, date and arrival handed to Departures; events can enter a proposal | Attendance semantics; route attachment; buffer/conflict preview; exact return context; rescheduled/cancelled occurrence behavior |
| Place → Saved or plan → journey | Details, directions, selection handoff; separate bookmarks on Today/Composer | Consistent department actions; chosen date/duration; routes tied to the activity; swap invalidates affected route checks |
| Services → provider/contact → appointment → plan | Requirements form and Composer context card | Coverage/results/details; contact vs booking status; confirmation; appointment identity and travel |
| Bureaucracy → appointment → plan → Departures | Explicit generic appointment sharing; Composer requests an end time before scheduling | Link back to the source record; detect later changes and ask before updating; optional route; never silently copy case data |
| Plan or department update → Today | Confirmed-plan strip and some shared Bureaucracy cards | Coherent next action/progress across overview and departments, including cancellation and uncertainty |

Composer should coordinate these journeys, while direct manual actions remain available. A route belongs to its travel record, a process to its bureaucracy record, and a confirmed plan references them. Repeatedly copying unrelated snapshots would make later changes hard to explain.

## Proposed work order

1. **Agree the common interaction rules:** Save versus plan; request versus booking; current versus historical; event attendance; return destinations; explicit approval for consequential changes. Document these before drawing new department screens.
2. **Refresh Events first**, alongside its place/event detail actions. Use event → route → plan as the first complete prototype journey. It exposes timing, approval and travel issues together.
3. **Refresh Places**, reusing the newly consistent actions and mobile controls while keeping its visual catalogue.
4. **Complete Services**, including honest unavailable-directory states and the appointment connection. Resolve provider capabilities before promising booking behavior.
5. **Connect and verify the full app:** Bureaucracy appointment, Services appointment, event visit and optional place in a day; relevant travel; Today summary; cancellation/Undo; return/reopen; current versus previous versions.

The final integration pass should verify the connections, not be the first time they are considered.

## Completion scenarios

- Save an event without scheduling it; later add its exact occurrence to a chosen day, inspect travel and approve the combined proposal.
- Replace a place and flag affected travel for rechecking rather than retaining an apparently valid old route.
- Keep a service brief when no directory result exists; when a confirmed appointment is entered, propose it without claiming to have booked it.
- Share a Bureaucracy appointment with minimal details; later source edits prompt a review instead of silently overwriting the plan.
- Cancel a day without losing bookmarks, changing external bookings or leaving a pending-add warning behind.
- Minimize/resume and return from any department with the conversation, draft, selected day and useful list position intact.
- Exercise denied notification permission, unknown times, stale data, no results and failed persistence on mobile. Never use a success animation to imply an uncompleted action.

## Review evidence and limits

Read the active department source, shared handoffs, Composer integration and approved design notes. Visually inspected all main page foundations at desktop and/or mobile, with explicit 390×844 checks of Places, Events, Services, Bureaucracy, Departures, My Plans and Saved. Inspected place/event details and verified the event-to-Departures URL and arrival controls through the UI. Restored the browser viewport afterward.

This is a design/integration review, not an exhaustive functional or accessibility certification. No contrast measurements, device keyboard tests, live route checks, provider lookup or real notification delivery were performed. No existing plan or bookmark was deliberately edited. No application code was changed. Older README claims describe earlier passes and are not treated as proof of current end-to-end readiness.
