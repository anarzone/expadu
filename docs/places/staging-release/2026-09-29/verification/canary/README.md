# EXP-72: guarded 100-record staging canary

Status: exact staging rollback rehearsal passed on 29 September 2026 at 14:18 UTC.
The catalogue import has **not** been committed. Upload and rollback-check
permission does not authorize either committed mode below. Production is excluded.

## Reviewed operation

- Operation UUID: `6f1e5fe0-0b54-4d7b-81eb-29d000001001`.
- Manifest: `../../canary-manifest-v2.json`, 50 additions and 50 refreshes.
- 22 categories; 97 OSM and three Overture records. All selected refreshes have
  earlier same-source observations. Nine original proposed refreshes were replaced.
- Package SHA-256: `ecfc2777acf6e91d8e574394418120163e725d2b432201179fbcc60fd20f605a`.
- Manifest SHA-256: `badef6eb0727c6af779934742acba976b483fd98bdd9f1dfffcddae70ee33cf3`.
- Acceptance SHA-256: `f5278ac5e7833c9e6b7adcd3d8fbca25b1edcaf18287cef220bb1009a0e269e6`.
- Expected final canary membership: 86 ordinary-visible, 87 activity/Composer,
  13 held. Existing selected membership is 42/43, yielding 44 additional visible
  identities in either discovery scope. Stored rows would become 9,978.
- No photos are approved. Unknown access/fees stay unknown. No verified free/public
  football result is created. Stadt Köln structured place data/media are excluded.

`canary-acceptance.json` freezes the exact expected membership and native display
names by public source key. Localized descriptive labels are distinct from source
names; for example the native label `Picknickplatz` corresponds to the package's
English descriptive label `Picnic area`. Neither is asserted to be an official name.

## Preconditions and storage

1. Confirm the running environment is staging, its database is `expadu_staging`,
   and its URL is `https://app.staging.expadu.com`. The runner enforces these checks.
2. For a committed run, deploy the empty `place_catalogue_operations` migration.
   A temporary journal is allowed only in a rollback rehearsal. Migration rollback
   refuses to delete a populated journal.
3. Keep the reviewed input bundle and helpers in private host storage:
   `/data/staging/places-research/staging-release-20260929` (directory 0700/files0600).
   Container private storage is ephemeral. After any application deployment, copy
   these reviewed inputs into its private research directory again; never assume
   the previous container receipt survives deployment.
4. Retain the fresh native-column snapshot on the private host. Its recorded hash
   is in `../../persistent-staging-storage.json`. The earlier container-only raw
   snapshot was lost during deployment; do not cite it as a durable backup.
5. Check the script, helpers and frozen input hashes against archived evidence.
   The runner also verifies eight relevant application file hashes, both recovery
   helpers, the importer, package, manifest, fingerprints and acceptance file.
   Hash mismatch or changed baseline requires a reviewed refresh, not bypassing a guard.

## Rollback-only rehearsal

From the staging application directory, set `PLACES_REHEARSAL_INPUT` to the private
prepared input folder, then run `php run-canary.php --staging --rehearse` using the
uploaded script's full path. The default mode is also `--rehearse`.

The runner uses an unsaved synthetic negative user ID, private array caches and
rate limiter, fake queue/HTTP clients, isolated error reporting and a private log.
It does not load real accounts/plans or export raw rows to the client. Reads and
writes to catalogue tables occur under explicit transactions and table locks.
Locks can briefly delay concurrent catalogue writers. Sequence allocations are
not rolled back. Runtime timeout must terminate the process if it exceeds 240s.

Before it reports a pass, the runner checks all 100 source identities, categories,
tags, names, resolved coordinates, unknown fees and actual detail API facts. It
requires exactly the frozen 87 Composer identities and preserves unrelated ordinary
and activity discovery sets. It rehearses native recovery before any possible
commit and verifies that rolling recovery back restores the imported state. The
last rollback verifies seven catalogue-table fingerprints while outer locks remain
held. Media and user references are untouched; separate synthetic tests cover them.

Save only the aggregate summary locally. Copy the private prepared receipt and
summary from the container to the private host, verifying the receipt checksum.
The receipt is written using a flushed/synced temporary file and atomic rename,
with an explicit `prepared_uncommitted` phase. Its name includes the runner hash.
It never proves commit, even when its embedded row describes a proposed recovery.
The database journal must be reconciled to establish committed state.

## Separately authorized committed modes

Do not run these until the owner has explicitly approved the concrete persistent
100-record staging import. Set `PLACES_CANARY_COMMIT_APPROVAL` to
the mode (`--apply-approved` or `--recover-approved`), operation UUID, manifest SHA and
SHA-256 of the exact reviewed `run-canary.php`, joined with colons in that order. This is an operator acknowledgement, not a
substitute for owner authorization. Never include API credentials in the bundle.

- `--apply-approved`: repeats every runtime/hash/identity/consumer/recovery guard,
  writes the journal in the same database transaction as the data, exports a
  private prepared receipt, then commits. Refuses a missing permanent journal.
- On success, verify the journal's `applied` state and all 100 live detail contracts
  using the same reviewed operation. A repeated apply is a checked no-op, not a
  second import. Verify live discovery/Composer results and persistent host evidence.
- If the process exits after database commit but before file export, the database
  journal remains authoritative. Retry the exact operation/context. If its target
  state has changed, stop for review instead of reconstructing from a receipt file.
- `--recover-approved`: requires authorization for recovery and the same pinned
  operation/context. Reads the database journal, refuses any changed target state,
  appends native source restores and restores only importer-owned Spot fields.
  New IDs remain but become inactive/nonrecommendable. The recovery receipt and
  `recovered` state commit atomically. A repeated recovery is checked and has no
  additional effect. An already recovered import cannot be reapplied.

Recovery preserves saved/visited/review/media references and audit history. Native
restore may retain a newly seen alias; the receipt reports alias deltas explicitly.
This exact canary reports zero alias deltas. Generic recovery is not literal history
erasure. Active access/fee/activity reviews and non-past source timestamps are
refused before import; they require a separately reviewed recovery rule.

## Remaining scope

This is a controlled first batch, not completion of the Places programme. Another
4,543 package entries remain. Of the full package, 374 existing refreshes have no
previous same-source observation and need distinct audited recovery support. No
75% photo-coverage or free-football completion claim is justified by this release.
The separate Places design remains under review; this release changes no UI.
