# EXP-72 local pilot result — 18 September 2026

The local rehearsal improved photo selection from **3 to 30 of the same 100 destinations**, added source history for all 100, and fixed a serious detail-API slowdown for destinations without linked components. **Staging rollout is held. This is not a completed citywide catalogue or a completed EXP-72 pilot.**

## What changed

- Recorded 100 exact-source OpenStreetMap observations through the existing ingestion service: names, aliases, source point type, available access/fee/hours/contact details and explicit negative facts. Sources remain claims with timestamps; no invented verified entrances or business-currentness certification.
- Ran a ten-place canary, then the other 90. The canary changed exactly ten fact payloads and left the other 90 materially unchanged. Replaying the complete observation manifest added no duplicate observations.
- Applied 27 new/pending media operations and refreshed three existing accepted matches. Every selected asset passed its individual rights, health and subject gates. Preserved accepted review history and manual selection protections. Exact replay skipped all 30 without downloads, new attachments or review changes.
- Added exact validation hosts for tourism images and official Commons thumbnails. Code commit: `0273e467`. Focused tests: 13 passed / 53 assertions. Fast suite: 1,609 passed / 6,652 assertions, one skipped. Formatting and secret scan passed for the code change.
- Reviewed 199 contained facilities across 26 roots. Ten sourced relationships are proposed; none applied. Kept 81 source-null identity cases and uncertain/private/underground relationships unresolved.

## Measured before and after

All counts use the frozen 100 destinations; this sample is purposive and cannot estimate citywide coverage.

| Measure | Before | After local rehearsal |
|---|---:|---:|
| Correct-ID successful detail API responses | 100 | 100 |
| Selected usable photos | 3 | 30 |
| Source-backed websites exposed | 0 | 35 |
| Known source access | 16 | 17 |
| Known source fee | 10 | 11 |
| Known source opening hours | 16 | 15 |
| Verified entrances | 0 | 0 |
| General recommendation eligible | 98 | 98 |
| Detail API p95 | 214 ms | 21 ms after the performance fix |

Café Eiszeit's fresh OSM source no longer supplies opening hours, so the old hours were not retained as a current claim. Unknown entrances remain unknown. After observations and media were populated, API p95 rose to 3,864 ms (18.02× baseline). The cause was unnecessary compilation of the full component policy query for destinations with no linked components. A narrow membership check now skips that query only when no candidates exist. Repeating the same 100 reads gives p95 **21.044 ms**, mean **17.957 ms**, maximum **89.508 ms**, with all 100 response records identical apart from timing. Focused regression tests pass: 26 tests / 96 assertions. After the performance change, the full fast suite passes 1,611 tests / 6,658 assertions, with one skipped. Destinations with candidates retain every existing policy gate; their performance and coarse discovery remain separate unresolved checks. The earlier restored-database statistics issue was repaired before the baseline.

| Cohort | Destinations | Selected photos after |
|---|---:|---:|
| park | 20 | 13 |
| playground | 20 | 1 |
| culture | 20 | 12 |
| cafe | 20 | 4 |
| sports_centre | 20 | 0 |

Seventy destinations still have no selected photo. `media-holds.json` records each outcome, including no linked candidate, unsuitable subject, unresolved asset host or missing named-source group. Municipal archive photos are excluded; independently licensed tourism assets retain their individual author, source and licence. No provider-wide rights approval was introduced.

## Important examples

Café Reichard now has a terrace/building image in place of the unsuitable cake-box candidate. Römerpark uses an actual park view rather than the nearby university facade. Römergrab uses its signed entrance. Medizinhistorisches Museum, Trude-Herr-Park and Kolpingplatz use official smaller Commons images that satisfy the application size limit. DOMiD archive objects, Toyota's logo-only image, a construction-era Luftschiff-Platz image and an unrelated church linked to CRUX remain held.

The outdoor boules court linked spatially to an underground sports hall is not accepted as its component. Private playgrounds and uncertain tennis ownership remain held. Grouping ten sourced records alone would not resolve the 81 legacy identity cases; no complete duplicate fix is claimed.

## Environment and reversibility

All new data changes are confined to disposable local `expadu_pilot_20260918_a`, restored from a private complete staging backup. The primary dirty checkout and live staging/production data were preserved. Backup details and restore samples are in `backup-rehearsal.json` and `restore-planner-evidence.json`; the raw backup contains private data and stays outside this repository.

The first media apply stopped at an existing accepted match and rolled the whole transaction back. Its earlier tentative row statuses do not represent committed changes. The corrected successful run preserves accepted history; subsequent replay proves no effective changes. Full end-to-end rollback, Composer/reference tests, complete coarse discovery validation and live staging acceptance remain open.

## What remains

1. Validate performance for destinations with linked components and complete coarse discovery checks. The empty-component detail slowdown is fixed and the same 100-place comparison passes locally.
2. Complete grouping/legacy identity review, API discovery/Composer checks and reference/rollback verification.
3. Deploy the tested host support and apply a freshly previewed, backed-up staging manifest; then validate the actual running API.
4. Expand free-photo coverage beyond this pilot. Earlier research identified 12,857 provisional groups and 1,076 with linked photo candidates (8.37%); those figures are research candidates, not publication-ready city coverage. The target of most Cologne places with free photos remains unsolved. Wider feed access is still pending the previously requested registration/access details.

Automatic approval review rejected pushing the code to `github.com/anarzone/expadu` because authorization for that private-code destination was not established. The requested owner approval remains pending; no push or pull request has occurred.

Evidence: `manifest.json`, `baseline.json`, `canary-after.json`, `after.json` (facts only), `after-media.json`, `after-performance-fix.json`, `performance-diagnosis.md`, `observation-rehearsal-result.json`, timestamped media results, `component-validation-status.json`, and `validation-results.json`. API evidence uses the real Laravel HTTP kernel and middleware with a non-persisted synthetic user and no origin; it is not a public-network staging test. UI checks were omitted per owner instruction.
