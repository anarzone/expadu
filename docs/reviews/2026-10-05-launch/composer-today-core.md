# Composer / Today independent final review — 5 October 2026

Scope: source review plus the actual local app at `http://127.0.0.1:8011`, against the accepted connected design decisions. This is a review of the implemented app, not the static prototype. Production deployment and catalogue readiness are separate gates.

## Findings and fixes

1. **Today displayed an operational acknowledgement as permanent content.** Its continuation selected the last non-plan message, including “Your plan is saved… Undo is available.” It now uses the latest actual user request, labels it “Your last request”, and gives unfinished proposals priority. Status-only history produces no continuation. An empty proposal is described as plan changes, not zero activities; proposals from a previous day warn that their times have passed.
2. **Only first-visit help was closable.** Every continuation now has a dismiss control. Dismissal belongs to the specific request/proposal, survives refresh for the tab, does not alter the plan, and does not hide a changed proposal or a new request. Unrelated workspace revisions do not revive it. Dismissal data is account-scoped and stores no message/proposal text. The original welcome dismissal remains compatible. Keyboard focus returns to the agenda.
3. **A stalled Composer request could leave the workspace busy indefinitely.** The shared API helper now bounds the request and body read to 30 seconds, preserves caller cancellation and conflict status, rejects redirected/HTML acknowledgements, and clears the timer/listener. An unconfirmed write says its outcome is unknown and asks the person to refresh before retrying; it never claims the plan was definitely unchanged.
4. **Returning to Today did not refresh same-day plans changed elsewhere.** The workspace hook now coalesces focus/visibility signals into a background read. It retains the visible plan and unfinished form while reading, defers reads during mutations, ignores older revisions and cancelled/outdated responses, and clears read errors on recovery. Workspace state and asynchronous results are account-bound. Agenda is keyed to the account.
5. **Refreshing during an open edit could otherwise make its old values overwrite newer changes.** Agenda captures the edit's original workspace revision; the hook rejects a stale edit before any write, retains the person's unsaved fields, and explains how to review the current plan.
6. **Three old browser assertions no longer represented the agreed product.** Incoming URLs are reviewable context rather than authority to send, ask mode adds a proposal, and My Plans labels saved days “In your plan”. Assertions now exercise these current semantics rather than restoring obsolete behavior.
7. **A quiet mobile day put generic paperwork guidance first.** When there is no actual next-step card, optional Paperwork guidance now follows city actions, agenda, discovery and saved/unfinished content on mobile. Real next-step cards stay in essentials and urgent signals remain above the canvas. Desktop placement is unchanged. The empty discovery message now says “No matching events for today.”

## End-user flow reviewed

The dedicated Composer workspace keeps its input, selected date, plan and direct edit actions visible. A person can add a personal appointment, review the proposal, change its time, explicitly apply it, reload the saved day, start a new conversation and reopen that day from My Plans. Unsent text survives the appointment flow and incoming requests. Paperwork requests lead to reviewed guidance after Send; no legal answer is generated in these checks. Confirmed itinerary content does not claim a booking or checked availability.

Today keeps optional help subordinate to agenda/discovery. Closing help does not delete, approve or discard a plan. New content can be shown again without repeatedly restoring the same dismissed content.

## Verification

- Herd PHP 8.4.23; Node 22.23.1.
- Existing Composer PHP suite: **155 passed, 480 assertions**. The sandbox-only first attempt could not access Redis and made zero assertions; the approved local test run passed.
- Focused Composer API, entry-context and Today model tests: **22 passed**. API regressions cover acknowledged JSON, request timeout, uncertain writes, preserved cancellation and conflict handling. Parent changed the pre-existing ApiError parameter property to an explicit field so these tests also work with the CI strip-types runner.
- Composer browser checks: **14 passed** across desktop/mobile: 12 handoff/origin/time-picker cases and 2 persisted appointment/history/reload cases. The appointment cases passed after correcting the two obsolete labels noted above.
- Today continuation browser checks: **6 passed** across desktop/mobile, including context-specific dismissal, changed proposal recovery, status-only history and no plan writes.
- Today resume/edit regression on the final client + SSR build: **4 passed** across desktop/mobile. Covers one coalesced return read, retained unsaved fields, zero writes on a stale edit, failed-read recovery and refusal to replace a newer plan with an older response.
- Quiet-day content priority regression: **4 passed** across desktop/mobile on final build 73898 and its restarted SSR renderer. The pre-fix mobile test reproduced Paperwork before City help. Final checks verify city actions, agenda and discovery before generic guidance on a 390px phone, a useful action in the first viewport, one Paperwork section, actual next steps still in essentials, and the unchanged desktop placement. These cases use controlled read-only feed/workspace responses. Log: `/tmp/today-priority-green.log`.
- Full TypeScript, scoped ESLint/Prettier and whitespace checks passed before the final combined build.
- Final repository lint cleanup: `npm run lint:check` passes with zero errors or warnings. Node/CommonJS test environments are explicit; temporary prototypes, browser scratch work and review captures are excluded, while actual tests remain linted. Test formatting changes preserve behavior. All **82 frontend Node tests** passed again; Prettier checks across Browser/Frontend/Unit frontend tests and `git diff --check` also pass. Logs: `/tmp/expadu-launch-lint-final.log`, `/tmp/expadu-launch-lint-node.log`, `/tmp/expadu-launch-lint-prettier-final.log`.

All real plan writes used the designated synthetic E2E account. Today continuation/refresh scenarios mock only workspace responses in isolated browser contexts; they do not mutate the review account. No catalogue was seeded to make a review screen look populated.

## Visual evidence

- `composer-core-desktop.png`, `composer-core-phone.png`: actual local persisted appointment flow on the synthetic account.
- `today-continuation-desktop.png`, `today-continuation-phone.png`: actual page rendering with controlled request context. The local admin QA overlay is omitted from these two crops so the actual dismiss control is visible; the app controls and layout are unchanged.
- `today-quiet-desktop.png`, `today-quiet-phone.png`: final quiet-day state, visually reviewed after the priority fix. City actions lead on mobile; optional generic paperwork is last. Desktop retains the existing side-by-side arrangement. Local QA overlays remain visible in these captures.

## Remaining launch gate

The parent verified that the active local app database has no usable places/events/media catalogue. The browser recommendation/inline-swap case requiring `E2EComposerCatalogueSeeder` was therefore **not run**; this review does not claim it passed with production data. Source-backed discovery and recommendation coverage must be verified against the intended deployed data and providers. Do not substitute synthetic content or mark the whole app launch-ready from these interaction tests alone.
