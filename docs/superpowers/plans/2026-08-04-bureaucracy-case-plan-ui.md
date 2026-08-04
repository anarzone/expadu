# Bureaucracy Case Plan UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the verified, deterministic bureaucracy case plan the primary live Bureaucracy experience, with structured clarification, official-source trust cues, safe task progression, and the existing Expadu visual language.

**Architecture:** The live controller continues producing the legacy payload for compatibility, then bootstraps the user's durable case, stores/reuses its deterministic plan snapshot, and adds a typed `casePlan` prop through a dedicated presenter. React renders the new plan whenever that prop is present and keeps the legacy checklist only as a fallback for demo or incomplete rollout states. Structured answers and plan-task status changes use authenticated, ownership-checked POST/PATCH endpoints; no model is involved.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia Laravel 2, React 19, TypeScript, Tailwind CSS 4, Tabler Icons, Pest 4, Playwright.

## Global Constraints

- Only approved, current, source-valid backend rules may appear as authoritative guidance.
- DeepSeek or another model may not create, select, order, or rewrite legal guidance.
- The page must retain Expadu's existing cards, spacing, typography, navigation, icon library, dark mode, and responsive behavior.
- Every authoritative item exposes official sources and a `Verified on` date in its detail view.
- High-impact items remind the user to verify the linked official source or responsible authority.
- Unsupported or tentative content must not use obligation or eligibility wording.
- A compact persistent notice says that Expadu provides informational recommendations, may make mistakes, and does not replace the responsible authority or qualified legal advice.
- The structured assistant asks one backend-authorized question at a time and offers `I don't know` and `Skip for now`.
- No manual browser testing; automated feature, type, build, and Playwright tests remain required.
- No new dependency or icon library.

---

### Task 1: Define and test the live case-plan page contract

**Files:**
- Modify: `tests/Feature/BureaucracyControllerTest.php`
- Modify: `app/Bureaucracy/Cases/CasePlanComposer.php`
- Create: `app/Bureaucracy/Cases/CasePlanPresenter.php`
- Modify: `app/Http/Controllers/BureaucracyController.php`

**Interfaces:**
- Consumes: `LegacyFactBootstrapper::bootstrap(User): BureaucracyCase`, `PlanSnapshotStore::store(BureaucracyCase): BureaucracyPlanSnapshot`, `QuestionSelector::rankedFactKeys(BureaucracyCase, CaseMatchResult): array`.
- Produces: `CasePlanPresenter::present(BureaucracyCase, BureaucracyPlanSnapshot, ?BureaucracyCaseQuestion): array` and Inertia prop `casePlan` with `coverage_state`, `generated_at`, `sections`, `next_question`, and section task DTOs.

- [ ] **Step 1: Write failing Inertia contract tests**

Add focused tests that seed one approved rule and assert the live page exposes:

```php
$response->assertInertia(fn (Assert $page) => $page
    ->component('bureaucracy')
    ->where('casePlan.coverage_state', 'matched')
    ->where('casePlan.sections.do_now.0.key', 'case.test-action')
    ->where('casePlan.sections.do_now.0.legal_sources.0.url', 'https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html')
    ->where('casePlan.sections.do_now.0.verified_at', '2026-08-03')
    ->where('casePlan.sections.do_now.0.status', 'not_started')
    ->etc());
```

Add a `needs_information` case asserting exactly one server-issued `next_question` with `id`, `type`, `question`, `why`, authored choices, sensitivity, and attempt. The prop must not expose a case ID or writable source.

- [ ] **Step 2: Verify the contract tests fail for the missing prop**

Run: `php artisan test --compact tests/Feature/BureaucracyControllerTest.php`

Expected: FAIL because `casePlan` is not present.

- [ ] **Step 3: Expand immutable snapshot items and add the presenter**

Make `CasePlanComposer::taskItem()` include immutable authored fields:

```php
'documents_required' => $task->documents_required ?? [],
'decision_options' => $task->decision_options ?? [],
'how_to_steps' => $task->how_to_steps ?? [],
'links' => $task->links ?? [],
'legal_sources' => $task->legal_sources ?? [],
'verified_at' => $task->verified_at?->toDateString(),
'high_impact' => $task->deadline_type?->value !== 'none'
    || in_array($task->urgency?->value, ['critical', 'high'], true),
```

`CasePlanPresenter` merges current `UserTask` state by task key without changing the snapshot, maps all eight section keys, and turns the server-selected `BureaucracyCaseQuestion` plus its registered definition into the public question DTO.

- [ ] **Step 4: Wire the presenter into the live page only**

In `BureaucracyController::index()`, retain the legacy payload, bootstrap the durable case, match it, reuse/store the deterministic snapshot, select/reuse at most one persisted question, and append `casePlan`. Keep `buildPayload()` pure so the demo controller remains read-only. This live-current-plan GET is already idempotently writeful through `PathGenerator::ensure()` and snapshot materialization; historical snapshot reads remain separate and read-only.

- [ ] **Step 5: Run focused backend tests**

Run: `php artisan test --compact tests/Feature/BureaucracyControllerTest.php tests/Feature/Bureaucracy/CaseMatcherTest.php tests/Feature/Bureaucracy/PlanSnapshotTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Bureaucracy/Cases/CasePlanComposer.php app/Bureaucracy/Cases/CasePlanPresenter.php app/Http/Controllers/BureaucracyController.php tests/Feature/BureaucracyControllerTest.php
git commit -m "feat(bureaucracy): expose verified case plan"
```

### Task 2: Accept only the server-selected structured clarification

**Files:**
- Create: `app/Http/Requests/AnswerBureaucracyCaseQuestionRequest.php`
- Create: `app/Bureaucracy/Cases/AnswerCaseQuestion.php`
- Create: `app/Http/Controllers/BureaucracyCaseQuestionController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Bureaucracy/CaseQuestionControllerTest.php`

**Interfaces:**
- Consumes: the persisted `BureaucracyCaseQuestion`, `FactRegistry::definition()`, `CaseFactStore::recordCandidate()` and `CaseFactStore::confirmCandidate()`.
- Produces: named route `bureaucracy.case-question.answer`, accepting only `{ value }` for a server-issued question ID and redirecting back with a recomputed snapshot on the next GET.

- [ ] **Step 1: Write failing authorization and validation tests**

Cover: unauthenticated and unverified redirects; a missing, answered, stale, or another user's question is rejected; invalid enum/date/integer/boolean values are rejected; a selected structured answer is confirmed; double submission is idempotent; a conflicting candidate remains unresolved rather than silently overwriting.

```php
$this->actingAs($user)
    ->post(route('bureaucracy.case-question.answer', $question), [
        'value' => 'settlement_permit',
    ])
    ->assertRedirect();

expect($case->facts()->where('key', 'case_goal')->where('state', 'confirmed')->value('value'))
    ->toBe('settlement_permit');
```

- [ ] **Step 2: Verify tests fail because the route is missing**

Run: `php artisan test --compact tests/Feature/Bureaucracy/CaseQuestionControllerTest.php`

Expected: FAIL with route not defined.

- [ ] **Step 3: Add request and controller**

The Form Request authorizes the question through `$request->user()->bureaucracyCase`, derives its fact definition server-side, and applies strict enum/date/integer/boolean rules. A single transactional `AnswerCaseQuestion` action locks case then question, handles stale/double submits safely, records a candidate with source `structured_interview`, confirms it, and sets `answered_at` / `outcome`. A conflict returns with an explanatory validation error and does not replace the confirmed fact.

- [ ] **Step 4: Add the authenticated named route**

```php
Route::post('bureaucracy/case/questions/{question}', BureaucracyCaseQuestionController::class)
    ->name('bureaucracy.case-question.answer');
```

- [ ] **Step 5: Run focused tests**

Run: `php artisan test --compact tests/Feature/Bureaucracy/CaseQuestionControllerTest.php tests/Feature/Bureaucracy/CaseFactLifecycleTest.php tests/Feature/Bureaucracy/CaseMatcherTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/AnswerBureaucracyCaseQuestionRequest.php app/Bureaucracy/Cases/AnswerCaseQuestion.php app/Http/Controllers/BureaucracyCaseQuestionController.php routes/web.php tests/Feature/Bureaucracy/CaseQuestionControllerTest.php
git commit -m "feat(bureaucracy): answer verified case questions"
```

### Task 3: Add safe case-task progression by verified rule key

**Files:**
- Create: `app/Http/Requests/UpdateBureaucracyCaseTaskRequest.php`
- Create: `app/Http/Controllers/BureaucracyCaseTaskController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Bureaucracy/CaseTaskControllerTest.php`

**Interfaces:**
- Consumes: current non-superseded `BureaucracyPlanSnapshot` and `Task::authoritative()`.
- Produces: named route `bureaucracy.case-task.update`, `PATCH /bureaucracy/case/tasks/{task:key}`, accepting only `status`.

- [ ] **Step 1: Write failing scope and lifecycle tests**

Cover: route requires authentication; task must be an authoritative key in the user's active snapshot; another user's or unrelated task is rejected; `done` uses `UserTask::markDone()`; reopening clears completion metadata; next GET changes dependency-based sections.

- [ ] **Step 2: Verify the tests fail because the route is missing**

Run: `php artisan test --compact tests/Feature/Bureaucracy/CaseTaskControllerTest.php`

Expected: FAIL with route not defined.

- [ ] **Step 3: Implement the narrow mutation endpoint**

Authorize by reading the active snapshot's section keys, then `firstOrCreate` the current user's `UserTask`. Reuse the existing status transition semantics and accept no applicability, document, note, or appointment mutation through this endpoint.

- [ ] **Step 4: Run focused tests**

Run: `php artisan test --compact tests/Feature/Bureaucracy/CaseTaskControllerTest.php tests/Feature/Bureaucracy/PlanSnapshotTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/UpdateBureaucracyCaseTaskRequest.php app/Http/Controllers/BureaucracyCaseTaskController.php routes/web.php tests/Feature/Bureaucracy/CaseTaskControllerTest.php
git commit -m "feat(bureaucracy): progress verified case tasks"
```

### Task 4: Build the recognizable case-worker UI

**Files:**
- Create: `resources/js/components/bureaucracy/case-plan-types.ts`
- Create: `resources/js/components/bureaucracy/case-assistant-card.tsx`
- Create: `resources/js/components/bureaucracy/case-plan-task-card.tsx`
- Create: `resources/js/components/bureaucracy/case-plan-view.tsx`
- Modify: `resources/js/pages/bureaucracy.tsx`
- Modify: `tests/Browser/bureaucracy.spec.ts`

**Interfaces:**
- Consumes: typed `casePlan` Inertia prop and named route URLs supplied as plain relative endpoints consistent with existing page code.
- Produces: primary verified plan UI; legacy `ChecklistFramingB` remains the fallback only when `casePlan` is absent.

- [ ] **Step 1: Add failing automated browser assertions**

Add a deterministic QA-persona case that asserts the page has:

```ts
await expect(page.getByText('Your verified plan')).toBeVisible();
await expect(page.getByText('Expadu can make mistakes')).toBeVisible();
await expect(page.getByRole('heading', { name: 'Do now' })).toBeVisible();
await page.getByRole('button', { name: /official sources/i }).first().click();
await expect(page.getByText(/Verified on/)).toBeVisible();
```

Add a needs-information case asserting one focused question, structured options, `I don't know`, `Skip for now`, and no free-form unrestricted chat box.

- [ ] **Step 2: Verify the browser test fails on the current legacy UI**

Run: `npx playwright test tests/Browser/bureaucracy.spec.ts --grep "verified case plan"`

Expected: FAIL because the new heading and notice do not exist.

- [ ] **Step 3: Define exact TypeScript DTOs**

```ts
export type CasePlan = {
    coverage_state: 'matched' | 'needs_information' | 'not_covered' | 'conflict';
    generated_at: string;
    sections: Record<CasePlanSectionKey, CasePlanItem[]>;
    next_question: CasePlanQuestion | null;
};
```

Keep source, document, question, and task status fields explicit; do not use `any`.

- [ ] **Step 4: Implement the assistant and task cards**

Use Tabler icons with `ICON_STROKE`, existing 14/20px radii, border/background tokens, display/body typography, dark-mode classes, and button interaction patterns. The assistant posts a structured answer with `router.post(..., { preserveScroll: true })`; `Skip for now` collapses locally; `I don't know` leaves the plan in needs-information and explains that independently verified steps remain visible.

- [ ] **Step 5: Implement the flexible section renderer**

Render only non-empty sections in this order: current status, do now, next, coming up, options you may qualify for, waiting for something, information we still need, not currently covered. `Do now` is open; lower-priority sections may collapse. Use the exact uncovered-case wording from the approved specification.

- [ ] **Step 6: Switch the live page to the verified plan**

Insert the compact persistent legal notice directly under the sticky tabs. Render `<CasePlanView>` when `casePlan` exists and retain the current checklist fallback for demo/rollout safety. Preserve Documents tab and derive verified-plan documents where available.

- [ ] **Step 7: Run type, formatting, and focused browser tests**

Run: `npm run types:check`

Run: `npx prettier --check resources/js/pages/bureaucracy.tsx resources/js/components/bureaucracy/case-*.tsx resources/js/components/bureaucracy/case-plan-types.ts`

Run: `npx playwright test tests/Browser/bureaucracy.spec.ts`

Expected: PASS with no JavaScript errors.

- [ ] **Step 8: Commit**

```bash
git add resources/js/components/bureaucracy/case-plan-types.ts resources/js/components/bureaucracy/case-assistant-card.tsx resources/js/components/bureaucracy/case-plan-task-card.tsx resources/js/components/bureaucracy/case-plan-view.tsx resources/js/pages/bureaucracy.tsx tests/Browser/bureaucracy.spec.ts
git commit -m "feat(bureaucracy): render verified case worker plan"
```

### Task 5: Integrate the right panel and document library

**Files:**
- Modify: `resources/js/components/bureaucracy/bureaucracy-right-panel.tsx`
- Modify: `resources/js/pages/bureaucracy.tsx`
- Modify: `tests/Browser/bureaucracy.spec.ts`

**Interfaces:**
- Consumes: `CasePlan.sections` and case task deadlines/documents.
- Produces: right-panel deadline summary and Documents tab derived from the authoritative visible plan, while retaining legacy behavior when `casePlan` is absent.

- [ ] **Step 1: Write failing browser assertions for verified deadlines and documents**

Assert that a fact-date case deadline appears in the right panel and that a document from the verified case item appears once in Documents with its requesting task.

- [ ] **Step 2: Verify failure against the legacy-only derivation**

Run: `npx playwright test tests/Browser/bureaucracy.spec.ts --grep "verified plan documents"`

Expected: FAIL because only legacy buckets feed the panel and library.

- [ ] **Step 3: Add pure case-plan derivation helpers**

Flatten authoritative sections, exclude `information_needed` and coverage notices from actionable document/deadline calculations, deduplicate by document label, and preserve the legacy fallback.

- [ ] **Step 4: Run frontend verification**

Run: `npm run types:check`

Run: `npx playwright test tests/Browser/bureaucracy.spec.ts`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/bureaucracy/bureaucracy-right-panel.tsx resources/js/pages/bureaucracy.tsx tests/Browser/bureaucracy.spec.ts
git commit -m "feat(bureaucracy): connect verified plan utilities"
```

### Task 6: Final legal-safety and regression verification

**Files:**
- Modify only if verification finds a defect.

**Interfaces:**
- Consumes: all prior tasks.
- Produces: a release-ready, programmatically verified UI slice.

- [ ] **Step 1: Format PHP**

Run: `vendor/bin/pint --dirty --format agent`

Expected: clean formatter exit.

- [ ] **Step 2: Run the focused backend suite**

Run: `php artisan test --compact tests/Feature/BureaucracyControllerTest.php tests/Feature/Bureaucracy/CaseQuestionControllerTest.php tests/Feature/Bureaucracy/CaseTaskControllerTest.php tests/Feature/Bureaucracy/CaseFactLifecycleTest.php tests/Feature/Bureaucracy/CaseMatcherTest.php tests/Feature/Bureaucracy/PlanSnapshotTest.php tests/Feature/Bureaucracy/InvestigatedCaseCorpusTest.php`

Expected: PASS.

- [ ] **Step 3: Run frontend checks**

Run: `npm run types:check`

Run: `npm run build`

Run: `npx playwright test tests/Browser/bureaucracy.spec.ts`

Expected: PASS with no browser/console errors.

- [ ] **Step 4: Run catalogue safety gates**

Run: `php artisan bureaucracy:coverage`

Run: `php artisan bureaucracy:import --prune --no-interaction`

Run: `php artisan bureaucracy:coverage`

Expected: import succeeds and both coverage runs report no gaps.

- [ ] **Step 5: Inspect the final diff and repository state**

Run: `git diff --check`

Run: `git status --short`

Expected: no whitespace errors and only intentional files before the final commit.

- [ ] **Step 6: Report the exact verification results**

Record the passing test counts, assertion counts, type/build result, Playwright result, catalogue coverage result, and any remaining concern in the task handoff. If verification required a code fix, return to that owning task's red-green-refactor cycle before reporting completion.
