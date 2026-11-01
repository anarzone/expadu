# Composer and saved-plan catalogue integration — 5 October 2026

The accepted local app now uses the prepared evidence-backed place contracts while retaining its durable Composer workspace, exact event selection, approval, cancellation, undo and Today dismissal behavior. This is an uncommitted integration on base revision `c386427d877e2e962b7f0b5aa023280dbcbed880`; no deployment or remote data change was performed by this work.

## Implemented boundaries

- Parser and constraint handling support the expanded place categories, explicit activities and bounded nearby radius. Candidate retrieval uses the common `PlaceFacts`, canonical identity and reviewed destination grouping. Unknown fees remain unknown; explicit free/low limits are never silently relaxed. The existing tag-based booking/access safeguards and English enrichment projection remain in place.
- Candidate time checks retain complete split and overnight intervals. Scheduling requires a whole visit to fit. Exact selected events beyond the discovery pool retain their occurrence identity and actual start/end, including short events.
- Pins, swaps, saves, new manual selections and proposal approval recheck current availability. Actual selected intervals must satisfy the plan's category, family, budget, activity, access, radius and window requirements. A person may shorten a flexible place visit without being forced back to its default duration. Manual additions calculate distance from the saved plan's origin.
- The existing single-category generation policy still makes an activity-led itinerary with refuel/wind-down stops, and still returns activity-only browse options. The generated itinerary now records its effective category set explicitly. This bounded generation policy does not grant an unrelated manual category override or relax family, time, price, activity or distance requirements.
- `PlanCatalogue` decorates copies of saved plans. An unavailable saved item keeps its identity, label and original times with `recommendation_status: unavailable` and a review message. Stale cost, opening and explanation claims are suppressed. Raw accepted history is not rewritten or silently deleted. Unchanged retained records do not prevent unrelated personal edits; new or rescheduled unavailable selections cannot be approved.
- Canonical normalization applies to saved slots and manual commands, so an alias cannot create a duplicate selection and remains usable for removal. Today hides the same raw saved-plan hash it later checks, preserving the existing dismissal behavior.
- Home ignores unavailable outdoor stops when deriving a plan-at-risk weather recommendation. Today retains those stops in the agenda but suppresses their Directions context. Event reminder delivery was separately repaired by the events owner to revalidate held/rescheduled occurrences.

## Regression coverage and intentional fixture changes

Prepared coverage was appended to the existing Composer tests, preserving the accepted app's exact-event and durable-workspace tests. Initial missing-behavior checks reproduced the expected failures before implementation.

Three existing assumptions were updated deliberately:

1. A thin explicit-free search previously expected the app to relax the budget. It now expects no matches and the explicit no-verified-places notice. Paid and unknown-price candidates cannot satisfy a free request.
2. The generic activity fixture now proves an unreviewed generic facility stays excluded, then supplies source identity plus public soccer evidence and an actual activity-discovery review before expecting inclusion. Generic-name suppression was restored in retrieval; the safeguard was not deleted to make the test pass.
3. The durable workspace fixture previously used nonexistent `spot:1` and `event:2`. It now creates those real catalogue identities with source-compatible hours, an exact event interval and a controlled service-area polygon. This makes catalogue validation meaningful while preserving the approval/revision/history assertions. Dates use the real timezone offset for their fixture day.

Additional tests reproduced and then corrected: held data after proposal creation; preservation of accepted held history; alias deduplication/removal; saved family/category/window violations; custom shorter visits; generated-complement save/reload; unrelated manual category rejection; radius relative to the saved origin; unavailable Home weather suppression; and retained agenda records without a travel action.

The original `WorkspaceEventSelectionTest` was retained unchanged. The new frontend Today model test first failed with a travel context for an unavailable stop, then passed with all 10 model tests. The Home test first failed by generating a weather tile for an unavailable stop, then passed. The four saved-selection constraint regressions first returned 200 instead of 422, then passed; the shorter valid visit also passed. Generated-category and saved-origin regressions independently reproduced their missing behavior before correction.

## Native local catalogue evidence

`verify-local-native.php` and `local-native-bootstrap.php` provide a reusable local-only verification. They require the owned loopback `expadu` database, use a synthetic unsaved user, isolate cache/session state in memory, prevent outbound HTTP, fake jobs/mail/notifications, and enforce a repeatable-read, read-only transaction that is rolled back. They do not load real user rows or change any catalogue records. The immutable private snapshot checksum is verified without copying source rows into this report.

`local-native-summary.json` records the successful check at **2026-10-05 13:55:42 UTC**, using the actual clock and source file hashes. Its input remains the historical **2026-10-01 09:18:34 UTC** snapshot, with the recorded 1,000,000 place-ID offset:

| Evidence | Verified count |
| --- | ---: |
| Stored places | 11,802 |
| Shared Places/Composer eligible place contracts checked | 4,235 |
| General destinations | 4,224 |
| Filtered Places list | 4,139 |
| Canonical alias mappings | 798 |
| Canonical held records excluded from Composer | 6,769 |
| Source-null records, including retained aliases | 4,356 |
| Source-null canonical exclusions | 3,558 |
| Eligible records with unknown fees retained as unknown | 4,149 |
| General destinations with publishable hero media | 119 (2.817%) |
| Historical place media assets / attachments | 229 / 341 |
| Source boundaries | 86 |

The verifier also checked 12 canonicalized alias detail routes, 12 held detail routes, category list samples, resolved facts and routing coordinates for every eligible place, and policy-approved active media. The three bounded soccer searches produced zero currently feasible matches at the actual evaluation time; the checker did not invent availability or replay 1 October as today.

This evidence is local. It does not prove fresh source observation, fresh remote image URL health, live staging behavior, or every interactive Composer conversation. The root task performs staging and browser verification separately. Historical review timestamps, unknowns and holds remain unchanged.

## Final verification

Final combined run: **289 tests passed, 906 assertions, 99.21 seconds** across `tests/Feature/Composer`, `PlacePracticalFactsTest`, all `tests/Feature/Home`, and `SendOccurrenceRemindersTest`. This includes the six added saved-selection/origin cases beyond the earlier 216-test Composer/shared-facts batch. The first broad Home run exposed a photo fixture without accepted match evidence; the media owner corrected that fixture and the complete final run passed.

All 10 Today frontend model tests passed. Scoped Pint validation passed for all 28 owned PHP/config/test/verifier files, and scoped `git diff --check` passed. The root task owns the complete frontend build, browser validation and overall working-tree verification.

## Root browser follow-up: automatic visit time

The actual mobile browse-to-plan flow exposed a past-time default: adding Rheingarten to a fresh afternoon plan proposed 10:00–11:15. Four failing regressions also covered afternoon-only windows, short morning windows and a day with insufficient time remaining. `WorkspaceStore::availableTime()` now bounds gap search by the plan window, starts no earlier than the next minute for today, and rejects a visit that cannot fit without creating a draft. Explicit times and fixed event occurrences retain their existing path.

The complete Workspace suite passed **25 tests / 134 assertions** after the repair. Browser selection then proposed **16:15–17:30** on the same day. The proposal remained reviewable and was discarded after checking; the existing confirmed personal plan was unchanged. This final Workspace run overlaps the earlier 289-test batch and must not be added to it as unique coverage.
