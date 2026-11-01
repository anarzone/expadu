# Bureaucracy Backend Foundation Implementation Plan

> **Superseded on 7 September 2026.** This document detailed only the foundation and did not meet the user's request for the complete solution. Use the [complete backend implementation plan](/Users/anar/Projects/Own/Startups/expadu-app/docs/superpowers/plans/2026-09-07-bureaucracy-complete-solution.md) instead. The old task sequence below is historical and must not be executed as the current plan.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development for independent routine tasks or superpowers:executing-plans for inline execution. Steps use checkbox (`- [ ]`) syntax for tracking. Architecture, security decisions, integrations and final verification remain with the orchestrator.

**Goal:** Begin the approved backend architecture with a safely testable foundation: honest date calculations, consistent question authorization and explicit account-holder/person ownership, without changing the UI or activating family sharing.

**Architecture:** Retain the existing Laravel application, authoritative rules and confirmed-fact pipeline. Deliver a small compatibility-preserving first milestone, then migrate rules and consumers through the separately gated milestones below. Person ownership is additive before existing account ownership is removed.

**Tech Stack:** Current locked Laravel/Pest dependencies; PHP 8.4 and Node 22 for local verification; PostgreSQL/PostGIS and isolated Redis for integration tests. No new runtime dependency.

**Spec:** [Accepted backend architecture](/Users/anar/Projects/Own/Startups/expadu-app/docs/superpowers/specs/2026-09-07-bureaucracy-backend-design.md).

## Global Constraints

- "Separate plans for family members too" is a first-release requirement; a person is not the same entity as a login account.
- "Preserve `Task::authoritative()` and `RuleSourcePolicy` during transition. The successor publication layer must retain or strengthen every check; no temporary direct-query bypass."
- "Skipping means an honest partial profile, not a fallback legal identity."
- "Never let an appointment replace an obligation’s deadline. Never treat missing dates as “no deadline”."
- "The backend selects approved follow-up questions."
- "Do not enable live model calls as part of this architecture work."
- Preserve unrelated working-tree changes and all historical user progress. No catalogue approval, destructive data migration, staging merge or production deployment is authorised by this plan.
- No UI changes. Existing frontend payloads remain compatible until their replacement contract is verified with the redesign task.
- No live database refresh, shared Redis flush, existing secret copying or model-provider calls during testing.
- New source edits use the project’s established conventions and test-first development. Never bypass commit hooks.

## Delivery map

This file is the executable plan for **M1**. The remaining milestones have explicit scope and acceptance boundaries; their detailed code plans are written against the verified interfaces produced by the preceding milestone. Do not treat M1 completion as completion of the architecture.

| Milestone | Deliverable | Acceptance boundary |
|---|---|---|
| M1 — safety and identity foundation | Tasks 1–5 below | Date inputs fail closed; question inputs share one authorization resolver; existing cases acquire explicit person ownership without changing their facts/progress |
| M2 — canonical family/process model | Per-person facts, effective history, relationships, repeatable process instances, scoped access grants and owner-approved sharing flow | Two family members can hold different titles, expiry dates, addresses and progress; updating one only reassesses authorised dependants |
| M3 — approved process modules and question protocol | Separate relevance, coverage, requirement and progress results; reviewed orientation questions; stable criterion/action/document identifiers | No question depends solely on an unapproved route; one unsupported process cannot hide unrelated supported actions |
| M4 — shared assessment and consumer migration | Versioned pure assessment, typed timelines, evidence requirements, compatible page projections, coherent cutover | Bureaucracy, Today, Composer, Paperwork and notifications agree for the same person/process/version; appointments cannot conceal obligation deadlines |
| M5 — bounded AI gateway | Consistent processing consent, subject attribution, provenance, retention and confirmation across entry points | Manual and AI-assisted confirmation produce equivalent decisions; no unconsented transport or cross-person leakage |

M2 must remove the legacy one-case-per-account constraint only after every write/read path is person-scoped. M3 must not auto-approve existing legacy records. M4 must retire independent eligibility hints and phase-based decision shortcuts rather than retaining them as hidden fallbacks. M5 does not include live provider enablement, translations, email sending or tax advice.

## Execution prerequisites

- [x] Read the supplied product audit, trace current source, reproduce the known low-level defects and record the architecture.
- [x] Confirm user approval to prepare a plan and start implementation.
- [ ] Resolve isolated-worktree permission. The current checkout is `staging` with concurrent redesign/backend changes. A worktree was requested; do not switch or stash that checkout while awaiting the answer.
- [ ] In the selected worktree, verify PHP 8.4 and Node 22, install locked dependencies without production setup scripts, and keep the accepted spec with this plan.
- [ ] Use a task-owned test database and Redis namespace/service. Verify effective connection names before the first integration test. Never run `migrate:fresh` against the current app or a shared testing database.
- [ ] Record baseline results before editing application code. Failures are classified and reported; do not amend unrelated code to get a green baseline.

## Task 1: Prevent missing entry facts from creating a permit deadline

**Files:**
- Create: `tests/Unit/Bureaucracy/DeadlineInputSafetyTest.php`
- Modify: `app/Models/Task.php`

**Interfaces:** Preserve `Task::computeDeadlineFor(User $user, ?array $attributes = null): ?Carbon`. Unknown or unsupported entry mode returns `null`, not a guessed visa-free date. Known authored branches retain their current calculation.

- [ ] **Step 1: Add a database-free regression fixture.** Use unsaved Eloquent models with explicit raw attributes and date formats, as in the investigation probe; do not boot app providers or read `.env`. Set the synthetic arrival to `2026-08-01` and an authored `permit_window` task to 90 days.

```php
test('missing entry mode does not invent a permit window', function () {
    $user = new User;
    $user->setDateFormat('Y-m-d H:i:s');
    $user->setRawAttributes(['arrival_date' => '2026-08-01 00:00:00']);
    $task = new Task;
    $task->setRawAttributes(['deadline_type' => 'permit_window', 'deadline_days' => 90]);

    expect($task->computeDeadlineFor($user, ['entry_mode' => null]))->toBeNull();
    expect($task->computeDeadlineFor($user, []))->toBeNull();
});
```

- [ ] **Step 2: Run the test and confirm the intended failure.** Run `vendor/bin/pest tests/Unit/Bureaucracy/DeadlineInputSafetyTest.php --compact`. The old code must produce `2026-10-30` rather than null.
- [ ] **Step 3: Remove the fallback.** In the existing `PermitWindow` match, replace the fallback string with `null`; preserve the `default => null` branch.

```php
DeadlineType::PermitWindow => match ($attributes['entry_mode'] ?? null) {
    'visa_free' => $user->arrival_date
        ? Carbon::parse($user->arrival_date)->addDays($this->deadline_days)
        : null,
    'd_visa' => ($attributes['visa_expires_at'] ?? null)
        ? Carbon::parse($attributes['visa_expires_at'])
        : null,
    default => null,
},
```

- [ ] **Step 4: Verify controls.** Literal expectations: explicit visa-free returns `2026-10-30`; explicit D visa with expiry `2026-09-10` returns that date; no expiry returns null; `has_permit` and an unsupported value return null. These are software fixtures, not new legal rules.
- [ ] **Step 5: Run focused existing deadline tests, Pint on the touched files, and review the diff.** Record that null-result presentation and broader timeline semantics remain M4 work; do not claim every unknown-date UI state is fixed.
- [ ] **Step 6: Commit only this task’s files when the isolated test environment can run the normal hooks.** Commit message: `fix(bureaucracy): do not infer permit deadlines from unknown entry`.

## Task 2: Reject invalid and future permit-duration inputs

**Files:**
- Create: `tests/Unit/Bureaucracy/PermitDurationSafetyTest.php`
- Modify: `app/Bureaucracy/PermanentResidencyEligibility.php`

**Interfaces:** Preserve `PermanentResidencyEligibility::for(Profile $profile): ?array`. Invalid/future dates return null; do not add routes, change legal thresholds or treat duration as full eligibility.

- [ ] **Step 1: Add tests against the real service with a fixed clock.** Build a valid profile from an unsaved user using the existing `ProfileEngine`; an in-memory config container supplies timezone/veedels without loading application services. The future fixture is `2028-09-07` with evaluation on `2026-09-07`. Add impossible `2026-02-30`, malformed text and an empty string. Reset Carbon and container state after each test.

```php
$result = (new PermanentResidencyEligibility)->for($futureProfile);
expect($result)->toBeNull();
```

- [ ] **Step 2: Observe the future-date regression failing.** Run `vendor/bin/pest tests/Unit/Bureaucracy/PermitDurationSafetyTest.php --compact`; the old service returns a hint with 24 months instead of null.
- [ ] **Step 3: Validate the stored date and compare in the correct direction.** Use an exact ISO date check and round-trip validation before computing elapsed months; future dates fail closed.

```php
if (! is_string($heldSince) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $heldSince) !== 1) {
    return null;
}
$start = Carbon::createFromFormat('!Y-m-d', $heldSince);
$today = now()->startOfDay();
if ($start->format('Y-m-d') !== $heldSince || $start->greaterThan($today)) {
    return null;
}
$monthsHeld = (int) $start->diffInMonths($today, false);
```

- [ ] **Step 4: Verify current behavior for valid past dates.** Use literal cases immediately before, at and after the existing duration boundary. Also exercise the current settled/EU/no-date guards. Invalid input must not crash the plan response.
- [ ] **Step 5: Run the focused tests and existing settled/eligibility regressions, format and review.** The legacy hint is still scheduled for replacement in M4; this narrow fix only prevents invalid temporal arithmetic.
- [ ] **Step 6: Commit the task files with `fix(bureaucracy): reject invalid permit duration anchors` after normal hooks pass.**

## Task 3: Resolve the current question consistently for all answer methods

**Files:**
- Create: `app/Bureaucracy/Cases/CurrentCaseQuestion.php`
- Modify: `app/Bureaucracy/Cases/AnswerCaseQuestion.php`
- Modify: `app/Bureaucracy/Ai/ExtractCaseFactAction.php`
- Create: `tests/Feature/Bureaucracy/CaseQuestionInputParityTest.php`

**Interfaces:**
- Produces `CurrentCaseQuestion::for(BureaucracyCase $case, bool $lockForUpdate = false): ?BureaucracyCaseQuestion`.
- Consumes existing `CaseMatcher::match`, `QuestionSelector::current`, `QuestionSelector::currentForKeys`, and `PendingAnswers::forCase`.
- Both answer commands use the resolver while retaining their own active-case, ownership, consent, stale-question and answer-validation checks.

- [ ] **Step 1: Add a fallback-only endpoint regression.** Build a synthetic published legacy rule requiring a registered fact, an owned case with consent and the current fallback question. Use a fake only at `ExtractsCaseFact`, not at question selection or ownership. Assert a candidate response is allowed, but no fact is confirmed and the question remains unanswered until explicit confirmation.

```php
$response = $this->actingAs($user)->postJson(route('bureaucracy.case.messages.store'), [
    'question_id' => $question->id,
    'message' => 'I entered with a national D visa.',
]);
$response->assertSuccessful()->assertJsonPath('outcome', 'candidate');
expect($question->fresh()->answered_at)->toBeNull();
expect($case->facts()->where('state', 'confirmed')->count())->toBe(0);
```

- [ ] **Step 2: Run the regression and observe the existing 403.** Run `php artisan test --compact tests/Feature/Bureaucracy/CaseQuestionInputParityTest.php` against the isolated services only.
- [ ] **Step 3: Introduce the shared resolver and inject it into both commands.** Move the existing approved-first/fallback-second selection expression into one class; do not broaden accepted question IDs.

```php
public function for(BureaucracyCase $case, bool $lockForUpdate = false): ?BureaucracyCaseQuestion
{
    return $this->selector->current($case, $this->matcher->match($case), $lockForUpdate)
        ?? $this->selector->currentForKeys($case, $this->pending->forCase($case), $lockForUpdate);
}
```

- [ ] **Step 4: Verify rejection cases.** Other user, obsolete fallback, already answered question, closed case and absent/withdrawn consent must remain rejected without dispatching to the extractor. The structured confirmation must still accept the same current question and remain idempotent for an identical repeated answer.
- [ ] **Step 5: Run input-parity, `PendingAnswersTest`, `CaseMessageEndpointTest`, `BureaucracyAiPrivacyTest` and `CaseQuestionControllerTest`.** Format all touched PHP and review both behavior and source gates.
- [ ] **Step 6: Commit with `fix(bureaucracy): share current question authorization across inputs`.** This is a compatibility fix, not approval of fallback rules; M3 replaces the fallback with a reviewed protocol.

## Task 4: Add an explicit account-holder identity foundation

**Files:**
- Create: Artisan-generated migrations for `bureaucracy_workspaces`, `bureaucracy_people`, and nullable `bureaucracy_cases.person_id` (separate schema concerns).
- Create: `app/Models/BureaucracyWorkspace.php`
- Create: `app/Models/BureaucracyPerson.php`
- Create: `app/Bureaucracy/People/EnsureAccountHolder.php`
- Modify: `app/Models/BureaucracyCase.php`
- Modify: `app/Bureaucracy/Facts/CaseFactStore.php`
- Create: `tests/Feature/Bureaucracy/AccountHolderIdentityTest.php`

**Schema and interfaces:**
- Workspace: ID, unique `owner_user_id` foreign key, timestamps.
- Person: ID, workspace foreign key, nullable account-user foreign key, unique `(workspace_id, account_user_id)`, timestamps. Do not copy names or other sensitive profile fields unnecessarily; account-holder display can use existing authorised user information.
- Existing case: nullable unique `person_id` foreign key. Keep the existing unique `user_id` compatibility constraint in M1. No new family case is created through a legacy account-only endpoint.
- Produces `EnsureAccountHolder::for(User $actor): BureaucracyPerson`, locking the actor row before idempotent workspace/person creation.
- Produces `EnsureAccountHolder::attach(BureaucracyCase $case, User $actor): void`, verifying actor ownership and refusing an incompatible pre-existing subject before attaching the account-holder person.
- Existing fact bootstrap/synchronization calls `attach` inside its transaction after resolving its owned case; fact values and progress are unchanged.

- [ ] **Step 1: Write integration tests.** Exercise both existing fact-store entry points. The expected ownership is the real actor, not a supplied person ID. Assert repeated bootstrap reuses the same workspace/person; another account gets different IDs; existing confirmed facts and user tasks remain untouched; a mismatched pre-linked person is rejected.

```php
$first = $store->bootstrapConfirmedFacts($user, ['citizenship_group' => 'non_eu']);
$second = $store->synchronizeConfirmedFacts($user, [], 'onboarding');
expect($first->fresh()->person_id)->not->toBeNull()
    ->and($second->person_id)->toBe($first->fresh()->person_id)
    ->and($first->fresh()->person->account_user_id)->toBe($user->id);
```

- [ ] **Step 2: Run and confirm failure from absent person ownership.** Use `php artisan test --compact tests/Feature/Bureaucracy/AccountHolderIdentityTest.php` in the isolated test environment.
- [ ] **Step 3: Generate the new migrations, then implement the schema and resolver.** Do not edit a deployed migration. Models use explicit fillable fields, typed relations and hidden internal ownership metadata where appropriate. Lock/check a persisted case before changing its subject. A mismatch throws `AuthorizationException`; never repair it by silently reassociating facts.

```php
return DB::transaction(function () use ($actor): BureaucracyPerson {
    $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
    $workspace = BureaucracyWorkspace::query()->firstOrCreate(['owner_user_id' => $locked->id]);

    return BureaucracyPerson::query()->firstOrCreate([
        'workspace_id' => $workspace->id,
        'account_user_id' => $locked->id,
    ]);
});
```

- [ ] **Step 4: Integrate only with account-holder bootstrap/synchronization.** Existing cases are attached lazily through those authenticated operations. Do not introduce sharing endpoints, auto-create a spouse from text, copy sponsor answers into another person, migrate all live accounts or change existing case queries to “first person in the household”.
- [ ] **Step 5: Verify ownership isolation, idempotency and data preservation.** Run the identity tests and fact lifecycle/onboarding/persona regressions. Exercise migration up/down/reapply on a disposable database with synthetic records; record any intentionally destructive rollback limitations rather than invoking rollback on app data.
- [ ] **Step 6: Commit with `feat(bureaucracy): establish account holder person ownership`.** Explicitly state that family-member plans are not activated by M1; permissioned multiple-person facts/processes arrive in M2.

## Task 5: Review and hand off the verified milestone

**Files:** This plan’s progress checkboxes and a scoped verification note; no unrelated source edits.

- [ ] Run all added tests and the focused bureaucracy/onboarding suites against isolated services. Record exact commands, totals, failures and whether the worktree has the redesign changes or only committed baseline.
- [ ] Run Pint on explicit changed PHP paths. If any frontend imports/exports were changed accidentally, remove that scope expansion or run the required frontend build; backend-only work does not claim UI verification.
- [ ] Review the complete milestone diff for source-gate bypasses, accidental account/person mixing, unsupported legal content, database safety and lost progress. Use a separate bounded reviewer where available; the orchestrator resolves and verifies all consequential findings.
- [ ] Update task checkboxes only where tests and review substantiate completion. Keep M2–M5 visibly open, including: unknown citizenship/business defaults, appointment-vs-deadline projections, the settled/permanent-residence conflation, per-process coverage, reviewed question protocol, consent versioning and content gaps.
- [ ] Report completed behavior, tests, remaining work and branch/worktree location in plain language. Do not push, merge or deploy without a separate request.

## Current execution record

- Architecture accepted; plan prepared from current source and test setup.
- Baseline checked with PHP 8.4.23 and Node 22.14.0: `vendor/bin/pest tests/Unit/Bureaucracy --compact --do-not-cache-result` — 42 passed, 183 assertions. This database-free baseline does not establish integration-suite readiness.
- Both planning documents' local references were checked: none missing.
- No implementation task is marked complete.
- Worktree choice requested because the existing staging checkout contains concurrent user changes. While that choice is pending, only these planning documents have been added.
