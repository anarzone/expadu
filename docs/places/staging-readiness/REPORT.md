# EXP-69 current staging Places readiness proposal

Date: 2026-09-30. Based on staging commit `89289db9641bb75a563e74b44be9b4717bd61b22`.
Status: implementation, one independent review/fix pass and final local/read-only staging verification complete; draft publication and exact-head CI pending. No code deployment.

This scoped backend port fixes retained-detail availability, explicit reviewed facility discovery, access-bound qualification and uncontrolled legacy seeding/scheduled catalogue writes. It preserves the current staging design and newer source-withdrawal behavior. It does not replace staging with the older production candidate.

## Behavior changed

- Held detail links retain their identity and facts while reporting `recommendation_status=unavailable`. They no longer present retained hours/fees as current open/closed or free claims.
- Explicit facility selectors reach reviewed descriptive places in Places, map search and Composer. General discovery still excludes those descriptive records. Mixed park/playground selection admits the requested playground, not other hidden facilities.
- Positive activity reviews require current known unconditional public access and bind to source/access review history. A later access review or its withdrawal invalidates the old qualification; combined reviews roll back if access is not proven.
- Legacy curated startup seeds and eight scheduled place/media writers default to disabled until explicitly enabled. Existing command handlers, other services and media rights/health publication checks remain intact.

No new photos, source observations, live facility approvals or source-null data changes are included in this code proposal. The source-null hold was applied separately under explicit staging approval.

## Verification

Test-first failures reproduced six missing detail-status cases, eleven category/access cases, and two startup/schedule failures before fixes. Focused verification passed: 53 Places API tests; 153 API/facts/map/Composer/withdrawal/recovery tests; 13 startup/scheduling/media revalidation tests. The complete fast suite passed **1,754 tests**, with **2 skipped**, **7,349 assertions**, using PHP 8.4.23 and Node 22.23.1 on dedicated local test databases. Fifteen changed PHP files pass Pint; lockfiles and frontend source are unchanged.

At **21:58:43 UTC**, the port's isolated API kernel read actual staging data under a guarded, repeatable, READ ONLY snapshot. It booted against the separate empty lab database, used an unsaved synthetic user, faked outbound side effects and refused six attempts to leave read-only/catalogue scope. Query logging was disabled. No real user records or raw rows were exported.

| Check | Fresh result |
|---|---:|
| Eligible place/component contracts shared by Places and Composer | 4,235 / 4,235 |
| General eligible destinations | 4,224 |
| Actual Places list total under its display/category filters | 4,139 |
| Source-null records entering eligibility | 0 |
| Retained source-null alias relationships | 798 |
| Eligible alias API and Composer references checked | 791 / 791 |
| Unlinked held records excluded from Composer by ID | 3,558 / 3,558 |
| Retained held-detail links checked | 12 / 12 |
| Held detail responses missing unavailable status | 0 |
| Policy-publishable hero associations | 119 / 4,224 (2.817%) |

Seven other retained aliases point to already ineligible canonical records; they are not claimed as usable recommendations. The Places list total has its own filters and is not the total stored-row or general-eligibility count.

All **11,802 stored records remain retained** after the separately committed source hold. That stored count is not a claim that all 11,802 are ready for recommendation or production. Shared eligible facts remain: access 123 public / 4,112 unknown; fee 49 free / 37 paid / 4,149 unknown. Unknown values were preserved.

At 21:58:44 UTC the canonical `soccer`/`pitch` discovery request within 3 km of the labeled sample Cologne-centre origin returned **0 free candidates and 0 without the budget filter**. It does not establish a citywide absence. The earlier unsupported `football`-label probe was corrected in the source-hold evidence; it is not the basis for this result.

The runtime fingerprint is `b7bbb275a67111744a9cc785005f89b06a5757d272628a05bbfd0050fa71fb0c`, covering 570 application/config/route/migration/lock/bootstrap files. A separate isolated public-source copy was used; the running image and pinned recovery lab were preserved. See the aggregate JSON summaries and `verify-evidence.py`.

## Independent review

One fresh read-only reviewer found a real upgrade defect: missing access-history bindings in old facility qualifications were treated as zero. Seven failing regressions reproduced the leak across Places, map and Composer before the single fix pass. Those old approvals now require fresh review. The related suite passed160tests/805assertions, and the full normal fix-commit hooks passed1,754tests/2skips/7,349assertions. There were no Critical or deferred Minor findings. See review-result.md and execution-decisions.md for the review boundary and all eight rulings.

## Release boundary and remaining work

This verifies the proposed source against staging data. It does **not** verify authenticated payloads through the public deployed HTTP server, deploy this code, render the new field in the frontend, freshly revalidate photo URLs, freshly reverify every original source, or qualify production.

`PLACES_AUTOMATION_ENABLED=false` pauses the catalogue/boundary/photo schedules **and media health revalidation**. A release must deliberately choose this setting and its validation/refresh operating procedure; long-term disabled automation stops remote-health freshness checks. `PLACES_CURATED_SEEDING_ENABLED=false` prevents legacy name-only seeds. Manual commands are still available; these controls do not claim to block every possible writer.

At21:58:50UTC, all90 pending source-reviewed facilities passed new post-hold native previews, retained unavailable detail checks and Composer by-ID exclusions:74playgrounds,11table-tennis facilities,3basketball courts and2pitches. All90 preserve known unconditional public access and unknown fees. The source evidence was fetched at11:35:58UTC and remained within its24-hour window; this check did not refetch it. No qualification or new apply package was written. Exact target-package preparation and combined recovery verification remain before any apply. The 21 changed legacy source projections, 469 facilities with uncertain access, useful free/public football coverage and media growth remain open. The 75% photo objective remains unmet. Production needs an independent package, recovery/backup qualification, explicit release approval and acceptance checks.

EXP-69 and EXP-72 remain In Progress. Only a private branch and draft PR against staging will be published; no merge or deployment is included.
