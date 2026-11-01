# Launch review — account, reliability and utility pages

Date: 5 October 2026. Environment: local actual Laravel/Inertia app at
`http://127.0.0.1:8011`, synthetic E2E account. Base revision `c386427d` plus
the shared working tree. This is local implementation evidence, not a staging
or production acceptance record. No deployment, account migration or live push
delivery was performed by this review.

## Scope and decisions

Preserve the accepted app colours and navigation. Keep utility pages focused;
Composer remains available as optional help. Services currently offers request
preparation, not a connected provider directory. No legal guidance, provider
availability, image permissions or fees were invented to fill an empty state.

### Account privacy and app updates

- The service worker no longer runtime-caches authenticated pages, Inertia
  responses or APIs. Public hashed assets, fonts and external map tiles retain
  their bounded caches. Activation removes old `api`, `inertia-pages` and legacy
  precache entries before claiming clients.
- Worker activation no longer reloads an open document. An unsaved form remains
  intact while a worker takes control. A new page load still receives the current
  asset manifest; this does not promise complete offline operation.
- Inertia history encryption is enabled. Logout/account deletion rotate history
  keys and remove private session drafts, legacy device-only saved events and
  old private response caches. Account deletion returns to the app's login page,
  avoiding a non-Inertia marketing response to its form submission.
- Push status is verified for the authenticated account, not inferred from a
  browser token that might belong to a previous account. A failed status request
  displays an unknown state and retry. Unsubscribe requires a successful server
  acknowledgement before presenting delivery as disabled. Push click URLs are
  restricted to this app's origin. Logout/account deletion also attempt to
  unsubscribe this device's native browser token; failure cannot prevent logout.

### Saved settings, alerts and profile

- Transit/ticket and notification preferences update after a successful JSON
  acknowledgement. Network errors, rejected responses and expired sessions show
  a persistent error; the original choice remains available for retry. Duplicate
  writes are disabled while saving.
- Alert read/dismiss/read-all actions also wait for persistence. Dismiss is a
  visible, labelled touch control; it no longer depends on hover. Shared unread
  counts refresh after success.
- Profile edits retain their draft when a request fails. Edit rows are keyboard
  buttons with labelled controls; pending writes cannot be repeated. Place forms
  close only after a successful save and retain visible validation errors.
- Settings switches have 44px targets. The settings navigation scrolls
  horizontally on small screens. Profile and Alerts use the focused utility
  layout without an unrelated widget rail; Profile has a semantic page heading.

### Onboarding

- Successful onboarding returns to Today or a validated, same-origin intended
  app destination. Auth pages, external destinations and protocol-relative
  external destinations are rejected as completion targets.
- Draft keys include the account ID and are cleared on completion/logout.
- Draft restoration now happens after the matching server/client initial render.
  A saved step-3 draft previously raised React hydration error #418 after reload;
  the regression now resumes the chosen neighbourhood and continues on desktop
  and mobile without replacing mismatched markup or overwriting the draft.
- “Skip for now” submits the explicitly cleared optional answers, avoiding a
  React state timing bug that could submit the answers the person just skipped.
- The final button says “Finish setup”, consistent with returning to the app
  rather than promising a completed plan. The existing four-screen structure is
  preserved; the browser test was updated from the obsolete five-screen flow.

### Paperwork and Services

- Paperwork keeps its dedicated checklist/document controls and one heading.
  Optional orientation is small and dismissible. Authority/source policies and
  all legal content are unchanged.
- Services preserves per-category preferences and clearly says provider search
  is not connected. A reviewable provider-facing message can be copied directly;
  opening an unsent Composer request is secondary. The appointment tip is
  dismissible. Copying or opening the draft never sends a message or books a
  service.

### Media publication

The review reproduced a rights bypass: `EventOccurrencePresenter` fell back to
an unmanaged legacy `Spot.photo_url`. The same direct field appeared in Today's
`DiscoveryFeed`; featured-event ranking also preferred those unreviewed photos.
All three now use `PublishedMediaSelector`. Only approved, active assets are
eligible. Current approval is re-read after a cached catalogue scan so revoked
rights do not remain publishable until the catalogue cache expires.

### Startup and legacy public claims

- The client previously always called `createRoot`, replacing any server-rendered
  DOM. With the local SSR renderer running, a delayed-JavaScript regression
  confirmed that an already-visible input was detached and its draft lost.
  Populated roots now use `hydrateRoot`; empty roots still use `createRoot` when
  SSR is unavailable. The same form node, focus and early draft survive startup
  on desktop and mobile. Seven core pages attached without hydration errors.
- CI now builds and starts the SSR renderer before browser checks; an absent
  renderer fails readiness rather than silently exercising only client rendering.
- The public citizenship and permanent-residency calculators exposed legacy
  hardcoded rules without the required review/publication evidence. Their routes
  now show a friendly, non-indexable review-pending state without calculator
  data or forms. Landing/hub recommendations are inactive and the sitemap omits
  these pages. Source views, calculation code and legacy rules remain available
  for human review. Salary and fare tools already have dated source
  configuration; this audit did not re-verify their figures or disable them.
- The same legacy permanent-residency estimate reached Paperwork and scheduled
  notices. Its publication is now suppressed, including old cached Today
  actions, stored Alerts/unread counts and queued notifications. User records
  are preserved. Reviewed case plans and `RuleSourcePolicy` remain unchanged.
- The privacy page incorrectly promised no third-party routing. The description
  now states the observed MOTIS/Transitous/configured TRIAS request flow, journey
  coordinates/search text/options, and direct browser requests to OpenFreeMap
  (IP address and requested map areas). This is factual implementation alignment,
  not legal approval of the privacy notice or its processor arrangements.
- Independent SavedJourney review found explicit account scoping on list,
  upsert and deletion; authentication/verification and write throttling; strict
  nested-field validation; a per-account unique key; and cascade deletion. No
  account-access bypass was found. Travel's tests cover ownership and fresh
  lookup rather than persisting stale itineraries.

### Location retention and account cleanup

- Passive `location_ping` and `spot_proximity` analytics retain event counts and
  timing, but no payload: neither coordinates nor place names/identifiers remain
  in new database records. Immediate neighbourhood/proximity handling continues;
  proximity deduplication uses a short-lived Redis key instead of durable place
  identities.
- Redis history now removes entries at or beyond seven days and expires the
  entire key at its oldest remaining entry's seven-day deadline. New writes do
  not extend older positions. This also bounds retention if the scheduled sweep
  stops; some newer entries can expire early in that case.
- Turning location sharing off and deleting an account purge that account's
  history, confirmed position, neighbourhood anchor, proximity keys and legacy
  passive event payloads. Other accounts remain intact. Pings, opt-out and
  deletion use the same account lock, preventing an in-flight ping from
  repopulating data after a purge. Logout still happens before model deletion
  so the session's remember-token update cannot recreate the user.
- `location:prune-history --dry-run` reports counts without changing records.
  `location:prune-history` scans existing keys, including keys without a matching
  user, removes expired members, installs the oldest-entry deadline, and nulls
  all existing passive location payloads regardless of age while retaining the
  analytics rows. It runs hourly as ongoing maintenance. **No cleanup was run on
  real application data during this review.** A rollout must inspect the dry
  run and execute the cleanup once before accepting the seven-day claim for
  historical data. The initial cleanup is required; new-history expiry does not
  depend on the scheduler. Monitor command failures and scheduler health.

## Verification evidence

Local PHP 8.4.23 and Node 22.23.1 were checked before project commands. PHP test
runs used the existing local test database with an exclusive agent test slot.

| Check | Observed result |
|---|---|
| Auth, onboarding, settings feature tests | 117 passed, 474 assertions |
| Push ownership and settings JSON acknowledgement | 13 passed, 46 assertions |
| Media presenter, Home discovery/featured events, Events API | 44 passed, 161 assertions; unmanaged-photo regression failed before the fix |
| Final auth-history, account-deletion and warm-cache media revocation pass | 31 passed, 109 assertions |
| Public tool/landing publication gates | 18 passed, 100 assertions; five new checks failed before the fix |
| Legacy eligibility suppression, reviewed case matcher and onboarding journey regressions | 42 passed, 246 assertions; eight publication checks failed before the fix |
| Seven-day retention, opt-out, location context, profile deletion and user settings | 39 passed, 133 assertions; payload, expiry and account purge checks failed before the fix |
| Reliability + utility browser tests, desktop and Pixel 7 | 20 passed; failed-save retry, 419 response, 320px layout/44px controls, retained profile draft, push unknown/retry, update without reload, logout/back privacy, Services category context, Paperwork tabs |
| Final utility browser pass, desktop and Pixel 7 | 8 passed, including direct provider-message copy and dismissible help |
| Dedicated current onboarding flow, desktop and Pixel 7 | 4 passed; saved-draft SSR recovery, optional-answer skip payload, shared icons and no horizontal overflow |
| Private storage cleanup, reliable writes and worker privacy Node tests | 4 passed |
| Service request builders | 3 passed, including a provider-facing copy message with no Composer instruction |
| Utility screenshot checks, desktop and Pixel 7 | 2 passed; eight page captures checked for one heading and no horizontal overflow |
| SSR bootstrap, fallback, core-page hydration and public tool UI | 8 passed, desktop and Pixel 7; actual SSR replacement reproduced before the fix |
| Final combined-build logout recheck | 2 passed, desktop and Pixel 7, run by the Travel agent |
| Application TypeScript check | Passed |
| Worker TypeScript check | Passed independently; `resources/sw.ts` is outside the app's default TypeScript include |
| Targeted lint/format | Owned React files, worker formatting, PHP files and whitespace checks passed; final shared build is recorded in the parent review |

An initial worker browser assertion counted Inertia's encrypted-history
`replaceState` as a reload. The test now counts actual document requests and
checks the unsaved value. A separate logout test explicitly starts with empty
storage so it cannot invalidate the session used by other test projects.

### Visual review

Screenshots are from the actual local app with built assets, at 1440px desktop
and 390px phone widths. All eight were inspected. Settings and Profile have
focused utility layouts and visible touch controls. Services makes the direct
copy action primary and keeps request preparation separate from unavailable
provider search. Paperwork honestly shows the synthetic account's partial
coverage; this is not evidence of a complete production catalogue.

| Page | Desktop | Phone |
|---|---|---|
| Transit settings | [Capture](screenshots/settings-desktop.png) | [Capture](screenshots/settings-mobile.png) |
| Profile | [Capture](screenshots/profile-desktop.png) | [Capture](screenshots/profile-mobile.png) |
| Paperwork | [Capture](screenshots/paperwork-desktop.png) | [Capture](screenshots/paperwork-mobile.png) |
| Services | [Capture](screenshots/services-desktop.png) | [Capture](screenshots/services-mobile.png) |

The local-only QA persona launcher is visible in these captures. Its absence in
production remains a configuration acceptance check. The partial-coverage
Paperwork state still has several separate explanatory blocks on mobile; a
follow-up should consolidate repeated coverage messaging without hiding missing
authority or inventing guidance. This review did not rewrite legal content or
weaken its publication gates.

## Release checks and limits

The workflow now adds pure frontend behavior tests, TypeScript after generated
routes, an explicit worker TypeScript check, the mobile browser project and the
dedicated onboarding flow. Deploy jobs continue to depend on the image build,
application tests and browser tests; the image build depends on lint.

The following require evidence in the target environment before release:

1. Run the complete workflow for the final candidate and record the built/running
   image revision. These focused local results do not establish a green remote
   workflow or validate unrelated pre-existing changes in the dirty tree.
2. Verify fresh account registration, verification email, password reset and
   enabled OAuth providers with actual configured delivery/callbacks. Local
   mocked notification tests prove application handling, not mail delivery.
3. Verify web push on supported real devices, including permission denial,
   actual delivery, unsubscribe, signing into a different account and iOS
   installation constraints. Tests exercised local token ownership/status and
   failure handling, not provider delivery.
4. Repeat account A/logout/account B/offline and upgrade checks on installed
   Chrome/Safari apps with the previous deployed worker. The local tests prove
   current cache matching, cleanup and history behavior; they do not simulate
   every historical browser cache version or mobile OS lifecycle.
5. Confirm `APP_DEBUG=false`, production auth/verification enforcement, disabled
   local-review/persona entry points, HTTPS/secure cookies and production log
   handling in deployed configuration. No authentication bypass was introduced.
6. After an authorized deployment, inspect application/queue/scheduler health,
   error monitoring and the affected journeys. Existing deployment scripts
   restart containers, migrate and import the authority catalogue but do not
   themselves establish an end-user smoke check or rehearsed rollback. Record
   the previous image and migration/backup recovery procedure before promotion.
7. Verify media approval/health and bureaucracy authority/coverage against the
   deployed dataset. Local synthetic records are not evidence of catalogue
   completeness, source freshness or permissions for staging assets.
8. Run `php artisan location:prune-history --dry-run` against the rollout
   environment, review its counts, then execute the command without `--dry-run`
   as the privacy migration. Confirm a second dry run has zero expired positions
   and passive payloads and check the hourly scheduler. The local synthetic
   regression does not establish cleanup of existing staging/production data.

`docs/sentry-alert-rules.md` is configuration guidance, not evidence that alert
rules are installed and delivering. Services provider discovery remains an
explicitly unavailable capability until its separate data/verification work is
accepted.

## Main files and routes

- Privacy/update: `resources/sw.ts`, `resources/views/app.blade.php`,
  `config/inertia.php`, `resources/js/lib/private-browser-data.ts`, logout and
  deletion handlers.
- Reliability: `resources/js/lib/reliable-write.ts`, `/settings/transit`,
  `/settings/notifications`, `/alerts`, `/profile`; write endpoints
  `/user-settings`, `/notification-preferences`, `/settings/profile`,
  `/alerts/{alert}/read`, `/alerts/{alert}/dismiss`, `/alerts/read-all`.
- Push: authenticated `/push/status`, `/push/subscribe`, `/push/unsubscribe`.
- Onboarding: `/onboarding`, `/onboarding/complete`, `/onboarding/restart`.
- Utilities: `/bureaucracy` and `/services`.
- Media: `app/Support/EventOccurrencePresenter.php`,
  `app/Home/DiscoveryFeed.php`, `app/Home/FeaturedEvent.php`.
- Tests: `tests/Browser/reliability.spec.ts`,
  `tests/Browser/utility-pages-connected.spec.ts`,
  `tests/Browser/onboarding-icons.spec.ts`, `tests/Frontend/`,
  `tests/Feature/Media/PublishedSurfaceMediaTest.php` and affected feature suites.

Reference for encrypted history and clearing it:
[Inertia v2 history encryption](https://inertiajs.com/docs/v2/security/history-encryption).

Startup/queue references checked through Context7:
[Inertia v2 SSR hydration](https://inertiajs.com/docs/v2/advanced/server-side-rendering),
[React hydrateRoot](https://react.dev/reference/react-dom/client/hydrateRoot),
[React client-only effect after hydration](https://react.dev/reference/react/useEffect#displaying-different-content-on-the-server-and-the-client),
[Laravel 13 queued notification shouldSend](https://laravel.com/docs/13.x/notifications#determining-if-a-queued-notification-should-be-sent),
[Laravel atomic locks](https://laravel.com/docs/13.x/cache#atomic-locks).
