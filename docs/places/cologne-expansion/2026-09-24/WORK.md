# Expanded Places delivery — EXP-70 / EXP-72

Owner requirement, 24 September 2026: include cafés and restaurants; prepare the largest practical clean Cologne catalogue; at least **75%** of unique eligible destinations must have a relevant, rights-approved, active photograph. Free sources only. No fixed 3–4k cap. Municipal-origin Stadt Köln data and photographs remain excluded, including redistributed copies.

This requirement supersedes earlier proposed 3,000-place/90% targets and completion based on a small pilot. It does not certify the provisional research inventory as a complete or deduplicated city census.

## Acceptance

- Coverage numerator: unique eligible destinations with at least one approved, active, subject-matched photo actually selected by the application.
- Denominator: all unique eligible destinations in the expanded catalogue, including places without photos. No shrinking categories or excluding missing-photo rows to pass.
- Threshold: `covered * 4 >= eligible * 3`, with a nonzero denominator. Report actual counts and date, not rounded percentages alone.
- Independently report food/drink, other categories and neighbourhoods. The owner specified 75% overall; category figures expose uneven coverage without inventing additional accepted thresholds.
- Provider image links, licensed but unreviewed files, map camera proximity, placeholders and generic/AI-generated pictures do not qualify.
- Source provenance, name, location role, access/currentness uncertainty, aliases and contained activities remain explicit. Independent businesses remain separate destinations.
- Check Places and Composer through APIs. Production deployment and sending outreach still require separate authorization.

## Ordered work

- [x] Add the owner's scope and 75% requirement to existing EXP-70 / EXP-72 task comments; keep both In Progress.
- [x] Reconcile all existing photo leads into a per-place queue with evidence and precise remaining checks. Generate the complete missing-photo queue, category totals and a food/drink breakdown. Preserve original source snapshots.
- [x] Remove the food/drink exclusion from Places browsing, add consistent filters, and verify API and Composer eligibility/grouping regressions locally. Committed as `d7f0e0eb` and deployed to staging through PR #55.
- [ ] Resolve candidate identities against the catalogue, including new destinations, existing aliases, independent businesses and contained facilities. No name-only or proximity-only merges.
- [ ] Complete per-photo rights, relevance and health checks; import approved decisions using the existing native workflows. A metadata audit is not final photo approval.
- [ ] Search remaining free sources against the uncovered destination list. Measure net additional unique venues and document access/rights limits. Stop repeating fully inventoried overlapping feeds.
- [x] Publish and deploy the tested code and finish the prepared ten-membership staging grouping rollout; verify legacy links, Composer mappings, coordinates and audit preservation.
- [ ] Apply further accepted catalogue/photo batches with preview, rollback and repeatability checks.
- [ ] Recompute staging coverage through the application selector and APIs for the full expanded denominator, including category/neighbourhood gaps, and verify at least 75%.
- [ ] Publish final evidence and obtain staging acceptance; do not close the expanded delivery while the threshold or quality checks fail.

## Current constraints

The saved September 23 research has 12,857 provisional groups and 1,122 groups with image leads; those leads are not approved photos. Full tourism feeds have already been inventoried and largely overlap the earlier tourism catalogue. The earlier staging count (34 photographed out of 3,287 eligible records) is a separate, narrower denominator and must not be compared as if it described the expanded inventory.

If the free-source evidence cannot support 75%, retain this requirement as failed and discuss a concrete acquisition route with the owner. Do not silently lower the target, introduce paid sources or send outreach. The owner authorized the requested public-code push and staging deployment on 24 September; PR #55 is merged, staging deployment succeeded, and the documented API/grouping verification passed. The separate detailed wiki-result disclosure question has not received a specific answer.

## Work log

- 2026-09-24, continuation: owner authorized publishing the tested commits and staging deployment. PR #55 merged after lint, PHP and browser CI passed. Staging merge revision `c87b2622454fcec65dac7ab8de76a2320414b0af`; deployment run `36027806631`. EXP-72 comment `dd01a761-63b0-4816-b807-e032318b13d6` records the merge and pending verification. Fresh private staging backup completed and all archive data parsed successfully; no fresh database restore rehearsal claimed.

- 2026-09-24: requirement comments saved on EXP-70 (`416bbaec-4a4f-4854-bb43-4dff59311820`) and EXP-72 (`efd9c4b8-9c3c-446e-902a-4a5aacafaf39`). No ticket closed.

- 2026-09-24: complete offline queue generated for 12,857 provisional groups, with 3,136 candidate records attached to exactly the 1,122 previously reconciled groups. All 11,735 missing-photo groups retained. Replay hashes match; no image approval or live change inferred. This completes queue preparation, not the per-photo review task.
- 2026-09-24: separate tourism library inspected: 9 food/drink images, mostly repeated views of a few venues; inspected Kebapland photo carries a tourism-specific licence. See `source-followup.md`; no coverage gain claimed.
- 2026-09-24: dining implementation committed locally as `d7f0e0eb` (12 files). Normal commit hooks passed the secret scan, formatting and fast PHP suite: 1,634 tests, 6,826 assertions. Frontend build and targeted lint/format checks passed. Independent review found the overlapping café/bakery refresh issue; a failing regression reproduced it and passed after the fix. Unrelated worktree reports remain uncommitted and untouched by this commit. No push, deployment or live import performed.

- 2026-09-24 final delivery checkpoint: staging deployment verified; ten reviewed facility memberships applied; 791 legacy links, 784 eligible Composer identities and all ten facility mappings verified. Private backup restore passed. Current selector coverage is 34/3,277 (1.0375%), with zero new photo approvals. Earlier pending-deployment/restore statements above are historical checkpoints superseded by this entry.
- 2026-09-24: fresh two-category dining rehearsal passed: 1,689 proposed creates, 317 updates, five retirements, stable replay and exact rollback. Import remains held; see `food-source-snapshot/identity-review/decisions.json`. No newly imported restaurants claimed.
- 2026-09-24: 22 direction-ranked Mapillary photo candidates were inspected and withheld for venue relevance; Geograph yielded a 1,062-image search count, not unique licensed destination coverage. The 75% requirement remains unmet. Bulk data acquisition alone has no demonstrated route to filling the photo gap; venue/photographer contributions remain a proposed free acquisition route, with no outreach authorized or sent.

- Final task evidence updates: EXP-70 comment `4c3b8674-2700-45d5-b963-a54659ec1a62` records the unmet photo requirement and source limits; EXP-72 received the successful dining rehearsal and unresolved identity/currentness findings. Neither ticket was closed.

- 2026-09-24 resumed delivery: 45 visually reviewed, rights-evidenced photos committed on staging through native capture, health validation, match review and publication selection. All 45 selected-photo/attribution/licence/source fields passed actual Laravel HTTP-kernel route and middleware checks (synthetic nonpersisted authenticated reader, not public-network authentication). Evidence: `photo-delivery/` and its `staging-evidence/` copy. Fresh private pre-batch database archive parsed successfully; SHA256 `1bb46c7819dac7ce83bb4f738e0b8c2fe8bc2e87099169eafdc602c54adc18e7`.
- 2026-09-24 at 19:12 UTC: two exact legacy duplicates reconciled with frozen previews and exact rollback verification: Stadtgarten 183→4910 and Wallraf-Richartz-Museum 1557→8090. All 45 canonical API responses and both legacy URLs pass. Selected photo coverage is now **79/3,275 (2.4122%)**, a gain of 45 unique photographed destinations from the preceding 34. The intermediate 81/3,277 included these two duplicates. Broader catalogue remains unimported and the 75% requirement remains failed.
- 2026-09-24: fixed Commons metadata-only health approval and stale checksum reuse; five regressions reproduced before their fixes. 105 focused media/photo tests pass (482 assertions); normal commit hooks pass 1,638 tests (6,856 assertions, one skipped). Commit `53e3221a`; staging PR #56 created and attached, deployment pending. Existing validated bytes survive unchanged-URL metadata refreshes and infrastructure failures; changed URLs clear old health evidence. No production change or outreach.

- 2026-09-24 at 19:26 UTC: audited all 79 currently selected photos. Seven older Commons assets still carried the original-file SHA-1 left by metadata-based health updates. Native validation of all seven actual files succeeded and replaced those stale checksums with SHA-256. The repeated full selector audit finds **79 unique selected assets, zero missing byte-evidence records, zero selected excluded-origin assets**. This does not increase the coverage numerator.
- 2026-09-24 source follow-up: separate panorama evaluation found 3,419 provisional groups near a qualifying panorama, including 1,096 dining groups. Of 12 deterministic sample images inspected, five contain identifiable storefronts. These remain panorama candidates requiring final matching/presentation and are not counted as published photos. See `mapillary-panorama/REPORT.md`. Fresh fast-food (936 source elements) and bar (206) queries succeeded; bakery requests still failed. No new dining import applied.
- 2026-09-24: PR #56 merged after all required checks passed. Staging merge `5039a4cb1c8168434b9035b7aa87e917a7fa6f11`; deployment workflow `36047572552` is running. Postdeployment verification remains pending.

- 2026-09-24 at 19:30 UTC: deployment workflow `36047572552` succeeded. Running staging revision equals `5039a4cb1c8168434b9035b7aa87e917a7fa6f11`. Frozen 45-photo verification, 45 canonical API responses, two legacy API links, and all 79 selected-asset health/origin checks passed on the deployed application. Current photo coverage remains **79/3,275 (2.4122%)**, with 2,378 additional photographed destinations needed even before expanding this denominator. Evidence is in `photo-delivery/REPORT.md` and `photo-delivery/staging-evidence/`. Expanded delivery remains open.

- Four-category disposable rehearsal completed successfully: 2,580 proposed new rows, 317 updates, five source retirements, 2,599 new observations, stable identity/observation counts on replay, and exact compared-row rollback. Eleven similar-name nearby pairs require identity review; these checks do not prove all new rows are unique real-world destinations. No staging import applied. Evidence: `food-source-snapshot/four-category-rehearsal.json` (relative to the day directory).

- 2026-09-24 at 20:00 UTC: eight oversized Commons sources recovered using original-provider 1280px renditions, visually inspected, rights-evidenced, validated in a rollback rehearsal and committed on staging. Eight API responses pass. Final selected coverage **87/3,275 (2.6565%)**, representing 53 additional unique photographed destinations from the prior 34. All 87 selected assets have valid byte evidence; zero selected municipal-origin assets. The current imported denominator needs 2,370 further photographed destinations for 75%. Expanded acceptance remains failed. Evidence: `photo-delivery/thumbnail-followup/staging-evidence/`.
- Openverse anonymous API access recovered; all 133 bounded query pages acquired successfully, 2,000 unique index assets (744 Flickr, 1,256 Commons). Against the unchanged 12,857 provisional queue: 266 name-lead groups, 117 previously lacking leads, with explicit false-match examples and no approvals inferred. No city-wide completeness claim. See `openverse/REPORT.md`.

- Final 20:00 UTC evidence posted to EXP-70 (comment `49888788-79db-49b9-acfb-2d443616a94c`) and EXP-72; both remain In Progress. The 75% target was not lowered or claimed complete.
