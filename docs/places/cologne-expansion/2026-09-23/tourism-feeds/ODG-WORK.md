# EXP-70 — ODG inventory continuation

Scope: measure the newly accessible free provider against the actual Cologne boundary and the existing research catalogue. Do not import or approve assets merely because the provider is accessible.

1. Retrieve source identities, coordinates, names, types and image associations for the Cologne rectangle. Include both directly geolocated entities and records using `schema:location`.
2. Verify retrieval completeness, reject duplicate/missing pagination and filter by the saved city polygon. Separate stops, events, routes and geographic areas from destination candidates.
3. Retrieve asset URLs, exact licences, credits and source attribution. Exclude municipal-origin signals. Resolve proxy/resized URLs to their underlying asset identity.
4. Match explicit provider identities to the existing catalogue. Keep name/proximity matches as review leads; do not silently merge them. Report records separately from unique destinations.
5. Download and decode genuinely additional candidate assets; review their place relevance before claiming ready coverage. Preserve publication gates.
6. Save reproducible evidence, update the ticket/wiki, and report the measured coverage and limits in plain language.

## Extraction decisions

- Working transport: normal curl, credentials through stdin, no user-agent spoofing, no redirects carrying credentials, no automatic retry of a denial.
- Respect published rate limits; sequential requests spaced at least four seconds. Raw provider data stays private and the research scripts never write to the application database.
- Large-area pagination failed completeness checks: 7,003 returned rows contained only 5,517 unique keys, while separate count queries differed. Image counts also disagreed. These exports are rejected; they are not coverage evidence.
- A direct numeric-coordinate count timed out. A single large-area response also disagreed with the separately retrieved image associations. Neither is used as a final complete export.
- The replacement divides the rectangle into 16 small cells, compares each index and image result with its count, subdivides further if needed, then deduplicates overlapping cell edges. The initial small cells passed. This avoids asserting an unproven root cause for the large-area inconsistency.

## Final verification

All six audit steps completed. Dense cells used a bounded 5,000-row cap when the initial 1,500-row cap was insufficient; cells whose counts still disagreed were subdivided. The accepted 19 cells cover the full rectangle, with 8,559 unique records and 959 regional image associations before exact-city/category filtering. Query and response hashes, cell-area coverage, unique keys, image-to-record membership, boundary examples and proxy-image equivalence were checked. No API key appears in the public evidence files.

Final result: zero additional photo assets versus the existing catalogue plus NRW; 579 assets now have exact licence and credit evidence, including 19 of the 21 recent NRW candidates. Details and limits are in `ODG-REPORT.md`. No approval, import or deployment. The ODG source audit is complete; EXP-70 and the wider photo objective remain unfinished. Awaiting the owner's next-route preference before expanding the work further.
