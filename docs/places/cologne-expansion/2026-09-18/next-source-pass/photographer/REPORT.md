# Photographer source pass — 18 September 2026

This pass finds **three distinct missing-photo venue candidates with primary photo captions and current official address support**: Steigenberger Hotel Köln, Ruder- und Tennisklub Germania, and IKEA Köln-Am Butzweilerhof. These are candidates for image review, **not approved images**. No media was downloaded, published, hotlinked, or written to the application. Both reviewed photo sites contain Marco Verch's work; this is **one author family**, not two independent photographers.

## Measured coverage

| Measure | Result |
|---|---:|
| Author's Cologne tag advertised photos | 768 |
| Unique image cards actually retrieved across 16 requested tag pages | 764 |
| Author-site photo detail pages reviewed | 76 |
| ccnull photo detail pages reviewed | 8 |
| Selected distinct destination subject groups | 39 |
| Groups with catalogue subject matches | 27 |
| Matched groups already containing media somewhere in the group | 20 |
| Matched groups with no media before currentness/subject holds | 7 |
| Missing-media groups with caption + current official address support | 3 |
| Exact image/page URL duplicates found against existing catalogue | 0 |
| Approved assets / downloaded images | 0 / 0 |

This is a bounded selected-subject pass, not a claim that all 764 cards represent places. The tag includes food, people, product shots, cosplay, transient events and AI imagery. The advertised/retrieved count differs by four; the final page returned no image cards. No extrapolated venue count is used. Two generic selected records remain ungrouped. The 39 counts group primary subjects conservatively: one wide aerial is not multiplied into every background landmark or nearby restaurant.

Zero exact URL overlap does **not** establish 84 new photographic works. Cross-host copies, differently cropped versions, and Flickr/Commons reuploads require pixel or original-file provenance comparison. Existing media counts mean any media in the read-only catalogue, not an assertion that its rights are approved. The entire catalogue was checked for exact normalized page/file URLs (query strings ignored); parent/child place IDs were grouped explicitly where evident.

## Stronger missing-photo candidates

| Destination / catalogue ID | Reviewed photo pages | Current venue identity evidence |
|---|---|---|
| Steigenberger Hotel Köln — `osm:way/7828108` | [Luftbild: Steigenberger Hotel Köln am Rudolfplatz](https://foto.wuestenigel.com/luftbild-steigenberger-hotel-koln-am-rudolfplatz/); [Aerial of Steigenberger Hotel in Cologne](https://foto.wuestenigel.com/aerial-of-steigenberger-hotel-in-cologne/) | Official [hotel page](https://hrewards.com/de/steigenberger-hotel-koeln): Habsburgerring 9–13, 50674 Köln, consistent with catalogue. |
| Ruder- und Tennisklub Germania — `osm:way/1391197324` | [Club aerial](https://foto.wuestenigel.com/luftbild-ruder-und-tennisklub-germania-e-v-koln/); [club photograph](https://foto.wuestenigel.com/ruder-und-tennisklub-germania-e-v-koln/); [tennis courts](https://foto.wuestenigel.com/tennisplatze-von-germania-e-v-koln/) | Club's [official imprint](https://rtk-germania.de/impressum.html): Alfred-Schütte-Allee 163, 51105 Köln. Club photos do not automatically match nearby Sportheim or Germania Terrassen dining rows. |
| IKEA Köln-Am Butzweilerhof — `osm:way/156371365` | [IKEA Filiale und Parkplatz in Köln-Ossendorf](https://www.ccnull.de/foto/ikea-filiale-und-parkplatz-in-koeln-ossendorf/1040228) | Official [store page](https://www.ikea.com/de/de/stores/koeln-am-butzweilerhof/): Butzweilerstraße 51, 50829 Köln. Caption's Ossendorf identifies this branch, not Godorf or either restaurant row. |

All six images still require inspection that the venue is recognisable, dated-photo currentness review, image health verification, rights review for the intended presentation, and cross-host deduplication. The 76 author-site records date from 2017–2018; ccnull upload dates do not establish capture dates. No aerial is treated as a verified entrance photograph.

## Four missing-media groups retained on additional holds

- **HEMA Schildergasse:** historic caption identifies Schildergasse, but the current official [HEMA locator](https://filialen.hema.com/de/k%C3%B6ln/hema-keulen) lists Hohestrasse 113. Two existing HEMA IDs remain ambiguous; do not match a brand name across branches.
- **Zalando Outlet Köln:** official [store page](https://www.zalando-outlet.de/koeln/) explicitly states relocation to Schildergasse 70–72. Older city-only photo needs historical address/facade corroboration and must not become a current-location image by name alone.
- **Kölner Jugendpark:** the park and institution are different candidate scopes. The catalogue's `koelner-jugendpark.eu` currently serves unrelated general/SEO articles, so it is not current venue corroboration. Actual operator [JugZ records a 2021 rename/conversion](https://www.jugz.eu/die-traditionseinrichtung-koelner-jugendpark-wird-zum-jugendzentrum-digital/) to Jugendzentrum.digital. Its [current event page](https://www.jugz.eu/dungeons-dragons-festival-im-jugendzentrum-digital/) locates that institution at Sachsenbergstraße 10. Hold the historic aerial pending park-versus-centre mapping.
- **Club Astoria:** caption mentions the club alongside RheinEnergieSTADION. Venue prominence and identity within the wide aerial remain unverified.

Odonien and Hohenzollernbrücke have apparently empty duplicate rows but other same-subject rows already have media; these are **not** counted as newly covered destinations. Other specific false-match protections: Bahnhof Köln-Ehrenfeld is not Club Bahnhof Ehrenfeld; Geißbockheim is not automatically Clubhaus am Geißbockheim; a playground image is not automatically the surrounding park; Kranhäuser/Rheinauhafen/Mediapark ensembles cannot fill every tenant's photo slot.

## Licence and provenance findings

The photographer's current [FAQ](https://foto.wuestenigel.com/faq/) distinguishes CC BY 2.0 and CC BY 4.0 and requires checking each image page. All 76 selected historical author-site pages explicitly attribute Marco Verch and link the author's [CC BY 2.0 Generic deed page](https://foto.wuestenigel.com/licenses/by/2.0/deed.de). Each original JPEG URL, detail page, original title when provided, publication date and exact licence URL is preserved in `reconciled-images.jsonl`.

The eight ccnull pages display the short label “CC-BY 2.0” but link **CC BY 2.0 DE**, confirmed by the [canonical Creative Commons deed](https://creativecommons.org/licenses/by/2.0/de/). The distinction is preserved as `CC-BY-2.0-DE`; do not silently normalize this to generic CC BY 2.0. All eight identify Marco Verch, so they add no separate author family.

Current [ccnull terms](https://ccnull.de/nutzungsbedingungen/) require visible attribution and forbid deeplinking even previews/thumbnails or bypassing their download. Consequently ccnull image URLs here are **provenance references only**, and no hotlinks or download shortcuts were used. The terms contain social-media language that differs from the newer short photo-page wording; keep intended-use review explicit. File pages also give supported download links for later approved ingestion. No images were fetched in this pass.

AI is an actual source issue, not speculation: [CSD Parade vor dem Kölner Dom mit Regenbogenflaggen](https://foto.wuestenigel.com/foto/517951-csd-parade-vor-dem-koelner-dom-mit-regenbogenflaggen) explicitly records AI creation and CC BY 4.0. It is **excluded as a real venue photograph**. The 347 `/foto/` numeric-route cards remain individually unverified; route shape alone is not asserted to prove AI. Do not let a free licence or Cologne tag override an AI exclusion.

## Audit artifacts

- `summary.json`: measured counts.
- `tag-inventory.jsonl`: 764 cards including selection and AI holds.
- `detail-inventory.jsonl`, `ccnull-inventory.jsonl`: primary per-file evidence.
- `reconciled-images.jsonl`: 84 candidate files with exact licence, author, URLs, catalogue matches and holds.
- `subject-groups.json`: destination-level grouping, not photos-as-places counting.
- `official-address-evidence.json`: narrow corroborating facts and official source links.
- `requests.jsonl`, `cache/`, `cologne-tag.html`: cached public source evidence. 108 direct HTTP reads including one charset retry and the initial cached tag page; no 401/403/429 responses, accounts, tokens, UI, or write APIs. Reads were spaced at least 1.1 seconds in the crawler; browser search/open evidence was used separately for current terms and official address verification.

The original refined catalogue was read only. All output stays in this photographer directory. No municipal photographic assets, AI substitutes, app/database changes or commits were introduced.
