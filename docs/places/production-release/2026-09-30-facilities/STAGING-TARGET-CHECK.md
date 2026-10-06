# Read-only staging mapping of reviewed cohorts

Refs EXP-69 and EXP-72. Continues release acceptance step 4 in
`docs/places/production-readiness.md`; no new application feature or rollout.

The reviewed copied catalogue is not the staging catalogue. Map the 90 public
facilities and 484 legacy-reference pairs using exact OSM source identities and
the retained legacy evidence, never the copied catalogue's numeric IDs.

- [x] Verify the existing isolated application's 467 runtime hashes and both
  operator helper hashes. Boot Laravel only on its existing isolated database.
- [x] Connect separately to `expadu_staging` with a REPEATABLE READ READ ONLY
  transaction and a 30-second per-statement timeout. Execute SELECTs only.
- [x] Compile the candidate's native eligibility SELECT in the isolated app and
  execute it on that guarded staging handle. Treat this as policy evidence,
  not deployed API evidence.
- [x] For all 90 facilities, check unique source identity, category, complete
  current tags and source-proved coordinates, held/public state, observations,
  corrections, grouping and duplicate-source proximity. Unknown fees remain
  unknown; no qualification is written.
- [x] For all 484 reference pairs, check the actual staging source target and
  exact retained legacy name/category/tags/map point. Separate existing links,
  ambiguous or missing aliases, source drift and native/journal review holds.
  Preserve existing reconciliations, facts, incoming references and journals.
- [x] Record overlapping ancestor families between otherwise pending reference
  pairs and facilities. This is sequencing evidence; it is not a recovery trial.
- [x] After the isolated bootstrap, wrap the already read-only staging PDO in a
  dedicated SELECT-only connection with the same read/write handle and disabled
  reconnect/transaction controls. Verify these guards before native reads.
  Resolve all eligible records through the native people/Composer contracts,
  and exercise the detail endpoint for the 90 held facilities and the mapped
  already-resolved references with a synthetic, unpersisted user. Only synthetic
  feedback may be queried; no real user data or location service may be used.
  These are candidate-code checks against staging data, not a deployed-version
  or end-to-end live HTTP acceptance claim.
- [x] Export aggregate counts and evidence hashes only. Keep any target IDs or
  per-record mapping inside the existing protected server directory. Do not read
  users, sessions, saved plans, reviews, feedback, media bodies or credentials
  into the mapping report. Do not create a private archive.
- [x] Publish exact findings in the existing report and ticket/wiki pages. Keep
  PR61 draft and the tickets open until live acceptance and rollout are approved.

Verification: all 90 and 484 inputs must receive one outcome; every executed
target query must be a SELECT; the target must report read-only before and after;
no raw target rows or credentials may appear in exported JSON. The existing code,
tests and independent reviews stay unchanged. Audit refusals are findings, not
permission to weaken source, identity or recovery gates.
