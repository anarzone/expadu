# Held Public Facilities Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans for the existing approved local-first work. Steps use checkbox syntax.

**Goal:** Recover useful source-backed public activity facilities blocked solely by unavailable legacy references, without merging identities, weakening access checks or deploying.

**Architecture:** Select exact existing OSM identities from the frozen whole-inventory preparation. Preserve every ambiguity edge and legacy row. Refetch the selected public source objects and complete geometry, prepare only unchanged evidence, refresh through the native importer, and qualify through native fact review inside an always-rolled-back local transaction. Export the combined native candidate catalogue separately from the earlier immutable result.

**Tech Stack:** Python 3, PHP 8.4, existing Laravel/PostGIS application services.

**Spec:** docs/places/production-pack/PLAN.md; docs/places/local-catalogue/2026-10-01/REPORT.md; user direction to continue completing data locally before one release.

## Global Constraints

- Continue codex/EXP-69-staging-readiness from 1f4c99250e2d985399d0e765afef22231b8e7b73. No push, merge, server apply or new provider/image approval.
- Read immutable private catalogue, source inventory and hold edges; verify hashes. New outputs go under the existing private dated directory's held-public-facilities subdirectory.
- Only existing active, nonrecommendable, unaliased source-backed facilities with explicit public access and ambiguity solely against source-null, inactive, nonrecommendable, unaliased legacy counterparts qualify for source review.
- Public access does not establish a free fee. Explicit supported fee=no may survive only when no charge/conditional conflict exists. Missing fees remain unknown.
- Every current source object must match its frozen tags and geometry. Changed/deleted/missing records stay held. Preserve complete source evidence and original collection dates.
- Preserve IDs, all legacy flags, source histories, memberships and media. Do not resolve ambiguity by proximity, bind legacy rows to a source, or auto-merge.
- Use only exp69_local_catalogue_20261001 on loopback, existing isolated bootstrap and API/kernel checks. Outer rollback and sequence restoration are mandatory. The new package is a local proposal, not a durable live operator.

## Review Focus

1. Active or source-backed competing identities and missing ambiguity edges must prevent qualification.
2. Unknown/conditional/restricted access and ambiguous fees must not become free/public recommendations.
3. Missing geometry nodes, changed way topology and changed source tags must remain held.
4. Native refresh must not reactivate legacy records or lose multiple sport capabilities.
5. Repeated apply and qualification must be stable; rollback must restore all catalogue rows and sequences, including after a failure.

### Task 1: Select and independently recheck the held cohort

**Files:** docs/places/held-facilities/selection.py, test_selection.py, fetch.py, run.py, 2026-10-01/selection-summary.json, source-summary.json.
**Interfaces:** `select_candidates(holds, edges, spots, records, observations)` returns selected normalized records with original hold evidence and reason-count summary; `fresh_evidence(record, current, after, nodes)` returns refusal reasons and geometry. Raw sources and snapshots remain unchanged.

- [ ] Write failing independent fixtures for eligible held legacy edges and refusal of active/source-backed/aliased counterparts, missing edges, named or already eligible records, access/fee conflicts and withdrawal history. Expected: missing implementation failure, then all fixtures pass.
  ```python
  self.assertEqual(select_candidates(holds, edges, spots, records, observations)['records'][0]['existing_id'], 20)
  self.assertEqual(select_candidates(holds, active_edges, active_spots, records, observations)['records'], [])
  ```
- [ ] Build a bounded private selection from verified immutable inputs. Preserve original ambiguity evidence, produce source/category/fee counts and every refusal. Expected: at most 602 candidates from this snapshot; the measured count is authoritative.
  ```sh
  python3 docs/places/held-facilities/run.py select
  ```
- [ ] Add failing fixtures for source changes, incomplete nodes and changed way topology, implement public OSM reads with bounded batches/timeouts and no contributor identities, then fetch all selected objects plus geometry. Expected: no silent skipped source; every selected identity gets evidence or an explicit hold.
  ```sh
  python3 docs/places/held-facilities/run.py fetch
  ```
- [ ] Commit local code/tests/evidence with Refs EXP-69 and Refs EXP-72. Complete with `python3 -m unittest discover -s docs/places/held-facilities -p 'test_*.py'`.

### Task 2: Native refresh, qualification and complete export

**Files:** docs/places/held-facilities/rehearse.php, run.py, 2026-10-01/REPORT.md and aggregate receipts; existing tracker/work-log pages.
**Interfaces:** consumes Task 1 selection/source proof and prior two-addition/90-facility proposal; produces complete combined native Places/Composer export, exact before/after and rollback evidence, every exclusion and source manifest.

- [ ] Verify every selected source against the current local row and native geometry; reject newer/withdrawn observations, active reviews, source conflicts, unsupported access/fees, and unsupported category/point changes. Prepare exact existing-row fingerprints. Expected: native-supported subset only; no inferred names, access or fees.
- [ ] Refresh accepted source records with applyPreparedPlaces while preserving eligibility. Preview and apply activity_discovery via ReviewPlaceFacts; repeat both operations and compare state. Expected: one source identity and stable repeated application; all legacy rows unchanged.
- [ ] Check every qualified resource and Composer by-ID contract in batches; check every category through paginated Places and map plus near-origin Composer requests including football and strict-free cases. Expected: fees and activities match source evidence, unknown fees excluded from strict-free results, general discovery unchanged.
- [ ] Rehearse the earlier proposal plus this cohort together and export all native eligible candidates. Roll back all tables, reviews and sequences, verify exact baseline and repeat source/package hashes. Expected: no persistent local qualification or live change.
  ```sh
  python3 docs/places/held-facilities/run.py rehearse
  ```
- [ ] Record business/category/geometry cases found during selection as unresolved source-review work, without treating old names as newly verified.
- [ ] Run normal commit hooks and one fresh independent review; fix substantive findings with regression evidence. Update existing EXP-69/72 and Work Log pages, preserve history, read back. Complete with `python3 docs/places/held-facilities/run.py verify`.

## Self-review

The two tasks share exact immutable inputs and source keys; Task 2 rechecks the selection rather than trusting a count. No current application code or UI changes are planned. Explicit fee evidence is verified through existing native facts; no change to the older unknown-fee-only qualification journal. Fresh proof timestamps are required before later deployment. The broader business identity and image tasks remain open.
