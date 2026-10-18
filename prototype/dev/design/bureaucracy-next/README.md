# Bureaucracy design preview

Open `http://127.0.0.1:8765/dev/design/bureaucracy-next/`.

The UI review covers Overview, process details, inline Paperwork and Composer planning. This remains a local clickable prototype, not a production Bureaucracy client.

## Design pass: colour, search and filters — 10 October 2026

Owner request: the page looked grey everywhere and had no search or proper
filters; the data itself is for the real app.

- **Paperwork's own colours, within the shared roles.** Every Paperwork icon
  (tasks, steps, dates, documents, Good to know, Your situation, the welcome)
  is lavender with a violet glyph; a finished step or a ready document is
  solid violet. Statuses: working on it in violet, blocked in attention amber,
  waiting quiet lavender, done solid violet, not started outlined. Each
  tracked task has a slim violet progress bar. Cyan is left only for what's
  selected (the current view, a chosen filter) — `tests/design-browser.cjs`
  checks nothing else uses it.
- **Outlined controls, not grey fills.** The views are a segmented control
  with the chosen one in cyan; whose plan and Your situation are outlined
  pills. Tasks and dates share one ivory card with hairlines between rows;
  the preview note is ivory with a hairline.
- **One search** beside the views (its own row on a phone) finds tasks (by
  name and every step), dates, documents (English and German terms) and
  Good to know, as you type; matching documents link to Documents, which
  filters by the same search. Escape or Clear empties it.
- **Filters in place.** Your tasks: All · To do · Waiting · Blocked (only the
  ones with tasks). Documents: a chip per task (replacing the browser's
  list) and Any status · Not ready · You have it · Ready.
- **Documents** has no page title; tasks without documents are named in one
  line instead of empty cards; the groups sit in balanced columns.
- **A task's page** leads with "Your overview", then its name with its status
  and the edit button; the Overview/Documents views aren't repeated there.
- **Phone first screen.** The question's answer is its one orange action and
  "Skip for now" is quiet, so the three fit on one line; Coming up and the
  question are tighter, so the first task shows on the first screen. Task
  rows show their status under the words (the whole row opens the task);
  dates put their day under their name; a step's actions start at the left,
  clear of Composer's button.
- Your situation's "Health insurance" uses the health icon, not a tick.

## The first version, and what's planned for later — 9 October 2026

Owner decision: what the backend doesn't support yet stays out of the first
version. The preview now shows that first version by default; Review →
"Show features planned for later" brings the rest back to look at (a
per-viewer setting, `expadu-bureaucracy-later` in this browser).

Planned for later (no endpoint or entity yet; don't build them now):

- reporting a change ("Something changed", the change picker and its
  "Reported changes" list) and the "Check after your change" flags it raises;
- the person-level status: "Time to check" and its check-in, "Needs review",
  "Answers checked" and "Last checked". The first version says only "Some
  details missing", which the plan's open questions already tell;
- "Explore a topic" with "Track task", and the welcome's "Browse first" that
  leads to it;
- the starter questions' "1 of 3" counter (the pause after three answers
  stays: the backend pauses the run).

## Simpler overview, by our own UI rules — 8 October 2026 (in review)

A UX check against the project's UI rules found three gaps; the owner asked
for all three to be fixed.

- **The plan comes first.** On a personal plan, Your situation is now one line
  ("5 answers · Last checked …") that opens in place. It starts open when
  something needs you (a check-in, answers to review, a just-saved change) or
  when the plan has nothing else to show yet. Every persona's first task,
  option or question is now on the first phone screen (it was 1,000–1,800px
  down), and pages are 2.6–3.4 phone screens instead of 3.9–5.2.
- **Colour roles (6 October).** The question card, the guided entry, the date
  rows and the "new task" highlight use the shared ivory surface with a
  hairline instead of the selection cyan; "Track task" is an outlined
  secondary action. Cyan stays for selected states.
- **Nothing listed twice.** Next steps and Your tasks are one list: each task
  shows its next step (plan.actions, soonest first), with "All steps" and the
  status counts beside it; on a wide screen, Dates & appointments sits beside
  the tasks. A date already in Coming up isn't repeated on a task (the step
  page still shows it). The guided entry ("Help me get started") shows only
  when there's no question card, since both lead to the same questions.
  Explore a topic leaves out topics already in your plan or waiting for your
  arrival.

The owner then asked for the remaining cyan surfaces too: the Fresh start
welcome panel (now divided by a hairline), the "Look around first" banner, the
current step's action box and the "For this task" panel on a task page, and
the family card use the ivory surface with a hairline; a family member's
portrait uses Paperwork lavender.

## One example of everything the backend can do — 8 October 2026 (in review)

The owner asked whether the mock covers every scenario the backend supports,
end to end; the backend session listed what it never showed. One example of
each kind is in now (it's for seeing the design, not every case). Most are
under Review: a persona preview or Other states.

- **Guidance that isn't a task** (plan.guidance, as the backend sends it
  since cf1b1caa and f98ed7b9): every row says whether it `applies` (every
  criterion met; an unanswered one isn't) and whether it waits for arrival
  (`after_arrival`). The page shows only rows that apply, in three quiet
  lists below the tasks and dates. None has a status, a count of steps or a
  button to track: they aren't to-dos. Each opens with when it was checked,
  then its own text and its official pages ("Official page", "Legal text").
  - "Good to know" (kind context), grouped by topic. All twelve context cards
    of the release are in `source-data.js` with the backend's own text,
    official links and applies_if (any one group, every condition in it; a
    list means one of these values). The sample person gets the backend's
    six: German citizenship, Other residence routes, Moving your permit to a
    new passport, While your application is pending, Permanent residence,
    Inviting a visitor. The Opportunity Card's work limits don't show (their
    track is never asked), and "Checking your residence title" shows only for
    another title or a settlement permit of unknown type, first because it's
    urgent. A student (Fresh start: non-EU, study) also gets cards under Work
    and Tax; an EU citizen gets only Inviting a visitor.
  - "Options for you" (kind option): routes you may choose, grouped by route;
    never a task and never a claim that you qualify. Review → Blue Card
    holder, settling (16 qualifying months, B1 German, aiming for permanent
    residence): no task applies, and the two settlement cards show under
    "Permanent residence with a Blue Card". Past 20 months the tracking card
    no longer applies. Other options (the freelancer's small-business VAT
    rule, a work-permit holder's §18c check) have the same shape.
  - "After you arrive": Review → Still planning the move (non-EU, a job
    offer). Health insurance is the one task. The bank account's catalogue
    phase is after arrival, so it isn't proposed, asks nothing before the
    move and is listed here; once they've arrived it's a task again. The mock
    holds back whole tasks (each of its after-arrival tasks starts after
    arrival); the backend does it per step.
- **No residence title yet**: the permit question's ninth answer, for a
  visa-free arrival or someone not here yet. It brings no permit-expiry
  question and no Blue Card route.
- **Withheld cards** (not machine-confirmed): only as unconfirmed coverage
  units in Sources (the freelance residence permit, Kindergeld). Life events
  aren't live, so they're not tasks.
- **Another route**: Review → Joining a spouse. "Joining your family" (prepare,
  aimed at the visa expiry; the permit appointment with its eight documents)
  and the integration course as a low-urgency proposal. Other routes
  (student, freelance, Opportunity Card, work permit, renewal, settlement)
  have the same shape and aren't repeated.
- **A relationship review** (review_relationship): in that preview, what you
  said about your spouse differs from their own record. You keep your answer
  or correct it; their record never changes.
- **An EU citizen**: everyday tasks only, and "No residence task applies to
  your answers": none applies, nothing is missing.
- **Entry states and coverage**: No profile yet (setup_required), Record
  unavailable, Outside Cologne (outside_coverage). Outside Cologne (owner, 18 Oct
  2026, Composer review canvas board 16) stays honest and gives a way on:
  "Tell me when it's covered" (a sample: it says so, and nothing is sent) and
  "My area is Cologne" (in the preview, back to your plan), then "Ask Composer
  about your move". The board's other rows, your documents and your own
  dates, aren't offered: with no covered area the plan has no documents or
  tasks to hold them. The foot says "Where this comes from", and the
  coverage line "We're still checking a few steps against official sources
  · Which ones".
- **Over time** (Review → Other states): an answer past its reconfirm days
  asked again; guidance changed for a task (review_required, "Use the updated
  steps"); guidance withdrawn (history_only, kept in History with its steps);
  a date under review ("Being reviewed").
- **Say it in your own words** (AI fact extraction, scripted; no AI is
  connected): in a date question, your words become a suggested answer you
  use or dismiss; nothing is saved until you confirm.
- **Sharing**: the person menu → Share your plan: who has access, remove
  access, and an invitation with chosen access; "Preview what they'll see"
  shows your plan without Your situation or questions, and "Documents aren't
  shared with you" without document access. Your record: download or delete
  (samples).
- **As the person you shared with sees it** (Review → Someone you shared
  with; owner, 18 Oct 2026, Composer review canvas board 15): the plan is
  "Anar's plan", a label, not the person menu; one line says who shared it,
  when, and what the invitation allows ("Shared by Anar on 12 Sept. You can
  see the plan and mark documents. Only Anar changes the plan."); the page's
  own words name Anar ("Anar's tasks", "Anar's D visa expires", "Needs
  Anar's move-in date"); without progress access, dates can't be added or
  edited. Task lines from the catalogue ("Next: Prepare the evidence for
  your first Blue Card application") still say "your": that copy comes from
  the backend's cards.
- Documents say uploading, scanning, translating and letter writing aren't
  available yet.

Left out on purpose: legal_due and authority_follow_up dates (supported, but
no card in the release has one), the 90-day permit window of visa-free routes,
adding a dependent and guardian approval, and correcting a recorded event
beyond the submission date.

**Ahead of the backend** (no endpoint or entity; don't build from the mock
yet): the life-change report ("Something changed"), the person-level status,
"Check after your change" flags, the "Explore a topic" grid and the starter
run of three.

## Tested against the rules, person by person — 8 October 2026

`tests/use-cases.test.cjs` walks six newcomers through every question they'd
be asked (a non-EU D-visa employee aiming for the Blue Card, an EU citizen who
has settled in, a non-EU person still planning the move, a work-permit holder,
someone who answers "I don't know" to everything and someone who skips
everything) and checks the backend's rules on each: who is asked what, which
tasks open, the pause after three, one question at a time, unknown answers
opening nothing. On the sample person it checks that reading the plan saves
nothing, documents (having one isn't ready; an optional one can't be marked
ready), progress (waiting needs a submission, completion every step), dates
(targets from your own answers, never a legal deadline), gaps (partial
coverage, an unconfirmed card shows nothing of its own) and read-only people.
A browser pass at 390 and 1280 checks every view for legal figures outside the
cards' own text, eligibility or deadline claims, overflow and errors.

Found and fixed by it: a newcomer's visa answer didn't date the Blue Card
targets (they read the page's own stored date only), and the model would mark
an optional document ready if asked (the page never offered it; the backend
refuses it).

## The backend's questions, Your situation, and tasks that arrive — 8 October 2026 (in review)

From the backend session's review (its points 1, 2, 8 and 9). Questions,
answers and tasks now follow the backend's rules; `registry.js` holds them.

- **Every registry question** (28, `schema/facts.yaml`) in its own words, with
  its why, type and options: dates, choices, yes/no and whole numbers. Labels
  and option names are UI copy, the same as the app's (`facts.ts`). No fact
  allows "Doesn't apply to me" yet; the editor offers it only where one does.
- **The order** is the backend's (`QuestionProtocol`, 7ffc1179, owner
  approved): the protocol's first questions (citizenship, the move, the
  permit, its expiry) under their own conditions; then the most urgent work
  first, and within one urgency a question for work that already applies
  before one that only decides whether other routes apply. So a newcomer is
  asked about address registration and health insurance before the work
  route, and a task's own date (the move-in date) right after it opens. The
  employment track isn't asked once the goal is the Blue Card (`not_asked`).
  A skipped question doesn't hold the next ones up.
- **The question card** (`plan.questions`) shows the backend's states: a
  question "For your {task} process" (by the backend's process id) with Enter
  date or Answer, I don't know and Skip for now; "{question} left for later"
  or "2 questions left for later · Answer when ready"; the pause after three
  answers or skips in a row ("You've answered a few questions. The rest can
  wait until you're ready. · Keep going"); "That answer didn't fit. You can
  try the question again. · Try again"; and two saved answers that disagree
  ("Review answers"). Review → Question states shows the last two.
- **Your situation lists what the backend can**: your answers, what you left
  for later and the current question, never answers it guesses apply. The
  sample person's answers have a permanent history (their first visit on
  2 Sept and a correction the next day), and "Last checked" is when an answer
  was last confirmed. Answers given here are dated on the sample day.
- **Tasks arrive from answers**: a task opens as a proposal once your answers
  meet its card's conditions, marked "New from your answers" until you open
  it; the pause says "EU Blue Card and Address registration are now in Your
  tasks". The address registration target is dated from your move-in date
  with the card's own days once you give it (as the backend's timeline does).
- **Try it as a new user**: Review → Fresh start now takes answers, kept in
  that tab only (your plan is never written; Composer stays off). The other
  previews stay read-only.

**Ahead of the backend, kept on the owner's word** (the mock is where the
logic is checked; don't build these from it until the backend has them):
the person-level status ("Time to check", "Needs review"), "Check after your
change" flags, the "Explore a topic" grid and the starter questions shown as
a run of three before the pause.

## The current catalogue, and when it was checked — 8 October 2026 (in review)

From the backend session's review of the mock against the backend (its
points 3–7). The sample's guidance now comes from the current reviewed
catalogue (`codex/bureaucracy-backend-v2`, `database/seeders/data/bureaucracy`,
8 Oct), copied exactly in `source-data.js`:

- **The EU Blue Card has three steps**: prepare the evidence, submit before
  the D visa expires (both still aim for the visa expiry), and "Attend your
  Blue Card appointment with the originals". The documents are listed once,
  on the appointment step: nine of them, four optional ("May be needed": the
  entry visa, BA approval, private health insurance proof and a professional
  licence). The sample's 14:00 appointment is that step.
- **Only required documents count**: only a required document can be marked
  ready, so a step's count is "ready of required" (1 of 5 for the Blue Card,
  as Today and Composer show), with the optional ones counted apart
  (`requirements.optional`), and its first document to check is a required
  one while any is open. The backend does the same since 6268e24d.
- **The other tasks as the catalogue words them now**: "Check and prepare your
  address registration (Anmeldung)" with its two documents, "Confirm health
  insurance cover for your stay" and "Review an account for your everyday
  payments" with none ("This task lists no documents."), and the Steuer-ID
  card.
- **Checked, with a date**: "About this step", Official information, the
  Sources sheet and Composer's guidance card say "Checked against the official
  pages on 30 Sept 2026" (the daily automated check, as of the sample day; the
  card's content version stays its own). The overview strip still says "Some
  guidance still needs checking": the units are preparation guidance only,
  and every unit would have to be complete to say otherwise. The footnote is
  "Sample progress and guidance".
- **What a newcomer tripped over**, fixed as the app fixed them: a task you
  start tracking reads "Tracking"; step text flows as paragraphs; a date a
  week away says "In a week".

## Arrival asked as itself, no free-text tasks, documents explained — 8 October 2026 (in review)

The remaining follow-ups from the backend mapping (C19 and C27), and the
backend's document names and links (8f4074c9):

- **The arrival answer is asked, not inferred (C19).** "Help me get started"
  used to ask "Are you already in Germany?" and quietly record its answer as
  the registry fact `arrival_planned`. It now asks that fact's own question in
  the registry's words, "Are you still planning this move to Germany?"
  (Yes, still planning · No, I have arrived · I'm not sure), with its reason
  and "Your answer is kept in your details." The answer goes into your
  details as you gave it, and answering again updates it. "I'm not sure" is
  an unknown answer, not a guess.
- **No "Add a task" (owner decision, 8 October).** There's no backend
  entity for a free-text task and none will be added: a person starts only
  what their plan suggests (proposals). The + beside Your tasks, its panel and
  the old "Guidance not connected" request cards and page are gone; older
  saved requests are no longer read, and `?view=request` opens the overview.
  Suggested tasks still appear under Your tasks ("Explore before you start").
- **German document terms in English.** A document whose label names a
  German term shows its English name with the term the office uses, for
  example "Landlord's confirmation of move-in (Wohnungsgeberbescheinigung)".
  Labels are unchanged (`paperwork.requirements[].terms`).
- **No Meldebescheinigung for health insurance or a bank account (owner,
  8 October).** The sample's old, unsourced catalogue labels listed one for
  both; no allowed source says an insurer or bank needs it, and §31 ZKG
  contradicts it for basic accounts. The two rows are gone from the sample.
- **Where a document comes from.** The Blue Card's "Health-insurance
  evidence" says "This comes from your Health insurance task" with a way to
  open it, and the Health insurance step says "Also needed for EU Blue Card
  (Health-insurance evidence)" (`produced_by` and `guidance[].produces`). It
  is a catalogue link, never a prerequisite or a claim about your progress.

Tests: `tests/orientation.test.cjs` covers the arrival question,
`tests/contract.test.cjs` the terms and links, `tests/plan.test.cjs` that
free-text requests never become tasks; checked in the browser at 390 and
1280 px.

## Reads the v2 plan contract — 8 October 2026 (in review)

The Bürokratiekarten session's updated contract (`docs/contracts/bureaucracy-v2.md`
in the `bureaucracy-backend-v2` worktree, 199b76d5; not on staging) now
supplies everything Paperwork used to work out for itself. `contract.js`
projects the sample records into that shape (`bureaucracy.plan.1`), following
the backend's read model, and the page reads only that: next actions, progress
counts, steps, documents, the timeline, attention and coverage. `plan.js`
plays the backend: the person's reports are kept in order and the workflow is
replayed from them, like `POST /processes/{id}/events`. Sample data only.

What changes on screen:

- **Next steps follow the backend's order:** earliest step date first,
  undated ones after, untouched. The sample shows the Blue Card preparation
  and submission (both aiming for the visa expiry, 1 Oct), then address
  registration.
- **A hold-up you report blocks the task, not its steps.** The counts are
  steps (4 to do, 0 blocked, 1 waiting), and a step can still be finished
  while its task is on hold; the task stays Blocked. Only an unfinished
  earlier step would block a step, and none of these have one.
- **Update progress offers the contract's moves.** "Something is holding me
  up" before a submission, "The office needs something from me" after one;
  waiting needs a submission; completion needs every step. An optional note
  (up to 500 characters) goes with most changes, and closing dates are
  optional, never later than today. A refused change shows the backend's
  message as it is, in the form.
- **Withdrawing a submission** (`submission_retracted`) replays your reports
  without it: a "waiting" that only followed it drops out. Step states stay.
- **Start tracking reports nothing** (Not started), and **Undo** takes it
  back until there's progress (`process_untracked`).
- **Appointments keep their length and place.** "How long" may be Not known
  ("End time not known", never assumed); a place typed as text is kept but
  "no journey can be planned to it yet". The sample appointment keeps its
  30 minutes and the Wiener Platz point.
- **Dates come from the timeline, and every step date is a target.** The
  visa expiry is a personal date (`document_expiry`, not a legal claim). Both
  Blue Card steps aim for it (`preparation_target`, anchored to the expiry),
  so "Visa expires tomorrow" comes from data and shows once. Since the
  owner's 7 October decision (backend f761736d) no date is a legal deadline:
  address registration is a "Target date" that "needs your move-in date",
  and its task says "Aim to register soon after moving in. The date needs
  your move-in date; it isn't a confirmed legal deadline", as the reviewed
  card does. UI copy carries no legal figure (no period, fee or day count):
  a figure a person reads must come from the card's own source-checked text,
  so it can't go stale. A target that isn't anchored reads "Aim to finish by
  …", never "Due".
- **Unconfirmed cards** (the backend's daily source check hasn't confirmed
  them) show in Sources & coverage as "We couldn't confirm this right now.
  Check the official page." with the official links, and nothing of their
  steps, documents or dates. The sample's cards are all confirmed, so this
  shows only when a card is marked unconfirmed (tests cover it).
- **The question is the registry's:** "When does your entry visa expire?"
  with why it's asked. Skip defers it; "I don't know" answers it.
- **Documents:** readiness has no "unknown" (it reads Not ready yet), "May be
  needed" comes from applicability data, and only a required document can be
  marked ready for a task. The step's first document to check is the first
  one not yet ready, as the backend picks it.
- **Sources & coverage** lists each reviewed unit with its state
  ("Preparation guidance only"), never "everything covered".
- Health insurance's sample "waiting" now has the submission it needs
  (24 Sept, sample).
- Fixed: "I've finished this step" saved but then failed before its
  confirmation.

Today and Composer read the same records through `BureaucracyContract.records()`:
the ready count, the visa date and whether a submission is recorded follow
what you mark here (Composer's "what to bring" answer and Your day's
deadline, Today's Paperwork lines). With nothing saved they match the sample
day exactly.

Task names are the backend's own process titles (`processes[].title`, from
its `process-titles.yaml` since f7e6f117): EU Blue Card, Address
registration, Health insurance, Bank account and Tax ID (the retained record
was "Tax identification number"). Step headings stay the steps' own. "Undo"
after Start tracking follows the plan's `untrackable` flag, which confirming
a document for the task also ends.

Nothing from the mapping is open now: the document English names and the
links between tasks are mapped too (see above). The backend catalogue has
grown to about 46 cards in 30 processes (Blue Card first application now has
four steps); this preview keeps its historical sample of two Blue Card steps
and three other tasks. Tests: `tests/contract.test.cjs` (19),
`tests/plan.test.cjs`.

## Aligned with the v2 backend rules — 7 October 2026 (in review)

From the Bürokratiekarten session's mapping (`docs/contracts/paperwork-ui-mapping.md`
in its worktree), where the prototype disagreed with backend rules a person can
see:

- **Submitting isn't finishing.** "I've submitted it" records the date and
  moves the task to Submitted; the step stays open ("The task waits for the
  office; mark this step finished when you hear back") and the visa-expiry
  warning goes. Removing the record goes back to Preparing.
- **Only valid progress changes.** Update progress offers what the backend's
  state machine accepts from the current state: Waiting for the office only
  after a submission, Back to preparing from Blocked or Needs your action, and
  Completed only once every step is finished (otherwise "To record completion,
  mark each step finished first"). Blocked steps can't be marked finished.
  Labels follow the backend: Needs your action, Submitted, Waiting for the
  office. The free-text progress note is gone (no backend storage yet).
- **"I don't know" isn't "Skip".** The visa-expiry question offers Enter date,
  I don't know (an answer: "You don't know your visa expiry yet") and Skip for
  now (left for later). The guided questions already kept "I'm not sure" and
  "Skip for now" apart.
- **Only people you can access.** The person switch lists you and the shared
  sample people; "Private adult" and "Pending dependent" are gone.
- **Read-only persona preview.** Review › Preview a persona shows Fresh start,
  Returning user or the existing sample plan read-only, like the app's QA
  preview, with "Back to your plan"; changes are refused and your plan isn't
  touched. Reset demo is gone. `?scenario=returning` opens the preview.
- **Anmeldung legal deadline** (superseded 8 October: it's a target to aim
  for, see above). Address registration shows "Legal deadline:
  within two weeks of moving in · §17(1) BMG" (verification recorded 8 Sept
  2026), as the backend declares it. Its date needs the move-in date, which the
  sample doesn't record, so it says so; the client never calculates it.

Already aligned: changing an answered visa or permit goes through Your
situation with "Correct it" or "It changed". Not part of this mock: the app's
onboarding (situation, Veedel and arrival are required there). Still open with
the backend at the time: data shapes (G2/G3/G7), "Add a task" and the
introduction writing "arrival" without asking (C19); all three are settled
since (8 October, above).

## Sample day, dates and a clearer header — 7 October 2026 (in review)

From the owner's Paperwork review (items 1–4):

1. **One sample day.** Paperwork opens on the Blue Card plan Today shows
   (Wednesday 30 September, `connected/sample-day.js`); Fresh start and
   Returning user stay available under Review. The plan carries Today's
   appointment (14:00, Mülheim), marked "On Today" instead of offering to add
   it again. Set aside time defaults to the sample day, suggests times after
   13:10 around the appointment and lists it under Your day. "Last checked"
   and reported changes use the sample day. Your situation for this plan
   records arrived, employment, non-EU and address not registered yet.
2. **What's coming up.** The overview starts with this week's recorded dates:
   "Today · 14:00 · EU Blue Card appointment" and "Tomorrow · Your D visa
   expires" in amber. The submission step carries "Visa expires tomorrow" and
   comes first under Next steps; the task page shows "Tomorrow · 1 Oct 2026"
   and "Today · 14:00". These are the person's own recorded dates, never a
   calculated legal deadline.
3. **Header.** The person menu shows the name ("You") instead of a second
   avatar beside the account's; "Details" is "Your situation" ("Situation" on
   small phones, where the title is a size smaller so it isn't clipped); the
   tabs are Overview and Documents; the loose "Sample" tag is gone (the
   footer keeps "Sample progress · Historical guidance").
4. **Review tools.** The Preview scenario menu and Reset demo sit in a small
   Review menu in the footer, beside Sources & coverage.

Items 5, 7 and 8 (what the getting-started answers change, English document
names, which task produces which document) wait for the catalogue and engine
review in the separate Bürokratiekarten session.

Verification: Paperwork 65 tests and every other suite pass. Browser checks at
320, 360, 375, 390 and 1440px cover the overview, the Blue Card task page,
Set aside time with Your day, Your situation and switching scenarios under
Review. Every other page's default view is unchanged.

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
- `core.yaml`: `core.anmeldung`, `core.health_insurance`, `core.bank_account`, `core.steuer_id`, version `2026-08-22.1`, recorded verification `2026-08-22`. Their legal descriptions, fee figures and timing rules are deliberately not presented here, except the §17(1) BMG registration deadline the backend now declares with its source.

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
