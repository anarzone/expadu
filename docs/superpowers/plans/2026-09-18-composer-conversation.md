# Composer Conversation Implementation Plan

**Goal:** Extend the approved prototype into a persistent conversational workspace with one live plan, history and explicit permissions.
**Architecture:** A small conversation model owns chapters, entries, summaries and immutable plan versions. The existing domain model remains authoritative for plan validation. The view renders a persistent feed and updates its live plan component without rebuilding earlier exchanges.
**Tech Stack:** Plain HTML/CSS/JavaScript, Node 22 built-in tests, browser local storage.
**Spec:** `docs/superpowers/specs/2026-09-18-composer-conversation.md` (approved direction in this task).

## Constraints

Only the prototype and local design documents change. Preserve existing saved ideas and plans. No production AI, legal guidance, deployment or external actions. Preserve unrelated changes. Keep existing time wheels, replacement panel and shared loaders.

## Tasks

- [x] Conversation model (`conversation.js`, `tests/conversation.test.cjs`): `ensure`, `current`, `append`, `updateEntry`, `startFresh`, `activatePlan`, `recordPlan`, `setApproval`. State lives in `state.conversation`; immutable versions reference dates and source entries. Verify migration, message ordering, snapshot immutability, fresh-section preservation and permission validation.
- [x] Conversation view (`conversation-view.js`, `conversation.css`, existing `app.js`, both HTML entry points): persistent user/assistant feed; inline pending/cancelled/failed states; compact history panel; one live plan and read-only historical versions. Preserve active input/scroll. Verify first message, follow-up and refresh.
- [x] Shared operations and approvals (`app.js`, domain model tests): route chat/manual edits through existing proposal validation, record versions after mutations, default Apply, routine auto-apply with guarded Undo. Add contextual plan shortcut and permission menu. Verify confirmed/draft separation and stale Undo rejection.
- [x] Scope and topics: add a supported replacement request flow with inline alternatives and Browse more; explicit missing coverage and out-of-scope responses; bureaucracy handoff without generated advice. Preserve history on topic switches, expose draft resumption.
- [x] Responsive verification: run all focused tests and syntax/format checks; desktop and phone acceptance flow; keyboard, scroll, pending cancellation, history/fresh-section behaviour and persistence. Update README and report precise remaining production work.

Each task is completed against the acceptance flow in the spec. No commit or deployment is requested. Model work can run independently while the root implements view/integration; root reviews all resulting files before final verification.

## Verification outcome

Implemented in the existing prototype only. All 50 focused tests pass; modified JavaScript passes syntax checks and the changed frontend files pass formatting. Browser verification covered 354/390 px phones and 1280 px desktop, persistent conversations, manual/chat overlap rejection, explicit confirmation, routine auto-apply, Undo, replacement and inactive suggestions, History/Start fresh, reload, panel draft preservation and corrected header-aware plan scrolling. No console warnings/errors appeared in the final browser check. Temporary test tabs and viewport overrides were cleaned up.

Real AI, authoritative retrieval, server-side approval enforcement and semantic memory remain production follow-up work. The source provides a bounded, deterministic context projection; the scripted responder does not claim to use semantic retrieval. Direct file navigation is covered by URL tests; interactive browser verification used the local HTTP preview.
