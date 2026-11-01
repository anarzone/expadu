# Fresh dining source snapshot — 24 September 2026

This directory is acquisition/rehearsal evidence, not an imported catalogue and not approved photo coverage.

The public OSM Overpass responses for cafés and restaurants were fetched successfully with source timestamps of 24 September 2026, 16:39:51 UTC. They contain 749 café and 1,806 restaurant elements in the importer's bounding rectangle. These counts include records outside Cologne's polygon, unnamed records and potential duplicate real-world venues. They must not be reported as new publishable destinations.

The fast-food request received HTTP 429, then HTTP 504 after backing off; both configured alternate endpoints later timed out. Bar and bakery acquisition was not reached. The five-category manifest remains incomplete. No incomplete five-category import was run. The complete café/restaurant subset was rehearsed separately with an explicit two-category scope.

The rehearsal uses a fresh private staging restore in a separate local database, exact response hashes, fake HTTP responses for the recorded queries, no queued jobs and an outer rollback transaction. It checks protected identities/memberships/corrections, proposed creates/updates/retirements, replay and exact rollback. Similar-name nearby records are review leads, never automatic merges.

The first attempt hit a local statement timeout, and the diagnostic rerun exhausted the local PHP 128 MB limit. The 512 MB rollback-only rehearsal **passed**, with stable identities/observation counts on replay and exact data rollback. It proposed **1,689 created rows, 317 updated rows, five retirements and 1,707 new observations**. Created rows are not verified unique destinations or photographed places. Seven exact-name/nearby pairs require explicit identity decisions; this check does not catch all legacy naming variants.

No source import changed staging. The completed operator result is `cafe-restaurant-rehearsal.json`; reviewed findings and source checks are in `identity-review/decisions.json`. The batch remains held for duplicate/legacy identity resolution, the Shukran address conflict and Café Hibiskus currentness uncertainty. The source marks the other four proposed retired records as disused; corresponding legacy destination representations also need review. No closure claim is inferred merely from a missing or deleted OSM record.

## Later source follow-up

At 19:23 UTC on 24 September, fresh fast-food and bar queries succeeded with 936 and 206 source elements respectively (source timestamp 19:21:44 UTC). Bakery attempts received HTTP 504 from the main endpoint and timed out on an alternate. The manifest therefore now contains four completed categories and remains explicitly incomplete for the five-category import. The acquisition script continues to independent categories after one query fails, while still returning failure for an incomplete manifest. None of these source counts is a verified destination or photo count. An explicit four-category rollback rehearsal is being performed separately; no staging import is inferred.

- Four-category disposable rehearsal completed successfully: 2,580 proposed new rows, 317 updates, five source retirements, 2,599 new observations, stable identity/observation counts on replay, and exact compared-row rollback. Eleven similar-name nearby pairs require identity review; these checks do not prove all new rows are unique real-world destinations. No staging import applied. Evidence: `food-source-snapshot/four-category-rehearsal.json` (relative to the day directory).
