# Expadu — local implementation and launch handoff

Date: 5 October 2026. Plan: `../../superpowers/plans/2026-10-05-page-by-page-launch-plan.md`.

The planned local implementation has been carried out in the **actual app**.
The accepted prototypes remain available separately; this pass does not replace
them with another design experiment. Final check outcomes are recorded below and
in the area reports. **This is not a deployed or approved public release.**

## Review the result

- [Open the actual local app](http://127.0.0.1:8011/dev/review): the existing
  local-only review access signs into the designated synthetic account.
- [Travel](http://127.0.0.1:8011/timetable), [Today](http://127.0.0.1:8011/dashboard),
  [Events](http://127.0.0.1:8011/events), [Paperwork](http://127.0.0.1:8011/bureaucracy),
  [Services](http://127.0.0.1:8011/services), [Composer](http://127.0.0.1:8011/composer).
- Port 8765 is the prototype reference server. It is not the running app above.

## Page outcomes

| Area | Implemented and reviewed locally | Remaining acceptance dependency |
|---|---|---|
| Shared shell | Consistent compact five-page navigation, safe startup hydration, no forced worker reload, private-response cache removal | Deployed/browser-device check |
| Travel | Side-by-side planner, canonical inputs, station-wide simultaneous services, compact filters, real stop-area maps, Map/Steps, exact return, validated GPS and trip starts, reusable account saves | Physical GPS/background limits, actual provider fallback latency and target coverage |
| Today | Preserved agenda/detail, useful continuation with dismissal, midnight and tab-resume updates, revision-safe edits, balanced quiet-phone ordering | Populated target catalogue and unfamiliar-user review |
| Events | Exact calendar date and occurrence links, account Saved, unavailable-item recovery, stale-detail response guards, validated reminder writes | Eligible current events/media and real reminder delivery |
| Composer / Saved | Shared durable workspace, explicit review/apply, preserved input, plan/history return, bounded failed requests; places, events and journeys reachable in Saved | Catalogue-backed recommendation and swap rehearsal on qualified data |
| Paperwork | Compact optional help, dedicated case controls, preserved source gates; unreviewed legacy eligibility/calculators withheld from publication | Human-reviewed catalogue coverage and public source/copy approval |
| Services | Direct reviewable request copy, per-category preferences, optional unsent Composer handoff, known-appointment path | Provider directory/booking remains outside the offered capability |
| Access / onboarding | Today or validated intended destination, genuine Skip semantics, account-bound draft recovery, protected local access | Real email, configured OAuth/2FA and deployed session checks |
| Profile / settings / alerts | Acknowledged writes, retained failed edits, retry, touch/keyboard controls, focused layout, account-aware push state | Actual push/reminder delivery on supported devices |
| Data / public content | Approved-media selection and cache revocation, truthful routing/map privacy description, legacy rule publication suppression | Catalogue promotion, legal/privacy review and deployed data qualification |

## Evidence

- [Travel UI and real-feed screenshots](travel-ui.md)
- [Travel data, dates, freshness and GPS](travel-backend.md)
- [Events and Today](events-today.md)
- [Independent Composer / Today review](composer-today-core.md)
- [Account, utility pages, startup, media and public-content review](reliability.md)
- [Supported actions and persistence contract](contracts.md)
- [Execution and environment ledger](progress.md)

Tests use controlled fixtures for edge cases and designated synthetic accounts
for real writes. They do not establish the contents of staging or production.
Counts in different reports overlap and must not be added as unique coverage.

Final local gates passed: the client/SSR production build, application and worker
type checks, full app ESLint, resource formatting, PHP style and **82 frontend
behavior tests**. Production-build font/chunk-size warnings remain recorded;
they are not evidence of measured device performance. App lint/style scopes
exclude archived prototype/review scripts, while shipping code and real tests
remain checked. Important observed browser batches include **28 Events/Today**,
**10 Events recovery** plus 10 timing repetitions, **14 Composer**, **14 focused
Travel**, **4 Today resume/conflict**, **4 Today priority**, **4 onboarding**, and **8 SSR/public-page**
checks across desktop and mobile. Area reports contain the separate backend,
privacy, settings and authority results.

The final phone priority screenshots were inspected after the last build. A
quiet day starts with useful city actions, then the agenda/discovery; generic
Paperwork is last. A genuine sourced next step retains its priority. The actual
Travel page was also opened after the final rebuild: real starting-point search
and multiple routes from Köln Neumarkt to Museum Ludwig completed successfully.
The local app and matching SSR renderer were left running for review.

## Why the catalogue is still a release gate

The initial launch pass found an empty local catalogue. The subsequent
[catalogue integration pass](../2026-10-05-catalogue/README.md) selectively connected
real source-backed content to the actual app, preserving existing user records.

- Historical places snapshot dated **1 October**: 11,802 stored records;
  4,235 eligible place components, 4,224 general destinations, 4,139 list results.
  All 798 aliases and held-record exclusions were verified with native consumers.
- Official events captured **5 October**: 196 visible occurrences and 80 held
  records. Exact dates, overnight schedules and conditional prices are preserved.
- Media rights remain gated: 119 general destinations have a policy-publishable
  hero; the 80 newly imported municipal image candidates remain pending.

Local integration is complete data wiring, not fresh verification of every
historical fact or permission to promote the snapshot to staging. Current
staging image and bounded counts were inspected read-only; its native consumer
qualification and source freshness still need the target-specific release process.
Do not overwrite staging with the local mapped IDs. Human review of withheld
sources/content and physical-device/external-service checks remain release gates.

## Release sequence still required

1. **Prepare a concrete candidate.** The current checkout is `staging` at base
   `c386427d877e2e962b7f0b5aa023280dbcbed880` with extensive pre-existing and current
   uncommitted work. Review the combined diff and create scoped commits without
   discarding unrelated changes or bypassing hooks. No clean candidate SHA is
   claimed here.
2. **Qualify data and publication.** Complete the catalogue process above and
   obtain human review of legal/privacy statements and source-sensitive public
   tools. Do not re-enable withheld legacy guidance without the authority path.
3. **Deploy the authorized candidate to staging through CI.** Apply the reviewed additive migrations, including event bookmarks, saved
   journeys and catalogue identity/fact/media support; ensure the client and SSR
   assets match, and verify the running image. Run the documented location
   retention cleanup in its reviewed rollout procedure; a dry run precedes
   any existing-record maintenance.
4. **Exercise real external behavior.** Verify email and configured OAuth,
   push/reminders and cancellation, scheduler/queues, route/feed freshness,
   failure latency and error-report delivery. Use synthetic accounts; do not
   treat local login shortcuts or mocks as evidence of external delivery.
5. **Rehearse on physical phones and with unfamiliar users.** Check iOS Safari,
   Android Chrome and installed PWA behavior, permissions, keyboard, resume,
   denied/stale GPS, safe updates and the main end-to-end tasks in the plan.
   Record task completion and any confusion; fix blockers and repeat affected
   journeys.
6. **Rehearse rollback and obtain release authorization.** Record the exact
   prior image, backup/restore proof and migration/data compatibility. Then
   release through the existing workflow and verify the running production
   revision, sources, persistence and critical journeys.

The last read-only staging CI observation was successful run
[36621142943](https://github.com/anarzone/expadu/actions/runs/36621142943) for
`89289db9641bb75a563e74b44be9b4717bd61b22` on 29 September. It is **not** this local
candidate. A subsequent 5 October read-only check verified the running staging
image and bounded catalogue counts; details are in the catalogue audit.

Stop or roll back a release for broken access, private-data exposure, lost plan
writes or widespread failure of routing/discovery. A successful push alone is
not a passed release.

## Intentionally unsupported promises

No live vehicle positions, verified line-to-platform assignments, dependable
suspended-app tracking, automatic rerouting or delay-adjusted exit reminders
were invented. Services does not provide a directory or book appointments.
Composer does not generate authoritative legal guidance. Unknown facts and
unsupported coverage remain visible. The app remains useful within those
explicit boundaries.
