# Complete Bureaucracy Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans for this ordered migration. Independent routine test/fixture work may use superpowers:subagent-driven-development; the orchestrator owns architecture, security, integrations, legal-safety decisions and final verification. Checkboxes describe work to perform, not completed work.

**Goal:** Replace the competing Bureaucracy decision paths with one trustworthy system for each family member, from skippable onboarding through questions, relevant processes, deadlines, paperwork, progress and reminders. Close the known backend defects and provide a tested contract for the separately developed UI.

**Architecture:** A modular monolith inside the existing Laravel application. One person has one fact dossier and several independent, repeatable processes. A pure assessor evaluates confirmed facts against versioned, approved rules. All consumers use its result. Manual answers and optional AI-assisted answers enter the same validation and confirmation pipeline.

**Tech Stack:** Existing locked Laravel, Pest, PostgreSQL/PostGIS and Redis dependencies; PHP 8.4 and Node 22. Existing React/Inertia and Playwright for integration checks, not a new visual redesign. No new model-provider activation or infrastructure platform.

**Spec:** [Accepted architecture and investigation](/Users/anar/Projects/Own/Startups/expadu-app/docs/superpowers/specs/2026-09-07-bureaucracy-backend-design.md). This plan replaces the narrower [foundation plan](/Users/anar/Projects/Own/Startups/expadu-app/docs/superpowers/plans/2026-09-07-bureaucracy-backend-foundation.md).

## Global constraints

- **Owner scope change, 8 September:** do not transfer saved legacy task/document
  progress into the replacement workflows. Preserve account identity and confirmed
  answers; start replacement workflow/evidence readiness from their own explicit
  events. Legacy rows may remain stored but must not influence new totals, readiness,
  completion or reminders. This removes progress-map/replay work from T15 and its
  acceptance gates below, not account/fact safety or QA isolation. No bulk data
  deletion or live reset is authorised by this change.
- Separate plans for family members are required in this release, not an optional next phase. Account membership alone must not grant access to another adult's information.
- Preserve `Task::authoritative()` and every `RuleSourcePolicy` check during migration and in the successor publication adapter. Neither a passing test nor a working link is legal-content approval.
- Unknown is not false, non-EU, visa-free, self-employed, eligible, complete or safe to ignore. A skipped answer stays unknown.
- No AI-authored legal content. AI may propose schema-bound facts; confirmation, authorisation and deterministic assessment remain mandatory.
- Keep historical facts and progress. Do not relabel a past legal title as the current one or treat an appointment as an application.
- No live AI calls, bulk live migration, push, merge or deployment is authorised. The owner subsequently authorised source-based content review; record that provenance honestly, not as an independent legal review.
- Preserve concurrent work. The current `staging` checkout is dirty; do not stash, reset, switch or overwrite it. The authorised isolated checkout is `.claude/worktrees/bureaucracy-backend-v2` on `codex/bureaucracy-backend-v2`.
- All source edits use `apply_patch`, test-first development and normal commit hooks. Tests must use isolated data services, not the running app's database or shared Redis.
- Keep city and authority differences in reviewed data/configuration. Cologne is the first supported jurisdiction, not an assumption embedded in every class.
- This is the complete implementation scope, delivered in ordered increments. Completing an early increment does not complete the solution.

## 1. What the finished solution must do

1. Remember a person's confirmed answers across onboarding and every app surface.
2. Handle planning a move, newly arrived and long-term residence without forcing an inaccurate identity.
3. Maintain independent plans for the account holder, spouse and children, with appropriate access controls.
4. Evaluate renewal, registration, longer-term options and other relevant processes together. Selecting one goal changes priority, not which obligations exist.
5. Ask a short, useful follow-up only when it clarifies orientation or evaluates reviewed guidance. Reuse answers and allow “I don't know” or “later”.
6. Keep supported actions usable when another process is unsupported or missing information.
7. Explain deadlines, appointments, waiting and required documents without conflating them.
8. Give the UI a small, prioritised overview plus complete, consistent detail lists and counts.
9. Let people correct answers, record a real change in circumstances, resume later and see what changed in their plan.
10. Admit coverage gaps and offer a reviewed official verification route. More questions must not masquerade as missing legal knowledge.

**Completion has three distinct gates:** backend implementation verified; UI integration verified; release/content/privacy activation authorised. None is interchangeable with another. An unreviewed catalogue record remains unavailable as a recommendation, even after its software is migrated.

## 2. Issue-to-delivery map

Historical items below include regressions already fixed in earlier commits. They must remain fixed; listing them is not a claim that all are currently broken. Source-derived risks and reproduced defects are distinguished in the accepted investigation.

| ID | Problem or requirement | Tasks | Release evidence |
|---|---|---|---|
| B01 | Checklist, case plan, Today and reminders disagree or use different approval gates | T02, T14 | `ConsumerDecisionParityTest`, `GuidancePublicationBoundaryTest` |
| B02 | Missing citizenship, business type or entry mode becomes an assumption | T03, T06, T10 | `UnknownFactSafetyTest`, `OnboardingDossierIntegrationTest` |
| B03 | One branch/goal hides other simultaneous needs | T06, T08 | `ConcurrentProcessesTest` |
| B04 | Entry history, current title, desired title and sponsor title mixed together | T06, T10 | `ResidenceHistoryTest`, `FamilyDependencyTest` |
| B05 | Questions drawn from unusable rules; valid fallback rejected by AI | T02, T09, T13 | `QuestionProtocolTest`, `AnswerMethodParityTest` |
| B06 | Lifetime question limits, repeat questions, GET consumes interview budget | T09 | `QuestionSessionTest` |
| B07 | One missing/conflicting fact blocks the whole plan | T06, T08 | `AssessmentIsolationTest` |
| B08 | Appointment overrides deadline; old overdue work disappears | T03, T11, T14 | `TimelineSafetyTest`, `ReminderDispatchTest` |
| B09 | Future/invalid date counts as elapsed qualifying time | T03, T06, T08 | `TemporalFactValidationTest` |
| B10 | “I'm settled” means permanent residence or silently completes work | T02, T11 | `CompletionProvenanceTest`, `SettledStatusTest` |
| B11 | Age/duration alone presented as eligibility | T02, T08, T17 | `AssessmentClaimStrengthTest` |
| B12 | Section placement confuses options, prerequisites, waiting and context | T08, T11, T16 | `ProcessStateTest`, `PlanContractTest` |
| B13 | Documents become ready through task completion; conditional papers flattened | T12 | `EvidenceRequirementUseTest` |
| B14 | Stale snapshots after facts, appointments, documents, rules or permissions change | T14 | `AssessmentRevisionTest` |
| B15 | Adult/child plans and permissions absent | T05, T06, T08, T15 | `FamilyPlanIsolationTest`, `DelegationRevocationTest` |
| B16 | Consent differs across entry points; AI provenance/retention gaps | T04, T13 | `ExternalProcessingBoundaryTest`, `AiFactConfirmationTest` |
| B17 | Catalogue restructuring can delete progress or expose partial imports | T07, T15 | `CatalogueReleaseTest`, `MigrationRehearsalTest` |
| B18 | Coverage audit counts fixtures, not supported processes/exceptions | T17 | `CoverageManifestTest`, reviewed coverage inventory |
| B19 | Snapshot/layout change not reflected on running app; push mistaken for deploy | T14, T18 | revision diagnostics and authorised deployment verification |
| B20 | QA switches retain facts, badges or progress; preview alters the real person | T15 | `PersonaSwitchTest`, `FamilyQaPreviewTest` |
| B21 | Refresh loses onboarding; stale drafts or concurrent edits overwrite answers | T06, T10, T16 | `OnboardingDraftTest`, browser draft/reload contract |
| B22 | Move-in questioned before arrival; missing provider proof hides actual occupancy | T06, T10 | `ArrivalAndOccupancyTest` |
| B23 | Inertia validation errors disappear or failures called “ad blocker” | T10, T16 | `OnboardingValidationFeedbackTest`, response/error browser contract |
| B24 | Single-digit/10/leap-day/date bounds and inconsistent date styling | T10, T16 | server temporal tests plus redesigned date-field browser matrix |
| B25 | Pre-commit formatting not staged | T01, T18 | retain current lint-staged integration; staged-diff check after hook |
| ON-2/ON-3 | Expiry before title; expiry required for unlimited residence | T06, T10, T16 | `ResidenceStatusQuestionTest`, `PermanentResidenceSectionTest` |
| BU-1 | Contradictory answers/status; stale “two answers don't match” | T06, T15 | `StaleConflictTest`, `ConflictResolutionResponseTest` |
| BU-2 | Completed/remaining totals cannot be accounted for | T11, T16 | `ProgressAccountingTest`, expanded `bureaucracy-progress.spec.ts` |
| BU-3/BU-4 | Licence documents/links not scoped to the person's route | T07, T12, T17 | `BranchScopedGuidanceTest`, requirement/action branch tests |
| BU-5 | Permanent resident sees contradictory long-term guidance | T06, T08, T17 | `CitizenshipVisibilityTest`, `SettledStatusTest` |
| BU-6a/BU-6b | Duplicate completion surfaces and undeclared “already done” | T11, T16 | progress identities and completion provenance tests |
| BU-7 | Documents/Paperwork unclear | T12, T16 | process-scoped readiness and aggregate Paperwork contract |
| BU-8 | Quick-action destinations fail or have the wrong purpose | T07, T14, T17 | `VerifiedActionDirectoryTest`, link-health report |
| ON-1 | Privacy notice on onboarding | T04, T10, T16, T17 | notice/version contract; reviewed copy and accessible UI link gate |
| ON-4 | Citizenship wording issue was incompletely described | T10, T16 | ordinary-language labels, unknown/other choices and usability review; do not invent the missing original wording |

**Explicit boundary:** LP-1 through LP-9 (landing copy, public calculators, theme, login privacy and other deferred landing issues), non-bureaucracy place/transit/media issues and the whole-app visual redesign are not backend work in this plan. The UI integration in T16 is required for Bureaucracy completion, not permission to overwrite the other chat's design. The historical Google Doc source is [owner feedback triage](/Users/anar/Projects/Own/Startups/expadu-app/docs/2026-08-16-owner-feedback-triage.md). Future translation, email-writing and tax-preparation tools remain future features; this release must not display them as working services.

## 3. Target contracts — implementation decisions

### 3.1 Ownership, people and records

Keep existing users as login identities. Add a Bureaucracy workspace for organisation, not automatic data sharing. Keep `BureaucracyCase` as a person's dossier so encrypted facts and their history can be migrated without inventing a second competing fact store. Add process instances below the dossier.

**Execution refinement, 8 September:** a linked account has one canonical person,
not a duplicate dossier for every household invitation. Workspace memberships are
separate from that identity and from access grants. Accepting an invitation links
the authenticated recipient's existing person to that workspace and grants only
the scopes they explicitly accept; it never copies or merges facts by email/name.
The person's original workspace remains organisational metadata, not access authority.
This closes a duplication risk in the earlier per-workspace account uniqueness.

All new tables use generated migration timestamps, foreign keys, explicit indexes and timestamps. No deployed migration is edited. Table names below are logical names with the `bureaucracy_` prefix unless stated otherwise.

| Entity | Essential fields and constraints |
|---|---|
| `workspaces` | `id`, unique `owner_user_id`; ownership manages the workspace, not another adult's dossier |
| `people` | `id`, original `workspace_id`, nullable globally unique `account_user_id`, encrypted display label, `record_version`; unlinked dependent records contain only the authorised minimum |
| `workspace_people` | unique workspace/person membership; organisation only, never an access grant |
| `invitations` | workspace, inviter, hashed recipient identity and expiring single-use token, requested scopes, accepted/revoked timestamps; acceptance links a canonical authenticated person |
| `access_grants` | `person_id`, `grantee_user_id`, granted scopes, grantor, authority basis/reference, accepted/revoked/expiry timestamps, version; no sensitive dossier in invite tokens |
| `relationships` | two person IDs in an authorised workspace, type, effective interval, confirmation provenance; marriage/sponsor/guardian roles are separate assertions, not inferred from account membership |
| existing `cases` | add `person_id`, unique per person, revision; make legacy `user_id` nullable and use it only for the original account holder during migration; new dependent dossiers use explicit person ownership, not that compatibility column; remove its old unique constraint only after cutover adapters are safe |
| existing `case_facts` | retain encrypted values; add effective interval, recorded time, superseded-by reference and validation/provenance metadata; subject resolved through dossier; serialize overlapping writes by dossier lock |
| `processes` | dossier ID, stable definition ID, occurrence key, origin event/fact reference, jurisdiction, lifecycle state, version; unique dossier/definition/occurrence prevents duplicate creation |
| `process_events` | process ID, action/step ID, event kind, actor/provenance, effective time, encrypted optional metadata; append-only corrections reference the prior event |
| `catalogue_releases` | immutable hash/version, manifest, compiled scalar artifact, activation state and source/review metadata; atomically switch an active release pointer |
| `evidence_items` | person, document-kind ID, encrypted metadata, asserted validity dates, provenance, version; this release adds no file upload/OCR service |
| `requirement_uses` | process, stable requirement ID, evidence ID if selected, explicit confirmation and version of requirement/evidence at confirmation; unique active use per process/requirement |
| `question_sessions` | dossier, scope/reason, current assessment revision, offered/answered/deferred counts, started/completed times; not a lifetime question quota |
| existing `case_questions` | session ID, protocol item/version, subject and dependency token, status; preserve old rows as history |
| `onboarding_drafts` | actor, person, schema version, encrypted partial answers, last step, optimistic version, expires-at; unique actor/person/schema; no draft becomes a confirmed fact automatically |
| `processing_consents` | actor, subject scope, purpose, provider/processor version, notice version, granted/withdrawn/expiry time, single-request binding |
| `outbox_events` | aggregate ID/version, event type, dedupe key, minimal scalar payload, delivered-at; written in the same transaction as state changes |

Access scopes are `view_plan`, `view_facts`, `edit_facts`, `manage_process`, `manage_evidence` and `request_ai`. Sharing and delegation administration require the subject/account-holder or explicitly reviewed guardian authority; they are not implied by `edit_facts`. A plan can disclose sensitive facts, so `view_plan` is not public household access.

Adults link/accept before another member gets dossier access. For dependants who cannot accept, require a separately reviewed guardian/delegation path; a claimed family relationship is not sufficient. Implement and test both paths now, but production activation requires the privacy/authority review in T17. No user-facing “whole family ready” release claim before that gate passes. A person can revoke a grant; check it again on delayed jobs, signed links and AI response confirmation. Shared sponsor data is read only through an explicit, revocable grant or recorded as an attributed report within the applicant's own dossier; never silently copied into the sponsor's dossier.

Account deletion and ending family access are different operations. Revoking a helper must not delete the adult subject's own dossier. Provide authorised subject export/deletion commands, invalidate derived snapshots/candidates, and retain only separately justified minimal audit metadata. Do not automatically merge people across workspaces by name or email. Linked-account reconciliation requires authenticated subject action and explicit provenance-preserving mappings.

### 3.2 Facts and changes

The canonical source is the person dossier, not `ProfileEngine` or `users.bureaucracy_path`. Distinguish:

- birth/citizenship facts; travel/entry events; arrival plan versus actual arrival;
- legal residence title and effective history; document/card expiry; visa expiry;
- actual occupancy, registration status and availability of provider confirmation;
- sponsor status at entry versus sponsor status now;
- employment, qualifying periods and user goals; dates alone cannot establish all eligibility criteria.

Extend the existing registry with type, meaning, subject scope, sensitivity, permissible sources, chronology validation, reconfirmation policy and question reference. `unknown`, `declined` and `not_applicable` are explicit answer states, not arbitrary enum values or false booleans. “Not applicable” is accepted only where the protocol defines it. Unsupported titles remain `other` plus optional user-confirmed description; no guessed legal classification.

Two commands have different meanings:

```text
CorrectFact(person, key, prior_fact_id, value, expected_revision)
RecordFactChange(person, key, value, effective_from, expected_revision)
```

A correction supersedes an erroneous assertion; a change closes the previous effective period and adds a new one. Unknown change dates remain explicitly unknown and block only date-dependent criteria. Conflicts exist only between incompatible live assertions about the same subject/key/effective period. Once either assertion is superseded, the conflict ceases to be actionable. Personal sources disagreeing do not mean the user gave a “wrong” answer; explanation identifies which answers and what resolving them affects.

### 3.3 Rules, coverage and assessment

Define independently versioned process modules, initially compiled from existing YAML with explicit migration maps. Each module contains stable IDs for relevance predicates, criteria, steps, temporal policies, documents, official actions, coverage claims and exclusions. Reusable concepts such as Anmeldung can share definitions while process instances and branch-specific content remain distinct. Never merge records solely because their titles match.

The compiled release accepts only finite, allow-listed operators from the existing applicability system and added tested temporal operators. No executable expressions. Preserve provenance for every content variant. A metadata-only move may preserve approval with an auditable unchanged-content mapping; a semantic change or newly composed legal claim returns to review.

Assessment outputs separate concepts:

```text
relevance: relevant | not_relevant | unknown
coverage: covered | partial | unsupported | review_required
criterion: met | unmet | unknown | conflict
assessment: requirements_met | supported_preparation | not_met | needs_information | not_assessable
workflow: not_started | preparing | blocked | submitted | waiting_authority |
          action_required | completed | cancelled
```

`requirements_met` is allowed only when the approved module declares a complete assessable criterion set and every criterion is met; it is not an authority decision or promise of issuance. A partial module can offer supported preparation without claiming complete eligibility. A goal is a preference/priority, not evidence or an applicability predicate that suppresses unrelated duties. One false alternative does not rule out every route.

```text
AssessPerson(ConfirmedFactView, RelationshipView, ProcessState,
             ApprovedCatalogueRelease, JurisdictionContext, EvaluationClock)
  -> PersonAssessment
```

No network, database writes, model calls or hidden current-time reads inside `AssessPerson`. Return per-process results, criterion reasons, fact dependencies, temporal events, applicable steps/documents, reviewed source references and coverage gaps. Assess only information the acting scope may use. Stable machine reasons link to reviewed UI wording rather than generating legal prose. Read-time policy checks can withdraw stale guidance even if a previously compiled artifact or cache exists.

### 3.4 Questions, onboarding and AI

Use one reviewed `QuestionProtocol`. A question must either establish minimal orientation (person, city, move stage, current status) or evaluate criteria in a relevant approved module. It declares why, who answers, schema, branches unlocked, skip behavior and when to revisit. An unapproved rule alone cannot cause an interview.

One question offer is returned at a time; additional missing facts remain inspectable. Ranking is deterministic: approved time-critical dependencies, user-selected process, number of relevant processes helped, registry priority, stable key. Skip defers a question for that session; “I don't know” stores uncertainty without guessing. A changed relevant fact, explicit resume or a new occurrence can reopen it. Limit consecutive questions per session with a visible pause, never lifetime access to assistance.

Initial configurable product limits are three consecutive question offers before a continue/later choice, three malformed attempts per fact within one session, and a 24-hour offer validity window additionally bound to the dependency revision. Enforce actor-level endpoint throttles across sessions so starting a new session does not evade abuse controls. These are product limits, not legal rules.

GET assesses and reads but does not create offers or consume attempts. `POST question-session/next` persists an idempotent offer bound to person, protocol version and dependency revision. Manual and AI-assisted submission share the same authorisation and validation path. A stale offer gets a structured refresh response; it must not overwrite changed facts.

Onboarding bureaucracy answers are all skippable. A draft can contain incomplete date segments without becoming a valid date or fact. Submission confirms only complete, validated answers; omitted keys are not deletions. Explicit clearing is a separate operation. Planning users need not provide an existing address or actual arrival. Unlimited legal status does not require a legal-title expiry; physical-card expiry is a different optional fact. Changing plans to actual arrival, or correcting accidental answers, uses the same fact commands as Bureaucracy.

Initial draft retention is 30 days after the last edit, with an expiry warning in the contract; completing onboarding or an explicit draft deletion clears it earlier. A schema upgrade must migrate known keys or offer recovery, never apply unknown fields. This is a proposed minimisation setting subject to T17 privacy review, not a statutory retention claim.

AI is an optional input aid, not another assessor. External processing is blocked before transport unless actor/subject access, single-request purpose/provider/notice consent and atomic quotas pass. Do not send text externally merely to decide whether consent is needed. Use a local deterministic entry check or require scoped consent first. All optional external Composer parsers/rankers must pass the same transport boundary when carrying personal context.

AI output is a strict allow-listed candidate set bound to the current offer and explicit subject. Reject legal prose, unknown keys, cross-person guesses and schema violations. User edits or accepts each candidate; only then do the normal fact commands run. Retain `ai_extracted_user_confirmed` provenance and model/schema/consent versions without logging raw prompts. Recheck grants/consent after delayed results. Preserve existing request ceilings and retention upper bounds initially, centralise them in config, and shorten retention where T17's reviewed policy requires it. No live calls are enabled by this work.

### 3.5 Timelines, documents and progress

Temporal event kinds are `legal_due`, `preparation_target`, `appointment`, `submission_recorded`, `authority_follow_up`, `document_expiry`. Every calculated date has a policy/version, known anchor and timezone. Unknown anchors yield `date_unknown` with the needed fact, not “no deadline”. A title's unlimited duration does not imply its physical document is unlimited. No generic overdue-age rule makes a legal risk disappear.

An appointment does not move a deadline. A self-reported submission does not establish legal continuation or authority acceptance. Recording submission changes workflow and records evidence provenance; legal effects require applicable reviewed rules. Blocked prerequisites and awaiting an authority are different states. Store dates as local calendar dates where appropriate, appointment instants with their timezone, and test month-end/leap-year/DST boundaries.

Document requirements use `required`, `conditional`, `not_required`, `unknown` applicability and independent readiness: `missing`, `reported_available`, `confirmed_for_use`, `needs_reconfirmation`. Evidence reuse requires explicit permission and confirmation for each process. Task completion never marks a document present. Requirement changes or evidence expiry invalidate only affected uses. Aggregate Paperwork deduplicates the evidence item, not its per-process requirements.

Progress counts only actionable step instances in the selected scope, not info cards, hypothetical options or hidden legacy duplicates. Each count has the exact step IDs behind it. Reopened work changes the same step's state; a new renewal/move creates a new occurrence. User completion records who asserted it and when, not an authority decision. Preserve old imported progress as attributed history where it cannot be mapped safely.

### 3.6 API, consistency and downstream consumers

New endpoints are authenticated, versioned under `/bureaucracy/v2`; these are internal app routes, not invented legal routes. Scope-bound identifiers alone never authorise access.

| Endpoint family | Contract |
|---|---|
| `GET people`, `GET people/{person}/plan`, `GET processes/{process}` | authorised list/detail; `schema_version`, `assessment_revision`, evaluated-at, next-reassessment-at and coverage |
| `POST people`, invitation/acceptance/revocation commands | minimal identity; explicit grant scopes and accepted authority; no implicit fact import |
| `POST people/{person}/facts/corrections` and `/facts/changes` | expected revision, fact reference, validated value/effective date; structured conflict/stale response |
| `PUT people/{person}/onboarding-draft`, `POST .../onboarding/complete` | optimistic draft version; separate raw draft and confirmed fact transaction |
| question-session/next/answer/defer/resume commands | person + protocol/offer token + idempotency key; one shared manual/AI confirmation contract |
| process events and requirement-use commands | authorised scope, expected process/evidence version, stable step/requirement ID |
| processing consent and extraction commands | single-request consent binding, quota outcome, candidate result only |

Use typed Form Requests, policies and response resources; don't return raw encrypted model structures. Validation returns field/key/reason data, authorisation returns a consistent inaccessible response, stale writes return conflict/refresh data, unavailable AI returns a recoverable manual path. Idempotent retries return the original result without duplicate progress or quota consumption for the same logical attempt.

All mutations transactionally increment relevant versions and write outbox events. Cache JSON/scalars only, not PHP objects. A decision revision includes fact/relationship dependencies, process state, evidence, catalogue and policy versions, evaluation-time boundary, and permission scope for the projection. Cross-person dependency changes invalidate affected plans without disclosing the other person's raw facts. Reassessment must also occur when a temporal threshold or source review expires without a user write.

Encrypt personal snapshot/cache payloads as well as fact values; cache keys contain opaque IDs/versions, not fact values. Permission checks occur before decrypting/serving cached data. Encryption rotation, subject export/deletion and queue cleanup must cover derived copies, not only the original fact rows.

`PlanReadModel` is the sole interface for Bureaucracy, onboarding continuation, Paperwork, Today, context evaluation and reminders. Composer consumes authorised appointment events, never a legal deadline disguised as a calendar booking. Today actions use typed reviewed actions (`open_process`, `prepare_documents`, `submit_online`, `contact_authority`, `view_appointment`, `wait`) rather than a generic “Book appointment”. Labels and external destinations come from reviewed content.

Legacy endpoints remain account-holder-only compatibility adapters during cutover. They must never choose the first family member. No dual decision-engine fallback is allowed after cutover; on failure return an honest unavailable/partial state and an approved verification route where available.

## 4. Ordered implementation tasks

Paths in this section are repository-relative. “Create” marks proposed files, not files already present. New migrations are generated with execution-time timestamps and the named schema operation. For each task: write the described failing tests, observe the intended failure, implement, run its focused suite, format explicit touched files, review the diff, and commit only those files through normal hooks once the isolated environment is ready. Never mark a task complete from code inspection alone.

### T01 — Establish a safe, reproducible baseline

**Depends on:** isolated checkout decision; does not require another architecture approval.

**Create:** `tests/Support/BureaucracyFixtureClock.php`, `scripts/test-bureaucracy-isolation.php`, `docs/verification/bureaucracy-backend-release.md`.
**Inspect/preserve:** `tests/Pest.php`, `phpunit.xml`, `.husky/pre-commit`, `.lintstagedrc.json`, `.github/workflows/ci.yml`. Modify test bootstrap only where needed to enforce the isolation guard.

- [ ] Record committed base, working changes excluded from the isolated checkout, runtime versions and existing suite failures. Do not copy a production `.env` or run dependency scripts that initialise the app.
- [ ] Configure disposable PostgreSQL/PostGIS and Redis services with task-specific identities. The guard must run before Laravel test database refresh/cache cleanup, reject non-testing app mode, missing task-owned service identity and any known app database, and never print connection secrets. A Redis key prefix alone does not make a shared `Cache::flush()` safe.
- [ ] Test the guard itself with synthetic connection configurations; rejected configurations must exit before any connection or deletion. Use a separate disposable service per parallel worker or disable parallelism locally.
- [ ] Capture baseline database-free unit, focused integration and existing full-suite results. Freeze time per fixture and reset the clock between tests. Preserve lint-staged's Pint re-staging behavior and record staged/unstaged scope before commits.

**Exit:** reproducible commands and service identities recorded; no app data affected. The previously observed 42 unit tests/183 assertions are an investigation baseline, not acceptance of this release.

### T02 — Put every existing recommendation behind the same safety boundary

**Depends on:** T01. Deliver before waiting for the new engine.

**Create:** `app/Bureaucracy/GuidancePublication.php`, `app/Bureaucracy/Cases/CurrentCaseQuestion.php`, `app/Bureaucracy/Questions/OrientationQuestions.php`, `database/seeders/data/bureaucracy/schema/orientation-questions.yaml`, `tests/Feature/Bureaucracy/GuidancePublicationBoundaryTest.php`.
**Modify:** `app/Bureaucracy/PathGenerator.php`, `app/Bureaucracy/Cases/PendingAnswers.php`, `app/Bureaucracy/Cases/AnswerCaseQuestion.php`, `app/Bureaucracy/Ai/ExtractCaseFactAction.php`, `app/Bureaucracy/PermanentResidencyEligibility.php`, `app/Http/Controllers/BureaucracyController.php`, `app/Home/HomeFeed.php`, `app/Home/TileComposer.php`, `app/ContextEngine/Evaluators/BureaucracyEvaluator.php`, `app/ContextEngine/Evaluators/PermanentResidencyEvaluator.php`, `app/Console/Commands/Bureaucracy/RemindCommand.php`.

- [ ] Seed synthetic approved, legacy, expired-review, invalid-source and unpublished rules. Assert every recommendation and outbound reminder obeys the authoritative gate, including warm-cache reads. Existing stored progress survives withdrawal.
- [ ] Make `GuidancePublication` delegate to `Task::authoritative()` plus `RuleSourcePolicy` at the delivery boundary. Withdraw unsafe recommendations, retain attributed historical progress, and return a coverage-gap reason instead of substituting another route. Do not delete legacy records.
- [ ] Stop the independent duration-only service from producing personalised eligibility claims. Preserve its callers through a conservative adapter returning reviewed case results or unavailable. In T14, remove its decision authority entirely after all consumers are migrated.
- [ ] Remove the “settled means permanent residence” inference and bulk undeclared task completion. Existing status declarations remain attributed history, not proof of a legal title. Unknown facts needed by approved universal rules must become eligible question dependencies.
- [ ] Replace the fallback question source with approved dependencies plus the explicit orientation registry, not arbitrary published-rule fields. The initial orientation allow-list references existing reviewed basic-question wording and records its product-review version; it does not create legal follow-ups. Until T09 lands, share a single current-question resolver between structured and AI paths; acceptance of a question never approves its originating content.

**Exit/tests:** all publication-boundary cases, existing `CoreSpineApprovalTest`, `PendingAnswersTest`, `SettledStatusTest`, `CompletionProvenanceTest`; no content auto-approval. This is immediate containment, followed by full replacement below—not a “hide the catalogue” completion claim.

### T03 — Remove guessed identity and date behavior immediately

**Depends on:** T01, T02.

**Create:** `app/Bureaucracy/Facts/ConfirmedBureaucracyAttributes.php`, `tests/Unit/Bureaucracy/UnknownFactSafetyTest.php`, `tests/Unit/Bureaucracy/TemporalFactValidationTest.php`, `tests/Feature/Bureaucracy/TimelineSafetyTest.php`.
**Modify:** `app/Bureaucracy/Cases/CaseAttributes.php`, `app/Bureaucracy/Facts/LegacyFactBootstrapper.php`, `app/Models/Task.php`, `app/Models/UserTask.php`, `app/Http/Controllers/BureaucracyController.php`; audit `app/Profile/ProfileEngine.php` callers without breaking unrelated discovery behavior.

- [ ] Test missing citizenship/business/entry answers separately from explicit answers. Bureaucracy must not consume inferred `Profile.isEu = false` as confirmed non-EU citizenship or a missing business classification as liberal profession. Read genuine explicit legacy values with provenance; quarantine branch-derived values for review/reconfirmation.
- [ ] Remove `entry_mode ?? 'visa_free'`. With synthetic arrival `2026-08-01`, missing entry returns unknown; explicit fixture visa-free policy of 90 days yields `2026-10-30`; explicit D-visa expiry `2026-09-10` yields that date. These are software fixtures, not authored legal rules.
- [ ] For the same D-visa fixture, setting appointment `2026-10-15` must leave its underlying deadline at `2026-09-10` in both `UserTask` and controller output. Add separate appointment data; remove generic old-overdue suppression.
- [ ] Reject malformed, impossible and future historical dates before arithmetic. Use exact ISO round-trip validation and signed elapsed calculations. Test leap day, date equal to today, month-end, missing anchor and unlimited-title versus card expiry.

**Exit:** literal assertions reproduce the old bugs and pass after the fix; existing deadline consumers retain separate appointment access. T11 replaces the compatibility fields with typed events.

### T04 — Close external-processing and privacy gaps before extending AI

**Depends on:** T01–T03. Shares the extraction boundary with T02; keep these changes sequential.

**Create:** `app/Privacy/ExternalProcessingGate.php`, `app/Privacy/ProcessingConsentStore.php`, `app/Models/BureaucracyProcessingConsent.php`, a `create_bureaucracy_processing_consents` migration, `config/bureaucracy_privacy.php`, `tests/Feature/Bureaucracy/ExternalProcessingBoundaryTest.php`.
**Modify:** `app/Bureaucracy/Ai/ExtractCaseFactAction.php`, `app/Bureaucracy/Ai/BureaucracyAiQuota.php`, `app/Composer/OpenAiCompatiblePromptParser.php`, `app/Composer/AnthropicCandidateRanker.php`, `app/Http/Controllers/ComposerController.php`, `app/Support/SentryScrubber.php`, `app/Http/Controllers/Bureaucracy/AiConsentController.php`, `routes/console.php`.

- [ ] Use HTTP fakes to assert zero outgoing requests without current purpose/provider/notice consent, after withdrawal and on quota denial. Include parser, optional ranker, retries and malformed provider responses. Gate every external text/context path before transport, including a request whose intent is not yet known.
- [ ] Replace ambiguous case-lifetime consent with explicit single-request grants for the existing “one answer” UX. Do not silently upgrade existing consent to broader processing. Bind requests and retries to an idempotency key and atomically reserve quota.
- [ ] Reject delayed results after revocation; record only minimal operational metadata. Scrub prompts, message text, extracted values, headers and exception bodies from logs/error reporting. Redact before logging, not merely in the final UI.
- [ ] Test scheduled raw-text cleanup, withdrawal cleanup, deletion and failures/retries. Consent audit metadata must not retain the removed text. Keep confirmed facts under the separately documented fact-retention policy.

**Exit:** existing `BureaucracyAiPrivacyTest` and new gateway tests pass with no live provider access. Full family-aware extraction is T13; family grants are enforced before enabling family processing.

### T05 — Implement people, family delegation and ownership isolation

**Depends on:** T01, T04 for consent subject references.

**Create:** `app/Models/BureaucracyWorkspace.php`, `BureaucracyPerson.php`, `BureaucracyAccessGrant.php`, `BureaucracyRelationship.php` in the same models directory; `app/Bureaucracy/People/EnsureAccountHolder.php`, `ManageDelegation.php`, `PersonAccess.php`, `PersonDataLifecycle.php`; `app/Policies/BureaucracyPersonPolicy.php`; `PeopleController`, `DelegationController`, `PersonDataController` and their typed requests/resources under `app/Http/{Controllers,Requests,Resources}/Bureaucracy/V2/`; migrations `create_bureaucracy_people_and_grants` and `add_person_to_bureaucracy_cases`; `tests/Feature/Bureaucracy/FamilyPlanIsolationTest.php`, `DelegationRevocationTest.php`, `PersonDataLifecycleTest.php`.
**Modify:** `app/Models/BureaucracyCase.php`, `routes/web.php` and the fact-store bootstrap.

- [ ] Test two adults and a dependent, separate dossiers, denied unaccepted invitations, forged person IDs, cross-workspace IDs, expired grants and revoked access. Workspace ownership must not pass the adult dossier policy by itself.
- [ ] Implement schema and explicit scope checks from §3.1. Lock the account row while idempotently creating its account-holder person. Existing cases attach only to their verified account-holder; never reassociate a conflicting subject silently.
- [ ] Implement invitation acceptance and scope revocation with hashed expiring single-use tokens, rate limits and no dossier disclosure in responses. Test linked adult self-access and explicit guardian-authority records using synthetic approved-policy fixtures.
- [ ] Implement independent family dossiers through the v2 person selector and access contract; their assessment is added in T08/T14, within this same release. Make the legacy `cases.user_id` nullable for new dependent dossiers while retaining the original self-dossier association. Legacy routes stay account-holder-only; they cannot infer ownership from a null compatibility field. Removing the old unique account-case constraint waits for T15's migration gate.
- [ ] Implement authorised export/deletion and helper revocation as distinct commands. Test deletion of owned derived records, queued work and encrypted copies; revoking a helper leaves the subject's dossier intact. Verify backups/retained audit records against the documented retention policy rather than promising immediate erasure from immutable backups.

**Exit:** separate people are functional in tests, not merely nullable columns. Production guardian/delegation activation remains gated on T17's concrete policy review, not left to an unspecified later design.

### T06 — Make the dossier canonical and preserve life history

**Depends on:** T03, T05.

**Create:** `app/Bureaucracy/Facts/CorrectFact.php`, `RecordFactChange.php`, `ConfirmedFactView.php`, `TemporalFactValidator.php`, `app/Bureaucracy/People/RelationshipFactView.php`; migration `add_effective_history_to_bureaucracy_facts`; tests `ResidenceHistoryTest.php`, `FamilyDependencyTest.php`, `ArrivalAndOccupancyTest.php` under `tests/Feature/Bureaucracy/`.
**Modify:** `app/Bureaucracy/Facts/CaseFactStore.php`, `FactRegistry.php`, `FactDefinition.php`, `app/Models/BureaucracyCaseFact.php`, `BureaucracyFactConflict.php`, `app/Bureaucracy/Cases/ResolveCaseConflict.php`, `database/seeders/data/bureaucracy/schema/facts.yaml`.

- [ ] Test correction versus real change: D visa → Blue Card → settlement title preserves history; correcting a typo does not fabricate a new legal period. Test §9, §18c, generic unknown settlement section, sponsor pending/current/historical status and different household addresses.
- [ ] Implement typed commands, optimistic dossier revisions and transaction locks. Omitted answers do not retire facts. Explicit clears/supersessions obsolete relevant conflicts. Repeating the same confirmed assertion is idempotent and does not manufacture a conflict.
- [ ] Separate occupancy from provider-proof availability and arrival intentions from actual arrival. Retain reported move-in even when proof is missing; only reviewed rules determine obligations/exceptions.
- [ ] Invalidate only dependent assessments after a relationship change. Revoked sponsor access becomes unavailable/needs reconfirmation rather than continuing to expose cached sponsor facts. An applicant's own attributed report is not rewritten as the sponsor's confirmed fact.
- [ ] Validate concurrent edits, overlapping effective periods, unknown transition dates and unsupported enum values; verify encryption and authorisation on serialization.

**Exit/tests:** new tests plus existing fact lifecycle, conflict, `StaleConflictTest`, `ConflictResolutionResponseTest`, onboarding override and permanent-residence section tests.

### T07 — Compile a versioned, review-preserving process catalogue

**Depends on:** T02, T06.

**Create:** `app/Bureaucracy/Catalogue/ProcessDefinition.php`, `CatalogueCompiler.php`, `CatalogueReleaseStore.php`, `VerifiedActionDirectory.php`; models/migration for catalogue releases; `database/seeders/data/bureaucracy/schema/process-schema.json`, `process-map.yaml`, `question-protocol.yaml`, `coverage-manifest.yaml`; `tests/Feature/Bureaucracy/CatalogueReleaseTest.php`, `VerifiedActionDirectoryTest.php`.
**Modify:** `app/Console/Commands/Bureaucracy/ImportTasksCommand.php`, `app/Bureaucracy/RuleSourcePolicy.php`, `config/bureaucracy_sources.php` only for separately approved host decisions, and `.github/workflows/ci.yml`'s two import invocations.

- [ ] Write compiler tests for duplicate stable IDs, unknown facts/operators, cyclic prerequisites, branch references, missing document/action IDs, unsupported jurisdictions, invalid review metadata and incomplete criterion sets claiming complete coverage. Invalid import must leave the active release unchanged.
- [ ] Compile all existing records, including legacy ones, into an inventory with explicit review status. Map existing keys to process/step/variant IDs; retain exact approved prose/source associations where semantics are unchanged. Put unreviewed material in review inventory, never auto-promote it.
- [ ] Stage and validate an immutable artifact, then atomically activate its pointer. Preserve the runtime source gate. Changing content invalidates approval for that changed unit; changing presentation does not silently rewrite legal meaning.
- [ ] Replace destructive `--prune` behavior with retirement and migration mapping. Update both staging and production workflow import commands before a future authorised deployment; retained old keys cannot delete user progress.
- [ ] Give official actions stable purpose/channel/jurisdiction metadata. Link checks distinguish dead from temporarily unverifiable; no arbitrary URL fetch from user input, redirects cannot bypass allow-lists, and link success cannot grant content approval.

**Exit:** all 95 currently inventoried records are accounted for, not necessarily approved; counts are remeasured at implementation. Existing source and branch tests pass. A failed release is invisible to readers and leaves history intact.

### T08 — Build the single deterministic, per-process assessor

**Depends on:** T06, T07.

**Create:** `app/Bureaucracy/Assessment/AssessPerson.php`, `AssessmentInput.php`, `PersonAssessment.php`, `ProcessAssessment.php`, `CriterionResult.php`, `CoverageResult.php`, `DependencyIndex.php`; `app/Bureaucracy/Processes/DiscoverProcesses.php`; unit tests in `tests/Unit/Bureaucracy/Assessment/` and feature tests `ConcurrentProcessesTest.php`, `AssessmentIsolationTest.php`, `AssessmentClaimStrengthTest.php`.
**Reuse:** validated operators in `app/Profile/Applicability.php`; extend only with independently tested operators, not free-form expression evaluation.

- [ ] Express synthetic truth tables for relevance, coverage and criteria. Unknown/conflict propagates only to dependent criteria; OR alternatives and AND requirements keep their distinct meanings. Partial criteria can never produce `requirements_met`.
- [ ] Discover multiple relevant processes from confirmed facts/events; return process proposals with repeatable occurrence keys deterministically, without writing inside the pure assessor. T11 reconciles proposals into persisted instances. Missing title and a desired Blue Card do not fabricate a current Blue Card or eligibility.
- [ ] Evaluate first application, renewal, registration and settlement options separately. A selected renewal goal may prioritise renewal while supported alternatives remain assessable. A permanent resident does not lose independent citizenship discovery because an arrival phase is complete.
- [ ] Return explicit dependencies and reasons for every actionable recommendation and suppressed/unknown criterion. Exclude blocked-by-unapproved-content interviews; retain a coverage gap with approved verification action where available.
- [ ] Test referential transparency, stable ordering and fixed clock boundaries. Identical input yields identical results with zero database/network calls. Do not derive claims from the UI section name.

**Exit:** existing investigated journeys are mapped semantically to the new contract; exact historical legal wording is preserved only where still approved. T17 separately checks current source validity.

### T09 — Replace question fallbacks with a purposeful, resumable protocol

**Depends on:** T06–T08.

**Create:** `app/Bureaucracy/Questions/QuestionProtocol.php`, `OfferNextQuestion.php`, `AnswerQuestion.php`, `DeferQuestion.php`, `QuestionToken.php`; migrations for sessions and legacy-question linkage; tests `QuestionProtocolTest.php`, `QuestionSessionTest.php`, `AnswerMethodParityTest.php`.
**Modify:** existing `QuestionSelector`, `PendingAnswers`, `CurrentCasePlan`, `AnswerCaseQuestion`, question controller/request and v2 routes into adapters; update the T07 protocol data.

- [ ] Test useful orientation even where no process is supported, approved universal-rule missing facts, one fact unlocking several processes, no legal questions solely from legacy rules, and deterministic prioritisation.
- [ ] Persist an offer only on an explicit idempotent next-question command. GET/reload must not increase counts. Enforce per-session pacing; a new renewal or changed fact cannot be blocked by an old lifetime count.
- [ ] Answer/defer/resume verifies actor, person, offer and dependency revision. Reuse already confirmed answers. A stale answer returns current-state information without overwriting a newer confirmation. Defer never records “no”.
- [ ] Share this command path with AI confirmation. Distinguish candidate extraction from confirmation and test the former fallback-only scenario through both methods. An answer must visibly change the relevant assessment or be documented orientation, not lead to an unbounded interview.

**Exit:** no active legacy fallback selector or GET interview mutation remains; the “finish your answers” contract works beyond the previously approved Blue Card/family subset without inventing new legal guidance.

### T10 — Wire truly skippable, resumable onboarding to the same commands

**Depends on:** T06, T09.

**Create:** `app/Onboarding/SaveBureaucracyDraft.php`, `CompleteBureaucracyOnboarding.php`, `app/Models/BureaucracyOnboardingDraft.php`, draft migration, v2 draft/complete controllers/requests; `tests/Feature/Onboarding/OnboardingDraftTest.php`, `OnboardingDossierIntegrationTest.php`.
**Modify:** `app/Onboarding/ApplyOnboardingAnswers.php`, `app/Http/Requests/OnboardingRequest.php`, `app/Http/Controllers/OnboardingController.php` as compatibility adapters.

- [ ] Allow completion with all bureaucracy fields skipped, including actual arrival/address for planners and unknown location. Require only account/security fields outside bureaucracy. Check any non-bureaucracy consumer relying on previously required profile fields and return a safe unavailable/default-display state, not a legal identity.
- [ ] Save encrypted partial drafts by actor/person/schema with optimistic version and a configured expiry. Refresh resumes the step and input; logout/account/person switches cannot load another draft. Completion saves validated confirmed facts transactionally and clears that draft; expiry/deletion never deletes already confirmed facts.
- [ ] On status/title/arrival changes, retract only dependent draft inputs that are now invalid. If confirmed history would be changed, use an explicit correction/change command. Never silently erase a genuine past arrival or permit history.
- [ ] Validate exact dates on final submission and surface per-field errors in both JSON and Inertia flows. Store raw partial date text only in drafts. Test no future historical dates, impossible days, missing dates, unlimited residence and separate card expiry.
- [ ] Produce a reviewable confirmation summary and next-question continuation from the same dossier, with no independent `bureaucracy_path` write authority.

**Exit:** existing planning, skippable, residence-status and validation tests plus the new draft/dossier tests. Browser and visual integration is T16; a backend response alone does not prove a comfortable date picker.

### T11 — Implement process events, honest timelines and auditable progress

**Depends on:** T07, T08.

**Create:** `app/Models/BureaucracyProcess.php`, `BureaucracyProcessEvent.php`; migrations `create_bureaucracy_processes_and_events`; `app/Bureaucracy/Processes/RecordProcessEvent.php`, `ProcessStateMachine.php`, `ProgressSummary.php`; `app/Bureaucracy/Timeline/BuildTimeline.php`, `TemporalEvent.php`; tests `ProcessStateTest.php`, `ProgressAccountingTest.php` under `tests/Feature/Bureaucracy/`.
**Modify:** `app/Bureaucracy/Cases/UpdateCaseTask.php`, existing task controllers/requests and `TimelineSafetyTest.php`; legacy `UserTask` becomes a migration/compatibility record, not a second workflow authority.

- [ ] Test the allowed transitions in §3.3. Require evidence/provenance appropriate to the event: a user can report submission, but cannot mark an authority decision as verified through a generic “done” request. Corrections append events, preserve history and invalidate affected projections.
- [ ] Evaluate temporal policies with known anchors only. Emit expiry/deadline and appointment separately even when they share a date. Test missed deadlines older than the old lapsed threshold, expired review windows, timezone boundaries and unknown dates.
- [ ] Compute prerequisites by stable step IDs within the relevant occurrence. Distinguish “you need to prepare this first” from “submitted and waiting for authority”. Waiting can have a reviewed follow-up policy; do not invent a service response deadline.
- [ ] Completion/reopening is explicit and idempotent. Repeating a renewal creates a new occurrence, not an empty duplicate or a rewrite of last year's completed work. Bulk declarations cannot create legal-title facts.
- [ ] Assert `total = count(unique actionable step IDs)` and that disjoint status sets sum to that total. Every summary count resolves to those exact records, including empty/all-complete cases. Info/options are not completion work. Expired/withdrawn guidance history is separately accounted for, not silently counted as currently actionable.

**Exit:** tasks, counts, timelines and evidence provenance survive reload/concurrent retries. Existing BU-2/BU-6 regression expectations are preserved without pinning the new UI to old section names.

### T12 — Give Paperwork independent, process-scoped readiness

**Depends on:** T05, T07, T11.

**Create:** `app/Models/BureaucracyEvidenceItem.php`, `BureaucracyRequirementUse.php`; migration `create_bureaucracy_evidence_and_requirement_uses`; `app/Bureaucracy/Evidence/ConfirmRequirementUse.php`, `EvidenceRequirements.php`, `PaperworkReadModel.php`; tests `EvidenceRequirementUseTest.php`, `EvidenceAccessTest.php`.
**Modify:** `app/Http/Controllers/BureaucracyCaseTaskDocumentsController.php`, its request, document projections in the old case composer and v2 resources.

- [ ] Compile branch-specific requirements without losing conditions. With an unknown branch, return conditional/unknown requirements and the relevant question; don't present every possible paper as mandatory.
- [ ] Test one evidence item used for two processes: confirming it for one leaves the other unconfirmed; completing a task does not create evidence; changing/expiring evidence marks affected uses for reconfirmation. A shared household paper requires permission for each subject/use.
- [ ] Bind readiness confirmation to requirement and evidence versions. Changing catalogue wording alone does not revoke readiness unless requirement semantics changed; the compiler records that distinction. Invalid attachment IDs and cross-person access are rejected.
- [ ] Return “prepare for this process” and “find my recorded documents” views from one model. Preserve source/why/conditionality, readable labels and exact processes requiring each item. Do not fabricate uploaded files from legacy checkmarks.
- [ ] Include capabilities metadata for future tools with `available = false`. No translation, outbound email, tax advice, OCR or upload processing is implemented or implied here.

**Exit:** existing `BranchScopedGuidanceTest` and `DocumentApplicabilityTest` plus new readiness/access tests. BU-7 is addressed as the later clarified Paperwork requirement, not guessed from the original vague note.

### T13 — Integrate bounded AI with the person/question model

**Depends on:** T04–T06, T09.

**Create:** `app/Bureaucracy/Ai/ConfirmExtractedFacts.php`, `ExtractionContext.php`, `tests/Feature/Bureaucracy/AiFactConfirmationTest.php`.
**Modify:** existing extraction action/request/result/schema, quota and message models, `DeepSeekCaseFactExtractor.php`, message controller and the T09 answer adapter.

- [ ] Build the extraction context from the active question, explicitly selected person and minimum necessary confirmed context. Do not send whole household dossiers. Unclear person references produce a clarification candidate, never automatic reassignment.
- [ ] Validate strict tool output: allow-listed fact keys/types, finite candidates, value/date bounds, no additional properties, no legal prose or arbitrary instructions. Test prompt injection, malformed JSON, wrong subject, fabricated legal-title keys, timeout and repeated response.
- [ ] Show candidates without saving confirmed facts. Confirmation passes `AnswerQuestion`/fact commands and rechecks current consent, grants, question dependency revision and candidate binding. Edited candidates are validated identically to manual input.
- [ ] Assert equivalent confirmed facts produce equivalent assessments through manual and AI-assisted entry, while retaining distinct truthful provenance. Rejected/expired candidates cannot later be replayed.
- [ ] Failures keep the manual path available and consume only the documented quota for the logical request. Test cleanup and withdrawal during an in-flight request with HTTP fakes.

**Exit:** extraction contract, privacy, answer parity and candidate-confirmation tests pass. Live credentials remain disabled; no UI or report says the model has been live-tested.

### T14 — Cut every consumer over to one versioned read model

**Depends on:** T08–T13.

**Create:** `app/Bureaucracy/ReadModel/PlanReadModel.php`, `LegacyPlanAdapter.php`, `PersonPlanResource.php`, `app/Bureaucracy/Assessment/AssessmentRevision.php`, `ReassessAffectedPeople.php`; outbox model/migration and publisher/job; tests `ConsumerDecisionParityTest.php`, `AssessmentRevisionTest.php`, `ReminderDispatchTest.php`.
**Modify:** `CaseMatcher`, `CasePlanComposer`, `CasePlanPresenter`, `CurrentCasePlan`, `PlanSnapshotStore`, `PathGenerator`, `OpenTaskCount`, `BureaucracyController`; `app/Home/HomeFeed.php`, `TileComposer.php`; both bureaucracy/permanent-residency context evaluators and notifications; `app/Console/Commands/Bureaucracy/RemindCommand.php`; `app/Composer/AppointmentRepository.php`; `routes/console.php`.

- [ ] Serialize a versioned scalar read model from the pure assessment plus authorised workflow/evidence state. Bound the overview to the next three actionable items and one question while returning complete topic/process links, counts and a way to reach all records; the UI decides layout, not legal meaning.
- [ ] Replace every independent applicability/deadline/eligibility consumer with this interface. The legacy profile branch may remain for unrelated discovery compatibility but cannot decide bureaucracy guidance. Public calculator consumers of legacy services must be identified and kept source-gated/unavailable until their separately scoped work is approved; do not silently break them.
- [ ] Give Today typed actions from the process/action directory. Composer receives only authorised appointment instants and durations; no legal deadline is added as a meeting. Reminders evaluate current source validity, grants, preferences and dedupe state at dispatch, not just enqueue time.
- [ ] Commit state versions and outbox entries atomically. Retry outbox delivery idempotently. Reassess on facts, relationship changes, workflow/evidence changes, catalogue activation and due temporal/source boundaries. GET can compute a missing/stale assessment without consuming interview state. Extend `PersonDataLifecycle` tests to every newly introduced snapshot, evidence, process, queue and extraction record; an earlier identity-only deletion test is insufficient.
- [ ] Cache scalars keyed by full input and projection scope. Test cold/warm equality, rule withdrawal, document/appointment edits, midnight/threshold transitions, two workers, permission revocation and a delayed notification. Another user's cached plan is never a fallback.
- [ ] Make `LegacyPlanAdapter` project the same result for the account holder. Remove active independent eligibility/hint code only after call-site search and parity tests prove consumers have moved.

**Exit:** same subject/revision produces the same rule decisions, actions, dates and progress everywhere. No second-engine fallback remains. Backend errors return a truthful unavailable state rather than cached, withdrawn guidance.

### T15 — Migrate existing data without guessing or losing work

**Depends on:** T05–T14.

**Scope amendment:** saved-progress transfer was waived by the owner. Do not build
`ProgressMigrationMap`, merge/split progress replay, old checkbox availability
imports, or legacy workflow/appointment/submission imports. Their older checklist
entries below are superseded. Retain existing records without replaying them. The
remaining work is safe account/dossier attachment, confirmed-fact provenance,
idempotent fact conversion, explicit uncertainty and QA reset/preview isolation.
Recorded appointments in new v2 processes remain supported; this amendment does
not authorise deleting either old appointments or new v2 work.

**Create:** `app/Bureaucracy/Migration/LegacyMigrationPlanner.php`, `BackfillPersonDossiers.php`, `ProgressMigrationMap.php`, `ReconcileMigration.php`; `app/Console/Commands/Bureaucracy/MigrateDossiersCommand.php`; `tests/Feature/Bureaucracy/MigrationRehearsalTest.php`, `FamilyQaPreviewTest.php`; schema cutover migration removing the unique `cases.user_id` constraint only at the safe point.
**Modify:** `app/Bureaucracy/QA/ResetPersonaState.php`, `ScenarioFactSynchronizer.php`, `BureaucracyPersonas.php`, `BureaucracyDemoController.php` and existing persona tests.

- [ ] Implement `--dry-run` as a read-only mapping report. Account-holder attachment is idempotent; counts/checksums and unresolved mappings are recorded without printing personal fact values. No spouse dossier is auto-created from an account-holder's sponsor field.
- [ ] Backfill in bounded transactions with resumable cursors and per-record version checks. Explicit user-confirmed facts retain provenance; inferred non-EU/business/path/settled interpretations become migration uncertainties, not trusted new facts. Preserve both values when the old source cannot establish which was current.
- [ ] Map task keys using reviewed T07 mappings. One-to-one progress is preserved. Merge/split cases use explicit step-level mappings or retain unmapped history and request reconfirmation; never mark a newly introduced requirement complete because a former broad task was complete.
- [ ] Import document checkmarks as attributed availability reports for that legacy use only. Preserve appointment and submission events separately. Old snapshots are archived/untrusted for decisions; reset only task-owned cache namespaces.
- [ ] Remove the legacy unique account constraint after all account-only routes select the explicit account holder and all new commands enforce person scope. Re-run backfill and assert zero duplicate people/processes/events.
- [ ] Keep QA preview read-only and separate from real dossiers. Test A→B→A, repeated same persona, all title options, another account's unchanged records, family grants and no stale badge/conflict. Do not give real users admin rights as part of migration. Implementation resolution, 8 September: the old “become”/reset commands target the acting real account and have no trusted disposable-subject boundary, so they are retired with explicit 410 errors rather than reused for family QA. The canonical in-memory preview needs no reset; persistent interaction tests run in isolated test services. Frontend preview integration remains required. Legacy CLI provisioning is not a cutover step and must not overwrite existing accounts.

**Exit:** restore/replay on disposable databases passes, account/fact/progress totals reconcile, unresolved records remain visibly unresolved, and no deletion/prune is necessary for cutover.

### T16 — Deliver and verify the UI integration contract

**Depends on:** T10–T15 and the other task's accepted UI implementation.

**Create:** `docs/contracts/bureaucracy-v2.md`, `tests/Fixtures/bureaucracy/v2-plan-contract.json`, `tests/Feature/Bureaucracy/PlanContractTest.php`, `tests/Browser/bureaucracy-v2-journeys.spec.ts`.
**Coordinate modifications, not overwrite:** `resources/js/pages/onboarding.tsx`, `resources/js/pages/dashboard.tsx`, bureaucracy components, `resources/js/components/date-field.tsx`, `resources/js/app.tsx`, `tests/Browser/bureaucracy-progress.spec.ts` and existing onboarding browser specs.

- [ ] Publish typed request/response examples for complete, partial, unsupported, conflict, no-data, waiting, overdue, all-complete and revoked-access cases. Include person identity, revision, question reason, typed actions, exact progress IDs, dates and conditional documents. Label fixture content as sample data.
- [ ] Verify the redesigned UI reads this contract and does not recalculate rules, suppress unknown states or hardcode generic actions. Questions must be visible when useful without nine parallel question cards or a permanent interview requirement. Summary counts open the actual underlying records.
- [ ] Programmatically walk onboarding → confirmation → plan → answer → completion/reopen → reload for the historical journeys in §5. Check family switching and direct deep links without repeating onboarding. When a question is skipped, other known actions remain usable.
- [ ] Exercise the real date component: typing 1/10/31, replacing selected digits, Tab/blur padding, backspace/paste, calendar selection, keyboard-only operation, leap day, invalid month/day, min/max, empty optional date, year navigation, refresh and field errors. Run desktop/mobile layouts; verify normal font weight and readable visible validation. A server-only test cannot close this item.
- [ ] Preserve privacy links and distinguish session expiry/validation/forbidden/server errors from an “ad blocker” message. Verify accessibility of focus movement, field labels, error association and reachable completed/conditional information.
- [ ] Run TypeScript checks, lint, production build and relevant browser matrix on the integrated revision. Capture proof of rendered states for the release report; no unrelated redesign work is claimed.

**Exit:** backend-ready and integrated-ready are reported separately. This task cannot be marked complete while the UI still uses the old competing payloads, even if the backend tests pass.

### T17 — Close content/coverage gaps through an explicit review pipeline

**Depends on:** T07, T08, T15; privacy gates also cover T04/T05/T13.

**Create:** `docs/bureaucracy-gaps/2026-09-07-release-review.md`, `tests/Feature/Bureaucracy/CoverageManifestTest.php`, `tests/Fixtures/bureaucracy/v2-coverage-matrix.php`.
**Modify:** `app/Console/Commands/Bureaucracy/CoverageCommand.php`, `CheckLinksCommand.php`, the T07 coverage manifest and relevant YAML only after the appropriate approval.

- [ ] Inventory each process/variant by city, stage, subject/relationship and known exceptions. Record `covered`, `partial`, `unsupported` or `review_required` with exact missing criteria/content/source and responsible review action. Structural fixture coverage is not a percentage of legal situations covered.
- [ ] Review all existing source-approved records for schema/text consistency, including the 12–20-month predicate with fixed “12 months” text. The implementation must not generate replacement legal prose; prepare the inconsistency and source evidence for a human-approved amendment, or withdraw the inconsistent claim and expose the gap.
- [ ] Explicitly assess registration, tax ID, insurance, licence, first residence/renewal, family sponsor changes, settlement options and citizenship discovery against the existing content inventory. Keep §9/§18c distinct. Do not claim immediate eligibility from the historical three-year expectation or a selected QA label.
- [ ] Record decisions on existing content blockers such as Rundfunkbeitrag's source-host basis and the church-tax figure; a failed host review leaves that claim unavailable. No new host is approved simply to make tests green.
- [ ] Complete a privacy/authority checklist: adult acceptance/revocation; dependent guardian basis; cross-household/linked-account handling; processor and notice versions; subject-specific AI permission; retention/deletion/export; lawful-purpose documentation and accessibility of notices. Technical implementation can be tested before sign-off, but activation cannot be silently substituted for it.
- [ ] Gate release on no unexplained regressions in already approved supported journeys, no question without a valid purpose, no unreviewed recommendation and an explicit outcome for every declared coverage fixture. Unsupported combinations pass only by exposing the right gap without invented advice.

**Exit:** a reviewer can approve specific content units and activation policies from concrete evidence; unresolved approvals remain named release blockers, not hidden development work. All software tasks still have executable acceptance tests independent of live approval.

### T18 — Verify end to end, then support an authorised cutover and rollback

**Depends on:** T01–T17; actual push/deployment needs the user's separate instruction.

**Create/update:** `docs/verification/bureaucracy-backend-release.md`, `docs/runbooks/bureaucracy-v2-cutover.md`, rollback/reconciliation tests in `tests/Feature/Bureaucracy/MigrationRehearsalTest.php`.
**Modify:** `config/bureaucracy.php` (create if absent) for evaluation/cutover flags; CI only for the required checks and non-destructive release activation procedure.

- [ ] Run all focused unit/integration suites, full PHP suite, catalogue validation, coverage audit, frontend checks/build and integrated browser tests in isolated services. Record exact revision, commands, pass/fail totals, skipped cases and proof artifacts. Do not reuse a previous branch's green CI as this branch's verification.
- [ ] Independently review security, legal-source gates, concurrency and migration diffs. Review every routine delegated change. Search for remaining legacy decision calls, account-only family queries, appointment-as-deadline mappings and unsanitised external processing.
- [ ] Rehearse shadow evaluation, mapped backfill, activation and recovery on synthetic databases. Compare meaningful per-person actions/dates/coverage, not just JSON section names. No unexplained regression in approved guidance is acceptable.
- [ ] On future authorisation, take a recoverable backup, deploy additive schema, backfill with reconciliation, run read-only shadow assessments, then enable the shared read model for an explicitly selected staging cohort. Shadow mode must not ask questions, send notifications or consume AI quotas.
- [ ] Verify running revision, active catalogue version, API/consumer parity and real environment data through authorised checks. Confirm both deployment jobs no longer prune user work. A healthy `/up` or push alone is insufficient.
- [ ] Activate for the complete intended family scope only after T17 approval and T16 integration pass. Check permission-denial rates, unknown/conflict rates, question abandonment, source withdrawals, duplicate reminders and migration errors using non-sensitive metrics. A spike triggers investigation, not relaxed rules.
- [ ] Roll back presentation/read flags or catalogue pointer only if the previous adapter can preserve current facts/processes and still enforce current source validity. Keep additive tables and new events. If the old reader cannot represent family data safely, disable affected writes and serve a truthful unavailable state; do not revert to the unsafe old engine or drop new data. Restore database backups only through a separately authorised recovery procedure with reconciliation of post-backup writes.

**Exit:** completion requires verified implementation, integrated UI, reviewed activation and observed running state for an authorised deployment. Until deployment is requested, deliver code/tests and the cutover runbook without claiming it is live.

## 5. Release acceptance journeys

The six earlier investigated journeys are regression inputs, not new legal approval. Their old corpus is frozen at `2026-08-03 10:00:00`. Preserve meaningful expectations with the original approved fixture version; evaluate current publication validity separately. Never move review dates merely to make a test pass.

| Journey | Required behavior |
|---|---|
| D visa → first Blue Card | Preparation and submission remain available with fixture deadline `2026-10-03`; adding a later appointment does not move it. Desired title is not current title. |
| Joining spouse while sponsor's Blue Card is pending | Supported first-permit preparation and registration remain available; the fixture needs `livelihood_secured`; no promise of issuance. Sponsor history and current status can differ. |
| Blue Card, B1, 12 qualifying months | The existing reviewed tracking route is not immediate eligibility. Test the full authored 12–20 range and surface the fixed-copy mismatch for review rather than repeating “12” for every case. |
| Spouse of a §18c holder after three years | Preserve the conditional reviewed option and authority-verification qualifier. A §9 sponsor must not be silently converted to §18c. |
| Family-permit renewal after almost four years | Renewal remains actionable with fixture deadline `2026-09-03`; supported longer-term alternatives remain separate; no separation route when the household facts exclude it. |
| Unsupported current title, desired Blue Card | No invented route. Preserve approved universal verification help and make only the affected process unsupported. |
| All bureaucracy answers skipped | Onboarding completes; no inferred citizenship, entry mode, title, address or deadline. Useful orientation remains resumable. |
| Planning, arrived, changing address | Actual arrival/occupancy are not required before moving; switching a mistaken draft clears dependent draft fields without deleting genuine history. Missing registration proof does not erase occupancy. |
| Permanent resident with employment and a new move | Separate current legal status, employment and address process; no expiry required for unlimited title, no “already settled” suppression of unrelated processes. |
| Account holder + spouse + child | Different title/expiry/progress and document uses; a sponsor update affects only authorised dependent assessments. Child and adult grant rules are tested separately. |
| Renewal plus settlement option plus registration | Several processes coexist; choosing a goal changes order, not applicability. One conflict affects only dependent criteria. |
| New renewal after prior completion | New occurrence, old progress retained, question session resumed with relevant current facts; no lifetime interview lockout. |
| Stale edit and delayed AI | Concurrent manual edit, consent withdrawal and grant revocation prevent a delayed candidate from overwriting or leaking data. |
| Source withdrawn or jurisdiction changed | Guidance is re-evaluated on warm reads and before notification. Unsupported local channels do not inherit Cologne's instructions. |
| Documents and progress | Task completion does not confirm papers; one use does not confirm another; every count equals the exact reachable record set. |
| QA preview and persona reset | Preview never edits real people; A→B→A and repeat switches isolate state, including facts, conflicts, tasks, documents and badges. |

Expand the canonical persona suite with boundary/property tests across citizenship uncertainty, entry history, title types, goals, dates and relationship changes. Cover the tri-state evaluator algebra and pairwise interactions rather than claiming that an arbitrary number of personas covers every real-world case. Run the persona chronology tests at multiple frozen dates so relative fixture labels do not drift into impossible histories.

## 6. Verification commands and gates

After T01's environment guard passes, use the project's verified PHP/Node runtimes. These commands are instructions for execution, not results of this planning turn:

```sh
vendor/bin/pest tests/Unit/Bureaucracy --compact --do-not-cache-result
php artisan test --compact tests/Feature/Bureaucracy tests/Feature/Onboarding
php artisan test --compact
php artisan bureaucracy:coverage
php artisan bureaucracy:check-links
npm run types:check
npm run lint:check
npm run build
npm run test:e2e -- tests/Browser/bureaucracy-v2-journeys.spec.ts tests/Browser/bureaucracy-progress.spec.ts
```

Before using a named package script/command, verify it exists in the selected execution revision; use the exact existing equivalent if renamed and record it. The isolated imported test catalogue must be explicit. Live link-health checks are read-only and separate from deterministic CI: distinguish network failures from proven dead destinations. Use the repository's Playwright script/config for the integrated v2 journey specs, with stale service workers blocked and no live user accounts. Format only explicit changed files; normal hooks still apply.

Each task's test files named above form its focused red/green suite. New tests use literal expected outcomes or independent truth tables, not assertions calculated by the implementation under test. A test failure caused by a missing class alone is not sufficient evidence for an existing bug: the early safety regressions must first execute the old behavior and reproduce the wrong result.

**Release thresholds:** no known critical/high source-gate, privacy, cross-person, deadline or data-loss defect open; all declared process fixtures have explicit coverage outcomes; zero unexplained data-loss/reconciliation differences; all deployed consumers share the same assessment contract; no known Bureaucracy UI integration regression deferred under a “backend complete” label. Legal content and policy reviews must be current at activation, not merely at coding time.

## 7. Delivery reporting and current record

Report progress by outcome, not number of classes: safety boundary closed; family/facts working; rules/questions working; processes/paperwork working; consumers migrated; data reconciled; UI integrated; release verified. Short updates should identify completed evidence, the current task and any specific blocker. Do not declare the complete solution delivered at the family-schema or first-milestone stage.

- [x] Complete local/source investigation and read the supplied product design audit.
- [x] Record the chosen architecture and the separate-family-plans requirement.
- [x] Replace the partial first-milestone plan with this end-to-end implementation scope.
- [x] Self-review the complete plan for task dependencies, family ownership during migration, source/privacy boundaries and UI handoff. Verify all 18 task blocks, 35 issue-map rows, local document links and absence of unresolved plan placeholders. These are document checks, not application acceptance tests.
- [ ] T01–T18 implementation, tests and review.
- [ ] Required content/privacy activation approvals and UI integration.
- [ ] Separately authorised deployment and running-state verification.

**Planning-turn status:** application code has not been changed by this plan. The user's unrelated working changes remain untouched. No live AI, migration, import, account mutation, merge or deployment has been performed. This document is the complete solution plan, not a claim that the solution is already built.
