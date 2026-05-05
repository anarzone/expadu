# Recommendation System — Validation & Regular Controls

Living spec for keeping the Phase 1 (Context Engine) and Phase 2
(personalisation) pipelines correct over time. Owner: anar.

This document is the source of truth for *what the system must do* and
*how we verify it*. When behaviour changes intentionally, edit this
file in the same PR — it is not historical, it is current law.

---

## Layout

- **Section A** — pre-cutover gates (one-time, before each flag flip)
- **Section B** — shadow window monitoring (48h, before ENABLED=true)
- **Section C** — live cutover monitoring (first week after ENABLED=true)
- **Section D** — steady-state continuous controls (forever)
- **Section E** — behaviour contracts (what each component promises)
- **Section F** — golden scenarios (the canary path-throughs)
- **Section G** — alarms (what should page someone)

---

## A. Pre-cutover gates

Run all of these before flipping each flag. Each gate has a clear
pass/fail signal — none are subjective.

### A1. Before `CONTEXT_ENGINE_SHADOW=true`

| Gate | Pass signal |
|---|---|
| Migrations applied | `php artisan migrate:status` shows `2026_05_05_133754_create_user_route_caches_table` and `2026_05_05_141352_add_embedding_columns_to_content_tables` as `Ran` |
| pgvector extension | `psql -c "SELECT extname FROM pg_extension"` includes `vector` |
| Embedding sidecar healthy | `curl http://embedding:8000/health` returns `{"status":"ok","dim":384}` (or via `:8001` from host) |
| Pest suite green | `php artisan test --parallel` → `292+ passed` (no new failures vs. main) |
| Pint clean | `vendor/bin/pint --test --format agent` → `pass` |
| User route cache populated | `php artisan routes:precompute-all` finishes; `SELECT count(*) FROM user_route_caches` ≥ users with home+work |
| Embeddings backfilled | `php artisan embeddings:backfill --missing` finishes; `SELECT count(*) FROM spots WHERE embedding IS NULL` = 0 |
| User vectors built | `php artisan users:rebuild-preference-vectors` finishes; `SELECT count(*) FROM users WHERE preference_vector IS NULL AND onboarded_at IS NOT NULL` = 0 |

### A2. Before `CONTEXT_ENGINE_ENABLED=true` (after 48h shadow)

| Gate | Pass signal |
|---|---|
| Shadow ZSETs populated | `redis-cli SCAN 0 MATCH 'pending_actions:*_shadow' COUNT 1000` returns ≥80% of onboarded user count |
| Score distribution sane | Spot-check 5 users via `php artisan context:replay --user=N`. Top-scored action per user matches a real disruption / weather alert / leave-by reminder, not a stale entry |
| Replay parity | `php artisan context:replay --user=3 --days=7` shows ≥80% overlap between `engine_actions` and `actual_alerts` (subtype × line × day) — false positives reviewed manually |
| No queue backlog | `php artisan queue:monitor commute --max=50` does not warn |
| No Sentry spike | Sentry shows zero new error types in `App\ContextEngine\*` over the 48h |

### A3. After `CONTEXT_ENGINE_ENABLED=true`

| Gate | Pass signal |
|---|---|
| Live ZSETs populated | `redis-cli SCAN 0 MATCH 'pending_actions:*' COUNT 1000` (without `_shadow`) ≥80% of onboarded users |
| Dashboard renders | Manual: open dashboard as user 3 in browser, see at least one card from the new pipeline (any `meta.source = "context_engine"` field present) |
| Push delivery unchanged | Notification volume per user per day for the first 24h within ±20% of prior 7-day average. Compare via `SELECT subtype, COUNT(*) FROM notifications WHERE created_at > now() - interval '24 hours' GROUP BY 1` |
| No card regressions | Dashboard for user 3 still shows: weather, departures, places, this_week, settlement progress |

If any gate fails: flip `CONTEXT_ENGINE_ENABLED=false` immediately. The
legacy `RecommendationService` is still wired and serves as instant
rollback for the first week.

---

## B. Shadow window monitoring (48h before ENABLED=true)

What to watch every ~12h during the shadow window:

- `redis-cli zcard pending_actions:3_shadow` — should grow as events fire. Empty after 6h = pipeline broken.
- `redis-cli zrevrange pending_actions:3_shadow 0 5 withscores` — scores should be in 5–100 range, top entry should be a real current event.
- `php artisan context:replay --user=3 --days=2 | jq '.engine_action_count, .actual_alert_count'` — counts should be roughly comparable.
- Sentry: no new exceptions in `App\ContextEngine\*` or `App\Jobs\PrecomputeUserRoutes`.
- `docker logs expadu-app-embedding-1 --since 12h` — no model load failures, no 500s.

If any of these is anomalous, do NOT flip ENABLED. Investigate first.

---

## C. Live cutover monitoring (first 7 days)

For the first week after `CONTEXT_ENGINE_ENABLED=true`:

### C1. Daily sanity (manual, ~5 min)

Run as a checklist each day at 09:00 (after morning commute traffic):

1. Open the dashboard as user 3. Confirm:
   - There is at least one card visible (not empty state).
   - If a real disruption is active, the disruption card is the top item.
   - Discovery slot shows 2–5 spots, none of them dismissed in the last 7 days.
2. Run `php artisan context:replay --user=3 --live --days=1 | jq '.engine_actions | length'`. Should be > 0.
3. `redis-cli LLEN queues:commute` — should be near zero (workers keeping up).
4. Check `notifications` table: count yesterday's deliveries, compare to the matching weekday last week. Within ±50% = OK.

### C2. Daily automated (cron job, runs at 04:00)

A new artisan command `controls:daily-audit` (see Section H — to-build)
runs every morning and emits a single Sentry breadcrumb + a Slack/email
summary if anything is off. Specifically:

| Check | Threshold |
|---|---|
| % onboarded users with non-empty `pending_actions:{id}` | ≥ 60% |
| % of yesterday's disruption events that produced ≥1 ScoredAction | ≥ 95% |
| Mean score of top-1 action across active users | 30–80 |
| `users.preference_vector` non-null rate | ≥ 90% of onboarded |
| Embedding sidecar p95 latency (rolling 24h) | < 200ms |
| pgvector ANN query p95 (rolling 24h) | < 50ms |
| Pest browser smoke-test result | passing |

### C3. End-of-week review (60 min)

After 7 days live, walk through Section F (golden scenarios) end-to-end.
If all green, retire the legacy `RecommendationService` thin shim per
plan §1.10.

---

## D. Steady-state continuous controls

Once cutover is stable, these run forever — most are automated.

### D1. Tests (CI, every PR)

- **Pest suite** — `php artisan test --parallel`. Existing 292+ tests + the ContextEngine ones must stay green. **No `--filter`-skipping in CI.**
- **Pint** — `vendor/bin/pint --test`.
- **Eslint + Prettier** — already in pre-commit hook. Per memory: never `--no-verify`.

### D2. Synthetic canary (cron, every 30 min)

A scheduled command `controls:synthetic-disruption` (Section H):

1. Picks a random onboarded user with a UserRouteCache entry
2. Dispatches a synthetic `TransitDisruptionDetected` event with a line that user actually uses
3. Polls `pending_actions:{id}` for ≤10 sec
4. Asserts: at least one `transit_disruption` action with score > 50 appeared
5. Cleans up the synthetic action after the assertion

Failure → page (see Section G). This catches the silent breakage where
events fire but nothing lands in the bus (queue worker died, listener
deregistered, etc.).

### D3. Browser smoke (cron, every 4h)

A Pest browser test `tests/Browser/DashboardSmokeTest.php` (Section H):

```
visit('/dashboard') as user 3
wait for the deferred feed
assert: at least one card rendered, no JS console errors
assert: weather widget visible, transit hero visible
assert: discovery section has 2–5 cards
```

Run via `php artisan test --filter=DashboardSmokeTest` from a cron container.

### D4. Drift detection (weekly, Mondays 04:30)

`controls:drift-report` writes a JSON report to `storage/app/controls/drift-{YYYY-MM-DD}.json`:

- 7-day notification volume per type vs. the prior 4-week median
- 7-day card-click rate per card type vs. the prior 4-week median
- Number of users where `pending_actions:{id}` was continuously empty for 48h
- Distribution of `personal_relevance` scores (route_match vs. routine_match vs. geo)
- p95 latency for `HomeFeedComposer::buildDashboardFeed`

Anything moving > 30% week-over-week needs a manual look.

### D5. Quarterly review (every 3 months, ~2h)

- Re-read this document. Is each behaviour contract still accurate?
- Pick 5 random users from each cold-start tier. Manually inspect their
  dashboard. Does the personalisation feel right?
- Review the past quarter's Sentry issues for the recommendation
  pipeline. Are any recurring patterns now feature requests?
- Audit `RecommendationService.php` carve-up progress (plan §1.10).

---

## E. Behaviour contracts

What each component must promise. These are the assertions that drive
the test suite and the synthetic canary.

### E1. `UserRouteCache` / `PrecomputeUserRoutes`

- **Must** dispatch a recompute job on UserPlace `created`/`updated`/`deleted` (debounced 60s).
- **Must** keep `home ↔ each anchor place` rows current within 60s of place mutation.
- **Must** record `lines` from `mode === transit` segments only — no walk segments.
- **Must NOT** delete cached pairs during job execution; only at the end after successful upsert.
- **Must NOT** N×N — pair selection rule is `home ↔ {work | place with arrive_by}`, both directions.

### E2. Source commands (`Check*`, `Send*`)

- **Must** call `event(new ...Detected(...))` for every NEW finding before any per-user fan-out.
- **Must** preserve their existing `withoutOverlapping` cadence in `routes/console.php`.
- **Must** retain the legacy `notify()` calls during the migration window — the new pipeline owns dashboards but legacy still owns push.
- **Must NOT** dispatch events for findings that haven't changed since last run (use cache dedup keys).

### E3. Evaluators (`app/ContextEngine/Evaluators/*`)

- **Must** be `ShouldQueue`-implementing listeners on the `commute` queue.
- **Must** chunk users in batches of 100, scoping to `whereNotNull('onboarded_at')`.
- **Must** dedupe via `action_key` — re-firing the same disruption never produces a duplicate ZSET member.
- **Must** consult `NotificationThrottle::canPush()` *via ActionBus on insert*, not at delivery.
- **Must NOT** call `notify()` directly. Notification is the source command's job during migration; later, a dedicated dispatcher.
- **Must NOT** swallow scoring errors silently. Throw → queue retries, logs, Sentry.

### E4. `ActionBus`

- **Must** use Redis ZSET `pending_actions:{userId}`, scored by score.
- **Must** apply `_shadow` suffix when `config('context_engine.shadow') === true`.
- **Must** ZREM expired members on read (sweeper-on-read pattern).
- **Must NOT** keep more than the natural expiry of each action (disruption.expires_at, weather forecast horizon, etc.).
- **Must NOT** allow more than one entry per `action_key` per user.

### E5. `AlternativeRoutePlanner`

- **Must** return `null` (caller emits `disruption_no_alt`) when no alt is within 15min of original.
- **Must** post-filter — never trust an unverified TRIAS `<LineFilter>` to do exclusion until the open question is resolved.
- **Must** cache results under `alt_route:{userId}:{disruptionHash}` for the disruption's lifetime.

### E6. `HomeFeedComposer`

- **Must** preserve the `DashboardFeed` shape (`recommendations`, `nearby_spots`, `this_week`, `places`, `settlement`, `departures`, `needs_setup`) — `dashboard.tsx` is untouched.
- **Must** delegate to `RecommendationService` when `CONTEXT_ENGINE_ENABLED=false`.
- **Must** displace only `disruption`, `accessibility_alert`, `weather_alert` types from the legacy feed when enabled — leave events, news, departure cards, settlement, commute_tip alone.
- **Must** apply diversity caps to the merged result.
- **Must NOT** show a spot in the discovery slot that was dismissed in the last 7 days.

### E7. `EmbeddingService` + `HasEmbedding`

- **Must** short-circuit re-embedding when `embedding_hash === sha256(embeddingText())`.
- **Must** return `null` on sidecar errors and never persist a stale or zero vector.
- **Must NOT** block content saves on sidecar availability — the `saved` observer wraps in a no-op-on-error path.

### E8. `PersonalisationStrategy`

- **Must** count only engagement-bearing event types when picking a tier (`event_saved, journey_planned, spot_viewed, card_clicked, departure_viewed`).
- **Must** fall through to `tagFilteredSpots` when situation/cohort data is missing — no empty discovery slot for cold users with valid onboarding.

---

## F. Golden scenarios

Walk these every Monday during the first month, then quarterly.
Each one names a real user behaviour and the expected system reaction.

### F1. Major disruption hits user's commute line

**Setup:** User has Home + Work places, weekday arrive_by, no Routine. UserRouteCache row exists with line "12" included.

**Trigger:** `php artisan tinker --execute 'event(new \App\Events\Context\TransitDisruptionDetected(disruptionId:9999, lines:["12"], stopsAffected:[], severity:"major", bbox:null, expiresAt:null));'`

**Expected within 30 sec:**
- `pending_actions:{userId}` ZSET contains a `transit_disruption` action with score > 50 and `meta.matched_route_id = <user's UserRouteCache id>`
- A second action: either `alternative_route` (with summary text) or `disruption_no_alt`
- Dashboard top card shows the disruption with "On your route" subtitle
- During commute window: push notification sent (via legacy notify, not the bus, during migration)
- `Alert` row created in DB by `CreateAlertFromNotification`

### F2. Disruption on unrelated line

**Trigger:** Same as F1 but `lines: ["99"]` — a line the user does not use.

**Expected:** No new entry in `pending_actions:{userId}`. No notification. No dashboard card.

### F3. Cold-start user opens dashboard

**Setup:** Fresh-onboarded user, situation=`student`, german_level=`a1`, no engagement events yet.

**Expected:** Dashboard's discovery slot shows 2–5 spots from `tagFilteredSpots` (verified, ordered by rating). Not empty. Not 50 spots.

### F4. Power user with rich engagement

**Setup:** User with > 50 engagement events, `preference_vector` non-null.

**Expected:** Discovery slot shows spots whose category matches the user's historical preferences (e.g. mostly cafes for a cafe-clicker). Distance from home ≤ 4km. None dismissed in the past 7 days.

### F5. Buergeramt slot drops at 11:30

**Trigger:** `php artisan tinker --execute 'event(new \App\Events\Context\BuergeramtSlotsAvailable(officeId:"innenstadt", dates:["2026-05-08"]));'` for a non-EU employee user.

**Expected:** Push notification fires immediately (Tier 1, exempt from quiet hours and commute window). Dashboard card appears with score ≈ critical-tier. `Alert` row created.

### F6. UserPlace edited

**Setup:** User edits Work address.

**Expected within 60–90 sec:** A `PrecomputeUserRoutes` job runs. `user_route_caches` row for `home → work` updated with new `computed_at`, possibly different `lines` and `bbox`.

### F7. Embedding sidecar dies

**Setup:** Stop the embedding container. User saves a Spot.

**Expected:** Save succeeds. `Spot.embedding` not updated (stays at last good value). Sentry breadcrumb logged. No 500 to the user.

---

## G. Alarms

What pages someone (or sends a Slack/email summary). Implement via the
existing `App\Console\Commands\NotificationHealthCheck` pattern or
extend Sentry alerts.

| Signal | Threshold | Action |
|---|---|---|
| Synthetic canary fails (D2) | 2 consecutive failures | Page on-call |
| Embedding sidecar 5xx rate | > 5% over 10 min | Page on-call |
| pgvector query p95 | > 200ms over 10 min | Slack ping |
| `PrecomputeUserRoutes` failure rate | > 10% of dispatches | Slack ping |
| Notification volume per user per day | > 7 (the documented cap) | Slack ping per user |
| Notification volume per user per day | < 50% of 7-day median | Slack ping (silent breakage) |
| Queue depth `commute` | > 500 | Slack ping |
| `pending_actions:*` total keys | < 50% of onboarded count | Slack ping (pipeline silent) |
| New error type in `App\ContextEngine\*` | first occurrence | Sentry alert |

---

## H. Tooling to build

The Section A–G checks reference commands and tests that don't exist
yet. Build in this order:

1. **`php artisan controls:daily-audit`** (Section C2) — emits one
   structured JSON line and writes to `storage/app/controls/`. Cron at
   04:00 daily after the existing scheduler tasks finish.
2. **`php artisan controls:synthetic-disruption`** (Section D2) — every
   30 min. Picks a real user, fires a synthetic event scoped to a line
   they actually use, polls for the action, asserts, cleans up.
3. **`tests/Browser/DashboardSmokeTest.php`** (Section D3) — Pest 4
   browser test. Run as a separate scheduled task with a real Chromium.
4. **`php artisan controls:drift-report`** (Section D4) — weekly Monday
   04:30. Compares this week's distribution to the rolling 4-week
   median.
5. **Sentry alert rules** (Section G) — configure via Sentry UI; record
   the rule names back here so they're reviewable.

These are deliberately staged. Phase 1 + 2 cutover does not require
them. They make the system *safe to leave running* — which is the
point of this document.

---

## I. Scope and review cadence

- **In scope:** the recommendation pipeline (Phases 1 + 2), the sources
  feeding it, the dashboard surfaces consuming it, push notifications,
  and the embedding sidecar.
- **Out of scope:** auth, transit search (route picker), settlement
  flow, admin panel. Each of those needs its own validation doc when
  it's the focus.

**Review cadence:**
- After each major behaviour change → edit this doc in the same PR.
- Quarterly walkthrough (Section D5).
- After any incident → add a row to the relevant table; if the
  scenario isn't covered, add it to Section F.
