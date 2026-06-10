# Backend Ownership — Territory 1: Spine & Config

> The "spine" is the wiring that ties every other backend territory together: how a
> request boots, what middleware runs, which services/providers register, what fires
> on a timer, and the configuration that switches behaviour. Read this first — it is
> the index to everything else.

---

## 1. Request lifecycle — `bootstrap/app.php`

The whole app is configured in one file (Laravel 11+ style, no HTTP Kernel class).

- **Routing:** web routes from `routes/web.php`, console routes from `routes/console.php`, health check at **`GET /up`**.
- **Trusted proxies:** `at: '*'` with the full `X-Forwarded-*` header set (incl. AWS ELB). Correct for running behind nginx / a load balancer — request scheme/host/IP come from the proxy.
- **Cookie encryption:** everything encrypted **except** `appearance` and `sidebar_state` (read client-side as plain values).
- **Global `web` middleware (append order matters):**
  1. `PerfTrackMiddleware` — stamps `perf_start`, records duration in `terminate()`.
  2. `HandleAppearance` — dark/light theme.
  3. `HandleInertiaRequests` — injects shared props (see §6).
  4. `AddLinkHeadersForPreloadedAssets` — asset preload hints.
  5. `EnsureUserIsOnboarded` — redirects un-onboarded users to `/onboarding`.
  6. `SetCacheHeaders` — forces `no-store` on GET responses.
- **Exceptions:** `withExceptions()` is **empty** — there is no custom reporting/rendering here. Error reporting comes from `config/sentry.php` (Sentry SDK auto-registers). _Ownership note: if you want custom error payloads or to suppress noisy exceptions, this is the place and it's currently untouched._

### Middleware details worth owning

| Middleware | What it does | Gotcha to own |
|---|---|---|
| `EnsureUserIsOnboarded` | If logged in & `!isOnboarded()` & path not excluded → redirect to `onboarding`. Excludes `onboarding*`, `logout`, `settings/*`, `email/*`, `two-factor-challenge`, `confirm-password`, `verified-email`. | Exclusion is path-pattern based (`$request->is()`). **API routes (`api/*`) are NOT excluded** — an un-onboarded user hitting an API endpoint gets a 302 redirect to onboarding, not JSON. Fine today (all app routes need onboarding) but a trap if you add public-ish APIs. |
| `PerfTrackMiddleware` | Records `route:{name}` timing to `PerfLogger` in `terminate()` (after response sent, no latency cost). Tags `ok`, `status`, `method`, `inertia`. | This is the telemetry hook the memory note "instrument render + external API timings" refers to. Backend half exists; external-API timing is not captured here. |
| `SetCacheHeaders` | All `GET` except `build/*` get `Cache-Control: no-store`, `Pragma: no-cache`, `Expires: 0`. | **Aggressive.** No GET response is ever cacheable by the browser or a CDN. Safe (avoids stale auth'd pages) but means you cannot lean on HTTP caching for any public/marketing page later without revisiting this. |

---

## 2. Service providers — `bootstrap/providers.php`

Only three app providers register (Laravel auto-discovers package providers separately):

1. **`AppServiceProvider`** — domain wiring (§3).
2. **`Filament\AdminPanelProvider`** — the admin panel spine (separate territory).
3. **`FortifyServiceProvider`** — auth wiring (§5).

---

## 3. `AppServiceProvider` — the nerve center

**`register()`** binds one singleton:
- `EmbeddingService` — constructed from `config('services.embedding')` (`url`, `timeout`, `dim=384`). Singleton because it's a stateless HTTP client to the sidecar.

**`boot()`** does the real wiring:

- **Notification → Alert bridge:** `NotificationSent` → `CreateAlertFromNotification` (every sent notification is mirrored into the `alerts` table for the in-app feed/history).
- **Model observers:**
  - `UserPlace` → `UserPlaceObserver` (place edits trigger route recompute).
  - `Spot`, `Event`, `CityNews`, `Service` → `EmbeddableObserver` (save → refresh pgvector embedding).
- **Context Engine event map (the moat's entry points):**

  | Event | Evaluator |
  |---|---|
  | `TransitDisruptionDetected` | `TransitDisruptionEvaluator` |
  | `TransitDelayDetected` | `TransitDelayEvaluator` |
  | `WeatherChanged` | `WeatherEvaluator` |
  | `BuergeramtSlotsAvailable` | `BuergeramtEvaluator` |
  | `RhineLevelChanged` | `RhineEvaluator` |
  | `MarketClosureDetected` | `MarketEvaluator` |
  | `UserContextChanged` | `LeaveByEvaluator` |
  | `ScoredActionInserted` | `ScoredActionPushDispatcher` |

  _This table is the seam between Territory 1 (spine) and Territory 2 (Context Engine). The `Check*` console commands fire the left column; the evaluators on the right consume them._

- **Parallel testing:** creates the `postgis` extension in each parallel test DB (so Pest `--parallel` works with PostGIS).

**`configureDefaults()`** — production-readiness defaults:
- `Date::use(CarbonImmutable::class)` — **all dates are immutable**. Important when reading date code elsewhere: `$date->addDay()` returns a new instance, it does not mutate.
- `DB::prohibitDestructiveCommands()` in production — blocks `migrate:fresh`/`db:wipe` against prod.
- Password rule default: `min(8)`.
- In production: force HTTPS scheme + force root URL from `config('app.url')`.

---

## 4. The heartbeat — `routes/console.php`

18 scheduled commands, all `withoutOverlapping()`. This is your **operational risk map**: every external dependency is invoked here on a timer.

| Cadence | Command | Feeds |
|---|---|---|
| every 5 min | `news:scrape` | CityNews / disruptions |
| every 5 min | `buergeramt:check` | Bürgeramt slot events |
| every 5 min | `commute:send-leaveby-reminders` | Leave-by push |
| every 5 min | `api:health` | External API monitoring |
| every 10 min | `transit:check-disruptions` | `TransitDisruptionDetected` |
| every 15 min | `events:enrich` | Event tagging/quality |
| every 15 min | `transit:check-delays` | `TransitDelayDetected` (>10 min only) |
| every 30 min | `events:scrape` | Event import |
| every 30 min | `controls:synthetic-disruption` | Pipeline canary |
| hourly | `rhine:check` | `RhineLevelChanged` (in-app only) |
| hourly | `notification:health-check` | Delivery monitoring |
| every 2 h | `weather:check-alerts` | `WeatherChanged` |
| daily 18:00 | `events:send-reminders` | Event reminders |
| daily 04:00 | `restaurants:scrape` | Spot data |
| daily 04:00 | `controls:daily-audit` | Pipeline parity audit |
| daily 03:30 | `users:rebuild-preference-vectors` | Personalisation vectors |
| daily 09:00 (Berlin TZ) | `bureaucracy:remind` | Bureaucracy deadline push |
| weekly Mon 03:00 | `gtfs:refresh` | GTFS static timetable |
| weekly Mon 04:30 | `controls:drift-report` | Pipeline drift |

**Ownership notes:**
- Only `bureaucracy:remind` pins a timezone (Europe/Berlin). Everything else runs in the app's default TZ — verify `config('app.timezone')` matches your intent for the daily jobs (04:00 audits etc.).
- `withoutOverlapping()` defaults to a 24h lock. If a command hangs, its slot is skipped until the lock expires/clears — worth a max-runtime guard on the scrapers.
- The three `controls:*` commands are the safety net for the Context Engine cutover (audit/canary/drift). They are part of the spine's "is the new pipeline safe to flip on?" story.

---

## 5. Auth wiring — `FortifyServiceProvider`

- **Custom actions:** `CreateNewUser`, `ResetUserPassword` (in `app/Actions/Fortify/`). _Note: `UpdateUserProfileInformation` / `UpdateUserPassword` are not overridden here — profile updates flow through the Settings controllers instead._
- **Views:** all auth screens render Inertia React pages (`auth/login`, `auth/register`, `auth/reset-password`, `auth/forgot-password`, `auth/verify-email`, `auth/two-factor-challenge`, `auth/confirm-password`). Login/register pages receive `socialProviders` (computed from which OAuth `client_id`s are configured — Google/Apple).
- **Custom logout response:** `LogoutResponse` singleton → `App\Http\Responses\LogoutResponse`.
- **Rate limits:**
  - `login` → 5/min keyed by `lower(email)|ip`.
  - `two-factor` → 5/min keyed by session `login.id`.
- Social login routes (`auth/{provider}/redirect|callback`) live in `web.php` under `guest` middleware and respond on **all domains** (same as Fortify's own routes).

---

## 6. Inertia shared props — `HandleInertiaRequests`

Every Inertia response carries these props (lazy `fn()` ones only evaluate when not partial-reloaded):

| Prop | Source | Note |
|---|---|---|
| `name` | `config('app.name')` | |
| `features` | `config('features')` | drives "coming soon" gating client-side |
| `auth.user` | `$request->user()` | **whole User model** is shared (see risk below) |
| `isOnboarded` | `user->isOnboarded()` | |
| `sidebarOpen` | `sidebar_state` cookie | |
| `vapidPublicKey` | `config('webpush.vapid.public_key')` | web push enrolment |
| `unreadAlertCount` | lazy count | one extra query per non-partial load |
| `serviceErrors` | `session('serviceErrors')` | surfaced from failed external calls |
| `notificationPreferences` | user's prefs or `NotificationPreference::defaults()` | |
| `userSettings` | user's settings or `UserSetting::defaults()` | |
| `userLocation` | `UserLocationService::resolve()` | lazy; resolves "where the user is now" |

**Ownership risk:** `auth.user` shares the **entire** User model to the frontend on every request. Laravel's `$hidden` array on `User` is what stops `password` / `two_factor_secret` / `remember_token` leaking — so the safety of this depends entirely on `User::$hidden` being correct. When you tour the Data layer (Territory 7), verify `$hidden` covers `two_factor_secret`, `two_factor_recovery_codes`, `social_id` if sensitive, etc. Consider switching to an explicit prop shape or a `UserResource` to make the contract intentional rather than "whatever columns exist."

---

## 7. Config inventory

`config/` has 17 files; the ones that change backend behaviour:

### `config/context_engine.php` — the cutover switches (own these closely)
| Flag | Env | Default | Effect |
|---|---|---|---|
| `shadow` | `CONTEXT_ENGINE_SHADOW` | `false` | Evaluators write to `pending_actions:{id}_shadow`; dashboard + push untouched. Observe the new pipeline safely. |
| `enabled` | `CONTEXT_ENGINE_ENABLED` | `false` | `HomeFeedComposer` reads the **live** ZSET instead of legacy `RecommendationService`. |
| `push_via_bus` | `CONTEXT_ENGINE_PUSH_VIA_BUS` | `false` | Dispatcher calls real `notify()`. **When flipped true, legacy `notify()` in `Check*` commands MUST be removed in the same change** or users get double-pushed. |

Cutover order (from the config comments): both false → shadow only → `enabled` → `push_via_bus` (with legacy notify removal).

### `config/services.php` — every external integration's credentials
- **`embedding`** — sidecar URL (`http://embedding:8000`), 5s timeout, dim 384.
- **`vrs`** — TRIAS + GTFS-RT URLs, mTLS `client_cert` + password, `enabled` flag (`VRS_REALTIME_ENABLED`, default false), `requestor_ref`.
- **`valhalla`** — routing engine URL.
- **`google` / `apple`** — Socialite OAuth.
- **Mail:** `postmark`, `resend`, `ses`.
- **`slack`** — bot token + channel (ops notifications).

_This file is the canonical list of "things that can be down." Cross-reference with the `api:health` command and the per-service code in Territory 4._

### `config/queue.php` — ⚠️ the connection trap
- **Default connection is `database`** (`QUEUE_CONNECTION`, default `database`).
- Redis connection exists (`REDIS_QUEUE`, default `default` queue).
- **Ownership-critical:** per the project rule and memory note, the **prod worker only listens on `redis`** (queues `commute`, `default`). Any job that does **not** explicitly `onConnection('redis')` lands on the `database` connection and **never runs in prod**.
  - `RefreshWeather` pins `redis` ✅.
  - `PrecomputeUserRoutes` pins only `onQueue('commute')` — **no `onConnection('redis')`** → likely a latent bug. Flag for Territory 4/spine fix.
- `after_commit => false` on all connections — jobs dispatch immediately, not after the DB transaction commits. Context **events** use `ShouldDispatchAfterCommit`, but **jobs** do not; mind ordering if a job reads a row from a not-yet-committed transaction.

### `config/features.php` — page kill-switches
`language_exchange`, `chat`, `neighbourhoods`, `just_arrived` — all default `false`. When false the route renders a `coming-soon` page and the sidebar entry hides itself. Matches the "post-launch scope" memory note.

### Others (brief)
`auth`, `cache`, `database` (pgsql + PostGIS/pgvector), `fortify`, `inertia`, `logging`, `mail`, `sentry`, `session`, `webpush`, `app`, `filesystems`.

---

## 8. Spine-level risks to take ownership of

1. **Queue connection default mismatch** (`database` vs prod redis-only worker). Audit every `Job` and every `dispatch()`/`->onQueue()` call site to confirm `onConnection('redis')`. `PrecomputeUserRoutes` is a confirmed offender.
2. **`auth.user` over-sharing** — frontend contract is "all User columns minus `$hidden`." Make it explicit.
3. **Empty `withExceptions()`** — no custom exception handling; all observability rides on Sentry config. Decide if that's intentional.
4. **`SetCacheHeaders` no-store on all GET** — blocks any future HTTP/CDN caching of public pages.
5. **Onboarding redirect catches `api/*`** — un-onboarded API calls get HTML redirects, not JSON.
6. **Scheduler timezone** — only `bureaucracy:remind` pins Berlin; confirm `app.timezone` is right for the other dailies.
7. **`withoutOverlapping()` default lock** — a hung scraper can silently skip its slot for up to 24h; add max-runtime guards.

---

## 9. Open questions for the owner (you)

- Is the cutover currently in **shadow** or **enabled**? (Check the actual env on staging/prod — the defaults are all `false`, but env may differ.)
- Should `auth.user` become a typed resource/DTO before more user fields are added?
- Do we want a custom exception reporter (e.g., to attach user/route context to Sentry) or is the default enough?
- Confirm the prod worker's exact `--queue=` list matches what jobs actually target (`commute`, `default`).
