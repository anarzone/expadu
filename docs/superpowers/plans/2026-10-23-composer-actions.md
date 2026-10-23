# Composer does things: inventory and action tools

23 Oct 2026 · AI session · for the coordinator and the owner. No code yet.

The owner's words: "composer should be able to do anything in the app so this one too". The ask he was
looking at was "Can u update my paperwork details?". Composer answered with an Open Paperwork button.

## How it would work

- **One loop, as now.** A change is a tool, like a lookup. The AI decides whether to call it from the
  conversation. There are no keyword readers.
- **The same code as the app.** Each action tool calls the same action class or validation as the app's
  own endpoint, never a direct table write. Paperwork goes through `RecordFactChange`,
  `RecordProcessEvent` and `StartProcess`, with their expected-version and review-token checks.
- **Composer's own words for a change.** "Saved Café Mercato", or "Your Blue Card renewal moves to
  12 Feb". The change line is built in code from what the action returned. The AI's sentence around it
  goes through the same check as today. The AI never states a legal date, outcome or figure itself.
- **A `changed` block** in the answer shows what changed, with **Undo**. The server keeps the "before"
  for each action under a short-lived undo token. Undo runs the opposite action through the same code.
  "Undo that" in chat does the same, through an `undo_last` tool.
- **Ask first ▾.** This setting exists only in the mock and the marketing page; the app doesn't have it
  yet. Proposal: one Composer setting that covers every action below.
  - Off (default, the no-confirm-taps rule): Composer does it and shows Undo.
  - On: Composer shows the change as a proposal with one "Do it" tap.

## Inventory

**Reads** are what Composer's tools read today. **Writes today** are what Composer can do now.

| Area | What a person can do in the app (endpoint) | Reads | Writes today | Proposed tool | Tier |
|---|---|---|---|---|---|
| **Paperwork: facts** | Change a situation fact (permit, dates, family…) `PUT people/{p}/facts/{key}`; correct one `POST …/corrections`; resolve a conflict | `paperwork` (an own card: shown, never sent to the AI) | – | `change_paperwork_fact` {key from FactRegistry, value, effective_from} | B |
| **Paperwork: steps** | Tick or reopen a step; record submitted, waiting, appointment… `POST processes/{id}/events` (14 event types) | same | – | `paperwork_step` {process, step, done/reopen}; `paperwork_report` {process, event, date} | B |
| **Paperwork: tasks** | Start or stop tracking a task `POST people/{p}/processes`, `process_untracked` | same | – | `track_paperwork_task` {task, on/off} | B |
| **Paperwork: documents** | Confirm or withdraw a document `POST/DELETE …/requirements/{r}/confirm` | – | – | `paperwork_document` {process, requirement, have/remove} | B |
| **Paperwork: questions** | Answer the onboarding questions (question sessions) | – | – | Later: `answer_paperwork_question`. Answers already pass through the AI extraction under `extract_case_fact` | B (later) |
| **My day / plans** | Plan a day, save or keep a plan, swap a stop, clear today (`composer/compose`, `save`, `keep`, `swap`, `DELETE composer/today`) | `my_day`, `my_next` | `plan_day` drafts a plan the person adds | `add_to_day` {plan or a place or event}; `remove_from_day`; `swap_in_day`; `clear_day` (Undo restores it) | A |
| **Saved places** | Save, been, more like this, not for me, clear `POST api/places/{spot}/feedback` | `my_places` (names) | – | `place_feedback` {place, state} | A |
| **Home / work** | Set or change home and work `POST/PUT user-places`; remove one | `my_places` | – | `set_my_place` {home/work, place}; removing it is A with Undo | A |
| **Events** | Save an event `POST api/event-bookmarks`; reminder on or off `POST/DELETE api/reminders` | `search_events` | – | `save_event` {event, on/off}; `remind_me` {event, on/off} | A |
| **Travel** | Favourite stops, saved journeys, planned trips (create, change, delete), start or end a trip, transport mode, Deutschlandticket | `departures`, `plan_trip`, `notices`, `my_trip` | – | `save_stop`; `save_journey`; `plan_trip_for_later` {to, at}; `set_travel_prefs` {mode, Deutschlandticket}; start trip opens Travel (it needs location in the browser) | A |
| **Alerts** | Mark read, dismiss, mute a line or kind `POST mutes`, `DELETE mutes` | `notices` | – | `mute_alerts` {what, on/off}; `dismiss_alert` | A |
| **Notifications** | Notification preferences `PUT notification-preferences`; push on or off | – | – | `set_notifications` {kind, on/off}. Turning push on needs a tap in the browser, so it opens Settings | A / open |
| **Needs** | Step-free need (`users.needs_step_free`). There's no write endpoint on this branch, so the right one on the preview stack needs checking | applied in code, never sent | – | `set_needs` {step_free} | A |
| **Settings: profile** | Name, city, situation, German level `PATCH settings/profile` | – | – | `update_profile` {field, value}. Email stays out (it needs re-confirmation) | A |
| **Settings: personalisation** | Personalised content, sharing, profile visibility `PUT user-settings`; taste profile `PUT/DELETE api/taste-profile` | `my_taste` (with the opt-in) | – | Turning a setting on is A. Turning `personalisation` or `share_location` off deletes stored data, so it's C | A / C |
| **Privacy and account** | Withdraw an AI consent `DELETE privacy/processing/{purpose}`; erase Paperwork `DELETE people/{p}`; Paperwork processing restriction; delete account `DELETE settings/profile`; export data | – | – | none: open the exact place | C |
| **Family and sharing** | Invite, accept, revoke, share evidence, dependent authority | – | – | none: open Paperwork's sharing | C |
| **Sign-in** | Password, email code, sessions | – | – | none | C |

## Tiers

- **A, reversible.** Composer does it and shows what changed, with Undo; with Ask first, one tap first.
  This covers saving, unsaving, adding to My day, home/work, events, travel, mutes, notification kinds,
  needs and profile fields.
- **B, Paperwork.** Reversible, so it works like A, plus:
  - **Consent:** only under the person's Paperwork AI consent (`paperwork_answer`). With it, the
    `paperwork` tool sends the AI the tasks, steps and facts it needs to name, by id and label. Without
    it, Composer says it can't change Paperwork without that consent, offers the consent in place and
    opens Paperwork.
  - **Paperwork's own checks:** it writes through `RecordFactChange`, `RecordProcessEvent` and
    `StartProcess`, with their expected-version and review-token checks.
  - **The review token:** Paperwork's review token exists so that a step changes only against steps the
    person has seen. With Ask first on, the proposal card shows those steps, and its "Do it" tap carries
    the token, which keeps that guarantee. With Ask first off, the server would read the token itself,
    so the person sees the steps only after the change, with Undo. That's the reason for question 1.
  - **A changed line from Paperwork's own result:** after the write, Paperwork re-runs its assessment.
    Composer compares the steps before and after and says what changed, in Paperwork's card words
    ("Your Blue Card renewal moves to 12 Feb", "2 new steps: …"). Dates and figures come only from the
    source-checked cards.
  - **Undo** uses Paperwork's own corrections (from session 2, 23 Oct):
    - a fact: `POST people/{p}/facts/{fact}/corrections` with the previous value from its history;
    - a step event: `POST processes/{id}/events/{event}/corrections`;
    - a document: `DELETE …/requirements/{r}/confirmation`.
  - **The plan is live, so "what changed" fits in one request.** `PlanReadModel::for` (behind
    `GET people/{p}/plan`) works the plan out from the current facts on every read, with no cache. So in
    one request Composer can: read the plan → make the change → read the plan again → diff the two.
    Reminders and notifications still run in the background through `ProcessReassessmentOutbox`.
    Composer never starts them and never calls `ReassessPerson`, which would bypass `delivered_at`
    (session 2, 23 Oct).
  - **One gap:** there's no "what changed" summary yet. Session 2 will build it as a plan diff: before and
      after `GET people/{p}/plan`, compared by process and step. Proposed shape:
      `PaperworkPlanDiff::between($before, $after)` → a list of
      {process, title, change: added, removed, due_moved, step_added, step_removed or state_changed,
      from, to}. Its dates and titles come only from the plan's own card fields. Composer would call
      the class directly, in the same request.
- **C, permanent or security.** Composer never does these. In a few words it says what would happen and
  opens the exact place: the Settings section, Paperwork's privacy page or the delete dialog.

## Rules kept

- **Haiku only.**
- **Privacy:** no coordinates and no addresses go to the AI, and none appear in URLs or logs. A home or
  work place is set by name or by a place the person picked. If they type an address, Composer passes
  it from their message straight to Travel's search; it isn't logged or sent back to the AI.
- **No keyword readers.** The AI chooses the tool; code only validates it.
- **Personal data:** never in the AI's sight without its consent. Paperwork needs `paperwork_answer`;
  taste and the day need the personalisation opt-in, as now.

## What it needs

1. Action tools in `AssistantTools`, each a thin call into the existing action classes. Where a
   controller holds the logic inline (place feedback, bookmarks, mutes, user places), that logic first
   moves into an action class that both the controller and Composer call.
2. The `changed` block and `POST composer/undo` with tokens, kept server-side.
3. The Ask first setting, applied in the server; its control is drawn by session 2.
4. The `paperwork` tool reading for the AI under `paperwork_answer`.
5. An eval set, `actions.*`, run on a scratch user. Each case checks:
   - the right tool is called with the right values;
   - nothing is written for a C ask;
   - Undo returns the record to its exact state;
   - Ask first holds the write;
   - no coordinates are in what the AI sees.
6. Tests: only Paperwork writes, consent and Undo on Paperwork, the sensitive part.

## Questions for the owner

1. Should Ask first be off by default everywhere, including Paperwork? Recommendation: yes for A; on for
   B until the change line has been seen working.
2. A first slice could be Paperwork facts and steps, saved places, My day and home/work; the rest would
   follow. Is that the right order?
3. How long should Undo last: this conversation, or 24 hours?
