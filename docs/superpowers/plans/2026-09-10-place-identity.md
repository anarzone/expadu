# Place Identity Implementation Plan

> **For agentic workers:** Use the approved specification and execute the tasks in order with a failing regression before each behavior change. Closely coupled identity consumers are implemented together; an independent audit component may be delegated. Checkboxes record verified completion.

**Goal:** Reconcile explicitly reviewed duplicates without losing old references or reintroducing aliases through discovery.

**Architecture:** Keep the original Spot rows and attach aliases to one canonical Spot. A read-only candidate report supplies evidence; an explicit reconciliation service locks records, validates a fingerprint and records the decision. Normalize identity at discovery, user-state and Composer boundaries.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL/PostGIS, Pest.

**Spec:** `docs/superpowers/specs/2026-09-10-place-identity.md`.

## Global constraints

Preserve unrelated changes and all original identity records. No automatic merges, licence approvals, frontend edits or production data mutations. Run tests with Herd PHP 8.4, use Node 22 for hooks, and end task commits with `Refs EXP-67`.

## Task 1: Read-only evidence report

Files: `app/Places/PlaceIdentityAudit.php`, `app/Console/Commands/AuditPlaceIdentities.php`, `tests/Feature/Places/PlaceIdentityAuditTest.php`.

Interface: `PlaceIdentityAudit::report(float $radiusMeters = 10): array`; console `places:audit-identities {--radius=10}` emits JSON to stdout. The report is candidate evidence, never executable merge approval.

- [x] Generate a Pest feature test and assert the report contains a source-null/source-OSM pair of identical name/category within 10m, leaves both rows unchanged, and includes typed source IDs.
- [x] Assert different categories, distant records and two sourced records do not become legacy matches. Multiple nearby matches remain listed with ambiguity flags; generic names never imply verified identity. Include counts for reviews, feedback, child/area/venue references, and media rights/health/locks without user identities or review text.
- [x] Run the new file and observe the missing-command failure.
- [x] Implement a spatial join using `ST_DWithin(legacy.location, current.location, ?)` with a bound radius, exact name/category comparisons and source-null legacy filtering. Aggregate references in batches. Use stable ordering and include source attributes and eligibility in each record.
- [x] Validate radius as numeric, greater than zero and at most 100m. Mark `review_required: true` on every candidate; `auto_merge_allowed: false` at report level. Emit valid JSON with no console decoration.
- [x] Run the audit tests and record results; review report output without catalogue mutation.

## Task 2: Explicit aliases and reconciliation

Files: new migration for `spots.canonical_spot_id` and `place_reconciliations`; `app/Places/PlaceIdentity.php`, `app/Places/ReconcilePlace.php`, `app/Models/Spot.php`; `tests/Feature/Places/PlaceIdentityTest.php`.

Interfaces: `PlaceIdentity::canonicalIds(array $ids): array` maps integer IDs to canonical integer IDs; `candidateIds(array $ids): array` normalizes `spot:<id>` while preserving other candidate types; `familyIds(int $id): array` returns canonical plus aliases. `ReconcilePlace::preview(int $aliasId, int $canonicalId): array` returns a stable fingerprint; `apply(int $aliasId, int $canonicalId, string $fingerprint, string $evidence): void` validates and applies within a transaction.

- [x] Write tests for retained rows, source values, old route binding, canonical-only eligibility, source refresh, idempotence, stale fingerprints, missing targets, cycles and category/proximity mismatches.
- [x] Run tests red, then generate the migration with Artisan. Add a restrictive self foreign key and index; create an audit record with original attributes and supplied evidence. No data backfill in the migration.
- [x] Implement ID normalization in batches; resolve route binding once to the canonical row. Add an explicit `canonical` query scope and compose it into `recommendationEligible`.
- [x] Implement locked reconciliation with fail-closed validation and atomic parent/area/venue reference remapping. Retain source rows and feedback/review/media relationships.
- [x] Run tests green and review the transaction's failure paths before enabling a command.

## Task 3: User state, discovery and media preservation

Files: `SpotFeedback`, `Review`, `Spot`, `PlacesController`, `PlaceFeedbackController`, `ReviewController`, `PlaceContextController`, `SpotSearchController`, `DiscoveryFeed`, `EventTrackingService`, `VenueResolver`, `PublishedMediaSelector`; focused existing and new feature tests.

- [x] Add regression cases where an alias owns the only saved feedback, a conflicting older review and approved media; canonical requests must preserve the effective state and eligible photo. Verify pending/broken media and manual locks retain their protection.
- [x] Implement family-aware effective feedback/reviews and canonical writes. Clear all family feedback for the acting user only. Average ratings count one effective review per user.
- [x] Apply canonical-only discovery constraints to raw ranking and non-scoped consumers. Normalize family relationships for detail context and destination activity queries.
- [x] Add a scalar identity revision to discovery cache keys so completed reconciliation expires every cell logically.
- [x] Run Places, feedback, reviews, discovery and media-selector tests once the new regressions pass.

## Task 4: Composer and operator workflow

Files: `CandidateRepository`, `ComposerController`, `TodayPlanStore`, `ReconcilePlaceIdentities` command; Composer and reconciliation command tests; this plan and EXP-67 wiki page.

- [x] Add regression tests for old-ID pins, exclusions, locked picks, saved slots and rejected swap choices; assert existing times remain unchanged.
- [x] Normalize incoming/stored candidate IDs at the boundaries, explicitly hydrate stored Spot IDs outside the nearest pool, and keep unknown/non-Spot IDs unchanged.
- [x] Add `places:reconcile-identity {alias} {canonical} {--fingerprint=} {--evidence=} {--apply}`. Default to preview. Applying requires nonempty documented evidence and the matching preview fingerprint. It is not scheduled.
- [x] Run affected Composer/import tests, Pint, and the project hook checks. Obtain an independent diff review and address reference-safety findings.
- [ ] Update Jira/BookStack with tests and limitations. Prepare a staging review; no production mutation. Commit only EXP-67 files with the required ticket reference.

## Verification record

- Baseline: `PlaceImportIntegrityTest.php`: 16 passed, 61 assertions (Herd PHP 8.4; local PostgreSQL and Redis).

- Identity, audit, Home and Composer: 94 passed, 373 assertions.
- Review-fix verification with feedback/review/Composer endpoints: 76 passed, 319 assertions.
- Final identity + media persistence + importer regression run: 52 passed, 199 assertions.
- Independent review found four blocking cases (repeated choices, media deletion, unavailable saved slots, and concurrent review writes); fixes received a clean scoped re-review.
- Changed saved candidates now produce an explicit conflict instead of silently dropping or renumbering slots. Unavailable pinned places produce a validation response.
- Canonical targets are sourced OSM records; aliases remain source-null and are not refreshed by the OSM identity importer. More general cross-provider reconciliation is outside this first operator path.

- Required commit hooks: gitleaks clean; staged Pint clean; fast parallel Pest suite 1,475 passed, 1 skipped, 5,853 assertions. No hooks bypassed.
- Staging and production catalogues remain unchanged. The migration has only been exercised in testing. EXP-67 remains in progress until staging validation.
