# "Good for laptop work?" — data design

Status: design only, 23 Oct 2026. The owner liked the idea; nothing is built until he says "build it".
UI: the Designer's canvas. This doc covers storage, aggregation, abuse limits, privacy and how
Composer and search use it.

**Why:** open data and the cafés' own websites give Wi-Fi for ~11% of cafés and sockets or laptop
policy for ~1% (docs/places/enrichment/websites-2026-10-23.md). People who have been there are the
source that can fill it.

## 1. Storage

Two layers: the person's own answer (personal, erasable) and an anonymous aggregate (a place fact).

**`place_work_answers`** — one row per person per place, like `spot_feedback`:

| column | type | notes |
| --- | --- | --- |
| `id` | bigint | |
| `user_id` | FK users, **cascade on delete** | |
| `spot_id` | FK spots (canonical id), cascade on delete | answers follow identity merges like feedback |
| `laptop` | `yes` / `no` | the one-tap question |
| `wifi` | `yes` / `no` / null | optional follow-up |
| `sockets` | `yes` / `no` / null | optional follow-up |
| `quiet` | `yes` / `no` / null | optional follow-up |
| `answered_at` | timestamptz | updated whenever the person changes an answer |
| unique `(user_id, spot_id)` | | one answer per person per place |

- Changing an answer updates the row (no history kept). Withdrawing deletes it.
- Not a `place_fact_observations` row per person: observations are append-only provenance with no
  owner, so one person's answer couldn't be changed, withdrawn or erased cleanly.

**The aggregate** is recomputed when an answer changes (queued, debounced per place) and written as
one observation per place: provider `people`, `provider_record_id` = spot id, payload
`practical.laptops` / `internet_access` / `power_supply` (+ a `quiet` key added to
`PlaceCapabilities::TAGS`) and `evidence` = a count summary, never names or ids:
`{"laptops": "9 of 11 people said yes, last 12 months"}`. No user id is stored in it.

## 2. Aggregation

Per question, over answers in the last **12 months**, weighted by age: weight 1 for answers up to
6 months old, 0.5 from 6 to 12 months, ignored after 12 months (cafés change owners, Wi-Fi and
rules).

- **Minimum to show anything: 3 answers** (weighted count ≥ 3) from **3 different people**.
  Below that the fact stays unknown — "Not listed yet".
- **Shown fact** (yes share = weighted yes / weighted total):
  - ≥ 70% yes → `yes` ("Good for laptop work").
  - ≤ 30% yes → `no` ("Not good for laptop work").
  - In between → `mixed`. Shown as "Mixed reports"; it doesn't count as work-friendly and isn't excluded.
- **Website rule wins.** A café's own website rule with its quote (provider `website`,
  e.g. `laptops=limited`, `laptops:conditional=Sa-Su`, "Laptops sind am Wochenende nicht erlaubt")
  overrides people's answers for the days it names. People's `yes` still applies on the other days.
  The place's own source (OSM) still decides where it lists a value. Order: website rule (quoted) >
  OSM > people. A disagreement between people and the website/OSM is kept as `disputed_by: ["people"]`,
  not hidden.

## 3. Abuse limits

- Signed-in, verified accounts only.
- The question appears only on places the person marked **Been there** or **Saved**, or
  opened from their own plan. This is the cheapest strong signal they've been there.
- Rate limits: 20 answers per person per day, 3 changes per place per day (route throttle, as
  the app's other write limits).
- Accounts younger than 24 h: answers stored but not counted until the account is a day old.
- One answer per person per place (the unique key), so one person can't move a place.
- **No per-person exposure anywhere:** no names, counts per person, "you and 4 others", or
  answer lists. Not in the UI, not in Composer's prompts or tools (they receive only the
  aggregate fact and the count summary), not in admin exports.
- Moderation: an admin can drop a place's aggregate (a fact correction), not edit people.

## 4. Privacy (to check with the owner's Datenschutz review; keep it accurate)

- Stored about the person: which places they answered and their yes/no answers, with the time.
  Purpose: improving place information for everyone. Legal basis: the person's own action
  (Art. 6(1)(f) legitimate interest, or (a) if the owner prefers it as consent; his call).
- Kept while the account exists; an answer counts for 12 months, and rows older than 18 months
  are deleted by a nightly prune.
- Erasure: deleting the account deletes the rows (cascade), then the aggregate is recomputed.
  Withdrawing one answer deletes that row. The aggregate holds no personal data.
- Export: included in the person's data download as "Your place answers".
- The aggregate never leaves Expadu except as an anonymous fact in answers. Composer sends the
  count summary to the AI processor; it already covers place facts.
- **Datenschutz needs one line** in the app's own privacy section (still to be written per the
  release checklist): "If you tell us whether a place is good for laptop work, we store your answer
  with your account, use it only in anonymous totals (from 3 people), and delete it with your
  account or after 18 months."

## 5. Composer and search

- `search_places` `features`: `laptop` (new; added to `PlaceAmenities`) and `wifi` read the
  resolved practical facts, so people's aggregate counts exactly like OSM or website facts:
  `laptops` = yes (from any source, website rule respected by day) passes; `mixed` and unknown
  don't pass a hard feature filter. For the soft `to_work` switch, `no` excludes, `mixed` is kept
  but ranked below `yes`.
- Composer cites by source, from the fact's provider and `evidence`:
  - people: "People who've been there say it's good for laptop work (9 of 11)."
  - website: "Its website says laptops aren't allowed at weekends."
  - OSM: "It lists Wi-Fi."
  - unknown: "Laptop work isn't listed yet."
- The count summary is the only people data Composer sees.

## Open questions for the owner

1. Minimum answers: 3 (proposed). 5 is safer against a few friends gaming a café, but fills more slowly.
2. Ask only after Been there or Saved, or on any café sheet? Asking only after them gives fewer but
   more trustworthy answers.
3. Follow-ups (Wi-Fi, sockets, quiet): ask all three after a "yes", or only Wi-Fi?
4. Legal basis wording for the Datenschutz line (above).
