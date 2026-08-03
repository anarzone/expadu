# Bureaucracy Guided Intake and Plan UI Implementation Plan

> **For Codex:** Execute after the deterministic foundation with `superpowers:subagent-driven-development`. Use the Inertia React, Wayfinder, Tailwind CSS, Pest, and verification skills. Manual browser use remains disabled unless the user explicitly asks for it.

**Goal:** Connect the deterministic case engine to onboarding and the Bureaucracy page with structured clarification, confirmed-fact review, flexible plan sections, official-source disclosure, and pixel-perfect Expadu styling.

**Architecture:** Laravel controllers and Form Requests own all mutations. Inertia provides one authoritative page payload. React renders the active snapshot and manually authored questions; it never matches rules or computes legal conclusions. Existing checklist/task components remain the visual base and existing `user_tasks` interactions remain intact.

**Tech stack:** Laravel 13, Inertia v2, React 19, Wayfinder, Tailwind CSS v4, Tabler icons, Pest 4, Playwright.

**Depends on:** `docs/superpowers/plans/2026-08-03-bureaucracy-deterministic-foundation.md` completed and green.

**Dirty-worktree warning:** The onboarding components already contain user changes. Before editing them, inspect `git diff -- resources/js/pages/onboarding.tsx resources/js/components/onboarding` and preserve every unrelated icon/layout change.

---

### Task 1: Add authenticated structured-case endpoints

**Files:**

- Create: `app/Http/Requests/Bureaucracy/StoreCaseFactRequest.php`
- Create: `app/Http/Requests/Bureaucracy/ConfirmCaseFactsRequest.php`
- Create: `app/Http/Requests/Bureaucracy/ResolveCaseFactConflictRequest.php`
- Create: `app/Http/Controllers/Bureaucracy/CaseFactController.php`
- Create: `app/Http/Controllers/Bureaucracy/CaseFactConfirmationController.php`
- Create: `app/Http/Controllers/Bureaucracy/CaseFactConflictController.php`
- Create: `app/Http/Controllers/Bureaucracy/CaseQuestionController.php`
- Create: `app/Http/Controllers/Bureaucracy/CaseReassessmentController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Bureaucracy/CaseIntakeEndpointTest.php`
- Modify: `tests/Feature/RateLimitingTest.php`

**Step 1: Write failing authorization and validation tests**

Test that every route requires auth + verified email + `throttle:app-writes`; users can mutate only their own active case; fact keys must exist in `FactRegistry`; values are validated from the definition type/options; a structured answer is recorded as a candidate, not authoritative; confirmations accept explicit candidate IDs; conflict resolution accepts only one of the conflict's values; skip/unknown outcomes are recorded without inventing a value; reassessment creates a new snapshot only when explicitly requested.

Route names and methods:

```text
POST bureaucracy/case/facts                         bureaucracy.case.facts.store
POST bureaucracy/case/facts/confirm                 bureaucracy.case.facts.confirm
POST bureaucracy/case/conflicts/{conflict}/resolve  bureaucracy.case.conflicts.resolve
POST bureaucracy/case/questions/{question}/skip     bureaucracy.case.questions.skip
POST bureaucracy/case/reassess                      bureaucracy.case.reassess
```

**Step 2: Run RED**

Run: `php artisan test --compact tests/Feature/Bureaucracy/CaseIntakeEndpointTest.php tests/Feature/RateLimitingTest.php`

**Step 3: Implement thin controllers and requests**

Controllers delegate to `CaseFactStore`, `QuestionSelector`, and `PlanSnapshotStore`, then redirect to `route('bureaucracy')` with a concise success/error flash. Form Requests use `$request->validated()` and policy-style ownership checks in `authorize()`.

Generate Wayfinder bindings after routes exist:

`php artisan wayfinder:generate --no-interaction`

**Step 4: Run GREEN and commit**

Run the two focused test files.

Commit: `feat(bureaucracy): add structured case intake endpoints`

---

### Task 2: Collect the missing existing-permit facts during onboarding

**Files:**

- Modify: `app/Http/Requests/OnboardingRequest.php`
- Modify: `app/Http/Controllers/OnboardingController.php`
- Modify: `resources/js/pages/onboarding.tsx`
- Modify: `resources/js/components/onboarding/situation-step.tsx`
- Modify: `resources/js/components/onboarding/confirmation-step.tsx`
- Modify: `tests/Feature/OnboardingTest.php`
- Modify: `tests/Browser/onboarding-icons.spec.ts`

**Step 1: Add failing onboarding tests**

For non-EU users selecting `entry_mode=has_permit`, require `current_residence_title` and `residence_title_expires_at`. For `d_visa`, keep `visa_expires_at`; for visa-free users, require neither title-expiry field. Verify that the controller writes the new answers through `CaseFactStore` as confirmed onboarding facts and keeps the legacy profile attributes synchronized during rollout.

Test reopening onboarding with existing values, switching entry modes clears no unrelated facts, and the final confirmation preview does not claim that no data ever leaves Expadu. The replacement copy is: “Your confirmed answers personalize your plan. AI-assisted free text is optional and explained before anything is shared with the configured processor.”

**Step 2: Run RED**

Run: `php artisan test --compact tests/Feature/OnboardingTest.php`

**Step 3: Update only the conditional fields**

Extend `OnboardingData` with:

```ts
current_residence_title: '' | 'national_d_visa' | 'standard_work_permit' | 'blue_card' | 'family_reunification' | 'settlement_permit_18c' | 'other';
residence_title_expires_at: string;
```

Use the existing `OnboardingIcon`, field styling, spacing, and bottom action bar. Do not add sponsor, employment-month, or settlement-condition questions here; those belong to targeted case clarification.

**Step 4: Run backend and automated browser tests**

```bash
php artisan test --compact tests/Feature/OnboardingTest.php
npx playwright test tests/Browser/onboarding-icons.spec.ts --config=playwright.onboarding.config.ts
```

Do not open or manually inspect a browser.

**Step 5: Commit**

Commit: `feat(onboarding): capture existing permit expiry`

---

### Task 3: Expose one authoritative Bureaucracy page payload

**Files:**

- Modify: `app/Http/Controllers/BureaucracyController.php`
- Create: `app/Bureaucracy/Cases/BureaucracyPagePresenter.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `tests/Feature/BureaucracyEngineTest.php`
- Modify: `tests/Feature/BureaucracyV2Test.php`
- Create: `tests/Feature/Bureaucracy/BureaucracyPagePayloadTest.php`

**Step 1: Write failing payload tests**

Assert a stable prop contract:

```php
case => [
    'coverage_state' => 'matched|needs_information|not_covered|conflict',
    'summary' => [...confirmed fact labels...],
    'next_question' => null|[id, fact_key, question, why, type, options, can_skip],
    'conflicts' => [...],
    'ai' => [enabled, consented, available],
],
plan => [
    'snapshot_id' => int,
    'generated_at' => string,
    'sections' => [
        'current_status' => [], 'do_now' => [], 'next' => [],
        'coming_up' => [], 'options' => [], 'waiting' => [],
        'information_needed' => [], 'not_covered' => [],
    ],
],
legal_notice => [message, verify_message],
```

Every task item must retain current fields needed by `TaskCardFramingB` plus `task_key`, `content_version`, `legal_sources`, `review_status`, and `verified_at`. Unapproved tasks must not appear in authoritative sections. Existing progress, documents, appointment, blocked dependencies, task toggles, `no_longer_relevant`, and settled behavior must remain functional.

**Step 2: Run RED**

Run:

`php artisan test --compact tests/Feature/Bureaucracy/BureaucracyPagePayloadTest.php tests/Feature/BureaucracyEngineTest.php tests/Feature/BureaucracyV2Test.php`

**Step 3: Extract presentation from the controller**

`BureaucracyController::index()` must orchestrate case bootstrap, snapshot retrieval, existing `user_tasks` loading, and one presenter call. `BureaucracyPagePresenter` maps task keys in the active snapshot back to current task/user-task data without recomputing rule applicability. Apply explicit ordering from snapshot sections; never rely on database default order.

The persistent notice text is:

> Expadu provides informational recommendations and may make mistakes. Verify consequential steps, deadlines, and eligibility with the linked official source or the responsible authority. This is not legal advice.

**Step 4: Run GREEN and commit**

Run all three focused files.

Commit: `refactor(bureaucracy): present stable case plans`

---

### Task 4: Build reusable case-status and clarification components

**Files:**

- Create: `resources/js/components/bureaucracy/case-status-card.tsx`
- Create: `resources/js/components/bureaucracy/case-question-card.tsx`
- Create: `resources/js/components/bureaucracy/case-fact-review-sheet.tsx`
- Create: `resources/js/components/bureaucracy/case-conflict-sheet.tsx`
- Create: `resources/js/components/bureaucracy/legal-notice.tsx`
- Create: `resources/js/components/bureaucracy/source-disclosure.tsx`
- Create: `resources/js/components/bureaucracy/coverage-gap-card.tsx`
- Modify: `resources/js/components/bureaucracy/task-card-framing-b.tsx`
- Create: `resources/js/components/bureaucracy/types.ts`
- Modify: `resources/js/pages/bureaucracy.tsx`

**Step 1: Add frontend type checks before markup**

Move shared case/plan/task prop types into `types.ts`. Use generated Wayfinder functions from `@/actions` or `@/routes` for every new request. Model state explicitly; do not use optional booleans whose combinations can contradict each other.

**Step 2: Implement the components in the current visual language**

- Reuse Tabler icons and the existing `ICON_STROKE` convention; no emoji and no second icon package.
- Reuse current card borders, warm backgrounds, radii, typography, 680px content width, bottom-navigation safe area, sheet behavior, focus rings, and button hierarchy.
- The next question uses structured inputs first, always shows “I don't know” and “Skip for now,” and displays `why` in progressive disclosure.
- The fact review sheet labels facts `Confirmed`; conflicts show both values and require an explicit selection.
- `SourceDisclosure` lists primary first, implementation second, and the verified date; it never displays a numerical confidence score.
- `CoverageGapCard` uses the approved unsupported-case wording and contains no obligation language.
- `LegalNotice` is compact, persistent, and screen-reader readable without visually alarming every card.

**Step 3: Add component lint/type verification**

Run:

```bash
npm run lint:check
npm run format:check
npm run build
```

Fix only errors caused by this task; preserve pre-existing unrelated worktree changes.

**Step 4: Commit**

Commit: `feat(bureaucracy): add guided case components`

---

### Task 5: Render flexible snapshot sections in the existing checklist

**Files:**

- Create: `resources/js/components/bureaucracy/plan-section.tsx`
- Modify: `resources/js/components/bureaucracy/checklist-framing-b.tsx`
- Modify: `resources/js/pages/bureaucracy.tsx`
- Modify: `resources/js/components/bureaucracy/task-card-framing-b.tsx`
- Modify: `tests/Browser/bureaucracy.spec.ts`
- Create: `tests/Browser/bureaucracy-case-worker.spec.ts`

**Step 1: Add automated browser scenarios first**

Using seeded QA scenarios, assert desktop and Pixel 7 behavior for:

- matched plan with `Do now`, `Next`, and `Coming up`;
- one structured clarification question and keyboard submission;
- fact review/correction sheet;
- source disclosure and verified date;
- `needs_information`, `not_covered`, and `conflict` states;
- persistent legal notice;
- no horizontal overflow, minimum 44px controls, usable bottom-nav safe area;
- `assertNoJavaScriptErrors` equivalent through Playwright `pageerror` and console-error capture.

Do not remove or rewrite unrelated assertions in `bureaucracy.spec.ts`.

**Step 2: Run the new spec and confirm RED**

Run: `npx playwright test tests/Browser/bureaucracy-case-worker.spec.ts --project=chromium --project=mobile`

**Step 3: Replace fixed card lanes with snapshot-driven sections**

`PlanSection` maps only known section keys to manually authored headings and helper text. Empty sections are omitted. Preserve existing task completion/toggle/document behavior. Keep `no_longer_relevant` and completed history available below the active plan but visually secondary. The UI must never infer a section from urgency or task text.

**Step 4: Run automated browser tests**

```bash
npx playwright test tests/Browser/bureaucracy.spec.ts tests/Browser/bureaucracy-case-worker.spec.ts --project=chromium --project=mobile
```

This is automated verification, not manual browser testing.

**Step 5: Commit**

Commit: `feat(bureaucracy): render flexible verified plan sections`

---

### Task 6: Accessibility, offline, error, and model-unavailable states

**Files:**

- Modify: `resources/js/components/bureaucracy/case-question-card.tsx`
- Modify: `resources/js/components/bureaucracy/case-fact-review-sheet.tsx`
- Modify: `resources/js/components/bureaucracy/case-conflict-sheet.tsx`
- Modify: `resources/js/pages/bureaucracy.tsx`
- Modify: `tests/Browser/bureaucracy-case-worker.spec.ts`

**Step 1: Extend the failing browser tests**

Cover focus return after sheets close, Escape behavior, labelled fields, live-region mutation results, reduced-motion behavior, 200% text zoom, offline submission failure, server validation errors, and `ai.available=false`. The last valid plan must remain fully visible in every failure state, and structured choices must remain usable.

**Step 2: Implement explicit states**

Use Inertia form processing/errors; do not introduce client-only shadow state for authoritative facts. Show a small retry message for failed writes, preserve the user's selected answer, and never clear plan sections. Model-unavailable copy must say that structured questions still work and must not imply that legal guidance depends on AI.

**Step 3: Run GREEN**

Run the browser case-worker spec for desktop and mobile, then `npm run build`.

**Step 4: Commit**

Commit: `fix(bureaucracy): harden guided intake states`

---

### Task 7: UI phase verification

**Files:**

- Modify only if failures require it: files changed in Tasks 1–6

**Step 1: Ask DeepSeek for a bounded routine review**

Through Cline, ask DeepSeek V4 Flash to inspect only the changed tests and type contracts for missing routine cases. It must not edit production files or assess the law.

**Step 2: Run backend, frontend, and automated browser checks**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/OnboardingTest.php tests/Feature/Bureaucracy tests/Feature/BureaucracyEngineTest.php tests/Feature/BureaucracyV2Test.php tests/Feature/RateLimitingTest.php
npm run lint:check
npm run format:check
npm run build
npx playwright test tests/Browser/onboarding-icons.spec.ts tests/Browser/bureaucracy.spec.ts tests/Browser/bureaucracy-case-worker.spec.ts --project=chromium --project=mobile
```

**Step 3: Verify worktree scope and commit**

Inspect `git diff --stat` and `git status --short`. Stage only this phase's paths. Do not perform manual browser testing.

Commit: `test(bureaucracy): verify guided case experience`

## Phase acceptance gate

- Structured clarification works with no model configured.
- Existing-permit users can provide the residence-permit expiry missing from current onboarding.
- The active snapshot controls every authoritative section and order.
- All consequential items expose official sources and verification dates.
- Unsupported/conflicting states preserve independently verified tasks and never guess.
- Current Expadu styling, icons, responsiveness, accessibility, and existing task progress behavior are preserved.
- Playwright stays present and passes; no manual browser action was performed.
