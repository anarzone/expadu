# Bureaucracy backend execution record

Plan: `docs/superpowers/plans/2026-09-07-bureaucracy-complete-solution.md`

## Scope and authority — 7 September 2026

The owner authorised the complete build, source-based resolution of content gaps,
and an isolated worktree. This expands the earlier plan's content-review scope:
the assistant may research and implement supported rule corrections and record an
owner-authorised source review. It must not claim an independent human/legal review
occurred. Ambiguous law, missing evidence and authority discretion remain explicit;
publication guards are not bypassed. No deployment or live AI activation was requested.

Use the existing local reference log, catalogue source lists and gap notes as the
research starting point; recheck consequential claims against current official sources.

## Workspace and services

- Branch: `codex/bureaucracy-backend-v2`.
- Base: `c386427`; main staging checkout and its unrelated changes are preserved.
- Worktree: `.claude/worktrees/bureaucracy-backend-v2`.
- PHP 8.4.23; Node 22.14.0.
- Locked Composer dependencies installed with application scripts disabled.
- Disposable containers: `expadu-bureaucracy-v2-pg` and `expadu-bureaucracy-v2-redis`.
- Their host ports are 32768 and 32769 respectively; no app data mounts are used.
- The worktree `.env` contains test-only configuration, no copied account/provider secrets.

## Execution decisions

- Owner scope update, 8 September: no legacy saved-progress transfer. Existing
  account details and confirmed answers remain in scope; old task completions,
  document checks, appointment/submission workflow reports are not imported into
  new process/evidence state. Existing rows remain untouched. This is a scope
  reduction, not authorisation for a live reset, deletion or deployment.
- Keep the tightly coupled architecture/security changes in the orchestrator. Delegate
  only independent routine inventory/test work, then review it.
- Start with isolated tests and the reproduced deadline defects while source review
  proceeds. This reorders the small database-free safety slice of T03 ahead of the
  broader T02 consumer cutover; it does not declare either task fully complete.
- Resolve each legal/content gap from evidence rather than bulk-approving the catalogue.
  Already approved records also need checking when the local research log flags an
  unsupported claim.

## Status — 8 September 2026

This is an implementation checkpoint, **not a complete backend or release**.

### Source and question correction continuation — 8 September

- Owner clarified that official rules are the authority and AI must judge only
  against them. Supported local corrections now carry honest owner-authorised
  source review; this is not an independent legal review or live publication.
- Real imported tests reproduced unnecessary `permit_track` questions, missing
  employee registration preparation, duplicate family registration steps and an
  unrequested Blue Card application becoming actionable. Corrected explicit
  source conditions preserve review/version gates. A separate intent gate never
  substitutes for legal criteria or suppresses unrelated obligations.
- Read-only code review found that unresolved intent lacked a question. Three
  failing regressions reproduced it; corrected dependency handling exposes an
  answer/conflict/reconfirmation path. The reviewer confirmed the correction
  statically. A different confirmed goal is not repeatedly questioned.
- Source-reviewed core preparation removes unsupported clocks, provider rankings,
  universal registration prerequisites and repeated newcomer tasks. Registration
  asks actual completion separately from housing-provider proof. Three new
  minimal needs/status answers do not collect account/tax identifiers or medical
  details. See the claim ledger for sources and remaining partial coverage.
- Explicit audience imports reject malformed/null modes and malformed conditions
  before any write, including unreviewed explicit records. Tests reproduced two
  validation gaps first. Changing an approved audience without a content-version
  bump is rejected without altering the stored record.
- Intermediate imported-journey/core/policy checkpoint: **74 passed, 540
  assertions, 38.28 s**. Later added policy tests exposed an unsaved synthetic
  source fixture being correctly withdrawn; the fixture now persists the same
  source record it compiles. Wider post-format results are recorded below when
  available; this intermediate pass is not a full-suite green claim.
- Earlier separate old-contract checkpoint: **38 failed / 99 passed, 573
  assertions, 41.66 s** in four root test files. Artifact:
  `/private/tmp/expadu-bureaucracy-v2-legacy-checkpoint-2026-09-08.xml`.
  It is superseded for those files by the 149-test checkpoint below. Actual
  consumer cutover is still required; passing compatibility tests does not
  remove the old decision/read/write paths. Steps 1–4 are not yet complete.
- Wider source-correction checkpoint initially returned **907 passed / 14 failed,
  4,532 assertions, 340.89 s**; artifact
  `/private/tmp/expadu-bureaucracy-v2-source-corrections-2026-09-08.xml`.
  The failures identified old whole-case coverage assumptions, retired family
  registration expectations and document fixtures that no longer matched their
  borrowed real registration rule. Real cases now use the canonical preview and
  plan together, retaining missing basic answers rather than manufacturing them.
- Reproduced explicit unconditional content becoming branch-limited in the old
  reader. Compiling an explicit empty condition as `[[]]` preserves unconditional
  meaning, distinct from absent legacy conditions. The focused test failed before
  correction and passed after it.
- The family-renewal corpus reproduced irrelevant separation guidance. Its
  mapping now includes the existing household condition in relevance. Together,
  separated and unknown controls pass; no legal criteria or prose were changed
  for this mapping. Combined imported case/question check: **29 passed, 305
  assertions, 20.18 s**.
- Independent review found a multi-alternative intent defect: another goal's met
  alternative swallowed the chosen alternative's missing answer. Three failing
  regressions reproduced unknown/conflict/reconfirmation. Dependencies now retain
  the chosen viable alternative's missing facts without changing legal OR
  assessment or interviewing when the chosen alternative is already satisfied.
  A decision version invalidates old assessment/offer revisions. Combined
  assessor/policy/imported check: **72 passed, 201 assertions, 15.67 s**.
  The reviewer rechecked the correction and found no remaining issue in that
  bounded scope (static review, not a second test run).
- Preserved mechanical deadline, document-origin, office, dependency, event,
  audience and information/progress checks with explicitly synthetic reviewed
  fixtures. Real unreviewed catalogue records stay unapproved. Reversed unsafe
  assertions about duration-only eligibility, appointment replacement, paused
  unknown dates and bulk settled completion rather than restoring those bugs.
- A failing reset regression proved that the old CLI cleared the profile while
  leaving the dossier behind. It now rejects a partial reset whenever either a
  dossier or person exists, under the account lock used by canonical commands.
  `--force`/`--keep-tasks` do not bypass this. Explicit resets of legacy-only
  profiles retain their previous behavior; other people's records stay intact.
  No live command was run. The obsolete QA CLI help no longer recommends it as
  a way to replay a canonical case.
  A separate static review confirmed target-account locking, both refusal
  conditions, flag behavior and transaction scope without additional findings.
- Combined four root compatibility files plus persona-mutation safety:
  **149 passed, 791 assertions, 44.64 s**; artifact
  `/private/tmp/expadu-bureaucracy-v2-compatibility-reviewed-2026-09-08.xml`.
  Targeted Pint and `git diff --check` passed. The subsequent full PHP run returned
  **2,130 passed / 6 failed / 1 skipped, 9,374 assertions, 622.65 s**. Artifact:
  `/private/tmp/expadu-bureaucracy-v2-official-rules-full-2026-09-08.xml`.
  The remaining failures involve the legacy matcher rejecting an explicit empty
  conjunction, the obsolete duplicate-family-registration expectation, and the
  old audit confusing unanswered prerequisites with broken dependencies and
  demanding registration/new permits from broad persona labels. These are being
  corrected and checked; this is not a full-suite green claim.
- The six failing areas subsequently passed in a **57-test / 208-assertion**
  focused run. The matcher now accepts explicit unconditional conjunctions while
  rejecting malformed alternatives; its snapshot layout version changed too.
  The legacy audit no longer invents registration/permit needs from persona
  labels, and distinguishes unresolved from definitely broken prerequisites.
  Its output explicitly directs operators to the strict canonical manifest.
- Review reproduced an unknown prerequisite hiding a missing nested dependency
  (expected failure, actual success). Catalogue-wide reference integrity now
  checks dormant/unknown records as well. Expanded checkpoint: **58 passed, 212
  assertions, 45.84 s**. A subsequent malformed-array dependency test reproduced
  an audit TypeError; malformed references now remain visible failures without
  aborting the report. The reviewer rechecked both fixes and reported no remaining
  findings in that bounded static review. Final formatting passed.
- Fresh full PHP verification after those fixes: **2,147 passed / 1 skipped /
  zero failures, 9,417 assertions, 645.09 s, exit 0**. Artifact:
  `/private/tmp/expadu-bureaucracy-v2-reviewed-audit-full-2026-09-08.xml`.
  The single skip is the existing weather near-term-window test, which skips
  after 14:00 Berlin time; it is not a bureaucracy or AI test.
  `git diff --check` passed. This supersedes the earlier six-failure checkpoint,
  not the open source-coverage, consumer cutover, rollout or UI integration gates.
- The API contract now records the official-rule authority explicitly: AI only
  proposes answers for confirmation, while source-approved backend rules decide
  the result. No runtime AI decision, source bypass or live model activation was
  introduced by this clarification.
- No live import, account/progress reset, frontend change, commit, push, merge,
  deployment or live AI activation. The earlier instruction-clarification
  blocker below is resolved by this continuation, not a current request to owner.

### Catalogue recovery continuation — 8 September

- Implemented exact-hash catalogue suspension without decoding the artifact or
  deleting releases, dossiers, facts or process history. Suspensions emit durable,
  idempotent reassessment work. An operator can restore a specified immutable
  release through `bureaucracy:recover-catalogue`; current source withdrawals
  remain enforced. This is catalogue recovery, not a global write/read-mode switch.
- Reproduced an activation integrity defect: retrying the already-active release
  returned success for a damaged artifact. Integrity validation now precedes the
  same-hash return. A damaged active artifact can still be suspended safely.
- New recovery tests first failed for the missing recovery behavior and for the
  reproduced integrity defect. After implementation and formatting, the recovery,
  catalogue release, reassessment outbox, consumer parity and plan contract suites
  passed: **49 tests, 620 assertions, 19.16 seconds, exit 0**. Artifact:
  `/private/tmp/expadu-bureaucracy-v2-recovery-2026-09-08.xml`.
- Pint passed on the four touched PHP files; `git diff --check` passed. An
  independent read-only code review found no critical/important defects in this
  recovery slice. It did not test or approve the complete rebuild.
- A read-only caller audit confirms the signed-in HTML page, its legacy question
  and mutation routes, and the HTML demo still need coordinated cutover. Today,
  context, Composer appointment projection, badge and reminders use the canonical
  read model already. The old page cannot be replaced with the new JSON contract
  while pretending its existing UI/forms are compatible.
- Steps 1–4 requested by the owner remain incomplete. No full-suite rerun was
  performed in this continuation; the prior **2,052 passed / 42 failed** checkpoint
  is not superseded by the focused recovery pass. No catalogue wording, approval
  metadata, live data, frontend, branch publication or deployment was changed.
- At that earlier checkpoint, the source-review authority needed clarification.
  It is now resolved by the continuation above: source-supported local corrections
  carry honest owner-authorised provenance; runtime AI cannot author guidance.
  This is not a current permission blocker or evidence that step 1 is complete.

| Task | State | Evidence / work still required |
|---|---|---|
| T01 | Baseline established | Isolated guard, dedicated services and test-only configuration work. Baseline: 1,486 passed, one failure, 5,870 assertions. The cookie-name failure was caused by the test APP_NAME; configuration was corrected and the affected test passed in a focused rerun. No subsequent full-suite green claim. |
| T02 | In progress | Shared publication guard now covers materialisation, checklist, Home, action cache, queued notifications and saved alerts. Obsolete duration-only eligibility and settled bulk completion are contained. Question parity uses approved dependencies plus explicit orientation. The missing family citizenship question is repaired; older consumer-contract tests remain open. |
| T03 | In progress | Exact dates, explicit identity, conflict/history masking, separate appointment/deadline semantics and overdue preservation implemented and regression-tested. Compatibility projection is transitional; canonical people/history is T05/T06 and event-based timelines are T11. |
| T04 | Implemented for existing account-holder paths; focused verification passed | All three external text clients require single-request, purpose/provider/notice/input-bound permission. Atomic quota admission, idempotent encrypted responses, short retention, withdrawal and delayed-result checks are implemented. 163 tests passed, 625 assertions. Family subject/access integration remains T13; this is not release-wide privacy certification. |
| T05 | Family identity/access implemented; lifecycle integration continuing | One canonical account-person/dossier, explicit adult sharing, dependent authority review records, scoped API, revocation, export and erasure guards are tested. Durable derived-copy erasure is implemented ahead of T11. Guardian activation, complete export coverage of later entities and legacy queued-payload cutover remain explicit release dependencies. |
| T06 | Canonical commands and conflict review implemented; compatibility integration continuing | Current conflicts require an explicit version-bound review; ordinary edits cannot bypass it. Current-only confirmation preserves disputed past periods, including unconfirmed legacy candidates and expired undated assertions. Latest fact/history/relationship subset: 36 passed, 196 assertions. Legacy migration still open. |
| T07 | Catalogue/compiler implemented; integration continuing | Immutable staging/activation, source-snapshot validation, strict IDs, live publication/action-host withdrawal and non-destructive retirement are implemented. Explicit audiences, unchanged-version semantic-change rejection and catalogue recovery are now regression-tested. Complete-coverage mappings remain T17 work. |
| T08 | In progress | Pure assessor now has 20 unit tests including review regressions for cross-person sponsor bundles, unresolved deadline selectors, and explicit non-applicability. Process occurrence integration and wider journeys remain open. |
| T09 | In progress | Authenticated resumable question APIs, idempotent offer receipts, three-offer pacing, explicit defer/unknown, dependency-bound tokens and malformed-attempt recovery are implemented and reviewed. Local fact conflicts and relationship-derived conflicts now open distinct usable review paths. Legacy GET selector cutover remains open. |
| T10 | Backend draft/confirmation implemented; UI and old consumer cutover open | Encrypted actor/person drafts, read-only resume/review, atomic confirmation, strict dates, optimistic revisions, expiry/erasure and optional legacy onboarding adapter implemented. Draft review defects were reproduced and fixed. The latest full checkpoint reports no onboarding test failure; the earlier 15-failure checkpoint is superseded. No UI integration claim. |
| T11 | In progress; reviewed core implemented | Repeatable occurrences, explicit continuity review, independent preparation/submission/waiting/completion, append-only report corrections, current-occurrence prerequisites, review-preserving progress changes, private process APIs and separate timeline kinds implemented. Two read-only review rounds reproduced three additional defects; current process/timeline suite: 32 passed, 135 assertions. Discovery integration, source-specific temporal mappings, migration and unified consumer cutover remain open. |
| T12 | Reviewed core and APIs; cutover continuing | Independent review found unseen-version retry receipts and undiscoverable first-time shares. Both failed regression tests before correction; reviewed subset: 39 passed, 119 assertions. Paperwork now delegates to the unified plan. Legacy checkbox cutover, migration and complete lifecycle verification remain open. No upload/OCR/translation/tax/email implementation implied. |
| T13 | Reviewed implementation; wider integration continuing | Selected-person strict-schema extraction, explicit confirmation through canonical answer commands, manual/AI decision parity, edited-answer provenance, encrypted short-lived candidates, rejection, withdrawal, quotas and APIs implemented. Adapter subset: 122 passed, 348 assertions. Review then reproduced grant replacement reviving a candidate and guardian revocation retaining it; both corrected with exact authority binding and immediate invalidation. Review-fix subset: 35 passed, 126 assertions. All transport mocked; no live AI enabled. |
| T14 | In progress | Shared plan/process/Paperwork/questions, Today/sidebar and reviewed reminders implemented. Composer now uses only recorded authorised appointments; encrypted saved plans detect rescheduling, cancellation and erasure. Durable reassessment handles facts, relationships, access, processes, evidence, catalogue activation and future time boundaries. Latest refresh/privacy/catalogue/reminder subset after review fixes: 59 passed, 211 assertions. Remaining legacy page/write adapters and complete consumer cutover remain open. |
| T15 | Answer continuity, account attachment and read-only QA API implemented; UI switcher cutover open | Owner waived old progress transfer. Read-only source classification retains genuine answers, rejects inferred assertions and accepts explicit reconfirmation. Bounded dry-run/apply attachment preserves identities, facts and inert legacy progress. Canonical QA assessment API uses synthetic inputs and preserves real/family records. Destructive reset/become endpoints and the reset service are retired with explicit 410 errors; fresh admin status gates preview. Old HTML demo and frontend switcher integration remain open. No live attachment run. |
| T16 | Draft contract and executable HTTP journey implemented | `docs/contracts/bureaucracy-v2.md` documents the API; structural expectations and real HTTP onboarding/answer/progress/reload and scoped Paperwork checks are implemented. Full typed examples and integrated UI/browser verification remain open. |
| T17 | Source review and live coverage inventory in progress | The read-only manifest names per-unit coverage/review/action gaps and is independently reviewed. Source-supported local core/Blue Card corrections and duplicate-family-registration retirement are implemented with owner-authorised review provenance; no live publication. Current local inventory is 14 partial, one retired/unsupported and 80 review-required units, not a legal-case coverage percentage. Complete criteria/exceptions and remaining modules are open. |
| T18 | Full PHP regression green; release gate open | Latest full PHP verification: 2,147 passed, one skipped, zero failures, 9,417 assertions, 645.09 s. Targeted Pint and diff checks passed; bounded review findings fixed and rechecked. Pre-release cutover runbook exists; remaining source-coverage/consumer/rollout/UI gates are not closed by tests. No release or live activation. |

No commit, merge, live import, push, live model activation or deployment performed.
The separate UI work remains untouched.

### Persona safety and imported journeys — 8 September continuation

- Reproduced deletion of saved task rows and dossier data through the legacy QA
  switcher. Both old mutation routes and the reset service now fail explicitly
  without writes. The replacement is the existing in-memory canonical preview;
  no real account is classified as disposable from an email, admin role, badge or
  environment. This is backend containment, not a completed frontend switcher.
- Reproduced stale admin authorization on the preview (200 after revocation),
  then fixed it with a fresh account check. The local-environment bypass is not
  retained on the retired endpoints.
- Independent read-only review identified an onboarding redirect hiding the
  retirement response. Both unonboarded-admin requests failed with 302 before the
  narrow middleware exception; they now receive 410 under the same auth gates.
- The six historical journeys now exercise real imported catalogue releases,
  actual onboarding, explicit question sessions, pauses/resume, answer writes,
  canonical plans and reload parity. This replaces the former assertion that
  onboarding must persist an inferred global path. Options remain non-actionable
  and partial; a settlement goal does not suppress independent renewal guidance.
  Sponsor citizenship is an attributed report, not a verified fact about another
  person's dossier. No rule wording or approval metadata was changed.
- The Blue Card journeys still explicitly answer `permit_track` because the
  imported branch conditions demand it. A separate regression pins the unresolved
  state before that answer. It does **not** approve the redundant question as a UX
  solution. The content/condition correction remains open in the review ledger.
- Focused pre-format checkpoint: **32 passed, 667 assertions, 21.77 s**, covering
  QA safety/preview, old-client compatibility and the six imported journeys.
  Later skipped-answer/permanent-title checks exposed the employee registration
  audience gap: they remain regressions to resolve, not permission to substitute
  unreviewed `nee.anmeldung`. A wider post-format checkpoint follows separately.

### Full PHP checkpoint after persona and journey changes

Command: explicit PHP 8.4 runtime, isolated manifest and test database, then
`vendor/bin/pest --compact --do-not-cache-result` with JUnit output at
`/private/tmp/expadu-bureaucracy-v2-persona-journeys-full-2026-09-08.xml`.

Observed: **2,052 passed, 42 failed, 8,913 assertions, 544.90 s, exit 2**.
The previous checkpoint was 2,030 passed / 53 failed. Do not call this full run
green or interpret the failure count as that many independently diagnosed bugs.

| Remaining failing file | Checks | Interpretation |
|---|---:|---|
| `BureaucracyEngineTest` | 27 | Old checklist/profile/materialisation contract; inspect each against the canonical model and source gates. |
| `BureaucracyV2Test` | 6 | Legacy checklist generations, not the new `/bureaucracy/v2` API contract. |
| `Bureaucracy/SettledStatusTest` | 1 | Canonical employee/permanent-resident registration preparation is missing from the actual approved audience. |
| `Onboarding/SkippableAnswersTest` | 3 | Same canonical registration gap, with skipped/supplied occupancy and reachable actions. |
| `DeadlineTest` | 1 | Old HTML deadline payload integration. |
| `SettledTransitionTest` | 4 | Old settled bulk-completion/suggestion/info/deadline expectations; preserve meaningful coverage without restoring unsafe behaviour. |
| Total | 42 | Release remains open. |

Pint verification passed for the 14 explicitly changed PHP files; `git diff
--check` passed. The independent QA reviewer confirmed that the onboarding
exception retains authentication, email verification and fresh-admin checks.
No frontend build/browser run, content publication, credential change, old-progress
deletion, commit, push or deployment was performed in this continuation.

Post-format focused verification: **36 passed, 685 assertions, 22.35 s** across
`LegacyPersonaMutationSafetyTest`, `FamilyQaPreviewTest`, `PersonaSwitchTest`,
`QA/PersonaSwitcherTest`, `InvestigatedOnboardingJourneyTest`,
`CanonicalImportedJourneyTest` and `PermanentResidenceSectionTest`. Evidence:
`/private/tmp/expadu-bureaucracy-v2-persona-journeys-focused-2026-09-08.xml`.
This subset does not waive the four canonical registration regressions above.

## Implemented safety behavior

- Publication is rechecked at use time, including warm cached actions and queued
  messages. Source withdrawals, expiration, completion, changed applicability,
  rescheduled appointments and changed dates invalidate old guidance. Content
  hashes detect old wording even if an editor did not bump the rule version.
- Saved progress and alerts are retained. Unsafe saved alert wording is replaced
  with a review-needed projection rather than being treated as current advice.
- Rule dates use confirmed, non-conflicted inputs. Missing entry is not visa-free;
  a branch label does not establish citizenship, business classification or sponsor
  identity. Superseded and uncorroborated old inferred facts remain historical,
  but are not used as confirmed evidence.
- Dates reject impossible values and future historical events. A permanent legal
  title is not made temporary by a physical card's date. Appointments remain
  separate from underlying deadlines. Age alone does not hide overdue work.
- A suppressed notification releases its reservation. Actual mocked WebPush reports,
  rather than merely queueing a job, record successful delivery. Background changes
  and future assessment boundaries are durable, retryable work; live transport has
  not been tested or activated by this work.
- New plans are computed fresh, not stored in another personal-plan cache. Revisions
  include facts, linked dependencies, workflow, evidence, permissions, catalogue,
  questions and the next time boundary. Other stored private Composer/Today copies
  are encrypted and revalidated. The remaining legacy snapshot reader is not yet
  cut over; it is not a fallback for the new plan.
- The current AI action withholds a delayed candidate after changes to consent,
  ownership, case status, facts, question completion or supporting rule relevance.
  This does not grant or activate any live processing.
- Provider parse failures log the exception type, not an echoed response body.
  Error reports redact structured sensitive keys and full Bureaucracy/Composer/
  onboarding request bodies; remove unstructured message/exception/breadcrumb
  text and stack variables; and drop events if sanitisation fails. This is not a
  claim that every application telemetry/transport path has completed T04 review.

## Reproductions and verification

All counts describe the selected run, not cumulative unique coverage.

### Sample isolation, coverage audit and reminder checkpoint — 8 September

- Combined continuity/policy/QA/onboarding/read-model checkpoint: **254 passed,
  1,316 assertions**, 81.57s. XML:
  `/private/tmp/expadu-bureaucracy-v2-continuity-policy-qa-2026-09-08.xml`.
- Subsequent read-only review found historical QA samples constraining real
  effective dates and explicit correction targets promoting samples into real
  answers. Seven new regressions failed before correction. Canonical writes now
  exclude samples from prior/history selection and reject sample correction
  targets; onboarding review agrees with completion. Initial post-fix raw-record
  comparisons needed a database refresh to compare persisted representations,
  not pre-insert Eloquent attributes. No production logic was relaxed for this.
- Added successful person-scoped archival, candidate/conflict preservation,
  idempotent completion replay and direct completion rollback across facts,
  revisions, outbox, drafts and processing candidates/consents. The selected
  synthetic/history/draft/mocked-AI checkpoint passed **61 tests, 229 assertions**,
  32.11s. A failed save cannot leave sample retirement or consent invalidation behind.
- Added `bureaucracy:coverage --manifest`: a read-only, live-gated internal unit
  inventory with named gaps and review actions. It does not claim a percentage of
  all legal cases. The local fixture import currently yields 95 units: 15 partial,
  80 review-required, zero completely covered units. No catalogue text was promoted.
- Independent audit review reproduced concurrent source-change false coverage
  and malformed legacy records aborting the report. Both were corrected with
  per-record hash checks and explicit shape gaps. Covered-only strict success,
  unavailable-action strict failure, source withdrawal/deletion and empty-catalogue
  failure are tested. Catalogue/audit checkpoint: **37 passed, 111 assertions**,
  10.53s.
- Migrated `BureaucracyEvaluatorTest` from old UserTask fixtures to reviewed
  synthetic v2 clocks and explicit appointment events. Removed the old unsafe
  expectation that an appointment cancels an overdue deadline; retained tomorrow,
  today/push, past-appointment and overdue coverage, plus inert legacy progress.
  Reminder checkpoint: **22 passed, 97 assertions**, 25.08s.
- Focused PHP formatting completed. The subsequent full regression run finished:
  **1,946 passed, 88 failed, 7,842 assertions**, 571.38s, exit 2; XML:
  `/private/tmp/expadu-bureaucracy-v2-full-audit-2026-09-08.xml`.
  The failure set included an exact QA-roster expectation for the added
  unknown-section permanent-residence scenario; that expectation was subsequently
  corrected and passed in the focused 57-test catalogue/coverage checkpoint.
  Remaining old page/write
  integration, detailed case/exception matrix, legal catalogue corrections,
  privacy activation and UI integration are not complete.

### Home publication and consumer-test cutover — 8 September

- The compiler now rejects blank complete-coverage review references and goal-only
  conditions, including a goal-only alternative within an otherwise specific rule.
  A user's desired outcome cannot establish that a legal requirement is satisfied.
  Published records without stable keys now appear as explicit audit gaps.
- Corrected the Home test-context helper, which silently ignored its supplied plan.
  This exposed a real defect: a prebuilt Home context replayed withdrawn guidance.
  Fifteen regressions failed across deadline tiles, prompt suggestions and paperwork
  after source withdrawal, same-version edits, changed city, verification or facts.
- Those consumers now obtain a fresh canonical self-plan using current account data.
  They reject a mismatched subject and do not replay snapshot wording. Tests also
  cover closed dossiers, retired people and altered snapshot text. The verification
  test initially tried to mass-assign a guarded field; its fixture was corrected
  without relaxing the production account model.
- Updated notification regressions to reject legacy plaintext claims and exercise a
  real sealed-reference reservation with mocked delivery. Canonical completion, source
  withdrawal and recipient checks remain covered; no live transport was enabled.
- Home ranking, suggestions, overdue visibility, dashboard completion, dismiss/undo
  and badge fixtures now exercise activated synthetic rules and canonical events.
  These are consumer-mechanics fixtures, not approval of actual legal content. Inert
  legacy-progress assertions remain; no old completions or appointments were imported.
- Intermediate checkpoints: **108 passed / 346 assertions** for publication,
  catalogue/audit and alert boundaries; **76 passed / 252 assertions** for publication,
  canonical reminders and push; **29 passed / 76 assertions** for ranking/suggestions;
  **16 passed / 41 assertions** for Home feed; **9 passed / 104 assertions** for
  dashboard and dismiss/undo. Counts overlap and must not be summed.
- Combined post-format checkpoint: **200 passed, 714 assertions**, 74.77s. XML:
  `/private/tmp/expadu-bureaucracy-v2-home-publication-2026-09-08.xml`.
  Independent read-only review of the new Home resolver, its three consumers and
  boundary tests found no additional concrete defects. No database tests were run
  by the reviewer. Focused Pint formatting and `git diff --check` passed.
- At this checkpoint the full-suite result was still **1,946 passed / 88 failed**.
  The later account-entry checkpoint below records the actual new full run; focused
  results must never be subtracted to invent a full-suite count.

### Onboarding contract follow-up — 8 September

- Reproduced seven failures in the old skippability/residence tests. Three still
  required basics that are now expressly skippable, and one expected entry mode
  in the unencrypted profile rather than the canonical dossier. Updated those
  assertions to the accepted contract and added checks against guessed facts.
- The supplied move-in date is confirmed in the encrypted dossier. The legacy
  page assertions remain unchanged: they still expect `nee.anmeldung` to appear
  on `/bureaucracy`. Those three tests remain red rather than bypassing source
  approval or masking the incomplete page/catalogue cutover with a test rewrite.
- Combined skippability/residence/dossier run: **21 passed, 3 failed, 94 assertions**,
  16.74s. This is a deliberately visible integration gap, not a green onboarding
  or end-to-end release claim. No saved progress was transferred or deleted.

### Account entry, timelines and HTTP contract — 8 September 2026

- Added authenticated, verified, self-only `GET /bureaucracy/v2/plan`. It reads the
  current city and source approval on every request, with private/no-store headers.
  Loading it never creates a dossier, offers a question or imports old progress.
  It distinguishes `setup_required` from inactive/erased `record_unavailable`.
- Six initial endpoint regressions failed with 404 before implementation. An added
  partial-setup test exposed an active identity without a dossier being incorrectly
  classified unavailable; fixed without automatic setup writes. Independent bounded
  review found no additional concrete defects. Added explicit inactive/erased-person
  and revoked-verification tests after the review.
- Account entry/plan/family/questions/onboarding checkpoint: **43 passed, 248
  assertions**, 15.54s. Earlier entry/questions check: **13 passed, 84 assertions**.
  A test initially read the session receipt as a nested object; corrected the
  fixture and documented the actual top-level `session_id` contract.
- Updated old reminder tests to use synthetic approved canonical clocks and process
  events. They no longer expect the evaluator to read retained UserTask appointments
  or put private dates into notification text. Withdrawal now asserts an action
  existed before changing the fact, avoiding a vacuous empty-before/empty-after pass.
  Timeline checkpoint: **11 passed, 53 assertions**, 12.04s.
- Deadline compatibility tests now allow read-only canonical fact lookups, preserve
  unknown dates, and assert both dry-run and normal reminder commands leave old
  recurring progress untouched. Entry/lifecycle/deadline check: **26 passed, one
  failed, 138 assertions**, 7.92s. The remaining failure is the unchanged old HTML
  page deadline assertion, not hidden by weakening source approval.
- Added executable structural contract expectations and a real HTTP journey:
  onboarding → invalid date rejection → answer → plan → process start → completion
  → reopen → reload. Exact progress IDs, unchanged document readiness, Paperwork
  parity and future-feature flags are checked. **3 passed, 402 assertions**, 4.09s.
  This does not certify the redesigned UI or every legal case.
- Added the pre-release cutover/recovery runbook. It explicitly names missing
  cohort controls, old page/QA adapters, source approvals and UI checks. No cutover,
  database reset, live import, model activation or deployment was performed.
- New full-suite checkpoint: **2,030 passed, 53 failed, 8,524 assertions**, 509.59s,
  exit 2. XML: `/private/tmp/expadu-bureaucracy-v2-account-contract-full-2026-09-08.xml`.
  Remaining files: `BureaucracyEngineTest` 27; `BureaucracyV2Test` 6;
  `InvestigatedOnboardingJourneyTest` 6; `SettledTransitionTest` 4;
  `SettledStatusTest` 3; `Onboarding/SkippableAnswersTest` 3;
  `PermanentResidenceSectionTest` 2; `DeadlineTest` 1; `QA/PersonaSwitcherTest` 1.
  These are failing checks, not 53 independently diagnosed product bugs.
- Subsequent bounded review requested explicit onboarding-completion and two-task
  evidence-use assertions. Added both. The first two-task HTTP test reused a stale
  proposal after starting another process and correctly received 409; the fixture
  now reloads the plan between mutations. No production guard was relaxed.
  Post-review contract/evidence/entry/timeline subset: **40 passed, 679 assertions**,
  23.70s. The full run above predates these additional assertions.
- Final post-format checkpoint, adding shared reminder delivery/rollback checks:
  **56 passed, 757 assertions**, 36.05s. XML:
  `/private/tmp/expadu-bureaucracy-v2-contract-reminders-2026-09-08.xml`.
  Focused Pint, PHP syntax checks and `git diff --check` passed. No frontend files
  were changed; this remains backend verification only.

### Earlier answer continuity checkpoint — 8 September 2026

- A source-classification boundary reuses genuine old structured answers only
  when their answered question belongs to the same case and fact. It does not
  rewrite the original provenance or convert computed onboarding labels into
  confirmed citizenship, purpose or permit track.
- Explicit same-value reconfirmation now replaces an untrusted assertion without
  retiring separately confirmed dependent answers. Identical duplicate assertions
  no longer produce a false conflict or allow an untrusted copy to hide a trusted
  answer. Different assertions still require review.
- The attachment command is read-only by default, cursor-bounded and retry-safe.
  Its review fingerprint includes question and conflict changes, not only facts.
  Apply was exercised on synthetic isolated records only. Old task/document
  progress is retained unchanged and is not replayed into new processes.
- The existing onboarding adapter records a selected configured Cologne district
  as Cologne; skipped location remains unknown or preserves an existing city.
  Planned arrival stays separate from actual arrival. Validation feedback maps
  back to the existing form field; an HTTP redirect/read test confirms visibility.
- Independent review identified three defects (incomplete attachment fingerprint,
  dependent expiry retirement on reconfirmation, and duplicate assertion order).
  All were reproduced and corrected before the 112-test checkpoint below.

| Run | Result | Evidence |
|---|---|---|
| Continuity, attachment, family/history/question integration | 112 passed, 497 assertions; 42.54 seconds | Focused run after the three review fixes and location correction. |
| Draft, real onboarding adapter and extraction contract | 68 passed, 359 assertions; 21.71 seconds | Includes original onboarding tests updated to the approved all-skippable, encrypted-fact, no-implicit-overwrite contract. |
| Full PHP suite after formatting | 1,871 passed, 92 failed, 7,303 assertions; 574.11 seconds | `/private/tmp/expadu-bureaucracy-v2-full-continuity-2026-09-08.xml`. Remaining failures include legacy consumers/fixtures, old inferred-path and automatic-completion expectations, QA and timeline integration. Each still requires reconciliation; none is waived. |

The larger suite contains more tests than the preceding full checkpoint. Counts
are not a percentage-complete estimate. Old UI/consumer cutover, QA integration,
source corrections and final verification remain open.

### Decision-policy and QA follow-up — 8 September 2026

- Reproduced the compiler dropping `relevance_keys` and `temporal_policy`: 20
  failures and one conservative-default pass. It now retains these settings in
  the immutable artifact. Relevance selectors must be unique actual criterion
  keys and cannot use the goal preference. A declared temporal policy requires
  a version-bound reviewer/date/content reference to an existing reviewed source;
  `legal_due` additionally requires that source to be primary. This is schema
  enforcement, not a new legal review or proof that any particular offset is right.
- Independent review requested stronger uncertainty, OR-alternative and source
  withdrawal coverage. The expanded compiler/assessor/timeline/persona run passed
  **80 tests, 164 assertions, 10.80 seconds**. No real catalogue mapping or approval
  was changed to activate a new deadline policy.
- Added the previously missing `settlement_permit_unknown` QA persona without
  inventing §9, §18c or a former Blue Card track.
- Added an admin-only, read-only `bureaucracy.qa-assessment.1` endpoint. It uses
  the current reviewed catalogue and canonical assessor/question protocol, not
  a second set of QA legal rules. Explicit sample content, no active catalogue,
  outside jurisdiction and unmapped old fixture context remain visible. It does
  not allocate real people, offers, tokens, processes or AI requests. The separate
  HTML preview and mutating persona switcher still require cutover.
- Review tightened the preview tests: assert the complete decision ID set rather
  than a possibly empty loop, exercise outside coverage with an active catalogue,
  and capture request queries to prohibit personal-answer/family table reads.
- Reproduced sample assertions leaking into real onboarding and current conflicts,
  then into conflict choices and historical disputes. Canonical decisions now
  exclude explicit `qa_scenario:` / `qa_persona:` sources. Confirming real
  onboarding retires those sample assertions transactionally while retaining their
  original values and sources. Genuine answers/disputes stay intact; failed
  confirmation rolls sample retirement and revision changes back. No live records
  were modified. Focused history/conflict/onboarding/preview checkpoint:
  **69 passed, 568 assertions, 26.07 seconds** before the final formatting pass.

### Earlier checkpoint table

| Run | Result | Evidence |
|---|---|---|
| Initial exact-date reproduction | 12 failing / 9 passing, then 21 passing | Unknown entry became visa-free; invalid dates were normalised. Additional date/type cases were subsequently added. |
| Earlier wider focused regression set | 385 passed; 49 failures and 12 errors; 1,853 assertions | `/private/tmp/expadu-bureaucracy-v2-focused.xml`; this predates several compatibility/test updates. |
| Safety subset before latest replay fixes | 284 passed, 909 assertions | `/private/tmp/expadu-bureaucracy-v2-safety-verified.xml` |
| Publication, timeline and snapshot checkpoint | 48 passed, 195 assertions | `/private/tmp/expadu-bureaucracy-v2-replay-check.xml` |
| Delayed AI response reproduction | Six failing cases, then 33 passing including existing endpoint/privacy tests | HTTP fakes change consent, ownership, status, facts, answer or rule during the response. |
| Private-error-text reproduction | Structured data and provider log tests failed before fixes; unstructured exception, private request-body and fail-open tests also reproduced the defects | Corrected one test-only stacktrace constructor before reproducing the exception-text failure. |
| Privacy increment | 47 passed, 208 assertions | `/private/tmp/expadu-bureaucracy-v2-privacy-increment.xml` |
| Privacy increment after review fixes | 49 passed, 214 assertions | `/private/tmp/expadu-bureaucracy-v2-privacy-reviewed.xml`; sensitive context-name and object-payload regressions first failed, then passed. Separate event/exception stacks now test both local-variable removal paths. |
| Expanded checkpoint after formatting | 478 passed, 55 failed, 2,087 assertions; 129.74 seconds | `/private/tmp/expadu-bureaucracy-v2-wide-checkpoint.xml`; not release-ready. Includes unit/date/isolation suites, Bureaucracy and onboarding feature suites, legacy engine/deadline/settled/reminder tests, and the privacy/parser tests. |
| Family citizenship dependency | Three failing reproductions before the schema fix; then 38 passed, 278 assertions across seven family/journey files | `/private/tmp/expadu-bureaucracy-v2-family-restored.xml`; explicit sponsor citizenship is now a registered question, not inferred from a path or title. Synthetic personas and journey answers explicitly supply it. No family legal condition was removed. |
| Single-request processing, after review | 163 passed, 625 assertions | `/private/tmp/expadu-bureaucracy-v2-consent-reviewed.xml`; includes manual fallback, all three HTTP clients, malformed/provider-disabled requests, idempotency, strict expiry, quota preservation after legacy-text removal, consent withdrawal, session/error redaction, parser time stability and legacy endpoint compatibility. HTTP is fake throughout. |
| Family, privacy and profile integration | 197 passed, 789 assertions | `/private/tmp/expadu-bureaucracy-v2-family-and-consent.xml`; includes genuine synchronized PostgreSQL acceptance/cancellation and reciprocal acceptance tests, HTTP-only sharing discovery/revocation, guardian self-review rejection, ownership separation, exact expiry, erased-record resurrection guards, rollback-safe erasure and retryable derived-copy cleanup. |
| Questions, drafts, privacy and legacy response compatibility | 50 passed, 222 assertions | `/private/tmp/expadu-bureaucracy-v2-drafts-and-questions.xml`; reviewed retries/defer/resume, encrypted drafts, explicit confirmation, isolated cleanup and no private HTML-input flashes. |
| Existing onboarding canonical-adapter regressions | 11 passed, 38 assertions | All-skipped, omitted citizenship, actual occupancy without registration proof, unknown permanent-title section, preserved history and exact-date error fields. Wider legacy suite still pending. |
| Process events and timeline after two independent reviews | 32 passed, 135 assertions | `/private/tmp/expadu-bureaucracy-v2-process-reviewed.xml`; changed-step review, corrections, expiry/appointment separation, current prerequisites, explicit repeat occurrences, old appointment cancellation and separated history. |
| Evidence core, catalogue and lifecycle | 37 passed, 113 assertions | `/private/tmp/expadu-bureaucracy-v2-evidence-core.xml`; private APIs, owner-scoped sharing, per-application confirmation, withdrawal, expiry and erasure. A later nine-test evidence run also verifies copy-only catalogue replacement preserves readiness while changed meaning invalidates it. |
| Full PHP checkpoint, 8 September | 1,823 passed, 106 failed, 7,053 assertions; 451.36 seconds | `/private/tmp/expadu-bureaucracy-v2-full-checkpoint-2026-09-08.xml`; all PHP tests, after formatting and before the reminder rollback fix below. No failure is waived. |
| Reminder rollback and background refresh | 25 passed, 108 assertions | A real database queue reproduced a lost reminder after rollback. Delivery side effects now wait for the outer commit and re-read the authorised plan. Three new regressions cover inner rollback, outer rollback and queue admission failure. This focused run does not supersede the full-suite failures. |
| Reviewed reminder/refresh, catalogue and privacy checkpoint | 59 passed, 211 assertions; 23.90 seconds | `/private/tmp/expadu-bureaucracy-v2-reminder-retry-reviewed-checkpoint.xml`; includes follow-up reproductions for nested queue failures, acknowledgement ordering and a stale reference retaining its reservation. Pint and `git diff --check` passed before this run. |

Pint ran on the explicit changed/new PHP file list. `git diff --check` passed after
that run. Subsequent changes require another targeted formatting/verification pass.

Guarded integration commands use
`BUREAUCRACY_TEST_MANIFEST=/private/tmp/expadu-bureaucracy-v2-test-manifest.json`
and explicit `DB_DATABASE=expadu_bureaucracy_v2_test`; this overrides PHPUnit's
default test database. Local socket permission is required for these disposable
services. Integration tests run sequentially. HTTP stray requests are rejected;
all provider responses are fake and no live model calls occur.

The expanded checkpoint precedes the last two Sentry review fixes; the reviewed
privacy subset was rerun afterward. No full-suite or expanded-suite green claim
is made from that subset.

## Code-review record

- T14 Composer review reproduced an erasure/save race, disappearance of a recorded
  appointment after changing city, and loss of the saved schedule-conflict warning.
  Each was fixed and regression-tested. Appointments keep their reported location
  and duration; no office, free fee or default meeting duration is invented.
- Fact review reproduced direct-correction conflict bypasses, nonexistent-conflict
  loops caused by identical/expired duplicates, and historical uncertainty lost on
  current-only resolutions and ordinary changes. Both confirmed and unconfirmed
  disputes remain uncertain in the appropriate past period. Relationship mismatches
  now lead to relationship review rather than an empty local-conflict screen.
- Background reassessment records minimal identifiers, never copied family values.
  Source erasure queues surviving recipients before dependency links are deleted.
  Access acceptance/revocation and catalogue activation are transactional events.
  Each successful account-holder refresh schedules its next time boundary. Durable
  per-person catalogue fan-out and lease/retry tests pass. Independent review then
  found that a database queue insert could roll back while its cache reservation
  survived, losing the notification on retry. This was reproduced before the fix:
  record the future boundary transactionally, then perform cache/delivery effects
  after the outer commit against a fresh authorised plan. A queue admission failure
  now propagates to the durable retry worker instead of marking refresh successful;
  private exception text is not copied into the failure record. Follow-up review
  reproduced early acknowledgement when the worker itself ran inside an outer
  transaction, plus a stale-reference suppression path retaining its lease.
  Effects and acknowledgement now run together after the durable claim commits;
  a deferred call truthfully returns false (not completed yet). Rejected stale
  references release only their own reservation. All three follow-up reproductions
  failed before correction, then passed in the 59-test combined checkpoint.
- T14 projection review reproduced omitted description additions, interview pacing
  disagreement, and session/offer expiry windows. Further boundary tests reproduced
  application-local fact timestamp drift, missing sponsor reconfirmation and source
  guardian/share expiry. The shared plan now advertises the earliest relevant boundary.
- T14 reminder review reproduced false delivery accounting with zero subscriptions
  and failed transport reports, duplicate listener accounting, lost delayed-queue
  reservations and a recognisable document-expiry mute token. Actual mocked transport
  reports now determine delivery once; no-subscription/failed sends remain retryable,
  delayed valid jobs can reclaim an unclaimed tier, and mute identifiers are scoped
  keyed digests. Queued references are encrypted; lock-screen and stored alert text
  are generic. Access, source validity, current events, preferences and mutes are
  checked before delivery. Family plan access alone does not grant notification rights.
- After formatting, the combined T13/T14 process/question/evidence/AI/reminder
  checkpoint passed 90 tests and 412 assertions. Evidence:
  `/private/tmp/expadu-bureaucracy-v2-t14-reviewed-checkpoint.xml`. This is not the
  full backend suite. Legacy page/command cutover, Composer appointment integration,
  migration, content review and UI contract verification remain open.

- T13 read-only review found that replaced/restored grants could revive a prior
  suggestion and guardian revocation omitted candidate cleanup. Two regressions
  reproduced both defects. Processing now binds the original authority identity,
  version and scope as well as actor/person/question/input. Replacement and
  revocation invalidate candidates and clear transient values/tokens. Confirmation
  receipts never duplicate a fact and edited values use truthful manual provenance.
- T12 read-only review found that an idempotent evidence retry returned a newer
  version the user had not reviewed, and recipients could not discover first-time
  document shares. Regression tests reproduced both. Retry receipts now return the
  original version; the Paperwork projection discovers only currently authorised,
  version-matched shares for the exact process requirement.

- T07 read-only review found rejected instruction links still present in the artifact,
  action-host withdrawal not applied on warm reads, retired approved records blocking
  recompilation, and newline-tolerant stable IDs. Reproductions failed before fixes;
  one task-ID test was strengthened because a missing map initially masked the regex
  bug. The corrected 31-test catalogue/action/document run passed. Snapshot round-trip
  testing also caught UTC conversion changing calendar review dates; artifacts now
  preserve those dates as exact YYYY-MM-DD values, not instants.

- T06 read-only review found same-type renewal expiry reuse, an undated relationship
  applied before confirmation, delayed relationship retries overwriting newer changes,
  chronology bypass through an unknown start, contradictory historical occupancy
  corrections, and current conflicts hiding undisputed past periods. All six defects
  failed their new regression tests before correction; the 23-test history/API set
  then passed. A separate regression exposed generic permanent residence being treated
  as time-limited in the legacy deadline helper; that title is now explicitly guarded.

- T05 read-only review found that the API did not expose the grant identifier needed
  to revoke sharing, plus a cross-user foreign-key lock cycle. The HTTP regression
  now discovers the grant through the sharing endpoint rather than querying the DB.
  Two synchronized PHP workers reproduced failures for acceptance/cancellation and
  reciprocal acceptance. Non-key actor locks preserve serialization without
  blocking foreign-key checks; both races pass. Cancelling an already accepted
  invitation explicitly directs callers to revoke its grant instead of claiming
  access ended. The strengthened guardian test uses an admin guardian, proving that
  administrative privileges cannot approve one's own authority claim.
- Erasure originally left task notes and a deleted account's label, and a legacy
  GET recreated a question. Focused reproductions failed before each correction.
  Dossier erasure and helper revocation remain distinct. Derived-cache cleanup is
  recorded transactionally and retried with lease ownership; no deleted values or
  exception messages are copied into its durable event. Queued legacy notification
  payload migration and future process/evidence cleanup are still T11–T15 work.

- T04 read-only review identified a withdrawal/admission race, raw text flashing on
  HTML-accepting validation errors, missing Composer withdrawal, and retry parsing
  against a different clock. Reproductions were corrected where overlapping HTTP
  fake patterns and a mutable test clock initially obscured the failure. Each defect
  then failed without its fix and passed with it. Dispatch admission is rechecked
  immediately before transport; withdrawal after admission can withhold results
  but cannot recall an already-sent provider request. The API reports that limitation.
- Single-request results expire after at most 15 minutes; minimal consent audit
  metadata after at most 30 days. Legacy quota-only metadata expires after 24 hours.
  These are product limits, not claims of statutory retention requirements. No new
  raw text is stored, no provider is enabled and confirmed facts are not erased by
  withdrawal of text-processing permission.

- Publication/fact review reproduced and corrected stale relation reads, conflicting
  fact use and fallback branch/identity inference. Reminder review reproduced
  same-version wording replay and a consumed-but-throttled appointment marker.
- The latest read-only privacy review found sensitive context names and object
  metadata bypassing Sentry filtering. Both were reproduced with synthetic
  telemetry, fixed, and included in the 49-test verification above. It also found
  a shared-stack test blind spot, now corrected with distinct event/exception stacks.
- Review findings are evaluated and verified by the orchestrator. No reviewer
  changed files, approved legal content or ran concurrent database tests. This is
  an incremental review, not approval of the unfinished whole backend.

## Known open compatibility and release risks

1. The branch-compiled `sponsor` predicate is now a registered explicit citizenship
   fact. Missing citizenship is asked instead of inferred from a title/path, restoring
   the family journeys in the focused run above. Its legacy name remains transitional:
   T06/T07 must preserve this distinction in the canonical relationship/compiler
   model. Citizenship, current title and status at entry are different facts.
2. Older engine tests intentionally expect all published legacy catalogue entries,
   guessed identity, duration-only eligibility, appointment-as-deadline or bulk
   settled completion. Reconcile each with the approved replacement contract and
   keep its meaningful coverage; do not blanket-approve fixtures to turn tests green.
3. Family access, immutable catalogue activation, subject-specific processing
   consent, process-scoped document readiness and a shared read model now have
   tested core implementations. They are not yet fully connected to legacy account
   data and every consumer. Complete assessable criteria and reviewed coverage
   mappings remain source-review work; the containment layer alone never supplied them.
4. The legacy checklist currently filters unpublished rows before building its gap
   projection. Their progress remains stored, but complete withdrawal visibility
   needs the unified read model. Recurring completion and per-instance deadline
   memoisation also still need replacement by explicit events/revisions.
5. UI integration, source/content/privacy activation, migration rehearsals and
   deployment verification are separate gates. Nothing here establishes coverage
   of every legal situation or constitutes an authority eligibility decision.

### Earlier wider regression failures, by test file

The counts below are an investigation inventory, not waived tests:

- `CaseMatcherTest` 1; `GoodToKnowLaneTest` 1;
  `InvestigatedCaseCorpusTest` 3; `InvestigatedOnboardingJourneyTest` 3;
  `NearMissRulesTest` 2; `PermanentResidenceSectionTest` 1.
- `Onboarding/SkippableAnswersTest` 3; `BureaucracyEngineTest` 27;
  `DeadlineTest` 4; `SettledTransitionTest` 4.
- `ContextEngine/BureaucracyEvaluatorTest` 3;
  `ContextEngine/PermanentResidencyEvaluatorTest` 3.

Each must receive either a verified implementation correction or an explicit test
contract migration with equivalent meaningful coverage. None is resolved merely by
being listed here.

### Earlier full PHP checkpoint failures — 8 September (106-failure historical run)

These are failing checks, not 106 independently diagnosed product bugs. This run
includes the earlier containment contracts and their newer replacements. Determine
the cause of every failure before changing an assertion; retain equivalent coverage
when replacing a superseded contract. Do not restore inferred identity, unapproved
guidance, duration-only eligibility or silent task/document completion to pass tests.

| Area | Failing checks | Test files |
|---|---:|---|
| Legacy catalogue/task engine | 33 | `BureaucracyEngineTest` 27; `BureaucracyV2Test` 6 |
| Onboarding and investigated journeys | 28 | `OnboardingTest` 13; `InvestigatedOnboardingJourneyTest` 6; `PermanentResidenceSectionTest` 2; `ResidenceStatusQuestionTest` 1; `SkippableAnswersTest` 6 |
| Publication and timeline safety contracts | 13 | `GuidancePublicationBoundaryTest` 7; `TimelineSafetyTest` 6 |
| Alerts and context notifications | 9 | `RecordContextAlertTest` 2; `BureaucracyEvaluatorTest` 3; `PermanentResidencyEvaluatorTest` 3; `PushDispatcherTest` 1 |
| Home, tiles and badge | 8 | `DiscoveryFeedTest` 1; `Home/HomeFeedTest` 2; `TileComposerTest` 2; `TileTriageTest` 1; root `HomeFeedTest` 1; `BureaucracyBadgeTest` 1 |
| Legacy deadline/settled transitions | 11 | `DeadlineTest` 4; `SettledTransitionTest` 4; `SettledStatusTest` 3 |
| QA persona integration | 3 | `PersonaIntegrityTest` 1; `PersonaOnboardingOverrideTest` 1; `PersonaSwitcherTest` 1 |
| Extraction request shape | 1 | `CaseFactExtractionContractTest` 1 |
| Total | 106 | Full checkpoint evidence above |
