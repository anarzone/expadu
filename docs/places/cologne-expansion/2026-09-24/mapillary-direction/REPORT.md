# Direction-based photo selection check

The prior sample considered nearest/newest camera records. This offline pass instead scanned all 1,559,525 saved camera records and ranked non-panorama frames by direction toward the place reference point, date, quality and distance. It does not recrawl imagery or establish a current city census.

Criteria: capture since 2020, provider quality score at least 0.65, distance 8–60 metres, bearing within 20 degrees of the place coordinate. The top three were retained for each qualifying group. This produced leads for 7,003 of 12,857 provisional groups, including 6,534 without existing photo leads. These figures are camera candidates, **not photo coverage**. Coordinates, direction and quality scores do not prove visibility or venue identity.

A deterministic diagnostic sample selected ten dining, eight shopping, two sports and two park/garden records from the recent subset (since 18 September 2023), using ascending SHA-256 of place IDs within each category. The sample is not a statistical citywide estimate. All 22 source thumbnails were fetched through the existing read-only application token and visually inspected; file hashes, creator/organisation metadata and individual decisions are saved alongside this report. No credentials were copied into evidence and no image was approved or imported.

All 22 proposed hero images were withheld. Pizza Mia is partly clipped, Pinocchio is visible but distant, and the City-Center facade does not show the requested EuroShop. Other frames show roads, adjacent businesses, squares, trees or buildings without a confirmed view of the requested venue. Detailed decisions are in `visual-review.json`.

This rules out treating the tested direction heuristic as an automatic photo-publication rule. It does not prove that every other frame is unusable. Further work would require stronger visual matching, correct entrance/footprint geometry and venue-facing panorama presentation. None currently has evidence that it can reach the required 75%.

The official [Mapillary example](https://github.com/mapillary/api-demo/) documents image metadata, detection and sequence APIs and a 360-degree viewer. The [platform licence guidance](https://help.mapillary.com/hc/en-us/articles/115001770409-CC-BY-SA-license-for-open-data) supplies a rights basis subject to attribution and share-alike obligations; individual provenance, relevance and health checks still apply. This pass did not alter photographs or publish a viewer.
