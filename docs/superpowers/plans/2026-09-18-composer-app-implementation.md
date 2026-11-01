# Approved Composer → application

**Goal:** Bring the approved mobile-first Composer into the authenticated app, using real catalogue/planning APIs. No new LLM integration.

**Reference:** `docs/superpowers/specs/2026-09-18-composer-conversation.md` and `prototype/dev/design/composer-studio/`, including the final review refinements.

**Architecture:** An authenticated, revisioned workspace stores conversations separately from confirmed plans and an unfinished proposal. Server commands own plan validation, approval, cancellation and undo. Existing composition produces server-owned candidate snapshots; the UI never submits arbitrary confirmed plans. React components render the continuing conversation, active plan, contextual panels, preview and compact input. Existing place details, bookmarks and journey UI remain shared.

**Constraints:** Preserve unrelated work. No invented bureaucracy answers or media. Keep unknown feasibility explicit. Plans survive Start fresh and deleting conversation history. Reject stale cross-device changes. No bookings/payments or reminder delivery implied. Weekly planning remains deferred.

1. Add workspace migration/model, strict command request and transactional service. Cover authentication, isolation, revision conflicts, invalid edits, approval, cancellation/undo and history preservation with focused Pest tests.
2. Connect composition handoff, catalogue selection and Today to confirmed durable plans. Keep existing endpoints compatible.
3. Replace the old result page with the approved React conversation: inline loading, live plan, versions, manual edits, scrollable swap options, panels, history and approval controls.
4. Add compact time wheels, actual-item preview/full details, saved places, appointment form, recovery states and mobile keyboard/reduced-motion handling.
5. Verify focused backend regressions, TypeScript, lint/build and desktop/mobile browser flows. Leave the prototype intact and app ready for user testing.


## Implementation outcome

Implemented in the authenticated application at `/composer`; the approved prototype remains intact. Workspace storage and guarded server commands persist conversations, proposals, confirmed days and versions. The interface supports manual time edits, appointments, approval preferences, cancellation/undo, saved catalogue browsing, scrollable swaps, real item previews, full details, history and starting-point selection. Today opens the exact saved day, and hiding its summary preserves My Plans.

No new LLM integration was introduced. Free-form understanding remains limited to the existing parser and the explicit editing commands. Weekly planning, reminder delivery, bookings and automatic recalculation of travel after manual edits remain outside this implementation; uncertain feasibility stays visible. Reviewed paperwork cards pass the existing authoritative-content and source-policy gates.

Verification: focused Composer/Pest tests, TypeScript, targeted ESLint/Pint, production build and desktop/mobile browser checks. Browser coverage includes persisted approval, independent time wheels and a saved time edit, reload/deep links, Start fresh, My Plans, origin selection, recommendation handoff, inline swap, item preview and preserving unsent text. A typing race during Start fresh was fixed during verification.

The local database initially had no place catalogue. `E2EComposerCatalogueSeeder` supplies clearly labelled temporary fixtures for real-endpoint browser testing only; it is guarded to local/testing environments and is not part of DatabaseSeeder. Remove only records matching its exact DESCRIPTION constant after testing. Import genuine catalogue data before testing local recommendations as a user. The existing local `spot_enrichments` migration was applied because the current catalogue reader already depends on it.

Run the browser config separately per project when using one shared test account; running both projects rapidly can exhaust the normal app write quota. Do not loosen production rate limits for tests.
