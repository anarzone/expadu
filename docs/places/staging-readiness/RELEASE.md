# Staging release proposal — PR #62

This is a code-release proposal, not approval to deploy or qualify data.
Target: staging only, preserving its current design, newer source-withdrawal behavior and separately committed source-null hold. Production PR #61 remains separate and draft.

## Before a release decision

- Require Lint, Test and Browser Tests to pass for the exact PR head. The first run's unchanged calendar-fixture failure was reproduced and corrected in CI configuration; do not bypass a failed check.
- Confirm the staging branch/image and the retained-data counts against the current target. Inspect deployment workflow status before diagnosing infrastructure.
- Record the existing image and configuration for rollback. This PR has no migrations or data operation. Application rollback must not run catalogue seed/import commands or undo the independent source-null data hold.
- Keep the pinned source-hold recovery package/helper/runtime and durable receipt intact. A code rollback is not a data recovery.

## Proposed configuration and photo maintenance

Keep `PLACES_CURATED_SEEDING_ENABLED=false` and `PLACES_AUTOMATION_ENABLED=false` for the controlled first release. Defaults are false, but confirm effective cached configuration on the running app.

The automation flag pauses eight catalogue/photo schedules, including `media:revalidate`. It does not block explicit commands or other services. Before accepting the release, assign and verify the photo-health maintenance procedure rather than leave freshness silently paused.

The existing `media:revalidate --limit=200` command queues due attached assets; its success is not proof that remote checks finished. A specifically authorized health-check run must verify queue completion, validation timestamps/outcomes, stale-input protection and the resulting publication-policy count. Repeat bounded due checks as needed; maintain an explicit refresh cadence while general automation stays disabled. This proposal does not install a new cron job or run this data-writing command.

Health validation changes health evidence, not rights approval. Stadt Köln exclusions, permitted licence rules and the approved-rights/active-health selector remain. Do not interpret a healthy URL as permission to use its image.

## After an approved staging merge/deployment

Use the existing deployment workflow; its image, application-test and browser-test gates remain mandatory. Check the running image/version and health endpoint after completion.

Use an authenticated dedicated QA account for API acceptance without exporting real users or private rows. Verify the actual Places list, retained held details (unavailable and no current price/open claim), canonical saved aliases and shared Composer facts. Check explicit facility and broad discovery behavior with controlled reviewed fixtures. A public health response or anonymous 401 is not authenticated payload acceptance.

Recount stored records, eligible place/component rows, general destinations, filtered list rows and policy-publishable hero associations separately. The current evidence is 11,802 stored, 4,235 eligible rows, 4,224 general destinations, 4,139 list total and 119 hero associations (2.817%); these are dated evidence, not permanent assertions. Record any legitimate difference and its source.

## Following data work

All 90 pending facilities passed post-hold previews, but remain unqualified. Refresh their source evidence, prepare an exact-target apply/recovery package, and rehearse combined operation/recovery sequencing before requesting a distinct live apply decision. All fees remain unknown, so approval must not turn them into free recommendations.

The 21 changed legacy source projections, 469 unknown-access facilities, useful public/free football evidence, licensed media growth, frontend unavailable-state presentation and independent production qualification remain open. No 75% photo-coverage or full-production-ready claim is made.

Release approval should name PR #62, staging, the two configuration flags and any authorized bounded photo-health run. It does not authorize production changes, unreviewed imports, source-null reactivation or the 90-facility apply.
