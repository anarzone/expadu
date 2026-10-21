# Local catalogue integration and qualification — 5 October 2026

The actual app now consumes a source-backed local catalogue. The accepted page designs and existing private records were preserved. **No staging or production deployment or data promotion was performed.**

## Review

Open [the local review account](http://127.0.0.1:8011/dev/review), then [Places](http://127.0.0.1:8011/explore), [Events](http://127.0.0.1:8011/events), [Today](http://127.0.0.1:8011/dashboard) or [Composer](http://127.0.0.1:8011/composer). Port 8765 remains the design prototype; 8011 is the actual app.

## Connected data

| Data | Verified local outcome | Limits |
|---|---|---|
| Places | 11,802 stored records; 4,235 eligible place components, 4,224 general destinations and 4,139 filtered list results | Historical source snapshot captured 1 October, evaluated against current code on 5 October. This does not refresh every source fact. |
| Identity | All 798 aliases resolve; 6,769 held canonical records excluded, including 3,558 source-null canonicals | 4,356 total source-null rows include aliases. Stored rows must not be called usable recommendations. |
| Events | 276 captured official records; 196 visible occurrences, 80 held records, 48 venues | Ambiguous schedules, unsupported locations and other failed publication conditions stay hidden. |
| Photos | 119 general destinations have a policy-publishable hero (2.817%); no new media rights approvals | Remote photo URLs were not freshly health-checked. All 80 new municipal image candidates remain pending. |
| Practical facts | Places and Composer agree on all 4,235 eligible records | Fees remain unknown for 4,149; unknown hours/access are not converted into assurances. |

The raw places snapshot was imported as a complete identity/evidence graph, not as a flattened API export. A documented 1,000,000 ID offset prevents historical local plan IDs from accidentally pointing to unrelated imported places. Backup, atomic rollback rehearsal, exact mapped row hashes and protected-record hashes were checked. Detailed receipts and the recovery dump remain private.

The event feed was captured on 5 October at 13:15:15 UTC and replayed through the native importer, with real network requests and job delivery suppressed during replay. Source dates, exact times, overnight ranges and mixed-price wording were verified after commit. Existing private/place records were unchanged across 35 protected tables.

## Behavior repaired

- Places and Composer use the same identity, evidence, access, activity, budget and reviewed destination-membership contracts. English enrichment remains supported.
- Existing saved items keep their names/times/history when their catalogue evidence becomes unavailable. Today and Composer show a review note; unavailable items no longer drive weather or journey actions.
- New or rescheduled plan selections enforce the actual selected interval, family/category constraints, budget/access/activity/radius and the saved origin. Generated itinerary complements retain their explicit effective categories.
- Automatic placement of a flexible activity uses the remaining time today and the selected plan window. If no visit fits, the draft is not changed.
- Official event parsing handles explicit ranges without “Uhr”, overnight ends and unconditional free-entry statements; ambiguous sessions or conditional/mixed prices are not guessed.
- Reminder delivery rechecks the exact current public occurrence and suppresses hidden/rescheduled events.
- Place filters expose the existing expanded category contract in the compact row. Empty-category recovery clears the category with a clear “Show all places” action. Photo details expose the supplied source and licence links without invented rights metadata.
- Scheduled place/photo acquisition defaults off until the target workflows are qualified; deployment must deliberately choose `PLACES_AUTOMATION_ENABLED`.

## Evidence and checks

- [Native local audit](local-native-summary.json): read-only transaction, array cache, no real users loaded or written; exact Places/Composer contracts, aliases, holds and scenario totals.
- [Official events apply receipt](events-local-apply-summary.json), [event/media report](events-media.md).
- [Compatibility and foundation report](compatibility.md), [Composer integration report](composer.md), [independent review](final-review.md).
- [Current staging read-only audit](staging.md): running image and bounded current aggregates verified separately from the historical local data.
- Composer/shared facts/Home/reminder final batch: **289 tests, 906 assertions passed**. Additional importer/qualification and reminder/API batches: 51 and 35 tests passed (overlapping cases; do not sum these as unique coverage).
- Places/media/unit-place final batch: **229 tests, 893 assertions passed**. Final workspace regression after the browser-discovered automatic-time fix: **25 tests, 134 assertions passed**, including four new failure reproductions. These batches overlap earlier checks.
- Frontend behavior checks: **83 tests passed** across Node and CommonJS suites. Client/SSR build, TypeScript, ESLint, focused resource formatting and working-tree whitespace checks passed. TypeScript was rerun after build completion to avoid racing generated route files.
- Actual local browser: real Today events; next-week Events results; event source/detail → exact-date Composer context while the saved plan stays unchanged; Places counts, category empty state and recovery; 390×844 mobile layout and practical-fact labels; real-place discovery into a reviewable Composer draft, with a future start time and no change to confirmed plans; readable photo attribution with the supplied Commons source and CC BY-SA licence links. The new four-case Playwright file is prepared but was not executed; actual-app interaction was checked through the browser instead.

Screenshots: [desktop Places](places-desktop.jpg), [mobile Places](places-mobile.jpg), [mobile photo credits](place-credit-mobile.jpg), [corrected Composer proposal](composer-mobile.jpg).

## Remaining release gates

This pass verifies local integration with dated source evidence. It does not approve public launch. Current staging/production source qualification, the later facility/additional-source cohorts, fresh remote media health, real reminder/push delivery, physical-device behavior, human source/legal review and an authorized release candidate remain separate gates. The richer 12,292-item readiness export was not used to bypass expired qualification proof.

The historical snapshot still returns no qualifying football facility within the tested central/north/east 3 km scenarios, including a free-only request. Those coverage gaps stay explicit. No private source row, uncertain access, guessed fee, image permission or fictional service was introduced to fill them.
