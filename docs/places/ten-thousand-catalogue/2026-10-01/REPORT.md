# Verified local Places / Composer catalogue — 1 October 2026

**The local catalogue contains 12,292 distinct usable records: 11,591 standalone destinations and 701 activity-facility/component candidates. Both Places and Composer contracts were verified for every record.**

The work adds **7,365 named destinations** and preserves the prior **4,927 accepted records**. This is a complete private export produced through the real application import, query and resource paths. It has not been deployed; the temporary database changes were rolled back after export and checked against the original baseline.

## What changed

- Expanded the native category contract to cover physical shops, health venues, customer services, community/learning, accommodation and fitness, as well as the existing food/leisure catalogue. Unsupported types, anonymous objects, arbitrary offices, closed/vacant sources and ambiguous identities were not accepted through fallback categories.
- Every new addition has a current, exact OSM identity, its source name, explicit supported type, full raw tags and a valid sourced point. Complete way geometry was read and reproduced in PostGIS; accepted points match the frozen selection within one metre. A map point is not described as a verified entrance.
- Places and Composer share identity, name, category, coordinates and practical facts. Composer retains the established flattened source tag vocabulary; supported practical capabilities are structured in place facts. Missing fees, hours, booking, access and entrances remain explicit.
- Fixed overlapping Composer terms: a veterinary-clinic request remains veterinary, and a sports-shop request does not become a request for playing fields. Separately requested categories remain supported.
- Fixed loss of distinct numeric source text in Composer's vocabulary. Values such as `3` and `3.0`, or identifiers `01` and `1`, remain distinct; source-preservation checks were retained.
- Preserved independent business branches. Existing canonical identities, destination/component relationships and withheld records were not merged or reactivated just to increase the count.

## What the catalogue covers

| Group | Records |
| --- | ---: |
| Cafés, restaurants, bars and food | 3,866 |
| Shops | 3,238 |
| Customer services | 1,859 |
| Health venues | 1,126 |
| Playgrounds | 725 |
| Culture and attractions | 327 |
| Community and learning | 287 |
| Courts and sports centres | 255 |
| Accommodation | 181 |
| Gyms and dance studios | 140 |
| Parks, viewpoints and picnic/BBQ places | 139 |
| Work/study and other existing supported types | 81 |
| Playing fields | 35 |
| Dog parks | 25 |
| Swimming and lakes | 8 |

The export spans **105 represented fine categories** and **86 assigned neighbourhoods**. 12,292 records have a neighbourhood assignment; 0 remain explicitly unassigned. This measures catalogue breadth, not the proportion of every real-world venue in Cologne. The full category and neighbourhood counts are in [rehearsal-summary.json](rehearsal-summary.json).

Effective name evidence: descriptive: 690, source: 11,602. All 7,365 additions use their source name; existing evidence-backed names are preserved.

## Practical information and photos

| Available field | Records / share |
| --- | ---: |
| Source opening-hours text | 6,277 (51.07%) |
| Address | 6,417 (52.20%) |
| Website | 4,348 (35.37%) |
| Phone | 3,695 (30.06%) |
| Description | 226 (1.84%) |
| Verified entrance point | 0 (0.00%) |
| Existing approved/active hero association | 119 (0.97%) |

These counts mean a recorded field is present; they do not imply every older source was re-read today or every opening-hours expression was independently confirmed on site. Per-field dates, provenance and uncertainty remain in the export.

| Recorded fee evidence | Records |
| --- | ---: |
| Free | 55 |
| Paid | 39 |
| Unknown | 12,198 |

Unknown fees are never converted into free recommendations. Nearby fine/coarse and strict-free checks passed for 82 representative new venue types; all 7,365 additions also passed per-record fee-policy checks.

**Photos remain limited to 119 existing policy-publishable hero associations (0.968% of the catalogue).** There are no new image approvals and no fresh URL-health claim. This pass does not meet the earlier 75% photo target. Stadt Köln provider records/images were not added; data licences never approve image rights. The approved optional-photo design and existing rights/health gates remain in force.

## How the count was verified

1. Screened 8,775 explicit supported named source objects. The conservative selector retained 8,117 and held 658 for name, identity, lifecycle, geometry or access concerns. No automatic merge was used.
2. Re-read those exact identities in eleven bounded source cohorts. Seventeen changed or disappeared. Another 735 cached representative points differed from the current complete geometry by more than one metre; these are point mismatches, not a claim that 735 real-world venues moved. All 752 remained held, leaving 7,365 additions.
3. Replayed the prior two additive destinations and 690 qualified facilities, imported all 7,365 additions through the native actions and repeated every new addition. Spot and observation hashes were identical after replay.
4. Exercised native Places detail/type-list and nearby Composer fine/coarse/strict-free queries. Exported every accepted record in bounded batches through PlaceResource and CandidateRepository, checking identical identity/name/point/facts, valid category, unknown-fee handling and prior media preservation.
5. Independently audited all 12,292 completed export records, frozen source/runtime digests, unique candidate IDs/source owners, prior-record preservation, completeness totals and private output permissions. No duplicate source owner or candidate ID was found.
6. Rolled back the successful trial and restored every sequence; all ten baseline tables matched exactly. A separate forced failure after ten new imports also restored the baseline/sequences and removed partial output. No user rows or live database were changed.

The first full trial hit local PostgreSQL lock-memory capacity during replay and produced no accepted export. A later trial caught numeric-string vocabulary loss near the end and also produced no accepted export. Its original ten table hashes and nine sequence states were independently restored. These refusal/recovery receipts are preserved. The successful trial used a separately owned loopback PostgreSQL clone with more lock capacity and the verified vocabulary fix; the shared development instance was not restarted or reconfigured. Planner statistics were refreshed only on the owned clone after bulk data changes, following [PostgreSQL's guidance](https://www.postgresql.org/docs/16/populate.html#POPULATE-ANALYZE). This changes planner metadata, not the guarded catalogue rows. There is no claim of a second byte-identical outer export: the complete native replay, independent audit and failure recovery establish the required data invariants; view fields such as open_now depend on the query clock.

Code verification: 66 focused native tests / 179 assertions for the latest fix; the earlier compound-term/source pass covered 83 tests / 266 assertions; 63 Python tests. Normal commit hooks passed the secret scan, formatting and the fast PHP suite (slow-group tests excluded): 1,821 passed, 1 skipped, 7,592 assertions. Nine compound-category regressions and three numeric-vocabulary regressions failed before their fixes and passed afterward. All 7,365 preparations also passed an independent exact-source-vocabulary diagnostic after the fix. The elapsed-time import replay test passed; it did not establish a new importer bug. No frontend files changed.

One fresh-context final reviewer independently reran the complete export audit read-only, checked frozen inputs/runtime and confirmed both implementation fixes. The final verdict accepts the local >=10,000 catalogue goal, with no outstanding Critical, Important or Minor findings. See [review-summary.json](review-summary.json).

The bulk typed-query verification stage was unusually slow. This is not a production request-latency benchmark; target performance remains part of release acceptance.

## Remaining release work

The 10k local catalogue goal is met. Serving this package to users still requires one coherent release: refresh current evidence where required, compare a current target-environment baseline, prepare the guarded import/qualification preview with compatible application code, apply it under the existing release safeguards, and verify the running Places/Composer APIs and target performance. This rollback runner is not a live promotion command.

Broader media coverage, fresh image URL health and unresolved source/identity holds remain separate open work. EXP-69 and EXP-72 stay In Progress until their broader release acceptance is met. The accepted UI is unchanged. The branch/worktree and complete private inputs/export are retained locally; nothing was pushed, merged or deployed in this continuation.

## Evidence and export

- Saved Jira/work-log records and complete read-backs: [project-records-summary.json](project-records-summary.json).
- Native application/export/recovery: [rehearsal-summary.json](rehearsal-summary.json).
- Whole-file independent audit: [independent-export-audit.json](independent-export-audit.json).
- Forced failure recovery: [failure-cleanup-summary.json](failure-cleanup-summary.json).
- Code/test evidence: [code-verification-summary.json](code-verification-summary.json).
- Source selection/current geometry: [selection-summary.json](selection-summary.json), [source-summary.json](source-summary.json), [native-source-summary.json](native-source-summary.json).
- Frozen runtime: [runtime-summary.json](runtime-summary.json); d07cc4be3be195a2023576111754384a84d5f859c73d5093c349174334d722a3.
- Private export: `storage/app/private/places-local/2026-10-01/ten-thousand-catalogue/candidate-places.jsonl` under the original source workspace, restricted to its owner. 119,286,805 bytes; SHA-256 `cfee38362754548fc2b5fe1c970e624962ff6f36a47729efbd6d8d47d3e33909`.

Data attribution: © OpenStreetMap contributors, [Open Database Licence](https://www.openstreetmap.org/copyright). Prior provider licences and per-field provenance remain in the preserved catalogue; see [the existing notice](../../production-pack/NOTICE.md). Fresh evidence for the new cohort was checked at 2026-10-01T12:47:46.692071+00:00; older records retain their own evidence dates.

## Recorded decisions and costs

The permanent [execution ledger](execution-decisions.md) retains the observed corrections and ruling context. There were no Minor review findings to defer.

- preserve the already completed inline implementation and its permanent ledger rather than restart because the scratch workspace was absent — commits and observed evidence define progress — cost if wrong: missing historical scratch details, which do not alter executable verification.
- start the one fresh-context whole-pass code review while the deterministic native import finishes; provide its successful receipts before the verdict — review reads files only and is independent of this local transaction — cost if wrong: reviewer must inspect any verification-driven fixes in the same review context.
- use a task-owned PostgreSQL container bound only to 127.0.0.1:15434, cloning the exact verified dedicated baseline and increasing max_locks_per_transaction to 4,096 with twenty connections — the shared development instance must remain untouched and native record locks must stay enforced — cost if wrong: extra disposable local disk/memory and another full verification run. Apply the already reproduced parser fix before freezing the new runtime.
- live deployment readiness remains outside this local package — the user asked to finish the coherent work locally before a later release — cost if wrong: target-specific release QA is still required before people receive this data.
- new photo supply and live URL health remain open media acceptance — this pass adds no media approvals and preserves the approved optional-photo design — cost if wrong: sparse photos remain visible and no 75-percent coverage claim is justified.
- current OSM identity/geometry evidence is the verification basis, not physical inspection of every venue — open data does not prove on-site conditions — cost if wrong: unreported real-world changes can persist until a refresh or feedback.
- replace an unnecessary second successful outer run with the complete all-record native replay, independent export audit and forced-failure/table/sequence recovery checks — those verify the required data/import invariants, while view fields such as open_now legitimately change with the request clock, and the developer rule discourages repeating passing checks without an unresolved concern — cost if wrong: no separate byte-identical outer-run comparison is claimed. The capacity-refused run is not counted as an accepted export.
- refresh planner statistics only on the owned loopback clone before the large native replay, before query verification and after rollback — PostgreSQL recommends ANALYZE after bulk data changes, and the guarded row/sequence checks remain unchanged — cost if wrong: additional local maintenance time and no claim that PostgreSQL statistics metadata is byte-identical. Shared development and live databases remain untouched.
