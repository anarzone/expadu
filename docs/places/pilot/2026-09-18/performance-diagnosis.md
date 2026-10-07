# Pilot place-detail performance diagnosis

Date: 2026-09-18  
Database: local disposable restore `expadu_pilot_20260918_a` only

## Finding

The regression is caused by PostgreSQL JIT compilation of the access-policy SQL inside the destination-component lookup, not by loading the selected place's observation row.

After the 100 OSM observations were recorded, park and sports-centre details call `PlacesController::activitiesForDestinations()`, which calls `DestinationGrouping::components()`. `eligible()` embeds `PlaceFacts::publiclyRecommendable()` for both the component and its destination. That policy expands to repeated correlated correction and latest-observation anti-joins. For a representative park (`spots.id = 4869`, Yitzhak-Rabin-Platz) with no reviewed components, the query still planned a scan/filter of 4,075 spots and compiled 295 JIT functions before returning zero rows.

`EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` for the exact component query reported:

- Planning: 37.493 ms
- Execution: 4,691.410 ms
- Result rows: 0
- Estimated total cost: approximately 870,848
- JIT functions: 295
- JIT generation/inlining/optimization/emission: 4,405.763 ms
- Top bitmap heap scan: 4,075 actual rows filtered for a zero-row result

The 4,075 rows here are an execution-plan scan count, not a current unique-destination count or coverage denominator.

The database has JIT enabled with `jit_above_cost = 100000` and optimization/inlining thresholds of `500000`. The query's inflated estimated cost crosses all three thresholds. JIT accounts for about 94% of the measured execution time.

The same unchanged query with session JIT disabled completed in 25.852 ms (planning 35.833 ms, zero rows). This isolates compilation as the dominant cost. Ordinary `ANALYZE` remains necessary after restore, but it does not solve this post-observation query shape: statistics were current when these measurements were taken.

The API evidence matches the plan. Before source observations, the 100-detail baseline p95 was about 214 ms. After observations, the slowest rows are parks and sports centres; representative elapsed times include Yitzhak-Rabin-Platz at 4,301.729 ms, Innerer Grüngürtel at 4,377.134 ms, and Westhovener Aue at 4,784.462 ms.

## Narrow fix proposal

Add a cheap raw-membership existence guard before `activitiesForDestinations()` invokes the fully reviewed `DestinationGrouping::components()` query:

1. Resolve the destination family IDs using the same destination/canonical semantics already used by `DestinationGrouping::components()`.
2. Query only whether any spot has `destination_spot_id` in that family, without applying recommendation/access policy.
3. Return an empty activities array immediately when no raw membership exists.
4. When a raw component exists, continue through the existing `components()` query unchanged so activity publication still requires all current identity, containment, access, review, and recommendation gates.

For representative park 4869, the equivalent raw-membership precheck returned `false` in 93.075 ms including application/bootstrap overhead. It prevents compiling the 295-function policy query for the common zero-component detail case. This is preferable as the first fix to globally disabling JIT or raising database-wide JIT thresholds, which would affect unrelated analytical queries and mask the expensive query shape.

This fix is deliberately scoped to the detail activity lookup. It does not change `PlaceFacts::publiclyRecommendable()`, destination eligibility, source fact resolution, or the behavior for a destination that actually has reviewed components.

## Regression contract

Add two focused feature cases around `GET /api/places/{id}`:

1. An observed park/sports-centre destination with no rows pointing to its destination family returns HTTP 200 with `activities: []`, and query capture proves no component-policy query containing the `activity_destination`/`reviewed_destination` joins ran.
2. A destination with a valid reviewed component still returns that component activity and does execute the existing reviewed eligibility path. A restricted, stale-parent, or otherwise ineligible component remains absent.

Avoid a wall-clock assertion in the ordinary test suite. The meaningful contract is that the expensive policy query is skipped when raw component membership is empty, while the policy remains mandatory when candidates exist. For the restored pilot performance gate, rerun the same 100 real-kernel detail harness and require p95 to return near the pre-observation baseline rather than merely checking functional correctness.

## Boundaries observed

All diagnosis queries ran against the local disposable restored database with a 60-second statement timeout. No application code, live database, staging database, identities, facts, media, or user data were changed during this diagnosis.

## Fix verification

The narrow guard was implemented in `DestinationGrouping::hasComponentCandidates()` and is called by the place API before building the reviewed component query. The destination-family matching is the same raw `destination_spot_id`/canonical-ID subquery used by `components()`. The existing component policy query is unchanged.

The focused destination grouping suite passed 26 tests and 96 assertions on PHP 8.4.23 against `exp72_testing`. Its API-level regressions prove that a destination with no raw candidates does not issue SQL containing the `reviewed_destination` policy join, while a valid reviewed child remains visible and restricted or stale-containment children remain held.

The same frozen 100-place real-kernel harness then ran read-only against `expadu_pilot_20260918_a` and wrote `after-performance-fix.json`. All 100 responses were HTTP 200. Detail elapsed time was 16.625 ms median, 21.044 ms p95, and 89.508 ms maximum, compared with the post-observation p95 of 4,377 ms that triggered this diagnosis.

This result covers the frozen 100 root details, whose destination activity lookups have no raw component candidates. Destinations that do have any raw candidate still run the full expensive policy SQL, including when every candidate is ultimately restricted or stale. Coarse discovery and other callers of `DestinationGrouping::eligible()` are also unchanged. A future optimization could first collect a tightly bounded set of raw component IDs and apply the existing policy only to those IDs, but that needs its own plan evidence and regression coverage; the current result does not establish catalogue-wide performance readiness.
