# NRW nature-media API feasibility — 2026-09-18

**Hold: public search works, but no usable Cologne place photos or reuse rights were established.** This is a state NRW service distinct from Stadt Köln municipal sources; that distinction does not establish asset ownership or permission.

Four unauthenticated API requests all returned HTTP 200:

1. Public collections: 5 returned, including OsirisAlbum (collection 2).
2. General asset sample: 5 recent ZIP records; no media downloaded.
3. Cologne geographic search: **0 geotagged assets returned**, no next page. The documented EPSG:25832 bounding box was `343904,5632748,370685,5661487`, calculated from the existing Cologne polygon and padded outward by 10 metres.
4. OsirisAlbum sample: 5 JPEG metadata records. Every record had `metadoc: null`, zero coordinates, and no photographer, licence or place description. More pages exist, but collection was stopped because rights and location were unresolved.

The zero geographic result does **not** mean that the archive contains no Cologne photographs. Unlocated assets are omitted by the documented geographic filter. Total Cologne coverage remains unknown; confirmed usable Cologne place photos and new publishable places are both **0**.

The [official API documentation](https://mediathek.api.naturschutzinformationen.nrw.de/mediathek/api/) identifies LANUV NRW as provider and documents public collection/asset listing, geographic filtering and optional extended metadata. It does not establish an open imagery licence. An optional copyright-author metadata field is not a reuse grant. The five sampled photos lacked even that field. No blanket provider approval is justified.

No imagery was downloaded or visually reviewed because the API could not establish location or reuse rights. Generic species images would not qualify as park/nature-area hero images without evidence that they depict the specific destination. No Stadt Köln assets were acquired; no token, account, private request, live-data update or publication change was made.

Evidence: `request-log.jsonl`, the four cached files in `responses/`, `summary.json`, `held-photo-metadata.jsonl`, `source-evidence.json`, and `projected-boundary.json`. The small research utilities reproduce the public queries; do not rerun unnecessarily.
