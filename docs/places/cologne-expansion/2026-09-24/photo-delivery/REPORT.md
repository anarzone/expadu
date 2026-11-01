# Places delivery checkpoint — 24 September 2026

This is evidence of concrete staging delivery. The expanded Cologne catalogue and 75% photo requirement are not complete; EXP-70 and EXP-72 remain In Progress.

## What changed in this continuation

- Added or repaired selected, rights-approved photos for **53 additional unique destinations** on staging. Every file was visually reviewed, checked against source identity and rights evidence, passed native byte validation, and was selected by the application. No municipal-origin photos were approved.
- Reconciled two confirmed legacy duplicates: Stadtgarten (183 → 4910) and Wallraf-Richartz-Museum (1557 → 8090). Frozen identity previews and exact rollback rehearsals passed; existing links resolve to the canonical records and preserve their photos.
- Fixed a real Commons ingestion defect: metadata could mark a file healthy before its bytes were checked and overwrite an actual thumbnail checksum with the original file's SHA-1. New metadata stays pending until file validation. Verified unchanged files survive refreshes and worker failures; changed URLs discard stale evidence.
- Revalidated seven older selected Commons photos with incomplete byte evidence. All seven passed actual file checks. The latest selected-media audit now has 87 unique assets with byte evidence and zero selected excluded-origin assets.

## Measured outcome

The current imported catalogue contains **3,275 eligible destinations**, of which **87 have selected approved/active photographs (2.6565%)**. This is up from 34 photographed destinations before the batch. An intermediate 81/3,277 included the two duplicate records subsequently reconciled.

At least 2,457 photographed destinations would be needed for 75% of this current denominator: **2,370 additional destinations still lack qualifying photos to reach that threshold**. The expanded external inventory is larger and is not fully imported or identity-reviewed, so 3,275 is not a claim about the whole city's place count.

## Verification and publication

The original 45 canonical photo endpoints, eight additional canonical endpoints and both legacy endpoints passed real Laravel HTTP-kernel route and middleware checks on staging, using a synthetic, nonpersisted reader and a read-only transaction. URL, attribution, licence and source fields were checked. This is API verification, not a claim of public-network authenticated testing.

Five regressions were reproduced before their fixes. The focused media/photo suite passes 105 tests with 482 assertions. Required commit hooks passed 1,638 tests with 6,856 assertions, with one skipped test. Lint, application tests and browser tests all passed on [PR #56](https://github.com/anarzone/expadu/pull/56). The PR merged into staging as `5039a4cb1c8168434b9035b7aa87e917a7fa6f11`. [Deployment workflow 36047572552](https://github.com/anarzone/expadu/actions/runs/36047572552) succeeded, and the running staging container reports the exact merged revision. Postdeployment verification passed at 19:30 UTC: the frozen 45-photo batch, all 45 canonical endpoints and both legacy links, all 79 selected assets with valid byte evidence, and zero selected excluded-origin assets. Coverage remains 79/3,275.

No production deployment or outreach was performed. The existing support reply remains unsent.

## Source and catalogue work still open

- Existing source research has 12,857 provisional groups and image leads for 1,122. Neither is an approved coverage figure or a complete city census. The remaining source gap is much larger than this delivery batch.
- A newly evaluated panorama sample contains five identifiable storefronts among twelve inspected scenes. Nearby panoramas exist for 3,419 provisional groups, including 1,096 dining groups, but these require individual matching and suitable presentation and contribute zero published photos here. Details: `../mapillary-panorama/REPORT.md`.
- Fresh café, restaurant, fast-food and bar snapshots are acquired. Bakery requests still fail at the provider. The four-category disposable rehearsal passed: 2,580 proposed new rows, 317 updates, five retirements, 2,599 observations, stable replay and exact compared-row rollback. Eleven possible duplicate pairs need review; no new dining records have been applied on staging.
- Resolve the recorded identity/currentness holds, apply the accepted catalogue batches, continue per-photo review, and measure coverage again against the entire expanded eligible catalogue. None of those remaining requirements is marked complete.

The follow-up eight-photo batch passed a full native validation rollback rehearsal, was committed, and passed eight API responses and whole-catalogue checks at 19:57–20:00 UTC. The selected-media audit now reports 87 selected assets, each with actual-byte evidence, and zero municipal-origin assets. The original 19:30 receipts above remain preserved. Catalogue records and unrelated correction/review tables were unchanged by this photo batch.

The completed free Openverse search adds 2,000 distinct index records, checked against the 12,857 provisional research groups. It produced 266 groups with name leads, including 117 groups lacking earlier leads; these include false matches and are **not 266 approved photos or new covered places**. Original-source licence and subject checks are still required. See `../openverse/REPORT.md`.

## Evidence

- `thumbnail-followup/reviewed-batch.json`: eight-photo follow-up input, SHA256 `0bb0053961708529428fbe05a912449366304f552bc96c9dc5831ca08171b833`; matching native apply, rollback, API, coverage and selected-media audit receipts in `thumbnail-followup/staging-evidence/`.
- `reviewed-batch.json`: immutable 45-photo input, SHA256 `5bcf80ca5e40414d235148f45d73b824b26c4ae7dc8fd5f95659e707fdbe98a0`.
- `staging-evidence/api-verification-postdeploy.json`: 45 canonical responses and two legacy links on the deployed revision.
- `staging-evidence/identity-rehearse-result.json` and `identity-apply-result.json`: rollback and committed identity results.
- `staging-evidence/legacy-health-revalidation.jsonl`: seven legacy asset validations.
- `staging-evidence/selected-health-audit.json`: all selected photos, byte evidence and origin checks.
- `staging-evidence/photo-coverage.json`: full category and neighbourhood totals, with the unmet acceptance status explicit.
- A fresh private pre-photo-batch database backup was parsed successfully; its SHA256 is `1bb46c7819dac7ce83bb4f738e0b8c2fe8bc2e87099169eafdc602c54adc18e7`. No new full database restore is claimed for this continuation.
