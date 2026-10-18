# EXP-69 / EXP-72 — finish the 374 held refreshes

Continuation authorized by the owner after the verified 4,269-record staging rollout.
The existing production/data constraints and documented recovery approach remain in force.

## Outcome and chosen design

Enable safe recovery for a refresh that introduces the first observation in a source
stream. Preserve place IDs, references, source history, approved corrections, and all
44 previously committed journals. Do not invent old source evidence or delete an import.

Append an explicit `withdrawal` event using the existing observation schema. Its
payload contains audit metadata (target observation ID and the approved preview
fingerprint), not publishable facts. Keep actor, reason, timestamp and a deterministic
operation key. A separate service accepts only a single newly introduced source
observation, locks the canonical place/history, binds a full preview fingerprint,
rejects changed/future history, and makes checked replay a no-op.

No nullable column is added: that would alter the raw snapshot shape for all existing
operation receipts. Source ingestion continues to reject unsupported audit-payload
fields. Restore refuses a withdrawal marker as a source snapshot. Replaying a withdrawn
ingestion key raises an error so importer transactions cannot republish cancelled
evidence through overwritten legacy fields; genuine new evidence needs a new key.

For consumer facts, an audited withdrawal excludes exactly the targeted observation
with matching source identity and payload hash, plus the audit marker itself. A later
genuine source arrival can reactivate the stream, even with an older source timestamp. Withdrawn names/aliases, practical-field history and
conditional-fee history must no longer suppress legacy fallback. The full audit
relation remains intact. SQL access checks exclude that exact target before selecting the latest effective
record. One materialized raw-history CTE preserves the existing single base-table
scan while allowing a small withdrawal-target set to be shared by the query.

Activity-review freshness continues to use all event IDs: withdrawal must not revive
an obsolete facility qualification. The guarded 374-record importer continues to
refuse existing access/fee/activity qualifications that need independent review.
Conservative search hints may retain superseded positives; shared resolved facts
remain the final authority. Check reconciliation of overlapping histories: the target-specific exclusion must
not hide another observation merely because it shares the source stream.

## Implementation sequence

1. Add focused Pest regressions first. Cover legacy names/aliases/fees/practical
   restoration, root and parent SQL parity, unknown facts, another provider and
   reviewed corrections, unchanged-data reactivation, late arrivals, stale preview,
   actor/reason/hash validation, canonical movement, immutable history and replay.
2. Implement the withdrawal service and shared active-history selector. Update the
   PHP resolver, practical-history fallback and both SQL access paths. Keep existing
   source import/recovery behavior unchanged for ordinary histories.
3. Create versioned held-batch helpers and tests; leave the earlier frozen helpers
   untouched. Recover first-source refreshes via withdrawal plus exact importer-owned
   column restoration; prove substantive facts, aliases and eligibility return.
4. Qualify all 374 frozen records in an isolated zero-user public-data database,
   including saved-data APIs, exact Composer membership, recovery/replay, and the
   4,269 already committed records' untouched facts. Measure discovery performance.
5. Review code, run focused checks and normal commit/CI gates, release the application
   to staging, and verify running hashes/APIs. Preserve the private host evidence
   across container replacement. Production stays unchanged.
6. Reconcile the live held records, run a rollback-only staging qualification,
   then apply bounded held cohorts with per-batch independent readbacks. Reverify
   the complete frozen 4,643-record workload and catalogue totals. Save summaries
   only locally and update Jira/BookStack without marking broader coverage Done.

## Files and verification

Application: new `app/Places/WithdrawPlaceObservation.php` and
`app/Places/PlaceObservationHistory.php`; change `PlaceFacts.php`,
`PlaceCapabilities.php`, `RecordPlaceObservation.php`, and reconciliation guards
only if needed to preserve mixed-source history. Tests live under
`tests/Feature/Places/`. New versioned operational helpers/evidence live with this
release; old journal receipts and guarded helpers remain immutable.

Use PHP 8.4 and the dedicated local test database; no real user accounts/plans.
Run the new regressions red then green, the affected Places/Composer/identity suite,
formatting, and normal required commit/CI checks. Staging evidence must verify actual
saved state through a fresh read-only connection, not merely an importer success.
No photos are approved, no access/fee claims are inferred, and no source is approved
without a documented basis.
