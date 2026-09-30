# Reviewed legacy reference recovery

Operator-only helper: `LegacyReferenceJournal.php`. Refs EXP-69, EXP-72.
This is a prepared protocol; publishing the helper does not authorize a live run.

A reviewed older ID can resolve to the exact existing source-backed destination
through Places detail and Composer saved-ID retrieval. The link adds no unique
place. The helper uses native `ReconcilePlace`, preserves the older row and user
references, and stores its checksummed receipt in `place_catalogue_operations`.

## Inputs and limits

Use one explicit caller-owned READ COMMITTED transaction and 1–500 disjoint alias/canonical pairs.
Bind the exact current database, reviewed package, independently verified application
manifest and actual helper hashes to the context. Every record supplies alias and
canonical IDs, exact OSM node/way source identity, current checked-at time, complete
source/geometry proof, proof hash and native preview fingerprint. Proof expires
in 24 hours. An absolute ISO timestamp with an explicit timezone and valid
calendar date is required; blank, relative and malformed times are refused. Future timestamps beyond five minutes are refused. The helper checks
the supplied evidence against stored tags and the effective map point. Fresh way
proof recomputes the existing documented method: area point-on-surface for the
prepared source pack, or bounding-box centre for earlier native Overpass imports.
A pair matching neither fresh method is held; target coordinates are never copied
into source evidence or silently changed. An operator
attestation/hash is not independent proof of source authenticity. Keep actual
source responses and private receipts in the protected target environment.

Only an eligible active recommendable source counterpart and an independently
reviewed pair qualify. Native identity guards still apply. Alias observations,
corrections (including revoked ones), previous reconciliation events or incoming
child/area/venue/destination references require a different reviewed protocol.
The 484-pair research cohort fits this narrow scope; the other 72 candidate pairs
remain held. Do not apply a proximity-only match or automatically reuse an alias
whose recovered native audit remains present.

## Apply and recover

1. Verify target identity, runtime/helper manifests, private source proof freshness,
   backup and coordinated writer pause before an authorized live operation.
2. Create records using current native previews and snapshot both IDs in each pair.
   `apply(UUID, context, records, baseline, actor)` locks relevant writer tables,
   rejects baseline drift, validates all pairs, then performs the native links.
3. Verify each old ID through the actual detail API and Composer saved-ID retrieval.
   Check unique eligible and general destination counts remain unchanged.
4. Recover with a new helper instance using the durable database receipt:
   `recover(UUID, context, actor, reason)`. The reason must contain at least 20
   characters. No exported row file is used as a replacement for journal state.
5. Verify older IDs return to their previous held state. Source facts, reviews,
   feedback, media and identity audit records remain intact.

Recovery restores only the alias canonical pointer and the canonical's original
rating/updated_at. It retains the alias post-link timestamp and appends recovery
state without deleting native audit history. It advances `PlaceFactRevision` to
invalidate consumers. It does not erase a user's saved reference or reconstruct
source rows. Replay requires matching context, actor, reason and complete current
state; a recovered operation cannot be applied again. Native audit uniqueness also
prevents a new UUID from silently reusing the same recovered alias.

All relevant identity families, ancestor identities, incoming relationship rows,
source/correction/audit history, reviews, feedback and place-owned media/assets are
snapshotted. Later changes in those rows refuse replay/recovery rather than being
overwritten. Limits are 1,000 seed IDs, 2,500 identity/ancestor rows and 5,000 rows
per protected collection. Table locks have a ten-second acquisition timeout and
remain held for the caller's transaction. A larger cohort needs coordinated writers;
partition only by independently verified disjoint identity families.

## Evidence and release boundary

The dedicated copied-catalogue rehearsal applies and recovers every reviewed pair,
checks API/Composer results and compares every protected table after outer rollback.
New-instance replay proves recovery does not depend on one helper object's memory;
it does not simulate a process crash or prove deployment readiness.

See `2026-09-30-facilities/legacy-journal-summary.json` for the actual final trial,
and `LEGACY-REFERENCE-SPEC.md` / `LEGACY-REFERENCE-PLAN.md` in that directory.
Live data application, merge and deployment require separate authorized execution.
The helper does not enable unrelated legacy records, approve media rights, verify
entrances, or infer free access from missing fee data.
