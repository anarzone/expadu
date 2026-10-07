# Whole-branch review and disposition

Reviewed range: `89289db9641bb75a563e74b44be9b4717bd61b22..f3ebca21e1e723bb29f5aa7445dab6e396b618fc`.
One fresh native gpt-6-astra reviewer, read-only; no nested reviewers and no repeat review. Findings were graded by user effect.

The reviewer confirmed shared policy, atomic combined reviews, mixed-category isolation, preservation of staging source withdrawal/design/bureaucracy/media gates, and the intended schedule/startup gates. It independently ran the aggregate evidence verifier, but did not repeat remote execution or the full application suite.

## Important finding and one fix pass

Legacy activity qualifications omitted `access_correction_id`. The new read predicate coalesced that omission to zero and could admit an old qualification made without known unconditional public access. New-write validation did not close this upgrade path.

Seven regressions failed first (10 assertions, 5.32s): unavailable details, Places category, map category, Composer by-ID and category discovery, and missing/null binding even with public source tags. Removing the null-to-zero fallback on the stored binding makes those old approvals require a fresh review. Current reviews explicitly write integer zero when public source access has no correction history.

Focused consumer/facts/withdrawal/recovery suite: 160 passed / 805 assertions / 59.40s. Normal fix commit `540416f3720b84d6ebc172bde6a3ad0ede46aefd` passed secret scanning, formatting and the full fast suite: 1,754 passed, 2 skipped / 7,349 assertions / 250.63s, 10 processes. No second review or additional fix pass.

Critical: 0. Important: 1, fixed. Minor: 0. Deferred minors: none.

## Behaviors the reviewer declined to judge

1. Frontend presentation of recommendation_status, explicitly outside this backend port.
2. Authenticated public-server behavior and deployment acceptance; no deployment is included.
3. Photo coverage, useful free-football coverage or pending facility approvals, requiring separate data/media work.
4. Blocking direct manual seed/import commands, explicitly preserved for reviewed runs.
5. Returning historical alias IDs instead of existing canonical route binding, which predates this patch.
6. Independently certifying remote probes or full-suite execution, observed by the executor rather than repeated by the reviewer.

Every disposition and its cost is preserved in `execution-decisions.md`. The review verdict was **With fixes**; the single Important finding is now covered RED to GREEN and the complete suite is green. This does not authorize merge/deployment or establish full catalogue/media production readiness.
