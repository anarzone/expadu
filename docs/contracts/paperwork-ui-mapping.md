# Paperwork UI ↔ bureaucracy v2 API mapping

Status: analysis draft, 7 October 2026. Read-only comparison of the Paperwork
prototype (`prototype/dev/design/bureaucracy-next/`, main checkout, 7 October
"sample day" state) with this branch's v2 backend. This document neither changes
the prototype nor confirms that the UI works. Where it mentions the backend, it
describes code in this worktree as of the date above.

Conventions

- `P/` = `prototype/dev/design/bureaucracy-next/`. Line numbers refer to that tree.
- All endpoints start with `/bureaucracy/v2`. `J` = `jurisdiction=de-nrw-cologne`.
- `plan` = `GET /people/{person}/plan?J` (schema `bureaucracy.plan.1`, built by
  `PlanReadModel::for`). `GET /plan` returns `{schema_version, state, plan}` for
  the account holder only. `GET /processes/{id}` returns one `plan.processes[]`
  or `plan.history[]` element plus `assessment_revision`, `evaluated_at` and
  `next_reassessment_at`.
- Step identity: a step is one catalogue variant. `step_id` = `<task_key>.complete`.
  An action/progress id is `<process_id or occurrence_key>:<step_id>`.
- Every write needs a client UUID `request_id`, reused for retries. After a
  successful write, re-read `plan`. A 409 means re-read and review.
- **MISSING** = the backend has no source. The table gives the field it would need.

---

## 1. Data displayed → backend source

### 1.1 Page chrome (all views)

| UI element (P/ file:line) | Backend source | Notes |
|---|---|---|
| Heading "Paperwork" or process name (app.js:230-231) | Process name: **MISSING** | A process has `definition_id` and `topic` but no display title. Need `processes[].title` (reviewed catalogue label) or a topic label map. |
| Person menu name/note (app.js:233-242) | `GET /people` → `people[].label`, `.kind`, `.is_self`, `.scopes` | The "Your sample plan / Shared adult · sample" notes are UI copy based on `kind`/`scopes`. |
| "Your situation" button visible only when allowed (app.js:243) | `plan.scopes` contains `view_facts` (read) / `edit_facts` (edit) | |
| Overview/Documents tabs (app.js:243) | Documents tab: `plan.paperwork` (or `GET /people/{p}/paperwork?J`) | When the viewer lacks `manage_evidence`, `paperwork = {available:false, reason:'permission_required'}`. |
| Footer "Sample progress · Historical guidance" (app.js:243) | Replace with `plan.coverage.state` (`partial`/`not_activated`/`outside_coverage`) | Do not show "Sample" on a real plan. |
| Review menu: scenario select, Reset demo (app.js:243-254) | None. Frontend-only QA control. | The admin preview is `GET /preview/{persona}?J` (read-only, `bureaucracy.qa-assessment.1`, not the plan schema). `POST /qa/reset-tasks` is retired (410). |

### 1.2 Overview

| UI element | Backend source | Notes |
|---|---|---|
| "Coming up" rows this week (app.js:324-334) | `plan.timeline[]` rows with `kind ∈ {appointment, legal_due, preparation_target, authority_follow_up, submission_recorded, document_expiry}` and `state ∈ {dated, recorded}` | The "this week"/urgent window is **MISSING** from the plan payload. `PlanAttention::for` already computes `days_remaining`, `urgency` and `label`, but only Home/reminders use it. Add `plan.attention[]`. |
| "Your D visa expires" row (app.js:331, plan.js:336-347) | No `document_expiry` row exists for `visa_expires_at`. Only `residence_card_expires_at` produces one (BuildTimeline.php:65-72). The visa date appears as the `legal_due`/`preparation_target` row of the Blue Card steps (`timeline[].source_rule_id`, `date`), and as a fact (`GET /people/{p}/facts` → `values.visa_expires_at`, needs `view_facts`). | **MISSING** if the overview must show "visa expires" as its own row. Either add a `document_expiry` row for `visa_expires_at` or show the step's due row with its own label. |
| Next steps, at most 3 (app.js:336-344) | `plan.overview.next_actions[]` → `title`, `process_id`, `occurrence_key`, `step_id`, `dates[]`, `requires_process_start` | Use the order given; do not re-sort (see §3). |
| Next-step badge "To do" (app.js:321) | Implicit: `actions[]` only contains `todo` steps | |
| Next-step process name and icon (app.js:321) | Name **MISSING** (see 1.1). Icon: `topic` via `processes[]` matched on `occurrence_key` | |
| Due chip "Visa expires tomorrow" (app.js:92-97) | `actions[].dates[]` (`kind`, `date`, `state`, `overdue`, `needed_fact`, `conditional`) | Both Blue Card steps (prepare and submit) have `deadline_fact_key: visa_expires_at`, so both carry a date row. Wording and urgency: **MISSING** (see `attention`). |
| Status count tiles todo/blocked/waiting/done (app.js:345-353) | `plan.progress.{todo,blocked,waiting,completed}.count` | |
| "All N" (app.js:336) | `plan.progress.total` | Note that `plan.actions` contains only todo steps (`overview.remaining_action_count` = todo − 3). |
| Question card (app.js:361-367) | `plan.overview.question` / `plan.questions` → `status` (`preview`/`offered`/`paused`/`answer_limit`/`no_more_questions`/`permission_required`), `question.{fact_key, kind, question, why, answer_schema, process_ids, can_skip, can_answer_unknown, action}`, `remaining_information_count`, `session_id` | Context line "For your EU Blue Card process": `question.process_ids` → process title (**MISSING**, see 1.1). |
| "Visa expiry remains unknown · Answer when ready" (app.js:364-365) | Deferred state: `questions.remaining_information_count` and `status`. There is no per-fact "deferred" flag in the plan. | **MISSING**: a list of deferred fact keys (`questions.deferred[]`) to render the reminder. |
| "Help me get started / Continue / Update your situation" entry (app.js:336, orientation.js:53-60) | Onboarding draft: `GET /people/{p}/onboarding/draft` (exists → "Continue"). Facts: `GET /people/{p}/facts`. | Picking among the three labels is a frontend decision. |
| Your tasks: process cards (app.js:354-359) | `plan.processes[]` → `id` (null = proposal), `state.workflow`, `progress.completed.count`/`progress.total`, `guidance_state` (`current`/`review_required`/`history_only`) | Title **MISSING**. Process note (app.js:359): **MISSING** (no free-text note on processes). |
| "Explore before you start" (app.js:359) | `processes[].id === null` | |
| Custom requests "Guidance not connected" (app.js:354) | **MISSING**, and this is a product decision: there is no free-text request entity. | |
| History count (app.js:354) | `plan.history.length` | |
| Dates & appointments list (app.js:368-371) | `plan.timeline[]` | Labels per `kind`. Show `date_unknown` / `needed_fact` / `overdue` / `conditional` instead of hiding the row. |
| Coverage strip (app.js:354) | `plan.coverage.state`, `coverage.processes[]`, `coverage.withdrawn` | |

### 1.3 Process detail

| UI element | Backend source | Notes |
|---|---|---|
| Workflow badge (app.js:549) | `processes[].state.workflow`: `not_started`, `preparing`, `submitted`, `waiting_authority`, `action_required`, `completed`, `cancelled` | The prototype uses `blocked`/`waiting` (app.js:42-49), which needs relabelling (§3). |
| "Look around first · Start tracking" (app.js:549) | `processes[].id === null` (+ `review_token`, `occurrence_key` for the start) | |
| Guidance changed banner | `processes[].guidance_state === 'review_required'` | Not in the prototype. Needed for `POST /processes/{id}/review`. |
| Steps list, order, "n/m done" (app.js:549) | Steps: `processes[].current_steps[]` (`id`, `depends_on`, `semantic_hash`). Step state: `processes[].state.steps[step_id]` (`todo`/`blocked`/`completed`). Counts: `processes[].progress`. | Display order is `ksort` on step id (DiscoverProcesses.php:34), which is alphabetical. **MISSING**: an explicit reviewed `position`. |
| Step title (app.js:549) | `processes[].guidance[]` matched on `step_id` → `title` | |
| Step status label (app.js:549) | `state.steps[step_id]` plus the workflow. The waiting bucket applies when the step is `todo` and the workflow is `submitted`/`waiting_authority` (ProgressSummary.php:28). | |
| Blocked reason | `current_steps[].depends_on` | The UI can name the prerequisite step. Readiness is not a blocked reason. |
| "About this step" (app.js:529) | `guidance[].description`, `description_additions[]` (`applicability`), `instructions[]` (`title`, `body`, `applicability`, `missing_facts`, `action_id`) | |
| "Read official information" link (app.js:529) | `guidance[].actions[]` → `url`, `purpose`, `channel` (filtered by host allowlist). `unavailable_actions[]` lists blocked links. | |
| "Check documents n/m" + first document (app.js:529, plan.js:256-284) | `paperwork.requirements[]` filtered by `occurrence_key` and `source_rule_id === guidance.id` | Choosing the "first document to check" is a frontend presentation choice. Do not present it as a cause. |
| "I've submitted it" only on the submit step (app.js:529) | **MISSING**: no step marker saying a step is a submission step. | Need `guidance[].completion_event` (`step_completed` or `submission_recorded`) or `step_role`. |
| Resources: Documents "n marked ready" (app.js:549) | Count of `paperwork.requirements[]` with `readiness === 'confirmed_for_use'` for this occurrence | |
| Resources: Official information "needs checking" (app.js:549) | `guidance[].review` → `verified_at`, `review_due_at`, `content_version`, `legal_sources`, `review_status` | |
| Resources: "Explore bank services" (app.js:549) | Frontend-only cross-link (Services page). | |
| Resources: Visa expiry (app.js:549) | `GET /people/{p}/facts` → `values.visa_expires_at`, `states.visa_expires_at` | Requires `view_facts`. |
| Your appointment (app.js:549) | `timeline[]` where `kind='appointment'` and `occurrence_key` matches → `starts_at` (with offset), `timezone`, `duration_minutes`, `location {label,lat,lng}`; `id` = `<occurrence_key>:appointment:<uuid>` | **MISSING**: a separate `appointment_id` and `event_id` field. The id must currently be parsed from `id`. |
| "On Today / Add to day / View in day" (app.js:549, 100-107) | Frontend/Composer only | |
| Submission line (app.js:549) | `timeline[]` where `kind='submission_recorded'` → `date`; `id` = `<occurrence_key>:submission:<event_id>` | **MISSING**: an `event_id` field. Channel and reference are stored in the event but not projected. |
| Appointment-vs-day mismatch banner (app.js:382-400) | Frontend/Composer only | |
| "This process is unavailable" (app.js:533-534) | `GET /processes/{id}` → 404 | |

### 1.4 Documents (Paperwork) tab

| UI element | Backend source | Notes |
|---|---|---|
| Task filter select (app.js:567) | Group `paperwork.requirements[]` by `occurrence_key` / `process_id` | Titles **MISSING** (1.1). |
| Group header "n/m" (app.js:570) | Count of `readiness === 'confirmed_for_use'` / count of rows with `applicability !== 'not_required'` | |
| Row label (app.js:574) | `requirements[].label`, `.note` | English labels are pending catalogue review (README items 5, 7, 8). |
| Row state label (app.js:35-41, 574) | `requirements[].readiness`: `missing`, `reported_available`, `confirmed_for_use`, `needs_reconfirmation` | The prototype's `unknown` readiness does not exist. "Not checked yet" maps to `missing`. |
| "May be needed" (app.js:558, 574) | `requirements[].applicability` (`required`/`conditional`/`unknown`/`not_required`), `missing_facts[]`, `reason` (`branch_not_determined`/`reviewed_conditions`) | Do not use the label regex. |
| "Start tracking this task" on a proposal row (app.js:574) | `requirements[].process_id === null` | |
| Evidence item behind a confirmation | `requirements[].evidence_id`, `.suggested_evidence_ids[]` → `paperwork.evidence[]` (`id`, `version`, `details {label, kind, reported_available, expires_on}`, `storage:'metadata_only'`) | |
| Future features (upload/OCR/translation) | `paperwork.capabilities.*` = `{available:false, reason:'not_implemented'}` | |
| "Check official source" (app.js:574) | `guidance[]` by `source_rule_id` → `actions[]`, `review` | |

### 1.5 Your situation

| UI element | Backend source | Notes |
|---|---|---|
| Fact rows: label + value (situation-ui.js:28-42) | `GET /people/{p}/facts` → `revision`, `values{}`, `states{}` (`value`/`unknown`/`declined`/`not_applicable`/`conflict`/`needs_reconfirmation`/`invalid`), `evidence{key:{fact_id, source, effective_from, effective_until}}` | **MISSING**: field labels, questions and option labels outside an offered question. `answer_schema` exists only on a question candidate. Need `GET /facts/schema` (registry `question`, `why`, `type`, `options`, `date_semantics`) or a field-label map. Option display labels are UI copy today (details-review.js:4-82). |
| Which facts to list (situation.js:95-115, details-review.js:123-139) | **MISSING**: the backend list of relevant/missing facts (= protocol candidates + answered facts). | `questions.remaining_information_count` gives only a count. Need `questions.candidates[]` (fact keys only), or the onboarding review payload. |
| Status "Needs review / Some details missing / Time to check / Answers checked" (situation-ui.js:46-49, situation.js:245-255) | Partly: `states[k] === 'needs_reconfirmation'` / `conflict`; `remaining_information_count > 0` | "Time to check" check-in: **MISSING**. |
| "Last checked …" (situation-ui.js:49) | **MISSING** (no last-confirmed/check timestamp exposed) | Need `evidence[k].confirmed_at` and a person-level `last_checked_at`. |
| "Check after your change" flags (situation-ui.js:39) | **MISSING** (no life-change report entity) | |
| Reported changes history (situation-ui.js:49) | **MISSING** | |
| Answer history (details-ui.js:52-55) | **MISSING** (superseded/historical assertions are not exposed) | Need `GET /people/{p}/facts/{key}/history`. |
| Competing answers (details-ui.js:43) | `GET /people/{p}/fact-conflicts` → `conflicts[]` (assertions with `value`, `answer_state`, `confirmation`, `source`, `recorded_at`, `effective_from`), `review_token`, `revision` | Requires `view_facts` + `edit_facts`. |
| Linked-person/sponsor review | `GET /people/{p}/relationships` | Not in the prototype. Needed for `kind: review_relationship`. |
| Explore a topic grid (situation-ui.js:51-59) | Proposals in `plan.processes[]` (`id: null`) and `plan.topics[]` | **MISSING**: browsing catalogue topics that the assessment does *not* propose. `topics[]` excludes `not_relevant` definitions, and there is no read-only catalogue endpoint. |
| Starter questions arrival/purpose/citizenship (situation.js:7, 95-115) | Option A: onboarding draft (`config/bureaucracy_onboarding.fact_keys` includes `purpose`, `arrival_planned`, `citizenship_group`, `registration_status`, …). Option B: question session. | The question protocol's orientation is `citizenship_group`, `arrival_planned`, then conditional title/expiry. `purpose` and `registration_status` are offered only when a reviewed process depends on them. |
| Orientation `move_stage` / `focus` (orientation.js:4-28) | **None**. These are not registry facts. | Frontend-only product orientation, or drop it. |

### 1.6 Question card / inline question flow

| UI element | Backend source |
|---|---|
| Title, why, input type, options | Offered question: `question.question`, `.why`, `.answer_schema {type, options, date_semantics, allows_not_applicable}` |
| "I'm not sure" / "Prefer not to say" options | `can_answer_unknown`; `answer_state: unknown/declined` (and `not_applicable` when allowed) |
| "Skip for now" | `can_skip` → defer |
| "n of 3" progress (situation-ui.js:67) | `question.remaining_before_pause` (offered response only) |
| Pause screen (orientation-ui.js:84) | `questions.status === 'paused'`, `can_resume` |
| Conflict question | `question.kind = resolve_conflict` + `action {type: review_fact_conflict, available, unavailable_reason}` |
| Relationship review | `question.kind = review_relationship` + `action.type = review_relationships` |

### 1.7 History and filters

| UI element | Backend source | Notes |
|---|---|---|
| All actions list filtered by status (app.js:372-378) | `plan.progress.<bucket>.ids[]` joined to `processes[].guidance[]` on `step_id` (id = `<process_id or occurrence_key>:<step_id>`) | **MISSING (medium)**: `plan.actions` holds only todo rows. Ship `plan.steps[]` (id, status, title, process ref), or document the join. |
| History rows (app.js:379-381) | `plan.history[]` (processes whose occurrence no longer has current guidance) | Completed/cancelled processes *with* current guidance stay in `processes[]` with `workflow ∈ {completed, cancelled}`. The UI must split them itself or the backend must add a flag. |
| History "Completion/Cancellation recorded · date" (app.js:380, 937-945) | Workflow: `state.workflow`, `state.completion_basis` (`user_report`) | **MISSING**: completion/cancellation `occurred_on` and event id. The prototype's "Tax ID" sample history (plan.js:60-70) is fixture data. |
| Paperwork task filter | Frontend-only (client grouping) | |

### 1.8 Family / person switch

| UI element | Backend source | Notes |
|---|---|---|
| Roster (family.js:4-38) | `GET /people` → only people with ≥1 scope | Inaccessible people ("Private adult", "Pending dependent") are never listed. A 403/404 removes them. |
| Identity "Alex's plan · view only / editing allowed" (family-ui.js:27) | `GET /people/{p}` → `label`, `kind`, `scopes`, `can_manage_sharing` | |
| Access & sharing (family-ui.js:11-15) | `GET /people/{p}/sharing` → `grants[]` (`scopes`, `is_my_access`, `accepted_at`, `expires_at`, `revoked_at`) | Pending dependent-authority state: **MISSING** (no `GET` for authorities; only POST/approve/DELETE). The "Sample access state" select is a frontend-only preview. |
| Family next step / documents (family-ui.js:28-29) | Same `plan` / `paperwork` for that person | |
| "Documents aren't shared" | `plan.paperwork.available === false && reason === 'permission_required'` | |
| "Personal answers are private" | `plan.questions.status === 'permission_required'`; `GET facts` → 403 | |

### 1.9 Sources & coverage sheet (app.js:591-605)

| UI element | Backend source |
|---|---|
| Title, version, verified date | `plan.guidance[]` → `title`, `review.content_version`, `review.verified_at`, `review.review_due_at`, `review.effective_from/to` |
| Official / legal source links | `guidance[].actions[].url`; `review.legal_sources` |
| Coverage disclaimer | `plan.coverage` (never "everything covered") |

The static `P/source-data.js` must be dropped in production.

---

## 2. User actions → endpoint

`exp_v` = `processes[].version`. `rt` = `processes[].review_token` (current plan).

| Action (P/ file:line) | Endpoint + method | Payload | Notes |
|---|---|---|---|
| Open page (self) | `GET /plan` | none | `state`: `ready`/`setup_required`/`record_unavailable`. |
| Explicit setup | `POST /people/self` | none | Only on a user action. Loading the page must not call it. |
| Switch person (app.js:737-745) | `GET /people`, then `GET /people/{p}/plan?J` | none | Drop the previous person's data. Do not fall back to a cached plan. |
| Start a process / "Start tracking" (app.js:990-996, 778-786) | `POST /people/{p}/processes` | `{jurisdiction, occurrence_key, review_token: rt, request_id}` | Returns `{process_id, version, event_id}`. It does *not* report preparation. |
| "Track task" from topic grid (app.js:693-698) | Same start, only if the topic exists as a proposal in `plan.processes` | as above | Non-proposed topics: **MISSING** (no catalogue browse). The prototype's include+start in one click must become a single explicit start. |
| Mark step done (app.js:921-936) | `POST /processes/{id}/events` | `{event:'step_completed', payload:{step_id, occurred_on?}, expected_version: exp_v, review_token: rt, request_id}` | Rejected while the step is `blocked`. |
| Undo / reopen step | same | `event:'step_reopened'`, `payload:{step_id}` | |
| "I've submitted it" / record submission (app.js:1079-1083) | same | `event:'submission_recorded'`, `payload:{occurred_on (≤ today), channel?, reference?}` | Moves the workflow to `submitted`. It does **not** complete the step. |
| Correct submission date | `POST /processes/{id}/events/{event_id}/corrections` | `{payload:{occurred_on,…}, expected_version, request_id, confirmed:true}` | `event_id` must currently be parsed from `timeline[].id`. |
| Remove submission record (app.js:1021-1039) | **MISSING** | — | There is no retraction event for a submission. Corrections keep the type. Need `submission_retracted`, or a correction marking it withdrawn. |
| Record/edit appointment (app.js:1072-1077, plan.js:302-314) | `POST /processes/{id}/events` | `event:'appointment_recorded'`, `payload:{appointment_id: <client UUID; reuse to edit>, starts_at:'YYYY-MM-DDTHH:MM:SS+02:00', timezone:'Europe/Berlin', duration_minutes (1-1440, required), location?: {label?, lat, lng}}` | The latest event per `appointment_id` wins. The prototype does not collect duration or timezone, and free-text location without coordinates is rejected (§3). |
| Remove appointment | same | `event:'appointment_cancelled'`, `payload:{appointment_id}`, `review_token` optional | |
| Update progress: preparing (app.js:1084-1096) | same | `event:'preparation_started'` (from not_started/preparing/blocked/action_required) or `process_reopened` (from completed/cancelled) | |
| Update progress: "Needs attention" (blocked) | same | `event:'action_required_reported'`, `payload:{occurred_on?, reference?}` | Allowed only from `submitted`/`waiting_authority`/`preparing`. |
| Update progress: waiting | same | `event:'waiting_reported'` | Allowed only from `submitted`/`waiting_authority`, so a submission must be recorded first. |
| Update progress: completed + date | same | `event:'completion_reported'`, `payload:{occurred_on?, reference?}` | Rejected unless every step is completed. |
| Update progress: cancelled + date | same | `event:'cancellation_reported'`, `payload:{occurred_on?, reference?}`, no `review_token` needed | |
| Progress note (app.js:661) | **MISSING** | — | No note field. `reference` (≤200 chars) is allowed on some events only and is not projected. |
| Accept changed guidance | `POST /processes/{id}/review` | `{expected_version, request_id, review_token, confirmed:true, bind_occurrence?}` | Not in the prototype. Needed when `guidance_state = review_required`. |
| "I have this" (app.js:574, model.js:89-91) | `PUT /people/{p}/evidence/{uuid}` | `{request_id, expected_version:0, details:{label, kind, reported_available:true, expires_on?}}` | Creates a metadata-only item. Readiness becomes `reported_available` only if `details.kind === requirement.evidence_kind`. **No catalogue document defines `evidence_kind`** (0 matches in `database/seeders/data/bureaucracy/*.yaml`), so readiness stays `missing` (§4 G1). |
| "Ready for this task" (confirm) (app.js:574) | `POST /processes/{id}/requirements/{requirement_id}/confirm` | `{request_id, expected_version: exp_v, evidence_id, evidence_version, requirement_hash: requirements[].semantic_hash, confirmed:true}` | Only when `applicability === 'required'` and the evidence is usable. Process must be started. |
| "Not ready yet" (app.js:574, model.js:102-105) | If confirmed: `DELETE /processes/{id}/requirements/{requirement_id}/confirmation` with `{request_id, expected_version}`. If only available: `PUT …/evidence/{id}` with `reported_available:false` (or `status:'archived'`). | — | These are two distinct commands. The UI must say which one it is doing. |
| Reconfirm after `needs_reconfirmation` | Confirm again (same as above) | | |
| Share a document with a family member | `POST /evidence/{id}/shares`, `DELETE /evidence/{id}/shares/{share}`, `GET /evidence/{id}/shares` | see ShareEvidenceRequest | Not in the prototype. |
| Answer the overview question (app.js:361-363) | 1. `POST /people/{p}/question-sessions` `{jurisdiction, request_id}` (if no usable `questions.session_id`); 2. `POST /question-sessions/{s}/next` `{request_id}`; 3. `POST /question-sessions/{s}/answers/{question_id}` `{token, value, answer_state?, operation?:'assert', effective_from?}` | | Then re-read `plan`. |
| Skip question ("Skip for now") | `POST /question-sessions/{s}/defer/{question_id}` | `{token}` | Saves no fact. |
| "I don't know yet" (app.js:363, 968-971) | answer with `answer_state:'unknown'`, `value:null` | | The prototype sends this to skip (§3). |
| Continue after pause | `POST /question-sessions/{s}/resume` | `{revisit_deferred: bool}` | |
| Starter questions, then complete (situation.js:132-166) | `GET/PUT /people/{p}/onboarding/draft` `{draft_id, expected_version, step (1-4), answers}`; `GET …/onboarding/review`; `POST …/onboarding/complete` `{draft_id, draft_version, expected_fact_revision, request_id, confirmed:true}`; `DELETE …/onboarding/draft` | | Or use question sessions. Pick one path for the starter. |
| Edit answer: correct an earlier answer (details-review.js:176-182) | `POST /people/{p}/facts/{fact_id}/corrections` | `{value, expected_revision: facts.revision, answer_state?}` | `fact_id` = `facts.evidence[key].fact_id`. |
| Edit answer: my situation changed | `PUT /people/{p}/facts/{key}` | `{value, effective_from?, expected_revision, answer_state?}` | |
| Edit answer inside an interview | `answers/{q}` with `operation: correct/change` | | |
| Resolve competing answers (details-review.js:154-166) | `POST /people/{p}/fact-conflicts/{key}/resolve` | `{review_token, expected_revision, request_id, confirmed:true, value, answer_state?}` | Confirms the present only. Do not preselect a value. |
| Remove wrong relationship | `DELETE /people/{p}/relationships/{id}` | | Not in the prototype. |
| "Still correct" (keep) after a change (situation.js:227-236) | **MISSING** | — | Need `POST /people/{p}/facts/{key}/reconfirm` `{expected_revision, request_id}`. |
| "Something changed" → confirm change (situation.js:193-226) | **MISSING** | — | Need `POST /people/{p}/life-changes` `{kind, effective_from?, request_id}` returning facts to review. Until then, map to direct fact edits only. |
| "Nothing changed" check-in (situation.js:237-244) | **MISSING** | — | Need `POST /people/{p}/check-ins`. |
| Help with this step → Composer (app.js:840-852) | Frontend-only (no facts sent) | | |
| Set aside time / add appointment to day (planning-ui.js, planning-model.js) | Composer only (`composer/*`). No bureaucracy write. | | |
| Add a free-text task (app.js:977-983, 1097-1102) | **MISSING** (and a product decision) | | |
| Family sample access select (app.js:674-681) | Frontend-only preview. Real: `POST /invitations`, `/invitations/accept`, `DELETE /grants/{g}`, `POST /dependents`, `/authorities/{a}/approve` | | |
| Review menu: scenario / reset (app.js:664-673, 812-829) | Frontend-only. Admin read: `GET /preview/{persona}?J`. | | |
| AI candidate extraction | `POST /question-sessions/{s}/extract/{q}`, `…/candidates/{c}/confirm`, `DELETE …/candidates/{c}` | | Gated by `plan.ai.available`. Not in the prototype. |

---

## 3. Conflicts (prototype vs backend rules)

| # | Prototype behaviour | Backend rule | Fix in UI |
|---|---|---|---|
| C1 | Builds the action list and statuses locally (`P/plan.js:145-175`) and picks Next up as the first todo per process (`plan.js:196-202`) | `plan.actions` is ordered by earliest step date (PlanReadModel.php:166-186). Statuses come from `progress` buckets. | Render `overview.next_actions` as given. |
| C2 | Re-sorts Next steps so the "visa" step comes first (`app.js:338-341`) | Ordering belongs to the backend | Remove. |
| C3 | Hard-links visa expiry to "blue-card record 1" (`app.js:89-97`, `app.js:529`) | Each step carries its own `actions[].dates[]` (`source_rule_id`). Both Blue Card steps are dated from `visa_expires_at`. | Use `dates[]`. Never key on a catalogue position. |
| C4 | Computes Today/Tomorrow/this-week and "urgent" from the sample day (`app.js:60-88`, `app.js:331`, `app.js:370`) | Urgency windows live in `PlanAttention` (not exposed in the plan) and are evaluated at `evaluated_at` in the jurisdiction timezone | Expose `attention`. The UI may format relative labels from `evaluated_at` + `timezone` only. |
| C5 | Builds the timeline client-side, including a "Document expiry" row for the D visa, and sorts it (`plan.js:332-374`) | Timeline is `plan.timeline` only. No `document_expiry` row exists for `visa_expires_at`. | Render `timeline`. Add a backend row if the product needs one (G7). |
| C6 | Recording a submission marks the submit step completed (`plan.js:160-163`) | `submission_recorded` sets workflow `submitted`. The step stays todo and counts as `waiting`. | Use backend step state. |
| C7 | Step done/undo flips a local boolean per process (`app.js:921-929`); non-Blue-Card processes have a single `done` flag | `step_completed`/`step_reopened` per `step_id`. Blocked steps cannot be completed. | Use events. Disable "done" on blocked steps. |
| C8 | Any workflow transition, including waiting from preparing and completed with open steps (`plan.js:213-239`, `app.js:657-663`) | State machine (ProcessStateMachine.php:55-69): waiting requires submitted; completion requires all steps done | Offer only valid transitions. Show the backend 422 message. |
| C9 | Workflow vocabulary `blocked`/`waiting` (`app.js:42-49`) | `action_required`, `submitted`, `waiting_authority` | Relabel. |
| C10 | Free-text progress note (`app.js:661`, `plan.js:237`) | No note storage | Drop it, or add G9. |
| C11 | Changing the visa expiry flips document 0 to `needs_reconfirmation` (`model.js:71-80`) | Readiness is computed server-side (hash/version/expiry/applicability, ProjectPaperwork.php:57-63) | Re-read paperwork. |
| C12 | Document actions mutate readiness directly. "I have this" marks a specific requirement `reported_available` (`model.js:81-107`, `plan.js:285-301`). | Availability is a person-level evidence item. `reported_available` appears only through an `evidence_kind` match. Confirmation is a separate command needing evidence id/version + requirement hash. | Two-step flow (record evidence → confirm). See G1. |
| C13 | Conditional documents detected by regex on the label (`app.js:558`, `app.js:529`) and confirmable as "ready" | `requirements[].applicability`. Confirm accepts only `required`. | Use `applicability`. Hide confirm unless required. |
| C14 | Readiness value `unknown` (`model.js:26`, `plan.js:130`) | Not a readiness state | Map to `missing` + `applicability`. |
| C15 | Appointment = date + HH:MM + free-text location, no timezone or duration (`app.js:628-635`, `plan.js:302-314`) | `starts_at` with seconds + offset, IANA `timezone`, **required** `duration_minutes`, `location` needs lat/lng | Collect duration (or G4). Send the timezone. Location needs a place picker (or G5). |
| C16 | Remove submission record (`app.js:1021-1039`) | Not supported | Hide until G6. |
| C17 | The hard-coded question card asks about D-visa expiry (`app.js:361-367`), with "I don't know yet" sending to skip (`app.js:968-971`) | Server-chosen question. Unknown (`answer_state: unknown`) and defer are different commands. | Render `overview.question`. Map "I don't know" → unknown, "Skip" → defer. |
| C18 | Client-side question routing, a fixed core of arrival/purpose/citizenship and a 3-question batch (`situation.js:7`, `situation.js:95-131`; `details-review.js:123-139`) | Protocol order comes from the server. `purpose` is not an orientation question. The pause is `consecutive_offers=3` server-side. | Use sessions or the onboarding draft. Drop the local sequence. |
| C19 | Orientation answer `move_stage` writes `arrival_planned` silently (`orientation.js:128-143`) | Facts come only from an explicit confirmed answer | Ask `arrival_planned` itself. |
| C20 | A life-change report flags facts for review locally (`situation.js:212-226`); "Still correct" sets `checkedAt` (`situation.js:227-236`) | No such entity | G10/G11, or omit. |
| C21 | Topic grid lets you track any topic regardless of applicability, and start it in one click (`situation-ui.js:51-59`, `app.js:693-698`) | Only assessment proposals (with `review_token`) can be started | Show only proposals as startable. Browsing others needs G8. |
| C22 | Roster lists inaccessible people with a denied state (`family.js:24-37`) | `/people` returns only people the viewer can access | Do not list them. |
| C23 | Sample access state selector (`family.js:87-97`, `family-ui.js:14`) | Real grants/authorities | Preview only. Remove in production. |
| C24 | Synthetic conflict demo (`details-review.js:255-261`, `details-ui.js:38`) | Conflicts from `fact-conflicts` only | Remove. |
| C25 | Static catalogue copy and verified dates (`source-data.js`, `app.js:591-605`) | `guidance[].review` / `actions` from the current release | Remove the static data. |
| C26 | Sample-day defaults: today, appointment and "On Today" (`app.js:16`, `app.js:60-61`, `app.js:100-107`, `model.js:4-10`, `model.js:33-46`) | Real clock = `plan.evaluated_at`. Real appointments = timeline. | Remove the fixtures. |
| C27 | Free-text "Add a task" requests (`app.js:977-983`, `app.js:1097-1102`) | No entity. No legal route may be assumed. | Remove, or add as a product decision. |
| C28 | "All N" counts every action, and `remaining = all − 3` (`plan.js:203-210`) | `plan.actions` = todo only. `remaining_action_count` = todo − 3. | Use `progress.total` for "All". |
| C29 | "First document to check" chosen as a missing/unknown-first heuristic (`plan.js:256-284`) | Allowed as presentation, but it must not imply that the document blocks the step (blocked comes from `depends_on`) | Keep the wording neutral, as in the README. |

No conflict found on "appointment treated as deadline": the prototype keeps
appointment, submission and expiry separate.

---

## 4. Backend gaps to close before wiring (priority order)

Update, 7 October 2026: G1 (evidence `requirement_refs`), G2, G3, G7, G12 and G15 are
now in the plan payload. G9 is covered for the closing date only (`closed_on`, no
notes). G10 is covered for the schema, `checked_at` and answer history, but not
display labels. See `bureaucracy-v2.md` for shapes. The other gaps are still open.

| # | Gap | Proposed shape |
|---|---|---|
| G1 | Per-requirement availability cannot be shown: no catalogue document has `evidence_kind`, so `suggested_evidence_ids` is always empty and readiness never becomes `reported_available`. "I have this" has no visible effect. | Populate `evidence_kind` in the catalogue, **or** let `PUT evidence` carry `requirement_refs[]`/a `kind` derived from the requirement, so the paperwork projection can attach it. |
| G2 | No process display title | `processes[].title`, `history[].title` (reviewed label), plus a topic label. Questions/actions/requirements resolve titles through it. |
| G3 | No full step list with status. `actions` is todo-only. | `plan.steps[]`: `{id, process_id, occurrence_key, step_id, title, status, position, dates[]}`. Add a reviewed `position` to `current_steps`. |
| G4 | Appointment `duration_minutes` is required, but users often do not know it | **Done (feat/bureaucracy-v2-events):** `duration_minutes: null` = unknown, never defaulted. Composer flags `appointment_end_unknown`. See bureaucracy-v2.md. |
| G5 | Appointment location needs lat/lng | **Done:** `{label}` only is accepted with `routable: false`. Composer flags `appointment_location_unroutable`. |
| G6 | Submission cannot be retracted. Timeline ids hide event ids. | **Done:** `submission_retracted {event_id}`. Timeline rows carry `event_id`, `revision_event_id` and `appointment_id`. |
| G7 | Attention/urgency is not in the plan. Visa expiry has no dated row. | `plan.attention[]` (reuse `PlanAttention::for`). Add a `document_expiry` row for `visa_expires_at` (and `residence_title_expires_at`) with `legal_effect: not_assessed`, if the product wants it shown. |
| G8 | No read-only catalogue/topic browsing for non-proposed topics | `GET /catalogue/topics?J` → reviewed titles + official actions, with no applicability claim. |
| G9 | Completion/cancellation date and process note are not projected | `processes[].state.closed_on` (from `occurred_on`) and `reports[]` (type, occurred_on, reference, event_id). |
| G10 | Situation display needs a fact schema and history | `GET /facts/schema` (labels, question, why, options, date semantics). `GET /people/{p}/facts/{key}/history`. `evidence[k].confirmed_at`. |
| G11 | Life-change report, "still correct" reconfirm, "nothing changed" check-in | `POST /people/{p}/life-changes`, `POST …/facts/{key}/reconfirm`, `POST …/check-ins`, plus `last_checked_at`. |
| G12 | Deferred questions and relevant missing facts are not listable | `questions.deferred[]` (fact keys), `questions.candidates[]` (fact keys and kinds only). |
| G13 | Dependent authority pending state is unreadable | `GET /people/{p}/authorities`, or `pending_authority` on `/people` entries. |
| G14 | No explicit submission-step marker | **Done:** `guidance[].completion_event ∈ {step_completed, submission_recorded}`. |
| G15 | Completed/cancelled processes with current guidance stay in `processes[]` | Add `is_closed`, or move them into `history` for listing. |

## 5. Frontend-only (no backend needed)

- Layout, tabs, view routing, URL state, Back/Forward, focus and motion.
- Relative date formatting ("Today", weekday) from backend dates + `evaluated_at` + `timezone`. Urgency itself should come from G7.
- Paperwork task filter and action status filter (client grouping of plan data).
- Labels for workflow/readiness/applicability enums and option values (until G10).
- "Help with this step" → Composer handoff. Set aside time / Add to day / Your day / appointment-vs-plan banner (Composer state).
- Open/closed disclosures, toasts, inline confirmations (Keep/Remove).
- Services cross-link (bank). "On Today" link.
- QA Review menu, scenario switcher, reset, family sample access selector, synthetic conflict demo: prototype-only. Remove them, or point them at `GET /preview/{persona}` for admins.
- Product orientation (`move_stage`, `focus`), only if kept, and never written as facts.
- Error handling per contract: 401/419 session, 403/404 remove the person/record, 409 re-read, 422 field, 429 rate limit, 5xx retry.
