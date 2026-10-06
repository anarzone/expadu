# Composer concept implementation plan

**Goal:** Build the owner-approved first mockup slice: adaptive Composer and a connected My Plans page.

**Spec:** `prototype/dev/design/connected/composer-plans-review-2026-09-17.md`, approved for changes in this conversation.

**Architecture:** Separate `composer-studio` and `my-plans` preview routes share an isolated local model. Shared existing fonts, icons and header styling preserve Expadu's visual foundation. No production code or existing preview state is changed.

**Stack:** Plain HTML, CSS and JavaScript; Node 22 built-in tests.

## Work and verification

- [x] Create `composer-studio/model.js` and `tests/model.test.cjs`. Model contracts: `fresh()`; `bookmark(state,id)`; `propose(state,ids,date,baseId)`; `confirm(state)`; `conflicts(proposal)`; `respond(text,state)`. Verify bookmarks never schedule, confirmation clones, revisions preserve confirmed state until applied, duplicates do not duplicate activities, and overlapping activities block confirmation.
- [x] Create `composer-studio/index.html`, `app.js`, `style.css` and `my-plans/index.html`. Implement an empty invitation, question answer from plan state, captured discovery cards, editable proposal and My Plans day selector/Saved collection. Keep follow-ups reachable and transitions explicit. Support date/time edits, remove/swap, confirm, reopen and apply revision, bookmark Undo, and recovery when local storage fails.
- [x] Verify representative flows in the browser at desktop and mobile widths: discovery → bookmark → proposal → confirmation → My Plans → revision; question before/after confirmation; unknown request and unavailable-data state. Check keyboard actions, page overflow, persistent state on reload and console errors.
- [x] Record scope and limitations in a concept README and open the new Composer preview for owner review. Leave broader week planning, reminder delivery and live backend integration for subsequent checkpoints.

## Before final Composer design sign-off

- [ ] Remind the owner to revisit the deferred plan-item hover / press-and-hold quick preview, saved in `prototype/dev/design/composer-studio/README.md`. Keep this open until discussed; do not silently omit it or implement it during unrelated refinements.

## Visual foundation

Geist throughout; pale canvas #F6FAFB; cyan #05BADD; action orange #D9340A; ink #211D15; supportive green #E9EFE0. Readable central responses, photo-led discovery, a paper-like timeline only for planning, compact contextual input. Reuse the accepted header rather than introducing a new navigation design. Sample sources and uncertain availability remain visible.
