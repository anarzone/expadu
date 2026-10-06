# Source corrections and retained-place behaviour

Refs EXP-69, EXP-72. Continues the reviewed production rehearsal at candidate
554eab971d3b2b43ec9312a893d292302bb6b0b3. Work in the existing candidate worktree;
production and live staging data remain unchanged.

1. Qualify all 103 older OSM source identities from the saved fresh response.
   Reuse the existing category/hold/normalization rules. Use public source names
   and nodes/centres, preserve unknown facts and existing recommendation holds.
   Journal only source-key-matched updates in the isolated lab, with exact drift
   guards, replay and recovery. Unsupported/missing/ambiguous records stay held.
2. Reproduce and fix the retained-reference inconsistency using focused Pest tests:
   held place details must state their unavailable status; saved Today plans must
   retain a visible review notice without presenting stale visits as recommendations.
   Keep existing consumer eligibility checks and the raw saved references intact.
3. Verify the relevant API and saved-plan contracts, then re-run those checks on the
   lab. Preserve the accepted design using existing surfaces and compact copy.
   The user's instruction to validate through APIs applies; no browser inspection.
4. Review the complete change once, run required tests/build/hooks, update draft
   PR #61 and record exact new evidence in Jira and BookStack. No merge/deploy.

## Decisions and progress

- Ruling: source screening differences are not automatic closures. An old/disused
  tag can describe a previous function. Current category, lifecycle and access
  evidence determines the update or hold; missing API output alone never means
  a place has closed.
- Ruling: preserve TodayPlanStore::get's null result for an unsafe recommendation.
  Add an explicit display-only review state so the Home feed cannot mistake a
  retained snapshot for a valid itinerary. Keep old cached claims out of that state.
- Ruling: current user-provided project rules supersede old worktree instructions.
  Preserve unrelated edits in the primary checkout and do not change root rules.

- Completed source classification and isolated journal operation: 76 updates,
  27 evidence holds; full recovery, replays and original-field restoration passed.
- Completed initial app regressions RED→GREEN: 8 tests / 56 assertions. The wider
  suite exposed a redundant parent-policy query; preserve the no-parent fast path
  with an explicit null-parent constraint to avoid a concurrent membership bypass.
- Ruling: `needs_review` is a display state and the existing Composer review action,
  not an audited catalogue approval. No new review authority or automatic replan
  is implied; cached recommendations remain rejected by the trusted `get()` path.
- New isolated API checks: all 4,719 prepared-source records checked; all 4,155
  Composer candidates are mapped to those records. Twenty held-reference category
  samples show a neutral unavailable detail and saved-plan review state.
- Final related suite before review: 162 passed / 767 assertions. Typecheck, lint
  and frontend build passed. Exhaustive lab verification needs a 1 GiB CLI memory
  allowance; the default 256 MiB failed without writing catalogue changes.
- Final independent review found a real retained-plan bug: changing a cafe to a
  bar/coworking space could bypass the existing kids restriction. Two regressions
  failed first, then passed with 26 assertions after moving that hard companion
  rule into shared discovery validation. Intentional category complements remain.
- Ruling: correct the review-notice copy in the same fix because it promised
  access to old choices that the existing Composer action does not provide.
  The action starts an updated plan from the retained prompt. No retained-slot
  editor is introduced; the original cache remains intact. Cost: users must
  compose again rather than directly edit a stale plan.
- Final review boundaries: production acceptance/deployment remains open;
  browser appearance was not inspected per user direction; retained event and
  appointment refresh is outside this place-source correction scope. These
  boundaries must remain explicit in release evidence.

- Final fix pass complete: 1,656 tests / 6,923 assertions passed through normal
  commit hooks (157.01 seconds), with secret scan and formatting. Final frontend
  build passed. Commit f779ce52e8d4f4363df0d6730d60f0c207e5ae8f pushed to existing
  draft PR61; no merge/deployment. Final isolated APIs passed with code hashes
  matching the commit. Jira69/72 and BookStack33/36 updated; history read back.
- Branch integration ruling: use the existing authorized draft-PR workflow and
  keep its checkout for follow-up; no new integration permission is needed.
  Production acceptance remains separate.

- Final exact-head CI36694619435 passed Lint, Test and Browser Tests. Image builds
  and both deployments skipped. This correction checkpoint is complete; the
  broader release conditions listed in REPORT.md remain open.
