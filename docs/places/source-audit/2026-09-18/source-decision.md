# Places with images: corrected source decision

## Current result: fresh external-source inventory

The owner clarified that the task is external source discovery, not another database count. The [full source inventory](external-inventory/report.md) now supersedes the sample-based availability figures below: all 554 KölnTourismus POI/dining pages checked; 294 records with a stated data licence and licensed image candidates, covering 777 distinct assets. A separate fresh Wikidata/Commons pass checked 120 leisure/culture items and 130 files, yielding 114 items with 119 distinct qualifying image candidates. Source overlap and image relevance remain review steps; these are not additive destination counts or live imports. The old 4,075 figure is not the target or denominator for this work.

The following database and 40-page sample sections are retained as historical evidence, not as the answer to the source-discovery request.

## Historical diagnostic: staging count — 18 September 2026, 14:25 CEST

Recomputed from the running staging database in one read-only transaction: [audit run](https://github.com/anarzone/expadu/actions/runs/35344593745). This replaces the earlier audit as the current count evidence.

| Measure | Fresh count |
| --- | ---: |
| All stored place/facility records | 8,461 |
| Recommendation-eligible records, same query as old 4,075 | 4,075 |
| Eligible rows after excluding reviewed activity children | 4,074 |
| Default Places category rows with coordinates, before name/grid clustering | 3,352 |
| Default Places browse cards after name/grid clustering, without personal/location filters | 2,809 |
| Eligible records with a parent link but no reviewed destination grouping | 587 |
| Eligible reviewed activity children | 1 |
| Recorded identity aliases across the catalogue | 5 |
| Eligible records with source explicitly marked OSM | 939 |
| Eligible legacy records with source unspecified | 3,136 |
| Eligible records with selected approved/active media | 14 |

The old 4,075 value happens to remain unchanged, but it is now independently rechecked. It counts eligible database rows, not verified distinct real-world destinations. The count of fully reconciled real-world destinations remains unknown. The 587 parent-linked rows are review candidates, not 587 confirmed duplicates. The raw total includes records excluded by the active/recommendable/facts/identity filters; no exclusion reason is inferred for every raw row here.

Exact query, timestamp, source/category breakdown and current media metrics are saved in staging-count-refresh.json. The original staging-count-evidence.json is retained as historical evidence from 09:06 UTC.

Checked 18 September 2026. EXP-70 follow-up after owner feedback. This supersedes the 17 September source recommendation. The source-finding result below is measured; catalogue integration and net coverage improvement are not claimed.

## Decision

Use **KölnTourismus's explicitly licensed destination.one place records and photographs** as the next Cologne enrichment source. Keep Wikimedia Commons as a supplementary exact-file source. Use Data Hub NRW as the preferred supported bulk-access route once Expadu has its own access key, and consider the German tourism Knowledge Graph for additional cities. Exclude Stadt Köln municipal datasets and municipal website photos from this replacement-source plan, as requested by the owner.

KölnTourismus GmbH publishes its own place records and individually licensed photographs. Its CC-labelled assets have their own documented rights basis; no licence is inferred from the Stadt Köln municipal dataset. A tourism page may link to a municipal website; that does not authorize importing that linked website, its prose or its pictures.

## What was actually tested

A 40-record capability sample was frozen before fetching the detail responses: 18 outdoor/leisure places, 10 cultural places, and the first 12 dining entries in the observed dining sitemap order. All outcomes are retained, including missing images and images without an explicit supported licence. This is a purposive source-capability sample, not a random sample, not the previous 100 staging records, and not a citywide coverage estimate.

| Observation | Result |
| --- | ---: |
| Public place pages successfully retrieved | 40 / 40 |
| Stable provider IDs, names, coordinates, addresses and source update dates | 40 / 40 |
| Description present in source metadata | 40 / 40 |
| Structured opening-hours entries present | 27 / 40 |
| At least one image in the place's own media list | 34 / 40 |
| At least one image with creator credit and explicit CC BY-SA 4.0 link | **30 / 40** |
| Distinct image candidates with that licence evidence | **83** |
| Other image entries excluded for missing supported asset licence | 70 |
| Selected image URL header checks returning HTTP 200 and image/jpeg | 10 / 10 |

| Sample group | With explicitly licensed image | Attempted |
| --- | ---: | ---: |
| Outdoor/leisure | 14 | 18 |
| Culture | 9 | 10 |
| Dining | 7 | 12 |

The public sitemaps listed **350 POI page URLs and 204 dining page URLs**. These 554 URLs are a discovery inventory, not 554 verified unique Cologne destinations or 554 image-covered places. Only the 40 detail records were sampled. All 40 identify their city as Köln.

For facts, the observed per-record metadata labels 38 records PD, one CC0, and one has no explicit licence. The unlabelled record is Der Eismacher: do not import its description under an assumed open licence even though its two photos carry separate CC BY-SA evidence. Keep facts and photo rights independent.

Ten selected original image URLs were checked with HEAD, without downloading image bodies. That proves endpoint availability and declared media type for those ten, not visual quality, decoding success or currency of all 83 photos. Width and height in the evidence are provider declarations. Final image validation and matching to an Expadu destination remain required before publication.

## Concrete examples

| Place | Provider ID | CC-labelled images | Exact evidence |
| --- | --- | ---: | --- |
| Rheinpark | p_100030055 | 3 | [Place page](https://www.koelntourismus.de/kunst-kultur/sehenswuerdigkeiten/detail/rheinpark) |
| Poller Wiesen | p_100096314 | 1 | [Place page](https://www.koelntourismus.de/kunst-kultur/sehenswuerdigkeiten/detail/poller-wiesen) |
| Decksteiner Weiher | p_100043748 | 4 | [Place page](https://www.koelntourismus.de/kunst-kultur/sehenswuerdigkeiten/detail/decksteiner-weiher) |
| Finkens Garten | p_100043750 | 3 | [Place page](https://www.koelntourismus.de/kunst-kultur/sehenswuerdigkeiten/detail/finkens-garten) |
| Fühlinger See | p_100168025 | 7 | [Place page](https://www.koelntourismus.de/kunst-kultur/sehenswuerdigkeiten/detail/fuehlinger-see) |
| TörtchenTörtchen | g_100014869 | 1 of 3 photos | [Place page](https://www.koelntourismus.de/essen-trinken/gastronomie/toertchentoertchen) |

TörtchenTörtchen is a useful negative control: two photos lack an open licence while a third explicitly carries CC BY-SA 4.0. Approval must attach to the exact image, not the page or provider. Stadtwald and KAP686 have photos but none passed the licence-evidence check in this source. Salatschüssel-Skatepark and Waldbad Camping supplied no image. No replacement or citywide coverage is invented for those gaps.

## Rights and access

The exact candidate images contain a named creator/source credit and a link to [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/). This licence permits commercial sharing and adaptation, requires attribution and a licence link, and requires adaptations to remain under the same licence. Record crops/edits when applicable. It does not automatically clear separate personality, trademark, artwork or other third-party rights. Use exact asset evidence; this is not blanket approval of every image on the tourism website.

KölnTourismus also publishes [its CC BY-SA image-library terms](https://www.koelntourismus.de/fileadmin/Mediendatenbank/Sonstige-Dateien/PDF/pixxio/pixxio-Lizenzen_CC-BY-SA.pdf). The sample uses stronger per-image links rather than relying solely on those general terms. Public page metadata was accessible without an account or key; no credentials embedded in website scripts were extracted or reused.

| Source | Finding | Access and role |
| --- | --- | --- |
| KölnTourismus / destination.one | Measured above: place details and 83 explicitly licensed image candidates for 30 sampled places. | Public page metadata supports a bounded pilot now. Website structure can change; use stable provider IDs, retain evidence and prefer supported bulk API access for continuing ingestion. |
| Data Hub NRW | Officially supplies POIs, dining, text and media; CC0/CC BY/CC BY-SA terms. The [public Rheinpark page](https://www.nrw-tourismus.de/parks-gaerten/rheinpark) also shows a credited CC-labelled image. | [Open Data Finder](https://tourismusverband.nrw/themen/datenmanagement/open-data-finder) requires business contact details and agreement to terms, then issues a key. Its [terms](https://tourismusverband.nrw/allgemeine-geschaeftsbedingungen-data-hub-nrw) describe free B2B access. No account was created or terms accepted. Cologne bulk coverage remains unmeasured; do not add NRW totals to KölnTourismus totals because records may overlap. |
| German tourism Knowledge Graph | Official API, MCP and service portal for structured tourism data, including use by AI applications. | [Access documentation](https://open-data-germany.org/en/retrieve-open-data/) requires registration/key. Useful route for other cities, not a separately measured Cologne photo gain. |
| Wikimedia Commons | Existing supplementary source with per-file authorship/licence evidence; coverage must be expanded by destination identity rather than only existing OSM photo links. | Public. Do not assume every Commons file matches the application's supported licence allowlist: the investigated [Stadtwald file](https://commons.wikimedia.org/wiki/File:Koeln_Stadtwald_10-2017_img2.jpg) uses Free Art License, not CC BY-SA. It was not included among the 83. |
| Foursquare Places | Its current [Place Photos endpoint](https://docs.foursquare.com/fsq-developers-places/reference/place-photos) returns photos by stable place ID and supports image classifications. | Requires authenticated API access. No authenticated Cologne test or contract/cost approval occurred, so it is an alternative to evaluate, not an integration-ready open catalogue source. |
| Google Places | Documented place photos, details and attribution mechanisms. | [Policies](https://developers.google.com/maps/documentation/places/web-service/policies) impose storage/display/attribution restrictions and different EEA terms. Treat as a separately licensed runtime integration, not a freely importable photo library or unrestricted Composer knowledge base. No coverage/cost claim made. |
| Stadt Köln municipal sources | Excluded by owner direction. | No municipal dataset or website image is recommended for the new integration. Existing runtime provider/data removal is a separate outstanding change; this research did not silently perform it. |

## Implementable mapping

The public detail HTML contains a data-config attribute with a dataObject for the actual page place. This is distinct from related/nearby cards. Its fields observed in the sample are:

- Identity: global_id (preserve p_/g_ prefix), title, type and categories.
- Location: geo.main.latitude/longitude; entry coordinates only when explicitly present. Never relabel the main point as a verified entrance.
- Address: street, zip, city, district.
- Freshness: created and changed; retain retrieval timestamp as a separate value.
- Facts rights: attributes entries license and licenseurl, separately from media rights.
- Media: media_objects[].id, url, type, source, license, copyright, width and height. The copyright field contains the exact licence URL.
- Asset origin observed: https://dam.destination.one; image transformations served via https://img.destination.one. Neither domain is a blanket rights grant.

The evidence file licensed-photo-candidates.jsonl contains 83 candidate rows with provider place/asset IDs, place name, coordinates, source page, direct image URL, creator credit, exact licence link and source response hash. It contains no guessed Expadu spot ID and does not imply that rights_status or match_status was changed in the application.

For the first import, resolve each source destination against existing names, aliases, category, address and geometry, then attach its own licensed media. Preserve a manual exception list for uncertain matches. Link facilities as activities of a verified destination; do not copy a general park photograph onto a specific court as though it depicts that court. Measure newly photo-covered canonical destinations against a frozen staging baseline after validation. Do not count image files as destinations.

## Where 4,075 came from

The [staging media audit](https://github.com/anarzone/expadu/actions/runs/35327777783) at **2026-09-18 09:06:24 UTC** counted Spot::recommendationEligible() rows. This excludes recorded canonical aliases and records failing active/recommendable/public-facts checks. It does **not** establish physical uniqueness, exclude reviewed activity children, or apply the Places browse clustering.

| Record categories | Rows |
| --- | ---: |
| Playgrounds | 1,360 |
| Pitches, basketball, tennis, table tennis, boules, skateparks, sports centres | 1,128 |
| Parks, picnic/BBQ spots, viewpoints, dog parks, swimming | 488 |
| Attractions, galleries, museums, zoos | 377 |
| Cafés, coworking, libraries | 722 |
| **Total eligible database rows** | **4,075** |

The individual counts are saved in staging-count-evidence.json. These are existing catalogue records, not 4,075 places discovered during this session. The application has an OSM importer and a committed historical snapshot (4,680 snapshot rows, whose source field is absent); exact historical origin must not be fabricated for those legacy rows.

The previously observed **2,809 Places API cards** use a different query: leisure/culture categories, coordinates required, reviewed activity children hidden in default browse, and same-name/neighbourhood/rounded-coordinate clustering. Personal/location filters can narrow it further. Both numbers should be labelled by their query; neither proves all real-world duplicate destinations have been reconciled.

## Evidence and remaining boundary

Files alongside this report: source-sample-manifest.json, koelntourismus-sample.jsonl, sample-summary.json, licensed-photo-candidates.jsonl, asset-head-checks.json and staging-count-evidence.json.

Completed here: identify a usable additional source, retrieve a fixed real sample, distinguish reusable image evidence from missing/unsupported licences, verify ten actual image endpoints, and explain the old count. Not completed here: import those candidates into staging, reconcile all destination identities, prove net catalogue-wide coverage, obtain new API accounts, or remove existing municipal provider/data from the running application. These remain explicit implementation work, not reasons to discard the now-measured source.
