# Expadu — Resources & Data Sources

Last updated: 2026-03-29

## Status Legend
- ✅ **Active** — integrated and working
- ⏳ **Pending** — code ready, waiting on external approval/access
- 🔧 **Partial** — built, results vary by source availability
- 📋 **Planned** — not yet implemented

---

## 1. Weather

| Resource | Status | Service | Details |
|----------|--------|---------|---------|
| Open-Meteo best_match | ✅ Active | `WeatherService` | Current + hourly forecast. Free, no API key. 5-min cache. |

**API:** `https://api.open-meteo.com/v1/forecast`
**Params:** `latitude`, `longitude`, `current=temperature_2m,humidity,...`, `hourly=precipitation,...`, `timezone=Europe/Berlin`
**Note:** Switched from DWD ICON (`/v1/dwd-icon`) to best_match (`/v1/forecast`) for accurate wind speed. Cloud cover override when precip < 0.5mm.

---

## 2. Transit — KVB & Regional Rail

| Resource | Status | Service | Details |
|----------|--------|---------|---------|
| VRS GTFS Static | ✅ Active | `GtfsDepartureService` | 7,025 stops, 611 routes, ~2M stop times. Calendar + calendar_dates filtering. |
| VRS GTFS-RT | ⏳ Pending | — | Real-time delays. Email sent to api@vrs.de. |
| KVB Open Data — Stops | ✅ Active | `KvbApiService` | 2,155 stops with line assignments. 1-hour cache. |
| KVB Open Data — Elevators | ✅ Active | `KvbApiService` | Live elevator disruptions. 2-min cache. |
| KVB Open Data — Escalators | ✅ Active | `KvbApiService` | Live escalator disruptions. 2-min cache. |
| KVB Betriebslage | ✅ Active | `ScrapeNews` | Per-line disruption status. HTML scraping every 5 min. |
| KVB Aktuelles | ✅ Active | `ScrapeNews` | Planned works with date ranges. HTML scraping every 5 min. |
| GTFS Nearby Departures | ✅ Active | `NearbyStopService` | KVB tram/bus + S-Bahn/RE split. GPS-based with API endpoint. |
| GTFS Stop Search | ✅ Active | `GtfsDepartureService::searchStops()` | API: `GET /api/stops?q=` |

**GTFS Source:** `https://download.vrsinfo.de/gtfs/GTFS_VRS_mit_SPNV.zip`
**KVB API:** `https://data.webservice-kvb.koeln/service/opendata`
**KVB Betriebslage:** `https://www.kvb.koeln/fahrtinfo/betriebslage/index.html`
**KVB Aktuelles:** `https://www.kvb.koeln/aktuelles/`

---

## 3. Maps & Geocoding

| Resource | Status | Service | Details |
|----------|--------|---------|---------|
| MapLibre GL JS v5 | ✅ Active | `map-view.tsx` | Open-source map renderer, MIT license. |
| OpenFreeMap tiles | ✅ Active | `bright` style | Free, no API key, no rate limits. |
| Photon (Komoot) | ✅ Active | `GeocodingService` | Address autocomplete. 5-min cache. `GET /api/geocode?q=` |
| Overpass API (OSM) | ✅ Active | `ImportOsmSpots` | POI import: cafes, coworking, libraries, parks. |
| Overpass API (OSM) | ✅ Active | `ImportOsmServices` | Services: doctors, pharmacies, dentists, banks, lawyers, etc. |

**Photon:** `https://photon.komoot.io/api/`
**Overpass:** `https://overpass.kumi.systems/api/interpreter`

---

## 4. Events & News Scrapers

| Resource | Status | Command | Schedule | Details |
|----------|--------|---------|----------|---------|
| koeln.de Events API | ✅ Active | `events:scrape` | Every 30 min | `wp-json/tribe/events/v1/events`. 2,400+ events. |
| stadt-koeln.de Calendar | 🔧 Partial | `events:scrape` | Every 30 min | HTML scraping. Lower quality (score 0.2). |
| Event Enrichment | ✅ Active | `events:enrich` | Every 15 min | Geocoding, quality scoring, expat relevance, tags. |
| KVB Betriebslage | ✅ Active | `news:scrape` | Every 5 min | Real-time per-line disruptions. 44 items on typical run. |
| KVB Aktuelles | ✅ Active | `news:scrape` | Every 5 min | Planned works with date ranges + affected lines. |
| Stadt Köln Press | 🔧 Partial | `news:scrape` | Every 5 min | Press releases. Category + relevance filtering. |
| Meetup.com | 📋 Planned | — | — | Cologne expat group events. |
| Eventbrite | 📋 Planned | — | — | Cologne events with API. |

---

## 5. Disruption System

| Source | Type | Service | Freshness |
|--------|------|---------|-----------|
| KVB Betriebslage scraping | Line disruptions (tram + bus) | `DisruptionService` via `CityNews` | Every 5 min |
| KVB Aktuelles scraping | Planned works (date ranges) | `DisruptionService` via `CityNews` | Every 5 min |
| KVB Open Data API | Elevator/escalator outages | `DisruptionService` via `KvbApiService` | 2-min cache |

**Personalization:** `TransitController::buildDisruptionCards()` checks user's Home/Work stop lines against disrupted lines.

---

## 6. Recommendation System

| Service | Status | Details |
|---------|--------|---------|
| `RecommendationService` | ✅ Active | Core engine: weather + transit + events + disruptions → ranked cards. |
| `DiscoverySuggestionService` | ✅ Active | Discovery mode: activity/fav spot/event pools with rotation. |
| `LocationPatternService` | ✅ Active | SQL pattern detection from tracking data. Routine suggestions. |
| `FrequentDestinationService` | ✅ Active | Weighted scoring: spot_checkin 3x, journey_planned 2x. |
| `NearbyStopService` | ✅ Active | KVB + rail station departures. GPS-based with destination prediction. |

**Commute context priority:** Routine (1st) → GPS detection (2nd) → Pattern prediction (3rd) → Time fallback (4th)

---

## 7. Authentication & Push

| Resource | Status | Package | Details |
|----------|--------|---------|---------|
| Laravel Fortify | ✅ Active | `laravel/fortify` | Login, register, password reset, email verify, 2FA. |
| Web Push (VAPID) | ✅ Active | `laravel-notification-channels/webpush` | Self-hosted, no third-party. |
| PWA Service Worker | ✅ Active | `vite-plugin-pwa` | Offline support, workbox caching. |

---

## 8. Analytics & Tracking

| Resource | Status | Service | Details |
|----------|--------|---------|---------|
| Custom event tracking | ✅ Active | `EventTrackingService` | `user_events` table. Auto spot_proximity detection. |
| Geolocation hook | ✅ Active | `useGeolocation` | GPS pings every 5 min. Feeds location tracking + nearby departures. |
| Umami (self-hosted) | 📋 Planned | — | Page analytics, no cookies. |
| Laravel Pennant | 📋 Planned | — | Feature flags. |

---

## 9. Database & Infrastructure

| Resource | Status | Details |
|----------|--------|---------|
| PostgreSQL 16 + PostGIS | ✅ Active | Docker via OrbStack (dev). Hetzner CX41 (prod planned). |
| Redis Alpine | ✅ Active | Cache, sessions, queue. |
| Laravel Horizon | 📋 Planned | Queue dashboard. |
| Laravel Reverb | 📋 Planned | WebSocket for real-time updates. |

---

## 10. Scheduler Summary

```
news:scrape          → everyFiveMinutes()       (KVB betriebslage + aktuelles + stadt-koeln press)
events:scrape        → everyThirtyMinutes()     (koeln.de API + stadt-koeln calendar)
events:enrich        → everyFifteenMinutes()    (geocoding, quality scoring, tags)
gtfs:refresh         → weeklyOn(1, '03:00')     (VRS GTFS timetable download)
```

Run with: `php artisan schedule:work` (dev, included in `composer run dev`) or cron (prod)

---

## 11. AI Services

| Resource | Status | Details |
|----------|--------|---------|
| Claude API (letter translation) | 📋 Planned | Bureaucracy AI Translator tab. claude-sonnet-4-5. |
| ML Recommendations (Gorse) | 📋 Planned | Deferred until 500+ users. |
| OSRM/Valhalla routing | 📋 Planned | Real travel times for leave-by calculation + route drawing. |
