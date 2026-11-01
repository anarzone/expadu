# Tourism feed audit — EXP-70 — 23 September 2026

**Latest result:** the full bounded ODG audit is now complete. It adds no new photo assets beyond the catalogue and NRW feed, but supplies exact rights evidence for 579 NRW images. See [the completed ODG report](ODG-REPORT.md) and `odg-summary.json`. The earlier access/debugging notes below are preserved as history; the broader citywide-photo objective remains unfinished.

The NRW access is operational and its complete Cologne feed has now been retrieved and reconciled. It does **not** solve broad photo coverage. **Open Data Germany access also works with the documented curl client**, verified on 23 September at 09:52–09:55 UTC. The earlier Python-client denial does not require provider intervention to proceed. The prepared support reply remains an **unsent draft**, and its request to enable access is now obsolete.

## Latest correction: documented curl tests succeeded

- Authentication `/api/cam/users/me`: HTTP 200 with the issued key. The response's account status was `INVITED`, but actual data access succeeds; no additional activation is needed for these tested reads.
- Exact documented Wartburg Query API V2 example: HTTP 200, five records returned as requested.
- Known Kölner Dom entity via the V2 detail endpoint: HTTP 200, name/address/coordinates returned. This particular Place record has no image field; an accessible record is not proof of photo coverage.
- Cologne bounding-box SPARQL endpoint: HTTP 200, 120 bindings in the limited first page. This is a page-size result, not a city inventory total. The broad query includes transit stops and outside-city points within the rectangle, so category and exact-boundary filtering remain necessary.
- Three deliberately image-bearing regional records were queried and their V2 details retrieved: all HTTP 200. Image content URLs and explicit Creative Commons licence URLs were present. The records were a protected area and two cities, not three new Cologne venues; no coverage gain or publication approval is claimed. Some attribution identities remain graph references requiring resolution.
- All 11 links in the provider email resolved successfully in direct checks. Ten returned HTTP 200 immediately. The apparent 2023 terms PDF returned a redirect to the **March 2026 German terms PDF**; its HTTPS destination returned 200. The March 2026 English PDF also returned 200. Both were read fully. The earlier version-mismatch claim came from relying on the original URL and web-reader result rather than inspecting the live redirect; it is withdrawn.
- RSS returned valid XML with ten entries. The supplementary service-status page returned HTML 200, but this only establishes page reachability, not component health. Newsletter and MCP registration pages were read without subscribing/registering. MCP remains optional and requires a separate key according to the email.

The documented curl client was used with its normal identity, no user-agent override, automatic retries, insecure TLS, proxy rerouting or alternate credentials. Header credentials were passed through stdin. The sandbox initially could not resolve the host; the network-enabled execution then succeeded. Python's earlier 403 and curl's success establish client-dependent behavior, but do not isolate whether the gateway distinguished HTTP headers, TLS behavior or another client characteristic.

Evidence: `odg-curl-results.json` records 23 bounded checks with statuses and response hashes; `check_odg_curl.py` contains the reusable bounded API checks. Raw responses and PDFs remain in the ignored private research directory. Existing `acquire.py` still uses Python urllib; use the tested curl transport when continuing ODG acquisition. No application data, rights approvals or deployments changed. The next substantive work is the full geographic inventory, exact city-boundary/category filtering, source deduplication and asset-by-asset rights reconciliation—not another access request.

## Verified NRW inventory

All 24 pages completed successfully, with a consistent total of **2,348 unique source records**. This updates the earlier first-page total of 2,354; the source changed between checks. Pagination offsets are contiguous and no source IDs repeat. All 2,148 place reference points fall inside the saved Cologne boundary.

| Source type | Records | Records with image links |
|---|---:|---:|
| Sights and other POIs | 246 | 196 |
| Dining | 1,330 | 48 |
| Accommodation | 572 | 0 |
| **Place records** | **2,148** | **244 (11.36%)** |

The remaining 200 records are 175 events, 24 routes and one city record; they are not counted as places. The place records contain **660 distinct image URLs**, representing 663 place-image associations. Booking/social links are not images.

## Actual incremental value

- 315 records already have an explicit source identity in the 12,857-group research catalogue. All 244 image-bearing records are among these existing matches.
- 1,833 source records lack that source identity in the catalogue, but **none has an image**. They are not 1,833 new destinations: 506 already have an exact normalized name within 100 metres of an existing group and need identity review. These are leads, not automatic merges.
- 21 image URLs on 14 existing place records are absent from the refined catalogue. All 21 downloaded and decoded successfully. Absence from this catalogue does not prove that a photograph itself is new or had never been encountered previously.
- Four currently empty groups received image candidates: Rikolonia Radwerkstatt am Heumarkt, Club Bahnhof Ehrenfeld, PULS and BRAUWELT. An individual image from each was visually inspected. This did not establish four publication-ready places.
- PULS's candidate depicts the town-hall tower and LEGEND building, without identifying the PULS venue. Keep it on hold. Rikolonia shows a workshop interior and relies on the provider association for identity. The club photo shows its entry area, but currentness remains unchecked. BRAUWELT depicts the Sünner brewery facade with a 2021 anniversary sign; do not imply that the photograph is current.

**No new ready-photo coverage is claimed. No catalogue groups were added, no live records imported, and no image rights were approved.** The baseline remains unchanged at 12,857 provisional groups, including 1,076 groups with linked photo candidates; those candidates are not equivalent to approved/live coverage.

## Rights and exclusions

The place-image metadata uses the label CC-BY-SA and supplies source credits. This API template does not establish a specific licence version for each asset. Retain exact licence/attribution evidence and currentness checks before approval. The textual provenance screen found no explicit Stadt Köln origin signal in the returned place author/copyright fields or image-credit fields; that is a screening result, not proof of complete provenance. Stadt Köln municipal-origin content remains excluded from any eventual import. A municipal venue name or location alone is not proof that an independent photographer's image originated from the city.

## Earlier Open Data Germany Python-client denial — superseded by successful curl tests above

The issued key was saved in the existing private, ignored credential directory (file mode 0600). Both the documented account example and the geographic data endpoint returned HTTP 403. One bounded diagnostic request captured **Cloudflare error 1010, browser_signature_banned**, with `retryable=false` and `owner_action_required=true`. The response says the site owner blocked the client's signature. This does not establish that the key is invalid. No alternate identity, user-agent spoofing or security bypass was attempted, and no further ODG requests were made.

Diagnostic reference: 2026-09-23 09:32:56 UTC; Ray ID `a3f88b87d99c4f92`, DUS. A reply in the existing provider email thread explains the denial and requests supported access. **The owner chose “Keep it as a draft”; it was not sent.**

## Reproducible evidence and next decision

- `page-manifest.jsonl`: offsets, counts and response hashes for all 24 NRW pages. Raw provider responses remain private.
- `summary.json`, `place-reconciliation.jsonl`: complete source counts, city-boundary checks, existing identities and possible duplicate leads.
- `image-inventory.jsonl`, `updated-image-candidates.jsonl`, `image-health.jsonl`: URLs, provider credits/licence labels, image associations and decoded-file hashes.
- `visual-review.json`: bounded visual inspection of the four potential newly illustrated groups.
- `odg-denial.json`: sanitized diagnostic response; no API key.
- `acquire.py`, `analyze.py`, `check_updates.py`: resumable offline acquisition/reconciliation. Scripts never write to the application database.

The next automated ODG measurement can proceed using the now-tested curl transport. The public search and map are also operational. Do not present the NRW result or the ODG access test as completion of the original citywide-photo task. EXP-70 remains unfinished. Further work on the 1,833 photo-less source records can improve place inventory after deduplication, but cannot deliver the requested media coverage by itself.

## Earlier web-reader documentation check — corrected by direct curl checks above

Read the provider email's authentication, data-access, terms, public search/map, graph explanation, changelog, newsletter and MCP links. Findings:

- [Authentication](https://changelog-dzt-kg.readme.io/docs/authentication): confirms the proxy host and `x-api-key` header already used. No additional activation step is documented. The email's old fragment still reaches the correct page. OAuth is retired.
- [Data access](https://changelog-dzt-kg.readme.io/docs/how-to-access-the-data): recommends Query API V2, limited pages and separate object-detail requests. The separately documented geographic SPARQL endpoint still uses `/api/ts/v1/kg/sparql`; its version alone is not evidence of a faulty request. Keyword searches are not city-boundary queries.
- [Public search](https://open-data-germany.org/datenbestand-such-widget/): verified a live search for Kölner Dom and opened its detail panel. It returned the Cologne record at 50.942451, 6.957469. The same search also returned records in Glandorf, Trier and Wetzlar, so its result totals cannot establish Cologne coverage. No image rights or coverage were approved through this check.
- [Public map](https://open-data-germany.org/datenbestand/): loaded live category totals, markers and record cards. The observed totals covered the initial national map extent, not a verified Cologne boundary. These are operational public inspection interfaces, not API activation steps.
- [Email-linked terms PDF](https://open-data-germany.org/wp-content/uploads/2023/03/2023-03-16-AGB-Datennutzer-Knowledge-Graph_DZT.pdf): readable, but dated 2023, whereas the registration form previously linked a March 2026 document. The 2026 PDF could not be retrieved with the web reader during this check. Record the version discrepancy; do not infer which document governs. Per-asset licensing still needs verification.
- [Graph explanation](https://open-data-germany.org/knowledge-graphen/), [changelog](https://changelog-dzt-kg.readme.io/changelog) and [technical newsletter](https://open-data-germany.org/anmeldung-technik-newsletter/): pages readable; newsletter is optional and no subscription was submitted.
- [MCP guide](https://changelog-dzt-kg.readme.io/docs/mcp-getting-started) and [MCP registration](https://open-data-germany.org/en/retrieve-open-data/#MCP): readable. The email explicitly requires a separate MCP key. This is another interface to the same database, not evidence of additional place/photo coverage. No MCP registration was submitted.
- [RSS](https://changelog-dzt-kg.readme.io/changelog.rss) and the supplemental [service-status page](https://status.opendatagermany.io/) could not be verified through the web reader; this does not establish that either URL is broken or the service is down.

Correction to the access conclusion: the evidence establishes that the tested Python client received Cloudflare 1010. It does not establish that the key is invalid or that every supported client is blocked. No new requests to the denied API endpoint were made during this documentation check. The support reply remains an unsent draft, as requested.

Official API references: [NRW search and pagination](https://help.destination.one/portal/de/kb/articles/meta-service-search), [ODG authentication](https://changelog-dzt-kg.readme.io/docs/authentication), [ODG geographic query](https://changelog-dzt-kg.readme.io/docs/pois-in-and-around-berlin), [ODG rate limits](https://changelog-dzt-kg.readme.io/docs/rate-limits). Context7 returned no relevant ODG library, so the provider's documentation was used.
