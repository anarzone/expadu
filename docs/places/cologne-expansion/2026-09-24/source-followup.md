# Remaining source checks — 24 September 2026

These checks added no publication approvals. The 75% requirement remains unmet.

## Separate KölnTourismus photo library

The public [Media Portal](https://media.koelntourismus.de/overview) was inspected directly after the text-only web reader could not load it. This was source research, not application UI verification. No account was created, licence accepted, original downloaded or message sent.

The overview lists eight collections: Highlights 151 files; Art & Culture 106; Lifestyle 37; Food & Drink 9; Carnival 9; Christmas 41; Outdoors & Activities 79; LGBTQIA+ 2. These are **434 collection memberships**, not necessarily distinct files and never distinct places.

The complete [Food & Drink collection](https://media.koelntourismus.de/collections/6/media) lists:

1. Halver Hahn
2. Kebapland Ehrenfeld
3. Sünner im Walfisch & Groß St. Martin
4. Hauszeichen Sünner im Walfisch
5. Sünner im Walfisch Salzgasse
6. Sünner im Walfisch & Groß St. Martin
7. Kölsche Gastfreundschaft: Gesellige Runden im Früh Brauhaus
8. Stapelhaus & Kirchturm Groß St. Martin
9. Sünner im Walfisch

The [Kebapland image's rights panel](https://media.koelntourismus.de/collections/6/media/304554761/licenses) displays “©KölnTourismus/KölnTourismus GmbH - Touristisch”. It must not inherit the footer's separate CC-BY-SA link. The inspected image clearly shows the Kebapland sign and diners, but no publication approval is made because its actual licence requires separate review. The saved catalogue already has 10 image leads for Kebapland, 9 for Sünner im Walfisch and image-bearing Früh records; no new destination coverage is established by these names. The Stapelhaus scene remains a lead requiring subject and identity review, not an automatically matched restaurant photo.

This collection cannot supply thousands of missing dining photographs. Other collections can supplement the catalogue, but their per-image rights and venue matches remain unreviewed; no claim is made that the entire portal has been audited.

## Openverse and Flickr

The official [Openverse client documentation](https://docs.openverse.org/packages/js/api_client/index.html) supports anonymous requests, but the web reader received HTTP 403 from the public API landing page on this pass. This is an access limitation of this attempt, not evidence of zero Cologne content. Earlier saved research also records unsuccessful broader queries; no bypass or new credentials were used.

The official [Flickr search explorer](https://www.flickr.com/services/api/explore/?method=Flickr.photos.search) supports geographic and licence filters and requires an API key. Its [API terms](https://www.flickr.com/help/terms/api) distinguish commercial application authorization from each photographer's licence, and reserve possible fees. No owner-controlled Flickr key or confirmed free commercial authorization was established in this pass. Therefore Flickr is a conditional evaluation route, not a proven free bulk solution. No account/application was submitted or payment authorized.

## Delivery implication

### Follow-up after owner authorization

Geograph Deutschland is an additional open-photo source. One [public search within 10km of Cologne centre](https://geo.hlipp.de/search.php?i=956495) returned 1,062 images on 24 September. The first page includes repeated cathedral, bridge and Christmas-market views; this radius is not the city polygon and the count is not unique destinations. Its [terms](https://geo.hlipp.de/help/terms) describe Creative Commons photo submissions and request no spidering or leeching. No automated catalogue crawl was performed. Specific asset licences, identity matches and overlap remain unverified. Raw response evidence is saved under `geograph/`; zero approvals are inferred.

The [Kölner Straßenlexikon](https://bilderbuch.koeln/) displays 1,305 photographs, 5,910 streets and 8,333 monuments. Those are site figures, not additions to Expadu. Its visible copyright notice did not establish a blanket free reuse licence, so no images were imported or counted.

The new [Mapillary direction check](mapillary-direction/REPORT.md) scanned the full saved camera inventory rather than only nearest/newest leads. A 22-image diagnostic review again failed to establish suitable hero images. This prevents falsely equating camera-facing metadata with actual photo coverage; it does not establish the absence of better frames.

Existing sources and further review remain useful, but the measured inventory does not support promising 75% free, relevant venue-photo coverage. Continue documenting exact gaps and review outcomes. A larger collection with explicit rights or owner/photographer contributions would need to supply the remaining places; sending requests remains separately authorized work. Do not replace missing venue photos with nearby street scenes, food stock photos, logos or generated images to pass the threshold.

## Openverse direct API access recovered later on 24 September

The earlier HTTP 403 came from the web reader. A direct anonymous request to the documented public `/v1/images/` endpoint with the application's user-agent succeeded with HTTP 200. Filtered `Cologne` and `Köln` requests returned 20 images per page and each reported a 240-result, 12-page window. Those reported totals are not unique venues or an inventory of all Cologne photographs. The responses include Flickr and Wikimedia sources and retain individual licence and landing-page links. Per-image original-source rights and relevance remain unverified. The response reports anonymous limits of 20 requests/minute and 200/day; a bounded follow-up search respects those limits. No new registration or credential was needed. This supersedes the earlier conclusion that the web-reader error prevented evaluating this source. Saved evidence: `openverse/`.
