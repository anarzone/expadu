# Places and Composer readiness — 28 September 2026

Refs EXP-69 and EXP-72. **Implementation and local package acceptance are complete; the live release remains open.** Staging and production were not changed. The immutable earlier preparation package remains unchanged.

## Measured catalogue result

| Measurement | Result |
|---|---:|
| Prepared records checked through native shared facts | 4,643 |
| Destinations / supporting facilities in the prepared package | 3,908 / 735 |
| Prepared identities retrievable by Composer after proposed reviews | 4,100 |
| Additional source-backed facility qualifications proposed | 16 |
| Prepared records still outside automatic recommendations | 543 |
| Prepared soccer-capability records | 34 |
| Eligible public football records without the free-only requirement | 2 |
| Eligible records satisfying explicit free public football | 0 |
| New approved photos | 0 |

The 16 qualifications were applied only inside the rolled-back rehearsal. They are evidence-backed proposals, not live approvals. They add to the earlier 4,084 retrievable identities. Names, geometry, structured facts and unknown states were checked for every prepared record. These counts do not measure all Cologne places, photo coverage, independent field verification or the live production catalogue.

## Concrete application fixes

- Free-only requests no longer relax into paid or unknown-price recommendations. Conflicting paid pins are rejected. Empty results explain that no verified match was found.
- Sport and radius survive parsing, editing, planning, swaps and saved plans. Football uses explicit soccer evidence, public unconditional access and the selected origin. Nearby requests require a known origin; the radius uses straight-line distance.
- Places and Composer use the same practical facts, provenance, canonical identity, name kind, fee/access conditions and explicit unknown/conflicting values. LLM ranking receives bounded structured facts and can choose only supplied IDs. Source text remains untrusted data.
- Descriptive facilities require an audited activity qualification. General browsing is unchanged. A changed admission-relevant source snapshot invalidates qualification; an unchanged refresh, rating or photo update does not.
- Destination groups and exact child IDs are retained. Grouped sports facilities can be found by activity alone, and automatic plans avoid sibling duplication.
- Saved place slots are checked against current restrictions and the full visit interval. Pre-release Today snapshots that omitted constraints require fresh composition; cached references are retained. Split hours and overnight intervals are respected; unknown opening hours remain visible as unconfirmed.
- OSM refresh preserves raw practical tags and conditions, covers libraries/coworking across the city and all element types, removes the attraction cap and preserves international website links.
- Retrieval uses conservative hints from all source/review points and possible claims, then current shared facts for admission. It avoids resolving the whole city when farther records cannot improve an already full category.

Photos remain optional. No media rights approval, Stadt Köln image import or unsafe fallback was added.

## Validation

- **Full required fast suite: 1,678 passed / 7,012 assertions; one skipped.** The isolated parallel runner was corrected to use the project’s existing per-worker Redis database offsets. A direct connection check reproduced and resolved the earlier cache collision; no application rule or assertion was weakened. Secret and formatting hooks pass.
- **374 relevant application tests pass, 1,600 assertions.** After the final old-snapshot guard, the affected complete endpoint/identity suites also pass **77 tests / 348 assertions**. These suites overlap and must not be added together. Coverage includes parse/compose/swap/save/read routes, model input, identity/grouping, practical facts and source-refresh preservation.
- The earlier full run had 371 passes and one local database connection timeout during concurrent rehearsal. The affected grouping suite then passed all 26 tests; the final complete run above is clean.
- TypeScript, scoped ESLint/Prettier and the production frontend build pass. The build reports existing unresolved font warnings. No visual redesign was introduced; API verification follows the owner's instruction.
- The staged secret scan identified the SHA-256 of frozen `before-api.json` as a generic API key. Its bytes were independently hashed and matched; only that exact path/rule/line fingerprint is excluded in `.gitleaksignore`. No real credential was found.
- Independent review findings were reproduced and fixed: grouped activity-only retrieval, conditional booking, unstable review snapshots, plain nearby intent, stale saved hours, overnight scheduling and pre-release saved snapshots. The subsequent search optimization also received an independent read-only review with no material findings.
- Every prepared record was checked in a dedicated public-data PostgreSQL/PostGIS clone. No real users or private plans were copied. Synthetic API user and qualification writes were rolled back; protected table digests match afterward. The original data/archive checksums are unchanged.
- Model-driver behavior uses deterministic HTTP fixtures; this is not a claim about a live paid model request.

Full free-football requests within 3 km on 29 September, 12:00–19:00 Europe/Berlin:

| Origin | Eligible returned slots | API elapsed |
|---|---:|---:|
| Ehrenfeld | 0 | 6,551.6 ms |
| Innenstadt | 0 | 5,016.5 ms |
| Chorweiler | 0 | 4,860.9 ms |

Empty results pass the unsupported-claim check but **fail the useful free-football coverage goal**. The missing evidence must be researched; no inference that public land means free play was introduced.

Broad prepared-catalogue retrieval returned 200 candidates in 3,015.7 ms. On the same pre-import baseline, individual diagnostic reads changed from 8,122.8 ms to 2,032.9 ms. These are local measurements, not production p95. Rehearsal peak memory includes loaded baseline/package data and is not per-request memory.

## Remaining release work

1. Review and merge the scoped application change, then verify the running staging revision and CI. This report does not establish a live deployment.
2. Reconcile the immutable package and proposed qualifications against a fresh staging baseline. Review changed evidence, canary the import, verify refresh/reference preservation and rollback, and record live API performance.
3. Build a separate production-specific identity mapping and manifest. Obtain approval on the concrete staging result before production promotion.
4. Research explicit fee/access/booking evidence for football and other capability gaps. Keep unknowns and the 10,234 held source records separate from approved recommendations.
5. Continue rights-qualified photo acquisition. This increment adds no approved photos and does not meet or waive the earlier 75% target.

## Evidence and reproduction

- [Release checks](release-checks.json), [Native package acceptance](package-acceptance.json), [test result](regression-tests.txt), [diagnostic timing](retrieval-performance.json), [first package measurement](package-before-performance.json).
- [Shared acceptance contract](../../production-readiness.md), [implementation plan](../../2026-09-28-composer-readiness-plan.md), [original prepared-data report](../../production-pack/2026-09-28/REPORT.md).
- Data SHA-256: `ecfc2777acf6e91d8e574394418120163e725d2b432201179fbcc60fd20f605a`. Full original public-data archive and licences are retained under `docs/places/production-pack/`.
- `docs/places/composer-readiness/verify-pack.php` runs only with `APP_ENV=testing`, the dedicated `exp72_ready_20260928` database and zero real users. Set `PLACES_PACK_ROOT` to the checkout holding the immutable package plus its private public-data baseline export; use PHP 8.4 with 768 MB for this rehearsal. It uses a caller-owned transaction and always rolls back. Credentials stay outside the report. Do not run the earlier frozen-report writer to update this evidence.
