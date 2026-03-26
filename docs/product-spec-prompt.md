# Expadu — Product Specification

> A daily-use companion app for expats living in German cities, starting with Cologne.

---

## Problem & Priorities

| Priority | Domain                       | Description                                                                                       |
|----------|------------------------------|---------------------------------------------------------------------------------------------------|
| **P1**   | Smart Transit & Navigation   | Better than KVB apps. Real-time departures, intelligent routing accounting for disruptions, events, weather, and saved places. **Daily retention hook.** |
| **P2**   | Local Discovery              | Cafés by time/noise preference, events by interests, work spots with live crowd data, neighborhood exploration. |
| **P3**   | Settling In                  | Personalized bureaucracy checklist by visa situation, Bürgeramt slot alerts, German letter AI translator, visa guidance, progress tracking. |

---

## Tech Stack

### Backend

| Component   | Technology                          |
|-------------|-------------------------------------|
| Framework   | Laravel 12, PHP 8.3                 |
| Database    | PostgreSQL 16 + PostGIS             |
| Cache/Queue | Redis 7                             |
| Auth        | Laravel Sanctum (SPA auth)          |
| WebSockets  | Laravel Reverb                      |
| Queue UI    | Laravel Horizon                     |
| Storage     | Hetzner Object Storage (S3-compat)  |

### Frontend

| Component    | Technology                        |
|--------------|-----------------------------------|
| Framework    | React 19 + TypeScript             |
| Bundler      | Vite 6                            |
| CSS          | Tailwind CSS v4                   |
| Routing      | TanStack Router                   |
| Server state | TanStack Query                    |
| Client state | Zustand                           |
| Forms        | React Hook Form + Zod             |
| Maps         | MapLibre GL JS                    |
| Components   | shadcn/ui                         |
| PWA          | vite-plugin-pwa                   |

### Infrastructure

- **Host:** Hetzner (all data in Germany, GDPR compliant)
- **Analytics:** PostHog (self-hosted) — behavior tracking
- **Recommendations:** Gorse (self-hosted) — ML-powered feed
- **Orchestration:** Docker Compose for tracking stack

---

## Database Schema

> Create all migrations in the order listed below.

### `users`

`id` · `name` · `email` · `email_verified_at` · `password` · `city` · `situation` · `arrival_date` · `german_level` · `speaks` (JSON) · `onboarded_at` · `avatar_path` · `created_at` · `updated_at`

### `user_places`

`id` · `user_id` · `emoji` · `name` · `address` · `lat` · `lng` · `sort_order` · `created_at`

### `tasks`

`id` · `title` · `description` · `situation` (JSON — applicable situations) · `phase` · `deadline_type` (days_since_arrival / fixed_date / none) · `deadline_days` · `urgency` (critical / high / medium / low) · `links` (JSON) · `documents_required` (JSON) · `created_at`

### `user_tasks`

`id` · `user_id` · `task_id` · `completed_at` · `snoozed_until` · `notes` · `created_at`

### `appointments`

`id` · `user_id` · `office_name` · `scheduled_at` · `notes` · `reminder_sent_at` · `created_at`

### `spots` (PostGIS)

`id` · `name` · `category` (cafe / coworking / library / park) · `description` · `address` · `location` (GEOGRAPHY POINT) · `wifi_speed` · `noise_level` (quiet / moderate / loud) · `time_limit_mins` · `opening_hours` (JSON) · `rating` · `created_at`

### `spot_checkins`

`id` · `spot_id` · `user_id` · `checked_in_at` · `checked_out_at` · `created_at`

### `events`

`id` · `title` · `emoji` · `category` · `description` · `starts_at` · `ends_at` · `location_name` · `address` · `location` (GEOGRAPHY POINT) · `max_attendees` · `is_free` · `price` · `organiser_id` · `created_at`

### `event_attendees`

`id` · `event_id` · `user_id` · `joined_at` · `reminded_at` · `created_at`

### `language_partners`

`id` · `requester_id` · `receiver_id` · `status` (pending / accepted / declined) · `matched_at` · `created_at`

### `conversations`

`id` · `type` (language / event / direct) · `created_at`

### `conversation_participants`

`conversation_id` · `user_id` · `joined_at`

### `messages`

`id` · `conversation_id` · `sender_id` · `body` · `read_at` · `created_at`

### `alerts`

`id` · `user_id` · `type` (system / social / reminder) · `title` · `body` · `deep_link` · `read_at` · `dismissed_at` · `created_at`

### `user_events` (append-only behavior log — never delete)

`id` · `user_id` · `event_type` · `payload` (JSONB) · `session_id` · `created_at`

---

## API Routes

### Authentication

```
POST /api/auth/register
POST /api/auth/login
POST /api/auth/logout
GET  /api/auth/me
POST /api/auth/forgot-password
POST /api/auth/reset-password
```

### Onboarding

```
POST /api/onboarding/complete   { situation, city, german_level, speaks[], arrival_date }
```

### Home Feed

```
GET  /api/home-feed             → sorted card array { type, data, priority }
```

### Transit

```
GET  /api/departures?stop=X&limit=10     → live KVB departures
GET  /api/routes?from=X&to=Y&time=now    → route options with disruptions
GET  /api/disruptions?city=cologne       → active disruptions
GET  /api/stops/nearby?lat=X&lng=Y       → nearest stops with walking time
POST /api/routines                        → save recurring journey
GET  /api/routines                        → user's saved routines
```

### Explore / Spots

```
GET  /api/spots?lat=X&lng=Y&radius=2000&category=cafe
GET  /api/spots/:id
POST /api/spots/:id/checkin
POST /api/spots/:id/checkout
GET  /api/spots/:id/crowd                → live occupancy from checkins
```

### Events

```
GET  /api/events?city=cologne&date=today&category=X
GET  /api/events/:id
POST /api/events/:id/join
DELETE /api/events/:id/join
GET  /api/events/saved
```

### Bureaucracy

```
GET  /api/checklist                      → tasks filtered by user situation
POST /api/tasks/:id/complete
POST /api/tasks/:id/snooze               { until: date }
GET  /api/appointments
POST /api/appointments
PUT  /api/appointments/:id
DELETE /api/appointments/:id
POST /api/translate                      → { text } → Claude API → { summary, action, urgency, deadline }
```

### Language Exchange

```
GET  /api/partners?offer=X&want=Y       → matched partners with compatibility score
POST /api/partners/:id/request
PUT  /api/partners/:id/respond           { accept: bool }
GET  /api/partners/my
```

### Chat

```
GET  /api/conversations
GET  /api/conversations/:id/messages
POST /api/conversations/:id/messages     { body }
WebSocket /app/expadu via Reverb         → channel: chat.{conversationId}
```

### Alerts

```
GET  /api/alerts?tab=all|system|social|reminder
POST /api/alerts/:id/read
POST /api/alerts/read-all
```

### Profile & Settings

```
GET  /api/profile
PUT  /api/profile
GET  /api/places
POST /api/places
PUT  /api/places/:id
DELETE /api/places/:id
GET  /api/settings/notifications
PUT  /api/settings/notifications
```

### Tracking (internal, fire-and-forget)

```
POST /api/track                          { event, properties }
```

---

## Behavior Tracking & Personalization

### Layer 1 — Event Capture: PostHog (self-hosted)

Use `posthog-js` (React) and `posthog-php` (Laravel).

**Client-side events:**

```js
posthog.capture('page_viewed', { page, duration_seconds })
posthog.capture('card_tapped', { card_type, destination })
posthog.capture('card_ignored', { card_type, times_scrolled_past })
posthog.capture('search_performed', { query, results_count, context })
posthog.capture('departure_checked', { stop, route, time_of_day })
posthog.capture('spot_viewed', { spot_id, category, source })
posthog.capture('feature_used', { feature, context })
```

**Server-side events:**

```php
PostHog::capture($userId, 'task_completed', ['task_id', 'days_since_arrival', 'situation'])
PostHog::capture($userId, 'appointment_booked', ['office', 'days_until'])
PostHog::capture($userId, 'partner_connected', ['language_pair'])
PostHog::capture($userId, 'event_joined', ['event_id', 'category', 'days_until'])
PostHog::capture($userId, 'spot_checked_in', ['spot_id', 'category', 'time_of_day'])
PostHog::capture($userId, 'letter_translated', ['detected_language', 'urgency'])
PostHog::capture($userId, 'onboarding_completed', ['situation', 'city', 'german_level'])
PostHog::capture($userId, 'route_searched', ['from_type', 'to_type', 'time_of_day'])
```

**PostHog capabilities to use:**
- Feature flags → A/B test home feed card order
- Funnels → identify onboarding drop-off points
- Session recordings → watch real user behavior on key flows

### Layer 2 — Live User State: Redis

After every meaningful action, update a Redis hash for the user (TTL 1 hour). Re-compute via queued `UpdateUserState` job dispatched after every tracked event.

**Cache key:** `user_state:{userId}`

**Fields:**

| Category    | Fields                                                                  |
|-------------|-------------------------------------------------------------------------|
| Progress    | `days_since_arrival`, `checklist_completion_percent`, `urgent_tasks[]`, `days_since_progress_viewed` |
| Appointments| `next_appointment_at`, `next_appointment_hours`                         |
| Profile     | `german_level`, `situation`, `city`                                     |
| Social      | `active_partners`, `pending_messages`                                   |
| Context     | `last_active_page`, `event_today`, `event_today_id`, `is_commute_time` (07:00–09:30 / 16:30–19:00) |
| Location    | `home_lat`, `home_lng`, `work_lat`, `work_lng`, `preferred_noise_level`, `usual_departure_stop` |

### Layer 3 — ML Recommendations: Gorse (self-hosted)

**Item ID format:** `card:{type}` or `content:{type}:{id}`
(e.g., `card:transit`, `content:event:42`, `content:spot:17`)

**Feedback types:** read · click · ignore · complete · join · checkin · skip

```
GET  http://gorse:8087/api/recommend/{userId}?n=10
POST http://gorse:8087/api/feedback
     [{ "FeedbackType": "click", "UserId": "123", "ItemId": "card:transit" }]
```

### Layer 4 — HomeCardService (Laravel)

Orchestration layer merging Gorse ML output with hard rules. **Hard rules always override ML ranking.**

**Card types:** `blue_highlight` · `live_departures` · `your_places` · `settlement_progress` · `event_tonight` · `messages_nudge` · `work_spots_nearby` · `quick_access` · `this_week` · `language_partners` · `neighborhood_tip`

**Hard rules (applied before ML):**

| Condition                              | Effect                               |
|----------------------------------------|--------------------------------------|
| `urgent_tasks` not empty OR appointment within 48h | `blue_highlight` always first |
| `days_since_arrival < 30`              | `settlement_progress` in top 3       |
| `is_commute_time`                      | `live_departures` boosted to pos 1–2 |
| `pending_messages > 0`                 | `messages_nudge` in top 4            |
| Event today                            | `event_tonight` shown regardless of ML |
| `days_since_progress_viewed > 3`       | `settlement_progress` score +30      |

**Fallback scoring (< 50 users, no Gorse):**

| Card                 | Score                |
|----------------------|----------------------|
| `blue_highlight`     | 100 (if urgent)      |
| `live_departures`    | 80 (commute) / 30    |
| `event_tonight`      | 75 (if today)        |
| `messages_nudge`     | 70 (if pending)      |
| `settlement_progress`| 60                   |
| `your_places`        | 55                   |
| `quick_access`       | 45                   |
| `work_spots_nearby`  | 35                   |
| `this_week`          | 30                   |

### Layer 5 — A/B Testing via PostHog Feature Flags

| Flag                | Tests                                      |
|---------------------|--------------------------------------------|
| `home_feed_v2`      | Alternative card order                     |
| `onboarding_short`  | 3-screen vs 5-screen onboarding            |
| `transit_prominent` | Transit-first home feed for new users      |

```js
// React
const variant = posthog.getFeatureFlag('home_feed_v2')
```

```php
// Laravel
PostHog::getFeatureFlag('home_feed_v2', $userId)
```

### Docker Compose (Tracking Stack)

```yaml
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
```

---

## Feature Specifications

### Onboarding (5 screens, no navigation shown)

**Screen 1 — Welcome**
- Expadu logo, tagline "Your city. Your guide."
- Three benefit rows with icons: "Smarter transit than KVB", "Find your city spots", "Settle in with confidence"
- CTA: "Get started"

**Screen 2 — Your situation**
- Single select: Non-EU employee · EU employee · Student · Freelancer · Family reunification · Digital nomad · Other
- Subtitle: "We personalise your checklist and guidance based on this"

**Screen 3 — Languages**
- German level (single select): None yet · A1 · A2 · B1 · B2 · C1 · C2
- Languages you speak (multi-select with flags): English · Arabic · French · Spanish · Turkish · Russian · Italian · Portuguese · Hindi · Mandarin · Other
- Used for language exchange partner matching

**Screen 4 — Your city and arrival**
- City (single select): Cologne · Berlin · Munich · Hamburg · Frankfurt · Other
- Arrival month + year (month/year picker, not full date)
- "We'll tailor your checklist and show what's urgent based on how long you've been here"

**Screen 5 — You're all set**
- Show checklist items count, estimated partner matches, "Your home feed is ready"
- CTA: "Open Expadu"

**Behavior:**
- On completion: `POST /api/onboarding/complete` → set `onboarded_at` → fire `onboarding_completed` PostHog event → redirect to home
- Show only if `onboarded_at` is null. Never show again after completion.
- Skip button on screens 2–4 with sensible defaults
- Progress bar across top. Back navigation between screens.

---

### Home Feed

- Powered by `GET /api/home-feed` (returns HomeCardService result)
- React renders a card list, switching component by card type
- Refresh on app focus (`refetchOnWindowFocus`)
- Skeleton loaders while loading, never empty state
- Pull to refresh on mobile

**Card → Component mapping:**

| Card type              | Component          |
|------------------------|--------------------|
| `blue_highlight`       | BlueHighlightCard  |
| `live_departures`      | DepartureCard      |
| `your_places`          | PlacesCard (horizontal scroll of place chips) |
| `settlement_progress`  | ProgressCard       |
| `event_tonight`        | EventCard          |
| `messages_nudge`       | MessagesNudgeCard  |
| `work_spots_nearby`    | SpotsListCard      |
| `quick_access`         | QuickAccessGrid    |
| `this_week`            | EventsListCard     |
| `language_partners`    | PartnersCard       |
| `neighborhood_tip`     | NeighborhoodCard   |

**BlueHighlightCard specifics:**
- Accent blue background, white text
- Top section: urgent item (appointment or task) with icon, title, action button
- Divider
- Headline: "Today — [day name]"
- Timeline rows: transit status, evening event, weather alert

---

### Transit Page

**Tabs:** Smart Route · Departures · Plan · Disruptions · Routines

#### Smart Route

- Shown automatically during commute hours (07:00–09:30, 16:30–19:00)
- Recommended Home→Work route based on real-time KVB data
- Accounts for disruptions and suggests alternatives
- Live countdown, platform number, line badges
- "Share ETA" button generates shareable link
- Outside commute hours: shows last searched or most used route

#### Departures

- Nearest stop via GPS (fallback: saved home stop)
- Live departure board: line badge (colored by line), destination, platform, countdown (refreshes every 30s)
- Tap stop name to change stop (search or nearby list)
- Line colors: U-Bahn blue, S-Bahn green, tram red, bus grey
- Disruption banner above board if active disruption affects this stop

#### Plan

- From/To inputs with saved places as quick-select chips
- Date/time picker (default: now)
- Returns 3 route options: total time, changes, walking distance, line sequence
- Each option expandable to step-by-step instructions
- Respects disruptions, highlights affected segments

#### Disruptions

- Live list of active KVB disruptions
- Each item: affected line(s), description, start time, estimated resolution, severity badge
- Dismissed disruptions hidden (option to show again)
- Auto-refreshes every 2 minutes

#### Routines

- Saved recurring journeys: name, from, to, days of week, departure time
- Toggle active/inactive
- When active: morning push notification with live route status and platform

---

### Explore Page (map + list split view)

**Layout:** Mobile → full-screen map + bottom sheet list. Desktop/tablet → map left, list right.

#### Map

- MapLibre GL JS with custom Expadu style (warm, brand colors)
- Spot pins clustered at low zoom, individual at high zoom
- User location dot with accuracy ring
- Tap pin → spot preview card rises from bottom

#### Filters & Sorting

- **Filter bar** (horizontal scroll): All · Cafés · Coworking · Libraries · Parks
- **Sort:** Nearest · Best rated · Least crowded

#### Spot Card (list view)

Name · category emoji · distance · noise level badge (Quiet/Moderate/Loud) · wifi speed (Fast/OK/No wifi) · time limit (90 min/No limit) · live crowd indicator (green/amber/red) · rating stars

#### Spot Detail Sheet (bottom sheet)

- Full info, opening hours, reviews tab, check-in button
- **Check-in:** marks user present, increments crowd count for 2 hours
- **Navigate:** opens native maps app with directions
- **Save:** adds to saved spots → appears in Your Places

---

### Bureaucracy Page

**Tabs:** Checklist · Documents · Slots · Translator

#### Checklist

Tasks personalized by user situation:

| Situation      | Tasks (in order)                                                                  |
|----------------|-----------------------------------------------------------------------------------|
| Non-EU employee| Anmeldung (critical, 0–14 days) · Health insurance · Tax ID · Ausländerbehörde · Blue Card · Bank account · SCHUFA · Library card |
| Student        | Blocked account · Enrollment cert · Student visa · Health insurance · Anmeldung · Student ID · BAföG |
| EU employee    | Anmeldung · Health insurance · Tax ID · EU citizen registration · Bank account    |
| Freelancer     | Anmeldung · Tax ID · Finanzamt · Health insurance · Trade license / Freiberufler · Business bank account |

**Task card:** title · description · urgency badge · deadline indicator · external link(s) · required documents · mark complete / snooze

- Completed tasks collapsed into "Completed" section at bottom
- Progress bar at top: X of Y tasks done

#### Documents

- Upload passport, rental contract, employment contract, insurance docs
- Stored in S3, tagged by task
- File list: upload date, file type icon, download/delete

#### Slots

- Bürgeramt appointment availability checker for Cologne offices
- User sets preferred offices and available times
- System polls for cancellations → push notification when found
- Manual booking link (cannot auto-book)

#### Translator

- Paste German letter or official document text
- `POST /api/translate` → Claude API (claude-sonnet-4-5)
- Returns: plain English summary, required action, urgency level, deadline (if mentioned), key terms explained
- Translation history (last 20), copy button, share as PDF

---

### Language Exchange

**Tabs:** Find Partners · Meetups · Drop-in · My Connections

#### Partner Matching (server-side)

User offers language X, wants language Y → match with users offering Y wanting X.

**Scoring:** language match (40%) · proximity (25%) · level compatibility (20%) · availability overlap (15%)

**Partner card:** flag emoji · name · languages with direction arrows · level badges · distance · availability tags · Connect button

- Connect → request notification → accepted/declined
- On accept: conversation created automatically → opens in Chat

#### Meetups

- List of language exchange meetups (user-created)
- Create: title, languages, location, date, max participants
- Join → fires `event_joined` PostHog event

#### Drop-in

- Mark yourself available now (expires after 2 hours)
- Shows currently available users nearby with language pairs
- Tap → send chat message directly

#### My Connections

- Accepted partners with last message preview
- Quick message button → direct conversation

---

### Chat / Inbox

**Tabs:** All · Language · Events

#### Conversation List

- Name, flag/avatar, last message preview, timestamp, unread badge
- Online indicator (green dot)
- Search: name, language pair, message content

#### Thread View

- My messages: right-aligned, accent blue background
- Their messages: left-aligned, surface-2 background
- Timestamps grouped by day
- Typing indicator (3 dots) via WebSocket
- Read receipts: single tick (sent), double tick (read)
- Send on Enter (desktop), send button (mobile)
- Image attachment (upload to S3)

#### Real-time (Laravel Reverb)

- Channel: `chat.{conversationId}`
- Events: `MessageSent` · `UserTyping` · `MessageRead`

**New conversations only created via:** language partner accept flow or event organizer contact button.

---

### Alerts Page

**Tabs:** All · System · Social · Reminders

| Type      | Examples                                                                     |
|-----------|------------------------------------------------------------------------------|
| System    | Transit disruptions on saved routes · Bürgeramt slot available · Deadline approaching (7d, 3d, day-of) · Document expiry |
| Social    | Partner accepted request · Event RSVP confirmation · New match found · Meetup reminder |
| Reminders | Appointment tomorrow · Checklist task due today · Weekly settlement summary (Sundays) |

**Each alert:** icon · title · body · timestamp · deep-link action button

- Swipe to dismiss (mobile), X button (desktop)
- Mark all read button in header
- Unread count on pill dock badge and sidebar nav badge

**Push notifications:**
- Web Push API with VAPID keys
- Subscribe on first alert permission grant
- Laravel queued jobs dispatch via WebPush package
- Click deep-links to relevant page

---

### Profile Page

**Tabs:** Overview · Activity · Settings

#### Overview

- **Hero:** avatar (S3 upload), name, city, situation badge, arrival date
- **Stats row:** Tasks done · Events joined · Partners · German level
- **Settlement progress bar:** X of Y tasks
- **Your Places:** editable list (Home, Work, etc.)
- **Interests tags**

#### Activity

Timeline of recent activity: tasks completed, events joined, spots visited, partners connected.

#### Settings

| Section            | Fields                                                                    |
|--------------------|---------------------------------------------------------------------------|
| Account            | Name · Email · City · Situation · German level · Origin country (InlineEdit) |
| Your Places        | Add/edit/reorder/delete saved places                                      |
| Notifications      | Per-feature toggles: Transit · Slots · Deadlines · Partners · Events · Weekly summary |
| Language Exchange   | Offered languages · Wanted languages · Availability schedule              |
| Transit            | Home stop · Work stop · Commute days/times · Route preferences            |
| Privacy            | Profile visibility · Activity sharing · Data export · Delete account      |
| Appearance         | Light / Dark / System theme toggle                                        |
| About              | App version · Terms · Privacy policy · Licenses                           |
| Danger zone        | Export my data · Delete my account                                        |

---

## Deployment

### Server

**Hetzner CX41** — 4 vCPU, 16GB RAM, 160GB NVMe SSD. All data in Germany (GDPR compliant).

### Services

| Service                    | Notes                           |
|----------------------------|---------------------------------|
| Laravel (PHP-FPM + Nginx)  | Main application                |
| PostgreSQL 16 + PostGIS    | Primary database                |
| Redis 7                    | Cache + queues + Reverb presence|
| Laravel Horizon            | Queue worker monitoring         |
| Laravel Reverb             | WebSocket server                |
| PostHog (Docker)           | Port 8001                       |
| Gorse (Docker)             | Ports 8087/8088                 |

### Networking

- **SSL:** Let's Encrypt
- **Storage:** Hetzner Object Storage (S3-compat) for user uploads
- **CDN:** Cloudflare free tier
- **CI/CD:** Custom scripts

### Environment Variables

```
APP_URL, DB_*, REDIS_*, REVERB_*, AWS_* (Hetzner S3),
POSTHOG_API_KEY, GORSE_API_URL, CLAUDE_API_KEY,
KVB_API_KEY, VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY
```

---

## Build Order

| Phase | Focus                  | Timeline    | Key Deliverables                                                              |
|-------|------------------------|-------------|-------------------------------------------------------------------------------|
| 1     | Foundation             | Week 1–2    | Migrations + models · Sanctum auth · Onboarding API + React flow · Basic HomeCardService · App shell + navigation |
| 2     | Core Daily Use         | Week 3–5    | Transit (KVB API, live departures, Smart Route) · Disruptions + alerts · Explore (spots, MapLibre, check-ins) · Events |
| 3     | Community              | Week 6–8    | Language Exchange (matching, meetups, drop-in) · Chat (Reverb WebSockets) · Alerts + push notifications |
| 4     | Settling In            | Week 9–11   | Bureaucracy checklist · Document upload (S3) · Bürgeramt slot monitoring · AI letter translator (Claude API) |
| 5     | Personalization        | Week 12–14  | PostHog integration · Redis user state · Gorse + feedback loop · HomeCardService ML mode · A/B testing |
| 6     | Polish                 | Week 15–16  | PWA (service worker, offline, install prompt) · Push notifications E2E · Profile + settings · Lighthouse > 90 · Production deploy |
