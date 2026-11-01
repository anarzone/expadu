# EXP-70: fresh external source inventory — 18 September 2026

This investigation starts from external source catalogues, not Expadu's database. The old 4,075 figure is not a destination target or a denominator for any result below. This inventory supersedes the earlier 40-page sample as the evidence for source availability.

## Results actually measured

| Source | Scope checked | Records with explicit image-licence evidence | Distinct qualifying image files |
| --- | --- | ---: | ---: |
| KölnTourismus / destination.one | All 554 URLs in the public POI and dining sitemaps | 299; **294 also have a stated structured-data licence** | **777** attached to those 294 records |
| Wikidata → Wikimedia Commons | Fresh Cologne query; 120 leisure/culture items selected by explicit classes, 130 referenced files checked | **114** items with a CC BY, CC BY-SA or CC0 image and sufficient declared dimensions | **119** |

These are source candidates, not imported or publication-ready places. Do not add the two record totals together: the sources overlap. A deliberately conservative exact-normalized-name plus distance-under-300m comparison found 30 possible overlap pairs, recorded separately for review; it does not resolve all differently named matches.

KölnTourismus is the tourism organisation's catalogue, distinct from the Stadt Köln municipal datasets and website-photo provider excluded by the owner. Selection is per explicitly licensed asset, never a blanket approval of the organisation's website or galleries. No municipal dataset was used in this new inventory.

## What the tourism catalogue really covers

All 554 pages responded successfully and exposed their own structured record. There were 554 distinct provider IDs and no exact normalized-name duplicates. All labelled their city Köln and had coordinates within a broad Cologne sanity box; this is not a boundary survey or entrance verification. Provider IDs do not prove that two related facilities are separate physical destinations.

| Research group | Source records | Records with stated facts licence and a licensed image candidate |
| --- | ---: | ---: |
| Dining | 204 | 79 |
| Outdoor, sport and leisure | 37 | 21 |
| Culture venues | 103 | 46 |
| Sights, monuments and public art | 142 | 127 |
| Shopping, services and other | 68 | 21 |
| **Total** | **554** | **294** |

The first three groups provide **146** candidates closest to the immediate leisure/culture/dining use case. The other 148 licence-complete records are separated rather than used to inflate that shortlist. These are research groups, not final product categories; the exact category sets and precedence are in `segment-summary.json`.

There are 407 records with any image, but only 299 with an explicit qualifying image licence. Thus **255 records still have no qualifying image**. Five additional records have qualifying photo metadata but lack a structured-data licence: Alter Markt, St. Agnes, Der Eismacher and two bicycle-service listings. They are excluded from the 294 combined-data shortlist; their photos and factual fields require separate decisions.

Sports and everyday neighbourhood coverage remain weak. The catalogue has unfilled photo cases including KAP686 Skateplaza, Salatschüssel-Skatepark, The Cube Padel, Kletterfabrik and AQUALAND. It cannot establish comprehensive playground or sports-centre coverage. Source update timestamps exist, but an updated record does not establish that hours, entrance position or image content were checked recently.

## What Wikimedia adds, and its limits

The fresh Wikidata query returned 2,866 rows representing 2,152 distinct image-linked entities administratively associated with Cologne. It did not hit its 10,000-row cap. This broad result includes streets, residential buildings, transit stops and demolished places, so **2,152 is not a destination count**.

The explicit leisure/culture class selection retained 120 items without a recorded dissolution date. Commons metadata was fetched for all 130 referenced files. Of those, 119 have an explicit CC BY/CC BY-SA/CC0 licence, creator metadata and declared dimensions of at least 400×225, serving 114 items. Twenty-six of the selected park-class items have such candidates, including Blücherpark, Beethovenpark, Klettenbergpark, Mülheimer Stadtgarten and Schlosspark Stammheim. The class selection is deliberately limited and is not a complete city inventory.

Eight files labelled public domain without a licence URL, one Free Art License file, one GFDL file, and one CC image missing creator metadata are not included in the 119. Exclusion here does not assert that those licences are unlawful; it means this bounded evidence check did not qualify them for the selected CC path.

An important negative control: Skulpturenpark Köln's Wikidata image points to the foundation's building, described as a former bunker/youth hostel. That is not evidence of a suitable park overview. Its licence passes this metadata filter, but it must not be automatically selected as the park's hero image. This illustrates why the 114 figure is a candidate count, not a count of solved photo gaps. Likewise, lack of a dissolution date is not proof that a venue is currently open.

## Source decision

1. **Primary source to integrate: explicitly licensed KölnTourismus destination.one records and assets.** This run establishes availability at record level. The public website provides a research route; recurring ingestion should use an authorised feed, preserving each record ID, data licence, asset ID, creator, exact licence version, source URL and checked date. KölnTourismus documents its relationship to [Data Hub NRW](https://www.koelntourismus.de/service/wir-ueber-uns/digitales-destinationsmanagement).
2. **Complement: Wikidata identity/coordinates and per-file Commons media.** [Wikidata's structured data licensing](https://www.wikidata.org/wiki/Wikidata:Licensing) does not license the photographs. Apply [Commons' per-file reuse guidance](https://commons.wikimedia.org/wiki/Commons:Reusing_content_outside_Wikimedia), including attribution and other relevant restrictions. Keep historical, building-only, artwork and location-mismatch cases pending relevance review.
3. **Feed expansion: Data Hub NRW and the German tourism Knowledge Graph.** [NRW's current terms](https://tourismusverband.nrw/allgemeine-geschaeftsbedingungen-data-hub-nrw) describe registration and an individual access key; [Open Data Germany](https://open-data-germany.org/en/retrieve-open-data/) describes its retrieval routes. Their Cologne coverage has not been separately sampled through authenticated feeds. They may redistribute the same tourism records, so no additional place or image count is claimed. No account or contract was created or accepted.
4. **Do not use Stadt Köln municipal datasets/photos in the replacement-source plan.** Two municipal-museum-credited Ubiermonument images appeared in the tourism catalogue, but had no qualifying licence and were excluded. No municipal-credited image passed the tourism shortlist check. This research has not removed existing municipal integration code or data; that remains an implementation task.

CC BY-SA permits commercial reuse subject to its conditions; creator credit, licence links, changes and applicable share-alike obligations must be retained. An image's licence alone does not resolve every possible third-party right. See the [CC BY-SA 4.0 terms](https://creativecommons.org/licenses/by-sa/4.0/). No provider-wide approval or live media-rights mutation was performed.

## Reproducibility and handoff

- `manifest.json`, the two saved sitemaps and `source-records.jsonl`: complete tourism discovery set, source IDs/URLs, timestamps, response hashes, individual image licences and negative outcomes. Same-day responses from the original 40-page sample were reused with their original observation timestamps.
- `summary.json`, `segment-summary.json`, `segmented-source-records.jsonl`: recomputable source totals and mutually exclusive research groups.
- `licensed-place-candidates.jsonl`: 294 tourism candidate records and their 777 distinct qualifying assets. This is an input to review, not an approval manifest.
- `wikidata-query.json`, `wikidata-source-response.json`, `wikimedia-manifest.json`: exact query, direct-class selection, scope and limits.
- `commons-batch-*.json`, `commons-file-metadata.jsonl`, `wikimedia-licensed-place-candidates.jsonl`, `wikimedia-summary.json`: all 130 file metadata responses, decisions and 114 candidate item records.
- `possible-source-overlaps.json`: 30 possible cross-source identity pairs. No automatic merges were made.

Verification: full tourism fetch 554/554, Commons metadata 130/130, unique-ID and normalized-name checks, recomputed asset and segment totals, and explicit negative licence examples. Image bodies were not downloaded or decoded. The earlier 10 successful HEAD checks only cover that earlier sample and must not be extrapolated to all files here.

Next implementation input: resolve these external records into canonical destinations; link courts/attractions to their parent when evidence supports it; verify current names, entrances and access; select relevant images and preserve their rights evidence; import through the existing guarded media path. Do not use 4,075 as the expected result. Count the resulting reviewed physical destinations and record unresolved gaps separately. EXP-70 remains open for its outstanding deep-review and integration acceptance work; no claim is made that the overall Places cleanup is complete.
