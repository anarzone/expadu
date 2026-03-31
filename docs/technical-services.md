# Expadu — Technical Services Reference

Last updated: 2026-03-29

## External API Calls

### 1. Open-Meteo Weather API
- **URL:** `https://api.open-meteo.com/v1/forecast`
- **Service:** `app/Services/WeatherService.php`
- **Method:** GET
- **Params:** latitude, longitude, current (temperature, humidity, precipitation, weather_code, wind_speed, wind_direction, cloud_cover), hourly (precipitation, temperature_2m, wind_speed_10m), timezone, forecast_days=1
- **Cache:** 5 minutes per lat/lng
- **Timeout:** 5 seconds, 2 retries
- **Auth:** None (free)
- **Used by:** RecommendationService, transit page hero, bike score

### 2. KVB Open Data API
- **Base URL:** `https://data.webservice-kvb.koeln/service/opendata`
- **Service:** `app/Services/KvbApiService.php`
- **Auth:** None (free)
- **Timeout:** 15 seconds
- **Encoding:** Auto-converts ISO-8859-1 → UTF-8

| Endpoint | Cache | Returns |
|----------|-------|---------|
| `GET /haltestellen/json` | 1 hour | 2,155 stops with id, name, lat, lng, lines[] |
| `GET /aufzugsstoerung/json` | 2 min | Elevator outages with stop names + timestamps |
| `GET /fahrtreppenstoerung/json` | 2 min | Escalator outages with stop names + labels |

### 3. Photon Geocoding API
- **URL:** `https://photon.komoot.io/api/`
- **Service:** `app/Services/GeocodingService.php`
- **Method:** GET
- **Params:** q (query), lat, lon (bias), limit=5, lang=en
- **Cache:** 5 minutes per query+coords
- **Timeout:** 3 seconds
- **Auth:** None (free, fair use)
- **Used by:** GeocodeController (address search), EventEnrichmentService (venue geocoding)

### 4. VRS GTFS Static Feed
- **URL:** `https://download.vrsinfo.de/gtfs/GTFS_VRS_mit_SPNV.zip`
- **Command:** `php artisan gtfs:refresh`
- **Schedule:** Weekly, Monday 3:00 AM
- **Timeout:** 120 seconds
- **Tables populated:** gtfs_stops (7,025), gtfs_routes (611), gtfs_trips, gtfs_stop_times (~2M), gtfs_calendar, gtfs_calendar_dates
- **Processing:** Downloads ZIP, extracts CSVs, bulk inserts. Date format YYYYMMDD → YYYY-MM-DD conversion.

### 5. Overpass API (OpenStreetMap)
- **URL:** `https://overpass.kumi.systems/api/interpreter`
- **Commands:** `php artisan osm:import-spots`, `php artisan osm:import-services`
- **Method:** POST with Overpass QL queries
- **Timeout:** 90 seconds
- **Rate limiting:** 2-3 second delays between queries
- **Schedule:** Manual (one-time import, re-run as needed)
- **Spots imported:** Cafes, coworking, libraries, parks (Cologne inner city bbox)
- **Services imported:** Doctors, pharmacies, dentists, banks, lawyers, tax advisors, insurance

---

## HTML Scrapers

### 6. KVB Betriebslage (Live Disruptions)
- **URL:** `https://www.kvb.koeln/fahrtinfo/betriebslage/index.html`
- **Command:** `php artisan news:scrape` (first source)
- **Schedule:** Every 5 minutes
- **Parsing:** Regex for `<li class="list-group-item linieXX bahn/bus">`, extracts line from class, title from `<b>`, details from `.weitereinfos`
- **Storage:** `city_news` table, source=`kvb-betriebslage`, expires in 3 days
- **Upsert:** Updates existing entries on re-scrape (live status)
- **Last run:** 44 disruptions (tram lines 1, 7, 9, 16, 18 + ~20 bus lines)

### 7. KVB Aktuelles (Planned Works)
- **URL:** `https://www.kvb.koeln/aktuelles/`
- **Command:** `php artisan news:scrape` (second source)
- **Schedule:** Every 5 minutes
- **Parsing:** Regex for `<h[234]>` headings, filters for transit keywords (Linie, Trennung, Sperrung, etc.)
- **Line extraction:** "Linien 1 + 9", "Bus 136", "S12" patterns
- **Date extraction:** "DD.MM. - DD.MM.YYYY" format → published_at/expires_at
- **Severity:** critical (Sperrung), major (Trennung/Umleitung/Sanierung), minor (default)
- **Storage:** `city_news` table, source=`kvb`, category=`transit`

### 8. Stadt Köln Press
- **URL:** `https://www.stadt-koeln.de/politik-und-verwaltung/presse/mitteilungen`
- **Command:** `php artisan news:scrape` (third source)
- **Schedule:** Every 5 minutes
- **Parsing:** Regex for `<a>` links to press releases
- **Categorization:** transit, regulation, event, culture, general
- **Relevance:** expat, transit, bureaucracy, all, skip
- **Storage:** `city_news` table, source=`stadt-koeln`, expires in 14 days

### 9. koeln.de Events API
- **URL:** `https://www.koeln.de/wp-json/tribe/events/v1/events`
- **Command:** `php artisan events:scrape`
- **Schedule:** Every 30 minutes
- **Params:** per_page=50, start_date=today, end_date=+30 days
- **Storage:** `events` table with title, dates, venue, address, description, category

### 10. Stadt Köln Events Calendar
- **URL:** `https://www.stadt-koeln.de/leben-in-koeln/freizeit-natur-sport/veranstaltungskalender/`
- **Command:** `php artisan events:scrape`
- **Schedule:** Every 30 minutes
- **Parsing:** HTML scraping, filters for parseable dates (DD.MM.YYYY)
- **Quality:** Low score (0.2) — less structured data

---

## Internal Services (No External Calls)

### 11. GtfsDepartureService
- **File:** `app/Services/GtfsDepartureService.php`
- **Source:** PostgreSQL GTFS tables
- **Cache:** 1 minute per stop+limit
- **Methods:**
  - `getDepartures(stopName, limit)` — grouped by line+direction, calendar filtered, deduplicated by trip_id
  - `getDeparturesNearby(lat, lng, limit)` — finds nearest GTFS stop by haversine
  - `searchStops(query, limit)` — ILIKE search on stop names

### 12. NearbyStopService
- **File:** `app/Services/NearbyStopService.php`
- **Methods:**
  - `getWalkableStops(lat, lng, 600m)` — KVB stops within walking distance
  - `getNearbyRailStations(lat, lng, 5000m)` — GTFS rail stations (S-Bahn/RE), cached 5 min
  - `getDeparturesByType(lat, lng, ...)` — splits into KVB (tram/bus) + DB (rail), top 3 KVB stops + nearest rail stations
  - `predictDestination(user, lat, lng)` — time + location based destination prediction

### 13. DisruptionService
- **File:** `app/Services/DisruptionService.php`
- **Cache:** 2 minutes
- **Aggregates:**
  - Line disruptions from `city_news` table (scraped KVB betriebslage + aktuelles)
  - Accessibility disruptions from KVB Open Data API (elevators + escalators)
- **Methods:**
  - `getActiveDisruptions()` — all combined
  - `getLineDisruptions()` — transit news only
  - `getDisruptedLines()` — quick lookup: line → severity
  - `getStopAccessibilityIssues(stopName)` — elevator/escalator issues at a stop

### 14. RecommendationService
- **File:** `app/Services/RecommendationService.php`
- **Aggregates:** WeatherService + GtfsDepartureService + DisruptionService + DiscoverySuggestionService + LocationPatternService + FrequentDestinationService
- **Methods:**
  - `getCommuteRecommendation(user)` — transit hero card with bike/transit/discovery options
  - `determineCommuteContext(user, home, work)` — priority: Routine → GPS → Pattern → Time fallback
  - `buildDashboardFeed(user)` — unified feed for dashboard

### 15. DiscoverySuggestionService
- **File:** `app/Services/DiscoverySuggestionService.php`
- **Methods:**
  - `getFullDiscovery(user, lat, lng)` — 3 rotating card pools (activity, fav spot, event)
  - `buildActivityPool()` — weather-aware nearby spots from Spot DB
  - `buildFavPool()` — from FrequentDestinationService, filtered by isActiveToday()
  - `buildEventPool()` — this week's events (limit 10) + news (limit 5)
  - `getContextCard()` — smart Card 3 for commute: morning→event, lunch→food, evening→attending

### 16. LocationPatternService
- **File:** `app/Services/LocationPatternService.php`
- **Cache:** 1 hour per user
- **Source:** `user_tracking_events` table (SQL aggregation, no ML)
- **Methods:**
  - `getRegularPatterns(user)` — day+hour grouped, min 2 occurrences
  - `predictCurrentDestination(user)` — matches current day+hour ±1hr
  - `detectCurrentLocation(user)` — compares latest GPS ping to saved places
  - `detectUnsavedRoutines(user)` — patterns with 3+ frequency, not yet saved as routines

### 17. FrequentDestinationService
- **File:** `app/Services/FrequentDestinationService.php`
- **Cache:** 1 hour per user
- **Scoring:** spot_checkin 3x, journey_planned 2x
- **Filters:** Excludes Home/Work, excludes departure_viewed (not physical visits)

---

## API Endpoints (Frontend)

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `/api/geocode?q=` | GET | Yes | Address search via Photon |
| `/api/stops?q=` | GET | Yes | KVB/GTFS stop search |
| `/api/spots?...` | GET | Yes | Nearby spots search |
| `/api/track` | POST | Yes | User event tracking |
| `/api/route-options?to_lat=&to_lng=` | GET | Yes | Route comparison (bike/transit/walk) |
| `/api/nearby-departures?lat=&lng=` | GET | Yes | GPS-based nearby departures |

---

## Cache Duration Summary

| Data | Duration | Reason |
|------|----------|--------|
| KVB stops | 1 hour | Rarely change |
| KVB elevator/escalator | 2 min | Real-time status |
| Active disruptions | 2 min | Critical for users |
| Weather | 5 min | Changes slowly |
| Geocoding | 5 min | Same queries repeated |
| GTFS departures | 1 min | Time-sensitive |
| Rail stations nearby | 5 min | Geographic, stable |
| GTFS stop search | 5 min | Stable data |
| User patterns | 1 hour | Slow-changing behavior |
| Frequent destinations | 1 hour | Slow-changing behavior |

---

## Scheduler (composer run dev)

```
┌─────────────────────┬──────────────────────┬──────────────────────────────────────┐
│ Schedule            │ Command              │ Sources                              │
├─────────────────────┼──────────────────────┼──────────────────────────────────────┤
│ Every 5 minutes     │ news:scrape          │ KVB betriebslage + aktuelles +       │
│                     │                      │ stadt-koeln press                    │
├─────────────────────┼──────────────────────┼──────────────────────────────────────┤
│ Every 15 minutes    │ events:enrich        │ Geocoding, quality scoring, tags     │
├─────────────────────┼──────────────────────┼──────────────────────────────────────┤
│ Every 30 minutes    │ events:scrape        │ koeln.de API + stadt-koeln calendar  │
├─────────────────────┼──────────────────────┼──────────────────────────────────────┤
│ Weekly Mon 3:00 AM  │ gtfs:refresh         │ VRS GTFS timetable ZIP download      │
└─────────────────────┴──────────────────────┴──────────────────────────────────────┘
```

All scheduled via `php artisan schedule:work` (auto-starts with `composer run dev`).
