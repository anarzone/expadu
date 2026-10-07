# Bureaucracy backend → UI integration contract

Status: implementation draft, 8 September 2026. This describes the new backend in
`codex/bureaucracy-backend-v2`, not staging or a completed UI integration. Existing
account migration, legacy-page cutover and final source review are still underway.

## What the user should experience

The selected person has one plan. Show the useful next steps immediately and one
relevant question when needed. The same answers and decisions drive Overview,
Paperwork and process details. Opening a page does not start work, answer a question
or mark a document ready. Switching person must not carry answers or edits across.

The backend decides applicability, dates, missing information and progress. The UI
chooses layout. It must not derive residence status from an employment label, treat
a blank answer as “no”, calculate eligibility from years alone, or turn a task's
completion into document readiness.

## Identity and transport

All paths below start with `/bureaucracy/v2`. Use authenticated JSON requests and
the application's CSRF handling. Private responses are `Cache-Control: private,
no-store`; do not put facts, offers, drafts or plans in public/service-worker caches.

- `GET /plan`: account-holder entry; no person lookup or setup write is needed to
  open it. Returns `schema_version: bureaucracy.account-entry.1`, `state` and `plan`.
  `ready` means a current self-plan is present, **not** complete case coverage or
  eligibility. `setup_required` means setup can be explicitly started with
  `POST /people/self`; loading the entry does not create a dossier. An inactive or
  erased record returns `record_unavailable` with `plan: null`, not a reset prompt.
  The returned plan uses the account's current city. This route ignores person
  and jurisdiction query overrides; use the scoped person-plan endpoint below
  for an explicitly selected family member or supported guidance jurisdiction.
- `GET /people`: permitted people and `next_cursor`; an empty list creates nothing.
- `POST /people/self`: explicitly create/attach the account-holder record. It never
  picks a spouse or creates another adult from a name in the account profile.
- `GET /people/{person}`: label, kind, `is_self`, `record_version`, `scopes` and
  `can_manage_sharing`. Respect scopes rather than assuming family membership is access.
- `GET /people/{person}/plan?jurisdiction=de-nrw-cologne`: the shared plan.
- `GET /processes/{process}`: the same process projection plus assessment revision
  and evaluation timestamps, not a separately calculated checklist.
- `GET /people/{person}/paperwork?jurisdiction=de-nrw-cologne`: the same Paperwork
  projection as the plan. Confirm the person before every mutation.

IDs are opaque references, not authority. A denied or deleted person must be removed
from the current view; do not show a previously cached person's plan as a fallback.

## Plan fields

Executable structural expectations live in
`tests/Fixtures/bureaucracy/v2-plan-contract.json`; this is not legal guidance or a
full JSON Schema. `PlanContractTest` walks real HTTP requests from skippable onboarding
through an invalid/corrected answer, process start, completion, reopening and reload.
It checks Paperwork parity, exact progress IDs and future-feature unavailability.
This is backend evidence, not UI verification or typed examples of every state.

Schema: `bureaucracy.plan.1`. Fields are additive while the schema remains compatible.

| Field | Meaning and display rule |
|---|---|
| `person_id`, `jurisdiction` | Explicit subject and guidance area; never inherit another person's selection. |
| `assessment_revision` | Opaque equality token. Refresh after successful changes or a conflict response. |
| `evaluated_at`, `next_reassessment_at` | ISO instants. Refresh when the boundary is reached or the app regains focus. |
| `overview.next_actions` | At most three next actions. This limit does not mean the rest are unimportant or absent. |
| `overview.remaining_action_count`, `actions` | A route to the complete action list; counts must open the underlying records. |
| `questions` | Interview state and at most one current preview/offer. `overview.question` mirrors it. |
| `processes` | Current process occurrences; a proposal has `id: null`, `version: 0`. |
| `history` | Retained occurrences without current guidance; never silently discard old work. |
| `topics` | Topic IDs with definition, occurrence and history references. |
| `guidance` | Reviewed text, criteria, instructions, official actions and source metadata. |
| `progress` | `total` and `todo`, `blocked`, `waiting`, `completed`, each with exact `count` and `ids`. |
| `timeline` | Typed dates/appointments, with uncertainty and provenance kept separate. |
| `coverage` | Overall `partial`, `not_activated` or `outside_coverage`, process-level results and withdrawn rules. |
| `paperwork` | Scoped requirements and evidence metadata, or `available: false, reason: permission_required`. |
| `scopes`, `ai` | Available operations; AI still needs single-request consent and confirmation. |

Do not label overall coverage “everything covered”. A process-level assessment may
describe supported preparation or that its reviewed criteria are met; neither is an
authority decision. Empty current actions with missing/unsupported guidance is not
“you have finished everything”. `history`, blocked work and coverage remain reachable.

Use the backend's `actions` and `actionable` values; do not turn every
`supported_preparation` result into a required application. Application intent is
separate from legal criteria. A different goal cannot erase unrelated obligations,
but an optional application requires its configured confirmed intent. Use the
shared question projection, not only a variant's `missing_facts`: legal OR criteria
can be met by one alternative while the user's chosen alternative still needs an
answer. AI does not resolve this by issuing its own recommendation.

### Official rules are the decision authority

The owner's 8 September clarification applies to every consumer, including AI:

- Only the current source-approved catalogue can supply legal criteria, guidance
  and official actions. A model's prior knowledge, a search result or a user's
  assertion about the law is not a publishable rule.
- AI interprets only the offered, server-authored question for the selected person.
  Its strict output is a candidate answer or an explicit unknown/off-topic/unclear
  subject result—not an eligibility verdict, new rule or recommendation.
- Consent precedes external processing. A candidate becomes an assertion only
  after the person's authorised confirmation; the same backend assessment then
  runs for manual and AI-assisted answers.
- Missing facts lead to relevant approved questions. Missing, withdrawn or
  incomplete rules remain visible coverage gaps; neither AI nor the UI fills them
  with a guessed route or treats partial preparation as confirmed eligibility.

This is a decision boundary, not a claim that the present catalogue covers every
case. Source review, legal completeness and authority discretion remain separate.

## Questions and answer changes

Reading `questions.status: preview` creates no offer. To answer or skip:

1. `POST /people/{person}/question-sessions` with `jurisdiction` and a UUID
   `request_id`, unless a usable session already exists. The response supplies
   `session_id`, `status` and `expires_at` at the top level, not inside a `session`
   object.
2. `POST /question-sessions/{session}/next` with a UUID `request_id`. Reuse it for
   network retries. The response supplies the offered question's `id`, `token`
   and `expires_at`.
3. `POST /question-sessions/{session}/answers/{question}` with `token`, `value`,
   optional `answer_state` (`value`, `unknown`, `declined`, allowed `not_applicable`),
   `operation` (`assert`, `correct`, `change`) and, for a real change, optional
   `effective_from`. Use the returned schema; do not invent enum values.
4. Refresh the plan. Offer the next question only when the user continues.

`POST .../defer/{question}` with `token` skips without saving a fact.
`POST .../resume` with `revisit_deferred` resumes after a pause. Three consecutive
offers cause a pause/continue choice, not a lifetime limit. Handle `paused`,
`answer_limit`, `refresh_required`, `already_handled`, `no_more_questions` and
`permission_required` explicitly. Preserve the manual path when AI is unavailable.

Question `kind` determines the interaction:

| Kind | What to open |
|---|---|
| `answer` | The schema-backed field, with its question and why it is needed. |
| `resolve_conflict` | `action.type: review_fact_conflict`; fetch `GET /people/{person}/fact-conflicts`. Show the competing assertions only with fact-reading permission. |
| `review_relationship` | `action.type: review_relationships`; fetch `GET /people/{person}/relationships`. Review multiple sponsors or a linked-record/report mismatch. Do not send these to the ordinary answer endpoint. |

Conflict confirmation uses
`POST /people/{person}/fact-conflicts/{key}/resolve` with the current `review_token`,
`expected_revision`, UUID `request_id`, `confirmed: true`, `value` and optional
`answer_state`. It confirms the present, not disputed historical periods. Do not
auto-select the latest answer or the AI candidate.

Relationship review provides minimal permitted linked facts, the applicant's
attributed report, and `can_remove` / `can_edit_related_facts`. Changing the report
does not change the sponsor's own record. Removing an incorrect relationship uses
the exact relationship ID. Lack of access is not evidence that the sponsor lacks a title.

Outside an interview, use `PUT /people/{person}/facts/{key}` for a real change and
`POST /people/{person}/facts/{fact}/corrections` for a correction, with
`expected_revision` and `value`. Offer this distinction in plain language; do not
quietly rewrite a former legal title when a new one is recorded.

## Processes and dates

A proposal can be read without saving it. An explicit start uses
`POST /people/{person}/processes` with `jurisdiction`, `occurrence_key`, current
`review_token` and UUID `request_id`. Use its returned `process_id` and `version`.
Do not manufacture a legacy task ID from an occurrence key.

`POST /processes/{process}/events` takes `event`, `payload`, `expected_version`,
UUID `request_id` and the current `review_token`. The token may be omitted for
the withdrawal operations `appointment_cancelled`, `cancellation_reported`,
`submission_retracted` and `process_untracked`; they never depend on current
guidance. Every event is the person's own report (`provenance: user_report`),
not verified issuance, an authority decision or document confirmation. Payloads
reject extra keys. Use `POST /processes/{process}/review` for changed-step/occurrence
review and the event correction endpoint for mistaken report details; do not
replay an outdated token. A refused transition returns 422 with a plain-language
message in `errors.event[0]` that the UI can show as is.

### Process events and payloads

`occurred_on` is an exact `YYYY-MM-DD` on or before today. `note` is optional
free text, 1–500 characters after trimming, stored only in the encrypted event
payload and encrypted process state. Omit a value the person does not know; the
backend never defaults it.

| Event | Payload | Allowed from workflow | Result |
|---|---|---|---|
| `preparation_started` | `{note?}` | `not_started`, `preparing`, `blocked`, `action_required` | `preparing` |
| `blocked_reported` | `{occurred_on?, note?}` | `not_started`, `preparing`, `blocked` | `blocked` |
| `step_completed` / `step_reopened` | `{step_id, occurred_on?}` | any open state | step `completed`/`todo`; workflow becomes `preparing` unless it is `blocked`, `submitted`, `waiting_authority` or `action_required`. A `blocked` step (unfinished prerequisite) cannot be completed. `step_reopened` also works from `completed`/`cancelled` and returns to `preparing`. |
| `submission_recorded` | `{occurred_on, channel?, reference?}` | `not_started`, `preparing`, `blocked`, `submitted`, `waiting_authority`, `action_required` | `submitted`. The step stays `todo`. |
| `submission_retracted` | `{event_id, note?}` | `submitted`, `waiting_authority`, `action_required` | The workflow the person reported without that submission (see below). |
| `waiting_reported` | `{note?}` | `submitted`, `waiting_authority` | `waiting_authority` |
| `action_required_reported` | `{occurred_on?, reference?, note?}` | `preparing`, `submitted`, `waiting_authority` | `action_required` |
| `completion_reported` | `{occurred_on?, reference?, note?}` | `preparing`, `submitted`, `waiting_authority`, `action_required`, and only when every step is `completed` | `completed`, `completion_basis: user_report` |
| `cancellation_reported` | `{occurred_on?, reference?, note?}` | every open state except `untracked` | `cancelled` |
| `process_reopened` | `{note?}` | `completed`, `cancelled` | `preparing` |
| `process_untracked` | `{}` | `not_started`, with no completed step and no event other than `process_started`/`process_untracked`, requirement confirmation or evidence share | `untracked` |
| `appointment_recorded` | see below | any state except `untracked` | workflow unchanged |
| `appointment_cancelled` | `{appointment_id}` | any state except `untracked`; the appointment must currently be recorded | workflow unchanged |

`completed` and `cancelled` refuse further reports until `process_reopened` or
`step_reopened`. Recording a submission never completes a step. Completing a step
never confirms a document.

Paperwork UI states map as follows. `not_started` and `preparing` are the same.
“Blocked” is `blocked_reported` before a submission and `action_required_reported`
after one (the authority needs something). “Waiting” is `waiting_reported` and
needs a recorded submission. “Completed” is `completion_reported` once every step
is done. “Cancelled” is `cancellation_reported`, with the close date in
`occurred_on`. Offer only these transitions.

`state.report` holds the person's own details for the current status:
`{event, occurred_on, note}` with `null` for anything not reported. It is set
by each event in the table except step and appointment events. A step event that
moves the workflow into `preparing` clears it; for a closed process it is the
reported close date and note. Older records may lack the key. It is never an
authority decision.

**Withdrawing a submission.** `submission_retracted.event_id` is the
`timeline[].event_id` of a `submission_recorded` row that has not been withdrawn.
The submission event stays stored and leaves the timeline. The workflow is
recomputed from the person's remaining reports in order, skipping the withdrawn
submission(s). A report that was only valid after a withdrawn submission, such as
“waiting”, drops out with it. If another submission remains, the process stays
submitted. Step states do not change.

**Undoing “Track task”.** `process_untracked` reverses a start before any
progress. The process and its `process_started`/`process_untracked` events stay
stored, but the plan shows the occurrence again as a proposal (`id: null`), and
`GET /processes/{id}` returns 404. Further commands on it return 409. Starting it
again with `POST /people/{person}/processes` reuses the same `process_id`, resets
the workflow to `not_started` and increments `version`. Once progress exists, use
`cancellation_reported` instead.

**Appointments.** `appointment_recorded` takes
`{appointment_id, starts_at, timezone, duration_minutes, location?}`. The client
creates the UUID `appointment_id`. Recording the same `appointment_id` again
reschedules the appointment; the latest event for an id wins. `appointment_cancelled`
removes it, and recording the id again restores it. Fixing details of one report
uses the correction endpoint with the row's `revision_event_id`.

- `starts_at` includes seconds and an offset that is valid for the IANA `timezone`.
- `duration_minutes` is required as a key: an integer from 1 to 1440, or `null`
  when the person does not know the length. It is never defaulted.
- `location` is optional (unknown location) or one of `{lat, lng, label?}` or
  `{label}`. A label-only place is stored as text and is `routable: false`.

An appointment never moves or replaces a legal deadline and never changes the
workflow (`legal_effect: not_assessed`).

**Timeline identities.** `appointment` and `submission_recorded` rows carry
`event_id`, the report's stable identity (use it for `submission_retracted`), and
`revision_event_id`, the latest correction (use it in
`POST /processes/{process}/events/{revision_event_id}/corrections`). Appointment
rows also carry `appointment_id`, `duration_minutes` (`null` = unknown) and
`routable`. Do not parse identifiers out of `timeline[].id`.

**Step completion event.** Each `guidance[]` entry has `completion_event`.
`submission_recorded` (catalogue step kind `action`) means offer “I've submitted
it”. `step_completed` (every other kind) means offer “I've finished this step”.
This is derived from the reviewed catalogue, not decided by the UI.

**Composer and Today.** An appointment without any `location` in the planning
window still makes planning return 422 on `appointments` ("add its location").
Uncertain appointments otherwise stay in the plan, without guessed values:

- Label-only place (`routable: false`): the slot has `routable: false` and
  `lat`/`lng` `null`. No journey to or from it is computed or offered. The slot
  and the stop after it have `travel_known: false`, `travel_min_from_previous: null`
  and `leave_by: null`. The plan adds a notice with
  `code: appointment_location_unroutable` and `appointment_id` (the slot id).
  Offer "Add address", which re-records or corrects the appointment with `lat`/`lng`.
- Unknown length (`duration_minutes: null`): no block length is drawn or assumed.
  The slot has `duration_known: false`, `end_time: null` and `duration_label: null`.
  Show "End time not known". `end_at` equals `start_at` and is not an end time.
  `leave_by` uses the start only. The next stop has `may_overlap_previous: true`.
  The plan adds a notice with `code: appointment_end_unknown`. This is not a hard
  conflict: `schedule_feasible` is unaffected. If an unknown-length appointment
  started up to 24 hours before the window, it may still be running, so planning
  that window returns 422.

Every slot has `travel_known` and `may_overlap_previous`. Today returns the same
notice codes next to `appointment_conflict`.

Keep date meanings separate:

- `legal_due`: source-backed legal date, not a meeting.
- `preparation_target`: preparation timing, not a claimed statutory deadline.
- `appointment`: recorded instant, offset, IANA timezone and the reported duration (`null` = unknown).
- `submission_recorded`: reported submission, no automatic legal continuation.
- `authority_follow_up`: follow-up under its reviewed policy.
- `document_expiry`: physical document timing, not proof that permanent status ends.

Calendar dates are exact `YYYY-MM-DD` strings, not UTC instants. Appointment
`starts_at` includes seconds and an explicit offset such as `+02:00`, consistent
with `timezone`; there is no invented default duration. `location` is optional:
unknown or label-only location stays unroutable and Composer does not route to it. A later appointment
never moves a legal deadline. Show `date_unknown`, `needed_fact`, `conditional` and
overdue state rather than replacing them with guessed dates.

## Paperwork and optional tools

`bureaucracy.paperwork.1` returns `requirements`, `evidence` and `capabilities`.
Each requirement has separate applicability and readiness, plus process/occurrence
identity. Readiness is `missing`, `reported_available`, `confirmed_for_use` or
`needs_reconfirmation`. Having a matching evidence item is not confirmation for
every application; conditional papers must not look universally mandatory.

Confirm a requirement with
`POST /processes/{process}/requirements/{requirement}/confirm`: UUID `request_id`,
`expected_version` (process), `evidence_id`, `evidence_version`, current
`requirement_hash` and `confirmed: true`. Start a proposal before confirming a use.
Evidence changes and sharing require their own explicit commands and permission.

Evidence has `storage: metadata_only`; there are no uploaded files to preview.
Translation, email writing, tax preparation, upload and OCR remain
`available: false, reason: not_implemented`. Future-feature cards must say so and
must not accept sensitive text/files as if those services work.

## Errors and remaining integration work

Treat 401/419 as authentication/session recovery, 403/404 as inaccessible records,
409 as refresh/review required, 422 as field validation, 429 as a quota/rate limit,
and 5xx as unavailable with retry. Never call all failures an “ad blocker”.
Do not display a successful save until the command confirms it.

## Read-only QA decisions

Admins can inspect a roster case using
`GET /bureaucracy/v2/preview/{persona}?jurisdiction=de-nrw-cologne`.
This returns `bureaucracy.qa-assessment.1`, explicitly marked `read_only` and
`sample_content`. It is a diagnostic assessment, not the person-plan UI schema.
It uses the same current reviewed catalogue, assessor and question protocol, but
only synthetic fixture inputs. It never attaches a person, creates question
offers, uses account/family answers or activates AI. Unknown persona keys return
404; ordinary users cannot access it. Admin status is reloaded before access, so
revocation takes effect even if authentication holds a stale model. Requests are
rate-limited and not cached.

The result includes fixture facts, process decisions, question candidates and
explicit coverage. Unmapped legacy fixture context is labelled separately; it
does not become an inferred legal fact. Question candidates have no live offer
IDs or answer tokens. No active catalogue returns `not_activated`; choosing a
different jurisdiction cannot reuse Cologne decisions.

The legacy `POST /qa/become/{persona}` and `POST /qa/reset-tasks` are retired.
For a verified admin they return HTTP 410 with `code: qa_mutation_retired`, a
plain-language explanation and `preview_route: bureaucracy.v2.preview`. They do
not edit a profile, delete a dossier or reset progress. This includes accounts
without completed onboarding or a dossier. Non-admins get 403; an unknown persona
gets 404 after authorization. Admin privileges, a QA email/badge or the local
environment never make an account disposable. Calling the old reset service
directly is also non-mutating and fails explicitly.

The old HTML demo and frontend persona controls have **not** been integrated with
the canonical preview. They must be replaced before release; do not restore the
retired mutations to make stale UI controls appear functional. The preview schema
is diagnostic and is not a substitute for the actual person-plan UI schema.

## Remaining integration

Open integration work: replace legacy page/write adapters, complete the QA
switcher cutover, publish executable state fixtures/types, and verify the real redesigned
onboarding/Overview/Paperwork flow on desktop/mobile. This document is not evidence
that the separate UI already uses these endpoints.

Account/answer attachment has been rehearsed with disposable records. The owner
waived old task/document progress transfer; existing progress stays stored and
is not replayed into new processes. No live attachment or deployment has run.
