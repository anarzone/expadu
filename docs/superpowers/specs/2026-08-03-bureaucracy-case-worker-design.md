# Bureaucracy Case Worker — Product and Architecture Design

**Date:** 2026-08-03
**Status:** Approved concept; written specification awaiting user review
**Scope:** Expadu onboarding, bureaucracy case clarification, verified-rule matching, personalized plans, and uncovered-case reporting

## 1. Decision

Expadu will replace persona-led bureaucracy planning with a hybrid, rule-grounded case-worker system:

1. A short structured onboarding captures universal facts.
2. A guided, bureaucracy-only conversation clarifies legally decisive facts when necessary.
3. The user confirms extracted facts before they affect recommendations.
4. A deterministic backend matches manually verified rules and assembles a stable personalized plan.
5. DeepSeek V4 Flash may extract candidate facts and summarize confirmed facts, but it cannot create, alter, explain, or publish legal rules, deadlines, document requirements, fees, or eligibility conclusions.
6. Uncovered cases are recorded for manual review. There is no frontier-model integration in the application. The product owner may investigate an uncovered case manually with a frontier model outside the codebase.

The core safety boundary is:

> AI may help Expadu understand free text and summarize confirmed facts. Only manually verified backend rules may select questions, order actions, and assert what applies.

## 2. Why this change is necessary

The current persona and checklist catalogue covers broad situations but misses decisive state changes and cross-person facts. The investigated scenarios exposed these gaps:

- A Blue Card applicant already working on a D visa is not the same as a first-time arriving employee.
- A spouse joining a non-EU sponsor needs different guidance depending on whether the sponsor's Blue Card is pending, issued, or replaced by a settlement permit.
- A Blue Card holder's permanent-residence threshold depends on qualifying employment months, pension contributions, and language level.
- A spouse approaching renewal may qualify for a faster settlement route depending on the sponsor's §18c settlement permit and the spouse's weekly working hours.
- The current application tracks visa expiry but not the expiry of an existing residence permit.

Adding a hard-coded persona for every combination would create an unmaintainable catalogue. Allowing a model to invent the missing plan would create unacceptable legal and product risk. Atomic verified rules plus progressive fact collection solve both problems.

## 3. Goals

- Produce a personalized bureaucracy plan from confirmed, structured facts.
- Ask only questions that can change the applicable verified rules or plan priority.
- Make every authoritative action traceable to manually verified official sources.
- Keep the user's plan stable and explain why it changes.
- Detect missing coverage instead of force-fitting the closest persona.
- Give users useful confirmed guidance even when part of their case is not covered.
- Record privacy-reduced uncovered cases for manual research and catalogue improvement.
- Match the current Expadu visual language precisely across mobile, tablet, and desktop.
- Preserve automated tests, including Playwright coverage, and add scenario regressions for every discovered legal gap.

## 4. Non-goals

- Expadu will not present itself as a law firm or provide a binding legal opinion.
- DeepSeek will not research German law from model memory or the open web at runtime.
- The application will not call a frontier model for uncovered cases.
- The first release will not ingest passports, residence cards, official letters, or full document scans.
- The chat will not act as a general-purpose assistant.
- The system will not guarantee that an authority will decide a case in a particular way.
- The project will not attempt to encode every German immigration rule before launch; unsupported areas must fail safely.

## 5. User experience

### 5.1 Entry flow

The preferred flow is:

```text
Short onboarding
→ preliminary status
→ targeted case clarification when required
→ confirmed case summary
→ verified personalized plan
```

The assistant is mandatory only when a legally important ambiguity blocks a safe plan. Otherwise, the user receives the deterministic plan immediately and may open **Improve my plan** to add detail.

### 5.2 Guided case interview

The interview appears inside the Bureaucracy experience as a case assistant, not as an unrestricted chat product. It:

- explains that it asks only questions relevant to the user's bureaucracy plan;
- asks one focused question at a time;
- offers structured choices when the answer space is known;
- accepts free text when the user needs to describe an unusual situation;
- always supports **I don't know** and **Skip for now**;
- shows why a sensitive or unexpected question matters;
- stops when no remaining answer can safely improve the current plan;
- redirects unrelated requests without continuing an off-topic conversation.

The legally meaningful question, answer choices, and help text are manually authored as part of the verified fact/rule catalogue. DeepSeek may interpret a free-text answer and produce a neutral conversational acknowledgement, but it cannot rewrite the legal meaning of the question or its choices.

AI-assisted free text is opt-in. A user who does not consent to model processing can complete the same clarification through deterministic structured questions.

### 5.3 Fact confirmation

DeepSeek extracts candidate facts but cannot silently update the authoritative profile. Consequential facts must be confirmed by the user.

Example:

```text
Here is what I understood:

• You hold a family-reunification residence permit.
• It expires on 18 November 2026.
• Your husband currently holds an EU Blue Card.
• You work 25 hours per week.

Is this correct?
```

The user can confirm all facts or edit an individual fact. When chat information conflicts with an existing answer, Expadu asks which value is current and does not overwrite either value silently.

The backend detects the contradiction by comparing the candidate structured value with the stored confirmed value. It selects a manually authored confirmation prompt and choices. DeepSeek does not decide whether a contradiction exists, which value has priority, or how the conflict is resolved.

### 5.4 Bureaucracy plan layout

The existing Bureaucracy page remains visually recognizable. Its current cards, spacing, typography, navigation, icon library, interaction patterns, and responsive behavior are reused. The plan is reorganized into flexible sections whose presence and count depend on verified rules:

- **Your current status**
- **Do now**
- **Next**
- **Coming up**
- **Options you may qualify for**
- **Waiting for something**
- **Information we still need**
- **Not currently covered**

The UI must not reveal the internal rule engine. It should read like a calm, knowledgeable case worker: concise, specific, and transparent about uncertainty.

### 5.5 Legal limitations and trust cues

Expadu must communicate limitations consistently without making every card visually alarming:

- A persistent, compact notice on the Bureaucracy page states that Expadu provides informational recommendations, may make mistakes, and is not a substitute for the responsible authority or qualified legal advice.
- Every authoritative task shows its official source and **Verified on** date in the detail view.
- AI-assisted conversation clearly distinguishes **Confirmed**, **Waiting for your answer**, **Not currently covered**, and **Under manual review** states.
- High-impact actions—deadlines, eligibility, applications, and changes of residence title—include a contextual reminder to verify the linked official source or authority.
- The product never uses a numerical confidence score for legal guidance.
- The product never describes an unsupported or tentative item with obligation language such as “must,” “required,” or “eligible.”

## 6. Rule architecture

### 6.1 Source of truth

Approved rules remain in version-controlled repository files and are imported into the database for runtime matching. The existing YAML catalogue is the starting substrate, but broad persona checklists will be decomposed into reusable atomic rules.

Each rule must define:

- stable rule key;
- title and user-facing explanation;
- legal scope and jurisdiction;
- applicability conditions;
- required facts;
- action produced when matched;
- deadline calculation or explicit absence of a deadline;
- required documents and per-document conditions;
- dependencies and conflicts;
- official primary source;
- official implementation or local-authority source where available;
- source verification date;
- legal effective date when known;
- review status and reviewer identity or review record;
- content version.

### 6.2 Legal verification standard

Before a rule becomes authoritative:

1. Its legal basis is checked against a primary official source, normally `gesetze-im-internet.de`, relevant EU law, or another official legal publication.
2. Its practical procedure is checked against a second official source when available, normally the responsible local authority, BAMF, Make it in Germany, or another government portal.
3. Any conflict between sources is recorded and resolved manually; the model cannot select the preferred interpretation.
4. Volatile facts such as fees, thresholds, forms, office procedures, and URLs receive a verification date and a review schedule.
5. The rule receives positive, negative, boundary, and conflict tests before publication.

If only one authoritative source is available, the rule is marked internally as single-source verified and requires explicit manual approval. Absence of a second source does not permit model-memory supplementation.

### 6.3 Runtime matching

The matcher operates only on confirmed facts. It returns one of four results:

- **Matched:** one or more rules can safely produce plan items.
- **Needs information:** known rules might apply but decisive facts are missing.
- **Not covered:** no verified rule covers the confirmed case.
- **Conflict:** verified rules appear to produce incompatible conclusions and require manual content review.

The matcher must not substitute the nearest persona or infer missing legal facts.

A **Conflict** result preserves and displays only independently matched, non-conflicting verified tasks. The disputed branch appears as **Not currently covered**, creates a manual coverage-review record, and—when urgent—directs the user to the responsible authority or a qualified adviser. DeepSeek cannot resolve a rule conflict.

### 6.4 Stable plan snapshots

The composed plan is saved with:

- confirmed fact version;
- matched rule keys and versions;
- generation timestamp;
- plan sections and ordering;
- unresolved facts;
- coverage state.

The plan is recomputed only when:

- a confirmed fact changes;
- a rule version changes;
- a relevant date boundary is reached;
- a task is completed and affects dependencies;
- the user explicitly requests reassessment.

The plan is not regenerated merely because the page reloads or DeepSeek produces different wording.

## 7. Question selection

The backend, not DeepSeek, controls which fact may be requested.

For every potentially applicable rule, the matcher lists its unresolved required facts. The question selector ranks allowed questions by:

1. imminent deadline or legal-status risk;
2. ability to eliminate or confirm major rule branches;
3. whether the answer unlocks the next actionable recommendation;
4. sensitivity and effort required from the user;
5. whether the same fact will be reused by multiple rules.

The selector chooses one defined fact target, its manually authored question, and its allowed answer schema. DeepSeek may interpret a free-text response into candidate structured values. The backend rejects any response that attempts to write an undefined fact or select an unauthorized action.

The interview stops when:

- all decisive facts are confirmed;
- the user says they do not know and no lower-risk question can resolve the branch;
- the remaining uncertainty does not affect currently actionable guidance;
- the case is genuinely not covered;
- the MVP limit of 12 clarification questions per case is reached;
- three attempts to resolve the same decisive branch have failed.

When a limit is reached or the user cannot answer, the case remains in **Needs information** rather than becoming an error. Expadu preserves independently matched verified tasks, shows the unanswered fact under **Information we still need**, offers deterministic editing later, and directs the user to an official authority when the missing fact blocks urgent guidance.

## 8. DeepSeek V4 Flash boundary

### 8.1 Allowed operations

- Classify a message as bureaucracy-related or unrelated.
- Extract candidate values for backend-authorized facts.
- Summarize confirmed case facts.
- Produce structured JSON matching a strict server-side schema.

### 8.2 Forbidden operations

- Create or change an eligibility rule.
- Calculate a legal deadline unless the backend supplies the calculation result.
- Add a required document, fee, legal consequence, or application step.
- Invent or fetch a citation.
- Treat an unconfirmed fact as true.
- Change or reorder the plan.
- Rewrite manually authored legal questions or answer choices.
- Paraphrase authoritative legal instructions for publication.
- Answer unrelated general questions.
- Publish content.
- Call arbitrary tools or browse the web.

Model output is validated server-side. Invalid, unavailable, timed-out, or rate-limited model responses fall back to structured UI questions and the last valid deterministic plan.

## 9. Uncovered cases and manual research

### 9.1 User-facing fallback

After bounded clarification, an uncovered case displays:

> We cannot currently verify a complete workflow for your situation. We will continue showing the steps that are independently confirmed, but we will not guess about the unresolved part.

The user may still receive:

- universal verified tasks;
- independently matched rules;
- known deadline warnings;
- a concise list of missing or unsupported facts;
- official authority contact options;
- related content labelled as related rather than applicable.

Urgent unsupported cases explicitly direct the user to the responsible authority or a qualified adviser. Expadu does not promise a manual answer within a deadline.

### 9.2 Internal record

The application stores a privacy-reduced uncovered-case record containing:

- normalized confirmed fact vector;
- coverage state and failure reason;
- rules considered or nearly matched;
- questions already asked;
- known urgency;
- anonymized frequency fingerprint;
- optional user-provided note;
- consent state for retaining detail and sending a later update.

Anonymous fingerprint counting is permitted for coverage analytics. Retaining detailed case content or contacting the user later requires explicit consent.

### 9.3 Manual frontier-model workflow

There is no application code or automated job that calls a frontier model.

The product owner may manually export or reconstruct a privacy-reduced review packet, ask a frontier model for research assistance outside the application, and bring the result back as an unverified draft. That draft follows the existing bureaucracy-gap workflow:

```text
Uncovered case
→ manual research packet
→ optional external frontier-model assistance
→ official-source verification by a human
→ concise atomic rule draft
→ automated regression tests
→ human approval
→ versioned publication
→ affected plans offered for reassessment
```

No frontier-model text becomes user-facing merely because it was generated by a stronger model.

### 9.4 Deduplication and notification

Similar uncovered cases are grouped using a non-identifying normalized fact fingerprint. One coverage gap may therefore represent many users without exposing their personal data.

When a new rule is published, users who consented to updates may receive:

> A newly verified rule may apply to your saved case. Review your facts to update your plan.

The new rule is not silently applied to an old plan. The user reconfirms relevant facts before activation.

## 10. Data responsibilities

### 10.1 Version-controlled files

Store:

- approved atomic rules;
- official source metadata;
- verification dates;
- content versions;
- reusable action and document definitions;
- scenario fixtures and expected matches.

### 10.2 Database

Store:

- user cases;
- candidate and confirmed facts;
- fact provenance and timestamps;
- contradictions and resolution state;
- bounded conversation messages or summaries under the retention policy;
- plan snapshots;
- matched rule versions;
- uncovered-case records;
- anonymized fingerprints and occurrence counts;
- consent and notification preferences;
- audit events for plan-affecting changes.

Unconfirmed candidate facts expire with their source messages after 30 days. Confirmed facts remain part of the user's profile until corrected, deleted, or the account is deleted, but time-sensitive status facts carry a manually defined reconfirmation interval; the default is 180 days. An expired status fact is treated as **Needs information** until reconfirmed and cannot activate a new high-impact rule. Expadu retains the active plan snapshot and the five most recent superseded snapshots; older superseded snapshots are deleted after 90 days.

The application must not collect passport numbers, residence-card serial numbers, or unrelated sensitive facts merely to improve model performance.

## 11. Visual and interaction quality

The implementation must be pixel-perfect relative to the current Expadu application rather than introducing a separate chatbot aesthetic.

Required design behavior:

- reuse the project's single icon library and existing design tokens;
- reuse existing card radius, borders, spacing, typography, colors, button hierarchy, sheets, and navigation patterns;
- maintain the existing warm, calm, supportive voice;
- preserve mobile safe areas and the current bottom navigation behavior;
- support keyboard navigation, focus visibility, screen readers, reduced motion, text scaling, and minimum touch targets;
- provide loading, empty, error, offline, rate-limit, and model-unavailable states;
- show progressive disclosure so legal detail and citations are available without overwhelming the checklist;
- avoid chat bubbles that visually dominate the plan;
- keep the confirmed plan usable when JavaScript model calls fail or the model service is unavailable.

Before implementation is considered complete, the relevant mobile and desktop screens must be compared visually with the current application and verified by automated browser tests. Manual browser testing remains opt-in and will be performed only when the user requests it.

## 12. Safety, privacy, and operational controls

- Per-user and per-IP rate limits control cost and abuse.
- A bureaucracy-domain gate rejects unrelated conversation.
- DeepSeek receives only the minimum case context necessary for the current authorized operation.
- The MVP rate-limit defaults are 20 AI-assisted user messages per rolling 24 hours and 12 clarification questions per case; both limits remain server-configurable and structured fallback questions remain available after the AI limit is reached.
- Prompts and structured-output schemas are versioned.
- Model name, prompt version, latency, token usage, validation result, and fallback reason are logged without unnecessary sensitive content.
- Raw AI-assisted messages and unconfirmed candidate facts are retained for no more than 30 days. Confirmed structured facts and user-approved case summaries follow the lifecycle in section 10.2. A consented detailed uncovered-case record is retained for no more than 180 days; after that, only its non-identifying aggregate fingerprint and occurrence count may remain.
- Users can inspect and correct confirmed facts.
- Users can use deterministic clarification without opting into AI-assisted free text.
- Users can withdraw optional consent for detailed uncovered-case retention and future notification.
- DeepSeek outages cannot remove existing deterministic guidance.
- Legal-source verification and content updates remain manual publication actions.

## 13. Testing strategy

Every production change requires automated coverage.

### 13.1 Deterministic rule tests

- positive and negative applicability;
- missing-fact results;
- exact date and threshold boundaries;
- dependencies and conflicts;
- plan stability and version-triggered reassessment;
- uncovered-case routing;
- source and verification metadata enforcement.

### 13.2 Case corpus

The initial regression corpus includes:

1. Employee working on a D visa and applying for a Blue Card.
2. Spouse arriving on family reunification while the sponsor's Blue Card is pending.
3. Blue Card holder approaching the 21/27-month settlement threshold.
4. Spouse whose sponsor obtains a §18c settlement permit.
5. Spouse approaching family-permit renewal after almost four years.
6. A genuinely uncovered case that must remain unresolved rather than over-match.

### 13.3 AI contract and safety tests

- valid and invalid structured extraction responses;
- contradictory user messages;
- attempted unsupported fact creation;
- attempted legal-rule invention;
- off-topic and prompt-injection requests;
- model timeout, transport failure, and rate limiting;
- deterministic fallback behavior;
- no plan mutation without confirmed facts and matched rule identifiers.

### 13.4 Frontend and browser tests

- guided-question flow;
- fact review and correction;
- plan section rendering;
- source and verification disclosure;
- unsupported-case state;
- responsive mobile and desktop layouts;
- keyboard and screen-reader semantics;
- loading, error, offline, and model-unavailable states;
- visual regressions for the current design language.

Existing Playwright tests are retained. Automated browser tests are part of verification; manual browsing is not required unless explicitly requested.

## 14. Delivery sequence

The implementation plan should divide the work into independently verifiable increments:

1. Rule schema and legal-source validation.
2. Structured case facts and confirmation lifecycle.
3. Deterministic matcher, missing-information results, and stable plan snapshots.
4. Progressive onboarding and case-summary UI.
5. Bureaucracy-only DeepSeek free-text extraction and case-summary integration.
6. Flexible plan sections and pixel-perfect responsive states.
7. Uncovered-case recording, deduplication, consent, and manual review workflow.
8. Full scenario, safety, accessibility, and visual-regression verification.

The deterministic system must work before DeepSeek is enabled. AI integration is an enhancement to free-text clarification and fact summarization, not a prerequisite for basic bureaucracy guidance.

## 15. Acceptance criteria

The design is successfully implemented when:

- all six scenarios in the investigated case corpus produce their exact reviewed outcome: the expected verified rule set or the explicit uncovered state;
- no DeepSeek response can introduce an authoritative action without a matched rule identifier;
- consequential extracted facts require user confirmation;
- conflicting facts cannot silently overwrite each other;
- every authoritative task exposes official sources and verification metadata;
- during model timeout, transport failure, invalid output, or rate limiting, the Bureaucracy page renders the complete last valid deterministic plan and all applicable structured fallback questions without an AI dependency;
- unsupported and conflicting branches produce no plan item without an independently matched verified rule identifier; independently matched verified tasks may remain visible;
- detailed uncovered-case retention and later contact require consent;
- rule changes are versioned, tested, manually approved, and trigger controlled reassessment;
- the Bureaucracy experience matches the current Expadu visual system across supported breakpoints;
- automated backend, frontend, browser, accessibility, and visual-regression tests pass;
- the persistent legal limitation clearly states that Expadu may make mistakes and users should verify consequential guidance with official sources or the responsible authority.

## 16. Implementation gate

No implementation begins until this written specification is reviewed and approved. After approval, a separate detailed implementation plan will identify exact files, migrations, services, UI components, tests, rollout controls, and verification commands.
