# Places follow-on preparation — 1 October 2026

**4,927 Places/Composer records are now prepared and checked locally**, up from 4,327. This pass adds 600 existing public activity facilities to the proposal. The combined export is a local candidate catalogue, not a deployed or fully source-current production count. Staging and production were not changed; PR #62 stays draft and unmerged.

## What this pass actually changed

- Rechecked 600 exact existing OSM source identities and 4,776 geometry nodes, including a second way-topology read. Source proof was collected at `2026-10-01T10:57:29.935642+00:00` in 20 bounded public requests.
- Prepared 508 playgrounds, 46 table-tennis facilities, 18 pitches, 13 basketball courts, 9 boules areas, 3 skateparks, 2 barbecue areas and 1 dog park. Their ambiguity was solely against unavailable, source-null legacy rows. Every legacy row, alias and source identity remains preserved; no identity merge was performed.
- Confirmed public access for all 600. Fee evidence remains unknown for 594. Six explicitly free facilities are 4 table-tennis facilities, 1 boules area and 1 basketball court. None of those six is a football facility.
- Verified nearby soccer searches for 18 facilities. These are useful public football candidates when no free-only condition is requested; they must remain excluded from strict-free recommendations because fees are unknown.
- Corrected 276 slightly different stored representative points using unchanged complete source geometry. Largest update: 11.881 m. The guard refuses changes above 25 m, and current geometry must reproduce the selected point within 1 m. No point is labelled a verified entrance.
- Fixed a real application bug: repeating an unchanged fact review created new correction/revocation entries because PostgreSQL JSONB reorders object keys and whole-number amounts round-trip as integers. Canonical JSON comparison now preserves unchanged reviews, while real changes, list-order changes and scalar-type changes still create history.
- Preserved a 23-case source-review queue: 7 possible successor businesses, 1 museum/restaurant category conflict, 9 mixed-sport category cases and 6 large representative-point changes. These were identified in the 28 September capture; they were not freshly refetched or applied in this pass. Do not treat old business names as newly verified.

## Verified result

| Measure | Result |
|---|---:|
| Existing application records preserved | 11,802 |
| Raw collected source records preserved (not unique places) | 255,949 |
| Earlier candidate records retained exactly by ID | 4,327 |
| Newly qualified activity records in the local trial | 600 |
| Combined native candidate records exported | 4,927 |
| General destination records | 4,226 |
| Additional activity/component records beyond general discovery | 701 |
| New selected source records still held after native checks | 0 |
| Detail/map/nearby Composer samples | 31 |
| Football facilities checked with near-origin soccer queries | 18 |
| Existing policy-publishable photo associations | 119 |
| Newly acquired or approved photos | 0 |

The 600 checks include all source identities/geometry/access/fees, all effective native facts, all 600 entries in paginated activity discovery, and all 600 strict-free outcomes. Detail, map and near-origin discovery cover every category, every football facility and every explicit-free facility. All 4,927 native Places resource/Composer-by-ID contracts were checked. The earlier 4,327 IDs retain their source identity, displayed name, category, coordinates and photo selection.

The representative-point guard was tested with 24 m accepted and 26 m rejected. Source refresh, qualification and qualification reversal replay without duplicate history; the replay also preserves the revision counter. Qualification reversal preserves the refreshed source evidence and the two independent destination additions. The outer rollback restores all ten baseline table hashes and sequence values, and leaves no operational journal. A reproduced failure also restored the baseline before the corrected trial. No real users were imported.

## Field completeness

| Field | Recorded result |
|---|---|
| Fees | 55 free; 37 paid; 4,835 unknown |
| Access | 813 public; 4,114 unknown |
| Opening hours known | 1,458 |
| Website known | 1,723 |
| Address known | 2,371 |
| Phone known | 1,466 |
| Verified entrances | 0 |

Photo coverage across this full candidate set is 2.42%. This denominator now includes the added facilities; it is different from the prior 2.8% general-destination measure. No photo URL health refresh or new rights approval occurred. The 75% photo objective remains unmet; Stadt Köln images and source exclusions remain unchanged.

## Evidence and verification

- 14 selection/source-proof regression tests, 24 existing consolidation tests and 17 snapshot-policy checks pass.
- 36 native Places-fact tests / 259 assertions pass, including five new replay/type/order cases. The original bug produced two failing tests before the fix.
- Normal commit hooks passed the secret scan, formatting and **1,761 native fast tests / 7,369 assertions**. Independent review and disposition are recorded separately in `review-result.md`.
- Independent review found one frozen-way-topology guard gap; it is fixed with Python and native regressions. Both tightened guards rechecked all 600 records, including 542 ways; the prepared payload and exported catalogue are unchanged. See `review-result.md`.
- `selection-summary.json`, `source-summary.json`, `runtime-summary.json`, `rehearsal-summary.json` and `completeness-summary.json` bind the inputs, fresh proof, new runtime and full export by checksums. The previous runtime manifest remains historical evidence; it was not overwritten.
- Private full data remains under `storage/app/private/places-local/2026-10-01/held-public-facilities/` in the primary checkout. `candidate-places.jsonl` contains all 4,927 records. The earlier export is preserved separately.

## Remaining work

Of the 2,428 existing identity-held records screened for this pass, 1,828 were not selected; 1,818 carried a missing-public-access reason, often alongside other reasons. These counts describe this screening cohort and must not be added to other hold counts without checking overlap.

The broader source/identity gaps, 469 prior facilities without public-access evidence, 23 unresolved category/business/large-point cases and useful confirmed-free football coverage remain open. No full LLM conversation, event-candidate or live authenticated HTTP acceptance is claimed by the Places candidate checks.

Before a single later release: refresh time-bounded source evidence; prepare and rehearse durable target-bound apply/recovery operations for the entire agreed proposal; qualify the actual staging target; deploy and verify the authorized coherent release. A committed-process crash-recovery drill is not established by this rolled-back local trial. Production needs separate target checks. EXP-69/72 remain In Progress.

## Decisions recorded for continuity

- Continue the already approved local-first scope without another plan-approval pause; all preparation and trial writes are reversible and local.
- Fix the reproduced native review defect as part of this work; unchanged reviews should not churn audit history.
- Allow at most 25 m from stored representative point only with exact source identity and fresh complete geometry agreeing within 1 m. Wrong acceptance would misplace a facility; observed changes are under 12 m and no entrance claim is added.
- Use 100 km only for the all-record fee-contract check because by-ID candidates measure from the default city origin; actual nearby tests keep their 0.1 km radius around each sampled facility. No product search radius changed.
