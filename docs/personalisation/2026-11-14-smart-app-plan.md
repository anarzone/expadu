# Making Expadu smart: the plan

14 Nov 2026. The owner asked for all three: the app reaches out, it learns the routine, and Today's ideas get smarter. The guiding principle: **Expadu's strength is a real personality through Composer and Today, built on knowing the person from what they do in the app and outside it.** Sources: `2026-11-14-smart-research.md` (how Citymapper, Transit, DB Navigator, Google and Apple Maps do it; what iOS allows on a free account; EU rules) and a code audit of what the server already has.

## What we have and what's missing

- **The server already works out the moments** that matter: when to leave (`NextTrip`, planned trips), risks to the day's plans (`PlanRisks`), the morning and evening brief (`DayBriefs`), delays and disruptions on the person's lines. None of it reaches the iPhone: the jobs only run for web push subscribers, and `/api/v1/today` strips the times.
- **No memory of trips.** A finished trip is deleted; planned trips are pruned the next day. Nothing can learn a routine.
- **Composer forgets.** "I'm vegetarian" is lost when the chat ends. It sees taste facts, today's plans and Home/Work only when personalisation is on; never the taste profile, past trips or the profile (situation, German level).
- **No "what Expadu knows about you"** on the iPhone (the web has a taste profile in Settings).
- **The iPhone barely feeds the taste**: only impressions and "less like this" on Today's ideas (What's on mislabelled as Today); no opens, shares, saves of places.
- **Today's voice** is a generic greeting; only the ideas' "why" lines speak from the person's facts.

## What the free Apple account allows (no paid account yet)

| Works | Doesn't |
|---|---|
| Local notifications (64 pending at most), scheduled by the app | Push of any kind (server-sent alerts, silent wake-ups, Live Activity push) |
| Live Activities updated by the app while it runs | Time Sensitive notifications (can't break through Focus) |
| Location wake-ups: visits, geofences, significant change (need **Always** location) | WeatherKit, Siri, Focus status |
| Background refresh (best effort: iOS may not run it for a rarely used app) | |
| Calendar and reminders (full access), motion activity | |

So: **notifications are computed ahead and scheduled on the phone**, and replaced whenever the app gets a chance (opened, background refresh, a location wake at home or work). Instant server alerts (a cancellation while the phone sleeps) come with the paid account; the server logic is already there.

## The foundation: one picture of the person

Everything below feeds one picture, and Composer and Today speak from it.

1. **Memory** (`user_facts`): facts with where they came from and when: told to Composer ("I'm vegetarian", "I go to the gym on Tuesdays"), learned (taste leanings, routines), declared (Home, Work). Composer gets a `remember` tool and reads the facts on every answer, with the taste profile and the profile fields.
2. **"What Expadu knows about you"** (iPhone Settings, reachable from Composer and Today): every fact, routine, place and leaning, each editable and deletable; a pause for all; a switch per source. This is what keeps it trustworthy (the ChatGPT-memory / Cortana-notebook pattern).
3. **Consent per purpose**, asked in context: personalisation (exists), notifications, background location (for routines and on-time wake-ups), calendar. Each one optional; everything works without it.
4. **Never learned**: places of worship, health, LGBTQ+ venues, political and union places, diaspora associations (the existing blocklist) — not as facts, routines, reasons or notifications.

## A. The app reaches out

- **Server: `GET /api/v1/moments`** — the next 36 hours as data: leave times (planned trips, plans, events with Getting there, confirmed routines), plan risks, the morning/evening brief, disruptions on their lines, each with an ISO time, a stable id, the words and a deep link. Computed on request (no push subscription needed).
- **iPhone: one notifier** that schedules local notifications from the moments, replacing them by id, rescheduled on every app open, every background refresh and every location wake.
- **What it says, and when** (from DB Navigator and Transit: "only as many as needed"):
  - *Leave now* for anything planned or a confirmed routine: 5 min before, with the live tram ("Leave now · the 12 at 8:12 is 3 min late").
  - *Your plan changed*: a delay or cancellation only when it changes what to do (a missed change, a later way); nothing when the change still works.
  - *Rain on your plan*: only for an outdoor plan or commute.
  - *Morning brief*: off by default; one line from the day.
  - Quiet hours, at most a few a day, each kind switchable in Settings. Permission asked the first time it's useful (Remind me, a trip, a routine), never at launch.
- **During a trip**: the Live Activity (done), plus get-off and change alerts from the app while it follows the trip.

## B. It learns the routine

- **Keep trip history** (opt-in, 90 days): each trip started — from, to, when, weekday — instead of deleting it.
- **Detector** (server, from started trips): the same from→to three times in three weeks, on similar weekdays and times (±30 min), is a routine candidate.
- **Outside the app, with Always location** (opt-in): visits on the phone (stays of 20+ min within ~200 m) cluster into places; departures by weekday and hour. Raw visits never leave the phone; only a place or routine the person confirms goes to the server.
- **Offer, never assume**: "You often go to work around 8:10 on weekdays. Want me to tell you when to leave?" A confirmed routine is named, set per weekday and pausable (DB Navigator), and appears in "What Expadu knows".
- **Then it shows up**: a weekday-morning card on Today ("Going to work? The 12 at 8:12 is on time"), leave notifications, and Composer knows "your usual".

## C. Smarter Today ideas, and a voice

- **The phone feeds everything**: opens, shares, saves, places, Not now, with the right surface (What's on fixed).
- **From day one**: onboarding asks for 3+ interests (`users.interests`, unused today), blended out as behaviour grows.
- **Negatives count** (three "not for me" on comedy lowers comedy), **a "New for you" pick**, at most 2 of one category.
- **Reasons chosen by code** from real facts; the model only phrases them; "why" on places too.
- **The ranking model**: Sonnet or Haiku (the owner allowed Sonnet for ranking), chosen by a replay on our logged lists: which order puts what people actually opened, saved and went to higher. It runs once a day per person, so cost stays small.
- **Today's voice**: a day line from the day and the person's facts ("Busy one: Bürgeramt at 10, rain from 4 — the umbrella"). Composer answers from the same picture ("Your usual gym day — the 9 at 18:05 is on time").

## Order of work (each slice shipped and checked on the simulator)

1. **Notifications**: `/moments`, the notifier, permission in context, Settings switches and quiet hours. Leave reminders for planned trips, plans and events work right away.
2. **Memory**: `user_facts`, Composer remembers and reads, "What Expadu knows" on the iPhone.
3. **Routines from trips**: trip history, the detector, the offer, routine cards and reminders.
4. **Outside the app**: Always location with visits (on the phone), calendar events with a place → leave reminders.
5. **Today's ideas**: full signals from the phone, interests on day one, New for you, reasons; the Sonnet/Haiku replay.
6. **The voice**: Today's day line and Composer from the whole picture.

## Decisions

- Background (Always) location: asked only when the person turns on routine learning (needed for visits and on-time wake-ups).
- Calendar: opt-in in slice 4.
- Defaults: leave reminders on (for what they planned); disruption alerts only for their planned trips and routines; morning brief off.
- Later, with the paid Apple account: server push for instant alerts, Time Sensitive for "leave now".
