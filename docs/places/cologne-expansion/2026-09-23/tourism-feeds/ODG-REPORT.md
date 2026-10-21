# Open Data Germany: completed Cologne source audit — EXP-70

**Result: this source provides no additional photo assets beyond the existing catalogue and the complete NRW feed. It does provide exact licence and attribution evidence for 579 NRW images.** Access is working; lack of API access is no longer the problem.

## What was measured

The Cologne rectangle was covered by 19 verified geographic cells. Each accepted cell's returned record count and image-association count matched its corresponding count query. Overlapping cells were deduplicated by source identity, and reference coordinates were then checked against the saved Cologne city polygon. The export includes directly geolocated records and records linked through `schema:location`.

| Measured inventory | Count |
|---|---:|
| Unique source records in the surrounding rectangle | 8,559 |
| Records with reference coordinates inside Cologne | 5,817 |
| Inside-city transit stops | 902 |
| Events | 162 |
| City/administrative area records | 4 |
| Protected-area records, reported separately | 5 |
| Remaining destination/location candidate records | 4,744 |
| Of those, records with a specific destination type | 2,602 |
| Generic location records requiring further classification/deduplication | 2,142 |

These are source records, **not 4,744 unique, verified destinations**. Among the 4,744 candidates, 279 have explicit identities already in the research catalogue; 1,526 have only exact-name/nearby matches requiring review. The remaining 2,939 have neither match. They must not be presented as confirmed new places. Reference points are not verified entrances, and records with missing geolocation cannot establish city membership through this extraction.

## Photos and their actual contribution

- 211 candidate records have image URLs, representing 585 distinct underlying assets and 587 associations. All 211 match existing catalogue identities.
- 197 of the 2,602 specifically typed destination records have photos: **7.57% source-record coverage**. Including generic location records gives 211 of 4,744, or 4.45%; these denominators must not be confused with catalogue-wide destination coverage.
- **Zero additional photo assets** were found against the combined existing catalogue and fresh NRW inventory. This was checked both by provider asset identity and by exact underlying image path after removing resize/proxy wrappers.
- The separate NRW audit still has 244 image-bearing place records out of 2,148 (11.36%). ODG's overlapping records must not be added to those figures.
- Exact Creative Commons licence URLs and attribution were reconciled to **579 NRW assets**, including **19 of the 21 recently identified NRW image candidates**. This resolves the missing licence-version evidence for those 19, but does not resolve place relevance or currentness. Adolf-Kolping-Denkmal and Rikolonia's recent candidates remain outside that exact-evidence match.
- Club Bahnhof Ehrenfeld, BRAUWELT and PULS again appear as existing groups with candidate images, but these are the previously found NRW candidates. PULS's relevance concern and the other earlier review limitations remain; they are not new ready coverage.

No additional files needed downloading because no new underlying image URLs were found. The earlier 21-file NRW health check is retained. No image was approved, no live place was imported, and the baseline catalogue is unchanged (SHA-256 `c158b0f43332c81001757af87210d100cd26acc7a3aefd0b8f138844d1443784`).

## Rights and reliability

Stadt Köln municipal-origin content remains excluded. No explicit municipal-origin signal was found in the returned credits, author/holder names and source-publisher fields for the candidate assets. This is a metadata screen, not proof of complete upstream provenance or a blanket approval. Exact rights evidence is stored per asset, and publication gates remain in place.

Large geographic requests were rejected after counts and identities failed to reconcile, even when HTTP status was 200. The accepted export uses count-checked small cells. The underlying cause of the large-query inconsistency was not established. Cells were acquired over a time window rather than as one atomic provider snapshot; the manifest records retrieval times and response/query hashes. This audit establishes the exposed geographic feed, not that the provider represents every real-world Cologne place.

## Deliverables and decision

- `odg-summary.json`: measured counts and limitations.
- `odg-records.jsonl`: normalized source names, coordinates, types, city membership, exact identity matches and separate match leads.
- `odg-image-metadata.jsonl`: image/source associations, URLs, exact licence links, credits and provenance-screen results.
- `nrw-exact-rights-evidence.jsonl`: the 579 per-asset rights-evidence reconciliations.
- `odg-page-manifest.jsonl`: accepted cells and query/response hashes; raw responses remain private.
- `odg_inventory.py`, `odg_media.py`, `odg_tiled.py`, `analyze_odg.py`: repeatable acquisition and reconciliation. Use the tiled acquisition; large-area exports are deliberately rejected by the analyzer.

**The ODG source audit is finished; the citywide photo objective is not.** This feed duplicates the existing photo pool and cannot close the broad coverage gap. The next decision is whether to finish preparing the existing photo-backed catalogue for publication or build a free venue/community contribution route to expand coverage. Further source expansion is paused for that discussion, following the owner's instruction to pause if no scalable free solution emerges. EXP-70 remains open. The obsolete support email remains an unsent draft.
