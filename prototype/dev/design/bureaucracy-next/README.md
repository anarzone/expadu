# Bureaucracy design preview

Open `http://127.0.0.1:8765/dev/design/bureaucracy-next/`.

The UI review covers Overview, process details, inline Paperwork and Composer planning. This remains a local clickable prototype, not a production Bureaucracy client.

## Finished in this pass

- Whole-plan Overview: at most three actionable next steps. Blocked/waiting work remains in the exact status counts and full action list. Compact process cards and recorded dates stay accessible.
- Process details: stable steps, explicit start for a proposed process, self-reported preparation/blocked/waiting/completed/cancelled state, dates and source access.
- Paperwork across processes: compact, inline disclosures with a process filter and separate availability/confirmation for each application. A confirmation keeps the current row open and focused. Starting and reconfirming are explicit actions.
- Unknown expiry: one question, explicit answer confirmation, skip and return later. Other processes remain accessible.
- Dates: document expiry, recorded appointment and submission stay separate. No legal date is calculated in the client.
- History: completion/cancellation records remain reachable. They are user reports, not authority decisions.
- Composer: plan personal preparation time inside a process using shared date/time controls, one-hour suggestions and Your day. Recorded appointments retain their date/start and require an explicit planned end. Add, edit and remove create proposals; confirmed plans and process records remain separate. Inline removal confirmation and guarded Undo are supported. Composer activities link back to the matching process/step, and minimizing Composer restores the origin. No document readiness, expiry or case guidance is copied into plan activities.
- Navigation: Overview, Paperwork, filtered actions, process details and history share one model. Native Back/Forward restores the requested view. An unavailable process does not silently fall back to another one.
- Family preview: separate authorised adult and dependent sample plans, document readiness and personal answers. View-only, pending and removed access states hide the corresponding private information. Sample permissions do not grant real access.

## Earlier sample-plan entry (retained for comparison)

- A compact “Help me get started” entry offers existing topics and an “I’m not sure” path. Browsing a topic does not start it. This is orientation, not an applicability interview.
- Step actions lead to the relevant document checklist or the exact existing official source. The first unchecked document is an entry point, not a claimed cause of a blocked task. Personal scheduling remains secondary.
- Availability and readiness use explicit labels (“I have this”, “Ready for this task”, “Not ready yet”). Readiness stays scoped to each task and never completes a workflow step automatically.
- “Help with this step” opens the matching source card in Composer, scrolls to it and returns to the exact step. It preserves the unfinished prompt, pending handoff and existing day plan. It does not generate legal advice or copy document/fact details into chat.
- Local text and touch targets are larger, inline editor focus returns to its trigger, and primary actions precede long document names on small screens.

## Basis and fixture boundary

Direction comes from the direct handoff with **Option B Bürokratiekarten** and `docs/contracts/bureaucracy-v2.md` in its separate backend worktree. The new contract describes a person-scoped plan, three next actions, one relevant question, complete action/history access, explicit process starts, scoped evidence, typed dates and backend-owned assessment.

`source-data.js` preserves exact catalogue titles/document labels from:

- `non_eu_employee_blue_card.yaml`: `case.bc.first_application.prepare` and `case.bc.first_application.submit`, version `2026-08-03.1`, recorded verification `2026-08-03`. Their descriptions are copied exactly as historical snapshots.
- `core.yaml`: `core.anmeldung`, `core.health_insurance`, `core.bank_account`, `core.steuer_id`, version `2026-08-22.1`, recorded verification `2026-08-22`. Their legal descriptions, fee figures and timing rules are deliberately not presented here.

The YAML approval flag does not establish current publication validity. This prototype does not run or bypass `Task::authoritative()` or `RuleSourcePolicy`. Current validity is identified as unverified. No new legal instructions, fees, eligibility decisions or computed legal deadlines were authored for it.

The Blue Card expiry (`2026-10-01`) comes from the backend's synthetic scenario. Other process statuses, readiness, ordering and historical completion date are **interaction fixtures**, explicitly labelled sample progress. They are not real account data or captured backend assessments. `plan.js` simulates a shared projection for visual review; production must consume backend decisions rather than port this simulation into React.

## Earlier guided orientation — 28 September

“Help me get started” now opens a compact inline flow shared with Composer. The
entry becomes “Continue where you stopped” for unfinished answers and “Update
your situation” once the sample questions are answered. Known sample visa,
goal and expiry details are available for review without being asked again.

This increment models two optional product-orientation questions: arrival stage
and what the person would like to do next. A choice is saved as an answer only
after **Continue**. “I’m not sure” is an explicit unknown answer;
**Skip for now** leaves the question unanswered. Pausing, refreshing, browsing a
topic and switching to Composer preserve the current question and draft choice.
The review lets the person edit either answer or continue to their existing
steps, documents or appointment entry. Topic browsing does not start a process.

Both surfaces read the same person-scoped orientation in
`expadu-bureaucracy-design-v1`. Composer merges only that orientation, without
copying answers into conversation messages or touching its typed prompt, saved
days or pending proposal. The selected person travels with the handoff. A switch
of person or loss of sample edit access blocks the old editor.

### Follow-up answers and family views

The shared inline surface now includes **Review personal details**. It reuses
known answers, presents one missing question at a time and returns to the review
after confirmation. Skipped, unknown and declined answers stay distinct. A
saved answer can be corrected or recorded as a real change, with an optional
effective date and retained history. Competing answers are shown without a
preselected winner, followed by explicit review and confirmation. Revision
checks reject a conflicting save from another surface.

Question titles and enum values come from the backend worktree's
`database/seeders/data/bureaucracy/schema/facts.yaml`. The field sequence is
an interaction fixture, not a client-side applicability engine. **Preview
states** exposes synthetic conflicting answers for design review. No legal
guidance, eligibility result or deadline is generated by these answers.

The person picker includes Alex (shared adult) and Sam (dependent), with separate
sample Overview, Paperwork and answer records. Each view identifies its person
and access scope. The **Access & sharing** disclosure has a labelled sample-state
selector for edit, plan-only, pending and removed access. It is not a real
invitation, consent or guardian-verification control. Unauthorised sample people
remain inaccessible. Today continues to show the signed-in person's records
even when a family member was last viewed in Bureaucracy.

### Optional welcome

The approved direction is to enter the app without a required settlement
questionnaire. Today has a small, dismissible invitation to start when useful;
it opens the person's own Bureaucracy guide. Dismissal persists, and the welcome
is hidden once their guide has started. For review, use
`../today-c/?welcome=preview`. There is no profile-completion pressure.

These flows do not run an assessment or change the fixture task list. The
prototype demonstrates the new UI direction; **the actual Laravel login and
account-onboarding gate are unchanged**. Removing that gate belongs to the
later approved frontend implementation. The backend remains responsible for
question selection, applicability, legal content, authorisation and confirmed
fact storage.

## Fresh start and returning situation — 28 September

This is now the default for a browser without a saved Bureaucracy workspace.
The introduction explains next steps, documents and dates, with **Get started**,
**Browse first** and **Start in Composer**. It seeds no permit, completed documents,
processes, history or family access. Existing saved sample plans are retained.

- The starter asks arrival stage, purpose of stay and citizenship group, one at a
  time. Selecting a choice is a draft; **Continue** confirms it. After three
  questions the user can open their overview or continue with relevant details.
  Skipping, unknown and declined remain distinct. Follow-ups use the recorded
  arrival/citizenship/title fixture; known answers are reused.
- **Your situation** shows saved answers, missing details and review requests.
  Browse stays available. **Track task** explicitly starts only the chosen
  sample topic, with every document unchecked. No topic is presented as an
  eligibility recommendation.
- **Something changed** supports work, home, residence and family reports.
  Its confirmation includes an optional effective date. Confirmation records
  the report and flags related answers; it does not replace facts, complete
  tasks or establish a permit change. A flagged answer can be kept or edited
  through the existing correction/change review. Earlier answers and reports
  remain in history.
- Composer and the page share the same record and unfinished choice. In the
  fresh/returning scenarios, explicit example messages such as **I changed
  jobs**, **I moved home** and **I got a new residence permit** open that same
  change confirmation. Matching is deliberately scripted: negations,
  hypotheticals and third-person reports do not trigger it. An unfinished
  update is resumed before another report can replace it.
- The returning fixture demonstrates a quiet **Anything changed?** check-in.
  **Nothing changed** records a check; **Something changed** opens review.
  This does not detect employment or residence changes from external services.
- Draft handoffs carry only a person/profile reference. Composer's prompt and
  proposals remain separate. Writes reject an old person, scenario or revision
  instead of overwriting a newer confirmed update.
- Today starts with a gentle invitation when no tasks are tracked, rather than
  displaying the old fictional progress.

Use **Preview** near the bottom to switch between Fresh start, Returning user
and Existing sample plan. Each keeps its own answers and unfinished work.
Direct review links: `?scenario=fresh`, `?scenario=returning`,
`?scenario=existing`. Reset demo resets the selected scenario only.

Question titles and values reuse the contract facts schema. The sequence,
review flags and check-in are UI fixtures. Production still owns question
selection, freshness policy, assessment, secure persistence and permissions.
The Laravel onboarding gate and backend are unchanged.

## Still required for production

- Wire authenticated v2 endpoints, revision/review tokens and the real person-scoped plan; handle stale/revoked permissions and network/session errors.
- Use currently published source guidance and backend applicability, action ordering, progress and date projections.
- Connect the reviewed question, correction/change, competing-answer and family interfaces to the complete backend contract rather than porting the fixture sequence.
- Integrate real evidence metadata and sharing/guardian permissions. The family and inaccessible-person views here are samples, not an implemented authorisation system.
- Apply the optional-onboarding decision to the real login/first-visit flow after UI approval.
- Adapt recorded appointments to the backend's exact offset/timezone/duration contract. No duration is invented by this preview.
- Rehearse legacy-data migration and cutover with the backend task, then run end-to-end production-client validation.

Optional AI extraction, translation, writing, uploads and OCR remain outside the functioning preview. No AI provider is called and no files or sensitive text are collected for unavailable tools.

## Persistence and review

The sample plan uses `expadu-bureaucracy-design-v1`, preserving earlier Blue Card edits and migrating named requests once. Reset demo resets only Bureaucracy. Composer retains its separate shared storage key and saved snapshot. Later case edits do not silently rewrite the draft or saved day.

Automated coverage includes process states, scoped readiness, proposal creation/edit/removal/undo, conflict handling, atomic workspace creation, safe process links, asset versions and the shared calendar. Browser review covers desktop plus 393px and 320px layouts, inline document confirmation, calendar navigation, time-wheel editing, preparation/appointment proposals, removal confirmation and return from Composer. Earlier interaction checks used the isolated localhost origin. The fresh-start increment uses separate preview scenarios and preserves the existing sample plan.

The follow-up increment passes 280 related checks. Its browser review covers
320px/393px phones and 1280px desktop: confirmed versus draft answers, correction
and real-change review, unknown date feedback, conflicting-answer history,
family document isolation, plan-only and removed access, preserved Composer
prompt/proposal and exact-person return, optional-welcome dismissal, and Today's
personal summary after family browsing. The final preview produced no console
warnings or errors. Review images are `details-mobile-review.png` and
`family-desktop-review.png`.

```sh
node --test prototype/dev/design/bureaucracy-next/tests/*.test.cjs prototype/dev/design/connected/tests/*.test.cjs prototype/dev/design/composer-studio/tests/*.test.cjs prototype/dev/design/today-c/tests/*.test.cjs prototype/dev/design/departures-next/tests/journeys.test.cjs
```

The fresh/returning increment passes 296 related checks, including empty-start
state, conditional questions, the three-question pause, retained drafts,
confirmed change reports, separate scenarios and stale-write rejection. Browser
review covers 320px/393px phones and 1280px desktop, the page-to-Composer round
trip, a scripted job-change confirmation, explicit topic tracking and Today's
empty starting point. The final welcome action is visible in the initial phone
viewport. Review images: `fresh-mobile-review.png` and
`situation-desktop-review.png`. A transient partial script load was caught in
final QA; subsequent clean reloads and the final user preview loaded correctly.

The entry page versions its local assets by content hash to prevent stale data and scripts being mixed by the browser. The normal preview address was reloaded and checked after fixing this cache issue.

The preview server remains on port 8765. Production code, deployment and the separate backend worktree are untouched.
