# DPIA draft: personalisation, memory, routines and location (16 Nov 2026)

**Status:** draft for the owner and one lawyer hour (track, week 3). It is structured along Art. 35(7) GDPR. It describes the code on `staging` as of 16 Nov 2026. The data itself is in `2026-11-16-data-map.md`.

## Why a DPIA is required

The German regulators' must-list (DSK, the "Muss-Liste") requires one here: [DSK list](https://www.lda.bayern.de/media/dsfa_muss_liste_dsk_en.pdf). Expadu does both of the following:
- **item 2:** extensive processing of location data. It does this through trips, a trip's live position on the phone and routines;
- **item 7:** comprehensive profiles of interests. It does this through taste learned from what people open, save and skip, and through what Composer remembers.

## 1. What is processed, and how (Art. 35(7)(a))

**Purposes**
- Suggestions that fit the person (Today's ideas and order).
- Composer answers that know the person.
- Notices in time (leave times, risks to plans).
- Routines learned from trips.

**Data**
- Opens, saves, skips and trips to catalogue places.
- Lasting facts the person tells Composer.
- Situation and German level.
- Trips started: destination, Home or Work origin, and time.
- Confirmed routines.

**Raw location:**
- **Live position during a trip:** used on the phone only. The server gets the stop left and the time.
- **Positions sent with requests:** used for that request only. Web location history is kept 7 days, with the share-location setting.

**Flow**
- The phone or browser sends the server what the person does.
- With the opt-ins, the server keeps it under a pseudonymous key.
- Composer's messages and Today's ranking send what is needed to Anthropic (Claude Haiku):
  - taste in words;
  - remembered facts;
  - situation and German level;
  - nearest stop and Veedel, never coordinates.
- Notices are computed for the next 36 hours and scheduled on the phone.

**Who:** the person; Expadu (one founder); Anthropic as processor (DPA with SCCs, no training); hosting at Hetzner behind Cloudflare; Sentry for errors.

## 2. Necessity and proportionality (Art. 35(7)(b))

**Legal basis.** Consent (Art. 6(1)(a)), asked per purpose:
- **Personalisation:** off by default, needs the person's word that they are 16 or older, and the wording is versioned. A changed wording asks again.
- **Routine learning:** its own yes, off by default, and only with personalisation on.
- **Composer:** each message is consented for 15 minutes.
- **Composer with Paperwork:** its own per-message permission.

Everything works without these consents, only less personally.

**Minimisation**
- Pseudonymous keys.
- No coordinates for personalisation.
- The trip origin is kept only as Home or Work, or not at all.
- Destinations are keyed about 100 m wide.
- Facts are capped at 40 and 160 characters each.
- What Composer receives is cut to words; never the person's coordinates or Home's address.

**Storage limitation**
- Signals and trips: 90 days.
- Composer traces: 30 days.
- Location history: 7 days.
- Turned-down routine offers: 30 days.
- Memory and confirmed routines: until forgotten.
- Turning an opt-in off erases what it kept, not just pauses it.

**Transparency and control**
- "What Expadu knows" shows remembered facts, taste, situation and German level. Every fact can be changed or forgotten (forget has Undo).
- Answers that used a fact say so ("From what you told me").
- Download your data includes everything kept.
- Withdrawing a consent is as easy as giving it (Art. 7(3)).

## 3. Risks to people (Art. 35(7)(c))

| Risk | How it could happen | Likelihood | Severity |
|---|---|---|---|
| R1. Special categories inferred | Trips to a mosque, clinic or LGBTQ+ venue; a fact like "halal" or "wheelchair" | Medium without measures | High |
| R2. Movement profile re-identifies a person | Destinations and times over 90 days | Medium | High |
| R3. Profiling the person didn't expect | Learning from actions without them realising | Medium | Medium |
| R4. AI processor in the USA | Remembered facts and taste in every Composer message | Certain (by design) | Medium |
| R5. Wrong or overreaching AI answers about the person | Composer misusing or inventing personal facts | Medium | Medium |
| R6. Minors | Under-16s turning personalisation on | Low | High |
| R7. Breach of the database | Server compromise | Low | High |
| R8. Notices revealing private plans on a locked screen | A leave reminder naming an appointment | Medium | Low |

## 4. Measures, and what's left (Art. 35(7)(d))

- **R1.**
  - `Memory` refuses health, religion or beliefs, sexuality, politics and unions in English and German, including halal and kosher, wheelchair, and party names.
  - `Sensitive` keeps health services, places of worship, LGBTQ+ venues and sensitive events out of signals and out of trips kept for routines: the place at the point, any within about 40 m, an event marked sensitive, and the destination's words.
  - The step-free need is never told to the AI as a fact about the person. Only results say "Step-free access listed" when the person needs it.
  - Residual: low. Word lists can miss an indirect phrase, so name a review rhythm.
- **R2.**
  - No coordinates are kept.
  - The trip origin is reduced to Home or Work.
  - Destinations are keyed about 100 m wide.
  - 90-day pruning.
  - A separate opt-in for routines.
  - Residual: low to medium. Destination names can still be specific, such as a person's own address typed in. **Open:** should a destination that isn't a catalogue place, Home or Work be kept only as its key, without its name?
- **R3.**
  - Opt-ins are off by default and say what is learned.
  - "What Expadu knows" shows it, and every answer that used a fact says so.
  - Routines are offered, never assumed.
  - Residual: low.
- **R4.**
  - Anthropic's DPA with SCCs.
  - No training.
  - Only what the answer needs is sent; never coordinates, documents or another person's records.
  - Composer's consent names the categories.
  - Residual: medium. This is accepted for the product's core and needs the lawyer's view on whether a TIA is required.
- **R5.**
  - Every sentence is checked against the facts it cites. Unsupported sentences are dropped (`AroundCheck`).
  - Personal facts can be cited only by their ids.
  - Constraints are checked in code (#496, #498).
  - Residual: medium. The blind grade is 3.27/5, and the track's gate is 4.0.
- **R6.**
  - The person's word that they are 16 or older, with each yes.
  - Residual: medium. There is no verification. Does the lawyer consider self-declaration enough here?
- **R7.**
  - Pseudonymous keys; personal data separated from the account id.
  - Hosting in Germany.
  - Encrypted transport.
  - Deletion paths.
  - Residual: low to medium. **Open:** encryption at rest and a written breach procedure.
- **R8.**
  - Notification words are the minimum ("Time to leave for Blue Card appointment").
  - **Open:** let iOS hide previews, or offer "hide what it's for" in Notifications.

## 5. Open for the lawyer

1. Is consent the right basis for memory, given that it's sent with each message, or is part of it contract performance?
2. Is self-declared 16+ enough for personalisation?
3. Is a transfer impact assessment needed for Anthropic?
4. AI Act Art. 50(1) and (2) (see `2026-11-16-ai-notice.md`).
5. Retention: are 90 days for trips and signals proportionate?
6. Is the one founder's access logged sufficiently?

The DPIA is to be reviewed when routines from location visits (outside the app) or calendar access are added. The plan has them as slice 4, with Always location, each a new opt-in.
