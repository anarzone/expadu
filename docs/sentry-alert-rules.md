# Sentry Alert Rules — Recommendation Pipeline

Spec for the alerts that should fire on top of the controls already
emitted by `controls:daily-audit` and `controls:synthetic-disruption`.

These are configured in the Sentry web UI (Project Settings → Alerts);
this file is the source of truth for what should exist there. When
you add or change a rule, edit this file in the same change.

Sentry project: `o4511156157349888` / `4511156222427216` (from
`SENTRY_LARAVEL_DSN` in `.env`).

---

## Rule 1 — Synthetic canary failure

**Goal:** page on-call when the end-to-end pipeline silently breaks.

- **Type:** Issue Alert
- **Conditions:** event message matches `synthetic disruption never landed in pending_actions` OR `synthetic disruption scored * (expected > 50)`
- **Filters:** environment = production
- **Frequency:** every event when triggered, escalate after 2 occurrences within 1h
- **Action:** PagerDuty / phone notification
- **Why:** the canary fires every 30 min. One miss may be a TRIAS hiccup; two in 60 min means the pipeline is wedged.

---

## Rule 2 — ContextEngine exception spike

**Goal:** catch new error types in the new code path before they
become widespread.

- **Type:** Issue Alert
- **Conditions:** issue is unresolved AND first seen
- **Filters:** issue path contains `App\\ContextEngine\\` OR `App\\Jobs\\PrecomputeUserRoutes` OR `App\\Services\\HomeFeedComposer` OR `App\\Services\\EmbeddingService` OR `App\\Services\\PersonalisationService`
- **Filters:** environment = production
- **Action:** Slack #expadu-alerts
- **Why:** any new error class in the recommendation surface is worth a human look in the first 24h.

---

## Rule 3 — Embedding sidecar 5xx surge

**Goal:** detect sidecar overload before it cascades into delayed
content saves.

- **Type:** Metric Alert
- **Metric:** count of events where `transaction:POST /embed` AND `http.status_code:5XX`
- **Threshold:** > 5 in 10 min
- **Filters:** environment = production
- **Action:** Slack #expadu-alerts (medium)
- **Why:** the wrapper degrades gracefully (returns null), so users
  do not see errors — but content stops embedding. Drift report would
  catch this in a week; we want to know in 10 min.

---

## Rule 4 — Daily-audit `fail` status

**Goal:** the validation plan's threshold violations should page someone.

- **Type:** Issue Alert
- **Conditions:** event message matches `controls:daily-audit tripped thresholds` AND tag `severity:fail`
- **Filters:** environment = production
- **Action:** Slack #expadu-alerts + email digest
- **Why:** the audit logs at WARNING level when status is `warn` or
  `fail`. Only `fail` warrants attention.

---

## Rule 5 — pgvector query latency regression

**Goal:** detect ANN slowdown before the dashboard feels it.

- **Type:** Metric Alert
- **Metric:** `transaction.duration` p95 for transactions tagged `op:pgvector.query`
- **Threshold:** > 200ms over 10 min
- **Filters:** environment = production
- **Action:** Slack #expadu-alerts (low — usually means rebuilding ivfflat indexes is overdue)
- **Why:** gradually-degrading ANN has bitten more than one team. Rebuild ivfflat with current `lists = sqrt(rows)` when this fires consistently.

To collect the metric, instrument `PersonalisationService::recommend()`
with a Sentry span (skip Phase 1 — add when this rule is needed).

---

## Rule 6 — `commute` queue depth

**Goal:** detect queue worker death.

- **Type:** Metric Alert
- **Metric:** custom gauge `queue.depth.commute` (emit from `controls:daily-audit` via `Sentry\addBreadcrumb`)
- **Threshold:** > 500 for 30 min
- **Filters:** environment = production
- **Action:** Slack #expadu-alerts
- **Why:** silent queue starvation means events fire but no actions land. Sympathetic to Rule 1 but earlier-warning.

To emit the metric, the daily audit can call
`Sentry::captureCheckIn(...)` or set a tag on its breadcrumb. This is
a follow-up — not blocking.

---

## Rule 7 — Notification volume floor (silent breakage)

**Goal:** the most dangerous failure mode — the pipeline runs but
never decides to deliver. Users would not complain because they would
not know what they were missing.

- **Type:** Metric Alert (custom)
- **Metric:** count of `Alert` rows created in last 24h
- **Threshold:** < 50% of the rolling 7-day median
- **Filters:** environment = production
- **Action:** Slack #expadu-alerts
- **Why:** pure floor alerts catch the silent-breakage class that the
  rest of these rules would miss.

To emit the rolling median, `controls:daily-audit` already counts
yesterday's alerts — extend it to also write the 7-day median to a
Sentry tag, then the rule can compare.

---

## Configuration order of operations

1. Rules 1, 2, 4 first — they fire off existing log/Sentry events with
   no extra instrumentation. Get them live with Stage 7 of the
   deployment runbook.
2. Rule 3 next — already covered by Sentry's transaction tracking if
   `Http::post` calls are auto-instrumented. Confirm in Sentry's
   transactions view, then add the rule.
3. Rules 5, 6, 7 are follow-ups that need extra instrumentation. Build
   the instrumentation as part of validation §H follow-on work, then
   create the rules. None of them block the cutover.

---

## Suppression / known noise

- **Mute window 22:00–06:00 CEST** for everything except Rule 1
  (synthetic canary) and Rule 7 (notification floor). The rest can
  wait until morning.
- **Mute Sundays** for Rule 3 (embedding sidecar 5xx). The market
  closure path emits a single batch on Saturdays/Sundays that warms
  the sidecar; transient spikes are expected.
