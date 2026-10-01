# Ten thousand local Places/Composer catalogue records

**Goal:** At least 10,000 distinct, native, eligible Places/Composer records in a reproducible local export. Continue the approved catalogue work; do not deploy, push, merge, or change live data.

**Authority:** The user explicitly requested continued implementation until 10k and previously authorized broader open-source collection, user-friendly practical facts and Composer access. Preserve the existing approved design. This is an extension of the local catalogue pipeline; no new UI or subsystem is proposed.

**Baseline:** Worktree `codex/EXP-69-staging-readiness`, starting commit `59f3b1c9`. The prior export contains 4,927 eligible identities, including 690 evidence-qualified facilities and two additive destinations. The untouched dedicated local database is `exp69_local_catalogue_20261001`. The raw 255,949-source inventory is evidence, not the count of usable places.

## Global constraints

- Count each canonical native candidate once, by `spot:<id>`, and verify both PlaceResource and CandidateRepository::byIds contracts for every exported record. Do not count map-only context, aliases, unresolved identities or duplicate attraction rows.
- Add named physical customer destinations from an explicit OSM retail/service/culture/community/health/accommodation/fitness allowlist. Missing/invalid names, unknown shop types, vacant shops, lifecycle restrictions, conditional fees/access, membership constraints and ambiguous identities remain held.
- Preserve all old category/identity/closure/source-withdrawal holds. Never retype or reactivate existing identities just to reach the target. New candidates are additions only.
- Use conservative proximity/name/contact screening against every existing native identity, both previous additions, and the entire new cohort. No automatic merges. Freeze inputs and per-identity decisions.
- Re-read exact public OSM identities and complete geometry. Source tags/topology must match the frozen selection, node/representative point within one metre, and proof must be less than 24 hours old. Re-read ways after their nodes. Reject changed/missing source evidence.
- Unknown fees, opening hours, booking/access limitations and entrances stay unknown. Preserve raw type tags for the LLM. Data licence is ODbL; no media licence is inferred from data. Exclude Stadt Köln providers and keep PublishedMediaSelector gates.
- Apply only inside a caller-owned transaction in the dedicated local database. Verify immutable baselines, all unrelated rows and media relationships, replay idempotence, failure cleanup, exact table hashes and sequence restoration. Export is a local candidate package, not a production release.

## Task 1: Broader native category contract

Add clear fine categories for the explicit everyday destination types, with friendly labels, coarse families and deliberate indoor/outdoor/facility behavior. Keep source-specific retail types in tags. Derive accepted coarse selectors from SpotCategory for Places and Composer; test coarse/fine query behavior and LLM parser normalization. Preserve independent business branches in list/detail clustering while retaining commodity facility grouping. No UI redesign or invented typical business hours.

**TDD:** Write focused enum/API/Composer regressions, observe failure, implement, observe green. Existing identity/activity and source/access tests must remain green.

## Task 2: Immutable additional-place selection and fresh source proof

Create a separate `ten-thousand-catalogue` private output directory. Normalize only explicit new supported OSM types; source names required. Screen identities against the entire native baseline, prior accepted additions and new cohort. Write every hold/pair decision and selection checksums. Unit-test unmapped/vacant types, source restrictions, duplicate node/way/branch ambiguity, exact existing owners, licensed source bounds and immutable outputs. Fetch public OSM evidence in bounded cohorts using the existing source-proof reader. Do not weaken the 20k geometry-node bound; split cohorts if necessary.

**Expected:** A sufficient cohort to exceed 10k after source/geometry refusals, with all inputs and receipts frozen. If the actual accepted supply is insufficient, extend verified open-source discovery within the same quality rules.

## Task 3: Native combined application, query checks and export

Replay the previous two additions and 690 facility qualifications using fresh compatible native previews, then add the selected new named destinations with native observation recording. Before import, validate the complete proof and frozen points in PostGIS and enforce no existing source owner. Apply native baselines, protected identities, fee/access/conflict checks and exact related-table protections.

Export every unique eligible native record in bounded batches. For each, require a readable effective name, supported category, valid sourced point, matching PlaceResource/CandidateRepository identity and unchanged media selection. Verify representative fine/coarse nearby requests, strict-free exclusion for unknown fees and real Places/API access. Verify new additions replay without churn and previous candidate preservation. Require total >=10,000 and no duplicate source owner or candidate ID. Record coverage/completeness truthfully.

Rollback all mutations, restore nontransactional sequence state and verify all ten baseline tables exactly. Repeat the complete run and compare catalogue payloads after ignoring only generated observation timestamps, using the same frozen source proof. Test forced failure cleanup. Keep previous exports unchanged.

## Task 4: Final review, verification and project record

Run focused Python/native/Pest suites; run required commit hooks. One fresh-context final reviewer examines the whole pass and source/identity/export/rollback contracts. Fix material findings with regressions and green relevant suites; no repeated reviewer loops. Write a simple report with exact total, new additions, type/neighbourhood coverage, unknown facts, photo coverage and local-versus-live scope. Update EXP-69/EXP-72 and their existing BookStack work logs while preserving history and reading back changes. Keep tasks In Progress if broader release/media acceptance remains open.

## Review focus

Fresh proofs with changed tags/nodes/geometry, unrecognized lifecycle/shop types, inconsistent source ownership, nameless/service-context padding, same-brand nearby source ambiguity, unknown fees being inferred free, named businesses collapsing as facilities, broad coarse selectors not accepted by Composer, importer replay/rollback changes, source withdrawal regressions, candidate loss through byIds, and any count derived from raw rows instead of native eligible identities.

## Progress

- [x] Task 1: native category and selector contract
- [x] Task 2: immutable selection and current source evidence
- [ ] Task 3: verified >=10k native export and exact recovery
- [ ] Task 4: final review, tests and tracker records
