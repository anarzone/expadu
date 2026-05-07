# Post-Cutover Roadmap

**Progress as of 2026-05-07:**

- ✅ #1 UI sanity smoke test → `55fbb42` (Pest hydrator suite + Playwright assertions)
- ✅ #2 MMR diversity → `6c4b341` (kills "Cafe Nova + Café Nova" duplicates)
- 🟡 #3 Push delivery → `eece960` (phase 1 deployed, log-only). Phase 2 cutover parked: legacy fans out synchronously and eats throttle cap before async evaluator runs, making the planned 48h log-only parity comparison structurally invalid. Re-architect needed before flipping.
- ✅ #4 Notification preferences wiring → `8d9f550` (ActionBus strips push for actions matching disabled prefs)
- ⏸ #5 RecommendationService carve-up → parked. Multi-day refactor; HomeFeedComposer still delegates to legacy buildDashboardFeed for non-engine cards; removing the legacy class requires re-implementing 8 card builders as Hydrators. Pick up deliberately, not under sprint pressure.
- 🟡 #6 Per-user mute → `ec39a37` (backend done: MuteService + ActionBus integration + 5 tests). UI half pending design review.
- ⏸ #7 Thumbs-down → unstarted. Same UI-design dependency as #6.



Phase 1 (Context Engine) and Phase 2 (personalisation) are live on prod
as of 2026-05-06. This document is the prioritised follow-on work that
turns the substrate into shipped product features.

Pair this with `docs/validation-and-controls.md` (the "what does ok
look like" spec) and `docs/deployment-runbook.md` (the cutover
procedure). When any task here changes behaviour, edit those documents
in the same PR.

---

## Work items, in priority order

### 1. UI sanity smoke test
**Why first:** every assertion about the new pipeline so far has been
via `php artisan tinker` calls. Nobody has actually opened the
dashboard as user 3 in a browser since the cutover. The card hydrators
in `HomeFeedComposer::actionToCard()` produce shapes that *match*
what `dashboard.tsx` expects — but no real rendering has been observed.

**Scope:**
- Pest 4 browser test or Playwright spec that logs in as user 3, opens
  `/dashboard`, asserts each engine-driven card type renders without JS
  errors. Run via existing `tests/Browser/dashboard.spec.ts` infra.
- Manual screenshot check of: weather, transit_disruption,
  alternative_route, disruption_no_alt, leave_by, transit_delay,
  buergeramt_slot, market_closure, weather_alert, rhine_level cards.
- Fix any hydrator bugs surfaced (likely null-payload edge cases).

**Files likely touched:**
- `tests/Browser/dashboard.spec.ts` — new assertions
- `app/Services/HomeFeedComposer.php::actionToCard` — possible bug fixes

**Acceptance:** all 10 ScoredAction types render at least once with no
JS errors in the browser console; screenshots in PR.

**Complexity:** small (~30 min). Highest catch-bugs-cheaply value.

---

### 2. MMR diversity in personalisation
**Why:** the discovery slot for user 3 surfaced "Cafe Nova" AND "Café
Nova" — two near-duplicates. Pgvector ANN naturally clusters
near-duplicates. Users want variety, not 5 versions of the same place.

**Scope:** post-process the top-K from `PersonalisationService::recommendSpots`
with Maximal Marginal Relevance. Pick top, then iteratively pick items
that are similar to the user vector but *dissimilar* to already-picked
items. Lambda parameter (~0.5) trades relevance vs diversity.

**Files:**
- `app/Services/PersonalisationService.php` — new `applyMmr()` method
- Optional: same for `PersonalisationStrategy::cohortPopularSpots` (less
  duplicate-prone, lower priority)

**Acceptance:** discovery slot for user 3 shows 5 distinct cafes
(no near-duplicates by name or by cosine similarity > 0.95).

**Complexity:** small (~1h). Unblocks "discovery feels right".

---

### 3. Push delivery via the pipeline
**Why:** the biggest piece of debt remaining. Today every source
command does dual-write: `event(...)` AND `notify(...)`. The new
pipeline writes a `push` deliver-channel into ScoredAction.deliver_channels
but it's dormant — legacy `notify()` is what actually fires push. Until
push moves over:
- Throttle decisions in `ActionBus::insert` don't gate real pushes
- The `deliver_channels` field is dead code
- Source commands can't be cleaned up
- Every future "send me X" feature depends on this being unified

**Scope:** new listener `ScoredActionPushDispatcher` that subscribes
to a `ScoredActionInserted` event from `ActionBus`. When fired, it:
1. Checks `$action->deliverChannels` includes `push`
2. Maps action type → existing Notification class
3. Fires `$user->notify(...)` (which still goes through legacy
   `CreateAlertFromNotification` listener for the alert table side-effect)
4. Records `NotificationThrottle::recordSent($user)`

After it lands and produces parity with legacy delivery for 48h, **delete
the `notify()` calls in all `Check*` commands** and the dual-write is
gone.

**Files:**
- `app/ContextEngine/Events/ScoredActionInserted.php` — new event
- `app/ContextEngine/ActionBus.php` — emit the event after ZSET write
- `app/ContextEngine/Listeners/ScoredActionPushDispatcher.php` — new listener
- `app/Providers/AppServiceProvider.php` — register
- `app/Console/Commands/Check*.php` (six files) — remove `notify()` calls
- Validation doc §E behaviour contracts — update to reflect push moves

**Acceptance:**
- 48h of running with both paths: dual delivery measurable in `alerts`
  table count vs `scored_action:*` log push-channel count
- After 48h, switch off legacy `notify()`; alerts table count and
  delivered notifications stay within ±10% of the prior 7-day median
- Validation §G alarm "notification volume floor" stays green

**Complexity:** medium (2–3 days). Highest architectural payoff.

---

### 4. Notification preferences wiring
**Why:** `user_settings` table already exists with a `User::wantsNotification(string)`
method. Only `BuergeramtEvaluator` consults it half-heartedly. When push
moves to the pipeline (#3), evaluators need to filter out actions the
user has muted at the type level.

**Scope:**
- Each evaluator calls `$user->wantsNotification($prefKey)` before
  inserting an action with `push` in deliver_channels
- If preference is off: strip `push` from channels (action still shows
  on dashboard)
- Map preferences to evaluator types: `transit_disruption` ↔ `transit`,
  `weather_alert` ↔ `weather`, `buergeramt_slot` ↔ `burgeramt`, etc.

**Files:**
- `app/ContextEngine/Evaluators/*.php` — pre-insert `wantsNotification` check
- Possibly extract into `ScoredAction::stripDisabledChannels(User $user)`

**Acceptance:** a user with `wantsNotification('weather') = false`
sees no `weather_alert` ScoredAction with `push` in channels (dashboard
card still appears, push channel removed).

**Complexity:** small (4h). Partially closes #6 below.

**Depends on:** #3 (push pipeline) — without that, this is academic.

---

### 5. RecommendationService carve-up
**Why:** validation plan §1.10 says carve up after 1 week stable. The
legacy class is still 1,429 lines, dual-purposing as both fallback and
primary path. Some of that code is unreachable now (the
`adjustPriorities*` methods). Cleanup removes attack surface for future
bugs and shrinks cognitive load on every dashboard change.

**Scope:**
- 8 card builders → extract to `app/ContextEngine/Hydrators/{Type}Hydrator.php`
- `determineCommuteContext` (215 lines) → `app/ContextEngine/UserContextResolver.php`
- `adjustPriorities*` methods → delete (their effect lives in scoring now)
- `getRecommendations`, `buildDashboardFeed` → delete (HomeFeedComposer
  is the entry point)
- `getCommuteRecommendation` → move into `HomeFeedComposer`
- Static helpers (`determineBestMode`, `calculateLeaveBy`, …) →
  `app/Support/CommuteHelpers.php`

**Files:** see §1.10 of the original plan.

**Acceptance:**
- Pest suite green
- `RecommendationService.php` does not exist (or is empty thin shim
  pending final delete)
- Manual smoke: dashboard render unchanged

**Complexity:** medium (1 day). Mostly mechanical refactor.

**Depends on:** #3 push migration done (so we know push isn't relying
on legacy code).

---

### 6. Per-user mute UI
**Why:** "stop alerting me about Line 12 today" is a real UX feature. The
backing logic is small (Redis key `mute:{user_id}:line:{line}` with TTL),
but the UI surface to set it is what makes this a project — needs a
discoverable button, a confirmation pattern, and a "muted lines" panel
in profile settings.

**Scope:**
- Backend: `MuteService` with `mute(User, $type, $key, $ttl)` /
  `isMuted(User, $type, $key)`. Evaluator checks before insert.
- Frontend: button on each disruption card → "Mute Line 12 for 24h" with
  confirmation. Settings page → "Currently muted" list with un-mute action.
- Wayfinder routes for mute/unmute.

**Files:**
- `app/Services/MuteService.php` — new
- `app/Http/Controllers/MuteController.php` — new
- `app/ContextEngine/Evaluators/*.php` — check `MuteService::isMuted`
- `resources/js/components/recommendations/DisruptionCard.tsx` — add button
- `resources/js/pages/profile/notifications.tsx` — muted list

**Acceptance:**
- User can mute Line 12 from a disruption card → no `transit_disruption`
  actions for that line for the muted duration
- Mute appears in settings, can be removed
- Pest test covers mute insert + check + auto-expiry

**Complexity:** medium (1–1.5 days). Most of the cost is UI.

**Depends on:** #3 (so muting actually suppresses pushes), #4 (so
type-level prefs and per-instance mutes use the same pattern).

---

### 7. Thumbs-down on any card
**Why:** generalises the existing 7-day `card_dismissed` cooldown for
spots to every card type. Useful as a learning signal for personalisation
(weak negative) but mostly a UX feature for cards-the-user-doesn't-care-about.

**Scope:**
- Frontend: thumbs-down icon on every recommendation card
- Backend: existing `UserEvent` event_type=`card_dismissed` covers it
- Evaluator: check `recentlyDismissedActionKeys($user)` before insert

**Files:**
- `resources/js/components/recommendations/*.tsx` — add icon
- `app/ContextEngine/Evaluators/*.php` — pre-insert dismissal check

**Acceptance:** click thumbs-down → that specific action_key suppressed
for 7 days; same disruption next week shows again.

**Complexity:** small-medium (1 day). Mostly UI.

**Depends on:** #6 finished (same UI patterns for mute/dismiss).

---

## Dependency graph

```
1 (smoke test) ─────────── independent, do first
2 (MMR) ────────────────── independent
3 (push pipeline) ───────┬─→ 4 (prefs wiring) ─→ 6 (mute UI) ─→ 7 (thumbs-down)
                         └─→ 5 (carve-up)
```

Run #1 and #2 in parallel today. Land #3 next. After #3 is stable for
48h, fork #4 and #5. After #4, do #6 then #7.

---

## Out of scope (revisit later)

- **Score formula tuning** — once we have 2–4 weeks of `scored_action:*`
  log data, run an analysis: do action scores correlate with click rate?
  Are temporal_relevance buckets the right shape? Adjust constants in
  `Scorer::SEVERITY_BASE` etc. based on data, not intuition.
- **Per-user-per-route mute** — a refinement of #6 (currently per-line).
- **Daily/weekly digest email** — alt delivery channel that consumes
  the same `pending_actions` ZSET.
- **Cohort-based score weight overrides** — situation=`student` users
  might rank `buergeramt_slot` higher than `non_eu_employee`.
- **Phase 2 expansion to events/news** — currently personalisation
  only drives `nearby_spots`. Same vector math could drive `this_week`
  events and `news` cards.

---

## Cadence

Quarterly review: walk through this list, mark done items, re-prioritise
remainder, add anything that's emerged from product feedback.
