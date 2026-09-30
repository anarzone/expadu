# Source provenance hold implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Prepare a reversible complete-cohort exclusion of source-null Places without losing stored data or saved references.

**Architecture:** An operator-only journal uses the existing catalogue operations table and native revision service. It hashes full protected rows and relationship projections, owns only flags/timestamps, locks writers and refuses drift before apply or recovery. Read-only staging preparation uses the existing guarded lab bootstrap.

**Tech Stack:** PHP 8.4, PostgreSQL/PostGIS, Laravel 13, Pest.

**Spec:** `docs/places/production-release/2026-09-30-source-hold/SOURCE-HOLD-SPEC.md`

## Global Constraints

- Target the complete current source-null cohort, bounded to 1–5,000 spots. Reject partial, changed or oversized cohorts.
- PHP 8.4, PostgreSQL/PostGIS and existing Laravel 13 services; no new dependency, schema change or live mutation.
- Do not read or copy real user records, review text, media bodies or credentials into the journal or exported evidence. Hash protected source/history rows in place; retain only owner IDs, original flags/timestamps and digests.
- Require a caller-owned READ COMMITTED transaction, target database, exact helper/runtime/package SHA-256, UUID, actor and a documented reason. Lock writers before the current-state check.

## Review Focus

- New source-null rows appearing after preparation must prevent partial exclusion.
- Source binding after exclusion must prevent unsafe recovery.
- Existing canonical aliases must keep native detail and Composer resolution after both operations.
- Corrupted or mismatched receipts must never authorize flags restoration.
- Fact/history and incoming relationship changes must refuse without a partial write; independent saved-reference changes must remain untouched.

### Task 1: Bounded reversible operator journal

**Files:** Create `docs/places/production-release/SourceProvenanceHoldJournal.php`, `docs/places/production-release/SOURCE-PROVENANCE-HOLD.md`, and `tests/Feature/Places/SourceProvenanceHoldJournalTest.php`.

**Interfaces:** Consumes native `PlaceFactRevision::bump()`, `ReconcilePlace`, `CandidateRepository::byIds()`, `GET /api/places/{id}`. Produces `snapshot(): array`, `applicationHash(): string`, `hash(array): string`, `apply(string $id, array $context, array $baseline, string $actor, string $reason): array`, and `recover(string $id, array $context, string $actor, string $reason): array`.

- [ ] Write real-database tests before the helper. The principal regression is:
```php
$before = $journal->snapshot();
$receipt = $journal->apply($id, $context, $before, 'test-operator', $reason);
expect($unknown->fresh()->is_active)->toBeFalse();
expect(app(CandidateRepository::class)->byIds([(string) $unknown->id], $day))->toBe([]);
expect($alias->fresh()->canonical_spot_id)->toBe($canonical->id);
$journal->recover($id, $context, 'test-operator', $reason);
expect($journal->snapshot())->toBe($before);
```
- [ ] Run `python3 /tmp/exp69-run-production-port-tests.py tests/Feature/Places/SourceProvenanceHoldJournalTest.php`. Expected: missing-helper failures; preserve the output.
- [ ] Implement bounded metadata-only snapshot, exact runtime fingerprint, READ COMMITTED guard, locks, durable apply/recover/replay and native revision bump. Validate request with:
```php
hash_equals($context['package_sha256'], self::hash($baseline));
hash_equals($context['application_sha256'], self::applicationHash());
hash_equals($context['importer_sha256'], hash_file('sha256', __FILE__));
```
- [ ] Run the same focused test command. Expected: all tests pass, covering native consumers, full state preservation, baseline/cohort/evidence drift, receipt/context corruption, transaction isolation, replay, and caller rollback.
- [ ] Format the PHP helper and tests with project Pint; run the source-hold plus facility and legacy journal tests. Expected: green.
- [ ] Write operator documentation with exact request fields and release boundaries. Commit only this addition using normal hooks after the final review gate. Expected: secret scan, formatting and normal suite succeed.

### Task 2: Actual-target preparation and publication

**Files:** Add private-server rehearsal scripts and aggregate evidence under `docs/places/production-release/2026-09-30-source-hold/`; update the existing public report/status/manifests and ticket/wiki history.

**Interfaces:** Consumes Task 1 `snapshot()` and context fingerprints; existing guarded staging SELECT-only connection and isolated `labBoot()`. Produces lab before/apply/recover/rollback hashes and exact staging aggregate package hash; exports no raw rows.

- [ ] Rehearse apply/replay/recover in an outer lab transaction against a restored pre-hold flag baseline; run native eligible queries. Verify outer rollback restores all protected tables. Expected: no persistent lab or live edits.
- [ ] Use the guarded SELECT-only staging connection to prepare a helper-native baseline and context on the server. Expected: 4,356 complete source-null rows if staging remains unchanged; any changed count is recorded as actual evidence rather than assumed.
- [ ] Complete one independent final review of this addition. Critical/Important findings receive one RED→GREEN fix pass; defer Minor findings explicitly. Expected: tested gate and no repeat review of completed facility/legacy changes.
- [ ] Push the reviewed addition to existing draft PR61 and verify exact-head CI. Preserve unrelated changes, no merge or deploy. Expected: application and browser CI succeed; deploy jobs skipped.
- [ ] Append verified aggregate findings to EXP-69/72 and BookStack pages33/36, preserve prior history and verify exact readback. Update public manifests. Expected: records remain In Progress with live rollout and photos explicitly open.
