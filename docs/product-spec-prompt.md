You are building Expadu — a smart city companion PWA for expats in Germany.
This is the complete product specification. Build features in the order listed.

## What is Expadu
Expadu is a daily-use companion app for expats living in German cities, starting with Cologne.
It solves three problems in this priority order:

Priority 1 — Smart Transit & Navigation
Better than KVB and similar apps. Real-time departures, intelligent route suggestions
that account for live disruptions, events, weather and the user's saved places.
Users check this every single day — it is the retention hook.

Priority 2 — Local Discovery
Suggesting cafés to work from based on time of day and noise preference,
events nearby tailored to interests, work spots with live crowd data,
neighborhood exploration for new arrivals.

Priority 3 — Settling In
Personalised bureaucracy checklist by visa situation, Bürgeramt slot alerts,
German letter AI translator, visa and registration guidance, progress tracking.

## Tech Stack
Backend:  Laravel 12, PHP 8.3, PostgreSQL 16 + PostGIS, Redis 7,
Laravel Sanctum (SPA auth), Laravel Reverb (WebSockets),
Laravel Horizon (queue monitoring), Hetzner Object Storage (S3)

Frontend: React 19, TypeScript, Vite 6, Tailwind CSS v4, TanStack Router,
TanStack Query, Zustand, React Hook Form + Zod, MapLibre GL JS,
shadcn/ui, vite-plugin-pwa

Infrastructure: Hetzner,
PostHog self-hosted (behaviour tracking),
Gorse self-hosted (ML recommendations),
Docker Compose for tracking stack

## Database Schema — create all migrations in this order

users
id, name, email, email_verified_at, password, city, situation, arrival_date,
german_level, speaks (JSON array), onboarded_at, avatar_path, created_at, updated_at

user_places
id, user_id, emoji, name, address, lat, lng, sort_order, created_at

tasks
id, title, description, situation (JSON array of applicable situations),
phase, deadline_type (days_since_arrival / fixed_date / none),
deadline_days, urgency (critical / high / medium / low),
links (JSON), documents_required (JSON), created_at

user_tasks
id, user_id, task_id, completed_at, snoozed_until, notes, created_at

appointments
id, user_id, office_name, scheduled_at, notes, reminder_sent_at, created_at

spots (PostGIS)
id, name, category (cafe / coworking / library / park),
description, address, location (GEOGRAPHY POINT),
wifi_speed, noise_level (quiet / moderate / loud),
time_limit_mins, opening_hours (JSON), rating, created_at

spot_checkins
id, spot_id, user_id, checked_in_at, checked_out_at, created_at

events
id, title, emoji, category, description, starts_at, ends_at,
location_name, address, location (GEOGRAPHY POINT),
max_attendees, is_free, price, organiser_id, created_at

event_attendees
id, event_id, user_id, joined_at, reminded_at, created_at

language_partners
id, requester_id, receiver_id, status (pending / accepted / declined),
matched_at, created_at

conversations
id, type (language / event / direct), created_at

conversation_participants
conversation_id, user_id, joined_at

messages
id, conversation_id, sender_id, body, read_at, created_at

alerts
id, user_id, type (system / social / reminder),
title, body, deep_link, read_at, dismissed_at, created_at

user_events (append-only behaviour log, never delete)
id, user_id, event_type, payload (JSONB), session_id, created_at

## API Routes — implement all

Authentication
POST /api/auth/register
POST /api/auth/login
POST /api/auth/logout
GET  /api/auth/me
POST /api/auth/forgot-password
POST /api/auth/reset-password

Onboarding
POST /api/onboarding/complete   { situation, city, german_level, speaks[], arrival_date }

Home Feed
GET  /api/home-feed             → returns sorted card array { type, data, priority }

Transit
GET  /api/departures?stop=X&limit=10     → live KVB departures
GET  /api/routes?from=X&to=Y&time=now    → route options with disruptions
GET  /api/disruptions?city=cologne       → active disruptions
GET  /api/stops/nearby?lat=X&lng=Y       → nearest stops with walking time
POST /api/routines                        → save recurring journey
GET  /api/routines                        → user's saved routines

Explore / Spots
GET  /api/spots?lat=X&lng=Y&radius=2000&category=cafe
GET  /api/spots/:id
POST /api/spots/:id/checkin
POST /api/spots/:id/checkout
GET  /api/spots/:id/crowd              → live occupancy from checkins

Events
GET  /api/events?city=cologne&date=today&category=X
GET  /api/events/:id
POST /api/events/:id/join
DELETE /api/events/:id/join
GET  /api/events/saved

Bureaucracy
GET  /api/checklist                    → tasks filtered by user situation, sorted by urgency
POST /api/tasks/:id/complete
POST /api/tasks/:id/snooze             { until: date }
GET  /api/appointments
POST /api/appointments
PUT  /api/appointments/:id
DELETE /api/appointments/:id
POST /api/translate                    → { text } → Claude API → { summary, action, urgency, deadline }

Language Exchange
GET  /api/partners?offer=X&want=Y     → matched partners with compatibility score
POST /api/partners/:id/request
PUT  /api/partners/:id/respond        → { accept: bool }
GET  /api/partners/my

Chat
GET  /api/conversations
GET  /api/conversations/:id/messages
POST /api/conversations/:id/messages  → { body }
WebSocket /app/expadu via Reverb      → channel: chat.{conversationId}

Alerts
GET  /api/alerts?tab=all|system|social|reminder
POST /api/alerts/:id/read
POST /api/alerts/read-all

Profile & Settings
GET  /api/profile
PUT  /api/profile
GET  /api/places
POST /api/places
PUT  /api/places/:id
DELETE /api/places/:id
GET  /api/settings/notifications
PUT  /api/settings/notifications

Tracking (internal, fire-and-forget)
POST /api/track                        → { event, properties }

## Behaviour Tracking & Personalisation System

### Layer 1 — Event Capture: PostHog (self-hosted on Hetzner)
Use posthog-js in React for client events.
Use posthog-php in Laravel for server events.

Fire these events:
// React (client-side)
posthog.capture('page_viewed', { page, duration_seconds })
posthog.capture('card_tapped', { card_type, destination })
posthog.capture('card_ignored', { card_type, times_scrolled_past })
posthog.capture('search_performed', { query, results_count, context })
posthog.capture('departure_checked', { stop, route, time_of_day })
posthog.capture('spot_viewed', { spot_id, category, source })
posthog.capture('feature_used', { feature, context })

// Laravel (server-side)
PostHog::capture($userId, 'task_completed', ['task_id', 'days_since_arrival', 'situation'])
PostHog::capture($userId, 'appointment_booked', ['office', 'days_until'])
PostHog::capture($userId, 'partner_connected', ['language_pair'])
PostHog::capture($userId, 'event_joined', ['event_id', 'category', 'days_until'])
PostHog::capture($userId, 'spot_checked_in', ['spot_id', 'category', 'time_of_day'])
PostHog::capture($userId, 'letter_translated', ['detected_language', 'urgency'])
PostHog::capture($userId, 'onboarding_completed', ['situation', 'city', 'german_level'])
PostHog::capture($userId, 'route_searched', ['from_type', 'to_type', 'time_of_day'])

Use PostHog feature flags to A/B test home feed card order.
Use PostHog funnels to identify onboarding drop-off points.
Use PostHog session recordings to watch real user behaviour on key flows.

### Layer 2 — Live User State: Redis
After every meaningful action update a Redis hash for the user. TTL 1 hour.
Re-compute via queued UpdateUserState job dispatched after every tracked event.

Cache key: user_state:{userId}
Fields to store:
days_since_arrival, checklist_completion_percent, urgent_tasks (array),
next_appointment_at, next_appointment_hours, german_level, active_partners,
pending_messages, last_active_page, event_today (bool), event_today_id,
is_commute_time (07:00–09:30 or 16:30–19:00), days_since_progress_viewed,
situation, city, home_lat, home_lng, work_lat, work_lng,
preferred_noise_level, usual_departure_stop

### Layer 3 — ML Recommendation Engine: Gorse (self-hosted)
Use Gorse REST API to get personalised home feed card rankings.
Send feedback to Gorse after every meaningful user interaction.

Item IDs follow format: card:{type} and content:{type}:{id}
Examples: card:transit, card:settlement_progress, content:event:42, content:spot:17

Feedback types: read, click, ignore, complete, join, checkin, skip

Get recommendations: GET http://gorse:8087/api/recommend/{userId}?n=10
Insert feedback:     POST http://gorse:8087/api/feedback
[{ "FeedbackType": "click", "UserId": "123", "ItemId": "card:transit" }]

### Layer 4 — HomeCardService (Laravel)
Orchestration layer that merges Gorse ML output with hard rules.
Hard rules always override ML ranking.

Card types: blue_highlight, live_departures, your_places, settlement_progress,
event_tonight, messages_nudge, work_spots_nearby, quick_access,
this_week, language_partners, neighborhood_tip

Hard rules (always applied before ML):
- urgent_tasks not empty OR appointment within 48h → blue_highlight always first
- days_since_arrival < 30 → settlement_progress in top 3
- is_commute_time → live_departures boosted to position 1 or 2
- pending_messages > 0 → messages_nudge in top 4
- event today → event_tonight shown regardless of ML score
- days_since_progress_viewed > 3 → settlement_progress score +30

Scoring fallback when Gorse has insufficient data (< 50 users):
blue_highlight: 100 (if urgent), live_departures: 80 (commute) / 30 (other),
event_tonight: 75 (if today), messages_nudge: 70 (if pending), settlement_progress: 60,
your_places: 55, quick_access: 45, work_spots_nearby: 35, this_week: 30

### Layer 5 — A/B Testing via PostHog Feature Flags
Flag: home_feed_v2 → test alternative card order
Flag: onboarding_short → test 3-screen vs 5-screen onboarding
Flag: transit_prominent → test transit-first home feed for new users
Check flags in React: const variant = posthog.getFeatureFlag('home_feed_v2')
Check flags in Laravel: PostHog::getFeatureFlag('home_feed_v2', $userId)

### Docker Compose for tracking stack
services:
posthog:
image: posthog/posthog:latest
ports: ["8001:8000"]
environment:
DATABASE_URL: postgres://...
REDIS_URL: redis://redis:6379

gorse-master:
image: zhenghaoz/gorse-master:latest
ports: ["8088:8088"]
environment:
GORSE_POSTGRES_DSN: postgres://...
GORSE_REDIS_URI: redis://redis:6379

gorse-server:
image: zhenghaoz/gorse-server:latest
ports: ["8087:8087"]
depends_on: [gorse-master]

gorse-worker:
image: zhenghaoz/gorse-worker:latest
depends_on: [gorse-master]

## Feature Specifications

### Onboarding (5 screens, no navigation shown)
Screen 1 — Welcome
Expadu logo, tagline "Your city. Your guide.", three benefit rows with icons:
"Smarter transit than KVB", "Find your city spots", "Settle in with confidence"
Single CTA: "Get started"

Screen 2 — Your situation
Single select: Non-EU employee, EU employee, Student, Freelancer,
Family reunification, Digital nomad, Other
Subtitle: "We personalise your checklist and guidance based on this"

Screen 3 — Languages
German level (single select): None yet, A1, A2, B1, B2, C1, C2
Languages you speak (multi-select with flags): English, Arabic, French, Spanish,
Turkish, Russian, Italian, Portuguese, Hindi, Mandarin, Other
Used for language exchange partner matching

Screen 4 — Your city and arrival
City (single select): Cologne, Berlin, Munich, Hamburg, Frankfurt, Other
Arrival month + year (month/year picker, not full date)
"We'll tailor your checklist and show what's urgent based on how long you've been here"

Screen 5 — You're all set
Show: checklist items count for their situation, estimated partner matches,
"Your home feed is ready" message
CTA: "Open Expadu"

On completion: POST /api/onboarding/complete, set onboarded_at, fire onboarding_completed
PostHog event, redirect to home feed.
Show if onboarded_at is null. Never show again after completion.
Skip button on screens 2–4 with sensible defaults.
Progress bar across top. Back navigation between screens.

### Home Feed
Powered by GET /api/home-feed which returns HomeCardService result.
React renders a card list, switching component by card type.
Refresh on app focus (TanStack Query refetchOnWindowFocus).
Skeleton loaders while loading, never empty state on first render.
Pull to refresh on mobile.

Card rendering map:
blue_highlight → BlueHighlightCard
live_departures → DepartureCard
your_places → PlacesCard (horizontal scroll of place chips)
settlement_progress → ProgressCard
event_tonight → EventCard
messages_nudge → MessagesNudgeCard
work_spots_nearby → SpotsListCard
quick_access → QuickAccessGrid
this_week → EventsListCard
language_partners → PartnersCard
neighborhood_tip → NeighborhoodCard

BlueHighlightCard specifics:
Accent blue background, white text
Top section: urgent item (appointment or task) with icon, title, action button
Divider
Headline: "Today — [day name]"
Timeline rows: transit status, evening event if any, weather alert if any

### Transit Page (tabs: Smart Route, Departures, Plan, Disruptions, Routines)

Smart Route tab:
Shown during commute hours (07:00–09:30, 16:30–19:00) automatically
Shows recommended route Home→Work based on real-time KVB data
Accounts for active disruptions and suggests alternatives
Live countdown to next departure, platform number, line badges
"Share ETA" button generates a shareable link
Outside commute hours: shows last searched or most used route

Departures tab:
User's nearest stop detected via GPS or defaulting to saved home stop
Live departures board: line number badge (coloured by line), destination,
platform, countdown ticking every 30 seconds
Tap stop name to change stop (search or nearby stops list)
Lines have correct KVB colours (U-Bahn blue, S-Bahn green, tram red, bus grey)
Disruption banner appears above board if active disruption affects this stop

Plan tab:
From/To inputs with saved places as quick-select chips
Date/time picker (default: now)
Returns 3 route options with: total time, changes, walking distance, line sequence
Each option expandable to show step-by-step instructions
Respects active disruptions and shows affected segments highlighted

Disruptions tab:
Live list of active KVB disruptions for user's city
Each item: affected line(s), description, start time, estimated resolution, severity badge
Dismissed disruptions hidden, option to show again
Auto-refreshes every 2 minutes

Routines tab:
Saved recurring journeys: name, from, to, days of week, departure time
Toggle active/inactive
When active: morning push notification at calculated departure time
containing live route status and platform

### Explore Page (map + list split view)
Mobile: full-screen map with bottom sheet containing list
Desktop/tablet: map left, list right

Map:
MapLibre GL JS with custom Expadu style (warm, matches brand colours)
Spot pins clustered at low zoom, individual at high zoom
User location dot with accuracy ring
Tap pin → spot preview card rises from bottom

Filter bar (horizontal scroll): All, Cafés, Coworking, Libraries, Parks
Sort: Nearest, Best rated, Least crowded

Spot card in list:
Name, category emoji, distance from user
Noise level badge (Quiet / Moderate / Loud)
Wifi speed (Fast / OK / No wifi)
Time limit (90 min / No limit)
Live crowd indicator (percentage filled, green/amber/red)
Rating stars

Spot detail sheet (bottom sheet):
Full info, opening hours, reviews tab, check-in button
Check-in: marks user present, increments crowd count for 2 hours
Navigate button: opens native maps app with directions
Save button: adds to saved spots, appears in Your Places

### Bureaucracy Page (tabs: Checklist, Documents, Slots, Translator)

Checklist tab:
Tasks personalised by user situation.
Non-EU employee tasks in order: Anmeldung (critical, 0–14 days), Health insurance
registration, Tax ID (Steuer-ID), Blocked account (if student), Ausländerbehörde
appointment, Blue Card application, Bank account, SCHUFA, Library card
Student tasks: Blocked account, Enrollment certificate, Student visa, Health
insurance, Anmeldung, Student ID, BAföG application if eligible
EU employee: Anmeldung, Health insurance, Tax ID, EU citizen registration,
Bank account
Freelancer: Anmeldung, Tax ID, Finanzamt registration, Health insurance,
Trade license (Gewerbeanmeldung) or Freiberufler status, Business bank account

Each task card: title, description, urgency badge, deadline indicator,
external link(s), required documents list, mark complete / snooze buttons
Completed tasks collapsed into "Completed" section at bottom
Progress bar at top showing X of Y tasks done

Documents tab:
Upload passport, rental contract, employment contract, insurance documents
Stored in S3, tagged by task
File list with upload date, file type icon, download and delete options

Slots tab:
Bürgeramt appointment availability checker for Cologne offices
User sets preferred offices and available times
System polls for cancellations and sends push notification when slot found
Manual booking link provided (cannot auto-book)

Translator tab:
Paste German letter or official document text
POST /api/translate calls Claude API (claude-sonnet-4-5)
Returns: plain English summary, what action is required, urgency level,
deadline if mentioned, key terms explained
Translation history stored per user, most recent 20 shown
Copy result button, share as PDF button

### Language Exchange (tabs: Find Partners, Meetups, Drop-in, My Connections)

Partner matching algorithm (server-side):
User offers language X, wants to practise language Y
Match with users who offer Y and want X
Score by: language match (40%), proximity in city (25%),
level compatibility (20%), availability overlap (15%)

Partner card: flag emoji, name, languages with direction arrows,
level badges, distance, availability tags, Connect button
Connect → sends request notification → accepted/declined
On accept: conversation created automatically → opens in Chat

Meetups tab:
List of language exchange meetups
User-created events: title, languages, location, date, max participants
Create meetup button → form with all fields
Join meetup → fires event_joined PostHog event

Drop-in tab:
Mark yourself available now for impromptu language exchange
Availability expires after 2 hours automatically
Shows list of currently available users nearby with language pairs
Tap to send a chat message directly

My Connections tab:
Accepted partners list with last message preview
Quick message button goes directly to conversation

### Chat / Inbox (tabs: All, Language, Events)

Conversation list:
Name, flag/avatar, last message preview, timestamp, unread count badge
Online indicator (green dot) for recently active users
Filter tabs: All / Language exchange / Event organisers
Search bar: searches name, language pair, message content in real-time

Thread view:
My messages: right aligned, accent blue background
Their messages: left aligned, surface-2 background
Timestamps grouped by day
Typing indicator (three dots animation) via WebSocket
Read receipts (single tick sent, double tick read)
Send on Enter (desktop) or send button (mobile)
Image attachment support (upload to S3)

Real-time via Laravel Reverb:
Channel: chat.{conversationId}
Events: MessageSent, UserTyping, MessageRead

New conversations only created via:
Language partner accept flow
Event organiser contact button

### Alerts Page (tabs: All, System, Social, Reminders)

System: Transit disruptions affecting saved routes, Bürgeramt slot available,
task deadline approaching (7 days, 3 days, day of), document expiry warnings

Social: Language partner accepted your request, event RSVP confirmation,
new partner match found, meetup reminder

Reminders: Appointment tomorrow at [time], checklist task due today,
weekly settlement progress summary every Sunday

Each alert: icon, title, body, timestamp, deep-link action button
Swipe to dismiss on mobile, X button on desktop
Mark all read button in header
Unread count shown in pill dock badge and sidebar nav badge

Push notifications:
Use Web Push API with VAPID keys
Subscribe on first alert permission grant
Laravel queued jobs dispatch pushes via WebPush package
Notification click deep-links to relevant page

### Profile Page (tabs: Overview, Activity, Settings)

Overview tab:
Hero section: avatar (upload to S3), name, city, situation badge, arrival date
Stats row: Tasks done, Events joined, Partners, German level
Settlement progress bar: X of Y tasks complete
Your Places: editable list of saved locations (Home, Work, etc.)
Interests tags

Activity tab:
Timeline of recent activity: tasks completed, events joined, spots visited,
partners connected

Settings tab sections (all with InlineEdit for account fields):
Account: Name, Email, City, Situation, German level, Origin country
Your Places: Add/edit/reorder/delete saved places
Notifications: Per-feature push toggles — Transit disruptions, Slot alerts,
Deadline reminders, Partner requests, Event reminders, Weekly summary
Language Exchange: My offered languages, wanted languages, availability schedule
Transit: Home stop, Work stop, Commute days and times, Route preferences
Privacy: Profile visibility, Activity sharing, Data export, Delete account
Appearance: Light/Dark/System theme toggle
About: App version, Terms, Privacy policy, Licenses
Danger zone: Export my data, Delete my account

## Deployment

Server: Hetzner CX41 (4 vCPU, 16GB RAM, 160GB NVMe SSD)
All data hosted in Germany — fully GDPR compliant

Services on server:
Laravel app (PHP-FPM + Nginx)
PostgreSQL 16 with PostGIS extension
Redis 7 (cache + queues + Reverb presence channels)
Laravel Horizon (queue worker monitoring)
Laravel Reverb (WebSocket server)
PostHog (Docker, port 8001)
Gorse master + server + worker (Docker, ports 8087/8088)

SSL: Let's Encrypt
Storage: Hetzner Object Storage for all user uploads (S3-compatible)
CDN: Cloudflare in front of everything — free tier sufficient for launch

CI/CD: we will create custom scripts

Environment variables:
APP_URL, DB_*, REDIS_*, REVERB_*, AWS_* (Hetzner S3),
POSTHOG_API_KEY, GORSE_API_URL, CLAUDE_API_KEY,
KVB_API_KEY, VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY

## Build Order
Build features in exactly this sequence:

Phase 1 — Foundation (Week 1–2)
Database migrations and models
Laravel Sanctum authentication (register, login, logout, me)
Onboarding API and React flow (5 screens)
Basic home feed with rule-based HomeCardService (no Gorse yet)
React app shell: AppLayout, navigation, routing structure

Phase 2 — Core Daily Use (Week 3–5)
Transit page: KVB API integration, live departures, Smart Route
Disruptions and alerts system
Explore page: spots, MapLibre map, check-in system
Events page: list, detail, join/leave

Phase 3 — Community (Week 6–8)
Language Exchange: partner matching, meetups, drop-in
Chat: conversations list, thread view, Reverb WebSockets
Alerts: all types, push notifications via Web Push

Phase 4 — Settling In (Week 9–11)
Bureaucracy checklist personalised by situation
Document upload (S3)
Bürgeramt slot monitoring
AI letter translator (Claude API)

Phase 5 — Personalisation (Week 12–14)
PostHog integration (client + server)
Redis user state cache
Gorse integration and feedback loop
HomeCardService ML mode
A/B testing via PostHog feature flags

Phase 6 — Polish (Week 15–16)
PWA: service worker, offline caching, install prompt
Push notifications end-to-end
Profile and settings pages complete
Performance audit, Lighthouse PWA score > 90
Production deployment on Hetzner
