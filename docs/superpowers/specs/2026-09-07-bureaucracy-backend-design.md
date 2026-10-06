# Bureaucracy backend architecture — accepted direction

Accepted for implementation on 7 September 2026 by the user's request to prepare the plan and start building. Separate family plans are a first-release requirement. The investigation below remains an evidence snapshot; its statements that no implementation had started refer to the audit date, not subsequent work. Live deployment, content approval and adult/minor sharing-policy activation remain separate decisions.

**Execution scope:** The [complete backend implementation plan](/Users/anar/Projects/Own/Startups/expadu-app/docs/superpowers/plans/2026-09-07-bureaucracy-complete-solution.md) supersedes the earlier foundation-only plan. It maps the known defects to tasks and release tests, specifies the full family/facts/process/question/document/AI contracts, and includes migration, UI integration and rollout gates. Its detailed contracts refine this architecture proposal; privacy and legal-content activation still require their explicit reviews.

# Expadu Bureaucracy: backend architecture recommendation

7 September 2026 · Investigation and proposal · No application changes

## The decision in plain English

**Build one shared, family-aware decision system inside the existing app. Organise it around people and the things they need to get done—not one permanent “type of expat”.**

Onboarding, Bureaucracy, Paperwork, Today and Composer should use that same system. A person’s confirmed answer should have the same meaning everywhere. Their renewal, move, and longer-term options should be evaluated separately, without asking them to choose one identity that excludes the others.

The foundations are useful. We should retain the fact registry, encrypted facts, confirmation and conflict handling, deterministic condition evaluation, source-review gates and regression tests. We should replace the competing decision paths and the data structures that confuse people, processes, deadlines and progress.

This is a structural correction, not a promise that a new algorithm will supply missing legal knowledge. **Expadu should be able to represent unfamiliar situations without forcing a wrong answer; it may only give process guidance where reviewed rules support it.** Coverage must grow through a controlled review process.

Your latest decision—**separate plans for family members in the first version**—is included throughout. It cannot safely be added as just another persona switcher.

### What this should feel like

- “Expadu remembers what I already told it.”
- “It is clear whether this concerns me, my spouse or my child.”
- “I can see useful next steps even if another part of my situation is unclear.”
- “It asks a short question only when my answer will change something useful.”
- “It distinguishes what I should do from what I am waiting for.”
- “If it cannot assess something, it tells me exactly which part—not that my whole life is unsupported.”

These are design objectives. They still need usability testing; source review alone cannot prove an intuitive experience.

## 1. What I investigated

I read the [5 September product design audit](/private/tmp/expadu-design-review-2026-09-05/report.md), traced the current working checkout across onboarding, profiles, canonical facts, matching, questions, plan composition, deadlines, documents, AI and downstream consumers, and inspected the catalogue and existing tests. I researched official Cologne processes, federal sources, government-service design guidance and privacy principles.

The earlier audit observed nine information-needed cards before the checklist, mismatched progress language, and a tax-ID waiting task given a “Book appointment” action. Those are historical staging observations, not newly repeated live tests. The current source still contains the generic appointment mapping. [Audit findings](/private/tmp/expadu-design-review-2026-09-05/report.md:24), [Today action mapping](/Users/anar/Projects/Own/Startups/expadu-app/resources/js/pages/dashboard.tsx:198).

I also ran isolated, synthetic PHP probes against current classes without booting the application or connecting to a database or network. They reproduced unsafe defaults and deadline handling described below.

**Evidence limits:** The checkout contains concurrent work. This review does not establish the deployed revision, current staging/production database content, live AI configuration or live legal coverage. I did not run migrations, imports or the full application test suite. The test setup includes database refresh and Redis cleanup, so it should first be isolated from the user’s active local services. No account, application source, rule, UI or deployment was changed.

## 2. Why the current system keeps producing confusing results

### A. The app has more than one answer to “what applies?” — high priority

The Bureaucracy controller assembles a profile-based checklist and a canonical-fact case plan in the same response. `PathGenerator` reads published tasks; `CaseMatcher` uses the stronger authoritative-source gates. Today also consumes the legacy task path. Thus, fixing the case plan does not necessarily fix every recommendation elsewhere. [Controller](/Users/anar/Projects/Own/Startups/expadu-app/app/Http/Controllers/BureaucracyController.php:48), [legacy publication filter](/Users/anar/Projects/Own/Startups/expadu-app/app/Bureaucracy/PathGenerator.php:119), [verified matcher](/Users/anar/Projects/Own/Startups/expadu-app/app/Bureaucracy/Cases/CaseMatcher.php:113), [home feed](/Users/anar/Projects/Own/Startups/expadu-app/app/Home/HomeFeed.php:285).

**Consequence:** Different screens can work from different facts and trust levels. The UI cannot solve that disagreement.

### B. Unknown answers sometimes become assumptions — high priority

The profile engine turns unknown citizenship into non-EU. Missing business classification becomes a liberal profession unless a specific branch says otherwise. The permit-window calculation treats missing entry mode as visa-free. These are explicit implementation defaults, not conclusions the user confirmed. [Profile mapping](/Users/anar/Projects/Own/Startups/expadu-app/app/Profile/ProfileEngine.php:177), [citizenship fallback](/Users/anar/Projects/Own/Startups/expadu-app/app/Profile/ProfileEngine.php:200), [permit deadline fallback](/Users/anar/Projects/Own/Startups/expadu-app/app/Models/Task.php:198).

The synthetic probe reproduced unknown citizenship becoming `non_eu` and a missing entry answer producing an arrival-based deadline.

**Consequence:** Making onboarding skippable is unsafe while downstream logic silently fills in the skipped facts.

### C. A booked appointment can replace the underlying deadline — high priority

`UserTask.absolute_deadline` returns the appointment date instead of the task’s calculated deadline whenever an appointment exists. A separate generic rule downgrades sufficiently old overdue tasks to “lapsed”. [Deadline override](/Users/anar/Projects/Own/Startups/expadu-app/app/Models/UserTask.php:61), [overdue policy](/Users/anar/Projects/Own/Startups/expadu-app/app/Models/UserTask.php:109).

In synthetic inputs, the missing-entry calculation returned 30 October, the same task with an explicit D-visa expiry returned 10 September, and a later appointment made the user-task deadline return 15 October. These are demonstrations of current software behavior, **not legal deadlines for an actual person**.

**Consequence:** Appointment reminders, expiry risks and submission deadlines must be separate fields, not competing values in one date.

### D. “Settled here” and “holds permanent residence” are mixed together — high priority

The bulk “I’m settled” action marks arrival tasks done and writes `settled_at`. The phase presenter interprets that field as holding permanent residency. There is also a separate duration-based permanent-residency hint outside the reviewed case-rule path. [Settled action](/Users/anar/Projects/Own/Startups/expadu-app/app/Http/Controllers/BureaucracyController.php:189), [residence claim](/Users/anar/Projects/Own/Startups/expadu-app/app/Http/Controllers/BureaucracyController.php:233), [separate eligibility service](/Users/anar/Projects/Own/Startups/expadu-app/app/Bureaucracy/PermanentResidencyEligibility.php:27).

The hint’s absolute month difference also interprets a future permit-start date as elapsed time. A synthetic future date reproduced that service-level defect. Existing profile-input validation blocks future dates on that route, so this is **not evidence that the normal UI currently accepts that input**.

**Consequence:** Lifestyle stage, legal title, task completion and an authority’s decision must never stand in for one another.

### E. Questions and usable guidance are not aligned — high priority

The main question selector uses approved rules. Its fallback scans published rules, including unapproved ones. The structured-answer endpoint accepts both, but the AI extraction endpoint recognises only the main selector’s current question. That can make a valid fallback question unacceptable to the AI path. [Fallback](/Users/anar/Projects/Own/Startups/expadu-app/app/Bureaucracy/Cases/PendingAnswers.php:43), [structured answer guard](/Users/anar/Projects/Own/Startups/expadu-app/app/Bureaucracy/Cases/AnswerCaseQuestion.php:74), [AI guard](/Users/anar/Projects/Own/Startups/expadu-app/app/Bureaucracy/Ai/ExtractCaseFactAction.php:44).

The selector also counts all questions ever created for the case against a twelve-question ceiling. Opening the current plan can create a question record. Neither is a suitable long-term model for repeated moves, renewals and family changes. [Lifetime count](/Users/anar/Projects/Own/Startups/expadu-app/app/Bureaucracy/Cases/QuestionSelector.php:141), [read-path question creation](/Users/anar/Projects/Own/Startups/expadu-app/app/Bureaucracy/Cases/CurrentCasePlan.php:25).

**Consequence:** Users may answer questions without receiving reviewed help, or eventually run out of questions despite a changed situation. Simply removing the fallback would recreate the earlier missing-question problem; it needs a replacement question protocol.

### F. Coverage is too coarse, and presentation affects meaning — high priority

The matcher has one overall coverage state: any unknown case rule can make it “needs information”; a match can make it “matched”. This does not establish whether each of the person’s needs is covered. Unknown universal rules are skipped when collecting questions. [Matcher](/Users/anar/Projects/Own/Startups/expadu-app/app/Bureaucracy/Cases/CaseMatcher.php:34).

The composer maps a task’s phase/type to sections such as “do now” and “coming up”. Submitted tasks and tasks blocked by prerequisites both become “waiting”. It does not use a complete process-state model to make those distinctions. [Section mapping](/Users/anar/Projects/Own/Startups/expadu-app/app/Bureaucracy/Cases/CasePlanComposer.php:191).

**Consequence:** One missing fact can make the whole page look blocked; an option’s position can imply timing or eligibility that has not actually been established.

### G. The schema currently represents one case per account, not a family

The case table makes `user_id` unique. Fact lookups and user-task ownership follow that account. [Migration](/Users/anar/Projects/Own/Startups/expadu-app/database/migrations/2026_08_03_210000_create_bureaucracy_cases_table.php:14).

**Consequence:** Supporting your new family requirement needs explicit person identity, relationships and permissions. Reusing QA persona switching would overwrite the wrong conceptual state rather than create independent family plans.

### H. Documents, snapshots and AI need stronger shared boundaries

- Conditional documents with an unknown condition are retained, but the condition is stripped from the case-plan response. This is a latent ambiguity in the runtime; the current catalogue does not use nested document `applies_if`. Requirements must carry their applicability state instead. [Document projection](/Users/anar/Projects/Own/Startups/expadu-app/app/Bureaucracy/Cases/CasePlanComposer.php:255).
- Snapshot reuse covers fact version and selected task/rule state, but not every legacy profile value or task field that can affect the presentation. All decision inputs need explicit versioning. [Snapshot signature](/Users/anar/Projects/Own/Startups/expadu-app/app/Bureaucracy/Cases/PlanSnapshotStore.php:80).
- The bureaucracy AI flow has consent, strict extraction and confirmation. However, Composer has a separate optional external parser without the same consent check. This is a code-path risk if enabled, not evidence that live calls are occurring. [Composer parser entry](/Users/anar/Projects/Own/Startups/expadu-app/app/Http/Controllers/ComposerController.php:106), [external request](/Users/anar/Projects/Own/Startups/expadu-app/app/Composer/OpenAiCompatiblePromptParser.php:56).
- AI consent wording asks to interpret “one answer”, while the stored consent remains active for the case until withdrawal. AI-confirmed values are recorded through the structured-interview path, losing a clear distinction in provenance. [Consent wording](/Users/anar/Projects/Own/Startups/expadu-app/resources/js/components/bureaucracy/ai-consent-sheet.tsx:84), [stored consent](/Users/anar/Projects/Own/Startups/expadu-app/app/Models/BureaucracyCase.php:81), [fact provenance](/Users/anar/Projects/Own/Startups/expadu-app/app/Bureaucracy/Cases/AnswerCaseQuestion.php:89).

### Catalogue reality

An independently reproduced, read-only inventory of the 16 top-level YAML files found:

| Item | Current authored catalogue |
|---|---:|
| Total records | 95 |
| Marked approved | 15 |
| Legacy/default review status | 80 |
| Tasks / information / waiting / decision records | 64 / 23 / 6 / 2 |
| Case-scoped / universal-scoped records | 82 / 13 |

No record explicitly sets `is_published`; the importer defaults it to true. Approved records are concentrated in core, family-reunification and Blue Card content. These are **authored-record counts, not a percentage of legal cases covered**, nor proof of the current database’s authoritative set. [Import defaults](/Users/anar/Projects/Own/Startups/expadu-app/app/Console/Commands/Bureaucracy/ImportTasksCommand.php:513).

Two further findings matter for maintenance:

- Repeated concepts are authored across branches. Seven exact-title groups cover 23 records, including five Anmeldung records. That does **not** prove all duplicates appear together for a user.
- One approved Blue Card tracking record accepts 12–20 qualifying months but its fixed description says 12. This is an internal predicate/text mismatch independent of its legal merits. [Record](/Users/anar/Projects/Own/Startups/expadu-app/database/seeders/data/bureaucracy/non_eu_employee_blue_card.yaml:183).

The importer validates useful structural and approval constraints, but upserts records individually. Its prune path can delete related user progress. A restructure needs versioned publication and explicit migration mappings, not “rename the YAML keys and reimport”. [Import loop](/Users/anar/Projects/Own/Startups/expadu-app/app/Console/Commands/Bureaucracy/ImportTasksCommand.php:110), [pruning](/Users/anar/Projects/Own/Startups/expadu-app/app/Console/Commands/Bureaucracy/ImportTasksCommand.php:140).

## 3. What Cologne and wider service research tell us

The following are research observations and architectural implications, not new approved app content or individual legal advice. Official sources were accessed on 7 September 2026. Accessing an official page does not itself approve every claim on it.

| Official evidence | Implication for Expadu |
|---|---|
| Cologne’s Blue Card service distinguishes first applications from renewals, varies required documents, and describes an application/review/invitation sequence. [Cologne Blue Card service](https://www.stadt-koeln.de/service/produkte/20321/index.html) | Model process stage and channel explicitly. “Book appointment” cannot be the universal action. |
| Federal residence law makes application timing and circumstances material; an appointment is not a substitute for recording an application. [Residence Act, §81 within the consolidated act](https://www.gesetze-im-internet.de/aufenthg_2004/BJNR195010004.html) | Keep title expiry, submission, confirmation and appointment separate. Do not infer continuing status from a calendar booking. |
| Cologne’s registration service distinguishes actual move-in and accommodation-provider evidence; federal registration law also contains exceptions. [Cologne registration](https://www.stadt-koeln.de/service/produkte/00415/index.html), [BMG §17](https://www.gesetze-im-internet.de/bmg/__17.html), [BMG including §27](https://www.gesetze-im-internet.de/bmg/BJNR108410013.html) | Record actual occupancy separately from missing paperwork or the user’s belief that an address is “registrable”. Preserve exceptions in reviewed rules. |
| Cologne has different family services and several permanent-residence process categories. [Family reunification](https://www.stadt-koeln.de/leben-in-koeln/soziales/auslaenderamt/familienzusammenfuehrung), [permanent residence](https://www.stadt-koeln.de/leben-in-koeln/soziales/auslaenderamt/niederlassungserlaubnis) | A single employment/family label cannot represent all relevant relationships and processes. |
| Cologne’s residence FAQ distinguishes application handling and passport-related matters. [Residence FAQ](https://www.stadt-koeln.de/artikel/73721/index.html) | Store the legal status separately from the physical card/passport and its replacement process. |
| GOV.UK recommends knowing why each question is needed, who needs to answer it, how it is checked and how it stays current. [Form structure](https://www.gov.uk/service-manual/design/form-structure) | Maintain a reviewed question protocol, not a growing inventory of fields displayed because they exist. |
| Government-service patterns support reviewing answers and completing longer processes over multiple sessions. [Check answers](https://design-system.service.gov.uk/patterns/check-answers/), [multiple tasks](https://design-system.service.gov.uk/patterns/complete-multiple-tasks/) | Make progress resumable and editable; separate questions from the user’s ongoing work. |

The privacy design should follow purpose limitation, minimisation, accuracy, retention limits and confidentiality. Household sharing and AI processing need a documented lawful basis and scope; this report is not a legal sign-off for those arrangements. [EDPB principles](https://www.edpb.europa.eu/topics/key-gdpr-concepts/basic-principles_en), [EDPB lawful processing guidance](https://www.edpb.europa.eu/sme/be-compliant/process-personal-data-lawfully_en).

The useful global lesson is **service design around a person’s goal and progress**. It does not mean importing another country’s legal rules or copying its page layout.

## 4. Recommended architecture

### Choice: a modular monolith, not a new platform

Keep Laravel and the current database. Build a single Bureaucracy decision service with clear internal boundaries. Relational tables and deterministic rule evaluation are enough; a graph database, microservices, external workflow platform or autonomous legal agent are not necessary for this scope.

| Approach | Trade-off | Decision |
|---|---|---|
| Keep patching both existing engines | Small individual changes, but duplicated semantics, gates and migrations remain | Not the target architecture |
| Consolidate into one person/process decision service | Requires deliberate migration, but reuses the strongest current components and supports family plans | **Recommended** |
| Replace the entire rules/workflow platform | Potential tooling benefits, but adds migration and operational work without supplying missing reviewed content | Reconsider only if demonstrated authoring/scale needs justify it |

### 4.1 People first; the account only controls access

Use a household workspace containing separate person profiles. A person can have an optional login; a spouse or child need not automatically receive a separate account just to have a plan.

Each person owns their facts, processes, deadlines and document references. Relationships connect people; they do not merge them. Adults’ delegated access and children’s guardian access require explicit, separately designed permissions. Merely creating a household does not establish permission to read or externally process every member’s information.

Some facts can genuinely be shared, such as a particular tenancy. Share that specific object with explicit affected members. Do not assume the whole family has the same address, arrival date, nationality or permit expiry.

**Important for your family example:** your earlier Blue Card and your current title are time-specific facts about you. Your spouse’s own title and expiry remain hers. A sponsor-status change can trigger reassessment of her related processes; it cannot overwrite her residence status or automatically establish eligibility.

### 4.2 One canonical fact record, with history

Every fact should identify:

- Who or what it concerns: person, relationship or explicitly shared object.
- The value and whether it is confirmed, proposed, superseded or disputed.
- When it was true, when it was recorded and whether reconfirmation is needed.
- Who supplied/confirmed it and through which input method.
- Any supporting evidence reference and relevant access restrictions.

Distinguish **unknown, skipped, does not know, not applicable and false**. Skipping a question is an interaction event, not a negative answer. A later real-world change is not automatically a contradiction: a past Blue Card and a current permanent title can both be true at different times. Conflicts concern incompatible claims for the same subject and time.

Keep these concepts separate:

| Do not combine | Why |
|---|---|
| Planning a move / arrival event / start of relevant residence | Different processes use different dates; null cannot mean all three |
| Current title / historical entry method / desired title | Wanting a Blue Card does not mean holding one |
| Legal status / card expiry / passport expiry | Document replacement is not necessarily renewal of the underlying status |
| User’s title / sponsor’s title | They belong to different people |
| Feeling settled / tasks handled / legal permanent residence | None proves the others |
| Elapsed time / legally qualifying periods | Reviewed rules must define which periods count and what evidence matters |

Prefer observable questions over asking users to adjudicate concepts such as “secured livelihood” or calculate “qualifying months” without help. Approved rules may derive those concepts from suitable facts and evidence; uncertain judgments remain unresolved for authority/manual verification.

### 4.3 Multiple processes per person, with repeatable instances

Introduce a process instance: a particular renewal, a particular move, a first application, or an optional longer-term assessment. A person can have several at once. A later renewal creates a new instance rather than inheriting “done” from the previous one.

The user’s stated goal should prioritise the plan, **not suppress unrelated obligations or relevant reviewed options**. Changes in confirmed circumstances can open or reassess other supported processes.

Shared concepts such as registration should have one reusable definition, with reviewed conditions and procedural variants. “One definition” does **not** mean “universal legal applicability”. Consolidating repeated text must retain the audience boundaries, document variants, sources and approval requirements.

### 4.4 Separate four decisions that are currently mixed

For each process, evaluate these independently:

1. **Relevance:** Could this process concern this person now or in the future?
2. **Coverage:** Does Expadu have reviewed guidance for this process and stage, and what is missing?
3. **Requirements:** Which reviewed criteria are supported, unmet, unknown or require authority assessment?
4. **Progress and next action:** What has the person done, what blocks the next step, and whose turn is it?

A matching preparation rule is not a full eligibility assessment. A user saying “submitted” is not an authority approving an application. An unknown optional route should not suppress a known renewal task.

Use explicit gap reasons, such as missing user information, unsupported circumstance, outdated source, conflicting facts or authority discretion. Keep coverage **per process/stage**, with a household summary derived from those results—not one global “matched” badge.

### 4.5 One question planner, used by every input method

Questions come from two reviewed sources:

- A small **orientation protocol** needed to establish person, jurisdiction, life stage and relevant processes.
- Missing criteria in an **available approved process module**.

This replaces the current approved-rule selector plus unapproved-catalogue fallback. It retains necessary orientation without collecting sensitive details merely because an unpublished legal route might use them someday.

Every question definition must state its subject, purpose, permitted answers, validation, skip behavior, sensitivity, reconfirmation policy and what decision its answer can change.

Recommended priority order: a known time-sensitive issue, the user’s active process, a prerequisite for useful action, then relevant optional exploration. Within a priority group, prefer the least burdensome question that resolves a meaningful uncertainty. Do not rank solely by how many rule rows mention a field.

Present one focused question or a small related group. Explain its purpose briefly, reuse valid answers, allow skipping, and stop when more answers cannot unlock supported guidance. A session’s attention budget must not become a lifetime ceiling. API abuse limits are a separate concern.

Use the same current question identifier and assessment version for structured and AI-assisted answers. Reopening a page must not consume new question attempts. Handle stale answers by reassessing safely, not issuing an unexplained rejection.

### 4.6 A timeline and evidence model that preserve meaning

A process may contain several distinct dates: legal deadline, recommended preparation date, appointment, submission timestamp, follow-up date, document expiry and review/reconfirmation date. Each needs its own type, source/basis and confidence or uncertainty state.

Never let an appointment replace an obligation’s deadline. Never treat missing dates as “no deadline”. Do not silently remove an unresolved risk merely because it has become old; overdue handling must be process-specific and reviewed.

Documents need two layers:

- **Evidence item:** whose document it is, type, validity and optional securely stored file.
- **Requirement/use:** why this process needs it, conditions, whether the user has prepared it, and whether further verification is required.

Reusing a document can reduce work, but readiness is checked for the particular use. Completing one task must not verify a document for every family member or process. No file upload is required merely to support a basic preparation checklist.

This also provides a safe foundation for the future Paperwork tools: translation drafts, correspondence and tax preparation can reference permissioned evidence. They remain future capabilities, not implied implementation or certified translation/tax advice. External processing and sending messages require their own authorization and review boundaries.

### 4.7 One assessment result for all pages

The core evaluator should take an authorised fact/workflow snapshot, the applicable policy release, jurisdiction and an explicit clock. It returns a deterministic result; it does not make external calls or silently change facts during evaluation.

Onboarding and Bureaucracy write through the same fact commands. After a confirmed change, the system reassesses the affected processes, records an explainable assessment, and gives all consumers the same version. Relationship changes also reassess permitted dependent processes.

Today, Composer, Paperwork and notifications consume limited projections of that result. They must not calculate eligibility or choose legal actions independently. Composer’s leisure-planning engine remains separate; it consumes permissioned commitments and routes bureaucracy requests to this service.

**Proposed shared contract, not an implemented API:**

| Contract field | Purpose |
|---|---|
| Person and process IDs | Whom the item concerns; which repeatable process it belongs to |
| Assessment/version and evaluated time | Detect stale data and continue the same plan across pages |
| Coverage and requirement states | State the precise limit of what is known |
| Next action and blocked/waiting reason | Distinguish prepare, submit online, attend, wait, answer or verify |
| Typed dates | Separate risk, preparation, appointments and submissions |
| Evidence requirements and readiness | Preserve person/process-specific meaning |
| Reviewed explanation and source references | Explain why the action appears and its limitations |
| Optional next question | Ask the same relevant question from any entry point |
| What changed / reassess-at | Explain updates and trigger time-based checks |

Legal wording comes from approved content/templates; the frontend may group these results into the new design. It must not invent an action from a card’s visual category. The product audit’s “Book appointment” mismatch is exactly what this contract prevents.

Cache only scalar projections. Include fact, relationship, workflow, evidence, policy and relevant temporal versions in invalidation. Maintain a dependency index so a change affects the necessary processes rather than resetting the household. Prevent out-of-order jobs from publishing an older assessment over a newer one. Reads may reuse a current assessment, but must not present expired guidance as current.

## 5. AI’s role: interpretation, not decision-making

Keep the existing strict extractor boundary, but make the privacy and confirmation rules consistent across all entry points.

**The flow:** permission and processing consent → narrowly scoped text interpretation → structured candidate facts → user checks person/value/date → deterministic assessment → approved next steps or an explicit gap.

AI may extract candidate facts and suggested user intent from text. It may help distinguish “this happened to my spouse” from “this happened to me”, but uncertain subject identity must be confirmed—not guessed. A model response must never create a family member, change a legal title, mark an application submitted or choose an official route on its own.

The backend selects approved follow-up questions. A missing legal rule does not trigger an open-ended AI attempt to invent guidance. Research/gap notes for human review must stay outside the published catalogue.

Required controls:

- Consent before any external text processing, including generic Composer input that may contain bureaucracy information. Classification itself can transmit sensitive text; it cannot be used to postpone the consent check.
- Explicit processing purpose, provider/disclosure version, subject permissions and withdrawal behavior. Do not describe persistent consent as permission for only one answer.
- Schema validation, permitted fact keys and values, no free-form legal output, and no access to another member’s unnecessary records.
- Confirmation with preserved provenance: model proposal, source passage, confirming actor and resulting fact version. Valid JSON is not evidence of semantic accuracy.
- Rate limits, bounded retention, encryption, safe logging, deletion/withdrawal tests and a provider failure path that leaves the structured workflow usable.
- Recheck authorization and current assessment/consent before accepting delayed results. Treat uploaded/text instructions as data, never as system instructions.

Do not enable live model calls as part of this architecture work. The current one-fact extractor is a useful starting point; broader narrative extraction is a separate tested increment after the person and rule models are reliable.

## 6. How onboarding and the day-to-day flow work

### Onboarding

Honour the agreed direction: basics plus current residence status and relevant expiry, all skippable. Skipping means an honest partial profile, not a fallback legal identity. A default city for browsing is not a confirmed jurisdiction or residential address.

Ask about planning versus being here before address details. Do not require an actual neighbourhood or move-in date from someone who has not chosen a home. Distinguish “not arrived”, “not answered” and actual arrival; preserve drafts and confirmed answers across sessions.

Current title and expiry can be useful early. Historical entry details should be asked when a reviewed process needs them, not of every long-term resident. Family profiles can be added progressively without making the whole household complete onboarding together.

### Bureaucracy

1. Load the permitted people and existing confirmed answers.
2. Assess their relevant processes, including time-sensitive needs beyond the currently selected goal.
3. Return supported actions immediately, plus explicit limits for the incomplete processes.
4. Offer the most useful missing question where it can be seen. No permanent interview panel is required.
5. On an answer or life change, update affected processes and explain meaningful changes.
6. On return, resume the same work. A new renewal/move creates a new occurrence; previous completion stays in history.

The UI team can use this contract to create a short overview, topic details and Paperwork without changing the underlying meaning. Layout work need not wait until every legal route is authored, but production UI must not manufacture semantics the backend does not supply.

## 7. Covering many situations without writing a persona for every combination

Treat personas as examples for testing, not as the rules architecture. Maintain a capability register for each jurisdiction and process, listing supported circumstances, required facts, reviewed actions, known exclusions, missing content and the verified handoff when assessment is unavailable.

At minimum, the register should expose planning/arrival/address processes, current residence administration, first applications and renewals, family relationships and children, employment/study/self-employment changes, document replacement, and longer-term options. These are coverage headings to audit—not a claim that all are currently implemented or approved.

Model exceptions and interacting conditions explicitly. A new reviewed rule should reuse existing facts, evidence types and process steps when their meaning is genuinely the same. It should not require another end-to-end “persona branch”. A rule about an option must declare how complete its requirement assessment is; matching one preparatory rule must never produce “eligible”.

### Content operations are part of the solution

- Separate federal legal criteria from state/local implementation details and submission channels. Jurisdiction follows the process and responsible authority, not a hardcoded city string. Planning from abroad may require an official overseas authority handoff rather than a Cologne procedure.
- Give criteria, actions, evidence requirements and content stable identifiers and source links. Figures need provenance and effective dates, not only substituted text.
- Compile and validate a complete policy release before activating it atomically. Validate nested conditions, dependencies, references, overlap/conflict declarations and coverage metadata—not only top-level predicates.
- Preserve `Task::authoritative()` and `RuleSourcePolicy` during transition. The successor publication layer must retain or strengthen every check; no temporary direct-query bypass.
- Require human review of changed legal meaning or audience. Reorganising existing prose is not automatically safe if it broadens who receives it.
- Track source changes and review expiry. Flag content for review; never auto-approve changed law with AI. Keep old assessments as labelled history while preventing invalid guidance from being offered as current.
- Record gaps with minimal personal data and an owner. A content-review backlog is a separate workstream from software defects.

This makes broad coverage maintainable. It still requires an accountable content reviewer and continued official-source work; no architecture can eliminate that responsibility.

## 8. Implementation order I recommend

No implementation has been started by this report.

### Phase 1 — Establish the safety contract and family identity

Freeze reproducible regression examples for the split engines, unknown defaults, date distinctions, misleading status claims and AI question/consent inconsistencies. Define person ownership, adult delegation and guardian permissions before introducing shared sensitive records. Establish isolated tests and a per-process capability inventory.

Apply narrowly scoped safety corrections once authorised. Do not bulk-approve legacy rules to make screens look complete. If a process cannot safely be presented, retain an explicit gap and reviewed handoff, not silently disappearing functionality.

**Exit condition:** skipped information cannot create a legal assumption; appointment and obligation dates cannot overwrite one another; sensitive operations have an explicit person/actor boundary.

### Phase 2 — Create the canonical person/process model and shared result

Introduce person profiles, relationships, process occurrences and versioned assessments. Migrate current account facts to that account holder only. Preserve provenance and historical task/document progress. Ambiguous old `settled_at` or derived branch values require a migration reason or reconfirmation, not fabricated legal facts.

Define stable mappings from existing rule/task keys to new process/action identifiers. Never use destructive catalogue pruning to perform this migration.

**Exit condition:** equivalent confirmed inputs produce one explainable result, with independent family-member state and safe cross-member dependencies. The UI team receives this contract and fixtures.

### Phase 3 — Move approved guidance and questions into process modules

Consolidate reusable concepts with explicit conditional variants. Start with registration and residence first-application/renewal lifecycles because they exercise shared paperwork, jurisdiction, timing and family relationships. Include planning and long-term-resident examples from the start; this is not a single-persona pilot.

Replace the question fallback with the reviewed orientation protocol plus module requirements. Fill content gaps as a separately reviewed stream. Do not expose an option as fully assessed if only a preparation fragment exists.

**Exit condition:** each question has a valid decision purpose, every active recommendation has an approved basis, and incomplete coverage is clear per process.

### Phase 4 — Switch every consumer coherently

Compare old and new results in a non-user-facing shadow evaluation using synthetic or appropriately protected data. Classify differences against approved expectations; agreement with old behavior is not automatically correctness.

Use a feature-controlled cutover by process/household cohort. For any migrated process, Bureaucracy, Today, Composer, Paperwork and notifications must all use the new result. Do not let them fall back to different engines for that same process.

**Exit condition:** the same process has the same meaning and action everywhere; rollback preserves new facts and progress without restoring unsafe guidance.

### Phase 5 — Extend bounded AI assistance

Unify the AI gateway across entry points, then expand beyond one-fact extraction only after privacy, person attribution and confirmation tests pass. Keep structured input fully functional. Translation, email and tax-preparation helpers remain later, separately scoped work.

**Exit condition:** AI-assisted and manual confirmation produce equivalent decisions from equivalent facts, while their provenance stays distinguishable. No AI is required to complete supported processes.

## 9. How we will know this is genuinely better

Existing tests are useful but not sufficient. The coverage command itself distinguishes structural coverage from verified-content gaps; a green structural audit is not proof of legal completeness. [Coverage advisory](/Users/anar/Projects/Own/Startups/expadu-app/app/Console/Commands/Bureaucracy/CoverageCommand.php:325).

Required verification before rollout:

- **Decision consistency:** identical canonical inputs yield the same process assessment across onboarding continuation, Bureaucracy, Today and Composer.
- **Family isolation:** changing one member’s facts/progress cannot mutate another’s; only authorised relationship-dependent reassessments occur. Revocation stops access and invalidates exposed projections.
- **Unknown handling:** every missing, skipped, disputed or stale input has an explicit result; none silently becomes citizenship, entry method, title or eligibility.
- **Rule truth tables:** test each reviewed branch’s satisfied/unmet/unknown conditions and important combinations. Broad generated combinations supplement—not replace—high-risk conjunctive and exception tests.
- **Transitions:** planning to arrival, new address, title change, sponsor change, first application to submitted/waiting, later renewal, document expiry/replacement and changed source approval.
- **Time boundaries:** month ends, leap years, future/backdated inputs, local date versus appointment time, qualifying intervals and overdue states. No fabricated clock when its anchor is unknown.
- **Question usefulness:** no question for an unavailable rule unless separately justified by the orientation protocol; no repeat loop after skip; no lifetime exhaustion; structured and AI paths recognise the same current question.
- **Publication and migration:** no unapproved or expired recommendation; nested condition/reference validation; stable progress mappings; idempotent backfill and rollback rehearsal.
- **AI and privacy:** consent before transport, minimal payloads, strict schemas, ambiguous-person confirmation, revoked/stale request rejection, safe logging, deletion and provider-failure tests.
- **Human usability:** planning, newcomer and long-term residents can identify whose task it is, what to do next, why a question is asked and what remains uncertain. Include older/non-expert participants rather than assuming automation proves clarity.

Track operational measures: unsupported processes by reason, repeated/skipped questions, recommendations withdrawn after source changes, cross-surface disagreements, stale assessments, AI correction rates and independent family-plan completion. Do not collect raw sensitive answers simply for analytics.

## 10. What is decided, and what still needs approval

**User requirements already established:** backend and rules first; straightforward flow; all three life stages; skippable onboarding basics/status/expiry; separate family-member plans; bounded optional AI; UI redesign belongs to the other task.

**My architectural recommendation:** one deterministic, family-aware process service in the current Laravel application; one canonical fact model; one question planner; separate coverage, requirement, progress and timing states; shared output for every consumer; reviewed reusable policy modules.

**Still needs owner/legal/product approval before implementation:** the architecture proposal itself; delegated adult/minor access and external-processing arrangements; who owns content review and the coverage release criteria. No new live-AI configuration, legal approval or production promotion is implied.

The shortest useful next step is to approve this direction, then implement Phase 1 and the canonical family/process contract. Another UI patch or another handful of persona-specific rules would not resolve the verified disagreements underneath.

### Verification record for this investigation

- Read the supplied product audit and current source; two bounded read-only side reviews covered catalogue integrity and AI/data boundaries. Consequential findings were checked against source, and catalogue totals were independently reproduced.
- Ran [synthetic read-only probes](/private/tmp/expadu-bureaucracy-architecture-2026-09-07.ybRTCm/probes.php). They demonstrate existing behavior, not fixes or end-to-end reachability.
- Read current official sources linked above. This is architectural research, not a comprehensive legal content audit or approval.
- No app tests against active data, live AI requests, migrations, imports, account changes, commits, pushes or deployments.

Method: the brainstorming skill kept this at architecture/proposal stage; systematic debugging required source tracing and concrete probes before naming defects; Laravel review guidance favoured explicit application boundaries within the existing stack. No old proposal was treated as proof that its behavior is implemented or approved today.
