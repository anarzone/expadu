# Legacy reference journal implementation plan

> Use superpowers:executing-plans inline. The user already authorized continued
> implementation, isolated checks and existing draft-PR/Jira updates; no new
> approval checkpoint is needed for these reversible tasks.

**Goal:** Make the 484 verified older place references safely recoverable without
adding duplicate destinations or modifying live data.

**Architecture:** A bounded operator helper wraps native ReconcilePlace with the
existing durable operation journal. Only exact reviewed pairs with no alias fact
history or incoming child references qualify. Narrow recovery restores native
link/rating changes while retaining source and identity audit history.

**Tech stack:** Laravel 13/PHP 8.4, PostgreSQL/PostGIS, existing Composer/API routes.
**Spec:** LEGACY-REFERENCE-SPEC.md in this directory. Base: c65075fa.

## Global constraints

- Preserve the dirty primary checkout; reuse the existing candidate branch/PR61.
- No live mutation, merge, deployment, provider approval or new private export.
- Use the dedicated role/database/copied application; synthetic users only.
- Preserve unknown access/fees; source map points are never verified entrances.
- Preserve native identity guards. Proximity and names alone are insufficient.
- Store private receipts in the existing lab; exports are aggregates only.

## Task 1 — Bounded protocol and regressions

Files: create docs/places/production-release/LegacyReferenceJournal.php and
LEGACY-REFERENCE-RECOVERY.md; create tests/Feature/Places/LegacyReferenceJournalTest.php.
Interfaces: apply(string id,array context,array records,array baseline,string actor)
returns a checksummed journal receipt; recover(string id,array context,string actor,
string reason) returns a checksummed recovery; snapshot(list<int> ids) returns full
bounded identity/reference state; hash(array value) returns stable SHA-256.

- [x] Write tests for durable apply/replay/recovery and API/Composer old-ID mapping.
- [x] Add table-driven guard cases for invalid second evidence, duplicates, context,
  native-preview/proof disagreement and unsupported alias references/history.
- [x] Add later source/review/identity/reference/media drift and checksum refusal.
- [x] Run the test file with the existing dedicated local test wrapper; expect
  undefined LegacyReferenceJournal failures before implementing the helper.
- [x] Implement the spec's bounded native mutation journal; use current sibling
  journal locking, checksum and transaction patterns. Do not invent native undo.
- [x] Format helper/tests and run the focused file plus PlaceIdentityTest,
  PlaceIdentityAuditTest and CatalogueRecoveryTest; expect all passed.

## Task 2 — Complete isolated trial and publication

Files: operational install/rehearsal tooling and aggregate legacy-journal-summary.json
in this directory; helper/guide/tests in the candidate. Interface consumes the
already-private legacy-links-package and fresh source records. Produces aggregate
API/Composer apply/recovery results and private durable receipts.

- [x] Verify runtime manifest and helper hash, exact target/role and source freshness.
- [x] Rebuild records using current native previews and fresh proof, keep the 484 disjoint pairs
  in one bounded operation with at most 500 pairs.
- [x] Apply the journal operation; verify every old ID resolves by actual API and
  Composer saved-ID retrieval and unique eligibility remains 4,155.
- [x] Recover from its durable receipt with a new helper instance;
  replay recoveries, retain audits, verify all older IDs are held again.
- [x] Roll back the caller transaction and compare every source/place/review/audit/
  operation/reference row with baseline; expect exact restoration and zero users.
- [x] One independent review of the completed branch, one regression-backed fix
  pass if needed; preserve earlier facility review decisions and reports.
- [ ] Use normal commit hooks and exact-head CI, then update existing PR61,
  EXP-69/72 and BookStack33/36 with the final verified counts and release boundary.

## Review focus

- A saved reference created during a merge must not be silently moved on recovery.
- A late child/family/parent, active correction or shared media change must refuse
  recovery; source rows cannot be reconstructed from a subset projection.
- Native audit uniqueness prevents unreviewed alias reapplication after recovery.
- Related pairs can share ancestors/families. Keep this reviewed cohort in one
  bounded operation so immutable audit events cannot look like foreign drift.
- Restore only expected link/rating fields, retain immutable audit events and
  invalidate consumer facts; zero unique places are added by reference links.
