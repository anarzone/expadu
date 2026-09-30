# Reviewed facility qualification

Refs EXP-69, EXP-72. Operator helper:
`docs/places/production-release/FacilityQualificationJournal.php`.

Use this only for a separately reviewed cohort of existing source-backed,
descriptively named public facilities. It does not discover source data or verify
an operator's evidence by itself. Explicit fine-category requests (for example,
playground or table tennis) can retrieve qualified facilities. General discovery
continues to use the existing recommendation flag. Sport requests still require
matching source-supported sports, known public access and supported conditions.
Mixed broad/fine requests admit qualification-only additions only for explicitly
selected fine facility kinds. Native qualification requires current known,
unconditional public access; any later access review or withdrawal invalidates
the qualification until a fresh public-access review, even if source tags remain
unchanged. Combined public-access and qualification reviews are supported.
Combined unknown-access reviews are refused atomically.

## Required evidence and execution

1. Read current OSM nodes/ways and all required geometry nodes. Recheck way topology
   after fetching nodes. Preserve source-response hashes and the source-check time.
   Compare complete tags and the original map-point method. An area representative
   point is not the bounding-box midpoint and is never a verified entrance.
2. Resolve each exact source key to one existing canonical target. Inspect native
   facts, source history, active corrections, parent/group links and overlapping
   source identities. Document every held key and reason. This helper supports
   active independent facilities with a descriptive name, explicitly public known
   access, unknown fee, no legacy price range and no active fact corrections.
   Fresh proof must also agree with effective native access, fee and map facts;
   conflicting older observations cannot be hidden by matching stored projections.
3. Review the target and build its native
   `ReviewPlaceFacts::preview($id, ['activity_discovery' => true])` fingerprint.
   Each record includes `source`, `source_id`, `spot_id`, `category`, `checked_at`,
   `proof`, `proof_sha256` and `fingerprint`. Proof identifies the visible source,
   positive version, complete tags and original map point, with an explicit
   geometry-verification attestation. Preserve the detailed source and geometry
   evidence privately; a boolean or a supplied checksum alone proves nothing.
4. Verify the installed application file manifest and helper hash independently.
   Context contains the exact target `database`, `package_sha256`,
   `application_sha256` and `importer_sha256`. The application hash is a journal
   binding; the caller must verify the files. Fresh source proof expires after
   24 hours; future proof beyond five minutes is refused.
5. Coordinate writers before execution. Begin a caller-owned transaction, then
   call `snapshot($ownerIds)` and
   `apply($uuid, $context, $records, $baseline, $actor)`. Batches contain 1–100
   unique targets. The helper locks the source, review, identity and operation
   tables, rechecks source uniqueness, current facts, overlapping identities and
   fingerprints, and uses the native review API. No source values, IDs, general
   recommendation flags or media rights are changed.
6. Verify every target through actual nearby Composer discovery, paginated Places
   category discovery, map category discovery and its detail API. A saved-ID
   lookup alone is insufficient. Unknown fee must stay unknown and must not enter
   a strict free request. Pace automated map calls below their 60/minute limit.
   Commit the outer transaction only for an explicitly authorized target operation;
   a rehearsal rolls it back. Save the durable operation UUID and receipt privately.

## Replay and recovery

The operation and its receipt are saved atomically in
`place_catalogue_operations` with kind `existing-facility-qualification-v1`.
Identical apply replay returns the saved receipt only while its state and full
family/source/review snapshot still match. Different context, actor, code,
package or later edits cause refusal. Snapshots include canonical aliases and all
referenced parents/destinations, bounded to 500 family rows.

In a caller-owned transaction, a new helper instance can call
`recover($uuid, $context, $actor, $reason)`. Recovery reads the database receipt,
refuses later edits, and revokes only the operation's qualifications through the
native review API. It retains the qualification and revocation audit records and
advances the fact revision. It preserves source rows, IDs and prior history.
Identical recovery replay returns the saved recovery receipt. Recovery does not
permit reapplying the same UUID; use a newly reviewed operation after recovery.

This protocol deliberately refuses active pre-existing corrections, aliases as
targets, already grouped facilities, unsupported categories, unknown/restricted
access, priced facilities and ambiguous overlapping source identities. Those need
separate reviewed handling. It approves no photos and grants no free/public/open
or current booking-availability claim beyond the source facts.

## Rehearsed result — 30 September 2026

The isolated production copy retained 12,063 records. All 559 prepared descriptive
OSM facilities passed current source/tag/geometry checks. Ninety qualified for the
cohort: 74 playgrounds, 11 table-tennis facilities, three basketball facilities and
two pitches. The other 469 retained unknown-access holds. Every one of the 90
passed actual API and nearby Composer discovery plus strict-free exclusion.
Eligibility rose from 4,155 to 4,245 during the trial; general destinations stayed
at 4,155. Recovery retained 180 audit records during the trial, and the final outer
rollback restored all source/place/review/operation rows. No live qualification or
production/staging deployment was performed. The test exercised restart through a
new service instance, without simulating an actual process crash.
