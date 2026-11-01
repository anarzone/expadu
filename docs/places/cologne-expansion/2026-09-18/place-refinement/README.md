# Catalogue refinement checkpoint — 18 September 2026

EXP-70 / EXP-72 remain open. This is an offline acquisition and review package. It has not been imported into staging or production, and it does not fulfil the requested majority-photo coverage.

## What changed

- Consolidated 18 reviewed venue groups, removing 22 redundant rows while retaining all source records, aliases, activities and facility-specific conditions. Rheinterrassen now groups restaurant, beer garden and events. Lentpark groups indoor/outdoor pools while retaining the separate park. The nearby Alfredo café remains separate from Ristorante Alfredo.
- Corrected 35 categories. Bowling, climbing and bathing facilities previously classified as parks are now sport/leisure. Transport, education and generic services are separated from shopping and sights where the source explicitly supports the correction.
- Removed five municipal archive photo references discovered inside Commons. The earlier blanket statement that the package excluded all municipal photos was incomplete. Commons licensing alone does not satisfy the owner's municipal-source exclusion. Historical evidence files retain the rejected references for audit; the refined candidate catalogue excludes them.
- Reviewed 21 additional photo-to-place associations against names, captions, locations or addresses. Rejected 48 associations, including the wrong Merzenich branch, an arena nickname matching a restaurant, and a demolished café matching a current church.
- Downloaded and decoded selected tourism and Commons photos, with results and bounded retry attempts recorded separately. Final result: all 315 selected place/photo pairs decoded successfully after retries (294 tourism selections and 21 Commons selections); 25 images were visually reviewed. These are different checks, not 315 publication-ready places. Consult `photo-validation-summary.json` for reconciled counts. Network failures are not classified as broken source images. Oversized Commons originals are retried using official smaller derivatives of the same image.
- Recorded direct visual judgments in `visual-review-decisions.jsonl`. A fountain detail, cake display or shared-complex view does not automatically qualify as a venue's main photo. Successful decoding does not imply suitable subject, current appearance or publication approval.

## What the counts mean

The catalogue contains **12,857 candidate groups**, reduced from the fresh external research's 12,879. **1,076 groups have linked photo candidates (8.37%)** after grouping and new matches. This includes unreviewed photos and provisional identities; it is not the number of ready places. The old 4,075 database figure is not used.

The catalogue spans dining, retail, accommodation, worship, culture, parks, sport, sights and services. It is not a verified count of all Cologne destinations. The earlier 661 provisional identity links still need scrutiny; 18 reviewed groups do not certify every other group. No majority-photo claim can be made from these results.

All 13,539 underlying source records are retained. Coordinates remain their source reference points; they are neither averaged nor represented as verified entrances. Hours, wheelchair information, fees and access conditions remain source claims with their original facility scope. A café's opening hours must not silently become the hours of its entire shared venue.

## Outputs and checks

- `catalogue-refined.jsonl`: current prepared catalogue, including source evidence and observed photo checks.
- `identity-decisions.json`, `canonical-id-map.json`: exact grouping decisions and old-to-new candidate IDs.
- `category-corrections.jsonl`, `municipal-media-exclusions.jsonl`: auditable corrections and exclusions.
- `photo-association-decisions.jsonl`, `photo-rejections.jsonl`: positive caption/identity decisions and explicit negative controls.
- `*-photo-health.jsonl`, `photo-health-retries.jsonl`: append-only retrieval evidence; temporary local image paths are review caches, not deployment URLs.
- `visual-review-decisions.jsonl`: actual inspected-image judgments, with image hashes.
- `photo-validation-summary.json`, `validation.json`: reconciled latest checks and data-integrity validation.
- `free-feed-access.md`: concrete next-source access requirements and remaining unknowns.

Validation checks that every source is retained exactly, coordinates are preserved, distinct venues stay separate, duplicate activities remain available, excluded municipal media and rejected associations are absent, asset IDs are unique within a venue, and no rights/publication approvals are silently introduced. These are data checks, not application/API deployment verification.

## Reproduction

From this directory, run the following offline steps in order with Python 3:

```sh
python3 tools/build_refinement.py
python3 tools/review_photo_matches.py
python3 tools/integrate_photo_checks.py
python3 tools/verify_refinement.py
```

The first step deliberately rebuilds the prepared catalogue from preserved research inputs. The second is idempotent for the reviewed photo associations. The third attaches already-recorded health and visual evidence. The fourth asserts preservation and negative controls. `match_collections.py` and `reconcile_places.py` regenerate discovery proposals, not approvals.

Network scripts are separate and require Pillow. They limit downloads to 10 MiB and 40 megapixels, allow only named image hosts, pause between requests and stop on HTTP 429. Retries preserve original attempts. Images are not bundled; source URLs, authors, exact licences and hashes are retained. No credentials or copied web application tokens are required.

## Work still required

1. Obtain authorised free-feed access, retrieve the complete Cologne-specific inventory and measure genuinely additional places with individually reusable photos after overlap checks. Public NRW pages did not provide reliable full pagination; a sampled café had no images, while sampled landmark images overlapped existing tourism assets.
2. Resolve remaining identity/parent and currentness questions, select useful photos rather than merely working files, and retain explicit gaps.
3. Convert reviewed decisions to the current application's existing source-observation, identity and media-review workflows in an isolated checkout. Preview changes against the affected environment before applying. This package is not a live-import command.
4. Apply the approved data changes and verify Places and Composer responses through APIs, including municipal exclusions and one destination with multiple activities. No live mutation or deployment occurred at this checkpoint.

Free-feed photo coverage remains unknown. If access does not materially improve coverage, the next discussion must address owner-contributed photos or a smaller initial catalogue; the current evidence cannot justify promising images for most places.
