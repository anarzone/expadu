# Onboarding simplification — design specification

Status: **draft, awaiting owner approval.** No implementation begins until this is reviewed.
Date: 2026-08-07
Related: [`2026-08-03-bureaucracy-case-worker-design.md`](2026-08-03-bureaucracy-case-worker-design.md) (§9.1 uncovered cases), [`2026-08-04-onboarding-case-chat-design.md`](2026-08-04-onboarding-case-chat-design.md)

## 1. Problem

Onboarding asks **16 questions across 2 screens** before the user sees anything:

| Step | Component | Fields |
|---|---|---|
| 1 | `WelcomeStep` | — |
| 2 | `SituationStep` | `situation`, `is_eu`, `entry_mode`, `visa_expires_at`, `current_residence_title`, `residence_title_expires_at`, `case_goal`, `sponsor_current_title` (8) |
| 3 | `VeedelStep` | `veedel`, `arrival_planned`, `arrival_date`, `address_registration_status`, `moved_in_at`, `documented_german_level`, `has_deutschlandticket` (7) |
| 4 | `InterestsStep` | `interests` (1) |
| 5 | `ConfirmationStep` | review |

Up to **8 are mandatory** before the flow can be completed (`OnboardingRequest`): `situation`, `veedel`, `arrival_planned`, `arrival_date` (unless planning), `address_registration_status`, `moved_in_at` (if registrable), plus conditionally `is_eu` and `entry_mode`.

Two observations that shape the work:

1. **The backend contract is already permissive.** Every residence/case fact (`current_residence_title`, `residence_title_expires_at`, `case_goal`, `sponsor_current_title`, `visa_expires_at`, `documented_german_level`, `german_level`, `has_deutschlandticket`, `interests`) is already `nullable`. The weight is in the UI, not validation.
2. **Skipping is already representable.** `CaseFactStore::synchronizeConfirmedFacts($user, $facts, $source, $retireKeys)` takes a list of keys to retire, and `ApplyOnboardingAnswers` already passes every null-valued fact as a retire key. An unanswered fact is a first-class state, not a gap.

## 2. Goals

- Reduce the mandatory set to **three answers**.
- Make the residence/bureaucracy questions and interests explicitly skippable.
- Give a **reliable** way to finish skipped answers later, on the Bureaucracy page.
- Change no rule content and no legal-review state.

## 3. Non-goals

- Rule decomposition or approving rules (tracked separately; the 7 duplicate Anmeldung rules and the 83 unreviewed rules are out of scope here).
- Changing `CaseMatcher`, `CasePlanComposer`, or the trust rule that only `authoritative()` rules render as verified.
- Any change to the AI extraction path or consent.

## 4. The required core

Three answers stay mandatory, because concrete features break without them:

| Field | Why it cannot be deferred |
|---|---|
| `situation` | derives `bureaucracy_path`, drives the home feed and every branch's `applies_if` |
| `veedel` | places, commute origins, alerts (`defaultAreas`) |
| `arrival_planned` + `arrival_date` | anchors every `days_since_arrival` deadline |

Everything else becomes optional. Proposed shape:

- **Step 1 — Welcome** (unchanged)
- **Step 2 — Your situation**: `situation` only. `is_eu` stays here **only when the situation requires it** (it already renders conditionally).
- **Step 3 — Your Cologne**: `veedel` + arrival. Nothing else.
- **Step 4 — Optional extras** (skippable, one screen, clearly marked): `interests`, `has_deutschlandticket`, `documented_german_level`.
- **Step 5 — Residence details** (skippable, clearly marked): `entry_mode`, `visa_expires_at`, `current_residence_title`, `residence_title_expires_at`, `case_goal`, `sponsor_current_title`, `address_registration_status`, `moved_in_at`.
- **Step 6 — Confirmation**: review, showing skipped groups as "not answered yet — you can add these on the Bureaucracy page".

Steps 4 and 5 each carry a **Skip** control that advances without writing those fields. Skipping is not silent: the confirmation screen lists what was skipped.

## 5. What "skip" writes

Skipping a group submits those fields as `null`. `ApplyOnboardingAnswers` already converts nulls into `retireKeys`, so the facts are recorded as *unanswered* rather than guessed. Derived values follow existing behaviour:

- `bureaucracy_path` — already only set when the answers justify it; otherwise remains unknown. Skipping keeps it unknown, which is correct and already handled.
- `housing_status` — derived from `address_registration_status`; skipping yields `null`.
- `entry_mode` — profile attribute; skipping yields `null`.

No new persistence concepts are introduced.

## 6. The resume path (the load-bearing piece)

**This must ship in the same change as the simplification.** Without it, "you can finish later" is false for most users.

`QuestionSelector::select()` only asks for a fact when an **approved** rule is currently blocked on it ([QuestionSelector.php:52-72](../../../app/Bureaucracy/Cases/QuestionSelector.php)):

```php
$unknownTasks = Task::query()->authoritative()->whereIn('key', $result->unknownRuleKeys)->get();
...
if ($gatingTasks->isEmpty()) { continue; }   // no approved rule needs it → never asked
```

Only 11 rules are approved, covering family reunification and Blue Card. So a fact skipped by a student, freelancer, EU employee, standard employee, Chancenkarte or digital-nomad user would **never be asked again**. Today's "Update my answers" link points at `onboarding.url()` — a full redo — and appears in only one assistant state.

Required instead: a **deterministic "Finish your answers" card** on the Bureaucracy page that

- is driven by *which fields are unanswered*, not by rule matching, so it works for every situation;
- is always present while anything is unanswered, independent of the assistant's state;
- shows a count ("3 answers left") and the same option sets/labels as onboarding, so nothing is re-learned;
- writes one field at a time via the existing endpoints — `POST /profile/attributes` for profile attributes, `POST /bureaucracy/case/questions/{question}` for registered case facts — with no new write path;
- disappears once nothing is unanswered.

The Case assistant is unchanged and continues to ask its bounded, rule-gated question when one applies. The two coexist: the assistant asks what a rule *needs now*; the card lets the user finish what they *chose to defer*.

## 7. Consequences to accept deliberately

These are trade-offs, not defects, and each needs owner sign-off:

1. **Anmeldung has no date until answered.** `address_registration_status`/`moved_in_at` gate `days_since_move_in`. Skipping means the Anmeldung card renders with no deadline. Given the 14-day legal window, the card must state plainly that the clock cannot be shown yet and link the answer. This is the most safety-relevant skip.
2. **Fewer confident steps up front.** `entry_mode` is consumed by branch `applies_if` (`d_visa`/`visa_free`/`has_permit`). Skipping turns several branch rules from applicable into Unknown, so the first plan is shorter.
3. **"Information we still need" gets more common.** Expected and by design, but the copy must read as *add when ready*, never as *we cannot help you*.

## 8. Copy

- Skip controls: "Skip for now" (matches the assistant's existing wording).
- Skipped group on confirmation: "Not answered yet — add these any time on the Bureaucracy page."
- Resume card: "Finish your answers" / "{n} answers left — these unlock more of your verified plan."
- Undated Anmeldung: "Your 14-day registration deadline needs your move-in date. Add it to see the exact date."

## 9. Tests

- `OnboardingTest`: completing with only the three required answers succeeds; skipped facts are retired, not guessed; `bureaucracy_path` stays unknown when unjustified.
- `BureaucracyCoverageTest`: `bureaucracy:coverage --full --fail-on-gap` stays green (no rule content changes).
- Feature test: the resume card's field list is derived from unanswered fields and is non-empty for a situation with **no** approved rules (the case the assistant cannot cover) — this is the regression guard for §6.
- Browser: onboarding completes via the shortest path; the resume card appears on the Bureaucracy page and answering one field decrements it. Verified in CI (local runs of `bureaucracy.spec.ts` cannot reach the QA switcher against a locally-started server).

## 10. Sequencing

Ship **before** any rule decomposition/approval work, as a separate change:

- different layers, minimal coupling — this reduces what onboarding writes; decomposition changes rule data;
- decomposition is legal-review-bound and slow, this is not;
- one PR per concern keeps the shared browser specs and the deploy gate diagnosable.

## 11. Open decisions for owner sign-off

1. Is the three-field required core correct, or must `address_registration_status` stay mandatory given the 14-day Anmeldung window? (§4, §7.1)
2. Should residence details be one skippable step (§4, step 5) or per-question skips inside it?
3. Is a 6-step flow acceptable, or should optional extras and residence details merge into one skippable step to keep the progress bar at 5?
4. Confirm the undated-Anmeldung copy in §8 is acceptable legally.
