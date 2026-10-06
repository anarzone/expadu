# Openverse source pass — 24 September 2026

Direct anonymous access to the public Openverse image API works. Earlier web-reader 403 responses did not prove the API unavailable. No account, payment, private credential or outreach was needed for this pass.

## Acquisition

Fourteen frozen searches covered Cologne/Köln, café/restaurant terminology, parks/playgrounds, four districts and breweries. Only `by,by-sa,cc0,pdm` licences were requested. The provider exposed at most twelve pages per query in this result window; those windows are not total city inventories. All 133 pages completed successfully: fourteen first pages and 119 subsequent pages. Pagination used at least 3.4 seconds between request starts, two concurrent workers, a 25-second timeout, 4 MiB response limit and stop conditions for authentication/quota errors or fewer than five daily requests remaining.

The saved pages contain **2,000 distinct index assets: 1,256 Wikimedia and 744 Flickr**. These counts describe indexed image records, not verified licences, distinct destinations or images approved by Expadu. The run log records individual response outcomes and hashes; no publication decision is inferred from HTTP 200.

## Place matching

The offline matcher checked the full **12,857 provisional groups** in `../place-review-queue.jsonl`, rather than the narrower live database. Exact normalized names in image titles or non-AI source tags yielded 984 candidate pairs across 266 provisional groups. Of these, 117 groups had no earlier image candidate. These are review leads and include false positives.

Examples demonstrate why name matching cannot approve these results: the restaurant name Amadeus matches a river-cruise vessel; Richartz matches museum paintings; multiple Subway branches match one image; a restaurant named Stadtgarten matches a different park. The matcher's output deliberately retains uncertainty and does not change identities or publish photos.

Against the current staging catalogue after the eight-photo follow-up, 33 still-uncovered eligible destinations have 83 name-based candidate pairs. This is a separate denominator. Stronger leads include explicitly named Jaely's, Café Elefant, Chocolat Grand Café and playground/park photographs; each still needs exact venue/location/currentness checks, original-source rights verification, municipal-origin checks, actual-file validation and native publication review. Flickr hosting is not blanket-approved by the current application configuration.

**New approved or published photos from Openverse in this pass: zero.** The eight successfully published thumbnail repairs are independently source-linked Commons candidates from the earlier review queue, not an Openverse coverage gain.

## What the evidence supports

Openverse supplies an additional free discovery route, including Flickr material not exposed by the existing tourism feeds. It has not demonstrated enough correctly matched, reusable photos to meet the expanded catalogue's 75% threshold. The current metadata pass cannot establish whether a more exhaustive search would succeed, and no claim of exhausting all public sources is made.

The remaining practical acquisition work is original-source review of strong leads, accurate destination matching and contribution of missing photos by venues/photographers. Contributions remain a proposed free route, not permission already received. No emails were sent, no provider rights were blanket-approved, and no new photos from this index were counted as coverage.

## Reproducible evidence

- `fetch_remaining_pages.py`, `pagination-log.jsonl` and `*-page-*.json`: bounded acquisition and saved responses.
- `match_expanded_review_leads.py`, `expanded-name-review-summary.json`, `expanded-name-review-leads.json`: complete provisional-inventory matching.
- `match_review_leads.py`, `name-review-summary.json`, `staging-name-review-leads.json`: currently uncovered staging matching.
- Original-source licence checks remain required as described by [Openverse](https://github.com/WordPress/openverse/issues/3264); [official API-client documentation](https://docs.openverse.org/packages/js/api_client/index.html) describes anonymous public use.
