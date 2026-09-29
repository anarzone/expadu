# Places release continuation — 29 September 2026

Refs EXP-69, EXP-70, EXP-72. Continues the [28 September release report](../2026-09-28/REPORT.md).

**The approved upload and full staging rehearsal are complete. The catalogue batch is not yet committed.**
All 4,643 prepared records passed the native staging check: 1,874 additions and
2,769 refreshes inside the rollback transaction. Places and Composer use the same
facts; 4,084 identities were retrieved by Composer and 559 remain outside automatic
recommendations. Existing ordinary and activity visibility had zero losses. **The corrected target
performance rehearsal also passed** at 13:12 UTC: expanded food/drink API p95
470/487 ms versus deployed current-catalogue baseline 1,055/1,694 ms. The source
fix is uploaded in PR #58; application release verification remains outstanding.

The user authorized the prepared upload, private server-only snapshot and
rollback-only rehearsal with “go ahead upload and continue your work”. The earlier
automatic-review rejection is resolved. No repeat approval is required for that
scope. Committed catalogue imports and production changes have not been performed.

## Verified on staging

- All 4,643 identities received name, source, category, coordinate, practical-fact
  and uncertainty checks. No missing fee was promoted into a free-price claim.
- Eight category listing endpoints and 28 representative detail endpoints passed.
  Existing IDs, grouping, media references, unrelated place rows, source history
  and synthetic saved references were preserved. A repeat import made no changes.
- Rollback and comparisons under retained table locks passed for all nine catalogue
  tables. Sequence allocations are nontransactional and were not reset.
- The running image and eight application implementation files match the reviewed
  staging release. The latest prior documentation CI run 36551042470 passed.
- No private user accounts or plans were loaded. Raw records and internal review
  metadata stay on the staging server; local evidence contains summaries only.

Evidence: [native result](staging-native-rehearsal.json),
[runtime verification](staging-runtime.json), [approval state](approval-status.json),
[rehearsal scope](rehearsal-preparation.json).

A fresh read after rollback confirmed all expected 2,769 old identities still
match and all 1,874 new source identities remain absent. Staging still has 9,928
stored place rows. This is not a count of unique eligible or photographed places.
The seven non-media tables also match the immediate pre-rehearsal snapshot.
After the rehearsal released its locks, 125 media assets and two attachments
changed maintenance fields: last-seen/update times and three validation retry
counters/schedules. No rows were added or removed; image URLs, rights, health states and
attachment links were unchanged. This is consistent with independent media maintenance, rather than a
committed Places import; the originating background job was not traced.

Evidence: [fresh identity comparison](reconciliation-after-native.json),
[snapshot comparison](post-native-snapshot-comparison.json),
[media change classification](post-native-media-change-summary.json).

The original verifier set its array cache after providers booted. Its 36 synthetic
GET requests may therefore have left short-lived rate-limiter counters in the
configured cache. The nine-table rollback does not cover cache state. The new
performance verifier sets and asserts isolated caches before provider startup.

## Target performance

The original target performance check completed and **failed the release speed gate**:

| Staging condition | Food/drink page 1 p95 | Page 2 p95 |
|---|---:|---:|
| Deployed application, current catalogue | 1,484.8 ms | 1,026.4 ms |
| Deployed application, expanded catalogue | 18,202.7 ms | 16,665.2 ms |
| Expanded catalogue, transaction-only JIT diagnostic | 3,123.7 ms | 3,238.0 ms |

Each case uses 30 sequential Laravel HTTP-kernel reads, middleware included, with
a fixed request time and a synthetic user without an origin. These exclude the
external network round trip. Payload stability, pagination, nine-table rollback
and statistics cleanup passed. The verifier's `status: passed` means its checks
completed; the separate p95 gate failed. No database-wide setting changed, and
JIT disabling was diagnostic only. [Measured result](staging-performance.json).

Planner statistics are refreshed for comparable conditions and again against the
restored baseline after rollback. A separate bounded cleanup also runs after the
main process, including timeout. Statistics, cumulative analyze counters and
sequence allocations can change despite catalogue rollback.
[Cleanup result](staging-performance-cleanup.json),
[verifier review](staging-performance-review.json).

Two narrow query simplifications remove redundant parent checks from ordinary
destination pagination and explicitly limit component matching to records that
have a destination. The latter is already required by the existing inner join.
Canonical, active, recommendation, effective-access and reviewed-containment
checks remain enforced. Explicit activity uses the full eligibility policy.
Both regressions failed on the previous queries; **all 30 grouping tests pass
with 116 assertions** after the changes. Independent final review found no
correctness issue. The full fast application suite also passed: **1,683 tests and 7,034 assertions**
in 314.23 seconds with ten parallel workers and no skips.
[Review and tests](query-simplification-review.json).

A controlled local comparison of the first simplification reduced the count/page
SQL executions from 6,688/6,182 ms to 1,217/1,267 ms, with identical results and
rollback. This excludes the second change and is neither full API latency nor
p95. [Local comparison](local-query-simplification.json).

The grouping-only candidate completed on staging at 12:07 UTC with the full
package and normal JIT settings. Food/drink p95 improved to **3,604.7 ms** for
page one and **2,959.9 ms** for page two, but still exceeded the current deployed
baseline (1,365.1 / 1,006.0 ms) by more than 20%. **The release speed gate remains
failed.** All eight original/candidate full API payloads were identical; pagination,
nine-table rollback, statistics cleanup and unchanged deployed-source checks passed.
Diagnostic copies ran only inside the verifier process. The query fix is not deployed.
[Candidate result](staging-candidate-performance.json),
[cleanup](staging-candidate-cleanup.json),
[runtime receipt](staging-candidate-runtime.json).

The remaining measured policy work repeats source-history scans per place. A
new synthetic regression observed 40 scans for 40 places. A batch-query
alternative is being compared with the existing access policy using temporary
local fixtures. Automatic review initially rejected replacing shared access SQL because of
possible eligibility changes. A local differential check then passed all 2,664
synthetic cases (956 allowed, 1,708 denied), and independent static review found
no semantic difference under current schema constraints. Automatic review accepted
the subsequent isolated-worktree edit on that evidence. The revised query passes
59 facts/grouping tests with 352 assertions, including the previously failing
40-place scan regression and parity for the destination alias. The full isolated
expanded catalogue preserved all 11,802 access decisions (11,271 allowed by the
access policy alone). These are not general recommendation or published counts.
That first batch-query shape still triggered expensive compilation when nested
in parent checks, so its staging diagnostic was stopped before candidate
measurements; rollback-on-exit and independent statistics cleanup completed.
The final shape retains the original bounded parent predicate and materializes
only the root allowed-ID set. A SELECT-only local baseline comparison measured
94.7 ms at estimated cost 15,518, with no JIT, versus 4,765.9 ms for the earlier
shape. This was not expanded-data API p95. The subsequent V2 staging measurement also
failed the gate: food/drink p95 is 6,132.2 / 6,895.0 ms against the same-run deployed
baseline of 1,273.8 / 1,045.1 ms. All nine full API payloads and the complete access-allowed ID set were identical.
Nine-table rollback, statistics cleanup and unchanged runtime-source hashes passed.
Keyed eligibility p95 rose from 3.0 to 1,090.9 ms; detail API p95 rose from 17.1
to 231.0 ms. These compare current baseline data against expanded candidate data.
At that checkpoint the candidate was not accepted; the subsequent timing correction and final acceptance are recorded below.
[Full V2 result](staging-batch-v2-performance.json),
[cleanup](staging-batch-v2-cleanup.json), [runtime](staging-batch-v2-runtime.json). No live access policy has changed.
[Equivalence proof](access-policy-equivalence.json), [review](access-policy-review.json),
[full local comparison](batch-access-local-comparison.json),
[bounded-parent query plans](bounded-parent-plan-comparison.json),
[stopped diagnostic cleanup](staging-batch-candidate-aborted.json).

The final source implementation passed the full local suite: **1,684 tests, 7,039
assertions, no skips**, with normal secret, formatting and commit-message hooks.
That functional result alone did not override the then-failed target performance gate.
[Test receipt](final-query-test-result.json).

## Rehearsal timing correction

Review found that the native importer creates 4,643 nested Laravel transactions
inside the rehearsal's caller transaction. Successful nested commits decrement
Laravel's counter but leave their PostgreSQL savepoints active. The expanded
local plan had no JIT and only shared-buffer hits, yet a simple 11,802-row scan
took around 200–229 ms. Those open transaction states inflated the read timings.

A controlled 3,000-row temporary-table experiment measured scans of 41.6–55.5 ms
with retained child savepoints versus 1.4–4.3 ms after releasing completed children.
Both variants produced the same count/sum and fully rolled back. Separate checks
proved successful children and an injected child failure preserve both caller
rollback boundaries. PostgreSQL documents that [releasing a savepoint](https://www.postgresql.org/docs/16/sql-release-savepoint.html)
merges its changes into the parent transaction; it does not commit that parent.
[Probe](savepoint-probe.json), [rollback guards](savepoint-rollback-guards.json).

The exact native package was then committed **only to a separate local, zero-user
public fixture**, and measured through a fresh read-only connection. It contains
11,802 stored rows, not 11,802 eligible destinations. The same revised SQL fell
from 1,507/1,657 ms during the open import to 169/163 ms after its commit. This
supports an open-import-state effect; it does not isolate subtransaction overflow
from all tuple-visibility and hint-bit effects. Independent imports have different
allocated new IDs and write timestamps, so this comparison checks query/binding,
count and baseline page-identity parity, not cross-fixture full-row hashes.
[Fixture receipt](public-query-lab.json), [fresh-read result](committed-public-fixture-performance.json).

On that **same completed local fixture**, the original deployed SQL and revised
SQL returned exactly identical full results. Original count/page executions took
7,698/6,589 ms, including 7,046/6,486 ms of JIT work. Revised executions took
144/149 ms with no JIT and unchanged settings. These are individual SQL executions,
not API p95 or a staging acceptance result. [Direct comparison](durable-query-comparison.json).

V3 releases successful import-child savepoints while retaining both caller rollback
boundaries. It changes only verifier transaction-resource handling. The full local
4,643-record run, all nine API comparisons, all access IDs, 4,643 child releases,
exact nine-table rollback and statistics cleanup passed. That local application
already contains the candidate, so its inherited `deployed_baseline` key is a local
candidate baseline, not evidence of deployed-original parity. The reviewed V3
staging measurement passed at 13:12 UTC. Food/drink page-one/page-two p95 was
470.2/487.0 ms against the same-run deployed current-data baseline of
1,054.7/1,694.4 ms. All nine full API payloads and all access-allowed IDs matched
the original code on the same expanded data. Exactly 4,643 child savepoints were
released, both caller rollback boundaries remained, all nine table digests were
restored, cleanup passed, and running application hashes stayed unchanged.
[Staging result](staging-batch-v3-performance.json),
[cleanup](staging-batch-v3-cleanup.json), [runtime](staging-batch-v3-runtime.json). The p95 gate here applies to the two listing pages.
Narrow reads remain a tradeoff: keyed eligibility median/p95 increased from
1.46/2.36 ms on the current deployed data to 55.33/126.89 ms for the expanded
candidate; detail API median/p95 increased from 12.99/16.07 to 34.91/84.73 ms.
The committed local fixture measured keyed p95 39.30 ms and detail p95 22.06 ms.
These are different environments and transaction states, not interchangeable
results. Final independent review recommends adopting the fix, with this tradeoff recorded.
There is no direct production keyed-exists caller. Candidate hydration and grouping
can repeat the policy per batch, including twice per 64-ID Composer candidate batch;
Composer/context latency remains a focused follow-up. The tested food-place detail
path does not invoke eligibility, so that timing difference does not establish a
policy-caused detail regression. The broad listing gate must not be described as a
pass for every narrow lookup. [Adoption review](final-adoption-review.json). Earlier failed measurements remain above and must
not be represented as durable production timings. [Local validation](local-v3-rehearsal.json),
[review](batch-v3-verifier-review.json), [executed verifier](verification/batch-v3/candidate-performance.php).

## Recovery and the next batch

A private nine-table snapshot was saved before the native rehearsal. Its initial
serialization roundtrip passed, but review found that decoding database JSON into
PHP arrays can conflate an empty object with an empty list. That first snapshot
is not accepted as evidence of exact typed-row recovery. A replacement preserves
each PostgreSQL column's native text, including JSON containers, precise numeric
values and spatial values. Its temporary-table restore check **passed on staging at 11:43 UTC** for all
nine tables, including 9,928 places and 4,085 observations. Every reconstructed
column value matched exactly; the temporary tables were removed afterward.
[Snapshot summary](column-snapshot-summary.json),
[restore result](snapshot-restore-summary.json),
[helper review](snapshot-helper-review.json). No snapshot will overwrite live
shared media tables or other concurrent changes.

The [proposed 100-record canary](canary-manifest.json) and its
[guarded release procedure](CANARY-RELEASE.md) contains 50 additions and
50 refreshes across all 24 categories: 97 OSM and three Overture records, with 87
source names and 13 descriptive facility names. Selection is deterministic and
references unchanged rows from the full frozen package. Descriptive labels do not
constitute new recommendation qualifications. This canary has not been imported.

Before committing it, release and verify the tested query fix, finish recovery
checks, reconcile its exact
identities under locks, and review the concrete commit and recovery procedure.
Production requires its own current mapping and acceptance evidence. Retain the
other 4,543 package records unchanged for the subsequent batch.

## Local performance finding

A controlled check reproduced a multi-second slowdown in the expanded local
catalogue, but not the exact earlier 23-second observation. The diagnostic used
the same immutable package and native importer inside a transaction on the
existing isolated public-data copy. No real user accounts or plans were loaded.
It captured the actual food-and-drink first-page and total-count queries from the
Places controller, without an origin and with the synthetic user's empty feedback.

| Local expanded-data condition | Count query | First-page query |
|---|---:|---:|
| Existing statistics, default JIT | 6,200 ms | 5,801 ms |
| Fresh statistics, default JIT | 5,329 ms | 5,465 ms |
| Fresh statistics, transaction-local JIT disabled | 572 ms | 558 ms |

`EXPLAIN ANALYZE` attributed 4.75–5.39 seconds per default query to JIT compilation,
mostly optimization and emission. Updated statistics alone did not remove the
slowdown. The query's estimated cost crossed the threshold that enabled expensive
JIT optimization; the smaller baseline used less costly compilation. This points
to compilation overhead as the dominant measured cost for these two local queries.

The results of each query were exactly identical across all three variants,
verified by checksums. Rollback restored all nine checked tables exactly, including
9,928 baseline places and 4,085 observations. Sequence allocations are not rolled
back. `jit=off` was set only inside the local diagnostic transaction; no persistent
configuration or runtime application code was changed. The frozen package hash is
unchanged.

The baseline controller samples were 1,488 ms for food/drink, 519 ms for pitches
and 661 ms for playgrounds. These include controller/resource processing but not
an external HTTP round trip. The expanded-data table above measures individual
SQL statements, not full API latency. Neither set is p95 or a staging measurement.
The import itself took approximately 415 seconds locally.

Evidence: [measured summary](performance-diagnostic.json). Full query plans and
reproduction scripts are retained locally under
`storage/app/private/places-research/performance-2026-09-29/`; their checksums are
recorded in the summary. They contain the controlled local diagnostic, not a
fresh staging export.

## Remaining acceptance work

1. Target query/API performance and equivalence now pass. Complete final evidence
   CI and verify the adopted application fix in the staging release.
2. Typed snapshot reconstruction passed; finalize a guarded, batch-specific recovery procedure.
   Temporary-table reconstruction does not exercise external foreign keys,
   application triggers, user references or full-database disaster recovery.
3. Review and authorize the concrete committed staging canary, then check its live
   APIs and refresh stability before the remaining package.
4. Continue the source-evidence work separately. The package adds no approved
   photos and establishes no verified free/public football result. Its 46 explicit
   free candidates are not 46 free football locations. The 10,234 other held source
   candidates and the 75% photo objective remain open.

EXP-69 and EXP-72 remain In Progress. Stadt Köln structured place data and its place
media remain excluded under EXP-70. No new media approvals or activity qualifications
were applied. Photos remain optional in the data contract. The separate Places-page direction is
under review in the 28 September connected design record; preserve the prototype.
See the [data/interface assessment](UI-DATA-ASSESSMENT.md).
