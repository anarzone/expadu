# Events and Today — local launch implementation

5 October 2026. Actual app at `http://127.0.0.1:8011`; base `c386427d` plus the
existing shared working tree. Approved prototypes remain references. No deployment
or catalogue promotion was performed.

## Events

- Added an exact calendar date alongside the existing relative date choices.
  The date and discovery state survive navigation and refresh. Choosing a
  relative window clears the explicit date. On Sunday, Weekend includes the
  remaining Sunday instead of unexpectedly jumping to next weekend.
- Exact event links retain the occurrence and its Berlin date. Closing a detail
  clears the selection through Inertia's history API. A late response for an old
  selection cannot replace a newer detail or reopen a closed one.
- Saved events are now account-backed occurrences, accessible both from Events
  and global Saved. A recurring event on a different date is a distinct save.
  Existing device-only saves can be imported explicitly in bounded batches;
  failed/unavailable entries remain on the device for recovery.
- Removed or expired saved items remain identifiable and removable. Unsave only
  removes the bookmark; it does not cancel a plan, reminder or external booking.
- Saving, reminder creation and exact detail lookup validate the current visible
  occurrence. A hidden or invented occurrence cannot become a new save/reminder.
- Loading and write failures stay visible and offer recovery. A save is reported
  only after server acknowledgement. Late responses are account-scoped.
- Existing contextual origin, travel mode, area, sorting and compact filters
  remain distinct controls. Missing catalogue content has an honest empty state.
- Media now passes through the existing approved-rights selector, including
  warm-cache rechecks; no new permissions or stock-photo substitutes were added.

## Today

- The clock updates while visible and on resume. Midnight changes the agenda,
  refreshes events and reloads date-sensitive home/weather content.
- Server workspace is refreshed on return to the tab. Reads coalesce, wait for
  an ongoing edit and cannot roll back a newer acknowledged revision.
- An edit captures its original revision. If another session changes the plan,
  the old form cannot silently overwrite it; unsaved fields remain visible with
  a recovery explanation. Account changes reset private inline edit state.
- Continuation prioritizes a real unfinished draft or meaningful conversation.
  Old operational acknowledgements do not become repeated advice. Each context
  is dismissible; a new unfinished context can appear later.
- Accepted agenda detail and connected layout are preserved. Discovery and
  practical help do not invent appointments, events or providers to fill space.

## Observed verification

- Events launch/API/workspace selection feature batch: **27 passed, 124
  assertions**, covering occurrence identity, timezone persistence, Sunday
  windows, account isolation, idempotent saves, invalid input and removal safety.
- Events/Today browser batch: **28 passed**, desktop and Pixel 7, including exact
  date refresh, save across reload, global Saved links and midnight rollover.
- Broad Home/Bureaucracy/Events/Media/local-access feature batch: **455 passed**;
  six old onboarding assertions expected Paperwork instead of the intentional
  Today destination. The destination expectation was corrected; all six then
  passed in the independent 42-test case/publication batch. No source-authority
  assertions were removed.
- Independent Events recovery: **10 desktop/mobile checks passed**, plus **10
  repetitions** of the previously intermittent close/refresh case. The remaining
  cause was a late deferred Inertia response for the original URL. Dismissal
  now suppresses that obsolete response while explicit reopening still works.
  The suite also covers unavailable saves and Back/Forward date continuity.
- Today workspace resume and stale-edit protection: **4 desktop/mobile checks
  passed**, recorded in `composer-today-core.md`. Counts are separate batches,
  not a sum of unique tests.

## Data and release limits

The actual local review database contained **zero places, events or approved
media** at inspection. Populated browser scenarios use explicitly controlled
test fixtures; these were not seeded into the review catalogue. A catalogue and
provider qualification on staging is required before discovery can be accepted
for launch. Real reminder delivery and external booking completion were not
verified by local API/browser tests.

Two additive account-data migrations accompany this pass: event bookmarks and
saved journey requests. They were applied only to the local review database;
normal release migration procedures remain required.
