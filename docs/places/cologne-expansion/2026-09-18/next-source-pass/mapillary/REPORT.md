# Mapillary access and Cologne coverage — 18 September 2026

**Access is operational. Majority coverage with usable venue photos is still unproven. EXP-70 remains open.**

Registered `Expadu Cologne Places Research` under the owner’s `anarzone` account with READ selected and WRITE/UPLOAD off. App ID: `28746308695006643`. The client token is stored in git-ignored local private storage with mode 0600; no token or client secret is included in this report. An authenticated entity request returned HTTP 200. No user OAuth, uploads or live catalogue changes were performed.

## Complete measurement against the working catalogue

Fetched all 174 original-position public coverage tiles needed for the 12,857 catalogue centres and their 60-metre bounding buffers. Indexed 1,559,525 distinct image IDs in those tiles, including 1,492,952 inside the saved Cologne boundary. These are camera metadata records, not downloaded photos or distinct venues. The tile footprint is complete for the specified catalogue searches, not an exhaustive inventory of all Cologne imagery.

| Measure | Catalogue records | Share of 12,857 |
| --- | ---: | ---: |
| Camera within 30 metres | 9,419 | 73.3% |
| Camera within 50 metres | 10,682 | 83.1% |
| Camera within 50 metres captured since 18 September 2023 | 6,653 | 51.7% |
| Panorama within 50 metres | 3,688 | 28.7% |

Of 11,781 baseline records without a linked photo candidate, 9,871 have a camera within 50 metres. **None of these numbers establishes usable-photo or ready-place coverage.** Municipal provenance and per-image eligibility are not resolved by a camera tile.

| Category | Total records | Camera ≤30m | Camera ≤50m | Captured since 18 Sep 2023, ≤50m |
| --- | ---: | ---: | ---: | ---: |
| shopping | 6,644 | 5,026 | 5,673 | 3,444 |
| dining | 3,418 | 2,821 | 3,027 | 2,044 |
| accommodation | 367 | 285 | 326 | 237 |
| sport_leisure | 416 | 179 | 236 | 128 |
| parks_nature | 255 | 65 | 109 | 49 |

## Actual image review

Selected a fixed sample of 30 missing-photo records with cameras within 30m: 12 shopping, 12 dining, three accommodation and three sport/leisure. Inspected 30 actual thumbnails plus eight panorama alternatives. All 38 decoded and matched their saved checksums.

- Initial images: 23 wrong/unidentifiable venue views, five uncertain, two identifiable storefront panoramas.
- Eight alternatives: four wrong/unidentifiable, four uncertain; zero additional confirmed matches.
- Bäckerei Brockmann and Der Fahrraddoctor are identifiable in their panoramas, but raw distorted street panoramas are not ready hero photos. Currentness, presentation and publication checks remain.
- No images approved or imported. No Stadt Köln-origin image approved.

This is a diagnostic sample, **not a statistical estimate of citywide ready coverage**. Initial frames came from each place’s nearest three/newest three camera leads; the eight alternatives were selected from all available inventoried panoramas within 50m. Other frames remain unreviewed. Poor selected frames do not prove that the source has no better photograph. It does show that proximity, recency and camera direction alone cannot justify automatic publication.

## Verification

All 174 tiles completed without acquisition errors. Five image IDs independently retrieved through the entity API also appeared in the tile inventory; coordinate differences were 0.167–0.247m, consistent with tile quantization. The catalogue hash stayed unchanged. Image IDs were kept as exact integers/strings, not rounded JavaScript numbers. Tokens were excluded from saved request logs and source files. Provider authentication/rate-limit errors stop acquisition; credentials are not forwarded to image CDN hosts.

Scripts operate only on offline research files. No Laravel data, publication state, deploy configuration or production service was modified.

## Remaining work

Mapillary access is no longer an owner blocker. Its role as a bulk source of attractive, correctly matched venue photos remains unproven. A venue-facing panorama renderer or additional candidate selection would require its own quality evaluation; neither is represented as finished here. The free tourism feeds remain the next complementary source, awaiting actual owner contact/organisation details. The overall request for a clean catalogue covering most places with usable photos remains unfinished.

Evidence: `camera-coverage-summary.json`, `place-camera-leads.jsonl`, `public-camera-inventory.jsonl`, `tile-requests.jsonl`, `visual-sample-summary.json`, `visual/review-01-15.json`, `visual/review-16-30.json`, and `panorama-alternates/visual-review.json`. Raw tile and image caches are local research artifacts; do not treat them as a publishable data release.

Source: [Mapillary API documentation](https://www.mapillary.com/developer/api-documentation/) (read while signed in). [Mapillary image licence guidance](https://help.mapillary.com/hc/en-us/articles/115001770409-CC-BY-SA-license-for-open-data).
