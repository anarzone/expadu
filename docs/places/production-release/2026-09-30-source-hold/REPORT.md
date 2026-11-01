# Actual staging check and reversible provenance quarantine

This checkpoint adds a reviewed, tested way to exclude Places with no source while retaining every stored record and saved reference. It does not apply the exclusion to staging or production. The actual staging data has now been checked with candidate Places/Composer contracts, and the exact source-null baseline is prepared privately on the server.

## What the counts mean

| Actual staging finding | Count |
| --- | ---: |
| Stored rows, including aliases, context and held records | 11,802 |
| Current candidate-policy eligible place/component rows | 6,585 |
| Current general destinations | 6,574 |
| Eligible rows with no source | 2,350 |
| Complete source-null cohort retained by the proposed quarantine | 4,356 |
| Existing canonical aliases within that cohort | 798 |
| Projected source-backed general destinations after quarantine | 4,224 |
| Projected source-backed eligible rows, including 11 linked components | 4,235 |

These are staging counts, not a claim that 11,802 unique destinations are ready for production. The 4,224 count establishes a source boundary, not a fresh source check of every field. The old 4,075 figure is not used as the current inventory.

All 6,585 current eligible rows passed the candidate's shared name, location, practical-fact, people/Composer and publication-policy checks with zero contract issues. The native contracts still report 5,861 unknown-access values and 6,489 unknown fees; unknown is never presented as free. The candidate ran on the isolated application with a separate guarded read-only staging connection. This does not establish that the deployed staging version or external HTTP endpoints have passed acceptance.

## Actual-target facility and legacy mapping

All 90 reviewed public facilities match actual staging source identity, tags, category, coordinates and native review preview. The held detail/Composer exclusions were verified. They remain unqualified in staging; six share one ancestor, so live snapshots and operation sequencing require care.

For the 484 copied-catalogue legacy pairs, 463 already resolve to the exact source counterpart in staging and passed native detail and Composer saved-ID checks. The other 21 source projections differ, including names in all 21 and one unproved map point. Do not replay the copied 484-pair operation on staging or count these as new unique destinations. Those 21 are excluded from the proposed fresh-reference operation until refreshed and reviewed; the audit itself did not change their live flags.

## New implementation and verification

`SourceProvenanceHoldJournal.php` is an operator-only bounded protocol for the complete source-null cohort. It pins database, runtime, helper and baseline hashes; requires a caller-owned READ COMMITTED transaction; locks catalogue writers; and changes only active/recommendable flags plus time. Digests protect names, coordinates, source data, canonical pointers, facts, history and incoming relationships. It never queries user or media tables.

Recovery restores only original flags/time when the complete after-state still matches. Added unknown records, source binding or owned evidence/relationship drift refuse atomically. Durable receipts support new-instance apply/recovery replay; a recovered UUID cannot be reused. The journal and advancing facts revision remain. Known canonical aliases retain their detail and Composer resolution; unresolved held details remain readable as unavailable.

| Verification | Result |
| --- | --- |
| Missing-helper failing baseline observed | 15 failures before implementation |
| New real-database regressions | 19 passed, 92 assertions |
| Source/facility/legacy journal suites | 78 passed, 336 assertions |
| One independent review of this addition | 0 Critical, 0 Important, 1 deferred Minor |
| Isolated complete-cohort apply/recover | 4,680 records, both new-instance replays passed |
| Isolated unknown-source recommendations | 3,141 to 0 during trial |
| Isolated general destinations | 7,296 to 4,155 during trial; original set restored |
| Outer rollback | All 14 checked table aggregates restored |
| Exact staging package preparation | 4,356 records, 11 SELECTs, 6 guard refusals, READ ONLY |
| Normal commit hooks | 1,763 passed, 1 skipped, 7,385 assertions; secret scan and formatting passed |
| Exact-head CI and security | Lint, Test, Browser Tests and GitGuardian passed; image builds and deployments skipped |
| Reviewed code checkpoint | `70d6581686114428c45d193682b697a11d242a14` |
| Live writes / record deletion / new image approval | None |

The staging baseline has zero owned fact observations or corrections, 798 native reconciliation rows and one incoming venue relationship. Its package SHA-256 is `5993618124c8f226bce574dc8347385694366c8c4f1f34e15f8703b98a6c9e72`. The 2,561 source-null rows with active/recommendable flags and no canonical pointer are a physical flag count; the native eligibility policy admits only 2,350 of them. Do not confuse these numbers.

The rehearsal verified new-instance replay within an outer transaction, not a committed cross-process crash/restart or real writer-contention drill. All raw baseline/receipt data stays in the existing protected server directory; public exports contain aggregate counts and digests only. No private archive was made.

## Images and remaining work

The pre-quarantine general-destination baseline has 121 policy-publishable hero associations out of 6,574 destinations: 1.841%. This check did not freshly revalidate image URLs. Coverage after the proposed source quarantine has not been recomputed; do not divide the same numerator by 4,224. The 75% photo target is unresolved. Stadt Köln structured records and unapproved media remain excluded under the existing source/media rules; no blanket rights approval was made.

Remaining live-release gates: exact deployed-version acceptance, approved backup/writer pause and reversible actual-target sequencing, refreshed source evidence for facility/legacy operations, compatible stacked recovery snapshots, and explicit authorization for any live data operation or managed deployment. Keep PR61 draft and EXP-69/72 In Progress. Prepare a controlled source-null exclusion first; regenerate facility snapshots afterward and verify Places/Composer through the API after an authorized release. Production has not been changed by this work.

All execution rulings and the deferred setup-diagnostic wording issue are recorded in `source-hold-execution-decisions.md`; source-hold operator steps are in `../SOURCE-PROVENANCE-HOLD.md`. Setup output is withheld by the wrapper rather than durably retained despite its generic error message. This Minor affects troubleshooting only.


Final checkpoint: draft [PR61](https://github.com/anarzone/expadu/pull/61) is on code `70d6581686114428c45d193682b697a11d242a14`; [exact-head CI](https://github.com/anarzone/expadu/actions/runs/36759291993) passed. EXP-69/72 and both full BookStack histories were updated and read back; ticket HTML differs from the supplied markup only by the normal outer wrapper, safe-link attributes and void-element serialization. All old history remains. The staged proposal is prepared and verified; no live rollout is claimed.
