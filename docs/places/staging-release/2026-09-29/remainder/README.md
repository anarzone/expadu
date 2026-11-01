# Remaining catalogue rollout

## Current execution result

All 43 remainder batches are committed and independently verified on staging:
1,824 additions and 2,345 refreshes. Together with the first 100, the saved change
set is 4,269 records. Final staging contains 11,802 stored records and 44 applied
operation journals. The 374 held refreshes remain unapplied.

See [final verification](../final-catalogue-verification.json),
[committed additions](committed-additions.json), [committed refreshes](committed-refreshes.json),
[staging rollback qualification](staging-rehearsal.json) and
[local cumulative apply/recovery qualification](local-cumulative-wave.json).
The unchanged operator SHA-256 is
`2d66dff84804348905e16a6ca6f7afe6e9ffcccb6896c466846ba3fb2549e8a7`.

The owner instructed continuation after the prepared workload and timing were
explained. Automatic approval review accepted both qualified staging cohorts.
No production write, held-record update or committed staging recovery occurred.
The final verifier and isolated application runtime hashes are recorded in the
final evidence. Private operation receipts remain on the staging host.

## Historical preparation and qualification design

Statements below about a future or unauthorized remainder rollout describe the
pre-execution checkpoint; the execution result above supersedes them.

The first 100 records were committed to staging on 29 September 2026 at
15:07:11 UTC and independently verified through a fresh read-only connection.
They are excluded from every manifest here.

`batches.json` contains exactly 4,169 unchanged prepared records in deterministic,
source-key-sorted cohorts bounded by the existing 100-record recovery limit:

- 19 addition batches: 18 × 100 and one × 24 = 1,824 new records.
- 24 refresh batches: 23 × 100 and one × 45 = 2,345 updates with prior same-source history.

`held-no-prior-history.json` preserves the other 374 updates. They remain part of
the original 4,643-record package, but cannot use the existing recovery rule.
They are not reclassified as additions or silently removed from the workload.

The frozen records package SHA-256 remains
`ecfc2777acf6e91d8e574394418120163e725d2b432201179fbcc60fd20f605a`.
The batch manifest SHA-256 is
`8c8fdfe7a3933782d0ea6ca4debe14121e7c8014d0137656e11a5ccb4373982b`.
History membership is derived from the earlier zero-user public local baseline.
It is a prerequisite, not a claim that every remaining record is ready to commit.

The rollback-only `../verification/canary/rehearse-remainder.php` checks each
batch independently. It uses the unchanged reviewed importer and recovery
helpers, source fingerprints, bounded transactions and catalogue locks. It checks
all detail APIs, source-backed names/coordinates/tags, explicit unknown fees,
shared Composer facts, unaffected discovery membership, recovery and replay.
Ten catalogue/media/journal table digests must match after each rollback.

The local run freezes exact per-source-key display names and before/after
discovery and Composer membership. The staging run requires that exact frozen
acceptance file and compares every record against it. It also verifies that the
already committed first batch is still applied and unchanged.

These are independent batch rehearsals against the same baseline. They do not
prove cumulative performance or authorize permanent rollout. Any failed batch
remains held. A future committed wave must recheck its changing baseline, retain
each operation's durable journal, support a reviewed recovery invocation and
verify the cumulative catalogue through a fresh connection. It must not replay
the first operation or change the 374 held source streams.

No photos are added or approved by these batches. Unknown access and fees stay
unknown, and free/public football coverage is not inferred from source counts.


## Frozen cumulative operator candidate

The candidate `../verification/canary/run-remainder-batch.php` uses the same
reviewed bounded importer and recovery helpers. It pins this manifest and the
frozen local acceptance, checks the exact first committed operation, and enforces
an ordered prefix of the 43 remaining operations. Before committing a new batch,
it rechecks every earlier committed snapshot and the exact resulting row count.
A separate `verify-remainder-prefix.php` uses a fresh read-only connection to
verify the durable journal, all prior snapshots, target APIs and Composer facts.
The next batch may start only after that readback succeeds.

Recovery is limited to the last applied batch and requires its own explicit
action acknowledgement. A recovered tail blocks further import until reviewed.
Recovered-state readback independently proves original resolved facts and
eligibility for refreshes, and inactive/nonrecommendable/excluded additions.
Prepared receipts and summaries are action-specific and include a run ID, state
transition and replay effect. The database journal remains authoritative after
any ambiguous process exit.

This is a candidate until cumulative runtime qualification finishes; there is
no staging remainder commit authorization or attempt. Its isolated local test
saves each batch in a fresh zero-user database, then independently reads it back.
After all 43 applies pass, it deliberately recovers the final refresh batch and
repeats recovery. Therefore that lab ends with 42 applied remainder journal rows
and one recovered row, plus the separately reproduced first batch. Its stored
row count is not a claim that all 43 remain active in that local final state.
