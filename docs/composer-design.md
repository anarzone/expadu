# Day Composer v2 — Design & Architecture

**Status:** Proposal for owner review · 2026-06-13
**Companions:** expadu-v2-brief.md §4.2/§5.2 (the original composer spec) · system-design-bureaucracy-onboarding.md (the attribute engine it reads from)

---

## 0. Where we actually are (inventory, not imagination)

The pivot week already built the deterministic core — it exists in `app/Composer/` and is endpoint-wired:

| Piece | File | State |
|---|---|---|
| Constraints DTO (window/areas/categories/companions/budget) | `Constraints.php` | ✅ built |
| Parser contract ("parses, never picks"; profile-default fallback) | `Contracts/ParsesConstraints.php` | ✅ built |
| Anthropic parser implementation | `AnthropicConstraintParser.php` | ⏸ blocked on key — owner wants DeepSeek/GPT |
| Candidate repository (spots + curated events, cap 200) | `CandidateRepository.php` | ✅ built |
| Feasibility filter (pure, hard constraints) | `FeasibilityFilter.php` | ✅ built |
| Plan scorer (weights const, scores against plan state) | `PlanScorer.php` | ✅ built |
| Slot filler (anchors fixed events, greedy left→right, ≤6 slots) | `SlotFiller.php` | ✅ built |
| Swapper (one slot re-scored, neighbours frozen) | `Swapper.php` | ✅ built |
| Travel estimator (haversine heuristic) | `TravelEstimator.php` | ✅ built (upgrade path below) |
| Intent weights (user_events counts per category×Veedel) | `IntentWeights.php` | ✅ built |
| Endpoints parse/compose/swap + Redis plan state (72h TTL) | `ComposerController.php` | ✅ built |
| Composer page | `pages/composer.tsx` | ✅ built (v1 visuals) |

**So the v2 work is not "build a composer." It is: (1) swap the parser provider, (2) turn the prompt into the app's universal entrance, (3) make the plan aware of the rest of the app (appointments, weather, German rhythm, signals), (4) ship the result page that can also answer questions, (5) the search index both features share.**

---

## 1. Thesis — one brain, two front doors

The composer prompt box becomes the app's **universal entrance**: "plan my Saturday", "do I need an appointment for Anmeldung?", "basketball court near Ehrenfeld", "how do I get to the Ausländerbehörde". One box, four very different correct responses.

The rule that keeps this honest (extends the brief's "the LLM never picks venues"):

> **The LLM only classifies and parses. It never generates facts, never picks venues, never answers bureaucracy questions from its own knowledge.** Plans come from the deterministic pipeline; bureaucracy answers come from retrieval over OUR verified catalogue; findings come from the search index. When nothing matches, the answer is an honest "I don't know" plus the right page.

```
ONE prompt box (Today screen + composer page)
   │
   ▼  one cheap LLM call (structured output)
 { intent, payload }
   │
   ├─ plan_day        → existing pipeline: candidates → feasibility → score → slots
   ├─ bureaucracy_q   → retrieval over the user's verified tasks → answer card + deep link
   ├─ find            → search index (places, events, tasks, offices) → result list
   ├─ take_me_there   → geocode/office resolve → transit sheet
   └─ unknown         → plain search results + honest framing

 Global search (⌘K / search page) → SAME index, no LLM at all
```

**Degradation ladder** (LLM down / no key / slow):
1. Prompt heuristics first: starts with "how/what/do I/when/can I" + bureaucracy keywords → bureaucracy_q via search; contains a known place/event term → find. Time-words ("Saturday", "tonight", "tomorrow") → plan_day with profile-default constraints.
2. Worst case the box behaves as plain search. The product never shows a spinner that ends in an apology.

---

## 2. The parse call (the only LLM in the loop)

One call, one JSON schema, two jobs (intent + payload). Roughly:

```json
{
  "intent": "plan_day | bureaucracy_q | find | take_me_there | unknown",
  "plan":   { "window_start": "...", "window_end": "...", "areas": [],
              "categories": [], "companions": null, "budget": null },
  "query":  "normalized search terms (find / bureaucracy_q / take_me_there)"
}
```

- Prompt receives: now + timezone (relative dates), the user's Veedel + default areas, the allowed category/area vocabularies (closed lists — the model picks from them, it can't invent areas), companions hint if `child_born` is recorded.
- **Provider abstraction:** keep `ParsesConstraints`, generalize to `ParsesPrompt` returning `ParsedPrompt {intent, ?Constraints, ?string query}`. Implementations: `OpenAiCompatiblePromptParser` (one class covers DeepSeek AND OpenAI — both speak the same chat-completions + JSON-schema dialect; base URL + model from config) and the existing `FakePromptParser` for tests. `config/services.php`: `llm.driver`, `llm.base_url`, `llm.model`, `llm.key`. The Anthropic parser stays as a third driver nobody is forced to use.
- Budget: parse calls are short (≤500 chars in, ~200 tokens out). At DeepSeek pricing this is effectively free; no caching needed beyond a 60s dedupe on identical text.
- Failure → heuristic ladder above; the response carries `source: "llm" | "heuristic"` so the UI can show "interpreted roughly" framing when degraded.

---

## 3. Planning pipeline v2 — make the plan know the app

The pipeline stays as built. Five upgrades, all deterministic, in priority order:

1. **Appointments are anchors.** Before filling slots, query the user's `user_tasks.appointment_at` inside the window — a booked Ausländerbehörde appointment becomes a fixed slot ("🏛️ Your permit appointment — 09:40, originals packed?") that leisure fills around, with travel buffers on both sides. This is the cross-feature moment nobody else can copy: the app that knows your Saturday ALSO knows your Tuesday appointment.
2. **German-rhythm guards.** `GermanHolidayService` already knows holidays: planning on a Sunday/holiday demotes shopping-adjacent categories and injects a notice chip ("Sunday — shops closed, parks and museums it is"); the eve of a holiday adds the "buy groceries today" warning tile to the plan footer.
3. **Weather as a scorer input (already wired) + as a narrative chip.** Rain kills outdoor scores (exists); v2 also surfaces *why*: "rain at 15:00 — indoor after lunch" as a plan chip, sourced from the same WeatherService call.
4. **Signals loop closes.** `IntentWeights` (counts per category×Veedel from `user_events`) exists; v2 feeds three more signal types into it: take-me-there taps (strongest), event reminders set, composer swap-aways (negative). All already tracked in `user_events` — this is aggregation, not new infra. The post-trip 👍👎 from the P1 list plugs in here later.
5. **Travel times: heuristic in the loop, real on tap.** Keep haversine inside scoring (fast, deterministic). When the user taps a slot's "take me there", the real Transitous journey replaces the estimate and the UI quietly corrects the connector ("≈12 min → 14 min via U5"). A full RouteService matrix inside scoring stays P-later; the heuristic is fine for ranking.

**Explicitly out (unchanged from the brief):** ML, >72h windows, weekly planning, beam search until greedy demonstrably feels samey (the Swapper usually fixes "samey" cheaper).

---

## 4. Bureaucracy answers — retrieval, never generation

"Do I need an appointment for Anmeldung?" must be answered by **the user's own verified card**, not by a model's memory of German bureaucracy.

- **Retrieval target:** the user's applicable tasks + info cards (their computed path — already personalised by the attribute engine), ranked by the search index. The verified Anmeldung task wins on "anmeldung/appointment/register".
- **Answer card:** the task's own content — title, the relevant how-to step, documents count, `verified_at` badge — plus "Open in your checklist →" (`/bureaucracy?focus={id}`, the deep-link built this week). Optional polish: the LLM may *rephrase the retrieved text* into one lead sentence, clearly grounded ("From your checklist, verified 11 Jun: …"). The displayed substance is always the card.
- **No hit:** "I don't have a verified answer for that" + bureaucracy page link + the Ausländeramt contact route. Never improvise. This is the translator-tab lesson applied in advance.
- Out of scope here: free-form legal Q&A, letter translation (returns with its own feature when the key lands).

---

## 5. The shared retrieval layer (= global search, same build)

One index, two entrances (prompt + ⌘K). Decision from the earlier session stands: **no Elasticsearch.**

- **Engine:** Laravel Scout with Postgres full-text (`tsvector`) + `pg_trgm` for typo tolerance. Corpus is hundreds of tasks/cards + thousands of spots/events — Postgres laughs at this. Scout keeps Meilisearch as a config-swap escape hatch.
- **The searchable-entity contract** (the "composer in mind" interface agreed earlier): every content type exposes `{type, title, keywords, body, deep_link, veedel?, time_window?, lat/lng?}`. Implemented by Task (title+description+documents+synonyms), Spot, Event, Office. The same rows serve: search results, composer candidates (spots/events already are), and bureaucracy-answer retrieval (tasks).
- **The synonym layer is the real quality lever:** newcomers type in three languages. A curated map (anmeldung=registration=register address; arzt=doctor; führerschein=driving licence=license; kita=daycare=childcare…) lives in config, applied at index time. Worth more than any engine choice.
- **Personalisation:** search over tasks is filtered to the user's applicable path first (their engine-computed subset), full catalogue as a second group ("not on your path").

---

## 6. UI (see `prototype/composer-v2.html`)

- **Today screen:** the prompt box with example chips ("Free Saturday afternoon", "Meet people tonight", "Something with the kids tomorrow" — the kids chip only when `child_born` recorded: the profile reaches the chips too).
- **Composer page, plan result:** parsed-constraint chips (editable — tap to change window/area/companions, recompose), then the timeline: slots with travel connectors, per-slot swap (↻) and take-me-there (🚌), woven-in appointment slots styled as anchors (🏛️, not swappable), rhythm/weather notice chips, and the plan footer ("save to Today" / "start over").
- **Bureaucracy answer result:** verified answer card as in §4 — visually a sibling of the task card, not a chat bubble. No avatars, no typing dots: this is a tool, not a chatbot.
- **Find result:** compact ContentCard list (the shared card language from Places/Events).
- **States:** parsing (one quiet line, no fake progress), degraded ("interpreted roughly — edit the chips"), empty window ("nothing fits 22:00–23:00 in the rain — here's tomorrow morning"), LLM-down (search results + plain-language note).

---

## 7. Build order (slices, each shippable)

1. **Provider swap + parse hardening** — `ParsesPrompt` contract, OpenAI-compatible driver (DeepSeek/GPT via config), heuristic fallback ladder, intent classification in the same call. *Blocked only by the key — everything else can merge behind the fake driver.*
2. **Search index** — searchable contract + Scout/Postgres + synonyms + ⌘K page + `find` intent. Ships value standalone even before the composer UI.
3. **Bureaucracy answers** — retrieval + answer card + focus deep-link reuse. (Small: the content and the deep-linking already exist.)
4. **Plan v2** — appointment anchors, rhythm guards, weather chips, signal aggregation. Pure-function changes with fixture tests.
5. **UI v2** — the prototype's result-page variants on the existing composer.tsx; Today prompt box becomes the universal entrance.
6. **Later, evidence-driven:** beam search, LLM narrative, RouteService matrix, post-trip 👍👎.

## 8. Testing strategy

- Parser: contract tests against the fake; one recorded-fixture test per provider driver (Http::fake); heuristic ladder unit tests (the degradation path gets the SAME coverage as the happy path).
- Pipeline v2: extend the existing pure-function fixtures — appointment-anchor immovability, Sunday demotion, signal-weight monotonicity.
- Bureaucracy answers: table-driven — question → expected task key surfaced; "no hit" honesty case.
- Search: ranking fixtures (typo "anmedlung" → Anmeldung task; "basketball" → courts before events).
- E2E: prompt → plan with woven appointment; prompt → verified answer; ⌘K → result navigation.

## 9. Open decisions for the owner

1. **Provider + key** — DeepSeek (cheapest, JSON-mode solid) vs GPT-4o-mini class. The driver covers both; pick by key you'd rather manage. *(Blocks slice 1.)*
2. **Events source for "meet people tonight"** — current curated/manual set is thin on prod (seed commands!). Composer quality is capped by candidate quality.
3. **Narrative sentences** — on or off for v1? My recommendation: off; the chips + timeline already explain themselves, and it's one more LLM surface to trust-check.
4. **Today-screen prompt placement** — replace the current dashboard header area or sit under it? Prototype shows my proposal (top, above tiles).

---

*The one-line version: the composer is not a chatbot — it's the app's intent router. The LLM translates; verified content answers; the deterministic pipeline plans; and everything the bureaucracy engine learned this week (appointments, life events, the user's path) makes the plan smarter than anything a maps app can offer.*
