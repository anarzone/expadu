# Cologne places release preparation — 28 September 2026

Refs EXP-69, EXP-70, EXP-72.

Prepare a reproducible, additive release from the complete 28 September OSM
inventory and 23 September Overture release. The previous broad inventory is
input, not a production-ready count. Photos are optional and never approved by
this process.

1. Read the current staging release and catalogue. Export only public place
   data, source observations, reviewed corrections and geographic boundaries.
   Do not export users, private plans, sessions, saved places or credentials.
2. Normalize supported destination categories and retain complete original
   source records, dates, licences and practical facts. Separate destinations,
   activity facilities, unsupported categories and review holds. Missing fees,
   access, opening hours and availability remain unknown.
3. Check the city boundary, stable source IDs, names and coordinates. Screen
   current and proposed records for identity conflicts using names, addresses,
   contact details and proximity. Hold ambiguous pairs instead of merging on
   distance or blocking unrelated records. Explicitly closed/restricted records
   and facts the deployed resolver cannot safely represent are not promoted.
4. Build a frozen pack with source checksums, all decisions and exact expected
   existing-row fingerprints. Import only selected IDs; absence is never a
   deletion signal. Preserve reviewed identities, memberships, corrections and
   media. No table truncation or category-wide retirement.
5. Rehearse in a new isolated local database with the deployed application
   schema and the places-only baseline. Verify every imported identity and
   observation, supported categories, native Places API and Composer results,
   strict free/access claims, repeat-import stability and exact rollback.
6. Publish the measured ready destination count, separate facility count,
   additions versus refreshes, explicit holds and remaining integration limits.
   Update the existing Jira tasks. Production promotion remains separate from
   preparation; never describe an unpromoted pack as live.

Definition: a prepared destination must have a traceable licensed source,
usable source name, in-city source point, supported category, stable identity,
no unresolved detected duplicate/closure/access conflict, and a successful
native application rehearsal. This is source-backed catalogue readiness, not
an assertion that every business was independently visited or every uncertain
attribute was verified. Overture-only additions also require high existence
confidence, reported open status and practical contact/address evidence.

28 September: automatic approval review rejected a full staging database copy
because unrelated user data might be included. The procedure above uses only
places data and does not require that export.
