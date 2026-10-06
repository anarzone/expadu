# Approved staging provenance hold

The owner's “continue” authorizes the previously proposed staging-only hold.
Use the reviewed helper at commit `70d6581686114428c45d193682b697a11d242a14`.
Retain every place ID, identity relation, saved reference and media decision.
This operation changes only the complete source-null cohort's active and
recommendable flags and update time, plus the fact revision and recovery journal.

1. Recheck the exact runtime, staging database and prepared package hashes.
2. Refuse drift and check the running application's place-read compatibility.
3. Rehearse apply, retry, recovery and retry on staging inside an outer rollback.
4. Preserve the recovery metadata privately on the server's persistent disk.
5. Apply the same reviewed request on staging and commit only after invariant checks.
6. Verify the committed journal in a fresh process and check Places and Composer
   through their API/data contracts, including retained legacy references.
7. Recount eligible destinations and policy-publishable photos on the resulting data.
8. Publish aggregate evidence in EXP-69, EXP-72 and their existing Work Log pages.

No production operation, deployment, facility qualification, source rewrite,
media approval or email is included. Recovery remains a separately invoked
operation; the verification step must not undo the approved hold.

Implementation checkpoint: all eight steps passed. Both ticket and Work Log readbacks preserve their complete prior histories; PR61 body readback matches and remains draft. See REPORT.md for actual version gaps and limits.
