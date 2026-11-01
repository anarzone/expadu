# Cologne place facts: collection expanded beyond named/photo-led catalogues

28 September 2026. Related work: EXP-68 / EXP-69 / EXP-70 / EXP-72.

**The 12k figure is not a collection ceiling.** This run downloaded complete, geographically bounded source results without requiring photos or names and built a searchable local research database. The data has not been merged into the live application or declared to be unique, recommendation-ready places.

## Measured results

| Source | Records inside Cologne | Meaning |
| --- | ---: | --- |
| OSM | 25,097 place/service or activity-facility candidates | 21,275 destinations/services and 3,822 activity facilities, including unnamed facilities. Raw source identities, before real-world reconciliation and access review. |
| Overture, release 2026-09-23.1 | 88,760 directory records | 50,901 source-reported open, 33,648 unknown status, 4,211 permanently closed. Includes offices/service businesses and overlaps with OSM; not all are visit destinations. |
| Wikidata | 3,827 geolocated entities | Enrichment records, including 68 with an end date. There are 874 explicit OSM-to-Wikidata references. Do not add these to the unique place total. |

The complete seven-family OSM union contains **163,362 source features**. Most are context: 124,723 supporting features, 6,761 transport features, 2,685 heritage/information features and 3,974 outdoor areas inside the city. Another 122 representative points are outside or missing and remain separately flagged. These are **not** 163,362 destinations.

Overture returned 124,087 records in the bounding rectangle; exact city-boundary filtering excluded 35,327. Its in-city categories include 7,683 food/drink records, 12,018 shopping records, 2,913 sports/recreation records, 2,946 arts/entertainment records and 37,317 business/service records. Provider counts overlap: this release already includes records from Microsoft, Meta, Foursquare, AllThePlaces, BrightQuery, PinMeTo, DAC and Krick. Recollecting those providers is not automatically incremental coverage.

The Wikidata request returned 5,889 distinct entities in the rectangle and 3,827 inside the city. Unlike the earlier image-required query, this request did not require P18. 2,237 entities have image references; **none was approved for display by this run**.

Previously collected tourism inventories remain available: 2,148 NRW place records and 4,744 ODG destination-candidate records from 23 September. Those are older, overlapping source inventories, not additional newly verified places or a fresh count from this run.

## The football example

After exact city/geometry filtering, this snapshot has **365 football pitches**. The preliminary area query returned 367; two representative locations were outside the city polygon. Of the 365:

- 350 have no source name; a name requirement would discard them.
- 265 report a surface; 220 report lighting; 25 report wheelchair information.
- 20 explicitly report public access; 13 report restricted access; 332 have unknown/unresolved access.
- None explicitly reports a fee or opening hours. No pitch can therefore be certified “free and publicly playable now” from these tags alone.

The source-aware query finds 44 not-explicitly-restricted football pitches within 3 km of the illustrative central coordinate 50.94, 6.95, with fee/access uncertainty preserved. Its strict free/public query returns zero. This is a missing-evidence result, **not a claim that Cologne has no free football pitches**. Distances are straight-line, not walking times. Source collection dates are not physical inspection dates.

955 full-geometry facility-containment links are stored. These do not merge identities or transfer a park's access/fees to its courts. Current playing conditions, gates, occupancy and booking availability require appropriate evidence beyond a static map.

## Concrete local changes

- Added reproducible city-wide OSM, Overture and Wikidata collection, complete-query manifests, checksums and exact boundary checks. No numerical record cap and no photo/name gate in this research collection.
- Built `inventory.sqlite` with raw facts, source identities, licences, capability search, full-text search, containment and source links. The database retains uncertainty rather than replacing it with guesses.
- Updated the application OSM importer to retain all valid string tags, including previously dropped fee/access conditions, booking/membership, websites, operators and verification dates. Expanded café/coworking/library requests to the entire existing city box and all OSM element types; removed the attraction output cap.
- Added a shared factual resolver. Composer no longer infers “free” from outdoor/library categories; unknown costs cannot satisfy free/low budgets. Known restrictions and arrangement requirements are filtered, and free sports require explicit access evidence. Structured fee/access/sport facts reach the ranker without losing key/value relationships.
- Removed category-only “free” and “open now” assertions from the Places API. A reported zero entry fee is no longer labelled “Free public access” in the detail copy.

The application still uses its existing category model and candidate-selection limits. Full source-backed capability retrieval, identity reconciliation and importing this expanded inventory remain integration work. The prototype is not silently connected to the research database, and no production/staging dataset or media approval changed.

## Verification and handoff

Seven research-data regression checks passed. The final combined application run passed **160 tests / 416 assertions**, covering fact parsing, Composer retrieval/filtering/ranking, the Places API and import integrity. PHP formatting, the touched frontend component's lint, database integrity and whitespace checks passed. Exact results are recorded in `verification.json`.

Jira updates were verified on EXP-68, EXP-69, EXP-70 and EXP-72. EXP-69 was reopened for the discovered fact-trust regressions and pending rollout; EXP-70/72 remain In Progress. EXP-68's previously delivered identity work remains recorded as completed.

The initial sandboxed feature-test attempt could not reach local Redis; the authorized run against local test services passed. OSM timeout/rate-limit failures were retained in the manifest and completed with backoff. The default Overture STAC lookup incorrectly returned no selection; direct dataset download completed successfully. The public Jira API required curl transport; the local Plane instance was older and was not modified.

Publication sequence: (1) reconcile identities and roles against existing catalogue data, retaining source evidence; (2) review closure, access and fee conflicts, especially football; (3) refresh application records so previously dropped conditions are restored; (4) connect capabilities to Composer search before result limiting; (5) validate the result through the existing staging/API release process. No promise of complete real-world coverage or new photo coverage is made.

Source semantics, licences, reusable commands and the ongoing collection contract are in [the collection reference](../../open-collection/README.md). Exact counts are in `summary.json`; individual football evidence and the example queries are saved alongside it.
