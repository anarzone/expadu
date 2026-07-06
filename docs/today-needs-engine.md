# Today: from a notification feed to a needs engine

**Status:** design thesis + phased plan (2026-07-05). Bugs in Phase 0 are shipped;
everything below Phase 0 is proposed, not built.

**Scope:** the Today screen's "Right Now" band — `app/Home/TileComposer.php`,
`Tile.php`, `TileTriage.php`, the ContextEngine evaluators that feed it, and the
`dashboard.tsx` presentation. Not the prompt/composer box, not the discovery rails.

---

## Part I — Thesis

### The reframe

Today's "Right Now" is built as a **notification feed**: evaluators detect
world-events (rain, a disruption, a Rhine gauge crossing), score them 0–100, the
composer merges them with a few synthetic tiles, caps at 8, and renders. That is
"alerts."

What the surface should be is a **daily brief of _needs_**. An alert answers
_"what happened in the world?"_ A need answers _"what does **this** person have to
know or do **today**, given their situation and their actual day?"_ The current
system does only the first and hopes ranking approximates the second. It doesn't —
ranking *orders* noise, it doesn't *remove* it.

The optimisation target is **precision of the promise**: everything shown is worth
the interruption. Once a user learns _"if Expadu surfaces something, it matters,"_
they stop cross-checking other sources — and that belief is the moat. So the metric
is not coverage or engagement; it is _"is this brief believed?"_ — which means it is
usually short, and sometimes empty.

### Five principles

1. **Needs are trajectories, not booleans.** A need emerges → *ripens* into a
   decision window → *peaks* → resolves or converts to a consequence. Scoring
   collapses that arc into one instant. Anmeldung on day 1 (nothing to do yet) and
   day 13 (window closing) are different states. The right question is not "how
   urgent 0–100" but "where is this need in its lifecycle, and is the user inside
   the window where they can act?" The system must be able to say **"not yet"**
   (a promise to surface later), which is different from "doesn't exist" (amnesia).
   The bureaucracy deadline tiers (`overdue`/`critical`/`urgent`) are already a
   crude version of this — the one tile that gets it right.

2. **The value is lead time, not "now."** The most valuable thing an app can do for
   an expat is give lead time on friction a local sees coming. "Shops are closed"
   is worthless (too late); "you have 4 hours before everything shuts for 36" is
   the product. Reframe every signal by its **lead time to consequence** — the best
   items on Today are often about a *foreseeable near-future the user can still
   change*, not the present.

3. **Organise by consequence, not by source.** Tiles are grouped by where they came
   from (weather / transit / bureaucracy). The user never experiences "a weather
   alert" — they experience _"my evening is at risk."_ The unit is the **threatened
   thing in the user's life**, and this yields a deeper dedup than any key fix: rain
   *and* a disruption that both hit the same 17:00 plan are **one need**, not two
   tiles. A tiny vocabulary of consequences covers it:
   **plan-at-risk · window-closing · caught-out · opportunity · on-track.**

4. **Error costs are asymmetric — the gate is not uniform.** Missing a legal
   deadline (false negative) is catastrophic → err loud. Showing an irrelevant
   market tile (false positive) spends trust → err quiet. One global "show if
   score > X" cannot encode this; each need type carries its own answer to _"which
   way do we fail?"_

5. **Silence is a feature.** A brief that is sometimes empty is trustworthy; a feed
   always full of filler trains people to ignore it. When nothing clears the gate,
   Today says _"nothing needs you right now"_ and hands off to the composer. If
   needs are rare when done honestly, **Today is primarily a launchpad** (the ask/
   plan box) with a thin, occasional needs band — not a wall of tiles.

### The gate — what "needed" means operationally

A candidate signal earns a place only by clearing four tests (tuned per type, per
principle 4):

| Test | Question |
|---|---|
| Actionability | Can the user *do* something, now? |
| Personal intersection | Does it touch *their* plan / places / commute / open task? |
| Decision window | Do they need it *now* to decide? |
| Novelty | Have we already said it, unchanged? |

Most raw signals should fail this — correctly. The complete record still lives on
the Alerts page, so Today can afford to be aggressive: nothing is lost, it's one
tap away. **This is why the two-surface split (Today vs Alerts) earns its keep** —
but only as long as Today stays ruthlessly curated. When it doesn't (the rain dup),
the home screen reads as a noisy copy of Alerts and the whole architecture looks
redundant.

### The learning loop — day 1 vs day 90

The resolution of a need is the highest-quality signal the app ever gets, and today
five-sixths of it is discarded: `TileTriage` distinguishes done / snooze / dismiss
but only `dismiss` feeds any learning, and it learns one blunt thing — demote the
*type*.

**Resolution is multi-dimensional, and the dimensions demand opposite corrections:**

| How it resolved | Meaning | Correction |
|---|---|---|
| Acted (took the action) | Right need, right framing | more of this |
| "Already handled" | Right need, **too late** | surface **earlier** |
| "Not relevant" | Wrong need | surface **less** |
| Snoozed | Right need, wrong moment | shift **timing** |
| Ignored → expired | Weak / not actionable | reframe or drop |
| Never seen (expired between sessions) | **Delivery** timing | deliver at a different hour |

"Too late," "wrong," and "annoying" need *earlier*, *less*, *differently* — a single
demotion number cannot encode three opposite fixes.

**The grain of learning** must be `need-class × consequence × context`, not `type`.
Too coarse (all "weather") and you bury the storm with the drizzle; per-instance and
nothing accumulates. Choosing that grain is the real engineering problem.

**Cold start = cohort prior → personal posterior.** Day 1: run on a situation-cohort
prior ("new non-EU arrivals need Anmeldung early and act on it"). Each user's own
resolutions pull them off the prior toward personal truth. That is the literal
answer to day-1-vs-day-90:

- **Day 1** — cohort prior; smart for a typical member of your situation.
- **Day 30** — learned you act on paperwork instantly (nag once), already know the
  Sunday rhythm (drop it — you *graduated* that lesson), care about plan-protection.
- **Day 90** — mostly quiet; speaks only for real plan-risk and the next ripening
  milestone. The manual has faded because you became a local.

**The floor — what must never be learned away.** For catastrophic-false-negative
needs (legal deadlines), learning may tune *how* you nag (timing, framing) but never
*whether*. Learning has a floor, and the floor is set by **consequence, not
preference**. (Phase 0's `DEMOTE_IMMUNE_TYPES` is the v0 of this.)

**Silence is unobservable, so learn conservatively.** You only get feedback on needs
you *showed*; correct silence and *incorrectly* missing something both produce no
event. Two partial escapes: (a) **consequence as delayed ground truth** — a deadline
that goes overdue *is* the label "should have surfaced sooner"; (b) **rationed
exploration** — occasionally surface a low-confidence need to stay honest, but on a
*trust* surface this is far more expensive than on a content feed, so ration it hard
and lean on priors + consequence-truth instead. **This loop optimises toward
_silence_ — its measure of success is how little it eventually has to say.**

---

## Part II — Audit of the current tiles

Nine tile types run through the thesis. Three are already need-shaped and are the
template; three are source-driven noise for most users; the rest are real needs
starved of the one thing never computed: the intersection with the user's day.

| Tile | Need or raw signal? | Fail direction | Verdict |
|---|---|---|---|
| `bureaucracy_deadline` | Need (deadline, action, stakes) | Loud (legal FN catastrophic) | ✅ Keep — the model tile |
| `buergeramt_slot` | Need *if* you need that office | Loud, matching cohort only | ⚠️ Verify it's alive + task-gated |
| `tonight_events` | Discovery in an urgency costume | Quiet | ◐ Keep only when saved/intended |
| `transit_disruption` | Need *only* × an active trip | Loud on your trip, else quiet | ◐ Fuse with a live trip/leave-by |
| `market/rhythm closure` | Need — the purest expat "caught-out" | Quiet, recurring | ◐ Keep — must decay with tenure |
| `weather_alert` | Two things in one type | Split | ⚠️ Hazard (loud) vs advisory (fuse-or-drop) |
| `transit_delay` | Signal — minutes before a boarding | Quiet | ❌ Drop from Today |
| `rhine_level` | Signal — an ungeacted gauge reading | Quiet for ~everyone | ❌ Alerts-only (flood cohort excepted) |

**Cross-cutting patterns (visible only across the whole list):**

1. **Source-driven artifacts** — rhine, transit_delay, advisory-rain, generic
   tonight-events exist because they were *easy to detect*, not because users need
   them. Supply-side, not demand-side. Drop candidates.
2. **The fusion gap is universal** — nearly every tile becomes a real need only when
   crossed with the user's plan/trip/task/place, and almost none of them do it. The
   half-measures (`TransitDisruptionEvaluator` line-scoring, `WeatherEvaluator`'s
   `hasNextAnchor` score-bump) prove the instinct exists but was never built into an
   actual intersection step. **One missing organ degrades six of nine tiles.**
3. **Three tiles already show the way** — `bureaucracy_deadline` (lifecycle tiers),
   `tonight_events` (cross-surface `claim()` + time-window gate), the *concept* of
   `buergeramt_slot`. The redesign is "make every tile as need-shaped as
   bureaucracy_deadline already is," not invent-from-scratch.
4. **The floor was under-enforced** — consequence ≠ severity; fixed in Phase 0.
5. **The rain dup had siblings** — any two producers narrating one real-world fact
   echo (market/holiday; rain start-times); fixed in Phase 0.
6. **Acclimation decay is entirely absent** — nothing gets quieter as the user
   becomes a local. A day-200 resident gets day-1 nagging.

---

## Part III — Phased plan

Sequenced for **standalone value at each stop** — the user can halt after any phase
and Today is strictly better. Backend-first, deterministic, no LLM required (an LLM
can later *narrate* fused needs but never *choose* them). Each slice ships with Pest
coverage, Pint, staging verify — the house rules.

### Phase 0 — Cleanup & honest baseline — **SHIPPED**

Cheap, reversible, no new machinery; buys immediate quiet and honesty.

- ✅ **Floor violation** — demotion immunity keyed on consequence, not severity.
  A dismissed urgent/critical legal deadline can no longer be buried.
- ✅ **Market/holiday dup** — the bus `market_closure` tile skipped; `rhythmTiles()`
  owns closures live.
- ✅ **Rain dup** — rain identity is the day, not the drifting forecast start time.
- ✅ **`buergeramt_slot` verified dead → removed** — nothing produces it (the live
  slot-checker was removed; booking is now a static task-card deep-link). The dead
  `actionToTile` arm is deleted, with a note on how to re-gate it (on an open office
  task) if a real slot source ever returns.
- ✅ **Pure-noise tiles demoted to Alerts-only** — `RhineEvaluator` and
  `TransitDelayEvaluator` no longer deliver `CHANNEL_DASHBOARD`; they stay on the
  Alerts record (a major ≥30-min delay keeps push). Their `actionToTile` arms remain
  as dormant rendering for the Phase 2 return. Advisory-rain waits for Phase 2
  (can't yet detect "without a plan").

### Phase 1 — The need model (structural foundation)

Introduce the vocabulary the rest depends on, mostly a refactor with little visible
change. Make each existing tile *declare* its consequence-class and lifecycle stage.

- `ConsequenceClass` enum — `PlanAtRisk | WindowClosing | CaughtOut | Opportunity |
  OnTrack`. Add to `Tile`.
- `LifecycleStage` — `dormant | ripe | peaking | lapsed`. Generalise the
  bureaucracy tier logic (`UserTask::deadline_status`) into a shared notion so any
  need can be "not yet." Tiles for `dormant` needs are withheld (the honest
  "not yet"), not shown-at-low-score.
- Promote `DEMOTE_IMMUNE_TYPES` into a consequence-derived floor (immunity follows
  from `WindowClosing`/`CaughtOut` legal needs, not a hardcoded type list).
- **Tests:** each tile maps to the right class/stage; a `dormant` need is withheld;
  the floor still holds via consequence.

### Phase 2 — The fusion organ (highest value) — **weather cut SHIPPED**

Intersect signals × the user's actual day. This is the differentiator and the
largest slice; split it so early wins land first.

- ✅ **2a — `HomeContext` carries "the day":** the pinned Today plan's slots
  (`TodayPlanStore`) + `arrive_by` commute anchors active today, with
  `outdoorExposureAfter($time)` as the intersection query. (Saved/intended events
  fold in with 2e.)
- ✅ **2b — fusion for weather:** advisory rain becomes a tile only when
  `outdoorExposureAfter(rainStart)` finds an outdoor plan stop or a commute;
  otherwise Alerts-only. The tile names the exposure. Lives as `TileComposer::
  weatherTile()` for now; extract a `NeedFusion` service when 2d needs it too.
- ✅ **2c — `weather_alert` split by kind:** `CheckWeatherAlerts` tags rain
  `advisory` (+ `from` time) vs ice/heat/wind `hazard`; hazards tile
  unconditionally, advisories go through 2b.
- ✅ **2d — `transit_disruption` × live route:** the evaluator gates Today on the
  disruption touching the user (their line / a saved place / a critical strike —
  killing the major broadcast-to-all) and records `on_user_line`;
  `HomeContext::hasLiveCommuteToday()` + `disruptionTile()` escalate the subtitle
  (on your line + a commute today → "leave early or reroute"). Kept the tile-method
  pattern — the logic stayed small, so no separate `NeedFusion` service yet.
- ✅ **2e — `tonight_events` gated on intent:** the urgent tile fires only for an
  event the user has an `EventReminder`/`EventAttendee` for (`HomeContext::
  intendedEventIds`), soonest within the 2h window, still `claim()`-ed so the rail
  won't repeat it. The rail's browse pool is untouched — it keeps owning discovery.
- ☐ **2f — consequence-grouping (optional, hard):** rain + disruption on the same
  plan-slot → one "your evening is shaky" need. Deterministic but mis-groupable;
  ship last, behind the rest.
- Tests shipped for the weather cut: signal × plan → need; signal × commute → need;
  no-exposure → Alerts-only; hazard bypasses intersection; kind/`from` tagging.

### Phase 3 — The acclimation loop (learning)

- Capture the full resolution vocabulary: extend `TileTriageController` to record
  *why* (already-handled vs not-relevant) and capture `acted` (take-me-there taps
  already flow through `/api/track`).
- Learn at `need-class × consequence × context` grain (generalise
  `dismissPenalties`), not per-type.
- **Cohort priors** table/service → personal posterior; solves cold-start and makes
  Today smart on day 1.
- **Acclimation decay:** rhythm/closure needs quiet as `already-handled` accrues;
  the consequence floor holds (legal needs never silenced).
- Rationed exploration + consequence-as-ground-truth for the silence blind spot.
- **Tests:** the six resolution modes drive the right correction; cohort prior used
  when personal signal is thin; decay quiets a repeatedly-handled type; the floor
  survives N dismissals.

### Phase 4 — The brief UI (presentation) — **SHIPPED**

- `dashboard.tsx` "Right Now" is now the brief (`Brief` + `LeadCard`/`NeedCard`/
  `AmbientRow`/`CalmCard`), replacing the flat 8-tile list. A tile's TYPE picks its
  lane: **Needs you first** (bureaucracy deadlines — lead, no triage), **Because of
  your day** (fused weather/transit/events — resolve-first, triage tucked to the
  corner), **Good to know** (ambient rhythm/river — dashed, dismiss-only). Nothing
  across all three → **Nothing needs you right now** (calm state).
- Verbs are **resolve-first** (`TILE_RESOLVE`: Book appointment · See routes · Plan
  around it · Take me there); the triage trio stays as the escape hatch, not the lead.
- The lead is set apart by colour, not size — same footprint as a need row, with a
  consequence-graded wash + solid glyph + countdown flag. Multiple deadlines stack in
  "Needs you first", **colour-graded red (overdue) → amber (upcoming)**; the flag
  carries the precise days so colour stays a coarse monotonic signal.
- Backend contract unchanged (still the flat scored `tiles` array); the hierarchy is a
  pure view concern. Reuses the existing triage plumbing + `Deferred` boundary.
- **Tests:** `tests/Browser/dashboard.spec.ts` asserts the brief renders (a lane label
  or the calm state) with no JS errors. Prototype approved first (`prototype/today-brief-v4.html`).
- **Follow-up:** the bureaucracy-deadline subtitle is just the countdown label (the flag
  covers it, so it's suppressed on the lead) — enrich it backend-side with the richer
  "14-day deadline is Wednesday, slots open 08:00" line the prototype showed.

### Recommended path

Phase 0 (finish the two ☐ items — an afternoon) → **Phase 2a+2b+2c** is the
value core (fusion turns the biggest set of tiles from noise into needs). Phases 1,
3, 4 are real but larger; 1 can fold into 2 if we want to move fast, 3 and 4 are the
"day-90 smart" and "reads like a brief" payoffs and can follow once fusion proves
out on staging.

**Guardrail:** the whole redesign is only safe because the Alerts page remains the
complete, lossless record. Never drop a signal entirely — demote it off Today, keep
it on Alerts.
