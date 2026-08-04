# Onboarding Case Handoff and Bounded Chat Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make real onboarding create the same canonical facts as QA scenarios and add an always-visible, optional DeepSeek fact interpreter that cannot author legal guidance.

**Architecture:** `ApplyOnboardingAnswers` owns one transactional user/profile/case transition and delegates fact lifecycle changes to `CaseFactStore`. Onboarding captures only high-value routing facts and no longer generates a competing legal preview. The existing backend-selected question remains authoritative; the AI endpoint may interpret free text for only that question and returns an unconfirmed value for explicit user confirmation through the existing answer endpoint.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, Inertia 2, React 19, TypeScript, Tailwind CSS 4, Tabler Icons, Pest 4, Laravel HTTP client, Playwright.

## Global Constraints

- No model may create, select, order, or rewrite legal guidance.
- No AI call without an active backend-selected question, explicit consent, complete processor disclosure, and available quota.
- Structured questions remain fully usable with AI disabled or unavailable.
- Missing facts stay unknown; never confirm `visa_free`, `standard`, or sponsor status from a fallback default.
- D-visa expiry and residence-title expiry are distinct.
- Self-assessed German never satisfies a rule requiring documented German proof.
- Existing design tokens, Tabler icons, dark mode, responsive behavior, and minimum 44px controls remain intact.
- No new dependency.

---

### Task 1: Canonical onboarding transition

**Files:**
- Create: `app/Onboarding/ApplyOnboardingAnswers.php`
- Modify: `app/Bureaucracy/Facts/CaseFactStore.php`
- Modify: `app/Bureaucracy/Facts/LegacyFactBootstrapper.php`
- Modify: `app/Profile/ProfileEngine.php`
- Modify: `app/Http/Requests/OnboardingRequest.php`
- Modify: `app/Http/Controllers/OnboardingController.php`
- Modify: `tests/Feature/OnboardingTest.php`
- Modify: `tests/Feature/Bureaucracy/CaseFactLifecycleTest.php`

**Interfaces:**
- Produces `ApplyOnboardingAnswers::execute(User $user, array $validated): BureaucracyCase`.
- Produces `CaseFactStore::synchronizeConfirmedFacts(User $user, array $facts, string $source, array $retireKeys = []): BureaucracyCase`.

- [ ] **Step 1: Add failing feature tests**

Post family/D-visa and Blue-Card-goal onboarding payloads. Assert explicit facts are confirmed, absent refinement facts remain absent, same-source answers are superseded, different authoritative sources create conflicts, inapplicable entry/expiry facts are retired, and all writes roll back when place creation fails.

- [ ] **Step 2: Run RED**

`php artisan test --compact tests/Feature/OnboardingTest.php tests/Feature/Bureaucracy/CaseFactLifecycleTest.php`

- [ ] **Step 3: Implement the transaction and fact synchronization**

The action locks the user, normalizes dependent fields, updates the profile, synchronizes `citizenship_group`, `purpose`, `entry_mode`, `visa_expires_at`, `current_residence_title`, `residence_title_expires_at`, `case_goal`, `sponsor_current_title`, `permit_track`, and documented `german_level`, then creates required places. It retires inapplicable onboarding/legacy facts and increments `fact_version` only when the case changes.

- [ ] **Step 4: Remove unsafe defaults**

`ProfileEngine` returns `null` for missing entry mode, unselected permit track, and unselected sponsor type. `LegacyFactBootstrapper` stops mapping the self-assessed user German field to legal `german_level`.

- [ ] **Step 5: Run GREEN and format**

`php artisan test --compact tests/Feature/OnboardingTest.php tests/Feature/Bureaucracy/CaseFactLifecycleTest.php`

`vendor/bin/pint --dirty --format agent`

---

### Task 2: Minimal five-step onboarding UI

**Files:**
- Modify: `resources/js/pages/onboarding.tsx`
- Modify: `resources/js/components/onboarding/welcome-step.tsx`
- Modify: `resources/js/components/onboarding/situation-step.tsx`
- Modify: `resources/js/components/onboarding/veedel-step.tsx`
- Modify: `resources/js/components/onboarding/interests-step.tsx`
- Modify: `resources/js/components/onboarding/confirmation-step.tsx`
- Modify: `tests/Browser/bureaucracy.spec.ts`

**Interfaces:**
- Extends `OnboardingData` with current title/expiry, goal, sponsor title, documented German level, move-in date, and address-registration status.

- [ ] **Step 1: Add failing browser assertions**

Cover family entry mode, existing-title expiry, Blue Card goal, sponsor title, separate move-in date, documented language proof, optional interests, truthful privacy copy, and a confirmation screen without legacy task claims.

- [ ] **Step 2: Run RED**

`npx playwright test tests/Browser/bureaucracy.spec.ts --grep "onboarding case facts"`

- [ ] **Step 3: Implement adaptive controls**

Keep five screens. Clear child fields when situation, citizenship, entry mode, current title, or goal changes. Permit “I’m not sure” without manufacturing a fact. Replace the temporary-housing legal claim with address-registration status and collect move-in date only when the address can be registered.

- [ ] **Step 4: Simplify confirmation**

Remove the coarse `taskPreviews` contract. Show an answer summary and “Open my first plan”; submit redirects to `/bureaucracy`, where the verified plan pipeline runs.

- [ ] **Step 5: Run type/build/browser checks**

`npm run types:check`

`npm run build`

`npx playwright test tests/Browser/bureaucracy.spec.ts --grep "onboarding"`

---

### Task 3: Extraction-only DeepSeek boundary

**Files:**
- Create: `app/Bureaucracy/Ai/Contracts/ExtractsCaseFact.php`
- Create: `app/Bureaucracy/Ai/CaseFactExtractionRequest.php`
- Create: `app/Bureaucracy/Ai/CaseFactExtractionResult.php`
- Create: `app/Bureaucracy/Ai/UnavailableCaseFactExtractor.php`
- Create: `app/Bureaucracy/Ai/DeepSeekCaseFactExtractor.php`
- Create: `app/Bureaucracy/Ai/CaseFactToolSchema.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `config/services.php`
- Modify: `.env.example`
- Create: `tests/Unit/Bureaucracy/CaseFactExtractionContractTest.php`
- Create: `tests/Feature/Bureaucracy/DeepSeekCaseFactExtractorTest.php`

**Interfaces:**
- `ExtractsCaseFact::extract(CaseFactExtractionRequest $request): CaseFactExtractionResult`.
- Result outcome is exactly `candidate`, `unknown`, `off_topic`, `unavailable`, or `invalid`; only `candidate` carries a typed value.

- [ ] **Step 1: Write contract and HTTP-fake tests**

Assert one target only, minimum context, forced tool output, all registered fact types, invalid enum/date/integer/boolean values, injected extra keys, prose, malformed JSON, timeout, 429/4xx/5xx, and no stray network requests.

- [ ] **Step 2: Run RED**

`php artisan test --compact tests/Unit/Bureaucracy/CaseFactExtractionContractTest.php tests/Feature/Bureaucracy/DeepSeekCaseFactExtractorTest.php`

- [ ] **Step 3: Implement disabled-by-default provider configuration**

Use `BUREAUCRACY_LLM_ENABLED`, base URL, model, key, processor name/privacy URL, timeout, prompt version, and daily limit. Bind the unavailable extractor unless every required configuration value is present.

- [ ] **Step 4: Implement strict extraction and run GREEN**

Use Laravel HTTP client with 3-second connect timeout, configured total timeout, temperature zero, and one forced `extract_authorized_fact` tool. Validate model output through `FactRegistry`; log metadata only.

---

### Task 4: Consent, quota, and message endpoint

**Files:**
- Create with Artisan: migration adding `ai_consent_at`/`ai_consent_withdrawn_at` to `bureaucracy_cases` and creating `bureaucracy_case_messages`
- Create: `app/Models/BureaucracyCaseMessage.php`
- Create: `database/factories/BureaucracyCaseMessageFactory.php`
- Create: `app/Bureaucracy/Ai/BureaucracyAiQuota.php`
- Create: `app/Bureaucracy/Ai/ExtractCaseFactAction.php`
- Create: `app/Http/Requests/Bureaucracy/UpdateAiConsentRequest.php`
- Create: `app/Http/Requests/Bureaucracy/StoreCaseMessageRequest.php`
- Create: `app/Http/Controllers/Bureaucracy/AiConsentController.php`
- Create: `app/Http/Controllers/Bureaucracy/CaseMessageController.php`
- Modify: `app/Models/BureaucracyCase.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Bureaucracy/BureaucracyAiPrivacyTest.php`
- Create: `tests/Feature/Bureaucracy/CaseMessageEndpointTest.php`

**Interfaces:**
- `PUT /bureaucracy/case/ai-consent` stores/withdraws consent.
- `POST /bureaucracy/case/messages` accepts only `question_id` and `message`, returns JSON extraction outcome/value/label, and never mutates a confirmed fact or snapshot.

- [ ] **Step 1: Write failing privacy, ownership, quota, and endpoint tests**

Cover auth, question ownership/currentness, consent, encrypted content, 30-day expiry, 20-message rolling quota, burst throttle, fixed off-topic/unavailable copy, and unchanged fact/snapshot state.

- [ ] **Step 2: Run RED**

`php artisan test --compact tests/Feature/Bureaucracy/BureaucracyAiPrivacyTest.php tests/Feature/Bureaucracy/CaseMessageEndpointTest.php tests/Feature/RateLimitingTest.php`

- [ ] **Step 3: Implement migration through Artisan, services, Form Requests, controllers, and routes**

Use a focused reversible migration, encrypted/hidden message content, per-user and IP limiter, constructor injection, and ownership checks against the active case and active next question.

- [ ] **Step 4: Regenerate Wayfinder and run GREEN**

`php artisan wayfinder:generate --no-interaction`

`php artisan test --compact tests/Feature/Bureaucracy/BureaucracyAiPrivacyTest.php tests/Feature/Bureaucracy/CaseMessageEndpointTest.php tests/Feature/RateLimitingTest.php`

---

### Task 5: Always-visible bounded assistant UI

**Files:**
- Modify: `app/Bureaucracy/Cases/CasePlanPresenter.php`
- Modify: `resources/js/components/bureaucracy/case-plan-types.ts`
- Modify: `resources/js/components/bureaucracy/case-assistant-card.tsx`
- Create: `resources/js/components/bureaucracy/ai-consent-sheet.tsx`
- Modify: `resources/js/components/bureaucracy/case-plan-view.tsx`
- Modify: `tests/Browser/bureaucracy.spec.ts`

**Interfaces:**
- `casePlan.ai` exposes only availability, consent, processor display name/privacy URL, and remaining quota.
- The UI confirms an extracted typed value by posting it to the existing `bureaucracy.case-question.answer` route.

- [ ] **Step 1: Add failing browser cases**

Assert the assistant is visible with and without a next question; structured fallback always works; first text use opens consent; candidate requires confirmation; decline/provider failure/off-topic/limit states preserve the plan.

- [ ] **Step 2: Run RED**

`npx playwright test tests/Browser/bureaucracy.spec.ts --grep "bounded case assistant"`

- [ ] **Step 3: Implement the compact card and consent sheet**

Reuse current card styling and Tabler icons. Show a text input only when a next question exists; otherwise show “Your plan has enough confirmed information” plus an update-onboarding action. Never render model-authored prose.

- [ ] **Step 4: Run frontend/browser checks**

`npm run types:check`

`npm run build`

`npx playwright test tests/Browser/bureaucracy.spec.ts --grep "bounded case assistant"`

---

### Task 6: Six-case end-to-end verification

**Files:**
- Modify: `tests/Feature/Bureaucracy/InvestigatedCaseCorpusTest.php`
- Create: `tests/Feature/Bureaucracy/InvestigatedOnboardingJourneyTest.php`
- Modify: `tests/Browser/bureaucracy.spec.ts`

- [ ] **Step 1: Split the corpus into an independent Pest dataset**

Each scenario receives a separate test result. `information_needed` assertions inspect authored questions/why text rather than an intentionally hidden internal rule key.

- [ ] **Step 2: Add real journey assertions**

Post onboarding facts, answer remaining structured questions, and assert the resulting matched/needs-information/not-covered plan for all six investigated cases.

- [ ] **Step 3: Run backend verification**

`vendor/bin/pint --dirty --format agent`

`php artisan test --compact tests/Feature/OnboardingTest.php tests/Feature/Bureaucracy tests/Feature/BureaucracyControllerTest.php tests/Feature/BureaucracyEngineTest.php tests/Feature/BureaucracyV2Test.php tests/Feature/RateLimitingTest.php`

- [ ] **Step 4: Run frontend verification**

`npm run lint:check`

`npm run format:check`

`npm run build`

`npx playwright test tests/Browser/bureaucracy.spec.ts`

- [ ] **Step 5: Perform final persona browser smoke review**

Visit every QA persona, confirm the visible status, section headings, expected key task/option, assistant state, and absence of console errors. Record any discrepancy; return to the owning TDD cycle before reporting completion.

- [ ] **Step 6: Inspect scope**

`git diff --check`

`git status --short`

Report exact passing counts and any remaining limitation. Do not describe the feature as complete while any required check is red.
