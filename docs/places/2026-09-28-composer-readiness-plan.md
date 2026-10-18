# Composer place readiness implementation

Spec: [people and Composer acceptance](production-readiness.md).
Refs EXP-69 and EXP-72. Base 53e3221ad195a14b5ad3868bb1044db7f07655d3.
The existing importer patch is part of this release; preserve all unrelated files.

## Task 1: Preserve hard constraints

Reproduce the complete API's free-budget relaxation with paid and unknown-fee
fixtures. Remove automatic budget relaxation and make a no-match result explicit.
Keep intentional user pins identifiable and reject an explicit budget conflict
instead of claiming that a paid pinned venue satisfies a free request.
Expected: targeted tests fail before the fix and pass after; compatible default
planning continues to work.

## Task 2: Shared practical facts and model input

Extend the native observation/fact framework for activity capabilities and
conditions, without replacing it with the older primary-checkout resolver.
Preserve sport, accessibility, surface, lighting, fee/access/booking conditions
and evidence. Resolve conflicting or conditional fees conservatively. Supply
structured facts to the ranker and Composer output from the same policy as Places.
Expected: Places, candidate, model input and slot assertions agree; missing facts
stay unknown, and a conditional or contradictory fee cannot become free.

## Task 3: Activity retrieval and qualified facilities

Add explicit sports and radius constraints to parser/API contracts and preserve
them in cache/hydration. Retrieve matching activities before result limiting,
including farther eligible rows behind a nearer ineligible group. Use an audited
activity-discovery qualification for unnamed facilities rather than ignoring an
existing recommendation exclusion. Preserve destination/child identity and
restrictions. Missing photographs never determine eligibility.
Expected: football matches soccer capability, not every pitch; reviewed unnamed
facilities are returned; unreviewed excluded records remain excluded; radius,
access and price constraints survive compose and swap.

## Task 4: Full API acceptance and release evidence

Exercise parse, compose, swaps and saved references using deterministic provider
fixtures and real database services. Cover free football, unknown/paid/private
facilities, booking/conditional access, missing hours, no-photo and grouped
facilities. Run the relevant Composer, fact, grouping, identity and importer
regressions once after the focused fixes. Inspect all failures. Perform one
independent whole-change review; fix material findings with regressions.
Expected: recorded green checks and honest remaining data/live-release gaps.
Update Jira and the living acceptance record. Commit only scoped files; prepare
reviewable release changes. Do not claim that local checks are live promotion.

## Implementation rulings

- The user's current supplied project rules supersede older worktree guidance.
- Existing native PlaceFacts observations/corrections remain authoritative.
- The frozen 4,643-record package is unchanged. This work adds application
  capability and separate qualification/promotion evidence.
- Free-only is a hard constraint. A truthful empty result is preferable to a
  paid or unknown substitute. This does not establish useful football coverage.
- Scope uses API verification, as requested. No redesign is introduced.

## Additional finding from full-package acceptance

The first native rehearsal found ~20-second catalogue reads because all eligible
records were fully resolved on each request. Add conservative search hints
using every source/review point and possible sport/free claim; use their minimum
distance only to bound work. Current resolved facts remain the admission gate.
Verify reviewed entrances/source facts cannot be lost, and measure again before
release. The original package facts/checksums and first measurements stay intact.
