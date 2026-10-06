# Final correction review

Candidate worktree: /Users/anar/.codex/worktrees/places-production/expadu-app
Baseline for this turn: 554eab971d3b2b43ec9312a893d292302bb6b0b3 (green CI, existing draft PR61).
Full production branch base: 8f7eb597b073c025fb8ab0f0011eb1313657d6ac.
The prior candidate branch was reviewed and tested; review the present full candidate
with particular attention to its uncommitted correction delta and integration.

Plan and operational helpers are in the PRIMARY workspace's
`docs/places/production-release/2026-09-30-corrections/`, not yet all in the candidate.
This turn fixes the held-place/saved-plan visibility defect and qualifies 103 older
sources. No live production or staging catalogue writes are authorized.

App changes: TodayPlanStore::get remains ready-only. getForDisplay returns ready
or needs_review with no stale slots. HomeFeed uses get; HomeFeedController uses
getForDisplay. Cached references are retained and rehydrated if eligibility returns.
Detail computes eligibility using the shared scopes; no-parent records retain the
existing cheap path while reviewed children use DestinationGrouping::eligible.
PlaceResource exposes an ephemeral status and suppresses open_now/price_text for
unavailable details. Shared rich detail shows a neutral notice and retained feedback
without routing or live-enrichment advice. Dashboard shows the review notice/CTA.

Source work: existing normalizer classifies 103 into76 qualified updates (11 name,
3 category changes) and27 evidence holds. Current public tags were inspected;
no closure is inferred from missing output, lifecycle tags, or a hold. Native journal
updates76 and separate recoverable hold27 in one lab transaction; anchor is saved
before mutation. Apply replay and full original-field/eligibility recovery passed.
Lab now12063 stored,4155 eligible, all4155 mapped to the4719 prepared-source records.
The prior massive source-null hold is still lab-only; production acceptance remains open.

Verification: initial6 regressions red then green48assertions; expanded8 green56.
Related162-test suite found an extra reviewed-parent query; controller now uses its
no-parent fast path with WHERE destination_spot_id IS NULL, rerun ongoing. Typecheck,
lint and production frontend build passed after formatting. User explicitly wants
API validation, no browser UI inspection. Existing CI includes its normal browser job.

Review focus: no stale recommendations from display-only states; no hold-as-closure;
canonical aliases, activity-qualified facilities, parent eligibility, retained snapshots,
no N+1, no unsafe cache objects; source identity drift/replay/recovery and target isolation.
Review read-only. No edits, new subagents, external model calls or messages to other chats.
Latest user-supplied AGENTS rules govern over the older file in this candidate checkout.
