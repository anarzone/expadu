# Reversible source provenance hold

`SourceProvenanceHoldJournal.php` prepares and journals the existing source-boundary decision: source-null places remain stored but cannot be recommended. This operator helper is not an automatic importer or application endpoint. No staging or production operation is authorized by this document.

## Scope and retained behavior

The complete current source-null cohort must contain 1–5,000 rows. Apply sets only `is_active = false`, `is_recommendable = false` and `updated_at`. It retains IDs, names, coordinates, tags, source fields, canonical pointers, facts, native audits, media and saved references. An old canonical alias continues to resolve to its source-backed destination. An unresolved old detail remains readable with recommendation status `unavailable`; listing and Composer eligibility exclude it.

Snapshots contain owner IDs, flags, timestamps and SHA-256 digests. Protected history is represented by row ID and digest; incoming relationships by digests of their IDs and relation fields. The helper does not query user, review or media tables. Independent saved-reference edits do not prevent restoration because this operation never owns those rows.

The complete cohort is rechecked under locks. An added unknown record, source binding, changed owned field, fact, audit or incoming relationship prevents apply or recovery. Recovery restores only the original flags and timestamps. The catalogue journal remains and the facts revision advances on apply and recovery.

## Preparing an exact target

Use a pinned code checkout and a read-only connection to the approved target. Keep the baseline on that server with mode 0600. Export only aggregate counts and digests. `applicationHash()` covers PHP code in `app`, `config`, `routes` and `database/migrations`, plus `bootstrap/app.php` and `composer.lock`; the helper has its own hash.

```php
require base_path('docs/places/production-release/SourceProvenanceHoldJournal.php');
$journal = new SourceProvenanceHoldJournal;
$baseline = $journal->snapshot();
$context = [
    'database' => DB::selectOne('select current_database() as name')->name,
    'package_sha256' => SourceProvenanceHoldJournal::hash($baseline),
    'application_sha256' => SourceProvenanceHoldJournal::applicationHash(),
    'importer_sha256' => hash_file('sha256', base_path('docs/places/production-release/SourceProvenanceHoldJournal.php')),
];
```

Names, source payloads and credentials are never exported by this preparation. The baseline includes original flags/time needed for recovery, not full raw source rows. A changed runtime requires a newly prepared context; do not edit the context to bypass a refusal.

## Authorized execution and recovery

Before execution, satisfy the release runbook's target, backup, writer-pause, deployment and explicit live-operation approval gates. Use a fresh UUID, an actor of 1–191 characters without surrounding whitespace and a reason of 20–2,000 characters. Both mutating methods require a caller-owned READ COMMITTED transaction. A stale REPEATABLE READ transaction is refused.

```php
$receipt = DB::transaction(fn () => $journal->apply(
    $operationId, $context, $baseline, $actor, $reason
));
// A separately authorized recovery uses the recorded target/code context.
$recovery = DB::transaction(fn () => (new SourceProvenanceHoldJournal)->recover(
    $operationId, $context, $recoveryActor, $recoveryReason
));
```

The helper opens a nested transaction/savepoint, locks the relevant catalogue writers with a 10-second lock timeout, checks the current cohort, changes only owned fields and writes its durable receipt. It does not commit the caller's transaction. A new instance replays an applied request only with the same actor, normalized reason and unchanged after-state. Recovery replay requires the same recovery actor/reason and restored state. An already recovered UUID cannot be reapplied. Do not catch a refusal and proceed with partial flags restoration.

Protected writer tables are `spots`, `place_fact_observations`, `place_fact_corrections`, `place_fact_revisions`, `place_reconciliations`, `place_destination_reviews`, `park_areas`, `venues` and `place_catalogue_operations`. This is a bounded maintenance operation, not a request-time operation.

## Release ordering and limits

Finish reviewed identity reconciliation before preparing this hold. Prepare source-backed facility qualification snapshots afterward; applying an earlier facility snapshot across changed parent flags is unsafe. Recovery of stacked operations must undo later facility qualifications before this hold. Compatibility with identity recovery, including retained identity audit timestamps, must be checked against the actual recorded snapshots before live approval. This document does not claim a complete cross-journal sequence has been rehearsed.

Source identity is a provenance boundary, not proof that every source-backed field was freshly checked. This helper adds no unique destinations, approves no image rights and does not solve the 75% photo target. Stadt Köln structured records and unapproved images remain excluded under the existing source/media policies.

The staging check on 30 September found 4,356 source-null records, including 798 existing aliases and 2,350 currently eligible records. Its read-only projection retained all 11,802 stored rows and left 4,224 source-backed destinations plus 11 linked components eligible. Treat these as dated staging evidence, not production counts or a claim that every destination is production ready.
