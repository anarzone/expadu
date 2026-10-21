# KartaView bounded Cologne photo-source check — 2026-09-18

KartaView's public API works and supplies freely licensed street imagery, but this check did **not establish a usable venue-photo match**. It does not support a claim that KartaView can fill most of Cologne's shop/dining photo gaps. This is a small, purposive sample, not a citywide coverage estimate or a conclusion that other frames cannot depict these places.

## Measured result

- Checked the existing 12,857-record catalogue, including 11,781 records without media candidates. Scanned all source tags for KartaView/OpenStreetCam/OpenStreetView references: **0 exact image-link leads**.
- Selected 14 missing-photo venues, one nearest the centre of each nonempty cell of a 4×4 geographic grid over the catalogue extent. Two cells were empty. No category weighting or random sampling was used.
- Queried each with a 60×60-metre bounding box, then filtered returned public active camera points to an actual 30-metre distance. **2/14 venues had such frames** (Blumen Heinen and Shaktinest);12 had no returned frames. All 14 queries succeeded, with no pagination truncation.
- A separate cathedral-area feasibility box returned 50 rows and more available pages; it was deliberately not paginated. One nonpublic row was redacted and excluded. This discovery box must not be mixed into the 14-venue sample success rate.
- Combined deduplicated inventory: **74 public, active photos with camera coordinates inside the Cologne boundary**, captured 2016–2024. Nearby-camera leads intersect 12 catalogue records, of which 8 lack existing media candidates. This is **8 proximity leads, not 8 photographed venues**.
- No exact OSM photo links, approved venue matches or new publishable places were established. The 74 inventory rows remain unapproved; any uninspected frames remain unverified.

## API feasibility and bounded requests

Official [API documentation](https://kartaview.org/doc/) describes public unauthenticated geographic/photo access. The [FAQ](https://kartaview.org/doc/faq) gives a 100-requests/hour public allowance; requests after feasibility were spaced at least 36.1 seconds apart. No account, token or private endpoint was used.

A documented 500-metre radius request returned HTTP 400/API 408 with a query-timeout message. A much smaller query using the documented `nwLat`, `nwLng`, `seLat`, `seLng` fields succeeded. Fourteen subsequent small bounding-box queries also succeeded. This is measured availability during this check, not a reliability guarantee. `request-log.jsonl` contains 16 actual API responses (15 successful,1 timeout), plus one sandbox DNS failure that did not reach the service. Image fetches have their own `preview-health.jsonl` log.

The unauthenticated response unexpectedly included one nonpublic record. Its metadata, identifiers and image URLs were removed from the cache; only an exclusion marker/count remains. No nonpublic image was accessed.

## Visual relevance review

Initial three previews were selected from available public active photos by **newest capture year, then nearest camera**. That is not a direction-aware selector. Capture dates, headings and available GPS accuracy are retained. An additional bounded KunstBar check selects a 2024 frame with heading 25.3 degrees from the bearing to the catalogue reference coordinate. This reference point is not a verified entrance; direction alone still cannot establish visibility or vertical level.

| Venue | Initial frame | Observation | Decision for that frame |
| --- | --- | --- | --- |
| Blumen Heinen |1059206970,2017,29.62 m |Readable directional sign beside road; no identifiable flower-shop storefront |Sign/arrival lead only; reject as venue photo |
| Foto-Lambertin |2015145721,2024,11.54 m |Foreground pedestrians and railway station; no identifiable shop frontage |Reject as venue photo |
| KunstBar |1953029113,2024,7.01 m |Cathedral plaza and adjacent buildings; no identifiable bar |Reject as venue photo |

All four processed-image URLs returned HTTP 200 and decodable JPEGs. The heading-aware alternate (1953029017) also showed the plaza without an identifiable KunstBar entrance or sign. See `visual-review.json` for all inspected-frame decisions and `preview-health.jsonl` for every image fetch, including the one heading-aware alternate. A rejected inspected frame does not invalidate other unreviewed frames for that venue. No automatic venue association is justified by these proximity checks.

## Licence evidence

KartaView's [official terms](https://kartaview.org/terms) place street imagery under CC BY-SA 4.0 and require attribution **© Grab and KartaView Contributors**. The imagery licence is separate from the software MIT licence. This supports licence eligibility, not approval of an individual frame's relevance, currentness, or health. No Stadt Köln image asset was acquired.

Documentation was read from search-indexed official page text; direct text-only opens returned the site's JavaScript loading shell. API behavior and image health were independently verified through direct HTTPS requests. Context 7 had no KartaView library entry. `source-evidence.json` records these limitations and official links.

## Artifacts and reproduction

- `summary.json`: measured totals and review counts.
- `sample-design.json`: all 14 selected places and the deterministic sample design.
- `public-photo-inventory.jsonl`: deduplicated public source IDs, coordinates, image URLs, capture date, heading, processing state, rights and review state.
- `nearby-camera-leads.jsonl`: exact distance results explicitly marked as camera proximity only.
- `exact-osm-tag-leads.json`: empty exact-link inventory.
- `responses/`, `response-summary.jsonl`, `request-log.jsonl`: cached API evidence, with nonpublic data removed.
- `preview-plan.json`, `preview-health.jsonl`, `visual-review.json`, `previews/`: bounded visual review evidence. All saved images require the above attribution.
- `collect.py`, `analyze.py`, `preview.py`: reproducible research utilities. `analyze.py` is offline; collection/image utilities make bounded public requests and stop on access denial/rate limits. Do not rerun unnecessarily: cached responses and previews already exist.
- `evidence-sha256.json`: integrity hashes.

No application code, live database, publication approval, ticket or commit was changed.
