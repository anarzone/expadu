# Final legacy journal review dispositions

One independent read-only review of the completed branch and new legacy helper,
without another facility review or database/network test run. Verdict With fixes:
zero Critical, one Important, one Minor. One executor fix pass; no re-review.

## Important findings and bounded behavior

1. Important — blank/relative source timestamps bypass expiry. Carbon treats an
   empty timestamp, now/today or a relative offset as current time. Require a valid
   absolute ISO timestamp with explicit timezone and validate its calendar value
   before applying the 24-hour/five-minute window. Seven invalid-second-record
   cases reproduce this; exact reasons and atomic state preservation are required.
2. Declined transaction isolation, regraded Important — the guarded protocol must
   not claim current-row drift checks when a caller uses an earlier repeatable
   snapshot. Bound the helper to READ COMMITTED, the successful trial's mode, and
   explicitly refuse REPEATABLE READ/SERIALIZABLE before a link. Two real database
   isolation regressions reproduce acceptance before the guard.

## Deferred minor

The later saved-reference regression inserts feedback directly on the alias,
while the actual feedback endpoint resolves a new save to the canonical ID.
Both owners are snapshotted, and the reviewer found no recovery defect. A future
endpoint-level save-after-link regression would strengthen coverage; this phase
claims actual detail/saved-ID retrieval and row drift guards, not a new endpoint
write test. Deferred as Minor, no speculative implementation change.

## Every behavior declined by the reviewer

- Earlier source/media/facility review repetition: those unchanged phases retain
  their completed independent reviews and green CI. This review assessed relevant
  journal/native interactions; another facility review is not claimed or needed.
- Private source responses/receipts: reviewer lacked network access; the executor
  fetched current source objects and geometry and tested the journal in the lab.
  No independent reviewer claim of private evidence authenticity is made.
- Source authenticity enforcement in helper: operator prerequisite; a checksum
  binds evidence but does not authenticate a provider response. Documented as such.
- Real process crash/restart/cross-process committed recovery: not tested; the
  observed recovery uses durable journal state with new helper instances inside
  a caller-owned rolled-back transaction. No stronger durability claim is made.
- Live deployment/backups/privileges/writer coordination/lock contention: live
  operation is still outside authorization and requires fresh target evidence,
  coordinated writers and backup verification. Non-default isolation was regraded
  into the Important guard above rather than silently left as a broad guarantee.
- Larger cohorts/historical-alias reuse/fact migration/unsupported references:
  explicitly refused; aliases with earlier audit/history or incoming references
  require a separate reviewed continuation protocol. No blanket legacy reenable.
- New media rights/verified entrances/inferred access or fees: excluded; no rights
  approval or new fact is inferred by linking references.
- Fresh tests and CI: reviewer did not run them; executor records actual local
  tests, normal hooks and exact-head CI separately, without attributing them to
  the reviewer.

## Verification

The original helper/native set passed91/401 and the484-pair rehearsal passed.
Review fixes' RED/GREEN, final trial/helper hash and normal hooks/CI are recorded
below only after their runs complete.

Review fix verification: all seven absolute-timestamp cases failed before the
fix, and both correctly initialized isolation cases failed before their guard.
The completed named journal/native suite passed **100 tests / 439 assertions**
in53.07s after the one fix pass. No second review was dispatched.

The final formatted helper SHA-256 is
`73d9a38b85247da17f1d8a702a54bf645929f89561fd1d546096037a502013b3`.
Its all484-pair trial passed14:16UTC:484 applied API/Composer resolutions,484
recovered API/Composer exclusions,484 retained audit rows,14 protected tables
restored by outer rollback, zero synthetic users and no added unique places.
