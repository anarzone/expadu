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
| `processes` | Current process occurrences; a proposal has `id: null`, `version: 0`. Each also has `title`, `topic_label`, `steps`, `is_closed`, `closed_on`, `closed_event_id`, `blocking_reason` and `untrackable` (see “Process presentation fields”). |
| `history` | Retained occurrences without current guidance; never silently discard old work. Same presentation fields as `processes`. |
| `history_count` | `history.length` plus closed (`is_closed`) entries of `processes`: what a History list shows. |
| `topics` | Topic IDs with `label`, definition, occurrence and history references. |
| `guidance` | Reviewed text, criteria, instructions, official actions and source metadata, with `applies` and `after_arrival` (see “Guidance that is not a task”). |
| `progress` | `total` and `todo`, `blocked`, `waiting`, `completed`, each with exact `count` and `ids`. |
| `timeline` | Typed dates/appointments, with uncertainty and provenance kept separate. |
| `attention` | Dated rows inside the product attention windows plus open-step deadlines whose date is unknown (see “Attention and coming up”). |
| `coming_up` | The `attention` rows due today or within the next 7 calendar days in the jurisdiction time zone. |
| `coverage` | Overall `partial`, `not_activated` or `outside_coverage`, process-level results, withdrawn rules and per-unit `units`. |
| `paperwork` | Scoped requirements and evidence metadata, or `available: false, reason: permission_required`. |
| `scopes`, `ai` | Available operations; AI still needs single-request consent and confirmation. |

All of the fields below are read-only projections. Reading the plan writes nothing,
and none of them moves a decision to the client.

### Process presentation fields

```json
{
  "title": "EU Blue Card",
  "topic_label": "Residence",
  "untrackable": true,
  "is_closed": false,
  "closed_on": null,
  "closed_event_id": null,
  "blocking_reason": null,
  "steps": [{
    "id": "42:case.bc.first_application.prepare.complete",
    "step_id": "case.bc.first_application.prepare.complete",
    "guidance_id": "case.bc.first_application.prepare",
    "title": "Prepare the evidence for your first Blue Card application",
    "description": "…reviewed text…",
    "status": "todo",
    "step_state": "todo",
    "position": 1,
    "depends_on": [],
    "verified_at": "2026-09-08",
    "review_due_at": "2027-03-08",
    "content_version": "2026-09-08.1",
    "sources": {
      "official": [{"id": "case.bc.first_application.prepare.action.1", "url": "https://www.stadt-koeln.de/…", "purpose": "information"}],
      "legal": [{"kind": "primary", "label": "§18g AufenthG", "url": "https://www.gesetze-im-internet.de/…"}]
    },
    "requirements": {"ready": 0, "total": 5, "optional": 0},
    "first_open_requirement": {"id": "case.bc.first_application.prepare.document.1", "label": "Valid passport and national D visa",
      "readiness": "missing", "applicability": "required", "conditional": false},
    "dates": []
  }]
}
```

- `title` is the process's own short name from
  `database/seeders/data/bureaucracy/schema/process-titles.yaml` (for example “EU Blue
  Card”, “Address registration”), so a single-step process no longer repeats its step
  heading. Names carry no figures or legal claims. A release without a name for that
  process falls back to the first step's reviewed title. A `history` entry whose units are
  no longer in the active release and has no name has `title: null`.
- `untrackable` is true when “Track task” can still be undone with `process_untracked`:
  the process is started, `not_started`, with no completed step, no event other than
  `process_started`/`process_untracked`, and no requirement confirmation or evidence share.
  The command applies the same rule. A proposal (`id: null`) is never untrackable.
- `progress_options` lists the workflow reports the state machine accepts from the current
  state, in display order: `[{event, date, note, allowed, reason}]`. `date` is
  `required`, `optional` or `null`; `note` says whether a note may be sent. Completion is
  listed with `allowed: false` and a `reason` while a step is still open. A proposal has
  an empty list. Offer only these changes; the command still validates every report.
  `topic_label` comes from `config('bureaucracy_catalogue.topic_labels')` (navigation
  copy, not legal content).
- `steps[].id` equals the action/progress id `<process_id or occurrence_key>:<step_id>`.
  `status` uses the progress buckets `todo`, `blocked`, `waiting`, `completed`, plus
  `cancelled` for a cancelled process. `step_state` is the raw stored step state.
- `position` is the reviewed `position` from `schema/process-map.yaml` (1 = first).
  Steps are listed in that order. The compiler rejects non-positive or duplicate
  positions within a process. Releases compiled before positions existed report
  `null` and keep their stored order. Re-import the catalogue to get positions.
- `requirements` and `first_open_requirement` are `null` without `manage_evidence`.
  `total` counts this step's `required` paperwork rows and `ready` those of them that are
  `confirmed_for_use`; only required rows can be confirmed, so `ready` can reach `total`.
  `optional` counts the conditional rows ("may be needed"), which are listed but never
  counted. The first open requirement is the first required row not yet confirmed, or the
  first conditional one once every required row is ready. It is a presentation aid, not the
  cause of anything. `conditional` is `applicability !== 'required'`.
- `steps[].dates` are the timeline rows with this step's `action_id`.
- Retained `history` steps keep their recorded states. Fields that need current
  reviewed guidance are `null` or empty.
- `is_closed` is `workflow ∈ {completed, cancelled}`. `closed_on` is the
  `occurred_on` of the person's active completion/cancellation report (`null` when
  they gave none). `closed_event_id` is that report's event id. Closed processes
  never appear in `actions`.
- `blocking_reason` is set only for workflow `action_required` or `blocked`, and only
  from the person's own paperwork: the first requirement (step order, then catalogue
  order) whose readiness is `missing` or `needs_reconfirmation`. Otherwise it is `null`.
  It is never a legal reason.

  ```json
  {"basis": "requirement_readiness", "step_id": "…prepare.complete", "requirement_id": "…prepare.document.1",
   "label": "Valid passport and national D visa", "readiness": "missing", "applicability": "required"}
  ```

`actions[]` also carry `process_title`.

### Timeline links

Timeline rows produced by a step add `step_id` and `action_id`, so `actions[].dates`
and `steps[].dates` are explicit joins, not inferences. A `dated` deadline row adds
`anchor_fact` (the confirmed date fact it was taken from). When that fact also has a
recorded expiry row, `anchor_event_id` names it:

```json
{"id": "<occurrence_key>:case.bc.first_application.submit.due", "kind": "legal_due", "date": "2026-10-08",
 "state": "dated", "action_id": "42:case.bc.first_application.submit.complete",
 "step_id": "case.bc.first_application.submit.complete", "anchor_fact": "visa_expires_at", "anchor_event_id": "visa.expiry", "…": "…"}
```

`document_expiry` rows are personal recorded dates, not legal deadlines:

```json
{"id": "visa.expiry", "kind": "document_expiry", "date": "2026-10-08", "precision": "calendar_date", "timezone": "Europe/Berlin",
 "state": "dated", "overdue": false, "provenance": "confirmed_fact", "legal_effect": "not_assessed",
 "fact_key": "visa_expires_at", "document": "visa"}
```

| `id` | Fact | Shown when |
|---|---|---|
| `visa.expiry` | `visa_expires_at` | the confirmed `current_residence_title` is `national_d_visa` |
| `residence-title.expiry` | `residence_title_expires_at` | the title is known and not `settlement_permit_9/18c/unknown` |
| `residence-card.expiry` | `residence_card_expires_at` | the date is confirmed (unchanged) |

An unknown title shows no visa or title expiry row. Showing an old date as the end
of an unlimited status would be wrong.

### Attention and coming up

`attention[]` reuses `PlanAttention`: the same windows (appointments 7 days, other
kinds 14 days, overdue kept) and `urgency` values as Home and reminders.
`event_revision` is unchanged by the additive fields. In the plan it also lists
open-step deadline rows in state `date_unknown` with a `needed_fact`, as
`date: null`, `days_remaining: null`, `urgency: "date_unknown"`, `label: null`.
Dated rows come first, sorted by `days_remaining`.

```json
{"id": "visa.expiry", "person_id": 7, "jurisdiction": "de-nrw-cologne", "process_id": null, "occurrence_key": null,
 "kind": "document_expiry", "date": "2026-10-08", "timezone": "Europe/Berlin", "days_remaining": 1, "urgency": "critical",
 "title": "Document expiry", "label": "Document expiry: 8 Oct 2026", "source_rule_id": null, "source_hash": null,
 "action": {"type": "review_details", "person_id": 7, "process_id": null, "occurrence_key": null, "event_id": "visa.expiry"},
 "event_revision": "…", "assessment_revision": "…",
 "state": "dated", "needed_fact": null, "overdue": false, "conditional": false, "action_id": null, "step_id": null,
 "anchor_fact": null, "anchor_event_id": null, "legal_effect": "not_assessed", "provenance": "confirmed_fact",
 "fact_key": "visa_expires_at", "document": "visa", "starts_at": null, "duration_minutes": null, "location": null}
```

Appointment rows fill `starts_at`, `duration_minutes` and `location`. Step deadline
rows fill `action_id`/`step_id` (`legal_effect` is `null` there; `kind` says whether
it is `legal_due`). `coming_up[]` holds the same row objects with
`0 <= days_remaining <= 7`. Overdue and undated rows stay in `attention` only.

### Question entry state

`questions` adds `entry_state`, `deferred` and `candidates_count`. They are `null`,
`[]` and `null` when the viewer lacks `edit_facts`.

```json
{"status": "preview", "session_id": 12, "remaining_information_count": 2, "question": {"…": "…"},
 "entry_state": "in_progress", "deferred": ["visa_expires_at"], "candidates_count": 3}
```

- `entry_state`: `none` means no unexpired interview session for this viewer.
  `paused` matches `status: paused`. `in_progress` covers any other session.
- `deferred`: fact keys skipped in this session whose skip is still valid. Keys only,
  no values.
- `candidates_count`: all current protocol candidates, including deferred ones.
  `remaining_information_count` excludes deferred ones.

### Guidance that is not a task

`guidance[]` holds every unit the assessment considered for the person, with
`definition_id` and `topic`. Each row has:

- `applies`: every criterion is met (`assessment` is `requirements_met` or
  `supported_preparation`). A row whose criteria are still unanswered or unmet is
  `false`.
- `after_arrival`: the person is still planning the move, the step applies, and its
  catalogue phase is after arrival (`first_30_days`, `settling`, `ongoing`). It is not
  `actionable`, is not proposed as a task and raises no questions until arrival.

Show, by `kind`, only rows with `applies: true`:

- `context`: “Good to know”. Information without steps, documents or dates. Never
  a status, a step count or a tracking action.
- `option`: “Options for you”. A route the person may choose, such as permanent
  residence or the small-business VAT rule. It is never a task and never says the
  person qualifies; the authority decides.
- Rows with `after_arrival: true`: “After you arrive”, listed and not to-dos.

`current_residence_title` has the option `none`: no German visa or residence title
yet (entered visa-free, or not arrived). It counts as having no title with an expiry.

### Coverage units

```json
{"definition_id": "residence.blue_card.first", "unit_id": "case.bc.first_application.submit",
 "title": "Submit the Blue Card application before your D visa expires", "content_version": "2026-09-08.1",
 "verified_at": "2026-09-08", "review_due_at": "2027-03-08",
 "source_urls": {"official": ["https://www.stadt-koeln.de/…"], "legal": ["https://www.gesetze-im-internet.de/…"]},
 "state": "partial"}
```

`coverage.units[]` lists every reviewed unit in the active release for the
jurisdiction, sorted by `definition_id` then `unit_id`. The `state` values:

- `complete`: a version-bound complete criterion review exists.
- `partial`: reviewed preparation guidance only.
- `not_covered`: in the release but outside its review/validity window.
- `withdrawn`: removed by the live publication gate since activation, including an
  overdue review. Its identity and review metadata stay, with `title: null` and no
  official URLs.

No state means the person is eligible, or that every legal case is covered.

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

Questions come in the backend's order; do not re-sort them. The most urgent dependent
work goes first. Within one urgency, a question for work that already applies to the
person goes before one that only decides whether other routes apply (address
registration before the employment track). `schema/question-protocol.yaml` `not_asked`
lists questions the person's own answers settle: the employment track is not asked once
`case_goal` is `blue_card`.

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

### Fact schema, last checked and answer history

`GET /facts/schema` (authenticated; no person) returns the reviewed registry wording
and answer shapes. There are no display labels in the registry. Option values are
raw enum values, and the UI owns their labels.

```json
{"schema_version": "bureaucracy.fact-schema.1", "registry_version": "…",
 "facts": [{"key": "visa_expires_at", "type": "date", "options": [], "question": "When does your entry visa expire?",
   "why": "We use this date to calculate your residence-application deadline.", "date_semantics": "expiry",
   "allows_not_applicable": false, "subject_scope": "person", "sensitivity": "high", "reconfirm_after_days": 180}]}
```

`GET /people/{person}/facts` adds `evidence[key].checked_at`: the ISO instant the
current assertion was confirmed.

`GET /people/{person}/facts/{key}/history` (needs `view_facts`; unknown keys 404)
lists confirmed answers newest first. AI candidates and synthetic QA rows are
excluded. Values are returned to an authorised viewer.

```json
{"schema_version": "bureaucracy.fact-history.1", "person_id": 7, "key": "current_residence_title", "revision": 4,
 "entries": [{"fact_id": 31, "operation": "changed", "state": "confirmed", "source": "manual",
   "after": {"answer_state": "value", "value": "blue_card"},
   "before": {"fact_id": 30, "answer_state": "value", "value": "standard_work_permit"},
   "effective_from": "2025-06-01", "effective_until": null, "end_date_unknown": false,
   "recorded_at": "2026-10-07T18:00:00+00:00", "confirmed_at": "2026-10-07T18:00:00+00:00", "superseded_at": null}]}
```

`operation` is one of:

- `asserted`: a first answer, or the same answer reconfirmed.
- `corrected`: replaces a mistaken answer. `before` is the corrected row.
- `changed`: a real change. `before` is the previous answer.
- `resolved`: a conflict confirmation.
- `recorded`: legacy rows with no operation.

`value` is `null` unless `answer_state` is `value`.

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
| `process_untracked` | `{}` | `not_started`, with no completed step, no event other than `process_started`/`process_untracked`, and no requirement confirmation or evidence share (same rule as `untrackable`) | `untracked` |
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
identity.

- `terms`: German document terms named in the requirement's `label` or `note`, each as
  `{german, english}` (for example `{"german": "Mietvertrag", "english": "Rental contract"}`).
  Show the English name with the German term the office uses. Names come from
  `schema/document-terms.yaml`; they carry no figures or legal claims. Empty when the
  label names no listed term.
- `produced_by`: the published step that produces this document, as `{unit_id, process_id}`
  (for example proof of health insurance → `{"unit_id": "core.health_insurance",
  "process_id": "health.coverage"}`), or `null`. It is a catalogue link, not the person's
  progress and not a prerequisite: the requirement does not wait for that step.
- Guidance rows (`guidance[]`, `processes[].guidance[]`) carry the reverse list,
  `produces: [{unit_id, process_id, document_id, label}]`, naming the documents other
  published steps need from this one.

Names and links never change a requirement's `id` or `semantic_hash`, so existing
confirmations stay valid. Links exist only where a step evidently yields the document;
the catalogue has no sourced basis for others (for example, banks or insurers needing
the registration certificate), so it does not claim them. Readiness is `missing`, `reported_available`, `confirmed_for_use` or
`needs_reconfirmation`. Having a matching evidence item is not confirmation for
every application; conditional papers must not look universally mandatory.

Confirm a requirement with
`POST /processes/{process}/requirements/{requirement}/confirm`: UUID `request_id`,
`expected_version` (process), `evidence_id`, `evidence_version`, current
`requirement_hash` and `confirmed: true`. Start a proposal before confirming a use.
Evidence changes and sharing require their own explicit commands and permission.

`PUT /people/{person}/evidence/{uuid}` accepts an optional
`details.requirement_refs`: a list (max 50) of requirement ids from
`paperwork.requirements[].id`. The item stays one person-level record. Paperwork
lists it in `suggested_evidence_ids` and shows `reported_available` on every
requirement it references, in any process, or whose reviewed `evidence_kind`
matches `details.kind`. The catalogue currently defines no `evidence_kind`, so send
references. To say “I have this” for another requirement later, `PUT` the item again
with the extended list and the current `expected_version`. A reference never
confirms use. Confirmation still needs the confirm command, which accepts a
referenced item even when its free-text `kind` differs.

```json
{"request_id": "…uuid…", "expected_version": 0,
 "details": {"label": "Passport", "kind": "passport", "reported_available": true, "expires_on": "2030-01-01",
   "requirement_refs": ["case.bc.first_application.prepare.document.1"]}}
```

Evidence has `storage: metadata_only`; there are no uploaded files to preview.
Translation, email writing, tax preparation, upload and OCR remain
`available: false, reason: not_implemented`. Future-feature cards must say so and
must not accept sensitive text/files as if those services work.

## Automatically checked cards

Most catalogue cards are checked by machine instead of being signed off by a person
(`source_verification: quote_checked`, `reviewed_by: automated_source_check`). Each
card's `claims` quote the official sentence behind every figure it shows.
`bureaucracy:verify-sources` re-reads those pages daily (05:15 Europe/Berlin) and at
deploy:

- **Pass**: the card is publishable for 30 days, renewed when fewer than 14 remain.
  Its `verified_at` is the first day this exact content passed.
- **Fail** (a quote has gone, or the page returns 404/410): the card is withdrawn at
  once and the failure is escalated.
- **Unreachable** (timeout, server error, bot check): nothing changes; the last pass
  runs out if the page stays unreachable.

A card that is not currently confirmed never shows its guidance. It appears in
`coverage.units` with `state: "unconfirmed"`, its `title`, and
`source_urls.legal` (allow-listed official pages only). Show it as: “We couldn't
confirm this right now. Check the official page.” with those links. Do not show
its steps, documents or dates. In a compiled release the inventory status of such
a card is `source_unconfirmed`.

Escalations (`bureaucracy_escalations`) record failed checks and plans the app
cannot answer (`no_verified_answer` per process, `empty_plan` per jurisdiction).
They hold no personal data. High-severity items reach the owner through Sentry,
once each time an item opens.

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
