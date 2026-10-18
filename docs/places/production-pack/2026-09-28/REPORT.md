# Cologne places — prepared catalogue release, 28 September 2026

**4,643 source-backed records prepared and verified in the application: 3,908 destinations and 735 supporting activity facilities.**

| Records | New | Refreshed | Total |
|---|---:|---:|---:|
| Destinations | 1,839 | 2,069 | 3,908 |
| Activity facilities | 35 | 700 | 735 |
| **Total** | **1,874** | **2,769** | **4,643** |

This is a qualified import package, not a live production count. Staging and production were not changed. The source-ID mapping was checked against a fresh staging catalogue export; production requires reconciliation against its own current baseline before import. Readiness means the source, identity, boundary, supported-category and native integration checks passed. It does not mean every source assertion was independently verified on site.

## What changed

The release uses 4,475 OSM records and 168 Overture records. It retains the full original record, source identity, source URL, observation date, licences, practical tags and explicit unknowns. Sources were screened against the current catalogue and each other; detected ambiguous identities, restricted/closed records, unsupported conditions and questionable category assignments were held for review. Review tightened the closure-date and same-address/name-overlap checks and held eight additional records before this final release.

There are 4,084 source-named records and 559 facilities with descriptive labels. A descriptive label is not presented as an official place name. The batch spans 86 neighbourhoods and 24 supported categories. Facility containment evidence remains reviewable and was not automatically converted into destination membership.

The accompanying importer patch retains practical source tags on future refreshes, covers libraries and coworking across the full city and all OSM element types, removes the 200-attraction response limit, and preserves international website URLs. Acquisition bounds now come from the official city polygons, so accepted edge locations are not lost through a smaller handwritten search rectangle. These code changes are prepared and tested, not deployed. The package import also respects the coordinate precision of the database while retaining the full original source point, so replay cannot churn timestamps from rounding alone.

## Evidence collected

| Source fact | Records carrying it |
|---|---:|
| Opening hours | 2,146 |
| Address | 2,316 |
| Website | 1,681 |
| Phone | 1,432 |
| Wheelchair accessibility | 1,889 |
| Cuisine | 1,714 |
| Sport | 505 |
| Surface | 197 |
| Lighting | 143 |
| Access | 206 |
| Fee | 78 |

Counts mean a source value is retained, not that every value is complete, current or positive. For example, accessibility can be limited or unavailable. Of the selected records, 4,565 have no explicit fee evidence; 4,437 have no explicit access evidence. Missing values stay unknown. No record becomes free, public or open now merely because it is on the map.

## Native verification

- Application base: `53e3221ad195a14b5ad3868bb1044db7f07655d3`; the tested importer patch is separately checksummed.
- GitHub's revision comparison confirms that this base has the same file tree as the latest successful staging revision `5039a4cb1c8168434b9035b7aa87e917a7fa6f11`.
- Started from the freshly exported public places data in a new isolated PostgreSQL/PostGIS database. No users, private plans, saved places or sessions were copied.
- Verified all 4,643 identities, source observations, coordinates, raw tags, access/fee handling and name kinds through the application's native services.
- Verified 4,084 eligible identities through Composer's candidate repository; 559 selected records remain outside automatic recommendations. Tested the strict free-cost filter without turning missing fees into free claims.
- Checked eight native Places list API categories and new-record detail samples. The same baseline and API controller were used for before/after comparison.
- Replayed every payload against its post-import source IDs and expected rows: no changed place rows, extra observations or revision changes. The original frozen manifest intentionally rejects stale preconditions; it is not blindly reapplied. Unrelated place rows and existing identity/grouping/media fields stayed unchanged.
- Rolled the transaction back and verified the original table contents. No live catalogue mutation took place. PostgreSQL sequence gaps are not imported data.
- 67 application tests passed (442 assertions), plus 15 preparation tests and 5 exact-state comparison checks. All 4,475 selected OSM website projections match the refresh importer.

| Native Places list category | Before | After local import |
|---|---:|---:|
| food drink | 1,805 | 3,456 |
| culture | 215 | 243 |
| park | 263 | 264 |
| pitch | 366 | 366 |
| court | 666 | 666 |
| playground | 1,197 | 1,198 |
| dog park | 48 | 48 |
| swimming | 8 | 8 |

These are category-specific API results, not a citywide unique-destination denominator. They must not be summed or quoted as total Cologne coverage.

## Photos and remaining work

**New approved photos: 0.** Photo rights, relevance and health were not changed by this data release. Original image references remain unapproved. No Stadt Köln structured dataset or image provider was added. The package does not establish 75% photo coverage.

**10,234 relevant-category source records remain held**, with explicit reasons in `holds.jsonl`. Larger OSM, Overture and Wikidata inventories remain available for subsequent category expansion and review. These overlapping raw-source totals are not production-ready place counts.

The complete Composer request was not exercised in this data rehearsal. Explicit activity retrieval for unnamed facilities, fee/access/booking conditions and the controller's budget-relaxation behaviour still need application work. The broader inventory includes 365 soccer pitches but no explicit fee or hours tags for those pitches; this release does not claim a verified free-football network.

Next release steps are: review the package, reconcile it against the target environment's current catalogue, merge/deploy the tested refresh fixes, perform the controlled staging import and live acceptance checks, then promote a newly reconciled production manifest. EXP-69 and EXP-72 remain In Progress; the wider programme is not marked complete by this batch.

## Category breakdown

| Category | Selected records |
|---|---:|
| restaurant | 1,467 |
| fast food | 700 |
| cafe | 596 |
| bakery | 360 |
| playground | 285 |
| tennis | 256 |
| bar | 204 |
| sports centre | 159 |
| attraction | 107 |
| park | 106 |
| table tennis | 71 |
| library | 64 |
| gallery | 63 |
| picnic | 54 |
| museum | 44 |
| pitch | 31 |
| dog park | 21 |
| viewpoint | 15 |
| basketball | 10 |
| coworking | 8 |
| swimming | 8 |
| zoo | 7 |
| skatepark | 4 |
| boules | 3 |

## Reproducible evidence

- Selected data SHA-256: `ecfc2777acf6e91d8e574394418120163e725d2b432201179fbcc60fd20f605a`.
- Places-only baseline SHA-256: `2356f373dedbf7aeb0e0c3a33609a4efaaff0dd85110ce7147675ad6a607362d`; exported `2026-09-28T12:33:02+00:00`.
- Research inventory SHA-256: `e89849ab76a6cd640a2561af8ed5a77accd2f5d923694b4bd7381f63b2f82d64`.
- `manifest.json`, `rehearsal.json`, `code-verification.json`, `website-verification.json` and `php-test-results.txt` record the measurements.
- `records.jsonl` is the selected import input; held records and containment evidence are separate files.
- See `../README.md`, `../PLAN.md` and `../NOTICE.md` for the procedure, release boundary and source attribution.
