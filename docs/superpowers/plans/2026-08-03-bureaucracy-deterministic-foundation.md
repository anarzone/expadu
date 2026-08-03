# Bureaucracy Deterministic Foundation Implementation Plan

> **For Codex:** Execute this plan with `superpowers:subagent-driven-development`. Keep DeepSeek V4 Flash limited to bounded test-writing and review tasks; Sol owns architecture, legal-rule approval, production code, and final verification.

**Goal:** Add confirmed case facts, source-gated atomic rules, deterministic coverage states, and stable plan snapshots without deleting the current task catalogue or user progress.

**Architecture:** Keep `tasks` and `user_tasks` as the action catalogue and progress store. Add a bureaucracy case aggregate beside the legacy profile, bootstrap existing onboarding answers as confirmed facts, and match only published rules with approved source metadata. The current page continues to work during this phase; the new matcher is exercised through services and tests before the UI switches to it.

**Tech stack:** Laravel 13, PHP 8.4, Eloquent/PostgreSQL, Symfony YAML, Pest 4.

**Depends on:** Approved specification `docs/superpowers/specs/2026-08-03-bureaucracy-case-worker-design.md`.

**Safety constraints:** Use `php artisan make:* --no-interaction` for Laravel files, then rename generated migrations to the exact planned timestamped paths below before editing if Artisan's timestamp differs. Use Form Requests for writes, never delete `user_tasks`, keep unapproved legacy content out of authoritative match results, and run `vendor/bin/pint --dirty --format agent` after PHP changes.

---

### Task 1: Define the verified fact catalogue

**Files:**

- Create: `app/Bureaucracy/Facts/FactDefinition.php`
- Create: `app/Bureaucracy/Facts/FactRegistry.php`
- Create: `database/seeders/data/bureaucracy/schema/facts.yaml`
- Create: `tests/Unit/Bureaucracy/FactRegistryTest.php`

**Step 1: Write the failing registry tests**

Cover successful loading plus rejection of duplicate keys, unsupported types, invalid enum defaults, missing question text, and an undefined reconfirmation interval. Assert that each definition exposes `key`, `type`, `options`, `question`, `why`, `sensitivity`, `priority`, and `reconfirm_after_days`. Add a value-normalization assertion proving an enum accepts only one of its registered options after an explicit legacy-value mapping; an unmapped value must fail instead of being stored as a free-form string.

**Step 2: Run the focused test and confirm RED**

Run: `php artisan test --compact tests/Unit/Bureaucracy/FactRegistryTest.php`

Expected: failure because the registry and catalogue do not exist.

**Step 3: Implement immutable definitions and a cached registry**

The YAML must define the existing universal facts plus these legally decisive facts:

```yaml
current_residence_title:
  type: enum
  options: [national_d_visa, standard_work_permit, blue_card, family_reunification, settlement_permit_18c, other]
  question: Which German visa or residence title do you currently hold?
  why: Your current title changes the application route and which deadline applies.
  sensitivity: high
  priority: 100
  reconfirm_after_days: 180
residence_title_expires_at:
  type: date
  question: When does your current visa or residence permit expire?
  why: We use this date to warn you before your current permission ends.
  sensitivity: high
  priority: 100
  reconfirm_after_days: 180
case_goal:
  type: enum
  options: [blue_card, renew_current_title, settlement_permit, understand_options]
  question: What do you want to do next with your residence status?
  why: This keeps the checklist focused on your next real decision.
  sensitivity: normal
  priority: 90
  reconfirm_after_days: 180
sponsor_current_title:
  type: enum
  options: [national_d_visa, standard_work_permit, blue_card_pending, blue_card, settlement_permit_18c, other]
  question: Which German residence status does your spouse currently have?
  why: Family-reunification and settlement options depend on the sponsor's current title.
  sensitivity: high
  priority: 95
  reconfirm_after_days: 180
blue_card_qualifying_months:
  type: integer
  question: How many complete months of qualifying Blue Card employment and pension or equivalent contributions can you document?
  why: The 21- and 27-month settlement routes count qualifying months, not simply time in Germany.
  sensitivity: high
  priority: 90
  reconfirm_after_days: 30
family_residence_permit_held_since:
  type: date
  question: Since when have you continuously held your family-reunification residence permit?
  why: Several renewal and settlement routes use the duration of this permit.
  sensitivity: high
  priority: 85
  reconfirm_after_days: 180
marital_household_continues:
  type: boolean
  question: Do you and your spouse still live together as a married household in Germany?
  why: Continuing family residence and an independent residence right follow different rules.
  sensitivity: high
  priority: 95
  reconfirm_after_days: 180
weekly_work_hours:
  type: integer
  question: How many hours per week do you currently work?
  why: One spouse settlement route requires at least 20 hours of work per week.
  sensitivity: normal
  priority: 80
  reconfirm_after_days: 90
livelihood_secured:
  type: enum
  options: ['yes', 'no', unsure]
  question: Is your household livelihood currently secured without Bürgergeld or Sozialhilfe?
  why: Residence and settlement routes can depend on secured livelihood.
  sensitivity: high
  priority: 75
  reconfirm_after_days: 90
housing_sufficient:
  type: enum
  options: ['yes', 'no', unsure]
  question: Do you believe your household has sufficient living space for everyone registered there?
  why: The authority checks sufficient housing for several settlement routes.
  sensitivity: normal
  priority: 60
  reconfirm_after_days: 180
legal_social_knowledge_proved:
  type: enum
  options: ['yes', 'no', unsure]
  question: Can you prove basic knowledge of Germany's legal and social system, for example with Leben in Deutschland?
  why: This is one of the statutory settlement-permit checks.
  sensitivity: normal
  priority: 55
  reconfirm_after_days: 365
```

Also define `entry_mode`, `visa_expires_at`, `german_level`, `citizenship_group`, `purpose`, and `permit_track` so rules never depend on an unregistered key. `FactRegistry::definition(string $key)` must throw a domain exception for an unknown key; `all()` returns a keyed collection.

**Step 4: Run GREEN and commit**

Run: `php artisan test --compact tests/Unit/Bureaucracy/FactRegistryTest.php`

Commit: `feat(bureaucracy): define verified case facts`

---

### Task 2: Persist cases, fact history, conflicts, questions, and snapshots

**Files:**

- Create with Artisan: `database/migrations/2026_08_03_210000_create_bureaucracy_cases_table.php`
- Create with Artisan: `database/migrations/2026_08_03_210001_create_bureaucracy_case_facts_table.php`
- Create with Artisan: `database/migrations/2026_08_03_210002_create_bureaucracy_fact_conflicts_table.php`
- Create with Artisan: `database/migrations/2026_08_03_210003_create_bureaucracy_case_questions_table.php`
- Create with Artisan: `database/migrations/2026_08_03_210004_create_bureaucracy_plan_snapshots_table.php`
- Create: `app/Models/BureaucracyCase.php`
- Create: `app/Models/BureaucracyCaseFact.php`
- Create: `app/Models/BureaucracyFactConflict.php`
- Create: `app/Models/BureaucracyCaseQuestion.php`
- Create: `app/Models/BureaucracyPlanSnapshot.php`
- Create corresponding factories under `database/factories/`
- Modify: `app/Models/User.php`
- Create: `app/Bureaucracy/Facts/CaseFactStore.php`
- Create: `app/Bureaucracy/Facts/LegacyFactBootstrapper.php`
- Create: `tests/Feature/Bureaucracy/CaseFactLifecycleTest.php`

**Step 1: Write lifecycle tests first**

Test these invariants:

- one active case per user;
- legacy onboarding/profile values are bootstrapped once as confirmed facts with source `legacy_profile`;
- a candidate never replaces a confirmed fact;
- an equal candidate can be confirmed without a conflict;
- a different candidate creates an unresolved conflict and leaves the old confirmed value authoritative;
- resolving the conflict supersedes the losing fact, confirms the chosen fact, and increments `fact_version` exactly once;
- a reconfirmation date in the past makes the value unavailable to high-impact matching;
- raw fact history remains append-only.

**Step 2: Run RED**

Run: `php artisan test --compact tests/Feature/Bureaucracy/CaseFactLifecycleTest.php`

**Step 3: Generate and implement focused schema**

Use constrained foreign keys with cascade delete from user → case and case → dependent rows. Required indexed columns:

- `bureaucracy_cases`: unique `user_id`, `status`, unsigned `fact_version` default `1`, nullable consent timestamps, nullable `last_assessed_at`.
- `bureaucracy_case_facts`: `case_id`, indexed `key`, JSON `value`, indexed `state` (`candidate`, `confirmed`, `rejected`, `stale`, `superseded`), `source`, nullable `source_reference`, `confirmed_at`, `reconfirm_at`, `superseded_at`.
- `bureaucracy_fact_conflicts`: `case_id`, `fact_key`, `existing_fact_id`, `candidate_fact_id`, indexed `status`, nullable `resolved_fact_id`, `resolved_at`.
- `bureaucracy_case_questions`: `case_id`, `fact_key`, `attempt`, `asked_at`, nullable `answered_at`, nullable `outcome`; index case/fact/outcome.
- `bureaucracy_plan_snapshots`: `case_id`, `fact_version`, `rules_hash`, JSON `rule_versions`, `coverage_state`, JSON `sections`, JSON `unresolved_facts`, nullable `reassessment_at`, `generated_at`, `superseded_at`; index case/generated_at and case/superseded_at.

Models must use explicit `$fillable`, `casts()`, typed relations, and mirrored defaults. `CaseFactStore` is the only mutation boundary. `LegacyFactBootstrapper` maps the current `ProfileEngine` output plus `german_level` into registered fact keys through explicit per-fact normalization maps, rejects unmapped enum values, and must be idempotent.

**Step 4: Run migration and GREEN**

Run:

```bash
php artisan migrate --no-interaction
php artisan test --compact tests/Feature/Bureaucracy/CaseFactLifecycleTest.php
```

**Step 5: Commit**

Commit: `feat(bureaucracy): persist confirmed case facts`

---

### Task 3: Gate authoritative rules on legal-source metadata

**Files:**

- Create with Artisan: `database/migrations/2026_08_03_210100_add_verification_metadata_to_tasks_table.php`
- Modify: `app/Models/Task.php`
- Modify: `database/factories/TaskFactory.php`
- Modify: `app/Console/Commands/Bureaucracy/ImportTasksCommand.php`
- Modify: `app/Console/Commands/Bureaucracy/CoverageCommand.php`
- Modify: `tests/Feature/BureaucracyV2Test.php`
- Modify: `tests/Feature/BureaucracyCoverageTest.php`

**Step 1: Add failing import/publication tests**

An approved task must fail import before any writes when it lacks any of: `jurisdiction`, `content_version`, `reviewed_by`, `verified_at`, `review_status: approved`, a primary official source, or a source-verification status. `dual_source` requires an implementation source; `single_source_approved` requires the explicit boolean `single_source_approved: true`. Legacy tasks remain importable as `review_status: legacy` but can never enter an authoritative match.

Also test HTTPS and host allowlists for primary sources (`gesetze-im-internet.de`, `eur-lex.europa.eu`, and official German government publications) and implementation sources (`stadt-koeln.de`, `bamf.de`, `make-it-in-germany.com`, other configured `.de` authority hosts). Do not treat arbitrary links in the existing `links` array as legal verification.

**Step 2: Run RED**

Run: `php artisan test --compact tests/Feature/BureaucracyV2Test.php tests/Feature/BureaucracyCoverageTest.php`

**Step 3: Add schema and importer support**

Add task columns: `jurisdiction`, JSON `legal_sources`, `review_status` default `legacy`, `source_verification`, `reviewed_by`, `content_version`, date `effective_from`, date `effective_to`, date `review_due_at`, JSON `conflicts_with`, `coverage_scope` default `case`, and nullable `deadline_fact_key`.

The YAML shape is:

```yaml
jurisdiction: de-nrw-cologne
review_status: approved
reviewed_by: expadu_content_owner
content_version: 2026-08-03.1
source_verification: dual_source
verified_at: 2026-08-03
legal_sources:
  - kind: primary
    label: § 18g AufenthG
    url: https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html
  - kind: implementation
    label: Stadt Köln — Blaue Karte EU
    url: https://www.stadt-koeln.de/service/produkte/20321/index.html
```

`CoverageCommand --fail-on-gap` must treat an approved rule with incomplete/expired source metadata as a failure. Add a 90-day default review schedule for volatile figures/forms/fees and a 365-day default for otherwise stable law-backed rules.

**Step 4: Run GREEN and commit**

Run: `php artisan test --compact tests/Feature/BureaucracyV2Test.php tests/Feature/BureaucracyCoverageTest.php`

Commit: `feat(bureaucracy): enforce rule source approval`

---

### Task 4: Extend deterministic conditions and fact-anchored deadlines

**Files:**

- Modify: `app/Profile/Applicability.php`
- Modify: `app/Enums/DeadlineType.php`
- Modify: `app/Models/Task.php`
- Modify: `app/Console/Commands/Bureaucracy/ImportTasksCommand.php`
- Modify: `tests/Feature/Profile/ProfileEngineTest.php`
- Modify: `tests/Feature/BureaucracyEngineTest.php`

**Step 1: Add boundary tests**

Keep scalar equality and list membership backward compatible. Add tests for `{gte: 20}`, `{lte: 27}`, `{in: [b1, b2, c1, c2]}`, `{present: true}`, and explicit null/invalid operator failure. Import must also reject scalar equality or `in` values that are absent from a registered enum's option list. Missing actual values remain `Unknown`; malformed rule operators fail import rather than evaluating at runtime.

Add `DeadlineType::FactDate`: `deadline_fact_key` identifies a registered date fact and `computeDeadlineFor()` returns that exact date. Test that D-visa application tasks still use `visa_expires_at`, existing-permit renewals use `residence_title_expires_at`, and missing dates produce a paused/unknown deadline instead of an invented one.

**Step 2: Run RED**

Run: `php artisan test --compact tests/Feature/Profile/ProfileEngineTest.php tests/Feature/BureaucracyEngineTest.php`

**Step 3: Implement the smallest compatible evaluator**

Operator objects contain exactly one supported operator. Numeric comparison rejects non-numeric actual values. `present: true` means non-null; `present: false` means null. The importer asks `FactRegistry` to validate every condition operand against the referenced fact type and enum option set before writing any task. `unknownAttributes()` must still return the registered fact key for any unresolved condition.

**Step 4: Run GREEN and commit**

Run: `php artisan test --compact tests/Feature/Profile/ProfileEngineTest.php tests/Feature/BureaucracyEngineTest.php`

Commit: `feat(bureaucracy): support rule boundaries and permit dates`

---

### Task 5: Compose deterministic coverage results and stable snapshots

**Files:**

- Create: `app/Enums/BureaucracyCoverageState.php`
- Create: `app/Bureaucracy/Cases/CaseMatchResult.php`
- Create: `app/Bureaucracy/Cases/CaseMatcher.php`
- Create: `app/Bureaucracy/Cases/QuestionSelector.php`
- Create: `app/Bureaucracy/Cases/CasePlanComposer.php`
- Create: `app/Bureaucracy/Cases/PlanSnapshotStore.php`
- Create: `tests/Feature/Bureaucracy/CaseMatcherTest.php`
- Create: `tests/Feature/Bureaucracy/PlanSnapshotTest.php`

**Step 1: Write state and stability tests**

Assert all four states: `matched`, `needs_information`, `not_covered`, and `conflict`. Universal rules may remain visible but cannot turn an otherwise unsupported case into `matched`. Unknown approved case rules yield `needs_information`; mutually conflicting matched keys yield `conflict`; only non-conflicting matched rules remain in sections.

Question selection must rank: urgent rule risk, branch elimination count, next-action unlock, lower sensitivity, reuse count. It may ask only registered facts, no more than 12 questions per case, and no more than three attempts for one fact branch.

Snapshot tests must prove page-read idempotence: same fact version, approved rule-version hash, task completion/dependency state, and date boundary reuse the same snapshot. A confirmed fact change, approved rule version change, dependency-changing completion, explicit reassessment, or reached `reassessment_at` creates one new snapshot and supersedes the previous snapshot.

**Step 2: Run RED**

Run:

```bash
php artisan test --compact tests/Feature/Bureaucracy/CaseMatcherTest.php
php artisan test --compact tests/Feature/Bureaucracy/PlanSnapshotTest.php
```

**Step 3: Implement services with constructor injection**

`CaseMatcher` receives confirmed, non-stale facts merged over the legacy profile projection and evaluates only `is_published=true AND review_status=approved`. `CaseMatchResult` exposes matched rule keys/versions, missing fact keys, conflict pairs, independently safe rule keys, and coverage state.

`CasePlanComposer` produces only these section keys: `current_status`, `do_now`, `next`, `coming_up`, `options`, `waiting`, `information_needed`, `not_covered`. Every authoritative item contains an approved task key and content version. `PlanSnapshotStore` uses a transaction plus `lockForUpdate()` on the case to prevent duplicate active snapshots.

**Step 4: Run GREEN and commit**

Run both focused files, then:

`php artisan test --compact tests/Feature/BureaucracyEngineTest.php tests/Feature/BureaucracyV2Test.php`

Commit: `feat(bureaucracy): compose stable verified plans`

---

### Task 6: Encode the investigated legal cases as an approved rule pack

**Files:**

- Modify: `database/seeders/data/bureaucracy/non_eu_employee_blue_card.yaml`
- Modify: `database/seeders/data/bureaucracy/family_reunification.yaml`
- Modify: `database/seeders/data/bureaucracy/family_reunification_standard.yaml`
- Modify: `docs/bureaucracy-sources.md`
- Create: `tests/Fixtures/bureaucracy/cases/investigated-cases.php`
- Create: `tests/Feature/Bureaucracy/InvestigatedCaseCorpusTest.php`
- Modify: `app/Bureaucracy/BureaucracyPersonas.php`
- Modify: `app/Console/Commands/QA/SeedPersonasCommand.php`
- Modify: `app/Http/Controllers/QA/PersonaController.php`
- Modify: `tests/Feature/BureaucracyCoverageTest.php`

**Step 1: Write the six-case corpus and confirm RED**

The fixture must use exact confirmed facts and expected rule keys/states for:

1. D-visa holder already working and seeking a first Blue Card: application/preparation rules match and the filing deadline is the visa expiry, never arrival + 90 days.
2. Spouse on a family-reunification D visa while the sponsor's Blue Card is pending: independently safe registration/document/application-preparation rules remain, the spouse's visa expiry is shown, and the sponsor-dependent branch remains `needs_information`; do not promise issuance.
3. Blue Card holder with B1 and 12 qualifying months: show the tracked 21-month option as `coming_up`, not current eligibility; require documented qualifying employment/contribution months and the remaining §9 conditions before an application recommendation.
4. Spouse of a holder of a §18c settlement permit: the §9(3a) option matches only with at least three years holding a residence permit, continuing marital household, at least 20 weekly work hours, and all referenced §9(2) conditions.
5. Spouse near four-year family-permit expiry: renewal under §30(3) is the `do_now` path when marital cohabitation continues; §9(3a) is a conditional option; general §9 remains `coming_up` until five years; §31 must not appear unless marital cohabitation has ended.
6. Unknown/unsupported current title: state is `not_covered`; only universal approved tasks remain and none claims eligibility.

Run: `php artisan test --compact tests/Feature/Bureaucracy/InvestigatedCaseCorpusTest.php`

**Step 2: Replace the broad fast-track card with atomic approved rules**

Use these primary sources and matching official Cologne procedure sources:

- Blue Card issue: `https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html` + `https://www.stadt-koeln.de/service/produkte/20321/index.html`.
- Blue Card settlement: `https://www.gesetze-im-internet.de/aufenthg_2004/__18c.html` + the Cologne Blue Card and skilled-worker settlement pages.
- Spouse issue/renewal: `https://www.gesetze-im-internet.de/aufenthg_2004/__30.html` + `https://www.stadt-koeln.de/service/produkt/aufenthaltserlaubnis-fuer-ehepartner-oder-eingetragenen-lebenspartner-von-auslaendischen-staatsangehoerigen`.
- Spouse of §18c holder: `https://www.gesetze-im-internet.de/aufenthg_2004/__9.html` + the Cologne settlement overview/checklist; mark single-source approved if Cologne has no route-specific §9(3a) page.
- Independent right only after marital cohabitation ends: `https://www.gesetze-im-internet.de/aufenthg_2004/__31.html`; never present it as an ordinary renewal alternative while cohabitation continues.

Do not hardcode salary amounts in descriptions. Existing volatile figures remain config-backed and require a review date. Do not claim that B1 plus elapsed time alone proves eligibility.

**Step 3: Add QA scenario users**

Add a separate `BureaucracyPersonas::caseScenarios()` list for the five investigated cases plus one uncovered case; do not overload the existing branch-coverage roster. `demo()` includes the scenarios. The seed command upserts each stable QA email, reuses its one active case, and synchronizes scenario facts without appending duplicates when the value/source is unchanged. Add a test that runs `qa:seed-personas` twice and asserts stable user, case, confirmed-fact, and `user_task` counts. Preserve the command default password `password`; preserve `E2ETestUserSeeder` password `e2e-password`.

**Step 4: Import, run coverage, and run GREEN**

Run:

```bash
php artisan bureaucracy:import-tasks --prune --no-interaction
php artisan bureaucracy:coverage --full --fail-on-gap --no-interaction
php artisan test --compact tests/Feature/Bureaucracy/InvestigatedCaseCorpusTest.php tests/Feature/BureaucracyCoverageTest.php
```

**Step 5: Commit**

Commit: `feat(bureaucracy): add verified investigated case rules`

---

### Task 7: Foundation verification and rollout guard

**Files:**

- Modify only if failures require it: the files changed in Tasks 1–6

**Step 1: Ask DeepSeek for a bounded read-only review**

Through Cline, provide only the changed file list and test summary. Ask DeepSeek V4 Flash to report schema/test gaps, not to edit production code or judge legal correctness.

**Step 2: Run the foundation verification set**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Unit/Bureaucracy tests/Feature/Bureaucracy tests/Feature/Profile/ProfileEngineTest.php tests/Feature/OnboardingTest.php tests/Feature/RateLimitingTest.php
php artisan bureaucracy:coverage --full --fail-on-gap --no-interaction
```

**Step 3: Inspect the diff for unrelated user changes**

Run: `git diff --stat` and `git status --short`. Stage only foundation files; do not stage the pre-existing dirty onboarding/place/transit changes.

**Step 4: Commit**

Commit: `test(bureaucracy): verify deterministic case foundation`

## Phase acceptance gate

- All six case fixtures return the reviewed state and exact approved rule keys.
- Every authoritative result contains a task key, content version, primary official source, verification date, and approved reviewer metadata.
- Legacy/unapproved tasks cannot satisfy case coverage.
- Confirmed facts are never silently overwritten.
- Repeated reads reuse the active snapshot.
- No DeepSeek or frontier model is required or called in this phase.
