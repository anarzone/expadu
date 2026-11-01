# Places production rehearsal — 30 September 2026

Refs EXP-69, EXP-72. Candidate: [draft PR #61](https://github.com/anarzone/expadu/pull/61),
commit `554eab971d3b2b43ec9312a893d292302bb6b0b3`.

All 4,643 prepared source records passed the isolated production-copy rehearsal.
The resulting catalogue has 4,182 Composer-retrievable places. That is an application
eligibility count, not a claim that 12,063 stored records are production ready or
that every remaining source has been freshly verified. Production was not deployed
or imported. The full Places programme remains open.

## What changed and was checked

- Recreated all 89 production-baseline migrations and copied 8,798 places, 86
  neighbourhoods, 231 destination boundaries and only place-linked media. The ten
  candidate migrations passed and preserved every original place field. Production
  reads were enforced read-only; no account or personal tables were copied. The
  isolated app used a separate database role, verified before application boot.
- Initially rehearsed 3,335 unambiguous named records, then completed the remaining
  1,308 source observations: **3,265 additions and 1,378 updates in total**. All 48
  source operations passed apply replay and individual native recovery checks.
- Added a separately journaled hold for **4,680 unknown-origin legacy records** in
  the lab. Their IDs and records remain present. This removes their unverified
  legacy copies from discovery without guessing identity merges or deleting data.
- Checked source identity, names, coordinates, practical tags and explicit unknown
  fees/access for every package record. Composer retrieves 4,079 package records;
  564 remain ineligible: 559 descriptive facilities and five pre-existing holds.
  These records were updated, not silently promoted into recommendations.
- Retrieved all 4,182 eligible Composer identities and checked uniqueness and
  shared facts. Exercised ten real GET listing/nearby routes and 24 category detail
  samples through the candidate application's HTTP kernel. Unknown facts stayed
  explicit: 4,097 candidates have unknown cost, 4,060 unknown access and 2,743
  unknown hours.
- Completed recovery of all 48 source operations and the legacy hold. It restored
  original fields for 8,798 places and their original discovery membership;
  3,265 additions became inactive/nonrecommendable. Repeated recovery was safe.
  The outer rollback restored the imported lab state exactly by checked table
  digests. Recovery retains audit history, sequence allocations and some update
  timestamps; it is not a replacement for a database backup.

A separate review found that an interruption before the final progress file was
written would leave the original recovery entry point unusable. The helper now
reconstructs committed work from validated database journals and the pre-cutover
anchor. Recovery passed at four simulated interruption points: after the hold,
and after 1, 7 and 14 remainder batches. Every simulation restored original fields
and discovery membership, checked repeat recovery, and rolled back to the exact
clean lab digests. These are rollback-only prefix simulations with retained inactive
additions/history, not actual process-kill or crash-durability tests. The reviewed
fix changes the operational helper only, not the candidate application.

## Counts with their actual meanings

| Count | Meaning |
|---|---|
| 12,063 | Stored lab records, including held/inactive entries. Not a readiness total. |
| 4,643 | Prepared source records fully rehearsed: 3,265 additions + 1,378 updates. |
| 4,079 | Package records retrievable by Composer. |
| 103 | Older candidates outside the package. Fresh source screening was performed, but they were not updated or fully qualified. |
| 4,182 | Total eligible and exhaustively Composer-retrievable identities. |
| 4,680 | Unknown-origin legacy records held, retained and recoverable. |
| 564 | Package records still not recommendation-eligible. |
| 69 | Eligible places with a freshly checked photo association in the rollback-only trial. |

The first partial rehearsal yielded 7,323 candidates, including 3,141 unknown-origin
legacy records. The final 4,182 count excludes those 3,141. It must not be presented
as a loss of newly sourced records or hidden by quoting the larger stored total.

## Duplicate and availability decisions

All 749 potential duplicate pairs were examined with the frozen identity rules.
Each source record has exactly one unknown-origin legacy counterpart; none shares
phone or website evidence. 575 match normalized name/category within two metres;
31 share an address. No identity merge was performed. The uniquely keyed source
records were refreshed and their uncertain legacy counterparts held.

This policy has a substantial availability cost. Compared with the partial lab
before the hold, API discovery totals change as follows:

| API category | Before legacy hold | Final lab |
|---|---:|---:|
| Food and drink | 3,685 | 3,358 |
| Culture | 245 | 243 |
| Parks | 264 | 136 |
| Pitches | 365 | 15 |
| Courts | 657 | 9 |
| Playgrounds | 1,180 | 142 |
| Dog parks | 48 | 24 |
| Swimming | 8 | 8 |

These are application filter totals, not category coverage of Cologne. Retaining
IDs does not preserve the previous visible behaviour: synthetic saved Today plans
containing held places became unavailable in all 20 sampled categories, while their
cached snapshots and identity references remained intact. All 4,680 held IDs were
excluded from Composer. Their direct detail routes still returned 200 without an
explicit active/held flag, and without publishing pending media. This inconsistency
needs a deliberate user-facing treatment before a live hold. Real favourites and
reviews were not copied or exercised. This availability change requires a product
decision before a live hold.

No unconditional free/public football result was established within three
kilometres of the shared central-Cologne origin (50.938, 6.957). Both eligibility
checking and the bounded Composer search returned zero. Do not turn unknown prices
or access into a free/public claim to increase this count.

## Fresh check of the 103 older source-backed candidates

A bounded public OSM query on 30 September returned 100 of the 103 exact source
IDs. All returned nodes or bounding-box centres were within 100 metres of the
stored position. Seventy-five names matched a current source name or alias; 25
need review. Thirteen returned elements had lifecycle tags, which can refer to an
old function rather than prove that the whole place is closed. Three source IDs
were not returned. These categories overlap; none is an automatic approval or
removal decision. The response and detailed comparisons remain server-side.

The [source-check receipt](remaining-source-check-summary.json) records the public
response hash and snapshot time. The query used documented
[OSM ID and centre output](https://wiki.openstreetmap.org/wiki/Overpass_API/Overpass_QL).
No database rows changed. This closes the question of whether those sources were
looked up; their exact name/category/lifecycle corrections still need qualification.

## Existing-photo recovery

The ten migrations put all 225 copied attachments into pending match status.
Without a reviewed recovery step, all 187 previously photographed places would
lose their displayed image under the new matching policy.

After the final source updates, 77 existing associations had a direct reference to
check. Three matched copied OSM Commons tags, 64 matched current Wikidata P18
claims and six matched current Wikipedia page images. Four remained unverified or
changed. All **73 matched files** passed fresh Commons licence/provenance checks
and image-byte health validation. The selector served the exact checked asset in
each trial. Municipal source exclusions remained enforced.

Of the 73 places, **69 are eligible** in the final catalogue: **1.65% of 4,182**.
This is recoverable existing coverage, not newly acquired broad coverage. The trial
was rolled back and table digests verified before its receipt was written. No
persistent media approvals were added; the lab's approved-match photo count remains
zero outside the trial. The other 114 previously photographed places lack enough
evidence under this review. The 75% photo objective is unmet. Copied OSM tags were
not independently refetched or visually reviewed in this step.

## Remaining release conditions

1. Decide whether the large legacy-only sports/playground availability reduction
   is acceptable for an initial release, or establish provenance before applying
   that hold. Keep unknown facts explicit either way.
2. Resolve the fresh source findings for the 103 older candidates and provide an
   explicit unavailable state for held references before a live hold. Synthetic
   tests already establish the Today-plan/detail mismatch. Duplicate identity
   merging and descriptive-facility eligibility remain separate evidence work.
3. Package the 73 proved photo associations into a concrete live operation with
   fresh target IDs, current source tags, licence/health checks and durable recovery.
   Preserve unproved attachments as pending. This is not yet a live photo import.
4. Recheck live drift, capture and verify a backup, then request approval for the
   exact deployment and data operation. Never reuse lab IDs or operation receipts.

PR #61 remains draft; EXP-69 and EXP-72 remain In Progress. No production merge,
deployment, legacy hold or live import was performed. No app/runtime code changed
in this rehearsal; the candidate remains the green CI commit above.

## Evidence and limits

- [Migration/copy receipt](setup-summary.json) and [migration impact](migration-impact.json).
- [All-source cutover](clean-cutover-summary.json), [API/Composer checks](clean-consumer-summary.json),
  [full recovery](clean-recovery-summary.json), [interruption recovery](interruption-recovery-summary.json).
- [Duplicate review](duplicate-review.json), [initial provenance impact](source-boundary-summary.json).
- [Held-reference behaviour](held-reference-summary.json), [fresh older-source screening](remaining-source-check-summary.json).
- [Production preservation](live-preservation-summary.json).
- [Current photo source links](media-link-summary.json), [native media trial](media-rehearsal-summary.json).

Private catalogue rows and recovery receipts remain on the server. Local exports
contain aggregate evidence and public source-key hold reasons only. API checks use
an unsaved synthetic user and simulated unavailable transport. They do not test live
users, live routing or the full conversational LLM. Composer membership is checked
exhaustively; list pages and details are sampled.
