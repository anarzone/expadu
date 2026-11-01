# Places source corrections and retained plans — 30 September 2026

Refs EXP-69, EXP-72. Continues the earlier [production-copy rehearsal](../2026-09-30-rehearsal/REPORT.md)
in the same isolated lab. [Draft PR #61](https://github.com/anarzone/expadu/pull/61)
contains the application changes. This checkpoint does not authorize a live import,
legacy hold, merge or deployment.

The 103 older source-backed candidates have now been classified and rehearsed:
**76 source updates and 27 evidence holds**. The updates include 11 corrected names
and three category changes, and refresh current coordinates and practical tags.
The original source IDs remain; no place identities were guessed or merged.
Every one of the final **4,155 Composer candidates** now maps to the **4,719 prepared
source records**. This establishes tested source mapping, not complete factual
coverage or production readiness. The lab contains 12,063 stored records including
held/inactive entries; that larger number is not a usable-place count.

## Problems fixed in the application

- Held or inaccessible place details now carry an explicit unavailable-for-
  recommendations state. The existing detail surface shows a compact notice and
  retains feedback controls. It does not show routing, price, open-now or enrichment
  advice. A hold is never labelled as proof that a place is closed.
- Saved Today plans retain their original cached references but display a review
  notice instead of stale visits. The trusted recommendation read still rejects
  unsafe plans. Restoring place eligibility restores the valid display. Older
  snapshots without enough constraints also get the notice.
- Independent review found and reproduced a family-plan bug: a cafe reclassified
  as a bar or coworking space could remain in a saved kids plan. The existing hard
  companion restriction now runs in shared discovery/revalidation. Both changed
  categories are rejected on Today and on save; changing back restores validity.
  Intentional itinerary category complements remain supported.
- The update action uses the retained prompt to start an updated Composer plan.
  Its copy no longer promises a retained-slot editor that does not exist.

## Data and API evidence

The source update and hold run in one lab transaction with a durable pre-operation
anchor. Apply replay, individual recovery, full original-field/eligibility recovery
and repeat recovery passed. All 12,063 pre-correction rows were restored in the
recovery trial; the outer rollback then restored the corrected lab exactly. Private
rows, credentials and recovery material remain on their server.

The final candidate passed exhaustive Composer membership and all 4,719 source fact
checks. Ten listing/nearby GET routes, 25 category detail samples and 20 held-reference
category samples were checked through the real Laravel HTTP kernel. Synthetic saved
plans retained their caches and displayed no stale slots. No real user records were
copied; live routing and full conversational LLM behaviour were not tested. The
user requested API verification instead of manual browser inspection.

| Count | Meaning in the corrected isolated lab |
|---|---|
| 12,063 | Stored records, including held/inactive entries |
| 4,719 | Prepared source records with checked facts and identity mapping |
| 4,155 | Eligible, unique, exhaustively retrieved Composer candidates |
| 564 | Prepared records still ineligible; existing conservative rules remain |
| 27 | Newly held older source records; reasons overlap |
| 4,680 | Earlier unknown-origin legacy hold, still lab-only and recoverable |
| 65 | Eligible places with checked photos in the rollback-only media trial |

The 27 holds include missing or short names, unsupported current categories,
lifecycle tags, unsupported conditional fees, explicit closed-hours values and
three source IDs not returned. None of these is an automatic permanent-closure
decision. The conservative rules can hold real venues, including conditional-fee
landmarks; resolving those needs more specific evidence/logic.

The current API totals include 3,346 food/drink places, 229 culture entries, 135
parks, 15 pitches, nine courts and 142 playgrounds. These are application filter
totals, not a claim of citywide category coverage. Unknown facts remain explicit:
4,074 candidates have unknown cost, 4,033 unknown access and 2,726 unknown hours.
The strict free/public football search still yields zero within three kilometres
of the tested central-Cologne origin. Unknown access or price was not promoted to
public/free to increase coverage.

## Media and release limits

The source-link check again matched 73 existing photo associations. All passed fresh
licence/provenance and image health checks, and the selector served the exact checked
asset. Following the 27 evidence holds, **65 are eligible: 1.56% of 4,155**. The earlier
69/4,182 figure belongs to the previous checkpoint. This is recoverable existing
coverage, not newly collected broad coverage. The trial rolled back; persistent
approved-match photo coverage under the new policy remains zero outside the trial.
Stadt Köln origins remain excluded. The earlier 75% photo objective remains unmet.

Before live release, the substantial legacy sports/playground reduction needs an
explicit product decision or further provenance work. The proved photo matches need
a guarded target-specific operation and durable recovery. Then refresh drift checks,
capture and verify a backup, and obtain approval for the exact production deployment
and import. EXP-69/72 remain In Progress and PR #61 remains draft.

## Decisions kept explicit

1. Missing source output or lifecycle differences mean uncertainty, not automatic
   closure. Cost: some valid places remain withheld pending evidence.
2. Preserve unsafe saved references separately from recommendation output. Cost:
   the old itinerary cannot be used until replanned or its evidence becomes valid.
3. Follow current project rules and preserve unrelated primary-checkout edits.
   Cost: this branch deliberately excludes unrelated ongoing product work.
4. `needs_review` is a display state, not catalogue approval or automatic replanning.
   Cost: a new plan requires the existing Composer action.
5. Correct the misleading review copy without building a retained-slot editor.
   Cost: users start an updated plan from the saved prompt.

Production deployment/acceptance, browser appearance and retained event/appointment
refresh were outside this correction's verification. No unresolved code-review
finding is being used to claim those areas complete.

## Receipts

- [Source preparation](source-preparation-summary.json), [application](source-corrections-summary.json),
  [recovery](source-corrections-recovery-summary.json).
- [Final API/Composer checks](corrected-consumer-summary.json), [held references](corrected-held-reference-summary.json).
- [Photo association check](media-link-summary.json), [rights and health trial](media-rehearsal-summary.json).
- [Production preservation](live-preservation-summary.json), [private archive receipt](preservation-summary.json).
- [Plan and review decisions](PLAN.md), [installed code hashes](candidate-code-manifest.json).

Final commit: `f779ce52e8d4f4363df0d6730d60f0c207e5ae8f`. Normal hooks passed
1,656 tests / 6,923 assertions, formatting and secret scanning. Typecheck, lint and
frontend build passed. [CI run 36694619435](https://github.com/anarzone/expadu/actions/runs/36694619435)
passed Lint, Test and Browser Tests for that exact head. Image builds and both
deployments were skipped. See [status](STATUS.json) and [CI receipt](ci-summary.json).
