# Local Places Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidate the full collected source inventory and a fresh catalogue-only staging snapshot into a reproducible local workspace, then verify the supported release data through the real Places and Composer code before another deployment.

**Architecture:** Continue the existing collection and additive preparation workflow. Preserve immutable source inputs, stage one fresh catalogue snapshot, restore it into a dedicated local PostgreSQL database, and build a SQLite reconciliation index covering every collected source record. Retain source evidence, review holds, aliases and destination/facility relationships; use native application services for changes to the supported catalogue.

**Tech Stack:** PHP 8.4, Laravel 13, PostgreSQL/PostGIS, Python 3 standard library and SQLite.

**Spec:** `docs/places/open-collection/README.md`, `docs/places/production-pack/PLAN.md`, and the user's 1 October local-first direction. This is continuation of that approved work, not a new design proposal.

## Global Constraints

- Work locally on `codex/EXP-69-staging-readiness`; keep draft PR #62 unmerged. Preserve the dirty primary checkout and the separate production proposal.
- All source records remain available locally, without a target count, name requirement or photo requirement. Source rows are not unique real-world destinations.
- Export only catalogue tables. No users, private plans, sessions, saved places, feedback, secrets or operational recovery receipts. Replace administrative actor identifiers with a local non-personal marker. Keep snapshots ignored and mode 0600.
- Read staging through one repeatable READ ONLY snapshot. Do not boot the live application for export or run a server data mutation.
- Local database name: `exp69_local_catalogue_20261001`; host must be loopback, application environment testing, queues/mail/network faked or disabled.
- Fees, access, hours and current availability stay unknown when unsupported. Geometric containment and shared names never prove identity, permission or free entry.
- Media remains optional, with rights, health, match and provider exclusion gates intact. No new Stadt Köln provider or image approval.
- Retain original source data, licences and collection dates. Do not claim the 28 September collection was fetched on 1 October.
- Use API/kernel verification, as requested. No deployment or photo-health write is part of this local work.

## Review Focus

1. A media attachment for a different resource or any user-owned table must not enter a place snapshot; prove refusal/filtering using synthetic fixtures.
2. A source-null held row or historical canonical alias must retain its local status and resolution after restore; verify against the snapshot and native consumers.
3. An exact source ID may map to an alias or a conflicting existing identity; link evidence without automatically reactivating or merging records.
4. A closed, restricted, conditionally accessible or fee-unknown pitch must not become a confirmed free/public recommendation; exercise literal independent fixtures and all observed football rows.
5. Rebuilding the local index or replaying a local import must not duplicate records, weaken holds, or silently overwrite a newer source observation.

---

### Task 1: Fresh catalogue snapshot and local restore

**Files:**
- Create: `docs/places/local-catalogue/snapshot.php`, `SnapshotPolicy.php`, `test-snapshot.php`, `run.py`, `restore.php`.
- Create: `docs/places/local-catalogue/2026-10-01/snapshot-summary.json`.
- Read: existing migrations, media selector, source observation and qualification services.

**Interfaces:**
- Consumes: approved catalogue table/column policy, staging `89289db9641bb75a563e74b44be9b4717bd61b22`, primary checkout's local connection settings (never exported).
- Produces: ignored `storage/app/private/places-local/2026-10-01/catalogue.json`, table counts/hashes and a dedicated restored local DB. Snapshot document has `schema_version`, `exported_at`, `application_commit`, `tables`, `columns`, `redactions`, and `scope`.

- [ ] Write and run behavior tests before implementing the policy. Unknown tables/columns and non-place attachment targets must be refused or excluded; admin actors are replaced, IDs/evidence/revocations retained.
  ```php
  assert(SnapshotPolicy::sanitize('place_fact_corrections', ['id'=>7,'actor'=>'private-reviewer','revoked_at'=>null])['actor'] === 'local-catalogue-review');
  assert(SnapshotPolicy::allowsTable('users') === false);
  ```
  Expected: policy behavior tests fail before implementation, then pass.
- [ ] Implement the explicit catalogue policy, fixed SQL queries and a read-only export with wrong-database/commit refusal. Verify the schema before fetching rows, and write privately on the local host without printing data.
  ```sh
  python3 docs/places/local-catalogue/run.py snapshot
  ```
  Expected: staging unchanged, zero user/private table reads, one complete snapshot and counts/checksum receipt.
- [ ] Create/migrate only the dedicated local database; restore all selected rows and relationship links transactionally, with sequence reset and empty-user assertion.
  ```sh
  python3 docs/places/local-catalogue/run.py setup
  python3 docs/places/local-catalogue/run.py restore
  ```
  Expected: every selected table count matches; held flags, aliases, reviews and media policy inputs preserved; zero real users.
- [ ] Commit only the tool/test/aggregate files with `Refs EXP-69` and `Refs EXP-72`; keep raw snapshot ignored.
- [ ] Complete verification command: `python3 docs/places/local-catalogue/run.py verify-snapshot`.

### Task 2: Whole-inventory reconciliation and searchable local data

**Files:**
- Create: `docs/places/local-catalogue/consolidate.py`, `query.py`, `test_consolidate.py`.
- Create: `docs/places/local-catalogue/2026-10-01/consolidation-summary.json` and public review summaries.
- Preserve: `docs/places/cologne-expansion/2026-09-28/inventory.sqlite` and original source captures.

**Interfaces:**
- Consumes: Task 1 catalogue snapshot plus the immutable research inventory and its recorded SHA-256.
- Produces: ignored local SQLite registry containing complete source rows, existing app links, retained app-only rows, roles, facts, existing review/hold status, explicit source links and containment. Summary separates raw rows, candidate roles, exact links, unsupported categories, ambiguity and actual app eligibility.

- [ ] Write RED tests for exact source linking, retained aliases, overlapping independent sources, missing names, supporting features, restricted/closed records, and strict unknown-fee exclusion.
  ```python
  self.assertEqual(result['exact_links'], [{'source_key': 'osm:node/10', 'spot_id': 4, 'canonical_spot_id': 2}])
  self.assertEqual(search_result['confirmed_free_public'], [])
  self.assertEqual(len(search_result['needs_checking']), 1)
  ```
  Expected: genuine missing-behavior failures, then GREEN after implementation.
- [ ] Build the complete index without modifying source inputs; count and checksum every source family and snapshot input.
  ```sh
  python3 docs/places/local-catalogue/run.py consolidate
  ```
  Expected: every source record preserved exactly once; no automatic cross-source merge, source-null reactivation or photo approval.
- [ ] Run queries covering café/restaurant destinations and named/unnamed sports facilities across central and outer origins. Return friendly source/descriptive labels, source/date, location meaning, distance kind and explicit uncertainty.
  ```sh
  python3 docs/places/local-catalogue/run.py query-checks
  ```
  Expected: results distinguish confirmed free/public matches from uncertain candidates; supporting map features do not inflate destination counts.
- [ ] Rebuild into a second temporary output and compare semantic table hashes; reject corrupt inputs and an existing output rather than silently replace it.
- [ ] Commit the implementation/tests/aggregate results locally; completion command: `python3 -m unittest discover -s docs/places/local-catalogue -p 'test_*.py'`.

### Task 3: Native supported-catalogue rehearsal, evidence and review

**Files:**
- Create: `docs/places/local-catalogue/verify-native.php`, `rehearse.php`, `2026-10-01/REPORT.md`.
- Reuse: `docs/places/production-pack/prepare.py`, `apply-pack.php`, native Places resource and Composer repository.
- Modify only if a reproduced defect requires it: the relevant preparation or native consumer file and focused regression test.

**Interfaces:**
- Consumes: restored baseline and full reconciliation registry. Supported selection must target the new snapshot and retain all review holds.
- Produces: measured before/after native API/Composer contracts, exact local import/replay/rollback proof, explicit remaining issues and one final review. Server data is unchanged.

- [ ] Verify the restored baseline through all eligible Places/Composer contracts and retained aliases; check held details, unknown facts and publication-policy photo associations.
  ```sh
  python3 docs/places/local-catalogue/run.py verify-native
  ```
  Expected: parity with the fresh snapshot, or a named reproducible discrepancy that is fixed before proceeding.
- [ ] Prepare a new dated supported-source package against the fresh baseline, excluding stale observations and existing unresolved review cases. Rehearse selected records through native services inside a local transaction, then repeat and roll back.
  ```sh
  python3 docs/places/local-catalogue/run.py prepare
  python3 docs/places/local-catalogue/run.py rehearse
  ```
  Expected: zero unsupported claims or lost relationships; repeat input is stable; rollback restores exact baseline table hashes. Unready source records remain in the complete local registry.
- [ ] Refresh public source evidence for the existing 559 held facilities and rehearse only unchanged, independently public-access facilities with the existing qualification journal. Prove strict-free exclusions, native discovery, replay/recovery and complete local rollback. Keep unsupported access and fees unknown.
- [ ] Run relevant regression suites and normal commit hooks for touched PHP behavior. Keep immutable package output distinct from deployed state.
- [ ] Obtain one fresh whole-change review, fix substantive findings with failing-to-passing regression evidence, and record outstanding limitations.
- [ ] Update existing EXP-69/EXP-72 descriptions and Work Log pages with local-first direction and measured results; preserve history and read back updates.
- [ ] Leave draft PR #62 unmerged. Completion command: `python3 docs/places/local-catalogue/run.py verify-evidence`.
