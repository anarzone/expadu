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
