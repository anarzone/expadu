# Independent catalogue integration review — 5 October 2026

Reviewed the current working tree, preserving unrelated accepted launch work. Scope: historical local seed and ID mapping, official-event replay/qualification and native schedule/price parsing, media publication policy, place identity/facts retrieval, and durable Composer/Today projection. No application files or database rows were changed by this reviewer. No database tests were run. PHP 8.4.23 and Node 22.23.1 were verified.

## Actionable findings

### P2 — Saved selections omit established plan constraints

`app/Composer/PlanCatalogue.php:98` checks `matchesDiscovery()` and visit hours, but omits the category and companions checks in `FeasibilityFilter::fits()` and does not require the selected interval to lie within the plan window. Both `WorkspaceStore` add/replace (`app/Composer/WorkspaceStore.php:246`) and commit (`:326`) use this weaker boundary. A saved catalogue slot can therefore be presented as available or newly committed when the discovery filter rejects it.

A pure PHP reproduction using only the autoloader, value objects and an unused uninitialized repository returned:

| Case | Discovery accepts | Saved-selection validation accepts |
| --- | --- | --- |
| Bar with `companions=kids` | false | true |
| Bar with `categories=[park]` | false | true |
| 08:00–09:00 slot in a 12:00–18:00 plan | not evaluated | true |

Explicit manual selection can reasonably override soft preferences only when that behavior is intentional. The implementation owner confirmed that these omissions were not intended overrides. The family restriction and actual interval bounds must remain enforced. Use a shared selection check against the actual selected interval; calling the full discovery filter unchanged would incorrectly reject legitimate resized visits shorter than the default duration. Existing unchanged historical slots should remain retained with an unavailable message, as the implementation already does.

Status at report creation: implementation owner is adding regressions and a fix; independent verification pending.

### P2 — Due event reminders bypass current occurrence and visibility checks

`app/Console/Commands/Events/SendOccurrenceReminders.php:23` selects due reminders by their stored time, then `:43` sends them after checking only event/user existence and notification preference. It never rechecks public visibility or whether the stored occurrence still exists. The new importer gate can hide an ambiguous event, or a refresh can move an event to another time, while a previously created reminder still tells the user that it starts at the stale time.

`EventReminderController` validates an occurrence when a reminder is created, but this does not establish validity at delivery. Recheck the exact current occurrence through the shared occurrence query before notification; preserve history and suppress stale delivery. This sender is pre-existing code, not a newly introduced regression, but it is an affected consumer of the newly enforced source holds. The older `events:send-reminders` command has a similar pre-existing visibility gap; it is not scheduled in the inspected console routes.

Status at report creation: reported to root and implementation owner; no database reproduction attempted because another agent owns the serial database test slot.

## Reviewed boundaries without additional blockers

- Historical import is restricted to the owned local database and the fixed raw snapshot checksum. The offset consistently maps live place identity/grouping/observation/attachment references. Historical embedded snapshots retain original IDs as provenance. Exact row hashes, protected-record hashes, rollback checks, checked atomic receipt writes and transactional sequence restart are present. This is historical restoration, not fresh source qualification.
- Event replay uses the complete checksum-pinned captured response, rejects an existing event inventory, suppresses job delivery and real network access, qualifies explicit single-date ranges inside the city boundary, retains holds, preserves capture time, verifies protected rows and checks rollback while locks remain held. The inspected preview summary records 196 published, 80 held, zero approved municipal images and restored catalogue rows. This review did not execute apply.
- Native schedule parsing retains overnight single occurrences and hides ambiguous/multi-date schedules. Price parsing no longer infers unconditional free admission from conditional free clauses.
- Place media still needs approved rights, active health, source/attribution metadata and accepted match evidence; configured excluded origins remain withheld for canonical place families. Manual locks only affect priority. Event posters retain their prior asset publication contract. No new permission or blanket match approval was found.
- Place queries retain canonical identity, current observations/reviews, explicit activity qualification and reviewed grouping. Today/Workspace project current catalogue availability without overwriting historical/undo state. Exact recurring-event IDs remain intact. Today journey actions and Home weather exposure now suppress unavailable slots.

Final application build, native import execution, connected browser verification and database regressions remain owned by the root and implementation agents. This report makes no deployment or current-data qualification claim.

## Implementation-owner closure (after independent review)

This section records the root implementation owner's follow-up verification; it is not a second independent review.

1. **Saved-selection finding repaired.** `FeasibilityFilter::matchesSelection()` validates the actual chosen interval and the existing family/category/window and discovery constraints. Saved-origin distance and the generator's explicit effective complement categories are covered without turning manual choices into unrestricted overrides. Shortened valid visits remain supported; unchanged historical unavailable slots remain visible. The combined Composer/shared facts/Home/current-occurrence reminder batch passed 289 tests / 906 assertions.
2. **Current-occurrence reminder finding repaired.** The scheduled sender checks public visibility and the exact current occurrence before notifying. Six stale/hidden/rescheduled cases were reproduced then fixed; valid recurring delivery and preference behavior remain covered. This does not claim a change to the unscheduled legacy sender.
3. **Browser-found automatic scheduling repaired.** New flexible visits no longer default to a past 10:00 or outside the chosen window. Four failing regressions were added, then the entire Workspace suite passed (25 tests / 134 assertions). A mobile real-place selection visibly produced a future afternoon proposal. It was discarded after verification, leaving confirmed plans unchanged.
4. **Broader integration and UI checks completed.** Places/media/unit-place suite passed 229 tests / 893 assertions, with acquisition fixtures explicitly retaining pending match review. All 83 frontend behavior checks, final client/SSR build, TypeScript, lint, focused formatting and whitespace checks passed. Local native import/audit receipts and the root browser observations are linked in README.md. The four new Playwright cases remain unexecuted and are not counted as passing browser automation.

No staging/production mutation, image-rights approval or launch approval is implied by this closure. Remaining release gates are explicit in the main report.
