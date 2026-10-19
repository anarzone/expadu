# LLM Integrations

Last updated: 2026-10-18 (rewritten for EXP-119)

The 6 April version of this page was a pre-pivot wish list (GPT-4o-mini,
"never on the hot path"). It no longer matched the code: the Composer ranker
now runs synchronously while a plan is composed. That version is in git
history.

For gaps, data residency and the proposed ledger, read
[AI data readiness and recommendation/notification/Today groundwork — Oct 2026](https://docs.anar.to/books/architecture/page/ai-data-readiness-and-recommendationnotificationtoday-groundwork).

## What is wired today

There are four clients. Each has a kill switch and a deterministic fallback,
and each is off by default.

| Feature | Code | Provider and default model | Kill switch (default) | Runs | Sends |
|---|---|---|---|---|---|
| Event classification and translation | `app/Services/AnthropicEventClassifier.php`, called from `ProcessEventJob` | Anthropic, `ANTHROPIC_MODEL` (`claude-haiku-4-5-20251001`) | `EVENTS_CLASSIFICATION_ENABLED` (false) | queued job | public event text |
| Composer candidate ranker | `app/Composer/AnthropicCandidateRanker.php` | Anthropic, `COMPOSER_LLM_MODEL` (`claude-sonnet-5`) | `COMPOSER_LLM_ENABLED` (false) | **in the request**, 8 s timeout, result cached 10 min | up to 40 candidate places plus a preference summary; see the residency table on the AI page |
| Prompt parser | `app/Composer/OpenAiCompatiblePromptParser.php`; `HeuristicPromptParser` is the default | any OpenAI-compatible API, `LLM_BASE_URL` (DeepSeek) | `LLM_DRIVER` (`heuristic`) | in the request, 10 s timeout | the user's raw prompt text |
| Paperwork fact extraction | `app/Bureaucracy/Ai/DeepSeekCaseFactExtractor.php` | DeepSeek, `BUREAUCRACY_LLM_MODEL` | `BUREAUCRACY_LLM_ENABLED` (false) | in the request, 8 s timeout, daily per-user quota | user text, up to 4,000 characters |

The bindings live in `app/Providers/AppServiceProvider.php`, and the settings
in `config/services.php` and `config/events.php`.

## Rules that still hold

- **Closed sets and validation.** The ranker ranks only candidate ids that
  Expadu supplied. An answer with an unknown, repeated or missing id is
  discarded as a whole, and the deterministic order is used. The
  parser picks from closed category and area lists. Everything that comes back
  is checked against a tool or JSON schema.
- **Deterministic fallback.** When a key is missing, a call times out or the
  output is invalid, the deterministic path runs and the feature still works.
- **No AI-authored Paperwork content.** AI may extract facts for the user to
  confirm, or draft gap notes for human review. It never writes fees,
  deadlines or legal answers.
- **Untrusted input.** Place names, event text and descriptions are data, not
  instructions. The ranker's system prompt says so.
- **Cache arrays, not objects.** `config/cache.php` sets
  `serializable_classes => false`, so a cached PHP object comes back as
  `__PHP_Incomplete_Class` on every store.

## What changed since April

- **The ranker is on the hot path.** "Never on the hot path" no longer holds:
  Composer waits for the ranker, up to its 8 s timeout. Its output feeds the
  largest `PlanScorer` term (`llm_rank`, weight 45). See
  [composer-design.md](composer-design.md).
- **No ledger yet.** No LLM call is tied to an outcome. There is no prompt
  version stored with rankings or classifications, and no per-user on/off
  bucket. EXP-113 adds `llm_calls` and `composer_runs`.
- **Residency is undecided.** Which user data may leave the EU is EXP-118. The
  prompt parser and Paperwork extraction send personal text to DeepSeek, which
  is outside the EU, so both stay off until that decision is made.

## Where the April ideas stand

| April idea | Status |
|---|---|
| Event enrichment (category, English summary) | Built as the event classifier; off by default |
| German disruption text summarised in English | Not built; proposed as a cached "alert humaniser" |
| Generated notification text | Not built. The October review keeps LLMs out of the push path; times are never generated |
| Place descriptions | Not built. `spot_enrichments` exists but has no producer; EXP-115 adds deterministic `place_features` first |
| Letter translation and decoding | Not built; needs the residency decision and PII redaction |
| Paperwork assistant | Only grounded extraction; answers come from the verified catalogue, never from a model |
| Natural-language search | The Composer prompt parser covers intent; there is no full-text search index |
