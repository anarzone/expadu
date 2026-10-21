# Catalogue compatibility audit — 5 October 2026

Initial read-only source audit of the current main workspace and `/Users/anar/.codex/worktrees/places-staging-readiness/expadu-app` (`codex/EXP-69-staging-readiness`). The initial audit performed no source edits, application commands, database queries/tests, imports or deployment. The subsequent authorized integration and verification are recorded below. The audit itself did not establish current database schema or record equality.

## Finding

The 12,292-record candidate export is evidence of a qualified historical catalogue, not an import format for the current application. It contains rendered Places/Composer snapshots with IDs belonging to its original baseline; it omits held rows and the complete identity/review/observation/media history needed to reconstruct that baseline safely. Do not upsert its candidate IDs or convert its flattened Composer tags back into source tags.

The current UI can consume `place_facts`, routing points and recommendation status already (`resources/js/components/places/types.ts`, `place-evidence.ts`, `place-rich-detail.tsx`, and `resources/js/components/composer/types.ts`). The runtime backend cannot yet reproduce the qualified contract:

- Current `app/Enums/SpotCategory.php` defines 26 fine categories; prepared defines 108 (105 represented in the export). New source types cannot be hydrated through the current enum.
- Current `app/Places/PlaceFacts.php` is a tag-only value object constructed with tags. Prepared `PlaceFacts` is a database-backed resolver with `resolve`, `resolveMany`, `attach`, `project`, recommendation/access policy and revision methods. They are incompatible APIs.
- Current `Spot::recommendationEligible()` checks only active/recommendable flags. Prepared additionally applies canonical identity, evidence-aware access and explicit activity qualification. Current Composer's micro-facility OR-category selection can expose held facilities solely because their category was requested; it must be replaced by reviewed qualification.
- Current Places list has a seven-category allowlist and legacy containment grouping. Prepared supports the expanded categories, prevents independent business branches being clustered away, and uses reviewed destination membership.
- Current `CandidateRepository::byIds` has newer event selection behavior that is absent in the prepared branch. Current `TodayPlanStore` uses durable Composer workspaces; prepared is an older cache-only implementation.

## Smallest coherent compatibility slices

These are dependency slices, not instructions to copy the entire branch. Apply narrow changes into the current files, preserving current behavior and tests.

### Identity and factual catalogue foundation

Bring in prepared classes which do not currently exist:

- `app/Places/PlaceIdentity.php`
- `app/Places/PlaceObservationHistory.php`
- `app/Places/PlaceFactRevision.php`
- `app/Places/PlaceCapabilities.php`
- `app/Places/DestinationGrouping.php`
- `app/Places/RecordPlaceObservation.php`
- `app/Places/ReviewPlaceFacts.php`
- `app/Models/PlaceFactObservation.php`
- `app/Models/PlaceFactCorrection.php`

Merge prepared `app/Places/PlaceFacts.php`, `app/Enums/SpotCategory.php`, and `app/Models/Spot.php`. Preserve current Spot `enrichments`/`englishEnrichment` relations and source-change stale-marking hook, while adding canonical relationships/casts, route resolution, effective reviews, deletion protection and recommendation scopes. Prepared `Spot` does not contain those current enrichment features.

Required prepared schema migrations for this slice:

1. `database/migrations/2026_09_10_194756_add_canonical_identity_to_spots.php` — nullable canonical FK and `place_reconciliations`.
2. `database/migrations/2026_09_10_211126_add_reviewed_destination_membership_to_spots.php` — reviewed destination FKs/evidence/date and `place_destination_reviews`.
3. `database/migrations/2026_09_17_184322_create_place_fact_observations_table.php` — source history including withdrawal/restoration fields, ingestion uniqueness and history indexes.
4. `database/migrations/2026_09_17_184324_create_place_fact_corrections_table.php` — reviewed corrections/revocations and active-review index.
5. `database/migrations/2026_09_17_184325_create_place_fact_revisions_table.php` — resolver/cache revision.
6. `database/migrations/2026_09_29_155011_create_place_catalogue_operations_table.php` — guarded apply/recovery journal; refuses rollback when populated.

Inspect the actual target migration state before running anything. Keep the current `spot_enrichments`, Composer workspace, event bookmark and saved journey migrations and rows. Do not replace previously run migrations.

### Places and connected consumers

Merge prepared logic into `app/Http/Controllers/Api/PlacesController.php` and `app/Http/Resources/PlaceResource.php`, keeping current eager-loaded English enrichment and summary/tip handling. The prepared resource exposes effective evidence and routing points, but does not contain the current `summary` field. Generic factual-sounding category tips should not substitute for missing source evidence.

Port identity/grouping/facts behavior into:

- `app/Models/SpotFeedback.php`, `app/Http/Controllers/Api/PlaceFeedbackController.php`, and `app/Http/Controllers/ReviewController.php` for existing feedback/reviews across aliases.
- `app/Http/Controllers/Api/SpotSearchController.php`, `app/Http/Controllers/Api/PlaceContextController.php`, `app/Services/NearbyPlaces.php`, and `app/Services/VeedelDirectory.php` for consistent discovery and counts.
- The places-query/cache sections of `app/Home/DiscoveryFeed.php` and canonical lookup in `app/Services/VenueResolver.php`. Preserve current event/home work; use the prepared identity/grouping/facts revisions in places cache keys and continue caching scalars.

The current Places screen has only seven static category filters (`resources/js/pages/places.tsx:47`). New records render via generic string category, fine label and emoji, but shops/services/health/etc. have no filter controls. Extend these options within the accepted UI, preferably using the server's category contract; do not copy prepared frontend files over the accepted design.

### Composer catalogue contract

Merge expanded fine/coarse category support and compound-term fixes into `HeuristicPromptParser.php`, `OpenAiCompatiblePromptParser.php`, `Constraints.php`, `Concerns/NormalisesConstraints.php`, and the validation section of `app/Http/Controllers/ComposerController.php`; add `app/Composer/PlaceSearchHints.php` and `config/composer.php`.

Merge the prepared places-only retrieval/resolution logic into `app/Composer/CandidateRepository.php`, including pre-cap hard filtering, effective facts, canonical IDs, reviewed facilities, destination groups and strict string vocabulary deduplication (`uniqueStrict`). Preserve current exact event-ID/occurrence selection and its reserved event budget. Preserve English enrichment for user-facing descriptions; the prepared repository uses raw resolved descriptions.

Merge Candidate evidence/grouping/distance and visit-hours methods into `app/Composer/Candidate.php`, with corresponding `FeasibilityFilter.php`, `PlanSlot.php`, `SlotFiller.php`, `Swapper.php`, `PlanNarrator.php`, and `AnthropicCandidateRanker.php` changes. Current ranker/filter use `sourceFacts`, whereas prepared uses `placeFacts`; either retain a compatibility projection or update all consumers coherently. Preserve current arrangement/restricted-access protections: prepared `matchesDiscovery` applies booking/access requirements specifically to activity requests, whereas current filter applies broader source-fact checks.

Do not replace `TodayPlanStore.php`, `WorkspaceStore.php`, `ComposerController.php`, or their tests wholesale. Add canonical normalization at their current plan boundaries while retaining durable plans, revision/approval rules, recovery, exact event selection and Today dismissal semantics. Prepared `TodayPlanStore` lacks the durable workspace behavior entirely.

### Media preservation

Keep all existing media rows, rights/health status, attachments, provenance and manual locks. A photo URL in the candidate export is not an approval record.

To reproduce the prepared policy-publishable association contract, merge `app/Media/PublishedMediaSelector.php` with the prepared `app/Models/MediaAttachment.php`, add `app/Media/MediaSourcePolicy.php` and `app/Models/MediaMatchReview.php`, and bring in `database/migrations/2026_09_17_235258_add_match_review_to_media_attachments.php`. Port the explicit origin exclusions in `config/media.php`. The prepared selector considers canonical/alias family attachments and requires accepted match evidence in addition to approved rights and active health for places. Existing attachments default to pending in the migration; transfer only already-supported match evidence after source-key reconciliation, never blanket-accept them to preserve a photo count.

Fresh media validation/recovery is a separate dependency slice (`ReviewMediaMatch`, `MediaAssetValidator`, `MediaValidationAttempt`, validation scheduling fields and attempt-table migrations, plus whichever recovery helper is used). It is not necessary to add acquisition automation to connect catalogue records. Do not copy recovery helpers without their exact model/migration dependencies. The October export explicitly claims only existing associations, not newly checked image health.

## Safe local import path

1. Preserve the current dirty workspace and take a recoverable current local database snapshot. Determine target schema and full source-key ownership, including inactive/held rows, aliases, parents, destination reviews, corrections, observations, media and user references. Catalogue-only old snapshots cannot replace a newer whole development database.
2. Integrate and verify the compatibility slices above on an owned local database clone before changing the app's active local data. Keep automatic source refresh/cleanup/photo acquisition off during qualification. Prepared `config/places.php` and the narrow guards in `routes/console.php` establish that boundary; current schedule and importer/prune code must not silently rewrite newly qualified data afterward.
3. Use retained native inputs under `storage/app/private/places-local/2026-10-01/`: `supported-package/records.jsonl` (prior two additions), `held-public-facilities/native-preparation.json` and source proof (600 facilities), `facility-qualification-package-private.json` (90 facilities), and `ten-thousand-catalogue/native-preparation.json` (7,365 additions, 752 still held). The latter is present and contains the full raw tags and observation payloads. Retain the earlier selector's additional 658 holds and identity-pair review evidence too.
4. Reconcile source keys against the new target baseline; allocate native IDs for additions and preserve existing target IDs and user references. Never assume export numeric IDs identify the same target row. Conflicting source ownership, changed coordinates/category/holds or missing reviewed baseline families must stop the affected records for review.
5. Reuse native operations in `docs/places/production-pack/apply-pack.php` (`applyPreparedPlaces`), `RecordPlaceObservation`, `ReviewPlaceFacts`, and the guarded `docs/places/local-catalogue/FacilityQualificationJournal.php` behind a new target-specific preview/application wrapper. `applyPreparedPlaces` requires a caller-owned transaction and exact existing-row fingerprints; it is not by itself a complete qualification gate. It sets active=true for supplied existing rows, so only independently requalified records may reach it. Keep held rows out.
6. Refresh proof where current qualification requires it, regenerate previews/fingerprints against the target, and record a target-specific journal receipt. `ten-thousand-catalogue/rehearse.php` cannot be used as a promotion command: it validates a frozen 484-file runtime, historical baseline and hard-coded local database context, rolls everything back, and rejects previous facility proof older than one day. The 1 October evidence is historical on 5 October. Do not fake dates or remove freshness/runtime guards. A historical snapshot view, if deliberately chosen, must retain those historical dates and must not masquerade as a fresh qualification.
7. Run an atomic apply and source-key replay on the owned clone, verify preservation and recovery, then apply the reviewed local package to the active local target with the same guards. Update facts-aware caches narrowly; do not flush unrelated user workspace/session state.

## Verification required before calling this connected

- Source-key ownership is unique; existing IDs and protected rows remain unchanged outside an explicit reviewed mutation set; held rows remain held. Verify aliases, components, 690 facility reviews and independent business branches, not just the final count.
- Verify every accepted row through `PlaceResource` and `CandidateRepository::byIds`, comparing source identity, effective name/category/map and routing point, facts, unknown fee/access behavior and approved media. Count is expected to differ if the current target has legitimate newer records or holds; explain differences by source key.
- Exercise Places list/detail/coarse and activity filters, Composer fine/coarse/nearby/strict-free requests, veterinary versus clinic and sports-shop versus playing-field parsing, `3` versus `3.0` and `01` versus `1` source text, split/overnight hours, stale review invalidation, alias saved selections, and destination deduplication.
- Preserve current regression coverage for `SpotEnrichmentTest`, `WorkspaceEventSelectionTest`, `ComposerWorkspaceTest`, Today workspace behavior, `PublishedSurfaceMediaTest`, and current Places/home tests. Bring in relevant prepared `PlaceFactsTest`, `PlacePracticalFactsTest`, `PlaceSourceWithdrawalTest`, `PlaceIdentityTest`, `DestinationGroupingTest`, `ActivityDiscoveryTest`, `AdditionalPlaceSourceProofTest`, `HeldFacilitySourceProofTest`, and recovery tests as compatible focused cases.
- Use a dedicated test database; do not run refreshing Pest suites against the active development data. Verify PHP/Node versions before project commands. Browser-check accepted mobile Places/Composer/Today flows and record target latency after bulk import; prepared full verification was explicitly not a production latency benchmark.

Source evidence: prepared `docs/places/ten-thousand-catalogue/2026-10-01/REPORT.md`, its README/rehearsal/native checks, the native apply and facility journal helpers, plus direct current/prepared file comparisons. No currentness or target-database equality is inferred from the historical report.

## Subsequent authorized foundation and Places integration

The foundation, reviewed membership and Places/connected-consumer slices above were integrated selectively on 5 October. Current `Spot` English enrichment relations and source-change invalidation remain; Places still returns the existing English summary/tip fields; Home changes are confined to its places pool and evidence-aware cache. `app/Places/SourceTagFacts.php` preserves the previous pure tag policy for Composer compatibility while `PlaceFacts` now resolves observations and reviews. Source withdrawal/reconciliation actions accompany the resolver. No main local migrations, data import, deployment, scheduler or worker were run by this agent.

The retained raw historical snapshot was inspected structurally: `storage/app/private/places-local/2026-10-01/catalogue.json` was exported read-only from staging at 2026-10-01T09:18:34Z. It contains 11,802 total spot rows (including held records and aliases), 7,810 observations, 798 reconciliations, 12 destination reviews, zero corrections, 229 media assets, 341 attachments, 86 veedels and 231 park areas. Media metadata includes sanitized actor fields. The 4,235 historical eligible candidates are a subset of these raw rows. The later verified candidate count is 4,235 + 2 destinations + 690 qualified facilities + 7,365 new destinations = 12,292; the later cohorts must not be mistaken for part of the historical staging baseline.

Verification receipts from the dedicated `testing` database and pure units:

- Expanded enum regression: 8 expected failures before port, then 11 tests / 80 assertions passed.
- Preserved pure source-tag policy: 22 tests / 32 assertions passed.
- Current recommendation gate reproduced an explicitly private row entering the old pool before integration.
- `PlaceEvidenceCompatibilityTest`, `PlaceDestinationCompatibilityTest`, existing `SpotEnrichmentTest`: 59 passed / 314 assertions. These prepared-derived cases cover observations, current evidence, review freshness, source restoration, aliases, grouping, canonical Home cache invalidation and enrichment preservation; operator-command cases outside this integration were not copied.
- Existing Places/nearby/Home/feedback/review batch initially passed 68 and failed 11. The failures were expected old contract fixtures: automatic containment (six), generic tip flag, known-paid text, unversioned cache key, and two media attachments without the newly required match/source evidence. Tests were retained and updated with explicit reviewed membership/match fixtures and truthful absent-tip/paid expectations.
- Revised Places/Home run: 61 passed, one media fixture missing source/attribution. The final missing source/attribution fixture was repaired and its focused check passed (1 test / 2 assertions). No failure is left unresolved from that batch. Nearby/feedback/review checks passed in the initial batch.
- `PlacePracticalFactsTest` is retained for shared Places/Composer verification after the parallel Composer slice is installed.

The root agent owns historical snapshot application and media integration. The Composer agent owns candidate/parser/plan behavior. This appendix does not claim those parallel tasks complete or claim current source proof for the later qualification cohorts.

## Independent import/media and saved-plan review

Reviewed root's fixed-checksum `HistoricalCatalogueSnapshot.php` and `import-historical-local.php` before local application. The 1,000,000 spot-ID offset consistently maps live identity, grouping, observation, reconciliation, park-area and attachment relationships. Raw historical JSON review snapshots remain in their original source-ID space, as stated in the receipt; zero corrections exist in this frozen baseline. Empty-catalogue guards, protected-record fingerprints, exact imported-row comparisons, rollback preview and transactional sequence restart are appropriate for this local historical seed. No source proof is refreshed by the seed.

One actionable issue was reported: unchecked journal/receipt writes could allow commit after a failed file write. Root corrected these to checked atomic writes before application. No remaining data-integrity blocker was identified in this fixed input/local-only path. Root owns execution, backup and private receipts; this review does not independently claim a fresh-source qualification.

The media review confirms that places need both published rights/health/source metadata and an accepted association with evidence, with configured excluded origins withheld across canonical families. Events deliberately retain the existing asset-only match behavior. No new media permission or blanket association approval was introduced by this compatibility agent.

The parallel `PlanCatalogue`/Workspace/Today review found no additional blocker: projected availability does not overwrite stored historical/undo state; canonical IDs are normalized on both sides of the unchanged-slot comparison; exact recurring-event IDs are retained; new or rescheduled items are checked at commit. The projection cache is transient and is only reused within a read.

## Accepted-interface compatibility follow-up

`SpotController` now supplies the fourteen filter options from `SpotCategory`, preserving the previous seven-option ordering at the start. The existing horizontally scrolling Places chips consume those options; there is no new panel or layout. Expanded labels also reach empty-state text and map/card metadata. The shared place detail now prioritizes the existing English enrichment summary over raw source descriptions. Composer plan cards and Today agenda items display the server's unavailable-item review message while retaining saved names and times. The shared slot type declares the optional status/message contract.

The new server-prop regression in `PlacesApiTest` was observed failing for the absent `categories` prop, then passing (1 test / 18 assertions). Focused ESLint and Pint checks passed. A TypeScript check caught the map metadata callback's new parameter and that caller was fixed. Final type/format results are reported separately to root.

`tests/Browser/catalogue-compatibility.spec.ts` adds desktop/mobile scenarios for new filter selection and retained unavailable-slot notes on Today and Composer, with mocked read responses and no catalogue/user-plan mutations. The local E2E server on port 8080 was not running, so these scenarios were not executed here; root owns the final build and actual-app browser verification. The live local port 8011 was not used as a substitute E2E target.
