# Supported actions and persistence

This records the implemented local contract on 5 October 2026. It does not promise
capabilities absent from providers or from the target environment's catalogue.

| Person's task | Required context and real path | Review/confirmation | Persistence |
|---|---|---|---|
| Plan a day from places/events | Date, time window, origin/mode and eligible catalogue; Composer candidate/planning APIs | Proposal stays separate until applied | Server workspace, dated plans and revision |
| Add a known appointment | User-supplied title, date and times; optional known location; Composer appointment controls | Add to proposal, then apply | Same workspace as Today |
| Save an event idea | Published exact occurrence, event ID and offset-bearing start | Save only; no reservation | Per-account event bookmarks |
| Add an event to a plan | Event ID, exact occurrence, venue and fixed times | Review proposed plan | Server workspace; source event identity retained |
| Receive an event reminder | Valid future occurrence and supported reminder channel | Explicit reminder action | Account-owned reminder; delivery needs environment verification |
| Compare or start a journey | Confirmed origin/destination coordinates, mode and date/time; actual routing API | Select route; Start is separate from preview | Selected preview: account-scoped tab session, at most 30 minutes. Active trip: server-owned |
| Save/repeat a journey | Reusable origin/destination request and mode | Save request; repeat performs fresh routing | Per-account saved journey; no historical itinerary/countdown stored |
| Check a station | Provider stop identity, selected clock, available feed | Direct line/direction/service controls | Stable filters during board refresh; timestamps retain source observation |
| Review paperwork | Situation plus reviewed case rules, official source/version/jurisdiction checks | Dedicated task/document controls | Account-owned case/progress; no AI-authored legal advice |
| Prepare a service request | Chosen category and optional user preferences | Copy a reviewable provider-facing message; optional unsent Composer handoff | Per-category page draft; sending/booking is not provided |

## State boundaries

- `WorkspaceStore` is the plan authority. Revision-checked writes reject another
  tab's stale version. Drafts, saved plans and history are distinct. Clearing
  conversation history does not erase confirmed plans.
- Entering Composer with a department request never sends it automatically and
  never overwrites existing unsent text. A failed/uncertain write does not claim
  that the plan was saved or unchanged.
- Server-backed Saved events/journeys are scoped to the authenticated account.
  Existing server-saved places remain in global Saved. Device draft/import data
  is explicitly separate and cleared at account exit.
- Event identity includes the occurrence, not just the recurring event record.
  Saving or removing a bookmark does not reserve attendance or cancel reminders.
- Location sharing is reactive across Travel, maps and background uploads.
  Stop-area markers, device GPS and provider route geometry have different
  meanings. None establishes a live vehicle position or a verified platform.
- No private page/API runtime cache is retained by the service worker. New
  updates do not reload an open form. Server-rendered controls hydrate in place.

## Intentional capability boundaries

- Services has no qualified provider directory or booking integration.
- Composer supports the implemented planning/discovery/guidance paths; an
  unrestricted conversational assistant is not part of this completion claim.
- Foreground GPS assistance is available. Reliable suspended-app/background
  tracking, live vehicle coordinates and automatic rerouting are not promised.
- Exit reminders follow selected scheduled times; subsequent provider delays do
  not automatically re-time them.
- The legacy TRIAS fallback cannot satisfy an explicit depart-at/arrive-by
  request and is skipped for those searches. An honest unavailable result is
  preferable to a leave-now route presented as a requested future journey.
- Cologne is the initial qualification area. Coverage and language completeness
  in other cities have not been certified. Missing costs, hours, accessibility,
  entrances or legal coverage remain unknown.
