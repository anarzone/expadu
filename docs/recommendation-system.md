# Recommendation System

Last updated: 2026-03-29

## Overview

The `RecommendationService` is the core intelligence engine that connects transit, weather, events, disruptions, and user behavior into smart, actionable suggestions.

## Architecture

```
WeatherService (Open-Meteo best_match)
GtfsDepartureService (GTFS static)           → RecommendationService → Dashboard
DisruptionService (KVB betriebslage + API)                            → Transit Hero
NearbyStopService (KVB + GTFS rail)                                   → Departure Boards
DiscoverySuggestionService (Spots/Events)                             → Discovery Cards
LocationPatternService (GPS tracking)                                 → Routine Suggestions
FrequentDestinationService (Visit history)                            → Fav Spot Cards
```

## Commute Context Detection

`determineCommuteContext()` uses a priority chain to decide where the user is going:

| Priority | Source | Example |
|----------|--------|---------|
| 1st | **Saved Routine** | "Home → Work" routine matches current day+hour (±1hr) |
| 2nd | **GPS Detection** | User is at Home (GPS ping near home) → suggest Work |
| 3rd | **Pattern Prediction** | SQL patterns show user goes to X every Tue at 9 |
| 4th | **Time Fallback** | Weekday morning → Work, evening → Home, weekend → off_hours |

**Off-hours detection:**
- Weekends → discovery mode
- Late night (22-05) → discovery mode
- GPS at Home during work hours → break detection → discovery mode

## Transit Hero Card (`getCommuteRecommendation`)

Returns 3 cards for the transit page highlight section:

```
Card 1: Bike route (weather-dependent)
Card 2: Transit line (GTFS departure)
Card 3: Context-aware suggestion (from DiscoverySuggestionService)
```

**Card 3 adapts by context:**
- Morning commute → tonight's event or weather alert
- At work → lunch spot or disruption alert
- Evening return → event tonight or café near home
- Off-hours → full discovery mode (activity + fav spot + event)

## Discovery Mode (`DiscoverySuggestionService`)

Three rotating card pools, each with 5 items:

| Pool | Source | Content |
|------|--------|---------|
| Activity | `Spot::nearby()` | Weather-aware: parks when sunny, cafés when rainy |
| Fav Spot | `FrequentDestinationService` | User's frequent destinations, filtered by `isActiveToday()` |
| Event | `Event` + `CityNews` | This week's events (10) + recent news (5) |

**Rotation:** RotatingCardSlot component auto-flips every 30-60 sec (random interval).

## Departure Boards

`NearbyStopService::getDeparturesByType()` splits into two boards:

| Board | Source | Radius | Data |
|-------|--------|--------|------|
| KVB (tram/bus) | `KvbApiService::getStops()` | 600m | Top 3 nearest stops, up to 8 departures |
| DB (S-Bahn/RE) | `GtfsStop` (route_type=2) | 5km | Nearest rail stations, up to 6 departures |

**Features:**
- "Your route" badge on lines heading towards predicted destination
- Disruption status per line (from DisruptionService)
- Clickable rows → detail modal with line info + active disruptions
- GPS-based: fetches from `/api/nearby-departures` when geolocation available
- Falls back to Home coordinates when GPS unavailable

## Disruption Personalization

`TransitController::buildDisruptionCards()`:
1. Gets all active disruptions from DisruptionService
2. Finds user's Home + Work nearest KVB stops → their lines
3. Cross-references: if disrupted line serves user's stops → `is_personal: true`
4. Sorts: critical first, personal boosted
5. Max 5 cards shown below "Plan a Journey"

## Routine Integration

Routines are the **#1 priority** in context detection. A matching routine overrides GPS, patterns, and time-based fallback. This means:
- Saved routine "Home → B2 Course, Tue 18:00" → at 17:30 on Tuesday, hero shows B2 Course departures
- Paused routines are skipped
- Day filtering respects routine's `days` array

**Routine suggestions:**
- `LocationPatternService::detectUnsavedRoutines()` detects patterns with 3+ frequency
- Skips if user has < 5 tracking events (insufficient data)
- Dismissals persist via localStorage for 1 hour cooldown

## Data Sources

| Source | Status | Service | Freshness |
|--------|--------|---------|-----------|
| Weather (current + forecast) | ✅ | `WeatherService` | 5-min cache |
| GTFS departures | ✅ Static | `GtfsDepartureService` | 1-min cache |
| KVB stops + lines | ✅ | `KvbApiService` | 1-hour cache |
| Line disruptions (betriebslage) | ✅ | `DisruptionService` | Every 5 min scrape |
| Planned works (aktuelles) | ✅ | `DisruptionService` | Every 5 min scrape |
| Elevator/escalator | ✅ | `KvbApiService` | 2-min cache |
| Events | ✅ | `Event` model | Every 30 min scrape |
| User places + arrive_by | ✅ | `UserPlace` model | Real-time |
| GPS location | ✅ | `useGeolocation` hook | Every 5 min |
| Tracking events | ✅ | `user_tracking_events` | Real-time |
| Real-time delays | ⏳ | Pending VRS GTFS-RT | — |

## Future Enhancements

- VRS GTFS-RT for real-time departure delays
- OSRM/Valhalla for real travel times → smart leave-by calculation
- Push notifications triggered by routine departure times
- Route drawing on map
- Learning from user behavior (which routes taken, events attended)
