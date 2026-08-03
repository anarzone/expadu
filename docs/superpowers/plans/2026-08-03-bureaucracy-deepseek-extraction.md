# Bureaucracy DeepSeek Fact Extraction Implementation Plan

> **For Codex:** Execute only after deterministic structured intake is green. Use `superpowers:subagent-driven-development`. Runtime DeepSeek is an optional extractor; it is never a legal researcher, rule selector, question writer, deadline calculator, or plan composer.

**Goal:** Add opt-in DeepSeek V4 Flash free-text extraction and neutral confirmed-fact summaries with strict schemas, explicit confirmation, rolling quotas, minimal context, audit metadata, and deterministic fallback.

**Architecture:** A dedicated bureaucracy extraction contract sits at an external-system boundary. The backend selects one registered fact target and supplies its exact schema. DeepSeek returns a candidate value or an off-topic/unknown outcome. Server validation records candidates only; existing confirmation endpoints remain the sole path to authoritative facts and plan reassessment.

**Tech stack:** Laravel HTTP client, OpenAI-compatible chat-completions API, Laravel config/rate limiting, encrypted Eloquent casts, Pest HTTP fakes, Inertia/React.

**Depends on:** Deterministic foundation and guided UI plans completed. The product must remain complete when every bureaucracy AI config value is absent.

**Provider decision:** Configure a generic OpenAI-compatible endpoint. Default `BUREAUCRACY_LLM_ENABLED=false`; default model identifier `deepseek/deepseek-v4-flash`; do not assume or hardcode an intermediary provider URL. Deployment must supply the approved processor's base URL and key after privacy review. Generate migrations with Artisan and rename them to the exact planned paths below before editing if its timestamp differs.

---

### Task 1: Define the extraction-only contract and strict result types

**Files:**

- Create: `app/Bureaucracy/Ai/Contracts/ExtractsCaseFact.php`
- Create: `app/Bureaucracy/Ai/CaseFactExtractionRequest.php`
- Create: `app/Bureaucracy/Ai/CaseFactExtractionResult.php`
- Create: `app/Bureaucracy/Ai/UnavailableCaseFactExtractor.php`
- Create: `tests/Unit/Bureaucracy/CaseFactExtractionContractTest.php`

**Step 1: Write failing contract tests**

The request contains exactly: target fact key, manually authored question, allowed schema/options, the user's current message, locale, and only confirmed facts explicitly listed as dependencies by the target definition. It must reject arbitrary context, task text, deadlines, source URLs, plan sections, and undefined fact targets.

The result is one of:

```php
Candidate(value: mixed)
Unknown()
OffTopic()
Unavailable(reason: string)
Invalid(reason: string)
```

No result type may contain free-form model prose, a task key, rule key, deadline, document, fee, source, eligibility conclusion, or question text.

**Step 2: Run RED**

Run: `php artisan test --compact tests/Unit/Bureaucracy/CaseFactExtractionContractTest.php`

**Step 3: Implement readonly DTOs and disabled fallback**

`UnavailableCaseFactExtractor` always returns `Unavailable('not_configured')`. Do not expose any model-written acknowledgement; candidate/unknown UI copy is a fixed backend translation string.

**Step 4: Run GREEN and commit**

Commit: `feat(bureaucracy): define extraction-only AI contract`

---

### Task 2: Implement the DeepSeek OpenAI-compatible extractor

**Files:**

- Create: `app/Bureaucracy/Ai/DeepSeekCaseFactExtractor.php`
- Create: `app/Bureaucracy/Ai/CaseFactToolSchema.php`
- Modify: `config/services.php`
- Modify: `.env.example`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `tests/Feature/Bureaucracy/DeepSeekCaseFactExtractorTest.php`

**Step 1: Write HTTP-faked tests before implementation**

Use `Http::preventStrayRequests()` and test:

- valid forced-tool JSON for enum, boolean, integer, and date facts;
- a value outside the target enum;
- a second/undefined fact injected into arguments;
- arguments containing a task, deadline, document, fee, citation, or eligibility field;
- non-tool prose response and malformed JSON;
- prompt-injection text asking the model to ignore the schema or answer unrelated questions;
- off-topic classification;
- timeout, connection failure, 429, 4xx, and 5xx;
- unexpected free-form acknowledgement/prose in the tool arguments;
- only minimum dependency facts appear in the outgoing request;
- no real network request occurs in tests.
- enabled config without a processor display name or privacy-policy URL still binds the unavailable extractor so consent can never be collected against an unidentified processor.

Invalid/unavailable outcomes must not throw into the page flow.

**Step 2: Run RED**

Run: `php artisan test --compact tests/Feature/Bureaucracy/DeepSeekCaseFactExtractorTest.php`

**Step 3: Add disabled-by-default config**

```php
'bureaucracy_llm' => [
    'enabled' => env('BUREAUCRACY_LLM_ENABLED', false),
    'base_url' => env('BUREAUCRACY_LLM_BASE_URL'),
    'model' => env('BUREAUCRACY_LLM_MODEL', 'deepseek/deepseek-v4-flash'),
    'key' => env('BUREAUCRACY_LLM_KEY'),
    'processor_name' => env('BUREAUCRACY_LLM_PROCESSOR_NAME'),
    'processor_privacy_url' => env('BUREAUCRACY_LLM_PROCESSOR_PRIVACY_URL'),
    'timeout' => env('BUREAUCRACY_LLM_TIMEOUT', 8),
    'prompt_version' => 'bureaucracy-fact-v1',
    'daily_message_limit' => env('BUREAUCRACY_LLM_DAILY_LIMIT', 20),
],
```

Document names only in `.env.example`; never add a key. Preserve the user's existing dirty config/env edits.

**Step 4: Implement forced structured extraction**

Use explicit `connectTimeout(3)`, configured timeout, temperature `0`, one forced function/tool named `extract_authorized_fact`, and a schema with only `domain`, `outcome`, and the single target `value`. The system instruction must state that no legal question may be answered and no fact beyond the supplied target may be returned.

Validate the response again through `FactRegistry`; model schema is not trusted. Log only structured failure metadata, never raw message/output. Bind the DeepSeek implementation only when enabled and endpoint/model/key/processor-name/privacy-URL values all exist; otherwise bind `UnavailableCaseFactExtractor`.

**Step 5: Run GREEN, format, and commit**

```bash
php artisan test --compact tests/Feature/Bureaucracy/DeepSeekCaseFactExtractorTest.php
vendor/bin/pint --dirty --format agent
```

Commit: `feat(bureaucracy): add guarded DeepSeek extraction`

---

### Task 3: Persist consent, bounded messages, and non-content run metadata

**Files:**

- Create with Artisan: `database/migrations/2026_08_03_220000_create_bureaucracy_case_messages_table.php`
- Create with Artisan: `database/migrations/2026_08_03_220001_create_bureaucracy_ai_runs_table.php`
- Create: `app/Models/BureaucracyCaseMessage.php`
- Create: `app/Models/BureaucracyAiRun.php`
- Create corresponding factories under `database/factories/`
- Create: `app/Bureaucracy/Ai/BureaucracyAiAuditRecorder.php`
- Create: `app/Bureaucracy/Ai/BureaucracyAiQuota.php`
- Create: `tests/Feature/Bureaucracy/BureaucracyAiPrivacyTest.php`

**Step 1: Write failing privacy and rolling-window tests**

Assert:

- no model call or message storage without `ai_consent_at`;
- withdrawing consent blocks future calls but does not alter the deterministic plan;
- message `content` uses an encrypted cast and has `expires_at <= created_at + 30 days`;
- unconfirmed AI candidate facts also expire in at most 30 days;
- audit rows store case ID, operation, model, prompt version, latency, token counts, validation state, fallback reason, and an expiry no later than 180 days, but no prompt, raw output, or message excerpt;
- quota counts only AI-assisted user messages created within the prior rolling 24 hours;
- exactly 20 are allowed by default, the 21st is rejected, and a message aged 24 hours plus one second leaves the window;
- quota is per user/case and also protected by a short per-IP/per-user burst limiter.

**Step 2: Run RED**

Run: `php artisan test --compact tests/Feature/Bureaucracy/BureaucracyAiPrivacyTest.php`

**Step 3: Implement schema and services**

`bureaucracy_case_messages`: case FK, role (`user`, `assistant`), encrypted content, operation, prompt version, `expires_at`, timestamps; index case/created_at and expires_at.

`bureaucracy_ai_runs`: case FK, operation, model, prompt version, latency_ms, input_tokens, output_tokens, validation_state, fallback_reason, `expires_at`, created_at; no `updated_at` and no content columns.

`BureaucracyAiQuota` performs the rolling database count and records usage only after consent and immediately before dispatch. Add `bureaucracy-ai-burst` in `AppServiceProvider`: five attempts/minute by authenticated user plus IP. The rolling 24-hour database quota remains authoritative for the 20-message product limit.

**Step 4: Run GREEN and commit**

Commit: `feat(bureaucracy): enforce AI consent and quotas`

---

### Task 4: Add the AI-assisted message endpoint without plan authority

**Files:**

- Create: `app/Http/Requests/Bureaucracy/StoreCaseMessageRequest.php`
- Create: `app/Http/Requests/Bureaucracy/UpdateAiConsentRequest.php`
- Create: `app/Http/Controllers/Bureaucracy/CaseMessageController.php`
- Create: `app/Http/Controllers/Bureaucracy/AiConsentController.php`
- Create: `app/Bureaucracy/Ai/ExtractCaseFactAction.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/RateLimitingTest.php`
- Create: `tests/Feature/Bureaucracy/CaseMessageEndpointTest.php`

**Step 1: Write failing end-to-end contract tests**

Routes:

```text
PUT  bureaucracy/case/ai-consent  bureaucracy.case.ai-consent.update
POST bureaucracy/case/messages    bureaucracy.case.messages.store
```

Test that the message endpoint requires the active backend-selected question ID and rejects a caller-supplied fact key. It must also reject a question that is answered, skipped, superseded, belongs to a different case, or is not the `next_question` for the active snapshot. The action resolves the current question's registered target, checks consent/quota, extracts, validates, records a candidate, and redirects with a confirmation payload. It never confirms a fact, increments fact version, calls `CasePlanComposer`, changes a snapshot, or writes a task.

For `Unknown`, `OffTopic`, `Unavailable`, `Invalid`, timeout, transport failure, or limit exhaustion, assert the response returns the same active snapshot and the same structured question/options. Off-topic copy is fixed server text: “I can only help clarify facts for your Expadu bureaucracy plan.”

**Step 2: Run RED**

Run: `php artisan test --compact tests/Feature/Bureaucracy/CaseMessageEndpointTest.php tests/Feature/RateLimitingTest.php`

**Step 3: Implement the action and routes**

Use Form Requests, named routes, `throttle:app-writes`, and `throttle:bureaucracy-ai-burst` on the message route. Return no model-written copy. Candidate confirmation uses the fixed server translation: “I found one detail for you to confirm.”

Regenerate Wayfinder: `php artisan wayfinder:generate --no-interaction`.

**Step 4: Run GREEN and commit**

Commit: `feat(bureaucracy): accept AI-assisted fact answers`

---

### Task 5: Add opt-in free text to the existing clarification card

**Files:**

- Create: `resources/js/components/bureaucracy/ai-consent-sheet.tsx`
- Modify: `resources/js/components/bureaucracy/case-question-card.tsx`
- Modify: `resources/js/components/bureaucracy/case-fact-review-sheet.tsx`
- Modify: `resources/js/components/bureaucracy/types.ts`
- Modify: `resources/js/pages/bureaucracy.tsx`
- Modify: `tests/Browser/bureaucracy-case-worker.spec.ts`

**Step 1: Add failing automated browser cases**

Cover consent decline, consent acceptance, candidate confirmation, candidate correction, off-topic redirect, model unavailable, invalid response, 20-message limit state, network/offline error, and page reload preserving the last deterministic snapshot. Assert that the structured input remains present and usable in every state.

**Step 2: Implement progressive enhancement only**

The free-text textarea appears below structured choices as “Describe it in your own words (optional).” First use opens a sheet that names the configured processor and model, links to the configured processor privacy policy, states what minimum text is sent, says raw text is retained up to 30 days, links to deterministic alternatives, and offers Accept/Not now. If either processor disclosure value is missing, free text remains unavailable.

After extraction, open the existing fact-review sheet. Do not update the plan until the user presses Confirm. Keep current Expadu sheet/card styling, Tabler icons, 44px controls, focus return, reduced motion, and bottom safe area.

**Step 3: Run frontend and browser checks**

```bash
npm run lint:check
npm run format:check
npm run build
npx playwright test tests/Browser/bureaucracy-case-worker.spec.ts --project=chromium --project=mobile
```

**Step 4: Commit**

Commit: `feat(bureaucracy): add optional free-text clarification`

---

### Task 6: Prune expiring AI data

**Files:**

- Create: `app/Console/Commands/Bureaucracy/PruneCaseAiDataCommand.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/Bureaucracy/PruneCaseAiDataCommandTest.php`

**Step 1: Write failing retention tests**

Freeze time and assert the command:

- deletes expired raw messages;
- supersedes/rejects expired unconfirmed candidates whose only source was an expired message;
- preserves confirmed facts and active snapshots;
- deletes non-content AI audit rows after their 180-day `expires_at` limit;
- is idempotent and safe with no rows;
- runs daily with `withoutOverlapping()`.

**Step 2: Run RED**

Run: `php artisan test --compact tests/Feature/Bureaucracy/PruneCaseAiDataCommandTest.php`

**Step 3: Implement chunked pruning and schedule**

Use `chunkById()` and model/query deletes; do not use raw interpolated SQL. Command name: `bureaucracy:prune-case-ai-data`.

**Step 4: Run GREEN and commit**

Commit: `feat(bureaucracy): prune expiring AI case data`

---

### Task 7: DeepSeek phase verification

**Files:**

- Modify only if failures require it: files changed in Tasks 1–6

**Step 1: Give DeepSeek a bounded safety-test review through Cline**

Provide only the extractor contract, test names, and failure matrix. Ask DeepSeek V4 Flash for missing routine schema/fallback tests; it must not edit production code, suggest legal content, or receive real user data.

**Step 2: Run the AI safety matrix**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Unit/Bureaucracy/CaseFactExtractionContractTest.php tests/Feature/Bureaucracy/DeepSeekCaseFactExtractorTest.php tests/Feature/Bureaucracy/BureaucracyAiPrivacyTest.php tests/Feature/Bureaucracy/CaseMessageEndpointTest.php tests/Feature/Bureaucracy/PruneCaseAiDataCommandTest.php tests/Feature/RateLimitingTest.php
npm run lint:check
npm run format:check
npm run build
npx playwright test tests/Browser/bureaucracy-case-worker.spec.ts --project=chromium --project=mobile
```

**Step 3: Prove disabled-mode independence**

Run the deterministic Bureaucracy feature tests with `BUREAUCRACY_LLM_ENABLED=false` and no base URL/key. Assert no HTTP request is attempted and the full structured flow passes.

**Step 4: Commit**

Commit: `test(bureaucracy): verify DeepSeek safety boundary`

## Phase acceptance gate

- No model call occurs without opt-in consent and a backend-selected registered fact target.
- Model output can create only an unconfirmed candidate for that target.
- The 21st AI-assisted message inside a rolling 24 hours is refused while structured intake remains usable.
- Invalid, off-topic, injected, timed-out, rate-limited, or unavailable responses cannot change facts, tasks, snapshots, deadlines, documents, fees, citations, or plan ordering.
- Raw messages and unconfirmed AI candidates expire within 30 days.
- No runtime frontier-model integration exists.
