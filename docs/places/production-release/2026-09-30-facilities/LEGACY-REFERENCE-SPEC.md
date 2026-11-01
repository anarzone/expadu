# Reviewed legacy reference recovery

Refs EXP-69, EXP-72. Implements the user's existing instruction to continue
preparing user-friendly, Composer-usable Places data and retain older references.
No live mutation, merge, deployment or new private export is authorized here.

## Outcome and scope

484 already rehearsed source-null records describe existing source-backed places.
They add zero unique places. Make their exact reviewed alias operation durable and
recoverable before any live proposal. Preserve original IDs, user references and
source values. This bounded operator addition reuses native ReconcilePlace and the
existing place_catalogue_operations table; it adds no public endpoint or migration.

The cohort supports 1–500 disjoint exact pairs per operation, an eligible active
recommendable canonical OSM node/way target, current complete tags and geometry,
matching native preview fingerprints, no earlier reconciliation for the alias,
no alias fact observations/corrections and no incoming alias children, area,
venue or reviewed destination references. Other cases are explicit holds.
All 484 current trial pairs satisfy the no-history/no-child-reference scope.

## Protocol

LegacyReferenceJournal has apply(UUID, context, records, baseline, actor),
recover(UUID, context, actor, reason), snapshot(pairIds) and hash(array).
Require an explicit caller-owned READ COMMITTED transaction, exact current database, actor,
package hash, independently checked runtime application manifest and helper hash.
Each record binds alias_id, canonical_id, source_id, checked_at, proof,
proof_sha256 and identity_fingerprint. Proof contains positive current source
version, visibility, complete tags, exact target map point and a geometry
verification attestation. Source-response hashes remain private. A supplied
attestation/hash does not independently prove source authenticity. Proof expires
in 24 hours; timestamps must be absolute ISO values with explicit timezone and a
valid calendar date. Blank/relative/malformed values and future values beyond five
minutes are refused. Other transaction isolation modes are explicitly held.

Lock the journal and relevant place/fact/identity/reference/media/review tables.
Snapshot complete bounded canonical families and ancestor identities, all relevant
fact/correction/reconciliation/destination history, incoming relationship rows,
user review/feedback and place-owned media/asset rows. Later changes in this scope
refuse replay or recovery. Native apply must change only alias canonical_spot_id
and updated_at, canonical rating and updated_at, and append exactly one native
identity audit record per pair. A guard rejects any other change atomically.
Store the receipt and its checksum in the operation table in the same transaction.

Recovery uses the durable database receipt, not an exported file or inferred
match. It restores only the alias's canonical pointer, retaining its post-link timestamp,
and the canonical's original rating/updated_at. It retains source rows and native
reconciliation audit history, records actor/reason/recovery checksum atomically,
and advances PlaceFactRevision for consumer cache invalidation. Identical replay
is non-mutating; altered context, actor, reason, checksum or current state refuses.
A recovered UUID cannot be applied again. Because native reconciliation audit has
a unique alias ID, aliases with historical reconciliation events require a
separate reviewed continuation; this helper refuses their reuse rather than
rewriting audit history. Coordinate writers and validate a backup before an
approved live operation.

## Required verification

Reproduce missing helper behavior before implementation. Test native apply and
new-instance replay/recovery, unknown origin remaining unavailable after recovery,
canonical detail/Composer saved-ID resolution during apply, retained audit history,
wrong database/helper/package, stale/future/missing/changed proof, invalid second
record atomic refusal, duplicate/cross-used pair IDs, tag/point/native preview and
reference drift, aliases with history or incoming references, later source,
review, identity, saved feedback and media changes, corrupt receipt, and recovered
operation refusal. Preserve source rows, original IDs and caller transaction.

Rehearse all 484 eligible pairs in one bounded atomic operation in the copied catalogue.
Verify all old IDs through detail API and Composer byIds, recover the operation from
a new instance, verify older IDs return to their previous held
state, retain native audit entries inside the trial and restore every affected
row by the final outer rollback. No actual process-crash claim is permitted.
Publish only aggregates and public source outcomes; leave private receipts in the
existing protected lab. Keep the unrelated 72 candidate holds explicit.
