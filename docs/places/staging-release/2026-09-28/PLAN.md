# Places release continuation — 28 September 2026

Refs EXP-69, EXP-70, EXP-72. Continues the completed application implementation
in PR #57 at `a18f5c31c2b65fc131f3fb814984ff6646ae1bc7`.

## Staging release

1. Completed: verify current branch, running image and health endpoint. Staging
   now runs the application from merge `f81e2d3bfb597aec7050a3b87f72ed51a8b29cd4`.
   Six deployed implementation files match the tested source; `/up` returns 200.
   The existing container probe targets `/` and returns 404; distinguish that
   probe configuration from application availability.
2. Completed on 29 September at 09:35:37 UTC: the specifically approved read-only
   comparison checked all 4,643 immutable package entries. All 2,769 existing
   fingerprints match; all 1,874 new source identities remain absent; zero
   conflicts. Raw records stayed on the server. See
   [current comparison and approval evidence](../2026-09-29/REPORT.md).
   Earlier automatic-review rejections in `approval-status.json` are historical;
   the owner has now explicitly authorized and completed this check.
3. Completed: release the tested application to staging through its normal CI
   deployment. Run `36470573038` passed application tests, browser tests, lint,
   image build and staging deployment. Verified the resulting image and `/up`.
   Preserve production and the existing unrelated worktree files.
4. Completed on 29 September: the owner authorized upload, a private server-only
   snapshot and the full rollback-only staging rehearsal. All 4,643 records,
   4,084 Composer identities, eight category endpoints, 28 details, preserved
   references, no visibility losses, exact replay and table rollback passed.
   No catalogue import was committed. See the [current report](../2026-09-29/REPORT.md).
   The original snapshot's normalized JSON is not accepted as typed recovery
   evidence; a native-column-text replacement and temporary-table restore are
   complete: all nine tables reconstructed with exact native column values.
   The target speed test reproduced a severe food/drink slowdown:
   first-page p95 rose from 1.485 to 18.203 seconds. A narrowly scoped query
   simplification passes 30 grouping tests (116 assertions); the final two-part
   query candidate improved p95 to 3.605/2.960 seconds but still fails the gate.
   The next equivalent access-query rewrite passes 2,664 synthetic cases and
   59 facts/grouping tests; full-catalogue and staging checks continue.
   Locks, sequence gaps, cache isolation and planner-statistics cleanup are
   recorded explicitly. The earlier upload/snapshot approval blocker is resolved.
5. Promote a small, deterministic canary only after the rehearsal passes,
   then the rest of the unchanged manifest. Report new versus refreshed places,
   actual consumer/Composer eligibility, holds, source dates and approved media.
   Proposed qualifications are separate audited decisions, not automatic
   eligibility changes hidden in an import.
6. Verify live APIs with synthetic input and repeat-import stability. Keep
   private users and plans inside the target environment. Production requires
   a separate reconciled manifest and review of the concrete staging result.

## Useful-coverage work alongside release verification

1. Investigate 352 football-pitch identity holds using the already retained
   local public-data inventory and baseline. Many match source-null legacy
   records at the same point; do not simply remove the hold. Produce exact
   legacy-to-OSM proposals, reject ambiguous one-to-many pairs and preserve
   all IDs/references through the application's native reconciliation policy.
2. Rehearse qualified identity proposals in the existing isolated public-data
   database. Check canonical lookups, observation history, grouping, replay
   and rollback. Do not mutate the frozen package or report its counts as a
   new live catalogue.
   Outcome: the first 624-pair trial exposed 611 activity-visibility losses.
   Current-source name checks then reduced the review to 12 candidates. Eleven
   preserve both ordinary and activity visibility, links and saved references;
   their native replay and exact rollback passed. One remains held for visibility.
   Two cafe name changes are unresolved business-continuity cases, not safe aliases.
3. Research source-backed free/public football evidence. Retain age, booking,
   date and availability conditions. A free event does not prove unrestricted
   daily access to its venue. Do not import protected images or reintroduce
   excluded Stadt Köln media. Retain unsupported cases as explicit gaps.
   Outcome: 17 NRW-hosted listings are still published by the excluded municipal
   provider. Their free/all-age metadata is also insufficient to establish
   unrestricted access. Keep all 17 outside promotion. The preflight accepts
   zero fact reviews and makes no new football-coverage claim.
4. Record actual results in Jira and the living reference pages. A green code
   release does not close the outstanding fee/access and photo-coverage goals.

No new photograph approval or 75% coverage claim is part of this continuation.
