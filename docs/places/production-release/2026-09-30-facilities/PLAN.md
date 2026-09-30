# Held facility recovery implementation plan

> Use superpowers:executing-plans inline. Continue without approval checkpoints for
> authorized research, isolated data rehearsal, fixes and existing draft-PR updates.

**Goal:** Recover useful source-backed facilities for explicit Places/Composer
requests without inventing access, prices, names, entrances or identity merges.

**Architecture:** Recheck the existing 559 descriptive source records against
current OSM objects and full way-node geometry. Compare exact source identities,
practical tags, coordinates, existing reviews and possible duplicate candidates.
Use native reviewed eligibility only where a real consumer can retrieve the result.
Retain excluded source keys with reasons. Keep application changes separate from
data evidence and use regression tests before any changed consumer behavior.

**Tech stack:** Existing Laravel 13/PHP 8.4, PostgreSQL/PostGIS, Composer repository,
OSM read API and Python evidence tooling. No UI work.

**Spec:** User asks to continue recovering the roughly 12k inventory for friendly
Places and Composer use. Root AGENTS.md and the existing production release runbook
govern trust and deployment. Earlier photo/saved-reference work is complete and
must not be repeated. Candidate begins at 75b2e6f8, PR61 remains draft.

## Global constraints

- Preserve the dirty primary checkout and work in the existing candidate worktree.
- Keep live production/staging data unchanged. Use the existing isolated role,
  database and copied catalogue; exports contain aggregates and public source keys.
- Do not re-enable 4,680 legacy records in bulk or guess merges from proximity.
- Unknown fee is not free. Unknown/restricted access is not public. Source centres
  are not verified entrances. Descriptive names are not official/source names.
- Stadt Köln media exclusions remain; photos are not an eligibility prerequisite.
- Use real API/native consumer verification, as requested by the user.
- A qualification must produce useful reachable behavior; by-ID retrieval alone
  must not be advertised as discovery coverage.

## Tasks

### Task 1: Current source and geometry evidence

- [x] Save only the 559 existing public source-package records as the research input.
- [x] Fetch current nodes/ways and all required geometry nodes in bounded reads
  of at most 400 IDs. Strip contributor identity. Preserve response hashes.
- [x] Compare complete tags, visibility, supported category and coordinates to the
  prepared package. Recompute each original map-point method from fresh visible nodes. For areas,
  use a representative point inside the polygon; compare bounding-box centres
  only as a diagnostic. Missing/invalid geometry, changed tags or more than one
  metre drift with the same method requires review.
- [x] Write one outcome per source key plus an aggregate summary. Expected: exactly
  559 outcomes, no database change, no invented facts and explicit provider failures.

Files: `check-sources.py`, `fetch-sources-server.py`, `source-summary.json` in this
directory. Detailed responses stay in the existing private server lab directory.
Interface: JSON `records` keyed by OSM source ID with current element, tag equality,
distance, geometry type, evidence hashes and qualification screening reason.

### Task 2: Resolve real consumer/review eligibility

- [x] Inspect all fresh-source outcomes against the isolated stored records, native
  facts, existing corrections, canonical/destination/parent links and overlapping
  source-backed identities. Export reason counts and exact public source keys.
- [x] Rehearse native qualification for supported explicit activity requests using
  `ReviewPlaceFacts::preview/apply`; verify unknown fee remains unknown and strict
  free constraints exclude it. General recommendation flags stay unchanged.
- [x] Check explicit playground/category behavior rather than invent sport tags.
  If a missing consumer path is confirmed, record a focused design ruling, write
  its failing regression and implement the smallest consistent fix before proceeding.
- [x] Prepare durable, target-bound apply/recovery only for reviewed outcomes.
  Assert native replay, source/review drift refusal and atomic failure semantics.
  Keep source facts, reviews and counts distinct throughout.

Interface: exact source/spot mapping plus original fingerprints, fresh evidence,
native previews and observed before/after API/Composer membership. A dry-run result
does not authorize a live data operation. Expected: zero unsupported claims and
every admitted facility reachable by the intended request.

### Task 3: Rehearse, review and publish evidence

- [x] Test every admitted record through shared facts and Composer retrieval;
  exercise real API routes for each supported intent and exact place details.
- [x] Test loss of source/eligibility, changed parents, duplicate identity hints,
  unknown cost/access and repeat execution; recover the isolated operation while
  retaining required audit history and preserving original IDs/references.
- [ ] One independent review, one regression-backed fix pass if needed. Normal
  hooks/CI for code changes, then update PR61, EXP69/72 and existing work-log pages.
- [ ] Publish stored, source-reviewed, general-discovery and actual activity-search
  counts separately. Record the next unresolved legacy cohorts, not a blanket
  production-ready claim. Live rollout remains a separate approved action.

## Review focus

Fresh geometry references which changed after the source object was fetched;
reviews invalidated by later source observations; duplicate source/legacy identity;
playgrounds lacking a sport tag; unknown fee falling back to legacy price ranges;
recovery after unrelated review edits; activity-only records leaking into general
Today browsing; source counts mistaken for reachable Composer results.

## Progress and rulings

- Existing skill review: activity intent requires explicitly public known access.
  Unknown fee can remain unknown for an unconstrained budget, but free is excluded.
  Legacy price-range fallback needs a specific check. Only 90 of the 559 prepared
  rows currently report public access; 74 of these are playgrounds without sport.
- Ruling: complete current-source evidence for all 559 before choosing a cohort.
  Do not bulk-qualify rows just because they have source IDs or inflate readiness
  with qualifications that no discovery request can retrieve.

- Confirmed consumer gap: Places' existing fine `activity` query uses
  `DestinationGrouping::eligible()` without its activity-qualified option;
  Composer includes qualified facilities only for sport intent, not explicit
  playground/facility category requests. Thus a reviewed playground is reachable
  by ID but cannot be discovered for a playground request. General browsing must
  still exclude descriptive facilities. A focused regression/fix is warranted.

- Task 1 verified: all 559 identities, current tags and original representative
  points match. The 73 bounding-box differences were different centre methods,
  not moved facilities. All 90 public-access rows pass the target checks; 469
  remain held for unknown access. None has a known fee or a legacy price range.
- Task 2 ruling: an explicit fine facility category is a valid discovery intent
  alongside a sport request. General browsing and broad park selection retain
  their existing policy. Category discovery preserves native access/fee facts;
  sport requests continue to require supported sport and public access. This
  recovers playgrounds without inventing a playground sport tag. If wrong, the
  category intent can be narrowed without changing any source facts.

- Task 2/3 verification: 90 focused tests / 393 assertions passed. All 90
  qualifications passed actual API detail, map/category and nearby Composer
  discovery plus strict-free exclusion. Native recovery retained 180 audit rows
  in the trial; outer rollback restored all source/place/review/journal rows.
- Follow-on source identity screening: all 4,680 legacy records compared with
  7,712 supported public OSM records. 556 bidirectional candidates already have
  a source-backed counterpart. They require fresh source/identity review; they
  are potential duplicate links, not 556 additional unique places. The other
  4,124 remain unresolved (2,771 nearby-only, 1,353 no nearby supported source).

- Final independent review: three Important findings, fixed in one pass after
  nine failing regressions. Minor batch-fixture masking was regraded Important
  and corrected so the intended second-record safeguards are exercised. The
  focused and related trust suite passed 201 tests / 1,035 assertions. Later
  access correction history now invalidates a qualification, including withdrawn
  corrections; native qualification requires known unconditional public access.
  Full hooks, CI and final evidence publication remain in Task 3.
