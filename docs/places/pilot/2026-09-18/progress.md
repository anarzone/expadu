# EXP-72 execution ledger — 18 September 2026

Status: In Progress. All new data changes are confined to a disposable local staging restore. No new staging or production data changes, push, or deployment occurred. API validation replaces UI checks per owner instruction.

- [x] Verify staging deployment revision and capture a read-only catalogue snapshot.
- [x] Freeze 100 existing source destinations across five cohorts and nine districts, recording sampling limitations and replaced IDs.
- [x] Create a private full backup and verify its disposable restore.
- [x] Capture the same 100-place real-kernel API baseline.
- [x] Apply ten source observations, verify the canary, then apply the remaining 90. Replay keeps exactly 100 observations and preserves protected fields.
- [x] Review 30 usable photo operations and record explicit holds for the other 70 destinations.
- [x] Apply 27 media operations and refresh three existing accepted assets locally. Exact replay skips all 30, with no downloads, attachment changes or review-history changes.
- [x] Add exact media validation hosts with rights and match gates unchanged. Commit 0273e467; focused 13 tests / 53 assertions and full fast suite 1,609 tests / 6,652 assertions passed, one skipped.
- [x] Fix the empty-component detail-query slowdown. Focused 26 tests / 96 assertions pass. All 100 API responses remain identical apart from timing; p95 falls from 3,864 ms to 21 ms. The full fast suite after this change passes 1,611 tests / 6,658 assertions, with one skipped.
- [x] Review 199 component candidates across 26 roots. Ten sourced relationships proposed; none applied. Keep 81 legacy identity cases and other uncertain relationships unresolved.
- [ ] Complete grouping, coarse discovery, Composer, saved-reference and full rollback acceptance.
- [ ] Validate remaining performance paths with real component candidates.
- [ ] Push code after pending destination approval, then prepare staging deployment and freshly previewed data application.
- [ ] Validate actual running staging APIs and document owner acceptance.
- [ ] Expand free-photo coverage beyond this pilot. Most-city coverage remains unsolved.

Results: selected photos 3 → 30 of the same 100; websites 0 → 35; known access 16 → 17; known fee 10 → 11; known hours 16 → 15 because one fresh source no longer supplies hours. All 100 entrances remain unverified. Recommendation eligibility stays 98 of 100. This purposive sample does not estimate citywide coverage.

The initial media attempt rolled back in full on an existing accepted review. The corrected run preserves that review history; final exact replay is unchanged. The private raw backup stays outside the repository. Automatic approval review rejected the private GitHub push; the owner approval question is pending.

See pilot-report.md, validation-results.json and performance-diagnosis.md for measured results and remaining gates.

## 23 September delivery continuation

Resumed after the owner requested urgent completion. Latest staging workflow still runs f5a85503. Local containers restarted; original disposable pilot database is intact. No production changes.

- Reproduced Innenstadt court list: 17,644.8 ms, 57 results. Count query EXPLAIN: 7,451.5 ms execution, 7,438.3 ms JIT (578 functions); cost 718,061.43.
- Hypothesis verified on the exact captured SQL: materialize each place's latest source observations and active access corrections once. Both count and result queries return identical values; candidate query timings 735.4 / 268.4 ms.
- Existing fact/grouping baseline: 54 tests, 327 assertions passed before modification.
- Ruling: reuse the existing pilot ledger and immutable manifests rather than restart completed work. The performance repair changes SQL evaluation only; latest source, correction conflicts, parent restrictions and legacy fallback semantics remain mandatory.
- Pending: full access/grouping regression and measured API acceptance; complete membership rehearsal; municipal-source acquisition exclusion; guarded staging delivery.

- Component rehearsal completed: 44 fully paginated list requests per snapshot; ten children grouped under seven roots; fine activity coordinates retained; replay and independent reversal correct; every spot and audit row restored by outer rollback. The first attempt exhausted the CLI's 128 MiB while holding snapshots; the complete rerun used 1 GiB and succeeded. No live write.
- Municipal source regressions: five RED cases, then 28 GREEN tests / 116 assertions. Independent-photo control remains publishable. Alias metadata regression RED→GREEN, 4 tests / 9 assertions.
- Full PHP suite: 1,629 passed / 6,815 assertions, one skipped (165 seconds, four processes).
- Final review: one Important warm-media relation finding; reproduced RED, fixed owner-relation invalidation, then 20 tests / 77 assertions passed. No Critical/Minor findings.
- Final Ruling: reviewer did not certify live rollout/performance, historical missing provenance or contaminated aliases, citywide coverage, or the forthcoming staging operator. Keep these open rather than infer completion from code tests; cost is that live catalogue acceptance remains outstanding.
