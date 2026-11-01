# Expadu page-by-page launch review and implementation plan

> **For agentic workers:** Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` for implementation after the relevant page review. Track completion with the checkboxes below. Parallelize independent investigation and testing; keep shared UI and data-contract edits coordinated.

**Goal:** Prepare the actual Expadu app for a trustworthy, friendly first public release by reviewing, implementing and verifying each page against the approved designs and real user needs.

**Architecture:** The Laravel/Inertia/React app is the shipping product. Approved prototypes supply visual and interaction references; production services, identity, plans and permissions stay in the actual app. Composer coordinates supported requests and shared planning state, while dedicated pages provide direct controls for their own tasks.

**Tech stack:** Current manifests specify Laravel 13, Inertia 2, React 19, TypeScript, PostgreSQL/PostGIS, Redis and MapLibre. CI uses PHP 8.4 and Node 22. Check active local runtimes before project commands.

**Spec:** The owner's 5 October launch-planning request, root `AGENTS.md`, and `prototype/dev/design/connected/design-decisions.md`. Later owner decisions supersede older experiments and reports.

**Status:** Planning complete; implementation authorized and carried out on 5 October. Local implementation and final verification are recorded in the launch handoff. Checked items below mean completed within the observed local scope, not deployed acceptance. Mixed items requiring real devices, target data or staging remain unchecked even when their local part is complete. Follow `docs/reviews/2026-10-05-launch/progress.md` and the area reports for observed results. Local implementation does not mark staging, physical-device, source-data or release-authorization gates passed. No deployment has been performed.

## 1. Product and scope

Expadu should help a person understand their day, discover worthwhile activities,
get somewhere, and deal with practical life in a city. Start with Cologne and
keep city-specific assumptions in data/configuration. Do not make the product
feel like a strict schedule or a paperwork management system.

The main navigation remains Today, Events, Paperwork, Travel and Services.
Composer is the shared planning engine. Saved ideas, dated plans and history
must be reachable consistently. Places remain contextual content in discovery,
events, services and Composer; they do not return as a sixth main destination.

This plan covers the actual app, its supporting detail views, account flows,
public entry points, source-data readiness and release operations. A new visual
identity, unrelated features and native app-store releases are outside this
launch pass. The working assumption is a responsive web/PWA release; device
limitations must shape the promises made about background tracking and alerts.

### Global constraints

- Preserve accepted designs. Record a specific discrepancy before changing a page; do not replace it with another concept during implementation.
- Use the actual app's shared colour tokens and the approved page references. Travel's current warm paper/Lagoon treatment is its reference. Resolve any cross-page palette conflict in the page review instead of silently restyling Today.
- Keep one page header; utility pages do not gain a widgets rail. Compact controls, purposeful cyan selections and orange actions remain consistent.
- Composer help on utility pages stays small and dismissible. Dedicated item actions remain prominent. Entering Composer must not send a message, book, or confirm a plan automatically.
- Build useful density from relevant content, grouped relationships and real unfinished work. Do not fill space with fabricated personal activities, provider listings or repetitive action cards.
- Prioritize mobile content, readable text, 44px touch targets, immediate subtle feedback, focus continuity and reduced motion. Avoid large blocking dialogs for simple choices.
- Distinguish live, scheduled, cached, estimated, draft, saved and confirmed states. A timestamp or label must reflect the source actually used.
- Preserve `Task::authoritative()` and `RuleSourcePolicy`. No invented bureaucracy fees/deadlines or AI-authored authoritative guidance. Coverage gaps stay explicit.
- Preserve `PublishedMediaSelector`: only approved rights and active health. Photos are optional; missing media never becomes a stock-photo substitute for a real place.
- Preserve canonical identities and verified routing points. Unknown location, access, price and hours remain unknown.
- Keep user data account-scoped. Never use a populated sample preview as proof of deployed catalogue quality or completed backend integration.
- Preserve unrelated work. The current checkout has extensive uncommitted work; reconcile it before integration. Do not reset it or import another branch wholesale.
- Planning and local page work do not imply authorization to deploy production. Release a concrete, reviewed candidate through the existing staging/main workflow after release authorization.

## 2. Initial findings that shape the work

These are source observations or dated reports, not completed launch tests.

| Finding | Implication for launch | Owner task |
|---|---|---|
| Travel prototypes use saved captures; the actual app already has routing, departures and trip-session foundations. | Integrate and verify the real engines behind the accepted interface; do not build a second live service in the prototype. | 3 |
| TRIAS routing fallback accepts but does not honor requested depart-at/arrive-by constraints; its parsed clock times are anchored to today. | Correct provider failover before relying on future appointments, arrival deadlines or overnight journeys. | 3 |
| Departures are grouped into minute arrays without individual service/boarding IDs and observation times. Cached source labels and retained GPS fixes can still imply live information. | Establish stable departure identity and explicit freshness; qualify position accuracy/age before prompting journey progress. | 1, 3 |
| Actual Services returns `comingSoon=true`; the provider directory is unavailable. | Review a useful preparation-only launch scope, or finish verified provider discovery before advertising it. Removing the guard alone is not a fix. | 8 |
| Composer planning/workspace exists, but open-ended conversation is explicitly unavailable. | Establish supported requests and prove every advertised prompt. Do not present a scripted acknowledgement as a completed search. | 1, 4 |
| Event bookmarks are user-keyed device storage; Composer Saved currently retrieves server-side saved places. | Unify saved-item expectations, retrieval and persistence. | 4, 6 |
| Actual Events exposes four relative date windows; specific event and saved-event routes redirect to the general page. | Review calendar parity and preserve exact occurrence/deep-link context. | 6 |
| Today's clock is captured at mount. | Test midnight, suspended tabs and returning to an elapsed appointment. | 5 |
| Onboarding completion always redirects to Paperwork. | Review Today or the user's original destination as the default; enter Paperwork when relevant. | 9 |
| Settings/alerts include optimistic writes that suppress request failures. | Success feedback must reflect persisted state; add visible failure/retry and rollback where needed. | 10 |
| The service worker caches authenticated pages and API responses under shared names; logout has no explicit worker-cache purge in the inspected handler. | Investigate account-switch/offline isolation before release. This is a source-level risk, not a reproduced leak. | 2, 9 |
| App update handling can reload on worker activation/tab return. | Preserve drafts, searches and active trips during updates; eliminate first-load flashes and jumps. | 2 |
| CI runs desktop/auth browser tests; the mobile project and TypeScript check are not in the release gate. | Add reliable launch-critical coverage and verify actual deployed health after a successful workflow. | 13 |
| The places investigation reports an expanded local catalogue in a separate branch; older staging reports also record provenance holds. | Reconcile current code, deployed dataset and eligible records independently. Local counts do not establish release coverage. | 0, 11 |

Source starting points: `resources/js/pages/`, `app/Http/Controllers/`,
`app/Composer/`, `resources/sw.ts`, `.github/workflows/ci.yml`,
`docs/reviews/2026-10-03-friendly-connected/README.md`, and
`docs/places/production-readiness.md`. The 3 October handoff in **Investigate
places data quality** is historical integration evidence; recheck its current
branch and environment status before promotion.

## 3. Review method and definition of done

Review one coherent page or flow at a time. Shared data contracts are checked
first; Travel is the first detailed visual/functional page review.

For each page, complete this loop:

1. **Establish the job:** who is opening it, what they need now, and its primary action.
2. **Inspect the actual page:** fresh/returning accounts, real data, mobile and desktop. Compare with the named approved reference.
3. **Record findings:** screenshot, reproduction, user impact, severity, source/data dependency and recommended fix. Separate visual issues from unavailable capabilities.
4. **Confirm the concrete direction:** show the page-level proposal where a design or launch-scope choice changes the accepted experience. Routine fixes follow established decisions.
5. **Implement in the actual app:** small coherent changes using existing components and services. Add focused regressions for changed behavior, not implementation-shaped tests.
6. **Re-review independently:** rerun affected user tasks, fix findings and verify returns to other pages.
7. **Close the page:** accepted screenshots, working actions, real-data provenance, passing relevant checks and an explicit list of any intentionally unavailable capability.

Use statuses: **Not reviewed → Findings recorded → Direction agreed → Implemented
locally → Verified on staging → Accepted for launch**. Design approval, passing
tests and deployment are separate columns; none implies the others.

Severity:

- **Launch blocker:** privacy/data loss, broken access, false real-time/authority claims, a promised core action that cannot complete, or a mobile dead end.
- **Required before release:** significant confusion, inaccessible controls, context loss, inconsistent persistence, missing recovery or a rejected design mismatch.
- **Follow-up:** polish or an additional capability that does not undermine an advertised launch task. Document it; do not quietly redefine a blocked task as polish.

Every page must cover populated, first-visit, empty, loading, failed request,
stale data and expired-session states where applicable. Verify cold load,
repeated refresh, Back/Forward, opening a deep link, and leaving/resuming the app.

Review at 320px and 390px phone widths, a typical larger phone, tablet and 1440px
desktop, with a wide-desktop check for disconnected empty regions. Check keyboard,
screen-reader labels, visible focus, text enlargement, contrast, reduced motion,
software keyboard and safe-area spacing. No horizontal page overflow or
hover-only action. The first useful content/action should be visible on a normal
phone without navigating through oversized chrome.

**Per-page evidence pack:** `docs/reviews/<date>-launch-<area>/` containing the
actual route/environment/commit, before/after screenshots, scenarios with observed
results, relevant test results and unresolved dependencies. Do not mark a page
accepted from source inspection alone.

### Review focus across the whole app

| Risk | Expected behavior | Owned by |
|---|---|---|
| Account A logs out; B signs in; device goes offline. | No A data, draft, alert or cached protected response appears for B. | 2, 9, 12 |
| A request returns late, after origin/date/filter changes. | Old response cannot replace the current selection; retries do not duplicate a write. | 3, 4, 6 |
| Midnight, daylight-saving transition or suspended tab. | Dates, occurrence IDs, requested arrival and live-data age remain correct. | 3, 5, 6 |
| No photo, coordinates, verified fee, source or supported coverage. | Useful truthful content or a clear unavailable state; no fabricated claims/actions. | 6, 7, 8, 11 |
| Small screen, denied GPS, unavailable map or failed save. | Primary task remains possible, context survives, and recovery is direct. | 2, 3, 4, 10 |

## 4. Execution plan

### Task 0 — Establish one trustworthy baseline

**Inspect:** `AGENTS.md`, `CLAUDE.md`, design decisions, current diff, existing review
reports, current staging/main revisions, CI history and the places readiness work.

- [x] Inventory accepted prototype URLs, actual routes and known alternative experiments. Identify the current Today brief and Travel station/planner references explicitly.
- [ ] Record local revision plus relevant uncommitted changes; record the code/image actually running on staging and production. Do not call local modified code “the old staging app”.
- [ ] Map which previous changes are reusable, rejected, unfinished or unrelated. Preserve all unrelated work while preparing scoped implementation branches/checkpoints.
- [ ] Capture the actual baseline at desktop and phone widths using isolated test accounts. Run the existing relevant checks and record pre-existing failures separately.
- [ ] Read current places/event/media/authority eligibility and source freshness in the target environment. Record prepared, stored, publishable and retrievable counts separately.

**Exit:** a page inventory and discrepancy list tied to exact environments. No
page is marked ready merely because an old report passed.

### Task 1 — Fix the shared product and data contracts

**Files:** `app/Composer/WorkspaceStore.php`, `TodayPlanStore.php`,
`ComposerWorkspaceController.php`, `resources/js/components/composer/types.ts`,
`resources/js/types/ui.ts`, existing Travel request helpers and place/event resources.

- [x] Define a supported-request table for Composer: user request → required facts → real tool/API → result → user confirmation → persistence. Include journey, event discovery, manual appointment, practical guidance and service preparation.
- [ ] Trace one selected event/place/journey through details, Save, Composer, confirmed plan and Today. Record stable IDs, date/occurrence, origin, mode, revision and provenance at each boundary.
- [x] Choose the authoritative server state for plans, drafts and saved items; define local draft recovery and conflict handling. Distinguish Save idea, Add to plan, Set reminder and external booking.
- [x] Agree a release promise for capabilities that depend on missing providers: live vehicle positions, background tracking, exact boarding platforms and provider search. Unsupported capabilities must not be presented as working.
- [ ] Define supported launch geography/language from current content. Preserve manual location and explicit unsupported-city behavior.

**Acceptance:** each visible primary action has a real owner and an observable
outcome. User-visible drafts never become confirmed plans through navigation.
This contract review precedes Travel integration; it is not a Composer redesign.

### Task 2 — Stabilize the common shell and app behavior

**Files:** `resources/js/components/connected-app-header.tsx`,
`resources/js/layouts/app/app-sidebar-layout.tsx`, `resources/css/app.css`,
shared UI controls, `resources/views/app.blade.php`, `resources/sw.ts`.

- [x] Match the agreed compact navigation, single heading, active state, icons and control treatment. Check that fonts/spacing do not change when selecting a tab.
- [ ] Consolidate reused menus, sheets, search, date/time controls, loading and toast feedback without inventing new page-specific variants.
- [ ] Compare initial HTML/loading state with hydrated state on slow and warm reloads; fix header/Composer/button flashes and layout jumps.
- [x] Define safe cache handling for authenticated responses, account changes and live transit. Test logout/offline isolation before retaining personalized offline content.
- [x] Replace disruptive update behavior where necessary: an update must not discard an unsent message, pending edit or active trip. Restore safe state when reopening.

**Acceptance:** same shell on all five destinations; 44px touch controls; keyboard
and reduced-motion behavior; no lost work on update or refresh; no stale transit
shown as live. Existing `connected-navigation.spec.ts` and new cache/update
regressions verify those user outcomes.

### Task 3 — Travel: first complete page review

**References:** `departures-next/` for the side-by-side planner and journey preview;
`departures-next/station.html?view=timetable` for the station-wide board and map.

**Files:** `resources/js/pages/timetable.tsx`, `components/departures/`,
`components/journey/`, `hooks/use-active-trip.ts`, `hooks/use-trip-progress.ts`,
`components/trip-banner.tsx`,
`TimetableController.php`, `Api/TakeMeThereController.php`, `Api/TripController.php`,
`app/Transit/`, `app/Services/TimetableService.php` and
`app/Services/GtfsDepartureService.php`. Preserve current API ownership while
aligning the UI.

**3A — Plan a trip**

- [x] Match the accepted inputs, compact search results, travel modes, schedule/preferences and desktop side-by-side results. On phones, expose the useful route/action early.
- [x] Use actual selected location identities/coordinates. Editing a label must invalidate an incompatible prior selection; use manual origin when GPS is declined.
- [ ] Verify depart-at and arrive-by requests, future dates, overnight arrivals, multiple changes, walking/cycling and no-route results. A fallback provider must honor constraints or explicitly report it cannot.
- [ ] Fix the inspected TRIAS fallback's ignored time constraints and today-based clock parsing. Repeat time-sensitive journeys with each primary provider unavailable; measure complete cold-cache response time, including sequential fallback calls.
- [ ] Reject stale asynchronous results after origin/date/mode changes. Show transparent source/freshness and separate scheduled from real-time information.

**3B — Station timetable**

- [x] Port the accepted station-wide list with simultaneous departures, supported transport types, line chips and the newly approved compact Direction/Service menus.
- [x] Extend the data contract to individual service identity, exact departure time, source observation time and boarding-point/platform identity when supplied. Keep tied departures together and preserve selection through polling; never invent absent platform or cancellation data.
- [ ] Connect real stop search and feeds. Preserve the station and clock when filters change; unknown delay does not imply on time.
- [x] Propagate fresh, last-good, cached and scheduled provenance from provider through API/worker cache to the board. A historical `trias_rt` source tag alone must not produce a Live label.
- [ ] Test busy interchanges, opposite directions, several boarding points, one-line stops, no departures, stale responses and provider failure.
- [ ] Keep the chosen departure/service and unresolved boarding-point status through Map/List, route planning, Composer and Back to journey.

**3C — Maps and journey guidance**

- [x] Render provider route geometry when supplied; never promote the prototype's illustrative connecting lines into street navigation. If geometry is unavailable, keep usable steps and label the map limitation.
- [x] Distinguish station centre, candidate stop, verified entrance and assigned boarding platform. Show an exact boarding side only when the provider links it to that service.
- [x] Connect the existing active-trip session and Map/Steps views, preserving route, step, origin and destination through minimize/resume/navigation.
- [ ] Consolidate Travel's itinerary and the existing cross-app journey sheet around the same route identity and reusable provider-geometry map. Restore selected but unstarted previews as well as active trips after refresh and Back.
- [ ] Verify GPS accuracy and age before using it for progress. Test denied permission, stale fixes, wrong direction, signal loss, map failure, reconnection and ending the trip.
- [x] Clear or downgrade stale fixes after location errors and app suspension. Reject implausible progress jumps and reset progress when the intermediate itinerary changes, even if the endpoints stay the same.
- [ ] Treat device GPS, schedule-based progress and live vehicle tracking as separate capabilities. Validate each advertised capability on real supported devices; no moving mock marker counts as evidence.
- [ ] Verify scheduled exit reminders separately from device tracking: delivery while closed, cancellation after ending/switching a trip, and honest behavior after a delay. The inspected reminders use saved itinerary times; they are not evidence of continuous background tracking or live vehicle positions.
- [x] Revalidate alternatives before replacing a journey. A saved/repeated journey performs a fresh lookup; an expired itinerary must not start as current.

**Acceptance evidence:** real local/staging route and departure journeys, provider
failure cases, Map/Steps parity, list/map/return continuity and phone foreground/
background limitations. Use `departures.spec.ts`, `take-me-there.spec.ts`,
`trip-session.spec.ts`, `tests/Feature/Timetable/`, `tests/Feature/Transit/`,
`tests/Feature/Trip/`, and existing frontend request tests. Finish Travel before
moving its visual patterns to other pages.

### Task 4 — Composer, Saved, My plans and history

**Reference:** `composer-studio/` and the approved connected handoff decisions.
**Files:** `resources/js/pages/composer.tsx`, `components/composer/`,
`app/Composer/`, `ComposerController.php`, `ComposerWorkspaceController.php`,
saved-item/event/place endpoints and resources.

- [ ] Review supported requests using actual results. Either complete an advertised tool flow or offer a direct truthful recovery; unsupported conversation must not look successful.
- [ ] Keep existing messages/drafts when arriving from another page. Show selected item and relevant context compactly; dedicated details remain usable without an assistant detour.
- [ ] Make propose, edit, review, confirm, discard and Undo distinct. Test concurrent revisions, double clicks, retries, late responses and failed writes.
- [ ] Unify global Saved retrieval for the item types offered in departments. Set an explicit persistence contract: account-backed items survive another browser/device; local-only drafts are disclosed and recoverable.
- [ ] Preserve event occurrence/place/facility identities and date when saving/planning. Unsave removes the bookmark only; it must not delete an appointment or reminder.
- [ ] Reopen exact dated plans, distinguish past plans from reusable requests, and preserve plans when clearing conversation history according to the visible promise.
- [ ] Test a long session, mobile keyboard, map/detail return, empty Saved, removed records, interrupted generation and app resume.

**Acceptance:** a supported request can become a reviewed real plan, appears on
Today, survives refresh and has a clear edit/recovery path. Composer and every
department read the same plan. Tests: Composer workspace/entry/time-picker browser
tests, `tests/Feature/Composer/`, and frontend entry/identity regressions.

### Task 5 — Today

**Reference:** `today-next/brief/`, with full appointment/travel grouping and the
later content/cohesion decisions. **Files:** `resources/js/pages/dashboard.tsx`,
`components/today/`, `resources/css/today.css`, `HomeFeedController.php`.

- [ ] Review first visit, quiet day, busy day, approaching appointment and active trip. Establish a clear priority order based on the person's actual situation.
- [x] Preserve useful timeline detail without making all content a rigid schedule. Keep discovery and practical help balanced; avoid excessive paperwork or repeated Composer prompts.
- [x] Group related appointment/journey content. Use real saved work and upcoming plans to address desktop gaps; let empty sections disappear or provide one useful starting action.
- [ ] Add/edit/delete/Undo appointments through the agreed flow. Keep unsent drafts safe and explain how to resolve an existing pending draft without losing the attempted edit.
- [ ] Recompute day and urgency after midnight, tab suspension and trip changes. Editing an appointment invalidates incompatible travel and invites rechecking.

**Acceptance:** a fresh user understands the next useful action; a returning user
sees accurate personal priorities and can continue the exact unfinished task.
First meaningful mobile content remains visible. Tests: `dashboard.spec.ts`,
`tests/Frontend/today-model.test.mjs`, dashboard/HomeFeed and workspace tests.

### Task 6 — Events and contextual place/event details

**Reference:** `events-next/` plus approved shared item details.
**Files:** `resources/js/pages/events.tsx`, `components/events/`, relevant
`components/places/`, `EventController.php`, `Api/EventsController.php`,
`Api/EventReminderController.php`, event occurrence/publication/media services.

- [x] Review date-led discovery, then reconcile exact-date/calendar support with current relative windows. Keep date, category, area, origin/mode and sort as separate compact controls.
- [ ] Ensure event-first presentation, useful density and no-photo layouts. Use real eligible upcoming occurrences; empty ranges need recovery, not sample events.
- [ ] Preserve exact occurrence, venue, fixed start/end and price uncertainty through detail, Save, reminder, planning and Travel. Handle cancelled/expired listings honestly.
- [x] Make specific event links and Saved links open the intended content; Back restores discovery filters/scroll. Repair redirects that currently drop the selection.
- [ ] Verify external booking versus internal saving/planning labels, reminder delivery and error recovery. Never imply a booking or confirmed attendance from a bookmark.
- [ ] Check missing coordinates, unverified hours/cost, duplicate venues, removed items and late search responses. Route to a verified point where available, without inventing an entrance.

**Acceptance:** discover a particular day's event → inspect → save/plan → obtain
directions → return with all context intact. Tests: `events-connected.spec.ts`,
item-detail tests, `tests/Feature/Events/`, reminder and workspace event-selection
tests. Place/media contracts must agree with Task 11.

### Task 7 — Paperwork

**Reference:** `bureaucracy-next/` and the verified case-plan interaction.
**Files:** `resources/js/pages/bureaucracy.tsx`, `components/bureaucracy/`,
`BureaucracyController.php`, case task/question/conflict/document controllers,
existing authority/source policies and reviewed catalogue import.

- [x] Make the page answer “What applies to me, what is my next step, and what is missing?” Keep orientation optional and progress calm.
- [ ] Review overview, situation editing, task/document progress, history, empty coverage and conflicts. Skipped answers stay unanswered; no assumed personal status.
- [ ] Trace every content lane to its authority status. Unreviewed general catalogue entries must not appear equivalent to authoritative personalized guidance.
- [x] Preserve source, verification date, jurisdiction and validity checks. Do not generate missing legal content to fill a card or enable unavailable family/person access from prototype fixtures.
- [ ] Test progress persistence, failed writes, conflicting facts, changed circumstances and outdated-content reports. Confirm sensitive information is not leaked through logs or shared caches.

**Acceptance:** each published next step has the required evidence and applies
within supported coverage; unknown/unreviewed cases remain clear and actionable.
Tests: bureaucracy browser/feature suites, authority/source policy tests and
case-state regressions. Content review is a release requirement independent of UI.

### Task 8 — Services

**Reference:** `services-next/` and the compact request/appointment flow.
**Files:** `resources/js/pages/services.tsx`, `components/services/`,
`ServicesController.php`, existing place/service request integration.

- [x] Present the current capability honestly: category-specific preparation and adding an already-arranged appointment.
- [ ] Review whether that is useful enough for the launch tab. If provider discovery is required, qualify real providers, source freshness, coverage and available actions as a separate deliverable before advertising search or booking.
- [x] Keep preferences optional and specific to the selected category. Preserve each request while moving between categories.
- [x] Send preparation context to Composer without overwriting or sending the user's draft. Add an existing appointment only with real user-supplied date/time/location and explicit plan confirmation.
- [x] Remove unsupported availability/language/distance claims and dead primary actions. Check narrow screens and failure recovery.

**Acceptance:** the page delivers the task its main action promises. It never
suggests fictional providers or appointments. Tests: utility-page browser tests,
service request logic and `tests/Feature/Services/` as appropriate to final scope.

### Task 9 — Authentication and onboarding

**Files:** `resources/js/pages/auth/`, `pages/onboarding.tsx`,
`OnboardingController.php`, Fortify/Socialite configuration, local review controller
and authentication middleware.

- [ ] Review register, verify email, sign-in/out, forgotten/reset password,
  password confirmation and configured two-factor/OAuth flows end to end.
- [x] Keep onboarding focused and skippable where allowed. Preserve intended
  destinations and review Today as the normal completion destination; Paperwork
  should follow explicit context rather than every signup.
- [ ] Test expired/used links, duplicate accounts, wrong credentials, disabled
  provider, throttling, session expiry and verification-mail resend.
- [ ] Verify actual staging email/callback delivery, without using the local
  review shortcut as evidence of production authentication readiness.
- [ ] Prove account A → logout → account B isolation online and offline, including
  plans, messages, Saved, location, push registration and caches.
- [x] Verify local review/QA access fails closed outside its intended local/admin
  boundary. Keep authentication active on public app routes.

**Acceptance:** a new user reaches useful content without supervision and can
recover access; an expired session never silently loses a pending edit. Use auth,
onboarding, session-cookie, local-review and OAuth tests; run the separately
configured onboarding browser checks explicitly.

### Task 10 — Profile, settings, alerts and notifications

**Files:** `resources/js/pages/profile.tsx`, `pages/settings/`, `pages/alerts.tsx`,
user-place/settings/notification controllers, push hooks and `resources/sw.ts`.

- [ ] Review profile facts, interests and home/work/custom locations. Manual
  location works without browser permission; changed preferences reach Travel
  and Composer consistently.
- [ ] Review account email/password/security, notification preferences, transit
  settings, appearance and deletion. Dark/system mode uses root theme tokens and
  does not flash or make approved screens unreadable.
- [x] Make save/read/dismiss success reflect the server result. Test offline,
  expired session, validation and server failure: rollback or retain pending
  state honestly, with retry. Reload must agree with the success message.
- [x] Request notification/location permissions at the relevant action. Honor
  denied/unsupported state and location opt-out in every consumer.
- [ ] Verify real reminder/push delivery, unsubscribe, duplicate prevention,
  unread badge updates and deep links to the intended event/task/trip.
- [ ] Exercise account deletion only on a designated disposable test account;
  verify session, personal-data and device-registration outcomes.

**Acceptance:** controls do what their labels promise across refresh and another
session; alerts are relevant, readable and actionable. Tests: settings/profile/
notification feature tests, `alerts.spec.ts`, and new failure/isolation cases.

### Task 11 — Shared catalogue, public pages and content readiness

**Files:** place/event resources and selectors, `app/Places/`, `app/Composer/`,
reviewed seed data, `resources/views/marketing/`, marketing controllers/routes,
current places readiness reports and source/rights policies.

- [x] Reconcile the places readiness branch selectively with newer Composer/UI
  work. Preserve aliases, canonical/facility identity and held-record exclusions;
  validate backend serialization rather than trusting an older frontend type.
- [ ] Verify current eligibility, source provenance and freshness on staging and
  production separately. Never promote private local export IDs or count all
  stored rows as available recommendations.
- [ ] Test common supported requests across central and outer neighbourhoods:
  suitable event, named place, activity facility, missing photo, unknown fee/hours,
  explicit free-only requirement and unavailable match. Do not loosen a hard
  constraint to hide poor coverage.
- [ ] Verify approved media health, attribution and licence links. A missing
  image leaves a good text layout; unsupported rights remain blocked.
- [ ] Review landing page, login links, public blog/tools, waitlist, legal/contact
  and support paths for broken links and claims that exceed shipped capability.
  Legal/source-sensitive copy requires the appropriate owner/content review.
- [ ] Check old `/explore`, event and settings bookmarks for a deliberate working
  destination. Remove obsolete navigation promises without destroying valid
  saved references or catalogue identities.

**Acceptance:** published content is useful and grounded in the running release;
people and Composer see consistent facts. Public-facing promises match the app.
Follow the existing places production-acceptance process for data promotion;
that process is separate from deploying frontend code.

### Task 12 — Whole-app end-user rehearsal

Run these on the actual staging candidate after page-level work:

| Journey | Required result |
|---|---|
| New user → signup/verification → minimal onboarding → Today | Useful start without fake records, forced paperwork or a permission loop. |
| Find an event → detail → Save → Composer → confirm → Today | Same occurrence/date/venue, distinct save/plan states and persistent result. |
| Place/event → Travel → compare route → stop timetable → Back | Original journey, selected context and view restored; no unrelated preview opens. |
| Start journey → Map/Steps → another page → resume → finish | Route/progress retained and source/position limitations clear. |
| Edit appointment with existing draft and another open tab | Clear conflict/recovery, no lost draft or duplicate activity. |
| Paperwork → source/next step → update progress → return | Authority boundary respected and real state persisted. |
| Services → prepare request or add known appointment | Useful outcome without implying provider discovery or booking. |
| Saved past journey → repeat today | Fresh routing; no historical countdown presented as current. |
| Notification → exact item → read/dismiss → reload | Correct destination, badge and persistence. |
| Offline/failed provider/denied GPS → recover | Honest status, preserved context and a usable next action. |
| App update while drafting/travelling; logout/account switch | No lost work and no previous-account content. |

- [ ] Run cold and warm loads, repeated refresh, Back/Forward, tab suspension and
  reconnection on desktop Chrome and real iOS Safari/Android Chrome. Include the
  installed PWA mode if offered to users.
- [ ] Add a small observed first-use session with people unfamiliar with the
  project. Ask them to complete tasks without coaching; record where they stop,
  misinterpret status or cannot recover. Agent review complements this evidence.
- [ ] Log and fix release-blocking findings, then rerun affected flows. Measure
  completion and perceived responsiveness; automated green status alone does not
  establish usability.

### Task 13 — Staging qualification and controlled launch

**Files:** `.github/workflows/ci.yml`, `playwright*.config.ts`, existing deployment/
container configuration, `resources/sw.ts`, Sentry setup and operational runbooks.

- [ ] Record the exact candidate revision, build and data dependencies. Run
  TypeScript checks, lint/format, production build and the relevant PHP/frontend
  suites. Do not bypass commit hooks.
- [x] Add a dependable mobile browser gate and TypeScript gate to CI. Include
  onboarding explicitly and assess release-critical tests excluded from the fast
  suite; run them in release qualification where appropriate.
- [ ] Measure key screen load/interaction and route/search latency on stated
  devices/network conditions. Compare before/after and investigate layout shifts,
  main-thread stalls, repeated requests and excessive map/Composer payloads.
- [ ] Verify staging's running image, migrations, catalogues, queues, scheduler,
  email, push, provider freshness and external links. Use synthetic accounts for
  writes and real provider checks where needed; record test time and source.
- [ ] Verify actual error reporting and alert delivery with release/environment
  tagging and sensitive-data scrubbing. Configuration files alone do not pass.
- [ ] Rehearse backup restoration and candidate rollback, including migration and
  catalogue compatibility. Record the previous known-good image and recovery
  procedure; never assume an image rollback also undoes data changes safely.
- [ ] Produce the final release report: page statuses, screenshots, supported
  capabilities, remaining limitations, test evidence and rollback criteria.
- [ ] Obtain release authorization for that concrete candidate, then deploy
  through staging/main as appropriate. Verify the workflow before investigating
  infrastructure; a push alone is not a completed release.
- [ ] After release, verify the running revision and smoke-test access, Today,
  Events, Travel, Composer and persistence on desktop/mobile. Check source
  freshness, queues and errors. Roll back for broken access, data loss/exposure
  or widespread core-flow failure according to the rehearsed procedure.

**Release gate:** all advertised core journeys work with real data or an explicit
supported fallback; no open launch blocker or required-before-release finding;
page designs accepted; relevant tests green; account/cache isolation proven;
source/rights gates intact; operations and rollback verified.

## 5. Tracking and sequencing

| Order | Deliverable | Dependency | Current status (5 October) |
|---|---|---|---|
| 0 | Environment/design/data baseline | None | Local baseline recorded; deployed image/data unverified |
| 1 | Shared supported-action and persistence contracts | 0 | Implemented locally; capability/persistence contract documented |
| 2 | Stable shell, controls and update/cache behavior | 0–1 | Implemented locally; desktop/mobile reliability checks |
| 3 | Travel planner, timetable, maps and guidance | 1–2, provider/data checks | Implemented locally; real local feed/map observed; device/provider qualification outstanding |
| 4 | Composer, Saved, plans and history | 1–2; Travel handoff checked jointly | Implemented locally; supported core flows checked; catalogue recommendation rehearsal outstanding |
| 5 | Today | 2–4 | Implemented locally; day/resume/conflict checks |
| 6 | Events and shared details | 2, 4; catalogue readiness in parallel | Implemented locally; exact occurrence/Saved/recovery checks; target catalogue outstanding |
| 7 | Paperwork | 1–2; authority/content readiness | Implemented locally; authority and legacy publication gates checked; human content review outstanding |
| 8 | Services | 1–2, 4; explicit provider scope | Implemented locally as request preparation / known appointments; no directory or booking |
| 9 | Access and onboarding | 2; fixes can start earlier | Implemented locally; external email/OAuth verification outstanding |
| 10 | Profile, settings, alerts and notifications | 2, 9; shared persistence | Implemented locally; acknowledged writes and privacy checked; physical push delivery outstanding |
| 11 | Catalogue/public-content qualification | Starts with 0; gates dependent pages | Local catalogue contracts/data integrated and native consumers checked; current staging aggregate audit complete; target promotion, fresh sources and privacy/source review remain |
| 12 | Whole-app end-user rehearsal | All launch-scoped pages | Local desktop/mobile rehearsals recorded; physical-device and unfamiliar-user sessions outstanding |
| 13 | Release qualification and launch | 11–12 and authorization | Local checks/CI gates added; staging, rollback and deployment not performed |

Travel is the first page presented for detailed review. Shared contracts,
account/cache risk investigation and data qualification run alongside it because
they can block every page. Subsequent page implementation is sequenced to avoid
duplicating state and reworking completed designs.

Do not assign a launch date from prototype completeness. At the end of the
baseline, estimate each scoped implementation from its actual gaps and provider
dependencies, then publish a range and critical path. Review progress by completed
user journeys and page gates rather than percentage of screens styled.

**Implementation handoff:** `docs/reviews/2026-10-05-launch/README.md` contains page outcomes, evidence and the remaining release gates. The local app is on port 8011; port 8765 remains the prototype reference. No candidate has been pushed or deployed.

## 6. Plan self-review

- Covered every primary destination and its detail/continuation flows, plus Saved,
  history, authentication, onboarding, profile, all settings, alerts and public
  entry points. Legacy and development routes have explicit boundary checks.
- Mapped all five cross-app review risks to page tasks and acceptance scenarios.
- Distinguished frozen prototypes, current local source, historical staging
  reports and still-unverified deployed behavior.
- Kept provider discovery, AI conversation, live vehicle tracking and exact
  boarding locations as explicit capability decisions, not implied completion.
- Preserved the agreed designs and authority/media rules; no code or data
  implementation was performed merely to write this plan.

## 7. Completion interpretation

The owner authorized implementation after planning. The local execution reports supersede the initial findings where a fix is now verified. Unchecked multi-part tasks are deliberately retained when they include physical devices, external delivery, catalogue promotion, human review or deployment. They are release dependencies, not a claim that the local app is already launched. See the handoff for the precise remaining action and evidence required.
