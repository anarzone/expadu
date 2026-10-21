# AI in Expadu: roles, rules and build slices

21 Oct 2026. Sits next to `2026-10-19-mock-to-app-build.md`. Written with the owner one role at a time; roles 2 and 3 are still to discuss.

## Rules for every role

- AI only rewrites, translates or ranks real data. It never invents times, reasons, prices, legal outcomes or legal figures. Figures come only from source text or source-checked card text.
- Anything a deterministic rule can decide (a price, "cancelled", a delay in minutes) is decided in code, not by the model.
- Every AI feature works without a key or with a failed call: the source text (or the rule-based result) shows instead.
- Models: Claude Haiku 5.5 for high-volume rewriting and translation; Sonnet 5.5 or Opus 5.5 only where a role needs the reasoning.
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

## Role 2: Composer's brain and analyser, also in Paperwork (to discuss)

## Role 3: Today, personalised to the user's steps (to discuss)

## Build slices

| Slice | Role | What | State |
| --- | --- | --- | --- |
| R1a | 1 | Event translation on Haiku 5.5, grounded chips and tips | On staging (#65, #142) |
| R1-data | 1 | KVB notices (#140), koeln.de once (#143), unlisted (#150), cancelled (#153) | Launch / in review; on staging |
| R1b | 1 | Transport briefs: `notice_briefs`, writer, command, `brief` on notices. Live KVB run: 42 notices briefed from 28 calls, $0.006, all checks clean | This PR |
| R1b-UI | 1 | Show briefs in Travel, Alerts, Change of plan | Design session |
| R1-de | 1 | German briefs and translations | Later |
