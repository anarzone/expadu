# Places release continuation — 28 September 2026

Refs EXP-69, EXP-70, EXP-72. Continues the completed application implementation
in PR #57 at `a18f5c31c2b65fc131f3fb814984ff6646ae1bc7`.

## Staging release

1. Completed: verify current branch, running image and health endpoint. Staging
   now runs the application from merge `f81e2d3bfb597aec7050a3b87f72ed51a8b29cd4`.
   Six deployed implementation files match the tested source; `/up` returns 200.
   The existing container probe targets `/` and returns 404; distinguish that
   probe configuration from application availability.
2. Reconcile all 4,643 immutable package entries against current staging rows.
   Every update must match its expected row fingerprint; every new source
   identity must still be absent. Do not force mismatches or transfer raw
   staging records. Automatic approval review rejected both the raw export and
   the proposed aggregate-only check; explicit owner approval for the latter
   is pending. A renewed attempt following the owner's “go” was rejected before
   execution because automatic review still required specific authorization for
   inspection and summary export. The exact-scope approval question remains
   pending; do not retry through another execution path. See `approval-status.json`.
3. Completed: release the tested application to staging through its normal CI
   deployment. Run `36470573038` passed application tests, browser tests, lint,
   image build and staging deployment. Verified the resulting image and `/up`.
   Preserve production and the existing unrelated worktree files.
4. Run a server-side transaction-only rehearsal of the full package, retaining
   detailed backup/evidence on the server. Verify expected identities, every
   shared fact contract, saved references, source refresh and exact rollback.
   The prepared `../rehearse-staging-package.php` pins the frozen input, importer,
   manifest and expected fingerprints. It has no commit mode. It compares
   ordinary/activity visibility and independently expected Composer identities,
   exercises Places APIs with a non-persisted synthetic user, repeats the import,
   and verifies exact table restoration while retaining transaction locks.
   Existing user accounts/plans are not loaded; feedback lookups are restricted
   to the synthetic ID. Error reporting is confined to the execution environment.
   Local verification does not authorize or establish a staging run.
   Outcome: all 4,643 records, 4,084 Composer identities, eight category endpoints,
   28 details, exact replay and rollback passed without new qualifications.
   Run database-heavy checks sequentially; overlapping local test setup exhausted
   shared-memory/lock capacity. The [29 September local diagnostic](../2026-09-29/REPORT.md)
   identified JIT compilation as the dominant measured query cost; fresh statistics
   alone did not resolve it. No runtime remedy has been adopted. Measure default
   target behavior and resolve any repeatable regression before promotion claims.
5. Promote a small, deterministic canary only after the rehearsal passes,
   then the rest of the unchanged manifest. Report new versus refreshed places,
   actual consumer/Composer eligibility, holds, source dates and approved media.
   Proposed qualifications are separate audited decisions, not automatic
   eligibility changes hidden in an import.
6. Verify live APIs with synthetic input and repeat-import stability. Keep
   private users and plans inside the target environment. Production requires
   a separate reconciled manifest and review of the concrete staging result.

## Useful-coverage work while staging permission is pending

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
