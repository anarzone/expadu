# EXP-70 source and media rights audit

Reviewed: 2026-09-17

Environment: staging

Frozen catalogue commit: `b9033f81f44ee0e6673b7bedef4275141d412666`
Frozen manifest SHA-256: `1eaf7cbe5a247d5ae46a653788abf9d1236f9dc9b3fe91db0629c21cf03c0b8e`

## Decision

Keep OpenStreetMap as the city-wide baseline, then add reviewed, field-level enrichment instead of replacing the catalogue with one provider. Pilot Stadt Köln's structured park data for authoritative park names, coordinates, district and street fields. Continue Wikimedia Commons only with exact-file rights evidence. Do not publish Stadt Köln website photos. Defer Data Hub NRW and Mapillary coverage scoring until access has been intentionally configured.

This gives Composer stable place identities with explicit uncertainty while avoiding the false promise that a new source will supply complete names, entrances, hours, access, fees and media in one pass.

## Frozen sample

The manifest contains 100 staging records: 20 parks, 20 sports centres, 20 playground destinations, 20 culture destinations and 20 cafés. It covers all nine Cologne districts through the combined cohorts.

| Signal | Result | Interpretation |
| --- | ---: | --- |
| Coordinates present | 100/100 | Coordinates are not the main sample failure. Entrance coordinates remain unverified. |
| Missing photo | 91/100 | Media remains the clearest end-user quality gap. |
| Descriptive/generic name | 20/100 | Generic rows are concentrated in picnic spots and playgrounds. |
| Multi-activity candidate | 14/100 | Parent-place evidence exists, but reviewed memberships remain intentionally empty until EXP-72. |
| Restricted or uncertain access | 87/100 | Unknown must stay visible to users and Composer. |
| Entrance uncertain/not exposed | 100/100 | Routing coordinates must not be presented as verified entrances. |

The intended 10 Innenstadt / 10 outer-city split was impossible in two cohorts. The exact sports-centre filter yielded 2 Innenstadt and 18 outer records in the frozen first 20. The outer-city café query returned zero because the current café importer is constrained to the inner-city bounding box. These are catalogue findings, not sampling errors, and remain in the manifest.

## Provider comparison

| Source | Measured result | Rights/access | Decision |
| --- | --- | --- | --- |
| Current catalogue / OSM | Broad city base; 91% of the frozen sample lacks media and enrichment is uneven. Exact coordinate duplicates remain in the café response. | ODbL 1.0 requires attribution and can create share-alike duties for a public derivative database. | Keep as baseline. Expand cafés city-wide, reconcile duplicates, retain source element provenance. |
| Stadt Köln open park data | 589/589 names and coordinates; 517 streets; 586 districts; 50 source links; 28 records with remarks; no media. In the balanced eight-row deep park sample, 4 exact name/parent matches and 4 nearby candidates within 250 m require identity review. | Dataset is DL-DE Zero 2.0. Raw numeric `status` and `objekttyp` have no exposed domain labels, so they cannot be interpreted yet. | Run a reviewed park enrichment pilot. Import only field-level provenance; never auto-merge a proximity candidate. |
| Data Hub NRW | Coverage was not measured because no key exists. The source advertises POIs, gastronomy, descriptions and CC-licensed media. | B2B contact/terms flow creates a private key. Current terms allow media under CC0, CC BY or CC BY-SA, disclaim completeness/currentness, permit fair-use limits and breaking changes. | Product/legal owner decides access. Then run the same frozen 40-row test; do not record blocked access as zero coverage. |
| Wikimedia Commons | 9/100 frozen catalogue rows currently have a Commons photo; landmark performance is visibly better than neighbourhood long-tail coverage. | Licence, author, source and non-copyright restrictions must be checked for every exact file. Commons gives no warranty. | Keep as one media source, never as a blanket-approved provider. |
| Mapillary | Coverage was not measured because the project token is unset. | Mapillary describes imagery as CC BY-SA with attribution. The API still requires access configuration and every result needs author plus direction/quality checks. | Defer. It can supplement street context but is not a reliable place-photo substitute by proximity alone. |
| Stadt Köln website photos | 20 exact park-page image candidates checked; 0 had asset-specific open-licence evidence. | City terms require prior written permission and prohibit commercial reuse. Press-download permission is limited to journalistic use. The park dataset licence does not transfer to page images. | Keep pending and excluded. Replace current candidates with approved Commons, Data Hub or first-party assets. |

## Stadt Köln structured data is usable; its page images are separate

The park dataset is explicitly published under [DL-DE Zero 2.0](https://www.govdata.de/dl-de/zero-2-0), which permits commercial and non-commercial copying, modification, combination and product integration without conditions. Whole-dataset metrics cover all 589 rows; the candidate inventory freezes a district-balanced 50-row review set.

The linked web pages use different terms. Stadt Köln's [general copyright notice](https://www.stadt-koeln.de/artikel/06121/index.html) requires prior written permission and prohibits commercial reuse. Its [photo download terms](https://www.stadt-koeln.de/artikel/00146/index.html) limit reuse to journalistic purposes. Consequently, every checked page photo remains `pending` and `exclude`; none inherits DL-DE Zero from the data record that linked to it.

## Name and duplicate findings

- The earlier “one place per attraction” issue is still visible as a data-modelling risk. The API now exposes an empty reviewed `activities` array and an optional parent place. Fourteen sampled rows have parent-place evidence that could support a shared destination, but EXP-68 deliberately did not auto-classify the catalogue. EXP-72 must review these memberships before Composer treats tennis, basketball, picnic areas or attractions as one destination.
- Repeated descriptive rows such as “Spielplatz” and multiple “Picknickplatz · Vorgebirgspark” records may be legitimate facilities inside one destination. They should remain source records linked to one reviewed destination, rather than being silently collapsed by name.
- Café rows include exact coordinate duplication: `Das Café` and `Carls` share `50.9379117, 6.9601206`, while the raw response also contained duplicate records outside the frozen unique-name selection. Identity reconciliation needs source ID, normalized name, distance and human review; coordinate equality alone is insufficient.

## Evidence files

- `baseline-manifest.json`: the exact 100 staging records and challenge flags.
- `sample-results.jsonl`: 40 fixed records × five provider tracks, including explicit blocked/not-applicable states.
- `candidate-inventory.jsonl`: a frozen 50-row, district-balanced official park candidate set with raw codes and field provenance. Whole-dataset aggregates were calculated across all 589 records.
- `asset-rights.jsonl`: 20 exact Stadt Köln page-image candidates, all pending and excluded.
- `source-register.json`: access, licence, strengths, gaps and decisions for each provider.

## Official references

- [Stadt Köln park dataset](https://www.offenedaten-koeln.de/dataset/parkanlagen-koeln)
- [DL-DE Zero 2.0](https://www.govdata.de/dl-de/zero-2-0)
- [Data Hub NRW Open Data Finder](https://tourismusverband.nrw/themen/datenmanagement/open-data-finder)
- [Data Hub NRW terms](https://tourismusverband.nrw/allgemeine-geschaeftsbedingungen-data-hub-nrw)
- [OpenStreetMap licence FAQ](https://osmfoundation.org/wiki/Licence_and_Legal_FAQ)
- [Wikimedia Commons reuse guidance](https://commons.wikimedia.org/wiki/Commons:Reusing_content_outside_Wikimedia/en)
- [Mapillary imagery licence guidance](https://help.mapillary.com/hc/en-us/articles/115001770409-CC-BY-SA-license-for-open-data)
- [Stadt Köln website copyright](https://www.stadt-koeln.de/artikel/06121/index.html)
- [Stadt Köln photo download terms](https://www.stadt-koeln.de/artikel/00146/index.html)
