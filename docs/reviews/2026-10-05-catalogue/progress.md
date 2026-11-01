# Catalogue qualification execution record

Continuation of Task 11 in `docs/superpowers/plans/2026-10-05-page-by-page-launch-plan.md`, authorized by the owner's “go ahead then”.

## Decisions and boundaries

- Preserve the accepted UI and the current dirty working tree. Selectively integrate factual catalogue support; never replace the checkout with the older readiness branch.
- Connect a historical, source-backed catalogue locally with its original observation dates. The 1 October snapshot is not fresh 5 October source qualification. Later facility/addition proof must be refreshed before promotion.
- Never import rendered API-export IDs as new identities. The initially empty local catalogue can retain the complete raw snapshot's identity graph after a target emptiness/reference check. Refuse a nonempty or changed target rather than merge by guessed IDs.
- Preserve all existing users, workspaces, bookmarks, saved journeys and other private records. Back up the local target before additive migrations/import; rehearse atomically and verify catalogue plus protected-table hashes.
- Keep source-null holds, aliases, reviews, observation history and media rights/match evidence. Unknown fees/hours/access remain unknown. Images are optional.
- Staging is read-only in this pass. Current GitHub deployment evidence, running staging image and bounded read-only database aggregates are verified. An initial temporary approval-review capacity failure was resolved by a later retry. Historical native qualification counts remain labeled by date.
- PHP database tests run serially. Use PHP 8.4.23 and Node 22.23.1, verified at start. New behavior needs focused failing/passing regressions; finish with connected consumer and browser checks.

## Work

1. Audit — source export checksum matches the recorded digest. Raw catalogue snapshot, complete native packages and historical reports located. Staging/compatibility findings are in sibling reports.
2. Foundation — integrated identity, evidence, destination and Places consumers while preserving enrichment and accepted UI.
3. Composer — place-aware retrieval integrated without replacing durable workspace or exact event selection. Final review found incomplete saved-selection constraint validation; shared selected-interval validation, saved-origin checks and generated-category handling are repaired and verified.
4. Media — retained photo rights, concrete reviewed matches and excluded origins across canonical aliases and consumers; publication tests pass.
5. Events — clock, overnight, ambiguous-session and conditional-price parsing repaired. Captured official feed replayed locally: 196 visible occurrences, 80 held, 48 venues. Eighty municipal assets stay pending. Stale reminder delivery now rechecks exact public occurrence; focused tests pass.
6. Local data — private full local backup saved; additive migrations applied. Initial preview identified lexical comparison order after ID mapping, corrected without changing any source values. The subsequent preview passed exact mapped hashes across all ten catalogue tables and protected-record hashes, and verified rollback. Independent import review completed and actual apply passed: 11,802 stored place records with the documented ID offset; existing private rows preserved. Import receipts and recovery dump remain private.
7. Verification — all 4,235 eligible Place/Composer contracts, 798 aliases, canonical holds, fees/access and list counts verified against the local imported data in a read-only transaction. Actual Events→Composer handoff inspected in the browser; mobile category recovery, real-place Composer proposals and final combined regressions passed.

## Current evidence

- Media match/source/rights tests: 7 failures reproduced before fixes, then 8 pure checks pass. Feature publication regressions: 21 tests / 51 assertions pass.
- ID allocation and reference graph: 5 mapping/refusal tests fail before implementation, then pass. Imported place IDs use offset 1,000,000 because existing workspace references 1, 3 and 6 must not be rebound. Historical embedded review snapshots retain their original database IDs as provenance; live FK columns use the documented mapping.
- Scheduled catalogue/image acquisition now defaults off until explicitly qualified. Test reproduced unguarded schedule then passed with the guard; source refresh is not running during historical review.
- Backup SHA-256: `f4bd4247b7ab167488f094311a053602c4ad59bcad3ad806e93b16152e74ce28` (private local recovery dump; not committed).

## Final checks and review closure

- Composer/shared facts/Home/current-occurrence reminders: 289 tests / 906 assertions passed. Places/media/unit places: 229 tests / 893 assertions passed. These are scoped batches, not a claim that every project suite was run.
- The broader media suite exposed acquisition fixtures that previously assumed a newly attached photo could be published without an accepted match review. Fixtures now explicitly verify pending-match suppression; publication fixtures add a concrete accepted review before expecting an image. No runtime publication gate or local media approval was weakened.
- Browser testing found automatic addition of a place at 10:00 after that time had passed. Four regressions reproduced the bug and window-boundary failures. Automatic placement now starts no earlier than the next minute today, respects the chosen window, can use an earlier valid gap in a future morning window, and refuses an overfull day without mutating the draft. Full Workspace suite: 25 tests / 134 assertions passed. The actual mobile UI then proposed Rheingarten at 16:15–17:30, rather than 10:00–11:15. Both temporary test proposals were discarded; confirmed plans were not changed.
- Frontend: 83 behavior tests, client/SSR build, sequential TypeScript check, ESLint, focused Prettier and whitespace checks passed. An initial concurrent type/build invocation raced generated Wayfinder files; the standalone final type check passed after the build. The local SSR process was restarted against the final build.
- The four new Playwright catalogue cases are prepared but not executed in this pass. Actual-app interactions were exercised through the browser at desktop and 390×844 mobile sizes.
- Native audit source hashes still match the relevant catalogue implementation. Staging inspection remained read-only; no commit, push or deployment occurred.

- Final photo detail check: full attribution was readable at mobile width, with the existing Commons file URL and CC BY-SA 4.0 licence URL. Saved desktop/mobile screenshots accompany the report. Temporary viewport override was reset and the actual Places page left open for review.
