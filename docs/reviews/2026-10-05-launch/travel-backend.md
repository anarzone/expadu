# Travel reliability implementation — 5 October 2026

## Scope

Implemented against the actual Laravel/Inertia app. The approved Travel prototype remains a design reference; no prototype records or scripted vehicle positions were introduced into production paths. No commit or deployment was performed.

## Changes

### Requested route times and strict dates

- `app/Transit/TriasAdapter.php` preserves the provider's full dated, offset-bearing trip and leg timestamps. Clock-only results, impossible dates and reversed transfer chronology are refused instead of being placed on today's date.
- `app/Services/VrsTriasService.php` exposes full trip and leg timestamps alongside the existing display times. Relevant caches are versioned.
- `app/Transit/FailoverRouteService.php` skips the legacy TRIAS adapter for explicit depart-at/arrive-by requests. That client does not send a requested time, so it cannot honestly satisfy those searches. Existing providers still receive the requested time; an unsupported fallback no longer silently returns a leave-now journey or opens a provider circuit.
- `app/Transit/DepartureTime.php` validates exact dated, offset-bearing timestamps for the new departure and trip-start paths. Relative strings and date rollover are rejected.

### Individual departures, observation age and stop identity

- `app/Services/VrsTriasService.php` and `app/Services/GtfsDepartureService.php` add individual `services[]` without removing the legacy grouped minute arrays. Each service has a stable ID, exact departure, schedule/estimate distinction, cancellation status and boarding evidence when provided.
- GTFS rows retain the actual matching `stop_id`; unrelated `MIN(time)`/`MIN(stop_id)` values can no longer be paired. Overnight hours such as `25:10` stay attached to their service date, including the previous service day after midnight. Tomorrow's first services use tomorrow's calendar and calendar exceptions.
- `app/Services/VrsRealtimeService.php` retains each trip update's observation timestamp, falling back only to the feed timestamp. Missing/future timestamps remain unknown. The service date is preserved when supplied, preventing today's delay from being applied to tomorrow's instance of a trip.
- Delay seconds are retained for exact timestamps; only the display delay is rounded to minutes.
- `app/Services/TimetableService.php` preserves the original observation time through warm-cache and last-good fallback reads. Cached countdowns age. Exact dated services are authoritative, so a departed service cannot reappear as a legacy zero-minute row. Newer duplicate observations replace older cancellation/estimate state.
- Nearby tram/bus boards can resolve to different stops. The combined board now exposes `scope: nearby` and all real stop areas, with actual stop names on departure rows. The frontend must not label this combination as one confirmed station/platform.

### Active journey and location privacy

- `app/Http/Requests/StartTripRequest.php` validates overall and per-leg times before writing. Ended routes, invalid chronology and already-passed first transit boardings are rejected. A rejected start preserves the previous active trip.
- `app/Http/Controllers/Api/TripController.php` permits an identical still-active journey to resume without resetting `started_at` or duplicating scheduled reminders.
- `resources/js/hooks/use-trip-progress.ts` rejects stale, future, invalid or inaccurate fixes, stops watching while hidden/inactive/opted out, and ignores callbacks from disposed watches. The same validated fix is exposed as `TripProgress.position` for the journey map, without a second GPS watch. It disappears when tracking is disabled, hidden, stale or attached to a different route. Route changes reset progress. A genuine location fix can correct schedule-derived progress for a late trip.
- `resources/js/hooks/use-geolocation.ts` and `use-background-location.ts` use the reactive shared location preference. Disabling sharing clears GPS state, stops watches/timers and aborts in-flight uploads; late callbacks and permission responses cannot restart them.
- `app/Services/EventTrackingService.php` rechecks the stored setting on receipt of a location ping. Opted-out pings do not create location events, Redis history, proximity events or neighbourhood updates.

## Additive response contract

Board fields:

```text
fetched_at: ISO timestamp | null       original observation, not response time
generated_at: ISO timestamp          anchor for returned legacy countdown minutes
is_stale: boolean
time_basis: realtime | scheduled | stale | unavailable
stop: { id, name, lat, lng, coordinate_kind: stop_area, boarding_confirmed: false }
stops: stop[]
scope: stop | nearby
```

Each grouped departure retains its original fields and adds `stop_name`, `stop`, and `services[]`:

```text
id: string
departure_at: ISO timestamp
scheduled_at: ISO timestamp | null
expected_at: ISO timestamp | null
time_basis: realtime | scheduled | stale
cancelled: boolean
delay: number                         display minutes
boarding_point: { id, name, platform } | null
stop_name: string
```

GTFS services also retain `observed_at`. IDs and boarding fields reflect available source data. Unknown platforms stay null. KVB map coordinates are stop-area markers, not verified boarding platforms or vehicle positions. A consumer should prefer individual services when the array is present; legacy relative minutes remain a compatibility fallback.

## Verification

Runtime: Herd PHP 8.4.23 and Node 22.23.1.

- `php artisan test --compact tests/Feature/Timetable tests/Feature/Trip tests/Feature/Location/LocationSharingOptOutTest.php tests/Feature/VrsRealtimeServiceTest.php tests/Feature/Transit/FailoverTest.php`: **90 passed, 437 assertions**.
- `php artisan test --compact tests/Unit/Transit`: **38 passed, 117 assertions**.
- `node --experimental-strip-types --test tests/Frontend/trip-progress.test.mjs tests/Frontend/location-sharing.test.mjs tests/Frontend/trip-request.test.mjs`: **18 passed**. The request tests belong to the coordinated UI work.
- Focused hook ESLint/Prettier and changed-PHP Pint passed. Parent launch verification owns the integrated frontend build and visual review.

Regressions include midnight/date rollover, calendar exceptions, exact delay seconds, requested-time fallback, old cache age, unknown feed timestamp, individual cancellation, newer duplicate observations, nearby stop identity, stale itinerary rejection, idempotent resume, GPS accuracy/age/route reset, location opt-out and late callbacks.

## Remaining external verification and capability limits

- No live-provider outage exercise or deployed-feed certification was performed here. Provider credentials, feed freshness/coverage and staging data still need environment verification.
- Browser location is foreground GPS assistance. Reliable background tracking while the browser/app is suspended is not guaranteed. Real-device permission denial, revocation, poor GPS and resume must be checked on the supported iOS/Android browsers.
- The app does not receive live vehicle positions through these changes. A map marker must not imply a moving vehicle or verified platform.
- Existing get-off push reminders use the selected itinerary's scheduled arrival times. They are not automatically re-timed by subsequent live delay updates. Push delivery with the app closed still needs device verification.
- Existing automatic replanning/rerouting was not added. A missed or rejected departure needs a fresh route search.
- The TRIAS legacy fallback intentionally remains unavailable for explicit dated/arrive-by searches until its request capability is implemented and verified.
- The coordinated Travel UI work now guards its independent automatic/follow location controls with the same reactive sharing preference and aborts late callbacks. The integrated desktop/mobile opt-out browser check passes even with browser permission granted; see `travel-ui.md`.
