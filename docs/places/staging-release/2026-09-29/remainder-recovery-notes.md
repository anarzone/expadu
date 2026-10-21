# EXP-69 / EXP-72: remainder recovery work

Completed on staging on 29 September 2026: PR60 supplied audited first-source
withdrawal, all 374 held refreshes passed recovery qualification, and all four
batches are saved and independently verified. See [current report](REPORT.md)
and [final evidence](held/staging-final.json). The implementation discussion below
is a historical pre-implementation checkpoint, not an outstanding blocker.

## Historical recovery requirements

This is an implementation note, not a completed readiness claim. The current
release is PR59, commit 7d00ad065b6a571db24a083f276373e13e1bdf19.

The frozen 4,643-record package contains 1,874 additions and 2,769 refreshes.
Of the refreshes, 2,395 have an earlier same-source observation and 374 do not.
All 1,874 additions and 2,395 refreshes with prior same-source history are now
committed and independently verified on staging. Their exact saved state and
Composer/API behavior are recorded in `final-catalogue-verification.json`.
The remaining 374 need a separately implemented and verified recovery mechanism.

Current native restore appends a copy of an earlier observation. It cannot restore
the absence of a source stream. Deleting the newly imported observation would
erase audit history; adding a fabricated legacy source observation would invent
provenance. Neither is an acceptable shortcut.

A future implementation must represent an audited withdrawal of a newly introduced
source stream without deleting history. The PHP fact resolver and SQL public-access
policy must agree about withdrawn streams and their legacy fallback, including
name/alias behavior, fee conditionals, source freshness and correction precedence.
It must preserve current IDs, references and unrelated provider streams; refuse
stale state under the same canonical lock; bump the revision; and be replay-safe.

Before rollout, add synthetic regressions and a separate zero-user public-data
rehearsal for all 374 affected keys, proving both restoration of pre-import facts
and unchanged recommendation decisions. Include late source observations and
reviewed corrections. Then run a specifically reviewed staging rollback check.
Do not weaken the current guard or call these 374 recovery-ready beforehand.


## Exact held membership, 29 September

The [held manifest](remainder/held-no-prior-history.json) now freezes all 374
source keys and their original package record hashes. All are OSM refreshes:
256 tennis, 60 table tennis, 30 pitches, 14 viewpoints, eight basketball,
four skateparks and two boules facilities. These are existing identities with
no earlier observation in the same source stream, not missing source records.

The existing implementation resolves both effective observations and historical
observations. A recovery change must cover more than latest-value selection:
`PlaceFacts::resolveName()` uses historical names/aliases; conditional-fee legacy
fallback checks whether historical same-source observations contained a
conditional field; the SQL access policy and activity-review observation checks
also consult observation history. A withdrawal that only filters the newest
payload would leave those paths inconsistent. A new audited withdrawal needs
explicit stream ordering and restore semantics across all of them, plus
regressions for a later real source refresh and concurrent review decisions.

The 43 ordinary remainder batches use the existing native recovery helpers
unchanged. Their progress and limits are documented in [remainder README](remainder/README.md).
