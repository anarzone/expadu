# Expadu — External APIs & Services Decisions

Last updated: 2026-03-29

---

## Transit

### Primary: VRS Open Data

- **What:** Live KVB departures (GTFS-RT), route planning (TRIAS), service alerts
- **Cost:** Free (requires signing user agreement)
- **Registration:** Contact VRS via https://www.vrs.de/en/future-mobility/for-companies/open-data-service
- **Coverage:** All KVB lines — U-Bahn, trams, S-Bahn, buses in Cologne and VRS region
- **Action required:** Register ASAP — manual process, may take days

### Backup: DELFI/Mobilithek (Germany-wide)

- **What:** Federal aggregation of all German transit data (GTFS-RT, SIRI, NeTEx)
- **Portal:** https://mobilithek.info
- **Cost:** Free with registration
- **Use for:** Fallback if VRS is down; expansion to Berlin/Munich/Hamburg/Frankfurt

### Supplement: Deutsche Bahn API

- **What:** S-Bahn and regional rail real-time data (HAFAS-based)
- **Portal:** https://developers.deutschebahn.com
- **Cost:** Free tier (10–100 req/min)
- **Use for:** S-Bahn lines and regional connections only

### Static Fallback: VRS GTFS on Open NRW

- **What:** Daily-updated GTFS static timetable data
- **Source:** https://ckan.open.nrw.de/en/dataset/vrs-verkehrsdaten-gtfs-k
- **Cost:** Free (Datenlizenz Deutschland Zero v2.0)
- **Use for:** Offline timetable display, stop data import

### Rejected: GTFS.de

Unreliable update frequency, community-maintained. DELFI/Mobilithek is superior.

---

## Maps

### Rendering: MapLibre GL JS

- **Website:** https://maplibre.org
- **Cost:** Free (MIT license, no API key)
- **Use for:** All map views — Explore, Transit, Neighborhoods

### Tiles (MVP): MapTiler

- **Website:** https://www.maptiler.com
- **Free tier:** 100,000 tile requests/month
- **Paid:** From $25/month beyond free tier
- **Why chosen:** EU company (Czech Republic, GDPR-friendly), first-class MapLibre support, visual style editor
- **Registration:** https://cloud.maptiler.com

### Tiles (Production): Protomaps on Cloudflare R2

- **What:** Self-hosted PMTiles — entire map in a single file via HTTP range requests
- **Cost:** ~$0.15/month (Cloudflare R2 storage, zero egress)
- **Why chosen:** Perfect GDPR (no third-party), near-zero cost, no API key
- **When to migrate:** When MapTiler free tier becomes insufficient

### Geocoding: Photon (by Komoot)

- **Public API:** https://photon.komoot.io
- **What:** Geocoder with native autocomplete/typeahead — built for search-as-you-type UX
- **Cost:** Free (public API, fair use)
- **Why chosen over Nominatim:** Nominatim lacks autocomplete. Photon is German-made (Komoot, Potsdam), has native typeahead, excellent German address data.
- **Self-host option:** Available for production scale

### Walking Routing: Valhalla (self-hosted)

- **Website:** https://github.com/valhalla/valhalla
- **What:** Multi-modal routing engine with purpose-built pedestrian routing
- **Cost:** Free (open source)
- **Why chosen over OSRM:** Valhalla understands sidewalks, stairs, crossings, surface types. Built-in isochrone support ("what's within 15 min walk"). Runtime-customizable without reprocessing data.

---

## Weather

### Primary: Open-Meteo (best_match model)

- **API:** https://api.open-meteo.com/v1/forecast
- **What:** Multi-model blend (best_match) — most accurate for German weather
- **Cost:** Free for non-commercial (10,000 calls/day), from EUR 15/month commercial
- **Use for:** Current conditions + hourly forecast for Cologne
- **Note:** Switched from DWD ICON (`/v1/dwd-icon`) to best_match (`/v1/forecast`) for accurate wind speed. Cloud cover override when precip < 0.5mm but code says rain.

### Rejected: Bright Sky

JSON wrapper around DWD data, but less accurate wind speed than Open-Meteo's multi-model blend.

### Rejected: OpenWeatherMap

Not DWD-native, requires API key + credit card, rate-limited.

---

## Analytics & Behavior Tracking

### Page Analytics: Umami (self-hosted)

- **Website:** https://umami.is
- **What:** Lightweight analytics dashboard — custom events, funnels, no cookies
- **Resources:** ~500MB RAM, single container, shares PostgreSQL with app
- **Cost:** Free (MIT license)
- **Why chosen over PostHog:** PostHog self-hosted needs 10+ containers, 12–14 GB RAM (ClickHouse + Kafka + Zookeeper). Won't fit on CX41 alongside the app. Umami is 20x lighter.
- **GDPR:** Cookie-free, no personal data, no consent banner needed

### Feature Flags: Laravel Pennant

- **What:** First-party Laravel feature flag package
- **Cost:** Free (ships with Laravel)
- **Why chosen over PostHog flags:** Zero infra, native Eloquent/Inertia integration, percentage rollouts + user targeting
- **Limitation:** No A/B experiment analytics (statistical significance) — acceptable for MVP

### Business Events: Custom `user_events` table

- **What:** Append-only PostgreSQL table — `user_id`, `event_type`, `payload` (JSONB), `session_id`, `created_at`
- Already defined in product spec. Used for domain-specific tracking (task_completed, spot_checked_in, etc.)
- **Cost:** Zero additional infra

### ML Recommendations: Deferred

- Product spec already defines fallback scoring for < 50 users
- Gorse deferred until ~500+ users with real behavioral data
- HomeCardService ships with hard rules + static scores from spec

---

## Bürgeramt Slot Monitoring

### System: NetAppoint (HTML scraping)

- **Portal:** `https://termine-online.stadt-koeln.de/index.php?company=stadtkoeln`
- **No official API exists.** NetAppoint is a multi-step HTML form.
- **Approach:** Scheduled Laravel job scrapes available slots via HTTP POST + HTML parsing
- **Reference:** https://github.com/defgsus/office-schedule-scraper (Python, supports Cologne's NetAppoint)
- **Rate limiting:** Poll every 3+ minutes to be respectful
- **Legal:** Low-moderate risk — appointment slot data is non-personal. Multiple services (terminator.koeln, terminli.de) have operated for years.

---

## Authentication

### Laravel Fortify (already scaffolded)

- Session-based auth via Inertia (not API tokens)
- Features: login, register, password reset, email verification, 2FA (TOTP + recovery codes)
- **NOT using Laravel Sanctum** — Sanctum is for API token auth, not needed with Inertia's session-based approach

### Social Login: Deferred

- WorkOS AuthKit (free up to 1M MAU) — potential future addition for "Sign in with Google"

---

## Push Notifications

### Web Push (VAPID Protocol)

- **Laravel package:** https://github.com/laravel-notification-channels/webpush
- **Cost:** Free — self-hosted, no third-party service
- **Key generator:** https://vapidkeys.com

---

## AI Translation

### Claude API — Deferred

- **Use:** German letter/document translation (Phase 4)
- **Model:** claude-sonnet-4-5
- **Not needed for Phases 1–3**

---

## Infrastructure Summary

All services on a single **Hetzner CX41** (4 vCPU, 16GB RAM):

| Service                      | RAM Estimate |
|------------------------------|--------------|
| Laravel (PHP-FPM + Nginx)   | ~512MB       |
| PostgreSQL 16 + PostGIS     | ~2GB         |
| Redis 7                     | ~256MB       |
| Laravel Reverb (WebSocket)  | ~128MB       |
| Laravel Horizon (queue)     | ~128MB       |
| Umami                       | ~512MB       |
| Valhalla (walking routing)  | ~1–2GB       |
| OS + overhead               | ~1GB         |
| **Total**                   | **~5–6GB**   |
| **Available headroom**      | **~10GB**    |

GDPR compliant — all data hosted in Germany (Hetzner), no user data sent to US services.
