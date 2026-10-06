# Launch implementation progress

Plan: `docs/superpowers/plans/2026-10-05-page-by-page-launch-plan.md`

## Baseline and scope

- Owner authorized implementation and completion on 5 October 2026.
- Initial checkout: `staging`, HEAD `c386427d877e2e962b7f0b5aa023280dbcbed880`, extensive pre-existing uncommitted app/prototype/data work. Preserve it; scoped edits only.
- PHP 8.4.23 and Node 22.23.1 verified. Prototype server is on 8765. Actual app review server restored on 8011; `/dev/review` signs into the designated synthetic local account with existing local-only safeguards.
- Approved prototypes remain design references. Actual Laravel/Inertia app is implementation target. No new sample content in user data.
- Ruling: continue in the existing checkout because accepted UI and Composer changes are uncommitted here; a clean worktree would omit them. Ownership is partitioned below and no reset/wholesale branch import is permitted.
- Ruling: finish local implementation and verifiable checks autonomously. Production deployment, real-device/human acceptance, provider credentials and target-environment data qualification remain explicit release gates; never claim them passed from local source or mocks.
- Ruling: Services launches within its real preparation/known-appointment capability; do not promise a provider directory or booking without qualified data/integration. Composer advertises supported tools, not unrestricted conversation.

## Work ownership and dependency review

| Tasks | Owner | Boundary / coordination |
|---|---|---|
| Travel backend, feed identity/freshness, GPS progress | travel_context | Additive API contract; coordinate with Travel UI. Own service/DTO/hook tests. |
| Travel planner, station board, map/steps and restoration | launch_travel_ui | Existing real APIs; preserve approved prototype composition. No sample transplant. |
| Shared cache/update, access, settings/alerts, CI | launch_release_audit | No main page edits. Own worker, Blade update handling, account utility pages and scoped tests. |
| Events, Saved, Composer, Today, Paperwork/Services review, overall verification | root | Coordinate route generation, builds, DB tests and independent review. |

Task consistency review: Tasks 0–13 match product/trust constraints. Tasks 1/3 share departure identity and freshness; backend is additive and UI supports legacy records during integration. Tasks 1/4/6 share Saved/occurrence identity; root owns the complete path. Tasks 2/9/10 share account/cache/update state; one agent owns those changes. Tasks 3/4/5/6 share journey handoffs; direct actions retain identity/date and Composer never auto-confirms. Tasks 7/8/11 cannot invent coverage to satisfy layout density. Tasks 12/13 need observed runtime evidence; source findings alone never complete them.

## Implementation log

- Started parallel Travel and shared-reliability implementation; root inspecting Events/Saved and restoring local review runtime.
- Database feature tests are serialized across workers; first slot assigned to launch_release_audit. Pure frontend tests may run independently.

## Verification and remaining release gates

- Travel backend: individual service identity, absolute dates, scheduled/realtime/stale states, overnight GTFS service days, correct fallback constraints, validated GPS and safe active-trip starts. See `travel-backend.md`.
- Travel UI: approved desktop split, compact mobile controls, station-wide board and nearby-stop scope, real stop-area map, Map/Steps and journey return, account-backed reusable Saved journeys. See `travel-ui.md`.
- Events: exact-date discovery and links; account-backed occurrence bookmarks and shared Saved; unavailable items retained for removal; reminder validation; imported device saves are explicit and bounded. Sunday Weekend means the remaining Sunday, matching a person's current weekend.
- Today: ticking/resume-aware clock, new-day agenda/feed refresh and recovery errors cleared on success. Composer continuation is dismissible and prioritizes meaningful unfinished context over old operational messages.
- Reliability/access/utilities: safe update/cache behavior, encrypted private history, acknowledged writes, retained drafts, onboarding target/skip behavior, push ownership, compact Services/Paperwork help. See `reliability.md`.
- Rights: unmanaged photo fallback removed from Events and Today; cached results recheck publication state. No asset permissions were newly approved.
- Public content: legacy citizenship/residency calculators withheld pending reviewed sources. Legacy ungated eligibility publication is suppressed across stored/cached alerts and queued notifications; reviewed case rules remain intact.
- Two additive migrations applied locally only: event bookmarks and saved journey requests. Automated feature tests use the separate `testing` database; review app uses `expadu`.
- Independent review found and repaired delayed event reopening, SSR draft replacement, Today resume/stale-edit handling, and generic Paperwork dominating quiet mobile Today. Only observed successes are recorded in area reports; never sum overlapping test batches as unique coverage.
- Location history now honors the existing seven-day promise for new records, uses account locks for opt-out/deletion purges, and removes passive coordinates from durable analytics. Historical deployed records require the documented dry-run/cleanup rollout; it was not run on user data during review.
- Full application lint, resource formatting and PHP style checks passed. Lint scopes now exclude non-shipping prototypes and archived review/data-audit captures; actual source/tests remain covered. Node/CommonJS test files use the correct environment. The full pure frontend batch passed 82 tests.
- Final client and SSR production builds passed and the local renderer is refreshed to matching assets. Two local additive migrations are present. No commit, push, deployment or historical-data cleanup was performed.
- Final local verification complete: 10 Events recovery checks plus 10 timing repetitions, 4 Today resume/conflict checks and 4 quiet-day/real-next-step priority checks passed on desktop/mobile. Final phone/desktop captures reviewed. Actual Travel search and provider routes completed after the last build. Local app and SSR left running for user review. Release gates below remain outstanding by design.

## Environment/data evidence

- Read-only local counts at 2026-10-05 13:26 Europe/Berlin: 0 stored places, 0 base-eligible places, 0 events, 0 upcoming visible occurrences, 0 approved/active media assets. This explains actual-app discovery emptiness. Test fixtures exercise populated states separately and are not release data.
- Re-read **Investigate places data quality** on 5 October. Its latest handoff, dated 3 October, describes a separately prepared local catalogue and branch, with source evidence frozen on 1 October. It explicitly does not establish current deployed content. No wholesale branch replacement, catalogue import or new media approval was made during this UI/reliability pass.
- Read-only GitHub workflow check on 5 October: latest listed staging CI run `36621142943` succeeded for `89289db9641bb75a563e74b44be9b4717bd61b22` on 29 September. This is not the local dirty candidate and does not prove its deployment. [Workflow](https://github.com/anarzone/expadu/actions/runs/36621142943).
- Target-environment catalogue reconciliation/source freshness, real email/OAuth/push delivery, physical iOS/Android use, deployed migration/CI checks, rollback qualification and launch authorization remain release gates. Local mocked cases do not substitute for these checks.
