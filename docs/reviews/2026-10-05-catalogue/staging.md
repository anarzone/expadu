# Staging catalogue qualification audit — 5 October 2026

Audit checkpoint: **2026-10-05 13:13:07 UTC / 15:13:07 Europe/Berlin**. Read-only inspection. No deployment, import, data qualification, media approval, source reactivation or cleanup was performed. No credentials or user records were exported.

**Update at 13:40 UTC: the running container and bounded current database aggregates are now verified.** The first attempt below was blocked by temporary approval-review capacity; the later read-only retry succeeded. Native consumer qualification of the deployed runtime remains separate from these stored/flag counts. The most recent complete catalogue-only capture is dated 1 October; its eligible counts are useful dated evidence, not a fresh 5 October acceptance result.

## Completed live read-only follow-up

The container was running, started 29 September at 19:48:52 UTC, using `ghcr.io/anarzone/expadu/app:staging`, image `sha256:3cbfc8b82cae73cf6f8b5d4534e7bd7c56736214e048e45fe8fd0a2bc39d0d24`. A repeatable, read-only PostgreSQL transaction returned [current aggregate evidence](staging-live-counts.json) at **2026-10-05 13:40:12 UTC**:

- 11,803 stored places; 798 aliases. All 4,356 source-null rows remain outside active/recommendable canonical flags. There are 4,242 active/recommendable canonical flag matches; this is not the stricter native eligible/list count.
- 4,362 stored events; 1,347 active rows, 1,335 active rows with an unexpired end, 45 recurrence rows, and 143 active unlocated rows. Stored rows are not expanded occurrences or fully qualified schedules.
- 628 media assets; 256 carry approved/active flags. 1,771 attachments, 268 with accepted-match flags. These counts do not prove remote image health or visible hero coverage.
- 86 boundary polygons. No server writes, user-record reads, source approvals or deployments.

This live dataset differs from the historical local seed by at least one stored place and by the event/media imports since capture. Do not overwrite it with local IDs or use the local seed as a staging replacement.

## Current deployment and release evidence

Read directly from the GitHub connector during this audit:

| Evidence | Current result |
|---|---|
| Staging branch | `89289db9641bb75a563e74b44be9b4717bd61b22` |
| Latest staging workflow | [CI 36621142943](https://github.com/anarzone/expadu/actions/runs/36621142943), push on 2026-09-29 at 19:42:28 UTC; completed successfully by 19:49:01 UTC |
| Required jobs | Lint, Test, Browser Tests, Build & Push Image, and Deploy to Staging all succeeded |
| Production deployment in that run | Skipped |
| Newer readiness proposal | [PR #62](https://github.com/anarzone/expadu/pull/62), open, draft, unmerged; head `d75cb825e8ddb29b9ca9fb752b064c8f9b778380`, base `staging` |
| Running image/container inspection | Completed at 13:40 UTC; image and aggregates recorded above |

The five latest staging runs were retrieved, and none is newer than the successful 29 September run. Exact aggregate results are saved in [staging-github-summary.json](staging-github-summary.json). This proves GitHub's recorded deployment outcome and branch head. The separate 13:40 UTC follow-up verifies the running image. Neither observation guarantees that scheduled catalogue writers leave the dataset unchanged afterward.

The source-readiness proposal explicitly has no code deployment. Do not treat its stronger retained-detail status, access-bound facility qualification, or automation controls as deployed merely because its own historical checks passed.

## Latest available staging catalogue capture

The investigation checkout contains a catalogue-only snapshot recorded at **2026-10-01 09:18:34 UTC**, bound to application commit `89289db9641bb75a563e74b44be9b4717bd61b22` and snapshot SHA-256 `e46d38199007add85a73e4901015cad8e7ae1b1282909055194435dd4dfe947d`.

| Captured catalogue table | Rows |
|---|---:|
| `spots` | 11,802 |
| `place_fact_observations` | 7,810 |
| `place_fact_corrections` | 0 |
| `place_reconciliations` | 798 |
| `place_destination_reviews` | 12 |
| `park_areas` | 231 |
| `veedels` | 86 |
| `media_assets` | 229 |
| `media_attachments` | 341 |

These are stored rows, not all publishable destinations or photos. The capture excluded user tables and source actors; administrative attribution was redacted where required. Events and venues were not among the ten exported catalogue tables, so this snapshot establishes no current event inventory.

The snapshot was restored and verified locally at 09:44:48 UTC on 1 October using the native Places/Composer services. The recorded results were:

| Qualification contract | Dated result |
|---|---:|
| Eligible place/component records | 4,235 |
| General destinations | 4,224 |
| Places list total under its display/category filters | 4,139 |
| Eligible OSM / Overture records | 4,067 / 168 |
| Public / unknown access states | 123 / 4,112 |
| Free / paid / unknown fee states | 49 / 37 / 4,149 |
| General destinations with policy-publishable hero | 119 / 4,224 (2.817%) |

The 1 October consumer result is a **local verification of a staging snapshot**, not a fresh check against a live authenticated staging API. The last available check using isolated copies of the running staging source against staging data was **2026-09-30 20:08:35 UTC**; it reported the same eligible/list/photo counts. Its runtime fingerprint was `df5d5376d4f9cc0e322ff42e3e61467b08515546663aa731e73893809eb9055b`.

## Source holds and capability limits

The staging source-null hold was committed at **2026-09-30 20:03:06 UTC**, operation `b501a02d-730c-4cfa-9d06-f68d40356524`, and verified in a fresh process at 20:05:24 UTC. All **4,356 records without source provenance were retained and held**, with zero eligible source-null records. The **798 alias relationships** remained; 791 resolved to eligible canonical records, and seven to ineligible targets. The 3,558 unlinked held records were excluded from Composer by ID. This audit did not recheck the live hold or reactivate anything.

At the 30 September deployed-source check, twelve held details retained their identity and returned HTTP 200 but all twelve lacked `recommendation_status`. PR #62's isolated candidate corrected those twelve cases; that PR is still unmerged. A successful old detail response therefore does not establish current recommendation availability.

The pending set of **90 facilities** (74 playgrounds, 11 table-tennis facilities, three basketball courts, two pitches) passed candidate previews at 2026-09-30 21:58:50 UTC, with known unconditional public access and unknown fees. No live qualifications or apply package resulted. Their source proof was fetched on 30 September at 11:35:58 UTC with a 24-hour window; that old proof is expired for a new operation. Later local qualification work is separate from live staging qualification.

The dated source-hold report also retained 21 changed legacy projections and 469 facilities with uncertain access as unresolved work. Those figures are historical work queues, not freshly counted current staging holds.

The canonical soccer/pitch probe within 3 km of a labeled Cologne-centre sample returned zero candidates both with and without a free-budget filter at **2026-09-30 20:58:18 UTC** using the deployed source. This is a specific scenario gap, not proof of citywide absence. The 1 October local snapshot checks also recorded zero for central, north and east samples.

Media coverage is a policy count only: assets still require approved rights and active health, with provider exclusions preserved. Neither the historical consumer probe nor this audit freshly validated photo URLs or every source fact. The stored total of 229 media assets must not be reported as 229 approved, attached, usable place photos. The 119 hero associations do not establish current remote health or a 75% coverage goal.

## Events and current unknowns

The follow-up counted current event rows and active flags, including unlocated rows, as recorded above. Native visible events, recurrence-expanded occurrences, per-source freshness, usable locations, unknown prices and publishable event-media coverage remain **unqualified**. The historical place snapshot excludes events; local event qualification is documented separately in `events-media.md`.

The following remain necessary for a current staging acceptance result:

1. Preserve the completed running-image and aggregate observations above as dated evidence.
2. Re-evaluate native place/fact eligibility, aliases, holds and policy media coverage against the actual deployed runtime; stored flag counts alone are insufficient.
3. Count visible events and expanded occurrences for explicit Europe/Berlin date windows; keep stored events separate from occurrences and require supported venue/location/media facts.
4. Check representative authenticated Places, Composer and event responses using the established synthetic-user/isolated-kernel read-only guards or an authorized dedicated QA account. Do not weaken authentication or copy real users.
5. Recompute dynamic availability from the actual request time and refresh any time-bounded proof before a separate qualification operation.

## Initial access limitation — resolved by the 13:40 UTC follow-up

The initial GitHub CLI read failed because sandbox network access was unavailable. Its escalation did not execute: automatic approval review failed because the selected reviewer model was at capacity. The read-only GitHub connector subsequently supplied the branch, workflow jobs and draft-PR evidence above.

A narrow SSH command to inspect only the `staging-app` container's name, image identity, status and start time returned `Operation not permitted` in the sandbox. Its escalation also did not execute because automatic approval review could not complete due to model capacity. This was a review-service failure, not a finding that the read-only action was unsafe. The same bounded SSH approach was subsequently retried successfully, yielding only the container identity and aggregate counts listed above.

The web retrieval tool could not access public `/up`, `/api/places`, or `/api/events`; this is a tool-access result, not evidence that those endpoints are down. No fresh public-health or authenticated payload claim is made.

## Evidence locations

- This checkout: `docs/places/production-release/2026-09-30-staging-source-hold/REPORT.md`, `staging-source-hold-verify-summary.json`, `staging-source-hold-deployed-after-consumers-summary.json`, and `staging-source-hold-deployed-football-summary.json`.
- Investigation checkout: `/Users/anar/.codex/worktrees/places-staging-readiness/expadu-app/docs/places/staging-readiness/REPORT.md`, `RELEASE.md`, `STATUS.json`, `consumers-summary.json`, and `post-hold-facilities-summary.json`.
- Investigation checkout: `/Users/anar/.codex/worktrees/places-staging-readiness/expadu-app/docs/places/local-catalogue/2026-10-01/snapshot-summary.json`, `snapshot-verification.json`, and `native-verification.json`.

The staging inspection read these dated reports and the bounded current aggregates above. The separate local-import work used the historical private snapshot without modifying staging.
