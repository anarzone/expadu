# Deployment Runbook — Recommendation Pipeline Cutover

End-to-end steps for rolling out Phase 1 (Context Engine) and Phase 2
(personalisation) to production. Designed to be copy-pasted in order
without surprises.

This runbook is paired with `docs/validation-and-controls.md` —
that document defines what "ok" looks like, this one describes how
to drive the change.

---

## Prerequisites

- All four pipeline commits are on `main` (`d0cb84e`, `aabe020`,
  `755c641`, `c9e4a28`).
- Coolify auto-deploys from `main`, so the new application code is
  already on prod after CI passes. The Docker image rebuild (pgsql +
  embedding sidecar) is the only manual step.
- Production host: `116.202.22.113` (Hetzner Cloud).
- App container: `app-eg4gsc4ok8g0cwkcsso48wso`.
- Redis container: `redis-eg4gsc4ok8g0cwkcsso48wso`.
- `pg_hba.conf` already accepts the in-network connection from
  the app container.

**Per memory:** test infra changes on staging first (`staging-app`
container exists on the same host). Only flip prod flags after
staging shows green for at least 24h.

---

## Stage 1 — Code deploy (auto, ~5 min after `git push`)

Coolify pulls `main`, rebuilds the app container, restarts. The new
code is live but **all flags default off** (`CONTEXT_ENGINE_SHADOW=false`,
`CONTEXT_ENGINE_ENABLED=false`), so behaviour does not change.

Confirm:

```bash
ssh root@116.202.22.113
docker ps --format '{{.Names}}\t{{.Image}}' | grep app-
# expect to see the latest image hash
```

---

## Stage 2 — Postgres image rebuild (one-time, ~3 min)

The pgsql service moved from `image: postgis/postgis:16-3.4` to a
locally-built image that adds `pgvector` (see `docker/pgsql/Dockerfile`).
Coolify will build it on next deploy *if* its compose orchestration
rebuilds local images. If not, force it:

```bash
cd /path/to/coolify/app/source   # whatever Coolify's checkout dir is
docker compose build pgsql
docker compose up -d pgsql
```

The data volume `sail-pgsql` (or its Coolify equivalent) is preserved
across the rebuild, so no data loss.

Verify the extension is loadable:

```bash
docker exec ${PGSQL_CONTAINER} psql -U sail -d expadu -c \
    "CREATE EXTENSION IF NOT EXISTS vector; SELECT extname FROM pg_extension;"
# Expect 'vector' in the output.
```

---

## Stage 3 — Apply migrations (~10 sec)

```bash
docker exec app-eg4gsc4ok8g0cwkcsso48wso \
    php artisan migrate --force
```

Two new migrations should run:

- `2026_05_05_133754_create_user_route_caches_table`
- `2026_05_05_141352_add_embedding_columns_to_content_tables`

Verify:

```bash
docker exec app-eg4gsc4ok8g0cwkcsso48wso \
    php artisan migrate:status | tail -5
```

---

## Stage 4 — Embedding sidecar (~5 min cold-start, includes model download into image)

The sidecar is a new service in `compose.yaml`:

```bash
cd /path/to/coolify/app/source
docker compose build embedding
docker compose up -d embedding
```

Verify:

```bash
docker exec ${EMBEDDING_CONTAINER:-expadu-app-embedding-1} \
    wget -qO- http://localhost:8000/health
# {"status":"ok","model":"sentence-transformers/all-MiniLM-L6-v2","dim":384}
```

The sidecar's HTTP endpoint must be reachable from the app container.
On Coolify the Docker network handles it via the service hostname
`embedding`. Confirm `EMBEDDING_SERVICE_URL` is unset or set to
`http://embedding:8000` in the app container's environment.

---

## Stage 5 — Backfills (~10–60 min depending on row counts)

These can run while flags are still off. They populate the data the
new pipeline reads.

```bash
# 1. Precompute commute routes for every onboarded user.
docker exec app-eg4gsc4ok8g0cwkcsso48wso \
    php artisan routes:precompute-all

# 2. Embed all existing content rows. --missing skips rows that already
# have a vector so this is safe to re-run.
docker exec app-eg4gsc4ok8g0cwkcsso48wso \
    php artisan embeddings:backfill --missing

# 3. Build user preference vectors from engagement history. Cold-start
# users get a vector seeded from their onboarding profile.
docker exec app-eg4gsc4ok8g0cwkcsso48wso \
    php artisan users:rebuild-preference-vectors
```

The `routes:precompute-all` command dispatches one queued job per
user. The `commute` queue worker must be running on Coolify — confirm
with `docker logs ${WORKER_CONTAINER}` (look for
`PrecomputeUserRoutes [DONE]` lines).

If the `commute` queue worker doesn't exist yet, add it to the Coolify
service config (a typical pattern is one supervisor process per queue
name). Workers handle ZSET writes too — without one running, nothing
lands in `pending_actions:*`.

---

## Stage 6 — Pre-cutover gates (validation §A1, ~5 min)

Run on prod and confirm each line:

```bash
docker exec app-eg4gsc4ok8g0cwkcsso48wso \
    php artisan controls:daily-audit
```

Expected:

- `pending_actions_coverage_pct` may be 0 — fine, flag is still off.
- `preference_vector_coverage_pct` ≥ 90.
- `embedding_latency_ms` < 200.
- `pgvector_query_latency_ms` < 50.
- `commute_queue_depth` ≤ 200.

Also:

```bash
docker exec app-eg4gsc4ok8g0cwkcsso48wso bash -lc '
    psql -U sail -d expadu -c "SELECT COUNT(*) FROM user_route_caches;" &&
    psql -U sail -d expadu -c "SELECT COUNT(*) FROM spots WHERE embedding IS NULL;"
'
```

`user_route_caches` count should be ≥ users-with-home-and-work.
`spots WHERE embedding IS NULL` should be 0 (or stable across reruns
of `embeddings:backfill --missing`).

---

## Stage 7 — Enable shadow mode (validation §A2 + §B, runs for 48h)

In Coolify, set the environment variable:

```
CONTEXT_ENGINE_SHADOW=true
```

Then redeploy or restart the app container so the new env is loaded:

```bash
docker exec app-eg4gsc4ok8g0cwkcsso48wso php artisan config:clear
```

After this, every event fired by the source commands is also evaluated
by the new pipeline. ScoredActions land in
`pending_actions:{userId}_shadow` keys. The legacy path still serves
the dashboard and push notifications, so users see no change.

**Do not flip `CONTEXT_ENGINE_ENABLED` yet.**

Watch for 48h:

```bash
# Spot-check user 3 (the canary)
docker exec app-eg4gsc4ok8g0cwkcsso48wso \
    php artisan context:replay --user=3 --days=2 | jq '.engine_action_count, .actual_alert_count'

# Top-K sample for several users
docker exec ${REDIS_CONTAINER:-redis-eg4gsc4ok8g0cwkcsso48wso} \
    redis-cli --scan --pattern 'pending_actions:*_shadow' COUNT 1000 | head

# Sentry: zero new errors in App\ContextEngine\* over the window
```

---

## Stage 8 — Flip live (validation §A3, ~30 sec, instant rollback available)

In Coolify, set:

```
CONTEXT_ENGINE_ENABLED=true
```

Redeploy / restart container. From this moment, `HomeFeedComposer`
reads `pending_actions:{userId}` and displaces legacy `disruption` /
`accessibility_alert` / `weather_alert` cards in the dashboard.

Push delivery is still on the legacy path during the migration window
— this is intentional. Do not change.

**Smoke test immediately:**

```bash
# Login as user 3 in a browser, open /dashboard, observe a card with
# meta.source = "context_engine" present (or just any disruption card
# if you happen to have one fired).

# Confirm 24h notification volume hasn't crashed:
docker exec app-eg4gsc4ok8g0cwkcsso48wso bash -lc '
    psql -U sail -d expadu -c "
      SELECT subtype, COUNT(*)
      FROM alerts
      WHERE created_at > NOW() - interval ${1:-24} hour
      GROUP BY 1 ORDER BY 2 DESC;
    "
'
```

If anything looks wrong: set `CONTEXT_ENGINE_ENABLED=false`, restart.
Legacy `RecommendationService` is still wired and serves immediately.
**This is the rollback. There is no other rollback step.**

---

## Stage 9 — Steady-state monitoring (forever)

Three scheduled commands keep watch (registered in `routes/console.php`):

| Command | Cadence | Purpose |
|---|---|---|
| `controls:daily-audit` | daily 04:00 | 7-metric snapshot, JSON to `storage/app/controls/`, exit 1 on threshold trip |
| `controls:synthetic-disruption` | every 30 min | End-to-end canary: real user, real line, real event, asserts ScoredAction lands |
| `controls:drift-report` | weekly Mon 04:30 | This-week-vs-prior-3-weeks median % delta |

These run inside the existing scheduler container; nothing extra to wire.

---

## Stage 10 — Retire legacy shim (validation §C3, after 1 week green)

Once the live cutover has been clean for 7 days:

1. Carve up `app/Services/RecommendationService.php` per validation
   plan §1.10 (8 card builders → hydrators, `determineCommuteContext`
   → `UserContextResolver`, etc.).
2. Delete the displaced legacy methods.
3. Drop the `CONTEXT_ENGINE_ENABLED` flag and make the new path
   unconditional.

This is a separate PR, not part of the cutover.

---

## Failure modes and what to do

| Symptom | Root cause to check | Action |
|---|---|---|
| Dashboard empty after enabling live | Worker not consuming `commute` queue | Restart queue worker, check `php artisan queue:work commute --once` |
| `controls:daily-audit` reports `embedding_latency_ms > 1000` | Sidecar OOM or GPU contention | `docker logs expadu-app-embedding-1`; restart sidecar |
| Synthetic canary fails twice in a row | Listener deregistered, evaluator throws | Sentry → grep for `ContextEngine\Evaluators\TransitDisruption`, check queue logs |
| Notification volume drops > 50% | Source commands not emitting events | Check `App\Console\Commands\Check*` logs; flip back to `_SHADOW=true` |
| Notification volume spikes > 200% | Throttle bypassed at insert time | Inspect `ActionBus::insert` callsite; consider emergency rollback |
| `pending_actions:*` keys sit at 0 | Queue worker dead OR evaluator returning null on every user | First check worker, then `php artisan tinker --execute 'event(new \App\Events\Context\TransitDisruptionDetected(disruptionId:1, lines:["12"], stopsAffected:[], severity:"major", bbox:null, expiresAt:null));'` and watch logs |

---

## Quick command index

```bash
# Backfills (re-runnable, idempotent)
php artisan routes:precompute-all
php artisan embeddings:backfill --missing
php artisan users:rebuild-preference-vectors

# Diagnose current state
php artisan controls:daily-audit
php artisan controls:synthetic-disruption --user=3
php artisan context:replay --user=3 --days=7
php artisan context:replay --user=3 --days=7 --live

# Trigger a synthetic disruption end-to-end (engine must be enabled)
php artisan tinker --execute 'event(new \App\Events\Context\TransitDisruptionDetected(disruptionId:99, lines:["12"], stopsAffected:[], severity:"major", bbox:null, expiresAt:null));'

# Inspect Redis state for a user
redis-cli zrevrange "pending_actions:3" 0 5 withscores
redis-cli zcard "pending_actions:3"
```

Read `docs/validation-and-controls.md` if a metric is unfamiliar.
