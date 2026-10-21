# Schildergasse storefront source pass — 18 September 2026

This source adds **three strict address-matched, visually checked missing-photo candidates** to the offline catalogue: Peek & Cloppenburg (Weltstadthaus), United Colors of Benetton, and UNIQLO. **Two further visually usable candidates** — SportScheck and Five Guys — now have their branch identity resolved by current official operator listings plus exact OSM records; their different address representations remain documented. All five photo candidates have resolved branch identity. See `currentness-review.jsonl` for the follow-up evidence and its limits. Nothing was approved, imported, or changed in the application/database.

## Rights evidence

The live [provider licence page](https://www.schildergasse.koeln/lizenzen/) grants CC BY 4.0 to its own photographs marked with its copyright credit. It does not grant a blanket licence for all hosted images. Attribution requires the provider name and link, licence name/link, and disclosure of changes. The page also explicitly distinguishes external images and logos.

The 278 public WordPress media records divide into:

| Evidence | Files |
|---|---:|
| Explicit individual image copyright credit | 62 |
| Explicit enclosing gallery copyright credit | 37 |
| External creator credit; no reusable licence established here | 63 |
| Unknown/unmarked, including logos and unused variants | 116 |

The 37 gallery records are reported separately: their particular enclosing gallery carries the provider credit. They are not individually captioned as licensed. A reviewer can inspect the exact gallery caption and constituent media ID in the stored evidence. No licence has been inferred from filenames, uploader identity, or mere hosting. Even licensed images can be poor or misleading place-card choices.

## Measurable place gain

| Place | Catalogue ID | Match | Visual result |
|---|---|---|---|
| Peek & Cloppenburg | osm:node/255240848 | Exact Schildergasse 65; article identifies the store inside Weltstadthaus | Usable facade with store signage |
| United Colors of Benetton | osm:node/577271440 | Exact Schildergasse 75; documented brand alias | Usable open storefront; dated opening balloons/Black Week signage |
| UNIQLO | osm:node/348530461 | Exact Schildergasse 24–30 | Usable opening-day storefront; explicit gallery credit |
| SportScheck | osm:way/30777324 | Source 38–42 vs catalogue 38, 40, 42 | Usable facade; resolved by official locator point inside exact OSM building |
| Five Guys | osm:node/7542349041 | Source 113–117 vs catalogue 113 | Usable facade; resolved by official branch identity and geocoded point 3.23 m from OSM |

All five catalogue records currently have zero media. These are incremental to the supplied offline catalogue only, not a claim about live production or another concurrent source pass. Coordinates in match records come from the existing catalogue and retain its reference-point role; the provider did not supply verified coordinates in the pages used.

Visual inspection rejected the otherwise branch-matched Hunkemöller and Deichmann photos: both show opaque pre-opening material rather than their completed current shops. Cookie Couture remains withheld because the article says 8, directory says 8–12, and catalogue says 9–12. Karnevalswierts remains withheld: sign-only subject, insolvency/currentness concern and insufficient source address evidence.

Twelve further named/addressed branch leads are preserved for review, without asserting a catalogue branch from name/proximity alone. Galeria's Hohe Straße branch already has catalogue media, so it is not a missing-photo gain. Historic former tenants, renovations, event photos and external promotional photos are not counted as gains. A second pass checked these five candidates against official operator location sources. Four have freshly fetched official listing evidence, including Benetton’s embedded public locator. UNIQLO has current indexed official location text, while its raw locator returns a JavaScript shell; the live rendered listing was not verified. These are operator listing checks, not independent physical visits. Older exterior photos do not establish current interiors or unchanged facades. Benetton’s official locator coordinates are displaced 180.84 m from the matched OSM branch; those coordinates are rejected and not copied over the catalogue point.

## Artifacts

- `catalogue-matches.jsonl`: nine assessed links, with addresses, IDs, image URLs, evidence pages, licensing and match decisions.
- `currentness-review.jsonl`: five current operator-listing reviews, exact OSM references, address reconciliation and photo/currentness distinctions.
- `benetton-current-store.json`: relevant record from the official embedded locator dataset.
- `additional-branch-leads.jsonl`: twelve source branches requiring identity and visual review.
- `media-inventory.jsonl`: all 278 public media records, with individual/gallery evidence and distinct external/unknown outcomes.
- `venue-directory.json`: 74 directory rows (including repeated Galeria category rows), literal addresses and external operator links; provider coordinates remain null.
- `articles.json`: cached extracted content for 80 editorial articles; original API payloads are in `cache/`.
- `visual-review/`: seven cached images actually inspected; rejection reasons are in the inventory.
- `requests.jsonl`, `summary.json`: bounded collection log and counts.
- `collect.py`, `analyze.py`, `finalize.py`: reproducible collection/extraction/reporting steps. Run extraction before finalization; finalization enriches its output.

## Collection boundaries

Public homepage, robots.txt, sitemap, licence, imprint, directory and paginated WordPress media/article endpoints only. Robots permits public content and excludes wp-admin; no admin endpoint was used. Requests were sequential, cached and delayed (0.8 seconds); 21 successful requests are logged plus one initial successful homepage read, two web page opens and three failed web image-cache opens. An initial sandbox DNS failure is also recorded in the summary. No access denial occurred in the first pass. During the second pass, a UNIQLO announcement URL returned HTTP 403; further requests to that domain stopped. The current request totals and denial are recorded in `summary.json` and `requests.jsonl`. Total remains well below the 150-request bound.

Homepage, directory and imprint links did not reveal a clearly related same-operator retail street site, so no speculative related-street crawl was performed. Source article/upload dates are preserved as source claims; they are not independent verification of capture dates. This is a useful small retail source, not broad Cologne coverage.

## Second-pass currentness sources

- [SportScheck Köln](https://filialen.sportscheck.com/filialen/koeln): official branch address 38–42, active listing and hours. Its reference point falls inside [OSM way 30777324](https://www.openstreetmap.org/way/30777324), whose enumerated address is 38, 40, 42.
- [Five Guys Köln Neumarkt Schildergasse](https://restaurants.fiveguys.de/nordrhein-westfalen/schildergasse-113-117): official 113–117 branch; current page data dated 15 September 2026. Its geocoded reference point is 3.23 m from [OSM node 7542349041](https://www.openstreetmap.org/node/7542349041), which uses 113.
- [Peek & Cloppenburg Köln Schildergasse](https://www.peek-cloppenburg.de/de/standorte/haus-226): current operator location at 65–67; the operator directory also uses 65 for this branch.
- [Benetton official store locator](https://de.benetton.com/pages/store-locator): its directly embedded StoreRocket dataset contains visible record 34020837 at Schildergasse 75, with regular hours. Address supports the match, but its coordinate conflict remains explicit.
- [UNIQLO Köln Schildergasse](https://map.uniqlo.com/de/de/detail/11500212): indexed official page identifies 24–30 with regular store hours. Raw JavaScript-shell response and separate 403 are preserved as access/currentness limitations, not concealed.

`update_currentness.py` applies this second review after `analyze.py` and `finalize.py`; rerunning the earlier stages without the last step would lose the follow-up enrichment. No opening hours, new coordinates, application media records or database records were changed.
