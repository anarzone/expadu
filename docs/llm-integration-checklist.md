# LLM Integration Checklist

Areas where LLM (GPT-4o-mini, Claude Haiku, or similar) can add real value to Expadu. Ordered by impact and feasibility.

## Guiding Principles

- **Never on the hot path** — Don't add LLM calls to page loads or real-time recommendation engine. Use batch/async only.
- **Cheap models first** — GPT-4o-mini or Claude Haiku for batch processing (~$0.15/1M input tokens). Reserve expensive models for accuracy-critical features.
- **Structured output** — Always request JSON output from LLM calls for reliable parsing.
- **Cache aggressively** — LLM results for the same input should be cached (events don't change, letters don't change).
- **Fallback gracefully** — If LLM is down or slow, the app must still work with rule-based defaults.

---

## Batch Processing (async, no user waiting)

### 1. Event Enrichment
- [ ] Parse scraped German event descriptions into structured data
- [ ] Extract: category, language, indoor/outdoor, expat-friendliness, age range
- [ ] Generate English summary from German event text
- [ ] Score per-user relevance based on interests and past attendance
- [ ] **Where:** `events:enrich` command (already runs every 15 min)
- [ ] **Model:** GPT-4o-mini or Claude Haiku
- [ ] **Priority:** High — directly improves event recommendations

### 2. News & Disruption Summarization
- [ ] Summarize verbose German KVB disruption texts into actionable English
- [ ] Extract: affected lines, duration, suggested alternatives
- [ ] Example: "Line 12 suspended Ebertplatz→Chorweiler until 14:00, use Line 15" from wall of German text
- [ ] **Where:** `news:scrape` command (runs every 5 min)
- [ ] **Model:** GPT-4o-mini
- [ ] **Priority:** High — expats can't read German disruption notices

### 3. Smart Notification Text
- [ ] Generate contextual, personal notification messages instead of templates
- [ ] Example: "Heading to your German course? Leave by 10:15 — overcast but dry, bike is fastest"
- [ ] Pre-generate during scheduled command runs (not real-time)
- [ ] **Where:** `commute:send-leaveby-reminders` and other notification commands
- [ ] **Model:** GPT-4o-mini
- [ ] **Priority:** Medium — nice UX polish, not critical

### 4. Spot Description Generation
- [ ] Generate English descriptions for curated spots (cafes, coworking, libraries)
- [ ] Include practical info: "quiet café with fast WiFi, popular with remote workers, cash only"
- [ ] One-time batch for existing spots, then on new spot creation
- [ ] **Where:** Admin/seeder script
- [ ] **Model:** GPT-4o-mini
- [ ] **Priority:** Low — spots already have structured data

---

## User-Initiated (on-demand, user waits but expects it)

### 5. German Letter/Document Translation
- [ ] User uploads or photographs official German letter (Finanzamt, Krankenkasse, Ausländerbehörde)
- [ ] LLM translates content + explains what action is needed + deadline
- [ ] Highlight urgency level (immediate action, informational, deadline-based)
- [ ] Store translated letters in user's document history
- [ ] **Where:** New feature — dedicated page/modal
- [ ] **Model:** Claude Sonnet or GPT-4o (accuracy matters for legal/official documents)
- [ ] **Priority:** Very High — killer feature for expats, high retention value

### 6. Bureaucracy Assistant (Conversational)
- [ ] Context-aware Q&A: "I'm a non-EU freelancer, visa expires in 3 months, what do I need?"
- [ ] Personalized based on user's situation, arrival date, completed tasks
- [ ] Suggest next steps from settlement checklist
- [ ] RAG with German bureaucracy knowledge base (visa types, Anmeldung, insurance rules)
- [ ] **Where:** New feature — chat interface or assistant panel
- [ ] **Model:** Claude Sonnet or GPT-4o (needs reasoning for complex situations)
- [ ] **Priority:** High — differentiator, but complex to build well

### 7. Natural Language Search
- [ ] "Where can I work quietly near Ehrenfeld with good WiFi?"
- [ ] "What events are happening this weekend for English speakers?"
- [ ] "How do I get to Mediapark from Nippes without tram line 12?"
- [ ] Convert natural language → structured query against spots/events/transit
- [ ] **Where:** Search bar enhancement
- [ ] **Model:** GPT-4o-mini (structured output extraction)
- [ ] **Priority:** Medium — nice but filters/categories work for most cases

---

## Not Worth LLM (keep rule-based)

These work well as deterministic logic — adding LLM would be slower, more expensive, and less reliable:

- Bike vs tram decision (weather + disruptions → simple scoring)
- Leave-by time calculation (math: arrive_by - travel_time)
- Holiday detection (lookup table)
- Card diversity/dedup (array filtering)
- Commute context detection (time + GPS + schedule → if/else)
- Notification throttling (Redis counters)
- GTFS departure lookups (database queries)

---

## Implementation Order (suggested)

| Phase | Feature | Effort | Impact |
|-------|---------|--------|--------|
| 1 | News & disruption summarization | Small | High |
| 2 | Event enrichment | Medium | High |
| 3 | German letter translation | Medium | Very High |
| 4 | Smart notification text | Small | Medium |
| 5 | Bureaucracy assistant | Large | High |
| 6 | Natural language search | Medium | Medium |
| 7 | Spot descriptions | Small | Low |

Phase 1-2 can share the same LLM integration infrastructure (API client, retry logic, cost tracking, structured output parsing). Build that foundation first, then the user-facing features become easier.
