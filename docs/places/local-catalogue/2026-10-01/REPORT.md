# Places local preparation — 1 October 2026

Later checkpoint: [the held-facility follow-on report](../../held-facilities/2026-10-01/REPORT.md) supersedes the candidate count below with **4,927 local candidates**, preserving this earlier 4,327-record evidence and export. It also records the new tested runtime after the approval-replay fix.

The collected data and a fresh application baseline are now together in a reproducible local workspace. The combined candidate catalogue contains **4,327 native Places/Composer records**, verified through the actual application services and exported privately. This is a local proposal; staging and production were not changed.

## Measured counts

| Layer | Count | Meaning |
|---|---:|---|
| Preserved source records | 255,949 | OSM 163,362; Overture 88,760; Wikidata 3,827. Includes context features, businesses outside the current product categories and overlapping identities. |
| Preserved application records | 11,802 | Complete current baseline, including unavailable records and aliases. |
| Exact source-to-application links | 7,401 | Source IDs matched without an automatic identity merge. |
| Retained aliases | 798 | Canonical targets verified. |
| Possible identity pairs | 1,161 | Research review candidates; not accepted merges. |
| Current eligible baseline | 4,235 | 4,224 general destinations plus eligible components. |
| Combined local candidate catalogue | 4,327 | 4,226 general destinations plus 101 eligible components. |
| Additional source destinations | 2 | One fast-food venue and one restaurant; all other prepared updates were proven unchanged or remain held. |
| Additional qualified facilities | 90 | 74 playgrounds, 11 table-tennis facilities, 3 basketball courts and 2 pitches. Public access is evidenced; all fees remain unknown. |
| Unchanged source captures skipped | 4,643 | Preserved without redundant source observations. This is not a readiness count. |
| Facilities still lacking public-access evidence | 469 | Retained and excluded from qualification. |
| Existing policy-publishable hero associations | 119 | No new photos. URLs were not freshly health-checked. Baseline coverage is 2.817% of 4,224 general destinations. |

All 255,949 source rows were retained. Their role counts are 110,055 destination/service source records, 3,827 activity-facility records, 3,827 entity-enrichment records, 124,752 supporting features, 6,763 transport features, 2,685 heritage/information features and 4,040 outdoor-area features. These are overlapping source categories, not deduplicated ready places.

## What changed in this work

1. Captured ten explicitly allowed catalogue tables in one read-only staging snapshot. Restored every native column value into a dedicated local database. No users, plans, saved places or historic recovery receipts were copied; administrative actors were redacted while public attribution was retained.
2. Built the complete source/application registry, preserving all raw rows, 955 containment links, 874 explicit source links, held records and aliases. A second build produced the same semantic checksum.
3. Added conservative local research retrieval with friendly source/descriptive labels, evidence dates, licences, distance meaning and explicit uncertainty. Restricted/conditional/member-only records remain excluded.
4. Fixed redundant preparation: PostgreSQL's second precision and PHP's empty negative-fact representation made an unchanged capture look newer. The final package preserves 4,643 unchanged captures and avoids writing duplicate observations. Newer observations, withdrawal/restore history and conflicting same-time evidence are held.
5. Refetched the existing 559 facilities directly from OSM, including 2,442 geometry nodes and a second way-topology check. Native geometry and facts checks qualified a 90-facility proposal and retained 469 access holds.
6. Rehearsed source additions and facility qualification together, verified all 4,327 eligible native resource/Composer contracts, and exported their exact candidate representation. Qualification recovery preserved the unrelated source additions; the outer rollback restored the complete baseline.
7. Fixed both independent-review findings with failing-to-passing tests: padded closure/access values cannot bypass exclusion, and conflicting exact application targets require identity review. Same-target aliases remain intact.

## Field completeness in the 4,327-record proposal

| Evidence | Present / known | Unknown |
|---|---:|---:|
| Source name / descriptive label | 4,237 / 90 | 0 missing display labels |
| Fee | 49 free, 37 paid | 4,241 |
| Public access | 213 | 4,114 |
| Opening hours | 1,452 | 2,875 |
| Website | 1,722 | 2,605 |
| Address | 2,371 | 1,956 |
| Phone | 1,466 | 2,861 |
| Verified entrance point | 0 | 4,327 |

These are recorded facts, not a promise that a venue is currently open or that its entrance has been surveyed. Unknown public access at a business is not treated as a claim of public/free recreational use. Coordinates retain their source-point meaning. Dynamic fields in the exported native representation reflect the 1 October rehearsal context and must be recomputed by the application for the actual request.

## Verification

- Snapshot/restore: exact hashes for all ten catalogue tables, zero real users and zero imported operational journals.
- Registry: every source record preserved, deterministic rebuild, 15 central/northern/eastern research queries.
- Baseline consumers: all 4,235 eligible records, all 798 alias targets, 3,558 source-null held exclusions, representative alias/held API links and category discovery.
- Broad input diagnostic: 4,645 source records, 4,086 eligible Composer identities and 31 source/category/name-kind API samples passed before unchanged-source elimination. It is retained as a diagnostic, not the final import size.
- Final additions: both records passed native resources, kernel API, Composer, exact replay and table/sequence rollback.
- Facilities: all 90 passed detail, map, paginated activity discovery, nearby Composer category discovery, strict-free exclusion and recovery checks. Apply/recovery replays were identical.
- Combined proposal: all 4,327 records passed the native Places/Composer contract; apply/recovery replay and exact baseline restoration passed.
- Full native fast suite: **1,756 passed / 7,354 assertions**, through normal commit hooks. Final local suite: **24 Python tests and 17 snapshot-policy checks passed**. Independent review findings are fixed; see `review-result.md`.
- Regression tests cover policy filtering, identity preservation, unknown/restricted facts, member-only reservations, changed checksums, reproducibility and source chronology/representation.
- API/kernel verification was used as requested. No UI changes were made.

The full research inputs remain dated 28 September. The fresh facility proof is dated 1 October at 09:51:33 UTC. Current app eligibility does not prove that every real-world fact has been freshly independently checked.

## Deliverables

Private data is under `storage/app/private/places-local/2026-10-01/` in the primary checkout:

- `registry.sqlite`: complete research registry.
- `catalogue.json`: sanitized catalogue-only baseline.
- `candidate-places.jsonl`: all 4,327 validated candidate records, with native Places and Composer fields.
- `supported-package/`: two additions, 4,643 unchanged captures and explicit holds.
- Fresh facility source/geometry evidence, exact qualification package and rehearsal receipts.

The aggregate JSON files beside this report provide the counts, checksums and verification scopes.

## Still open

- Complete source/identity review for held or unsupported rows; the full source collection is available locally, but is not all consumer-ready. The 21 previously changed legacy source projections remain a separate review item.
- The 469 facilities without public-access evidence cannot be represented as public or free. The new 90 have unknown fees. Useful confirmed-free football coverage remains unproven.
- Photo coverage remains far below 75%; no new image acquisition or rights approval was part of this work. The accepted photo-optional direction still needs honest presentation of missing images.
- EXP-69 and EXP-72 remain In Progress until their broader acceptance criteria are met. This local preparation plan and its final review are complete; no broader catalogue-completion claim is made.
- Before any live release: refresh time-bounded proofs, prepare exact live-target operations/recovery, and perform authorized staging acceptance. Production needs its own target qualification. PR #62 remains draft and unmerged.

Full Composer conversations, event candidates, live authenticated server traffic and a committed-process crash recovery drill are not claimed by these local checks.
