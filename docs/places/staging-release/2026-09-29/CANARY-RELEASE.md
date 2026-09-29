# Guarded staging catalogue canary

Refs EXP-69 and EXP-72. This is the next release procedure, not an import receipt.
The exact public identities are in `canary-manifest.json`: 100 unchanged records
from the frozen 4,643-record package, comprising 50 additions and 50 refreshes.
The canary spans all 24 categories, with 97 OSM and three Overture source records.
It does not approve photos, new activity qualifications, or the excluded municipal
source. The other 4,543 package records remain a separate later batch.

## Before a committed run

1. Release the reviewed query remedy through staging CI and verify the running
   image and source hashes. The candidate verifier alone is not a deployment.
2. Finish the target p95 and API-parity check; retain both successful checks and
   the earlier measured failure. Do not substitute a different baseline silently.
3. Complete and review a commit-capable wrapper around `applyPreparedPlaces`.
   Default to rollback. A commit must explicitly name the canary manifest hash,
   exact target environment, approved application release and operator decision.
   Existing rollback-only verifiers must retain their no-commit guards.
4. Under bounded locks, validate the package and every selected record hash,
   recheck 50 existing fingerprints and 50 absent source identities, and retain
   exact native-column before/after values for affected records only on staging.
   Refuse stale or ambiguous identities. A new snapshot cannot grant permission
   to restore unrelated shared records.
5. Validate the same facts, eligibility, retained references, source history,
   API contracts and native repeat-import stability before the final commit.
   An error must roll back the entire batch. Verify the backup receipt before
   the commit boundary and write an atomic operation receipt on success.

## Recovery that must be reviewed before commit

A whole-table restore is not an acceptable batch rollback: media maintenance and
other users can change shared tables after the snapshot. Do not rewind global
identity sequences or fact-revision counters.

The recovery wrapper must use the exact operation receipt and refuse records
whose post-import fingerprints or observation state have since changed. Retain
new place IDs and any references; deactivate new records if they must be withdrawn.
For refreshed places, restore source facts using the native audited observation
restore where an earlier observation exists, then rebuild the shared projection.
Handle the case with no prior observation explicitly; do not invent a source
observation or delete audit history. Preserve later reviews, corrections, media,
identity reconciliation and saved references. Advance the native fact revision
when recovery changes published facts.

Test the recovery wrapper on the dedicated local public-data database: both the
normal recovery and stale/concurrent-change refusal paths. Verify people and
Composer agree after recovery, retained identities still resolve, and a second
recovery makes no unintended change. The successful temporary-table snapshot test
only proves typed reconstruction; it does not replace these application checks.

## After an authorized commit

Read the actual staging APIs against the operation receipt and confirm new,
refreshed, eligible and held counts separately. Fifty new rows would raise the
current 9,928 stored-row count to 9,978 if the baseline remains unchanged; this is
not a prediction of unique destinations or photo coverage. Check refresh stability
and unchanged IDs, relationships and claims. Report any coverage gap honestly.

The next batch selects only the remaining 4,543 original package entries. Do not
replay the old absent-identity precondition for the 50 already-created canary rows.
Use the committed operation mapping for explicit replay verification while keeping
the original package and its hashes immutable. Production requires its own current
identity reconciliation, review and authorization.

## Current state

- Selection manifest prepared and independently cross-checked against the package.
- Full staging rollback rehearsal passed; replacement typed snapshot reconstruction passed.
- Final bounded-query candidate measurement is pending; earlier grouping-only speed gate failed. The fix is not deployed yet.
- Commit/recovery wrapper implementation and its focused rehearsal remain open.
- No canary or full catalogue import has been committed.
