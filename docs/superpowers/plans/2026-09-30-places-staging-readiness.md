# EXP-69 current staging Places readiness implementation plan

> For the implementer: use superpowers:executing-plans for inline execution, test-driven-development for each behavioral change, and one final whole-branch review. The user has already authorized continuing implementation.

**Goal:** Port held-detail status, reviewed facility search and controlled startup/automation into the current staging branch without replacing its unrelated work.
**Spec:** docs/places/staging-readiness/SPEC.md
**Base:** 89289db9641bb75a563e74b44be9b4717bd61b22
**Source candidate:** 70d6581686114428c45d193682b697a11d242a14
**Architecture:** Shared recommendation eligibility remains authoritative. Controllers select eligibility context; PlaceResource exposes detail state; CandidateRepository admits explicitly selected fine facilities; PlaceFacts/ReviewPlaceFacts bind qualification to evidence history. Configuration gates scheduled writers and legacy seeding.
**Stack:** Herd PHP 8.4.23, Node 22.23.1, existing locked Laravel/Pest dependencies, a dedicated localhost-only PostgreSQL/PostGIS/vector test database. No dependency/version change.

## Global Constraints

- Use only the attached places-staging-readiness worktree. Preserve both the dirty primary/pilot trees and the clean pinned production candidate needed for recovery.
- Current human-provided project instructions govern over stale instructions copied from an older Git revision. Reuse the earlier Laravel rule-reader findings; use native tools and one required native final reviewer.
- Never boot the live staging application for research. Remote payload verification uses an isolated copy, an unsaved synthetic user, guarded SELECT-only staging access, faked side effects and no environment-file export.
- Tests use exp69_staging_readiness_tests_20260930 on localhost; no application database or real Redis keys. Set the Herd executable explicitly.
- No frontend modifications, migration, data qualification, media approval or deployment in this port. Do not overwrite source-withdrawal code that landed on current staging.
- Retain unknown access, fees, sports and hours. Qualification cannot convert descriptive names into broadly recommended destinations.
- Run focused behavioral tests first, the normal suite and commit hooks before a draft PR. No hook bypass.
- Existing remote recovery package, receipt and pinned helper remain untouched.
- Reference EXP-69 in the final commit line; tracker and existing Work Log pages retain their prior histories and In Progress state.
- Preserve public evidence with scoped counts and limitations, including corrected canonical soccer probe and fresh URL health not verified.

## Review Focus

1. Detail eligibility agrees with discovery/by-ID eligibility for held records, aliases and destination components; no retained record is deleted or casually described as closed.
2. Fine-category or mixed broad/fine requests do not leak other descriptive facilities into general discovery, and sport/price facts are not inferred from category.
3. A new access correction or its withdrawal cannot revive a stale positive activity qualification; combined access/activity writes must be atomic.
4. The port preserves current staging source-withdrawal behavior and unrelated design/bureaucracy code, despite the older production candidate branch.
5. Default automation/seeding gates actually pause the intended writers and preserve unrelated schedules. Assess the explicit operational consequence of pausing media health checks and any route that could recreate held places.

## Task 1: Retained detail availability

**Files:** Modify app/Http/Controllers/Api/PlacesController.php, app/Http/Resources/PlaceResource.php, tests/Feature/Places/PlacesApiTest.php.
**Interfaces:** Consumes Spot::recommendationEligible(true), DestinationGrouping::eligible(..., true) and PlaceFacts::attach/resolve. Produces a transient recommendation_available attribute only for detail resources, and detail recommendation_status string.

- [x] Run the existing Places/Composer/source-withdrawal baseline before editing tests.
  Command: python3 /tmp/exp69-run-staging-readiness-tests.py tests/Feature/Places/PlacesApiTest.php tests/Feature/Places/PlaceFactsTest.php tests/Feature/Places/PlaceSourceWithdrawalTest.php tests/Feature/Api/SpotSearchTest.php tests/Feature/Composer/ActivityDiscoveryTest.php
  Expected: existing tests pass before porting.
- [x] Port the six named detail tests from the source candidate first: retained unavailable details (three cases), eligible detail unknown hours, held schedule/fee suppression, and qualified facility availability. No implementation edits yet.
  Assertions: data.id equals fixture ID; unavailable for inactive/held/private, available for eligible; held open_now and price_text are null; record still exists.
- [x] Run the held/eligible/detail subset and observe RED caused by missing recommendation_status or unsupported current claims.
- [x] Implement the shared detail eligibility and conditional claim suppression:
  `$spot->recommendation_available = $eligibility->whereKey($spot->id)->exists();`
  `'recommendation_status' => $this->whenHas('recommendation_available', fn () => $this->recommendation_available ? 'available' : 'unavailable')`
  Keep raw opening-hours and place_facts for provenance; suppress only current display claims.
- [x] Run the whole PlacesApiTest file; Expected: all pass, existing list/media/location contracts preserved.

## Task 2: Explicit reviewed facility discovery and access-bound qualification

**Files:** Modify app/Enums/SpotCategory.php, app/Composer/CandidateRepository.php, app/Http/Controllers/Api/PlacesController.php, app/Http/Controllers/Api/SpotSearchController.php, app/Places/PlaceFacts.php, app/Places/ReviewPlaceFacts.php; tests/Feature/Composer/ActivityDiscoveryTest.php, tests/Feature/Api/SpotSearchTest.php, tests/Feature/Places/PlacesApiTest.php, tests/Feature/Places/PlaceFactsTest.php.
**Interfaces:** Consumes Task 1 shared detail eligibility; produces SpotCategory::isActivityFacility(): bool and access_correction_id integer within activity_discovery correction values. All consumers use the same existing PlaceFacts eligibility policy.

- [x] Port candidate behavior tests before application code: explicit playground/pitch/table_tennis with no invented sport/fee; mixed park+playground excludes picnic; current category-qualified map/Places discovery; later access unknown and withdrawal keep old qualification invalid; unknown-access qualification refuses with zero correction writes; combined access+activity is atomic.
  Update the prior general-discovery assertion to set both activities=[] and categories=[]; explicit pitch is now an intentional fine-category request.
- [x] Run those files and observe RED for fine-category retrieval, public-access review enforcement and qualification freshness.
- [x] Add isActivityFacility for playground, pitch, basketball, tennis, table_tennis, boules, dog_park, bbq, picnic, skatepark.
- [x] Admit reviewed descriptive facilities only when a sport or explicit fine facility selector is requested. For mixed selectors without a sport require is_recommendable=true OR category in requestedFacilities. General discovery behavior stays unchanged.
- [x] Bind activity qualification to max observation ID and max access-correction ID including revoked history. In ReviewPlaceFacts apply, require resolved access known/public/unconditional and bind after access writes so a combined review is valid.
- [x] Run the focused five-file task suite; Expected: all pass, unknown fees never become free, access changes remove candidates from Places/map/Composer and details state unavailable, failed combined writes leave the exact previous corrections.
- [x] Include PlaceSourceWithdrawalTest and CatalogueRecoveryTest; Expected: all current staging withdrawal/recovery behavior preserved.

## Task 3: Controlled startup and scheduled writes

**Files:** Create config/places.php and tests/Feature/Places/ProductionCatalogueGateTest.php; modify database/seeders/DatabaseSeeder.php and routes/console.php.
**Interfaces:** Independent of Tasks 1/2; produces places.automation_enabled and places.curated_seeding_enabled booleans, both default false. Existing command handlers and unrelated schedules remain unchanged.

- [x] Create the three candidate behavioral gate tests first. Expected assertions: managed spot not duplicated on default startup; explicit curated enablement dispatches SpotSeeder; all eight scheduled place/media writers switch false->true->false while api:health stays true.
- [x] Run gate tests and observe RED before configuration/controller changes.
- [x] Add config flags reading PLACES_AUTOMATION_ENABLED and PLACES_CURATED_SEEDING_ENABLED with default false; condition only the SpotSeeder dispatch in DatabaseSeeder.
- [x] Add when callbacks to the eight existing place/media scheduled writers using the automation flag, preserving recurrence/locking and every unrelated service.
- [x] Run the gate file and MediaRevalidationTest. Expected: dormant writers pass toggling checks; explicitly enabled schedules and existing rights/health behavior still pass.
- [x] Document that media revalidation is also paused by this group. Manual command execution remains possible and is not claimed to be blocked.

## Task 4: Staging evidence, review and draft release

**Files:** Add docs/places/staging-readiness/REPORT.md plus sanitized evidence summaries. Maintain the spec/plan and use only this plan's .superpowers workspace for scratch ledger.
**Interfaces:** Consumes Tasks 1-3 code and tests. Produces a scoped release proposal and draft PR against staging; no deployment.

- [ ] Format the changed PHP and check scoped diff; no unrelated application or design changes.
- [ ] Run the complete fast Pest suite using the dedicated local test DB, then retain exact counts. If a pre-existing unrelated failure occurs, reproduce it on the unmodified current staging base before attributing or changing unrelated code.
- [ ] Prepare a separate isolated remote code copy for this port without modifying the pinned recovery lab or live app. Verify all eligible shared contracts and retained held details with the existing guarded read-only consumer harness; use soccer for football probes.
  Expected: current denominator re-read, retained held status unavailable, zero staging/production writes, no raw rows/private files exported. Do not reuse old counts as current evidence.
- [ ] Commit the task changes with normal hooks and Refs EXP-69. Generate a whole-branch review package against 89289db9641bb75a563e74b44be9b4717bd61b22. Dispatch one fresh native reviewer using the Review Focus above.
- [ ] Re-grade every finding; one Critical/Important test-first fix pass, no repeat reviewer; record every Ruling and deferred Minor.
- [ ] Push only the private codex/EXP-69-staging-readiness branch; create and attach a draft PR with base staging. Check CI at that exact head. Do not merge or deploy.
- [ ] Update EXP-69/EXP-72 and their existing BookStack Work Log pages with exact results and remaining release/data/media limits; read back complete histories. Preserve In Progress and the original production draft PR.
- [ ] Preserve completed evidence and rulings in tracked docs, remove only this plan's scratch workspace after completion.
