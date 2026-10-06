# Events and media qualification — 5 October 2026

The local review catalogue now contains **196 qualified visible events**, with
80 additional source records held for review and 48 linked venues. The complete
276-record official response is retained privately. All 80 municipal image
candidates remain pending; no image rights were approved. The manual YAML and
demonstration seeders were not used.

## Observed local state

At audit start, the local `expadu` database had 0 events, 0 venues, 0 places,
0 media assets, 0 official boundary polygons, and no system organiser. The parent
task restored 86 boundaries, 11,802 places, 229 media assets and 341 attachments
before this event replay. This is the review app's database, distinct from
`testing`. The event apply described below changed only this local environment;
staging and production were not mutated.

Runtime checked: Herd PHP 8.4.23 and Node 22.23.1. Laravel Boost reported Laravel
13.2.0 and PostgreSQL.

## Current official feed evidence

The [official public feed](https://www.stadt-koeln.de/externe-dienste/open-data/events-od.php?ndays=40&nobrs=1)
returned HTTP 200 and `success: true`. Two successful bounded reads were made:
one availability check and one retained snapshot. No event-image bytes or
geocoding providers were fetched.

Retained snapshot metadata:

- Capture time: `2026-10-05T13:15:15.322382+00:00`.
- SHA-256: `d63ecd9d0e704c69edf755319929d62fcad7285a2069d048c837629091157268`.
- Size: 239,788 bytes; 276 source records.
- Private temporary source: `/private/tmp/expadu-events-catalogue-2026-10-05/official-feed.json`.
- Adjacent `official-feed-metadata.json` records URL, capture time and checksum.
- Directory mode is 0700; both files are 0600. The payload is public source data.

The records include 259 same-date entries and 17 multi-date entries. 269 start
on or after 5 October; the overall start-date range is 9 May–14 November because
the feed includes ongoing exhibitions. 258 have nonempty coordinate strings;
19 fail numeric-coordinate parsing. 263 carry a teaser-image URL, which is not
permission to publish that image.

The final qualification against the current clock and the actual 86 city polygons
accepted **196 records** with one explicit source range and a usable in-city
location. The remaining **80 records** stay hidden and marked for review.
Overlapping hold reasons: 63 ambiguous/incomplete time strings, 19 invalid or
missing coordinates, 17 multi-date schedules, 13 absent/non-Cologne venue city
values, and 1 coordinate outside the city polygons. An early offline screening
was only provisional; the applied cohort uses the polygon-checked result.

## Confirmed parser fixes

`app/Console/Commands/Events/ImportOfficialEvents.php` now accepts explicit
`HH:MM bis HH:MM` and dash-separated clock ranges without the word `Uhr`, while
retaining the existing hour/minute validity checks. The public
[5 October Rathaus event](https://www.stadt-koeln.de/leben-in-koeln/veranstaltungen/daten/39393/index.html)
specifies 18:30–19:30 without that suffix. Before the fix it became
00:00–23:59:59. The retained feed has three affected records: 39393, 41143, 39394.

The importer now distinguishes a source spanning multiple dates from a one-date
event that runs past midnight. The snapshot's Museumsnacht record 41384 specifies
7 November, 18:00–01:00. Before the fix it became a daily series ending before
its start on 7 November. It now remains a one-off ending at 01:00 on 8 November.
The source identity/upsert key remains unchanged. The final native occurrence
readback confirms both corrected examples in the applied local data.

The official price parser also now confirms free admission only from a complete,
unconditional statement. It recognizes `kostenfrei`, optional donations and
ordinary explicit free-admission wording. Mixed free/paid audiences, free venue
entry with paid event participation, free cancellation, and multiple fee amounts
retain their original text with an unknown numeric price instead of a false free
label or a guessed general price. Five failing price cases were reproduced before
the change; the nine focused pure price cases then passed. The source wording is
not rewritten.

Regression evidence:

- Five missing-suffix cases failed first against the real importer (three valid
  ranges, two invalid clocks); the source overnight case also failed first.
- After the fix, `ImportOfficialEventsTest` and `EventOccurrenceTest` passed:
  **26 tests** in the combined batch.
- Pint passed for both changed files.
- The same batch also ran `PublishedSurfaceMediaTest`: all five media cases
  failed because a concurrent compatibility edit introduced a selector requiring
  `Spot::identityAliases` before that relationship was present. This is recorded
  as an integration failure to recheck after that agent completes, not a passing
  media verification. The combined batch was 26 passed, 5 failed, 109 assertions.
- Initial sandbox execution could not reach Redis; the regression runs above
  used the authorised separate local testing services.

After the price correction and shared schedule-publication gate, the complete
targeted batch passed **51 tests / 166 assertions** (`ImportOfficialEventsTest`,
`EventOccurrenceTest`, and `tests/Unit/Events`). Four additional refresh cases
failed first, proving that unsupported schedules were previously returned to
active status; they now remain hidden. The parent later reported its integrated
media batch passing 21 tests / 51 assertions after compatibility work.

The downstream occurrence reminder sender now rechecks the exact current
occurrence through `Event::occurringBetween` before delivery. Six regressions
first reproduced stale notifications for hidden, expired, low-relevance,
rescheduled one-off, changed recurring weekday and ended-series events. They
now suppress delivery while retaining the reminder history and user record,
using the existing `sent_at` retirement policy. Valid recurring reminders,
notification preferences and idempotency remain covered. The reminder, Events
API and occurrence batch passed **35 tests / 149 assertions**; Pint passed.
No reminder delivery command was run against the review catalogue.

## Remaining schedule limitations

Do not publish the full captured feed solely because the two parser defects are
fixed. The current model/importer still cannot faithfully represent these forms:

- Multiple audience sessions, such as the [6 October Spieletester event](https://www.stadt-koeln.de/leben-in-koeln/veranstaltungen/daten/40624/index.html),
  which has one session for children and another for older children. The importer
  takes the first clock range and loses the second session.
- Different hours by weekday, closures, and opening-night exceptions. Any
  nonempty time string on a multi-date record can currently become `FREQ=DAILY`,
  including days the source does not say the venue is open.
- Door-opening time followed by performance time. The first single time can be
  treated as the event start.
- A single start time with no end. The importer supplies a two-hour end, which
  is a planning estimate, not an official end time.

The conservative subset excludes all these forms. Do not guess additional
occurrences, turn a multi-session event into one continuous window, or infer
availability from an exhibition date span.

`OfficialEventSchedule` now supplies one strict clock-range gate shared by the
native importer and local replay qualification. Native refresh retains complex,
multi-date and incomplete schedules as hidden, with `needs_review` and the
`schedule-uncertain` tag. This prevents the hourly official import from silently
republishing those held schedules. Their stored source records remain available
for review. Location qualification remains an additional replay requirement.
Unlocated or online events are not globally hidden solely for lacking map
coordinates: that is an honest supported state. This local rehearsal deliberately
selects the narrower cohort that can support in-city routing.

## Pipeline and safe local connection

Native commands and their actual effects:

| Entry point | Effects and constraints |
| --- | --- |
| `php artisan events:import-official --days=40` | One official feed; upserts by source/UID; stamps verification; writes source-run cache; queues `ProcessEventJob` and teaser-image validation; a complete response hides missing official records in the window. Has no snapshot, dry-run or qualified-subset option. |
| `php artisan events:link-venues --limit=1000 --geocode=0` | Links/repairs venues from stored event coordinates and names without external geocoding. Omit `--geocode=0` and it can make up to 60 provider lookups. |
| `php artisan events:scrape` | koeln.de Tribe endpoint, up to 20 pages of 50 rows in a 30-day window; not verified live by this audit. Also queues processing/media work. |
| `php artisan events:enrich` | Deterministic tags/quality and external geocoding for missing locations. |
| `php artisan events:import-manual` | Ten YAML templates; derives next dates, creates organiser/venues, marks all verified and quality 1.0. Not suitable without fresh occurrence evidence. |
| `php artisan events:source-health --json --max-age=36` | Inventory/freshness readout, but also writes an error log on unhealthy result. Requires 80% translation and 40% location coverage for healthy status. |

Dependencies before native import are the system organiser and the complete
official city boundary set. Create only the missing organiser in the scoped
local operation. Do not run the general `DatabaseSeeder` merely to obtain it:
that also imports/prunes bureaucracy tasks and runs `SpotSeeder`.

The native command enforces the shared schedule gate; the local-only wrapper
`events-local-replay.php` adds snapshot identity, actual polygon and protected
data controls. Its qualification rules live in `events-local-qualification.php`.
Eleven focused qualification tests and nine price tests pass (20 tests / 33
assertions). The reviewed rollback preview and authorised local apply both
completed. The wrapper controls are:

1. Assert the intended local database/environment and snapshot checksum. Record
   protected-table fingerprints and catalogue counts. Keep classification
   disabled and hold queued work for this replay.
2. Install/verify the 86 source-backed boundary polygons and minimal organiser.
3. Feed the complete retained response through the native importer under one
   database transaction, replacing only that outbound HTTP response. Prevent
   all other outbound requests and hold processing/media jobs. Do not pass a
   filtered response as a complete authoritative feed against populated data:
   the importer would hide other official events absent from that subset.
4. Before committing, hide the held source IDs; retain only explicit future or
   ongoing ranges inside Cologne polygons. Preserve raw evidence separately and
   use the actual snapshot capture time for `verified_at`. Do not claim the
   snapshot was freshly fetched at replay time.
5. Link stored venues with `events:link-venues --geocode=0`, preserving unknown
   coordinates, language, capacity, booking and price facts. Review the exact
   event counts/API occurrence times before committing the transaction.
6. Verify Events, Today and Composer against that local data. Keep every
   municipal teaser pending; the catalogue can be usable without a photo.

The wrapper defaults to preview, accepts only local PostgreSQL database `expadu`,
refuses an existing event inventory, checks the exact captured response hash,
and refuses replay after twelve hours. It locks affected catalogue and protected
tables during its transaction, compares protected row counts and hashes, and
verifies complete preview rollback. PostgreSQL identity sequences can advance
even when a preview rolls back; this is disclosed in the receipt. It does not
change the scheduler or the application's persistent queue/classifier settings.

The following rollback preview was executed after restoring all 86 boundaries:

```sh
php docs/reviews/2026-10-05-catalogue/events-local-replay.php --mode=preview --receipt=/private/tmp/expadu-events-catalogue-2026-10-05/replay-preview-atomic.json
```

After the parent reviewed the preview and granted local apply, this was executed:

```sh
php docs/reviews/2026-10-05-catalogue/events-local-replay.php --mode=apply --receipt=/private/tmp/expadu-events-catalogue-2026-10-05/replay-apply.json
```

These commands use the default private source snapshot path above. All actual
outbound HTTP is refused; the importer receives the complete saved response.
Processing and media-validation delivery are suppressed. The wrapper writes
pending municipal poster candidates and links venues from stored coordinates,
without acquiring photographs or translations.

## Completed replay and verification

The atomic rollback preview took **20.005 seconds**. It created the same 276
source records / 196 visible events / 48 venues within the transaction, then
proved that every catalogue and protected row returned to its prior state while
the outer locks were still held. The first preview had an inconclusive final
restoration assertion after releasing locks; its rows were rolled back, and no
cause was established. The final preview uses nested rollback and retains locks
through verification, eliminating that observation gap.

The authorised apply took **18.297 seconds**. It retained all 276 source records,
published 196, held 80, linked 48 venues and created only the missing minimal
system organiser. Media totals changed from 229 assets / 341 attachments to
309 / 480; the 80 municipal assets all have pending rights. Counts and hashes of
35 protected tables—including existing user, place and workspace data—were
identical across the locked apply. The importer used a complete HTTP response
fake; zero actual outbound requests and zero job deliveries occurred.

A post-apply readback caught a timezone serialization defect in the replay
wrapper: the UTC capture clock had initially been written into a local naive
datetime column without converting to the application's Berlin timezone. A
scoped transaction corrected only `verified_at` for the 276 newly imported rows,
from 13:15:15 local to 15:15:15 local, preserving the actual **13:15:15 UTC**
capture instant. A hash over all other event fields was unchanged. The wrapper
now converts before storage and verifies the stored instant before commit.
The database stores seconds; the original fractional capture time remains in
the private snapshot metadata.

Final native readback passed at `2026-10-05T15:48:09+02:00`:

- 196 visible events produce 196 native occurrences across the captured window;
  all 80 held rows remain hidden and need review.
- Every source start date and capture instant is preserved.
- Source 39393 occurs **5 October, 18:30–19:30**, as the official page states.
- Source 41384 occurs **7 November 18:00–8 November 01:00**, with no recurrence.
- Mixed-price source 39535 keeps its exact raw price text, `is_free = false`,
  and unknown numeric price.
- All 80 municipal candidates remain pending, with zero approvals.

Aggregate, non-private evidence is saved in
[`events-local-preview-summary.json`](events-local-preview-summary.json) and
[`events-local-apply-summary.json`](events-local-apply-summary.json).
Complete protected fingerprints, held source IDs and before/after receipts stay
in the private snapshot directory. The wrapper now refuses a further replay
because the event inventory is populated. Browser-level Events/Today/Composer
checks are owned by the parent task and are not claimed by this report.

With classification disabled, German source titles remain available, but the
card presenter only supplies a summary from English summary/description fields.
English summary/translation coverage is therefore not established. The existing
health threshold will report degraded until those translations exist; do not
relax it merely to get a green indicator.

## Media readiness

At audit start the current checkout's `PublishedMediaSelector` selected only
approved-rights, active-health assets. `EventOccurrencePresenter` uses the same
gated cascade for event poster, event hero, venue hero, then linked-place hero.
The compatibility task is separately integrating the prepared branch's stricter
source/match and alias-family checks; its completed report and tests supersede
this initial-selector description.

Both event importers capture teaser/poster candidates with rights pending.
`MediaAssetValidator` checks a permitted HTTPS provider host, response status,
MIME type, size and dimensions, then changes health only. It does not approve
rights. Stadt Köln images must remain pending without documented permission.

Existing Commons options are `spots:fetch-photos --geo=0` for linked references
and bounded `venues:fetch-photos --limit=N`. The first still queries all linked
places; it is not a total-request limit. Commons resolver requires an allowed
open licence, attribution/source evidence and publication metadata; venue
discovery additionally tries coordinate-verified entity search. These commands
are new acquisition/approval work, not passive restoration of a qualified export.
Do not run them to fill the empty review catalogue without a bounded target list
and the completed stricter media compatibility layer. Mapillary remains optional
and token-dependent; no call was made.

Prepared evidence is narrower than broad photo coverage:

- The [28 September production pack](../../places/production-pack/README.md)
  explicitly approved zero photos; place-data licensing is separate.
- The [30 September recovery report](../../places/production-release/2026-09-30-media-recovery/REPORT.md)
  records 73 refreshed source references, 65 eligible existing associations and
  8 holds in an isolated rollback trial. It added zero persistent approvals.
- That 65-photo trial covered 1.56% of 4,155 eligible Composer candidates, not
  the earlier 75% coverage objective. It is historical rehearsal evidence.
- The report references `MediaRecoveryJournal.php`, but that helper is absent
  from this checkout. Do not mistake the retained report for an executable,
  already-applied recovery pipeline. Its raw source proofs stayed server-side.

No new image rights, photo-to-place matches, current byte health, or broad event
photo coverage were established by this audit.
