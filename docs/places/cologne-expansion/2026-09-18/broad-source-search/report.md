# Broader free-source search — 18 September 2026

EXP-70 / EXP-72. This search expanded beyond database records and direct OpenStreetMap image links. It found additional reusable collections and a scalable aerial-image option. **It has not yet established majority coverage with ordinary photographs of distinct Cologne destinations.** Counts below are source evidence, not a revised count of ready places.

## Substantive new findings

| Source | Measured result | Practical value and limits |
| --- | --- | --- |
| [Köln Vorort](https://koeln-vorort.de/koeln-vorort/) | Retrieved all 451 public photo posts, 451 distinct image URLs, 82 district labels; all 451 posts include a CC BY-SA 4.0 link | A new independent collection covering outer districts. Captions identify streets/districts, generally without coordinates. Many photographs show residential streets rather than named venues. These are not 451 newly covered destinations. |
| [Wikimedia Commons category collections](https://commons.wikimedia.org/wiki/Category:Restaurants_in_Cologne) | Traversed 180 category pages; discovered 3,516 files; checked metadata for 3,000. Of these, 2,729 carry an allowed CC licence URL and author metadata; **2,108 were absent from the earlier Commons title inventory** | Material missed by the earlier direct-link approach. Includes restaurants, cafés, playgrounds, parks, museums and sports facilities. Also includes objects, historic views and repeated images of the same venue. Subject, provenance and current relevance remain unreviewed. |
| [Geobasis NRW aerial imagery](https://www.wms.nrw.de/geobasis/wms_nw_dop?SERVICE=WMS&REQUEST=GetCapabilities) | Retrieved three actual outdoor-place images and their capture metadata: 10cm ground resolution, captured 11 May 2025 | A free source of real overhead views for parks, playgrounds and outdoor facilities. Separate from ground-level venue photography. No citywide usefulness/occlusion audit was performed. |
| [Data Hub NRW](https://tourismusverband.nrw/themen/datenmanagement/open-data-finder) | Public full-text “Köln” search reports 4,212 mixed regional results; a 15km radius reports 3,578. The first 15 radius records contained no image field | A substantial place-information lead, including gastronomy. These totals include the surrounding region and are not photo coverage. Full Cologne-only feed evaluation requires its documented access process. |

Köln Vorort requires the credit “CC-BY-SA 4.0 koeln-vorort.de”. The photographer also describes donating usage rights to the municipal archive; our evidence is the photographer's own public licence, not a municipal dataset or archive licence. Any photos retrieved from third-party collections still require origin checks before approval.

NRW imagery is provided by the **state surveying authority**, Geobasis NRW / Bezirksregierung Köln, rather than Stadt Köln. Its [published terms](https://www.bezreg-koeln.nrw.de/system/files/media/document/file/lizenzbedingungen_geobasis_nrw.pdf) specify DL-DE-ZERO-2.0. The examples are Spielplatz Manderscheider Platz, Reischplatz and Gemeinschaftsgarten Pantaleonspark. An aerial view can show layout, trees and facilities; it cannot establish an entrance, indoor atmosphere, accessibility or current operation.

## Commons overlap and limits

The 3,000 metadata records include 667 titles already in the previous Wikidata/OSM-linked Commons retrievals and 2,333 previously unseen titles. Of the unseen titles, 2,108 pass the narrow licence-URL/author metadata check. This is not visual deduplication, legal clearance or image approval. A JPEG can depict a museum object, label, old building or menu rather than a useful destination overview.

New metadata-qualified files by collection root: restaurants 372, cafés 51, playgrounds 20, sports 249, parks 1,042, museums 365 and libraries 26. Root totals overlap and **must not be summed**. The bounded traversal leaves 306 queued categories and 516 discovered files without metadata retrieval. It is not an exhaustive Commons census.

Explicit category references in existing OSM records connect 764 new file candidates to 33 groups, all of which already had image candidates. This demonstrates richer galleries, not an increase in destination coverage. Additional subjects cannot be assigned to places on name similarity or camera proximity alone.

## Other resources investigated

| Resource | Finding / disposition |
| --- | --- |
| [Flickr / Openverse](https://openverse.org/) | Initial public search returned 20 records of a reported 240 for Cologne, including independent Flickr photography. Broader requests returned 401/403 and were stopped. Some licences forbid commercial use or adaptation; source-level filtering is insufficient. No exhaustive inventory obtained. |
| [KölnTourismus media library](https://media.koelntourismus.de/) | Separate photo-library lead beyond the POI website. Its [CC BY-SA document](https://www.koelntourismus.de/fileadmin/Mediendatenbank/Sonstige-Dateien/PDF/pixxio/pixxio-Lizenzen_CC-BY-SA.pdf) supports selected licensed assets, not blanket approval of the whole library. Inventory not measured. |
| [Open Data Germany](https://open-data-germany.org/abruf-open-data/) | Structured national tourism catalogue, with API, MCP and service portal accessing the same inventory. Registration/key required for full access. Possible overlap with NRW and tourism sources must be removed. No account or access request submitted. |
| [KuLaDig / LVR](https://www.kuladig.de/) | Cultural landscape and heritage records with per-image rights. Historic and municipal images must not be treated as current reusable venue photographs. Search paths disallow automated crawling; no search crawl performed. |
| [Geograph Germany](https://geo-en.hlipp.de/) | Geographical CC photography lead. Automated retrieval returned 403 and stopped; current Cologne volume not measured. Historic national totals are not evidence of current Cologne coverage. |
| [Pexels](https://www.pexels.com/license/), [Unsplash](https://unsplash.com/license), [Pixabay](https://pixabay.com/service/license-summary/) | Free licensed photographs exist, including Cologne café/restaurant scenes, but exact venue identity, free versus premium status and each platform's restrictions need checking. Not CC0 by default and not a structured complete venue catalogue. |
| [ccnull](https://ccnull.de/foto/teich-im-park-volksgarten-in-koeln-aus-der-vogelperspektive/1039073) | Specific Volksgarten image with CC BY 2.0 claim; useful supplementary lead, no measured city inventory or import. |
| [Spielplatztreff](https://www.spielplatztreff.de/nutzungsbedingungen) | Relevant playground photos, but contributor rights granted to the operator do not grant us reuse; automated crawling requires consent. Excluded from automatic acquisition. |
| [Koelnmesse](https://www.koelnmesse.de/press-office/bilddatenbank/), [Sporthochschule](https://bilddatenbank.dshs-koeln.de/), [Erzbistum](https://www.erzbistum-koeln.de/mam-nutzungsbedingungen/index.html) | Photo libraries exist, but editorial, subject or eligible-user restrictions prevent treating them as general free app-photo feeds. |
| [Bilderbuch Köln / Werkladen](https://www.werkladen.de/bilderbuch-koeln) | Large historical collection is not evidence of a free reuse grant. Not adopted as a free source. |
| [Piqs](https://piqs.de/) | Old claims about a CC photo database were not established as a usable current collection. Not counted. |
| Panoramax / Mapillary / KartaView | Street-level photography can supplement exact venue views. Prior Panoramax results remain separately documented. No new broad coverage result established for Mapillary/KartaView in this pass. |

## What this changes

The acquisition approach should combine independently licensed tourism records, deeper Commons subject discovery, local photographer collections and exact street-level photos. NRW and national tourism feeds deserve a complete city-filtered evaluation through their supported free access process. Multiple frontends exposing the same data must not be counted as independent coverage.

There is now a concrete free alternative to a placeholder for some outdoor places: a clearly labelled, dated aerial view. It remains a product choice, not a substitute silently counted as a normal venue photo. Sample images and metadata are included for review; the application has not been changed to use them.

The unresolved problem remains ordinary photographs for the long tail of dining, shops and indoor facilities. This search does not justify promising 80–100% coverage. Public visibility alone never supplies reuse permission. If supported free feeds and exact-photo matching do not close this gap, owner/user-contributed photography is a separate acquisition route requiring work and rights consent, not an already available dataset.

## Artifacts and acceptance status

`koeln-vorort-photo-records.jsonl` preserves captions, source/image links and per-post licence evidence. `commons-category-media.jsonl`, `commons-new-file-candidates.jsonl` and their summaries preserve file-level credits and overlap results. `aerial-examples.json` links the three sample JPEGs to service queries, capture dates and licence evidence. Raw source responses remain available locally for reproduction; the review ZIP includes a selected evidence set.

No paid source, municipal dataset import, live catalogue import, blanket rights approval or deployment was performed. EXP-70/72 remain open. The existing place coverage denominator and numerator have not been inflated with these collection sizes. Readiness still requires exact destination matching, duplicate/component reconciliation, allowed provenance, image health, visual relevance and current-place checks.
