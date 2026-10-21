# Cologne place preparation — 18 September 2026

EXP-70 / EXP-72. The requested goal is the largest practical Cologne catalogue with real, rights-supported images across most places. **This preparation expands the source inventory substantially, but that overall goal is not yet achieved.** The old 4,075 database number was not used as an acquisition target or coverage denominator.

## Current refinement checkpoint

The latest prepared catalogue and validation are in [`place-refinement/README.md`](place-refinement/README.md): 12,857 candidate groups after reviewed grouping, with 1,076 groups carrying linked photo candidates. Neither count means publication-ready places. The sections below preserve the earlier research baseline. Five municipal archive photo references subsequently found inside Commons are excluded from the refined catalogue; historical files retain the rejected evidence. No live import has occurred.

## What was prepared in the earlier baseline

- **12,879 named candidate groups inside Cologne's actual OpenStreetMap boundary**, assembled from fresh external OpenStreetMap data, the complete tourism POI/dining catalogue and expanded Wikidata/Commons records. These include shopping and services; they are not all leisure destinations or certified unique physical places.
- **1,052 named groups with source-linked, licence-qualified image candidates**, representing **3,022 distinct image assets**. A readable list with exact image-source and creator links is in `image-catalogue.md`; full metadata is in `places-with-image-candidates.jsonl`.
- **92 additional geographic/name photo-discovery cases** from 675 searches. These are kept separate because proximity/name tokens do not prove that an image depicts the requested facility. They are not added to the 1,052 source-linked count.
- **2,210 unnamed groups** and playground/parent questions remain in a review queue. Individual courts, tracks, swimming pools, picnic tables, small historic features and private/inactive records were separated before assembling the main destination candidates.
- **15 coordinate cases outside Cologne** are held separately, including cross-boundary natural areas and regional landmarks. This is coordinate-based eligibility: it does not imply that an entire large natural area lies outside Cologne.

All records retain source identifiers and URLs, coordinates, source facts licences, category, image creator and licence evidence, and explicit review status. No records or photos were imported into the application. No provider received a blanket rights approval. This is a review/acquisition package, not a publication-approved seed file.

## Coverage by category

| Category | Named candidate groups | Source-linked licensed-image candidates | Additional geo/name cases to review |
| --- | ---: | ---: | ---: |
| Accommodation | 367 | 18 | 0 |
| Coworking | 5 | 0 | 0 |
| Culture | 552 | 124 | 0 |
| Dining | 3423 | 101 | 0 |
| Parks and nature | 270 | 92 | 37 |
| Playgrounds | 148 | 0 | 43 |
| Shopping and services | 6669 | 34 | 0 |
| Sights and landmarks | 573 | 393 | 0 |
| Sport and leisure | 407 | 13 | 12 |
| Places of worship | 465 | 277 | 0 |

The source-linked image-candidate fraction is **8.17%** across the broad named inventory. The catalogue's size must not hide this shortfall. Excluding retail, accommodation and worship leaves 5,378 named dining/culture/parks/playground/sport/sight/coworking groups, with 723 source-linked image-candidate groups. These are still provisional identity groups, not a verified count of distinct destinations.

Open sources have been useful for parks, cultural buildings and landmarks. They have not demonstrated majority coverage for neighbourhood businesses, sport or playgrounds. A category photo, nearby building, generic stock image or a parent's photo is not automatically a photo of a child facility.

## Fresh external retrievals and checks

1. A city-boundary OpenStreetMap query returned **21,532 raw map features**. The query, timestamp, original response and all normalized/excluded records are retained. The main query used the administrative area associated with Wikidata Q365, not a rectangular approximation or the app database.
2. The earlier same-day complete tourism retrieval covered **554 public POI/dining pages**; its 294 facts-and-image-licence candidate records were incorporated without adding source totals blindly.
3. Wikidata class selection expanded from 120 to **792 records**, with **849 directly referenced Commons files** checked. Of those source records, 726 had qualifying image metadata before boundary/overlap/relevance controls.
4. **215 Commons requests** checked files and categories explicitly linked from relevant OSM records, including a follow-up for 109 Wikidata IDs missing from the administrative query. This yielded 1,623 qualifying distinct files attached to 249 OSM records before cross-source deduplication. Category queries were bounded to 12 direct files; 104 categories had more files available. No claim is made that all Commons images have been exhausted.
5. **675 nearby Commons searches** covered named park, playground and sport photo gaps with distinctive name tokens. District-only and generic-facility matches were rejected afterward. For example, “Gesund in Ehrenfeld” initially matched unrelated Ehrenfeld building photos; these do not qualify as facility photos.
6. Actual Cologne boundary geometry was reconstructed from **91 OSM outer ways, 4,867 vertices**, closing into one ring. Every prepared candidate point was checked against it.
7. **661 provisional identity links** used a shared Wikidata ID with a distance check or an exact normalized name within 100m. They remain reviewable links; no automatic live merges were applied. Differently named duplicate facilities and destination/component relationships are not fully reconciled.
8. Image reachability checking made **45 HTTP HEAD attempts: 44 successful image responses and one HTTP 429** from Wikimedia's image host. Further requests were stopped; 1,014 scheduled groups were left unverified. HEAD checks do not decode an image or verify its visual subject. No rate-limit bypass was attempted.

The source-linked images are **licence-metadata checked, not approved for publication**. Health, current scene, relevance, identity, entrances, public access and current operation remain distinct checks. The previously identified Skulpturenpark foundation-building image is held rather than selected as a park overview.

## Rights and source policy

Direct Stadt Köln acquisition was excluded. A subsequent provenance review found five municipal archive photo references republished through Commons; those are excluded from the current refined catalogue, while historical evidence retains them. Independently licensed KölnTourismus and Commons assets are evaluated individually. Existing application municipal code/data has not been removed by this research pass.

OSM-derived data retains [ODbL and contributor-attribution obligations](https://www.openstreetmap.org/copyright). Wikidata's structured facts and Commons photographs have separate licences; see [Wikidata licensing](https://www.wikidata.org/wiki/Wikidata:Licensing) and [Commons reuse guidance](https://commons.wikimedia.org/wiki/Commons:Reusing_content_outside_Wikimedia). Creator, original source, exact licence/version and applicable modification/share-alike notices must accompany published assets. The whole mixed-source package is not declared CC0. JSON preserves the full credit metadata; Markdown credits are only readable summaries.

## Free-only acquisition policy and next sources

The owner requires all acquisition and media sources to be free. The previously proposed paid-provider test is cancelled; no account, paid request or expenditure occurred. Free trials requiring later payment are not the solution. The existing 300-place gap sample is reused only for no-cost source evaluation.

The next independent photo source under measurement is [Panoramax](https://panoramax.fr/mentions-legales): public geolocated photographs with individual licence links, producer credits and capture dates. Explicit OSM photo links are evaluated separately from nearby cameras pointing toward a place. Street imagery must still show the correct destination clearly; these records are not automatically hero photos or publication-approved.

A broader tourism lead is [Data Hub NRW](https://tourismusverband.nrw/themen/datenmanagement), which integrates DISH restaurant records. Its dashboard currently reports 1,487 gastronomy records for Cologne **and Rhein-Erft together**. This is not a Cologne-only or photo-qualified count and must not be added to our totals. The free feed and public visitor catalogue need record-level city, overlap and media checks. Its documented API onboarding asks for contact details and terms acceptance; no registration has been submitted.

Stadt Köln municipal sources remain excluded. Commons and independently licensed tourism records remain candidates; the acquisition goal stays open until free source coverage and image relevance are demonstrated. The completed Panoramax check added eight explicit photo links, including six previously photo-less groups, plus 16 separate directional leads from 300 gap queries. See `free-source-followup/report.md` for evidence and limitations. Existing catalogue totals above have not been inflated with nearby-photo results.

## Package files and safe handoff

The later broader search is documented in [`broad-source-search/report.md`](broad-source-search/report.md). It retrieved 451 independently licensed Köln Vorort photo posts and discovered 3,516 Commons collection files; 2,108 checked files have qualifying licence metadata and were absent from the earlier Commons title inventory. These are image counts, not additional covered places. Three free Geobasis NRW aerial examples provide a separate outdoor-image option. NRW/DISH regional result totals do not establish Cologne photo coverage. Existing catalogue counts above remain unchanged pending exact subject and rights review.

- `places-named.jsonl`: all 12,879 named city candidate groups, including source and review metadata.
- `places-with-image-candidates.jsonl`: 1,052 source-linked named image candidates; no live publication approval implied.
- `image-catalogue.md`: readable category index with place sources, image sources, creators and licences.
- `places-missing-images.jsonl`: the explicit named-photo gap queue.
- `names-and-parent-review.jsonl`: unnamed records and possible playground/parent relationships; not additional approved destinations.
- `geo-discovery-review.jsonl`: separate nearby/name-matched image leads requiring subject review.
- `outside-city-review.jsonl`: 15 boundary-coordinate holds.
- `identity-links.jsonl`: 661 provisional cross-source links for review.
- `image-health-checks.jsonl`: per-attempt HTTP evidence, including rate-limit and unverified states.
- `prepared-summary.json`, `source-evidence-hashes.json`, query/manifests and raw responses: reproducibility and arithmetic evidence.
- `paid-coverage-pilot-manifest.json`: cancelled historical proposal; superseded by the zero-cost sample in `free-source-followup/free-coverage-manifest.json`.

The source data remains separate from live catalogue tables. Do not bulk-approve the package or suppress image/identity review flags during import. EXP-70/72 remain open for the unfulfilled majority-photo and clean-destination acceptance criteria.
