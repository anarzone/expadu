# AI in Expadu: roles, rules and build slices

21 Oct 2026. Sits next to `2026-10-19-mock-to-app-build.md`. Written with the owner one role at a time. Roles 2 and 3 were settled on 21 Oct.

## Rules for every role

- AI only rewrites, translates or ranks real data. It never invents times, reasons, prices, legal outcomes or legal figures. Figures come only from source text or source-checked card text.
- Anything a deterministic rule can decide (a price, "cancelled", a delay in minutes) is decided in code, not by the model.
- Every AI feature works without a key or with a failed call: the source text (or the rule-based result) shows instead.
- Models: Claude Haiku 5.5 for every AI role. Owner, 21 Oct 2026: "Only haiku should be used for all places, no sonnet." `config/services.php` turns any non-Haiku `*_MODEL` setting back into Haiku.
- One `ANTHROPIC_API_KEY` for every environment. Each call is logged in `llm_calls` (feature, model, tokens, latency, outcome, cost). No user data in prompts, logs or URLs unless the EU data decision (EXP-118) allows it.
- Languages: English now; English and German later.

## Role 1: events and transport

Inputs are public data only (event listings, KVB notices, VRS delays), so role 1 isn't blocked by EXP-118.

### 1a. Event translation (live on staging)

- **What it does:** translates each listing's title and description into English, plus a two-sentence summary, an optional tip, a category, a relevance score and a language estimate.
- **Model and cost:** Claude Haiku 5.5, effort low. One call per new or changed listing, about $0.001 each. Prod's backlog of about 1,200 events costs about $1–2.
- **Never AI:**
  - The "free" chip comes from the source's price fields.
  - Tips may only restate the listing; a tip that hedges, or names a language the listing doesn't mention, is dropped in code.
- **Data it depends on (fixed 20–21 Oct):**
  - koeln.de stores each event once (re-published copies are hidden) and without page chrome.
  - Events the source stopped listing are set aside (`unlisted`).
  - Listings that say they're cancelled are `cancelled`, decided by rules.
- **No key or a failed call:** the German original shows. Two malformed replies flag the event for review; a refusal parks it until the source changes.

### 1b. Transport briefs (this slice)

- **What it does:** turns each KVB notice (German) into a one-line English headline, one or two plain sentences, and an impact type.
- **Example:**
  - KVB's text: "Linie 16: Baumaßnahme im Bereich HGK-SWB bis 02.11. ca. 03:00h. Linie 16 fährt zwischen …"
  - The brief: "Line 16: no trams between Ubierring and Bad Godesberg until about 03:00 on 2 Nov", then "Use replacement bus 116 between Ubierring and Bonn Hauptbahnhof."
- **Inputs:**
  - The KVB live notice (title + details from `city_news`, source `kvb-betriebslage`), and KVB news headlines (source `kvb`).
  - Not VRS: its live feed has delay minutes but no text, so delay wording stays rule-based ("about 12 min late"), not AI.
- **Outputs:**
  - Stored in `notice_briefs`, one row per notice text and language.
  - A notice copied for several lines (16 and 17) shares one brief.
  - `DisruptionService::getLineDisruptions()` adds a `brief` key (`headline`, `text`, `impact`) to each notice, or `null`. The other keys are unchanged.
- **When:**
  - `transit:brief-notices` runs every 5 minutes after `news:scrape` and queues a job on the `llm` queue for each open notice without a brief.
  - A notice is briefed again only when KVB changes its text.
- **Model and cost:** Claude Haiku 5.5, effort low, about 400 output tokens. About 40 notices at first, then a handful a day: cents per day. The cap is `TRANSIT_BRIEFS_DAILY_LIMIT` (default 300 calls); the switch is `TRANSIT_BRIEFS_ENABLED`.
- **Never AI:**
  - A time, date, reason, replacement, stop or line the notice doesn't give. Code throws away a brief and shows the German notice when:
    - it carries a number the notice doesn't;
    - it misspells a stop or street name (live run: "Venloer Straase");
    - it puts trams on a bus notice, or buses on a tram notice.
  - Sentences about the notice itself ("The notice gives no end time.") and advice it never gave ("check the KVB app") are dropped.
  - A short glossary pins KVB's terms ("in Höhe" = at, not opposite).
  - Push decisions: severity stays as it is, and `impact` is stored for later rules, not used yet.
- **No key, failed call or rejected brief:** `brief` is `null` and screens show KVB's own text.
- **Where it shows:** the design session's call. Travel's notices, the Alerts card and a trip's "Change of plan" can use `brief.headline` and `brief.text`, with the German original one tap away.

## Role 2: Composer's brain and analyser, also in Paperwork

### Owner decisions (21 Oct)

- **One provider:** every AI path moves to Claude, including the two former DeepSeek paths (Composer's request reader and Paperwork's fact extractor).
- **EU residency (EXP-118):** set aside for testing; the first-party API is used. Revisit before real users.
- **Composer must be smart, not only an algorithm.** The design follows what works in leading products, as researched on 21 Oct.

### What the research says

- **Planning:** an LLM plans badly on its own. GPT-4 alone completed 0.6% of tasks on the TravelPlanner benchmark, against 93.9% when the LLM turns the request into constraints and a solver plans (arXiv 2402.01622, 2404.11891; LLM-Modulo, 2405.20625).
- **Grounding:**
  - Products that last ground every fact in their own data: Google's Maps grounding, Amazon Rufus "hydrating" answers from its catalogue, DoorDash's "code for facts, LLM for narrative".
  - Expedia reportedly retired Romie after trust broke on ungrounded answers.
- **Where the LLM earns its keep:**
  - understanding the request;
  - judgement between good options;
  - plain-language explanations (Spotify: 4x engagement when told why);
  - plain-language taste profiles that users can see and edit (DoorDash, Spotify Taste Profile).

### Composer: the pipeline

The solver core is code, with the LLM at the edges. One LLM call per plan by default, three at most.

| # | Stage | Who | What it does |
|---|---|---|---|
| 1 | Understand | Haiku 5.5, structured output | Request → typed constraints. Each is marked hard or soft: categories from the catalogue enum, time window, companions, budget, needs such as step-free. Also mood words ("quiet", "cosy") and, for follow-ups, edit operations. The heuristic stays the fallback. Cached by normalised text. |
| 2 | Context | code | Hourly weather, holidays, events, saved needs, the taste profile. |
| 3 | Retrieve | code | Replaces "nearest 12 per category". Candidates are open in the window, reachable (MOTIS travel time) and pass the hard filters. They are matched on tags and place descriptions, weighted by the user's affinities, a quality prior, and one or two exploration picks. About 150 candidates. |
| 4 | Score | code | Today's weighted scorer, then a learned ranker once logs exist. |
| 5 | Assemble | code (solver) | 3–5 plans that are all feasible: dwell time per kind, real travel legs, opening hours, event start times, meal and nap times, more indoor stops when rain is forecast, variety. Feasibility is owned here, never by the LLM. |
| 6 | Judge | Haiku 5.5, strict JSON | Picks and reorders among the plans, and may swap in an alternate. It sees the request, constraints, taste profile, plans and about 20 alternates, all as IDs plus facts. It returns IDs only, from a per-request allow-list, plus reason codes and one plain line per stop. It runs only when needed: mood words, a taste profile to weigh, or near-tied plans. |
| 7 | Verify | code | Re-checks every swap against hours, travel and hard needs. On a violation: one repair prompt, then the solver's own plan. |
| 8 | Hydrate | code | Names, times, hours, prices and travel come from the database, never from the model. "Why" chips are built from reason codes ("Open till 18:00 · 12 min, tram 13 · indoors"). A linter drops any model sentence that carries digits, €, weekdays or clock times. |
| 9 | Refine | Haiku 5.5 | "Swap the museum" or "make it cheaper" becomes a typed change to the plan. The solver re-plans only the stops it touches and keeps the rest. |

- **Offline enrichment:** Haiku (Batch API) writes a short description and vibe tags for each place. Every attribute carries its evidence (an OSM tag or source text) or "unknown". This feeds stage 3 matching and the stage 6 lines.
- **Evaluation:**
  - A set of about 200 Cologne requests across user types.
  - Release gates must be zero: unknown IDs, facts that don't match the database, infeasible plans.
  - Plans are graded head-to-head against our own labels, with Haiku 5.5 as the grader where a model is needed.
  - Gate checks run before and after each slice.
- **Cost:**
  - Understand: about $0.0003 per request.
  - Judge: about $0.001–0.002 on Haiku when it runs.
  - Enrichment: a one-off of a few dollars for about 12,000 places on the Batch API.
- **Never AI:** places, times, opening hours, prices, travel minutes, legal facts, feasibility.

### Paperwork

- **Fact extraction:** now Claude (Haiku 5.5). Same per-request consent gate, the same exact-key and candidate validation, and the person still confirms every value.
- **The "analyser" for Paperwork:** questions are answered only from the source-checked cards (citing the card), and the case's own facts are explained back. It never answers from the model's own knowledge, and figures come only from card text. This slice comes after Composer's evaluation set exists.

## Role 3: personalisation from the user's steps, across the app

**The owner's framing:** "steps" means behaviour everywhere in the app. First build a ranking system that tracks behaviour, then an AI recommendation layer on it, later used for notifications and to fill Today.

### What exists to build on

- `user_events`
- Server-side `TasteSignals` (trip started, event saved). These carry only a category and a Veedel, with no item ids, deliberately.
- `IntentWeights` (category × Veedel counts fed into the scorer)
- `spot_feedback` ("more like this" / "not for me")
- Event bookmarks, saved journeys, `composer_runs`

### Design

- **Signal ledger.** Emitted server-side where an action happens, plus allow-listed client events:
  - Impressions, logged with their position and surface; without these, missing items look like rejections.
  - Detail viewed for 10 seconds or more, save, "Get there", trip taken (with location consent), plan kept unedited, a stop swapped out, "Not for me", "Not now".
- **Weights and decay** (starting values, tuned against retention, not clicks):

  | Signal | Weight |
  |---|---|
  | Trip taken | +5 |
  | Save, "Get there" | +3 |
  | Each stop of a plan kept unedited, share | +2 |
  | Detail view | +1 |
  | Stop swapped out | −2 for that place in that context |
  | "Not for me" | −4, hidden 90 days |
  | "Not now" | −1, hidden 7 days |
  | Shown 3 times, never touched | −0.3 |

  Half-lives: tastes 60 days, place negatives 90 days, session intent a few hours. Affinities are kept separately by context: weekday or weekend, alone or with family, weather.
- **Aggregates:**
  - Per-user affinities by category, Veedel, price band, time of day and context.
  - Per-place negatives.
  - Live counters, recomputed nightly.
- **Cold start:**
  - The request itself.
  - Then priors from the situation profile (household, arrival date, home Veedel).
  - Popularity among similar users.
  - Exploration by Thompson sampling with informed priors: one labelled "something new" stop per plan, and 10–20% of Today.
- **Taste profile (nightly):**
  - Code computes the facts.
  - Haiku (Batch API) writes 3–5 plain sentences plus tags, only for users whose signals changed.
  - The user can see and edit it. Hard needs stay code filters, never prose.
  - It feeds Composer (retrieve and judge), Today's recommendations, and later notifications through the push policy.
- **Privacy:**
  - Personalisation is a separate opt-in. A non-personalised mode works fully.
  - Raw events are kept 90 days; aggregates sit under a pseudonymous id.
  - Raw GPS never reaches the model.
  - AI is disclosed where it's used (EU AI Act, Art. 50).
- **Measured by:**
  - Plans kept unedited, edits per plan, "Get there" starts, trips, saves, 7- and 28-day return.
  - Guardrails: dismissals, "wrong info" reports, p95 latency under 3 s, cost per plan, the share of views going to the top 1% of places.
  - At low traffic: interleaving and log replay.

## Build slices

| Slice | Role | What | State |
| --- | --- | --- | --- |
| R1a | 1 | Event translation on Haiku 5.5, grounded chips and tips | On staging (#65, #142) |
| R1-data | 1 | KVB notices (#140), koeln.de once (#143), unlisted (#150), cancelled (#153) | Launch / in review; on staging |
| R1b | 1 | Transport briefs: `notice_briefs`, writer, command, `brief` on notices. Live KVB run: 42 notices briefed from 28 calls, $0.006, all checks clean | This PR |
| R1b-UI | 1 | Show briefs in Travel, Alerts, Change of plan | Design session |
| R1-de | 1 | German briefs and translations | Later |
| R2a | 2 | DeepSeek → Claude: Composer request reader and Paperwork fact extractor | In progress |
| R2b | 2 | Composer evaluation set (about 200 requests) and release gates; baseline measured before any change | Next |
| R2c | 2 | Understand v2: hard and soft constraints, mood words, edit operations (Haiku) | |
| R2d | 2 | Place enrichment: descriptions and vibe tags with evidence (Haiku, Batch API) | |
| R2e | 2 | Retrieve v2 and a solver that returns 3–5 feasible plans | |
| R2f | 2 | Judge (Haiku), verify and repair, hydrate, reason chips, number linter | |
| R2g | 2 | Refine: follow-up edits as typed plan changes | |
| R2h | 2 | Paperwork analyser, grounded only in source-checked cards | |
| R3a | 3 | Signal ledger, impressions with position, weights, decay, aggregates | |
| R3b | 3 | Nightly taste profile (Haiku), visible and editable | Profile page: design session |
| R3c | 3 | Recommendations into Composer and Today, with exploration | |
| R3d | 3 | Notifications from recommendations through the push policy | Later |
