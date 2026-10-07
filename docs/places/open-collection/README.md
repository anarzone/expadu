# Open place collection for Composer

Decision recorded 28 September 2026, following the owner's example: “football nearby, free to play”. Related work: EXP-68, EXP-69, EXP-70 and EXP-72.

## Collection contract

Collect useful source records across the complete city, without a target count, a name requirement or a photo requirement. The previous ~12k named-destination snapshot is not the size of Cologne's available place data. A missing name must not discard an otherwise useful pitch, playground or other facility.

Keep three concepts separate:

1. **Destination or service:** a park, sports centre, café, restaurant, library, shop or service provider.
2. **Activity facility:** a particular pitch, court, playground or pool, with its own location and conditions. A shared venue can support several activities without becoming several duplicate destinations.
3. **Supporting context:** entrances, transport stops, toilets, water, seating, parking, outdoor areas and heritage information. These are useful facts and routing context, not extra destinations to inflate the count.

Raw source identities remain distinct until reconciled. Geometric containment records a spatial relationship; it does not establish shared ownership, identical identity, entry permission, fees or opening hours. Exact external-ID references are retained as evidence rather than silently collapsing entities. Company offices, registered businesses and historical entities need role/currentness review before they can become visit recommendations.

## Facts required for activity queries

Preserve source names and aliases, identifiers, geometry and its meaning, sports, access restrictions and conditions, fees and charges with conditions, booking/membership requirements, hours, surfaces, lighting, facilities, accessibility, operator/website/contact details, source dates and licence information. Retain all valid source tags, including fields the current interface does not display. Record collection time separately from source verification time.

Unknown, explicitly free, paid, restricted, conditional and contradictory facts are different states. A public-access tag does not prove no fee. Outdoor does not prove public access. A `multi` sport tag does not prove football. A nearby pitch does not prove a free booking slot, usable current conditions or an open gate. Source photos and websites do not grant photo reuse rights.

For an automatic “free football nearby” match, require football capability, geographic proximity, supported no-fee and access evidence, no unresolved booking/membership/conditional restrictions, and no inactive indication. Report current play/booking availability as unknown unless an appropriate live source establishes it. Keep useful uncertain candidates for checking, clearly separated from strict matches.

## Reproduce the collected snapshot

Use an isolated Python environment with `requirements.txt`. These tools write research artifacts only; they do not update application, staging or production tables.

```sh
python collect_osm.py --city-qid Q365 --out ../cologne-expansion/2026-09-28/osm
overturemaps download --bbox=6.77,50.83,7.17,51.10 --release=2026-09-23.1 --no-stac -f geojsonseq --type=place --output=../cologne-expansion/2026-09-28/overture/places.geojsonseq
python collect_wikidata.py --bbox=6.77,50.83,7.17,51.10 --out ../cologne-expansion/2026-09-28/wikidata
python build_inventory.py ../cologne-expansion/2026-09-28
python search_inventory.py ../cologne-expansion/2026-09-28/inventory.sqlite --sport soccer --lat 50.94 --lon 6.95 --radius-km 3
python search_inventory.py ../cologne-expansion/2026-09-28/inventory.sqlite --sport soccer --lat 50.94 --lon 6.95 --radius-km 3 --confirmed-free-public
python -m unittest discover -p 'test_*.py'
```

Paths above assume this directory is the working directory. City selection, download region and query origin are arguments rather than hidden design assumptions. For a later refresh, use a new dated output directory and select the current Overture release from its public catalogue; do not overwrite past evidence. The builder verifies source checksums, a complete OSM query manifest and full-city bounding coverage. Failed requests cannot silently become complete snapshots. Downloads are sequential with backoff for rate limits. Source families may reflect slightly different provider timestamps; the manifest records each one.

The SQLite database contains raw records, facts, an activity index, text search, spatial containment and explicit source links. Large raw downloads and the rebuildable database stay local and are excluded from Git; manifests, query tools, checksums and the report document them.

## Scope and remaining publication work

The [28 September report](../cologne-expansion/2026-09-28/REPORT.md) records observed counts and limits. “Complete” means the specified source queries returned successfully. It does not mean every real-world place or every publicly accessible website has been collected. No source provides a verified denominator for all Cologne places.

Before application publication: reconcile source identities and parent/facility relationships, distinguish customer-facing venues from other business records, refresh existing tags, resolve conflicts and stale/closed records, and map capabilities into the application search contract. Search sport and eligibility before applying result limits. Preserve useful uncertain records without portraying them as confirmed free/public/open. Verify the resulting API and Composer behavior on staging through EXP-72. Media remains optional and governed by the existing approval gate.

## Source references

- [OpenStreetMap copyright and data licence](https://www.openstreetmap.org/copyright); attribution: © OpenStreetMap contributors. Keep ODbL provenance attached.
- [OSM fee meaning](https://wiki.openstreetmap.org/wiki/Key:fee), [access meaning](https://wiki.openstreetmap.org/wiki/Key:access), [football tagging](https://wiki.openstreetmap.org/wiki/Tag:sport%3Dsoccer).
- [Reservation values](https://wiki.openstreetmap.org/wiki/Key:reservation): a possible or recommended reservation is not a required reservation; member-only reservation privileges do not themselves prohibit walk-in access. Preserve the value separately from access and availability.
- [Overture Places guide](https://docs.overturemaps.org/guides/places/) and [provider attribution/licensing](https://docs.overturemaps.org/attribution/#places). Preserve each record's source licences; providers include CDLA-Permissive-2.0, Apache-2.0 and CC0-1.0 records. Foursquare's notice remains linked from that attribution page.
- [Overture regional download instructions](https://docs.overturemaps.org/getting-data/overturemaps-py/). On this run the STAC selection was empty; the documented direct dataset mode completed the same region.
- [Wikidata structured-data licensing](https://www.wikidata.org/wiki/Wikidata:Licensing). CC0 covers structured facts; linked media needs separate rights evidence.
- [NRW and Open Data Germany audit](../cologne-expansion/2026-09-23/tourism-feeds/REPORT.md), [complete ODG inventory report](../cologne-expansion/2026-09-23/tourism-feeds/ODG-REPORT.md). Previously collected snapshots remain explicitly dated; they are not claimed as new records in this run.

No direct Stadt Köln dataset or image provider was added in this collection. No media approval follows from any factual-data licence.
