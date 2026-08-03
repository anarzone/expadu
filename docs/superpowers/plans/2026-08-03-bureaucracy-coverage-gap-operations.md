# Bureaucracy Coverage Gap and Manual Review Implementation Plan

> **For Codex:** Execute after deterministic matching is live; DeepSeek extraction may be enabled or disabled. Use `superpowers:subagent-driven-development`. This phase records and operates gaps but never calls a frontier model or publishes model-generated law.

**Goal:** Record unsupported/conflicting branches safely, deduplicate non-identifying patterns, collect optional retention/contact consent, support a manual research packet and verified publication workflow, notify consenting users for reassessment, and enforce retention limits.

**Architecture:** Aggregate rows contain only an HMAC fingerprint, a coarse reason code, state, timestamps, and a count. High-cardinality normalized facts, near-rule keys, and question keys live only in encrypted, explicitly consented, time-limited reports. A coarse cohort summary may be materialized only after at least three matching occurrences. CLI review tools output sanitized packets for the product owner; publication remains the existing version-controlled YAML import plus tests and human approval. Notifications offer reassessment and never mutate an old plan silently.

**Tech stack:** Laravel 13, Eloquent/PostgreSQL, Artisan, scheduled commands, Laravel notifications, Pest 4, existing Inertia/React UI.

**Depends on:** Deterministic foundation complete. Guided UI must expose `not_covered` and `conflict` states before detailed gap consent is offered. Generate migrations with Artisan and rename them to the exact planned paths below before editing if its timestamp differs.

---

### Task 1: Persist aggregate gaps separately from consented case detail

**Files:**

- Create with Artisan: `database/migrations/2026_08_03_230000_create_bureaucracy_coverage_gaps_table.php`
- Create with Artisan: `database/migrations/2026_08_03_230001_create_bureaucracy_case_gap_reports_table.php`
- Create: `app/Models/BureaucracyCoverageGap.php`
- Create: `app/Models/BureaucracyCaseGapReport.php`
- Create corresponding factories under `database/factories/`
- Create: `app/Bureaucracy/Gaps/GapFingerprint.php`
- Create: `app/Bureaucracy/Gaps/CoverageGapRecorder.php`
- Create: `tests/Feature/Bureaucracy/CoverageGapRecorderTest.php`

**Step 1: Write failing privacy and deduplication tests**

Assert:

- `not_covered` and `conflict` assessments create/increment an aggregate gap; `matched` and ordinary `needs_information` do not;
- the same normalized case shape produces one fingerprint and increments `occurrence_count` atomically;
- exact dates, free-text notes, names, email, user/case IDs, permit/card numbers, and message content never enter the fingerprint input or aggregate row;
- the fingerprint uses an HMAC keyed from application config, not a reversible concatenation;
- aggregate rows contain only fingerprint, coverage state, a fixed low-cardinality reason code, coarse urgency band, first/last seen, and count;
- no cohort summary, normalized facts, near-rule keys, or question keys are stored on the aggregate while occurrence count is below three;
- once count reaches three, an optional coarse `cohort_summary` may contain only the fixed low-cardinality fields approved by `GapFingerprint` tests;
- without explicit detail consent, no per-user report detail is retained;
- with consent, one case report stores encrypted detail and `detail_expires_at <= consented_at + 180 days`;
- recording is transaction-safe under duplicate attempts.

**Step 2: Run RED**

Run: `php artisan test --compact tests/Feature/Bureaucracy/CoverageGapRecorderTest.php`

**Step 3: Implement focused schema**

`bureaucracy_coverage_gaps`: unique indexed `fingerprint`, `coverage_state`, fixed enum-like `reason_code`, coarse `urgency`, unsigned `occurrence_count`, nullable JSON `cohort_summary`, `first_seen_at`, `last_seen_at`, indexed `status` (`open`, `researching`, `covered`, `dismissed`), nullable `covered_by_rule_key`, timestamps. `cohort_summary` must remain null until `occurrence_count >= 3`.

`bureaucracy_case_gap_reports`: case FK, gap FK, encrypted JSON `detail` containing the authorized normalized facts/near-rule keys/question keys, `retain_detail`, `notify_when_covered`, consent timestamps, `detail_expires_at`, nullable `notified_at`, timestamps; unique case/gap and indexes on gap/notify and detail expiry.

Fingerprint normalization may include only registered categorical/numeric bands needed to reproduce the matching gap. Dates become broad duration/expiry bands; raw values stay in the optional encrypted report only. Before a three-occurrence cohort exists, even the normalized vector is used transiently to compute the HMAC and then discarded unless the user consented to the encrypted report.

**Step 4: Run GREEN and commit**

Commit: `feat(bureaucracy): record privacy-reduced coverage gaps`

---

### Task 2: Integrate automatic aggregate recording and optional user consent

**Files:**

- Create: `app/Http/Requests/Bureaucracy/UpdateGapConsentRequest.php`
- Create: `app/Http/Controllers/Bureaucracy/GapConsentController.php`
- Modify: `app/Bureaucracy/Cases/PlanSnapshotStore.php`
- Modify: `app/Bureaucracy/Cases/BureaucracyPagePresenter.php`
- Modify: `routes/web.php`
- Modify: `resources/js/components/bureaucracy/coverage-gap-card.tsx`
- Create: `resources/js/components/bureaucracy/coverage-gap-consent-sheet.tsx`
- Modify: `resources/js/components/bureaucracy/types.ts`
- Create: `tests/Feature/Bureaucracy/GapConsentEndpointTest.php`
- Modify: `tests/Browser/bureaucracy-case-worker.spec.ts`

**Step 1: Add failing backend and browser tests**

Route:

```text
PUT bureaucracy/case/gap-consent  bureaucracy.case.gap-consent.update
```

Test separate controls for `retain_detail` and `notify_when_covered`; neither is preselected. With both declined, the aggregate occurrence remains but no user link/detail exists. Withdrawal deletes encrypted detail and contact intent immediately while preserving the anonymous aggregate count. Consent changes do not alter facts or snapshots.

Browser tests assert plain-language consent, the 180-day limit, no response-time promise, official authority/adviser escalation for urgent gaps, and continued visibility of independently verified plan items.

**Step 2: Run RED**

```bash
php artisan test --compact tests/Feature/Bureaucracy/GapConsentEndpointTest.php
npx playwright test tests/Browser/bureaucracy-case-worker.spec.ts --project=chromium --project=mobile
```

**Step 3: Implement integration**

After a new snapshot is committed with `not_covered` or `conflict`, call `CoverageGapRecorder` after the transaction. Aggregate recording is automatic; per-user detail exists only after the explicit consent endpoint. The page payload exposes gap ID/status only to the owning user and never exposes fingerprint internals.

Regenerate Wayfinder after adding the route.

**Step 4: Run GREEN and commit**

Commit: `feat(bureaucracy): add coverage gap consent flow`

---

### Task 3: Add read-only manual review commands

**Files:**

- Create: `app/Console/Commands/Bureaucracy/ListCoverageGapsCommand.php`
- Create: `app/Console/Commands/Bureaucracy/ShowCoverageGapPacketCommand.php`
- Create: `tests/Feature/Bureaucracy/CoverageGapCommandsTest.php`

**Step 1: Write failing command tests**

Commands:

```text
bureaucracy:gaps {--status=open} {--limit=25}
bureaucracy:gap-packet {fingerprint}
```

The list command sorts open gaps by urgent first, occurrence count descending, then oldest first. The packet command writes sanitized JSON to stdout only. For cohorts of at least three it may include the coarse cohort summary. For smaller cohorts it may include normalized facts, near-rule keys, and questions only when at least one unexpired consented report exists, and it derives a privacy-reduced packet in memory without printing encrypted raw detail. It must omit users, case IDs, emails, exact dates, messages, free-text notes, and direct encrypted-field values. Without a three-person cohort or a consented report, the packet contains only state/reason, urgency, frequency, and existing official rule/source references. Unknown fingerprints fail clearly.

**Step 2: Run RED**

Run: `php artisan test --compact tests/Feature/Bureaucracy/CoverageGapCommandsTest.php`

**Step 3: Implement no-write review commands**

These commands cannot update status, generate a draft, browse, call an LLM, or write a file. The product owner may manually copy the sanitized packet for external frontier-model assistance, then must return to official-source verification and version-controlled YAML.

**Step 4: Run GREEN and commit**

Commit: `feat(bureaucracy): add manual gap review packets`

---

### Task 4: Mark a gap covered only through verified publication

**Files:**

- Create: `app/Console/Commands/Bureaucracy/MarkCoverageGapCoveredCommand.php`
- Create: `app/Bureaucracy/Gaps/MarkGapCoveredAction.php`
- Modify: `app/Console/Commands/Bureaucracy/ImportTasksCommand.php`
- Modify: `tests/Feature/Bureaucracy/CoverageGapCommandsTest.php`
- Create: `tests/Feature/Bureaucracy/GapPublicationGateTest.php`

**Step 1: Write the failing publication gate**

Command:

`bureaucracy:gap-covered {fingerprint} {rule-key}`

It must fail unless the rule exists, is published, has `review_status=approved`, has complete non-expired legal-source metadata, passes import validation, and matches the reviewable coarse cohort or consented normalized facts without conflict. It must also fail when focused case-corpus tests for that rule are absent; enforce a scenario fixture carrying the gap fingerprint and expected rule key. If no reviewable cohort/consented facts remain, manual reconstruction and a new test fixture are required before the command may succeed.

**Step 2: Run RED**

Run: `php artisan test --compact tests/Feature/Bureaucracy/GapPublicationGateTest.php tests/Feature/Bureaucracy/CoverageGapCommandsTest.php`

**Step 3: Implement explicit human action**

The import command never auto-closes gaps. After the human has added official-source metadata and regression fixtures, `gap-covered` sets `status=covered`, `covered_by_rule_key`, and completion timestamp. It does not rewrite any user's active snapshot.

**Step 4: Run GREEN and commit**

Commit: `feat(bureaucracy): gate coverage gap publication`

---

### Task 5: Notify consenting users to reassess; never silently apply

**Files:**

- Create: `app/Notifications/BureaucracyCoverageAvailableNotification.php`
- Create: `app/Console/Commands/Bureaucracy/NotifyCoveredGapCommand.php`
- Modify: `app/Models/User.php` only if notification preferences require it
- Create: `tests/Feature/Bureaucracy/NotifyCoveredGapCommandTest.php`

**Step 1: Write failing notification tests**

Command:

`bureaucracy:notify-covered-gap {fingerprint}`

Assert it targets only reports with `notify_when_covered=true`, a covered gap, an unnotified row, and a live user/case. It sends at most once, uses the existing notification channel conventions, and marks `notified_at` only after successful dispatch. It does not confirm/reconfirm facts, increment fact version, or create a snapshot.

Notification copy:

> A newly verified rule may apply to your saved case. Review your facts to update your plan.

Action URL: `/bureaucracy?review-case=1`.

**Step 2: Run RED**

Run: `php artisan test --compact tests/Feature/Bureaucracy/NotifyCoveredGapCommandTest.php`

**Step 3: Implement the explicit command**

Respect current notification preferences and throttling. The user must review/reconfirm relevant stale facts and press reassess before a new plan snapshot is created.

**Step 4: Run GREEN and commit**

Commit: `feat(bureaucracy): notify users of verified coverage`

---

### Task 6: Enforce gap, snapshot, and reconfirmation retention

**Files:**

- Create: `app/Console/Commands/Bureaucracy/PruneCaseHistoryCommand.php`
- Create: `app/Console/Commands/Bureaucracy/MarkStaleCaseFactsCommand.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/Bureaucracy/PruneCaseHistoryCommandTest.php`
- Create: `tests/Feature/Bureaucracy/MarkStaleCaseFactsCommandTest.php`

**Step 1: Write failing time-frozen tests**

`bureaucracy:mark-stale-case-facts` marks only currently `confirmed` time-sensitive facts stale after `reconfirm_at`, increments fact version once per affected case, and creates no new snapshot until the page is assessed. It preserves timeless confirmed facts. Run the command twice at the same frozen time and assert the second run changes no row and does not increment `fact_version` again.

`bureaucracy:prune-case-history`:

- deletes consented gap detail after 180 days and clears contact consent tied to expired detail unless notification-only consent remains valid;
- retains the active snapshot plus at most five newest superseded snapshots;
- deletes other superseded snapshots only after 90 days;
- preserves aggregate fingerprints/counts after detail deletion;
- never deletes active facts, current tasks, or `user_tasks` progress;
- is idempotent and chunked.

**Step 2: Run RED**

Run both command test files.

**Step 3: Implement and schedule**

Schedule both daily with `withoutOverlapping()`. Keep the existing `bureaucracy:prune-case-ai-data` schedule from the AI phase independent so retention also works when AI is disabled.

**Step 4: Run GREEN and commit**

Commit: `feat(bureaucracy): enforce case history retention`

---

### Task 7: Operational and regression verification

**Files:**

- Modify only if failures require it: files changed in Tasks 1–6

**Step 1: Ask DeepSeek for a bounded operations-test audit**

Through Cline, give DeepSeek V4 Flash only the command contracts and test matrix. Ask for missing routine privacy/idempotence cases; do not provide user detail, allow edits, or request legal analysis.

**Step 2: Run the complete gap workflow suite**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/Bureaucracy/CoverageGapRecorderTest.php tests/Feature/Bureaucracy/GapConsentEndpointTest.php tests/Feature/Bureaucracy/CoverageGapCommandsTest.php tests/Feature/Bureaucracy/GapPublicationGateTest.php tests/Feature/Bureaucracy/NotifyCoveredGapCommandTest.php tests/Feature/Bureaucracy/PruneCaseHistoryCommandTest.php tests/Feature/Bureaucracy/MarkStaleCaseFactsCommandTest.php
php artisan bureaucracy:coverage --full --fail-on-gap --no-interaction
npm run build
npx playwright test tests/Browser/bureaucracy-case-worker.spec.ts --project=chromium --project=mobile
```

**Step 3: Audit the absence of runtime frontier integration**

Run:

`rg -n "frontier|OpenAI|Anthropic|Claude|GPT" app/Bureaucracy app/Http/Controllers/Bureaucracy routes config`

Expected: only the approved generic DeepSeek extraction boundary/config documentation, with no frontier client/job/tool in the gap workflow.

**Step 4: Commit**

Commit: `test(bureaucracy): verify manual coverage workflow`

## Phase acceptance gate

- Unsupported/conflicting patterns aggregate without storing identity or raw text.
- Detailed case retention and later notification are independently opt-in and reversible.
- Detailed gap data expires within 180 days; aggregates may remain.
- Manual packets contain no personal data and trigger no model/API call.
- A gap closes only against an approved, source-complete, tested rule.
- Users are invited to reassess; their old snapshot is never silently replaced.
- Active snapshot + five recent snapshots are retained; older superseded snapshots follow the 90-day rule.
- No frontier model exists in runtime code.
