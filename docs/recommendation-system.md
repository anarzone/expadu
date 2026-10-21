# Recommendation System

Last updated: 2026-10-21 (Today's discovery, EXP-170; rewritten for EXP-119)

The 29 March version of this page described `RecommendationService`,
`DiscoverySuggestionService`, `LocationPatternService`,
`FrequentDestinationService`, saved routines and the transit hero cards. All of
these were removed in the June pivot. That version is in git history if you
need it.

For the full picture, gaps and proposed next steps, read the two October 2026
architecture pages:

- [AI data readiness and recommendation/notification/Today groundwork — Oct 2026](https://docs.anar.to/books/architecture/page/ai-data-readiness-and-recommendationnotificationtoday-groundwork)
- [Performance and efficiency review — Oct 2026](https://docs.anar.to/books/architecture/page/performance-and-efficiency-review-oct-2026)

## Where recommendations come from today

There is no shared recommendation engine. There are four separate
deterministic rankers, and they share no model:

| Surface | Code | How it ranks |
|---|---|---|
| Today's discovery | `app/Home/TodayDiscovery.php`, `app/Places/OpenNearby.php` | No taste ranking. Tonight's listed events that are still to come and don't clash with a plan, soonest first: two posters, then three compact listings. Near the next plan with a place: your own saved idea in that area first, else an event listed there after it. Then up to three everyday places (bakery, café, pharmacy, grocery) whose listed hours say they're open when the plan ends, nearest first within 1 km. Nothing is cached. |
| Events, "recommended" sort | `Api/EventsController`, `EventController` | LLM relevance first, then proximity. Not personalised. Today uses the default sort. |
| Composer plans | `app/Composer/PlanScorer.php` | 13 weighted terms. The optional LLM ranker feeds `llm_rank` (weight 45, the largest term); see [composer-design.md](composer-design.md). |
| Places list | `app/Services/NearbyPlaces.php` | Distance only. |

Every surface draws from the place catalogue in `app/Places/`
(`PlaceFacts`, `PlaceIdentity`, `DestinationGrouping`). A place is offered only
if the facts there say it may be publicly recommended.

## Taste signals

`app/Composer/IntentWeights.php` turns `user_events` rows from the last 90 days
into category × Veedel counts. Every request recomputes them; nothing is stored
as a profile. Composer reads them; Today's discovery doesn't.

It weights 11 signal types. Since the 6 October UI rewrite, only the four
Places feedback signals still have a live emitter:

- `spot_more_like_this`
- `spot_saved`
- `spot_not_interested`
- `spot_been_liked` / `spot_been_disliked`

These signals are weighted but have nothing writing them on this branch:
`take_me_there` (the strongest, 3.0), `event_saved`, `spot_viewed`, the
post-trip thumbs and `composer_swap_away`. EXP-109 restores them as server-side
emitters.

The browser sends only `page_viewed`, the onboarding events and location pings
to `POST /api/track` (`resources/js/hooks/use-tracker.ts`,
`use-geolocation.ts`).

## Departure boards

`NearbyStopService::getDeparturesByType()` builds the "nearby" boards behind
`GET /api/nearby-departures`:

- **KVB (tram and bus):** the 3 nearest KVB stops within 600 m. It asks
  `GtfsDepartureService` for 15 departures per stop and keeps one row per line
  and direction.
- **DB (S-Bahn, RE, RB):** GTFS rail stations within 5 km.
- **Cache:** results are cached for 30 seconds on a grid of about 100 m, keyed
  by origin and destination.
- **"Towards" badge:** when a destination is given, lines that also serve the
  KVB stop nearest the destination get the badge.
- **Disruptions:** disrupted lines are marked from `DisruptionService`.

Real-time delays come from VRS GTFS-RT through `VrsRealtimeService`. It is off
by default (`VRS_REALTIME_ENABLED=false`).

## What is not built

These are covered in the AI data readiness page, with tickets:

- No record of what a user was shown, so there are no impressions, request ids
  or ranker versions (EXP-110).
- No stored taste profile, no embeddings, no full-text search.
- No single ranker mixing familiar and new places (EXP-114).
- No materialised place features or shared interest taxonomy (EXP-115).
- Routines and leave-by reminders were dropped in the June pivot. Only
  `user_places.arrive_by` and `active_days` remain.
