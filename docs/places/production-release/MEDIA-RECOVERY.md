# Existing place-photo recovery

Refs EXP-69, EXP-72. `MediaRecoveryJournal.php` is an operator-only helper for a
reviewed package of existing associations. It does not discover photos, expose an
endpoint, schedule work or grant permission to run against production.

## Review inputs before execution

The caller verifies the target database and installed application file hashes,
then supplies their identity in `context`. The helper verifies its own file hash;
`application_sha256` is the caller's attestation, not a build check by the helper.
`package_sha256` uses `MediaRecoveryJournal::hash($records)`.

Every record needs an exact OSM source ID, existing spot/asset/attachment IDs,
Commons filename, supported matching method, exact current source reference,
source-proof SHA-256, check time, freshly fetched native Commons metadata and the
reviewed **image-byte SHA-256**. Source proof must establish the actual OSM-to-file
link: a licence alone does not establish a place match. Keep raw provider responses
and the reviewed package privately with the operation. Metadata SHA-1 cannot stand
in for validated image bytes. The helper trusts this reviewed operator evidence;
it does not independently query Wikidata or Wikipedia to establish the link.

Only eligible canonical owners and pending, unlocked hero matches are accepted.
The helper enforces current allowed media hosts, open licences, file-page identity,
attribution requirements and municipal-origin exclusions. It refuses already
accepted/rejected matches. Existing asset/attachment identity, priority and primary
state are preserved; adding/remapping assets needs another reviewed workflow.

## Apply and replay

Capture the target-specific `snapshot($ownerIds)` immediately before review. Open
an explicit caller-owned transaction and call `apply($uuid, $context, $records,
$baseline, $actor)`. Commit only after all acceptance checks pass. On any error,
roll back the caller transaction. The journal and changes commit together in
`place_catalogue_operations` using a separate `existing-place-media-v1` kind.

The operation captures native pending candidates, validates current image bytes,
checks the reviewed checksum, creates audited match decisions and checks every
selected hero **after the full batch**. Shared assets validate once. A failed
record rolls back the entire batch. Reusing the same operation ID with the same
actor/context and unchanged post-state returns its receipt without network calls
or new capture/review work.

This small maintenance operation is bounded to 100 records and 2,500 rows in each
media table and the affected place family. It locks the spot and media tables while
validating. All attachments must be place-owned. It snapshots the entire bounded
media catalogue, every attachment owner, canonical aliases and referenced
parents/destinations. This deliberately conservative operation is unsuitable for
large or mixed-owner catalogues. Any unrelated media edit also prevents recovery.
Coordinate writers and scheduled/queued work for a live maintenance window; reads
remain possible. The database lock timeout is ten seconds.

## Recovery

With the same verified target/application/helper context, open a caller-owned
transaction and call `recover($uuid, $context, $actor, $documentedReason)`. The
committed database journal is the recovery input; a progress file is unnecessary.
An unchanged post-state is mandatory. A later media row, shared owner, alias or
parent change causes refusal before restoration. Re-review drift rather than
forcing past the guard.

Recovery restores original media asset/attachment values and retains new health
and match audit history. Its own checksum-protected recovery receipt is saved in
the same journal. Repeat recovery returns that receipt only while the recovered
state remains identical. Applying a recovered operation ID is refused.

The isolated 30 September rehearsal uses an **outer rollback**, with nested
recovery and repeat-operation checks. A new service instance demonstrates journal
lookup without an in-memory receipt; it is not an actual process-crash test.
No persistent approval or live deployment is implied by the rehearsal.
