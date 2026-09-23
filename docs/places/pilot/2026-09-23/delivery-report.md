# EXP-72 delivery continuation — 23 September 2026

This change repairs concrete catalogue blockers. It does not complete the citywide free-photo coverage objective or publish a new catalogue.

## Changes verified locally

- The same Innenstadt courts API request fell from 17,645 ms to 1,125 ms (about 16× faster). Latest observations and active access corrections are materialized once per place instead of repeatedly recomputed. Original and replacement queries return identical data and the same 57 records. Private/conditional access and contradictory corrections still fail closed.
- Completed the previously stopped grouping rehearsal: ten reviewed facilities across seven destinations, 44 paginated list requests per snapshot, correct general-versus-activity behavior, unchanged coordinates, replay, independent override and complete transaction rollback. No memberships were committed to staging.
- Municipal-origin place media is screened using provider, author, original credit and source hosts, including Commons redistribution. Independent photos depicting municipal venues remain eligible. New evidence can demote a previously approved asset; publication also screens known origin evidence.
- OSM translated-name extraction now excludes etymology IDs, pronunciation and signed-name metadata. Six invalid Q-code aliases were removed from the newly prepared staging input. Historical local observations are preserved; this is not a silent history rewrite.

## Validation and review

Existing facts/grouping regressions: 54 tests / 327 assertions passed before and after the query change. Municipal/photo regressions: 28 tests / 116 assertions passed after five intentionally failing municipal cases. Alias regression failed before the fix and then 4 importer tests / 9 assertions passed. Full PHP suite before the final review fix: 1,629 passed / 6,815 assertions, one skipped. Final hook/suite results are recorded in the execution ledger.

Independent review found one important same-instance cache issue: a refreshed asset could remain loaded on its owning place. A regression reproduced it; capture now invalidates the owner's loaded media relations. No other Critical or Important findings. The final regression result is recorded in the ledger.

## Fresh live state and remaining work

Staging is still at f5a85503 with a successful deployment workflow. The read-only live snapshot contains 8,472 rows (4,116 OSM and 4,356 source-null legacy rows); these are not unique city destinations. There are zero `stadt-koeln` source rows and zero assets using that provider, which does not by itself exclude every redistributed municipal image.

The researched photo catalogue maps by exact source identity to 109 single live rows. Most research destinations have not been imported, and this number does not establish usable photo coverage. The frozen 100-place pilot still has 30 selected images only in its disposable rehearsal; 70 remain held. A 100-observation/30-photo/10-membership staging input is prepared with corrected aliases. Staging application, API acceptance and wider reviewed source import remain unfinished.

Ruling: keep the existing frozen sample and historical evidence rather than restart or silently replace the denominator. Reuse the existing ledger instead of generating a second plan. This preserves comparability; unresolved sample gaps remain visible.

Review boundaries: live deployment/performance, historical missing provenance or contaminated aliases, citywide coverage and the future staging operator were not certified by code review. They remain separate acceptance work; no claim of full completion is made.
