# DPIA addendum: your calendar, leaving Home or Work, and the open points (17 Nov 2026)

**Status:** decided. This adds to `2026-11-16-dpia-draft.md`, which said to review it when calendar access or location outside the app is added. Both are now:
- **your calendar:** built, #528 and #529, on production 17 Nov;
- **noticing when you leave Home or Work:** designed, canvas "Expadu iPhone: When you leave", not yet built.

The owner decided on 17 Nov 2026 that no lawyer will be involved ("no lawyer will involve just u should know"). The open points in section 5 of the draft are therefore decided here. Where the law is unclear, the cautious reading is taken, and the reading is named.

## A. Your calendar (built)

**What is processed.** On the phone, the app reads the calendars the person chose, using EventKit full access. Full access is iOS's only read level. Birthdays and subscribed calendars start off.
- **Stays on the phone:** titles, places, notes and invitees. The phone uses them to show entries in Your day.
- **Sent to the server:** only when the person is busy today and tomorrow, as start and end times. They go in the `Expadu-Busy` header with each Today request.
- **What the server does with them:** uses them for that answer only, to leave out ideas that clash and to say "Busy until …" in the day line. It keeps nothing. A test (`CalendarBusyTest`) checks that no table holds the times. The header is not in the access logs.

**Basis.** Consent (Art. 6(1)(a) GDPR). Reading the calendar is also access to information stored on the device, so § 25(1) TDDDG applies.
- Both consents are given through the app's own switch, "Use my calendar on Today", after a screen that says what it does, then iOS's own prompt. Reading of § 25 taken: consent, not the "strictly necessary" exemption, because Today works without it.
- Withdrawal: the switch, or iOS Settings. Nothing is kept, so there is nothing to erase.

**Risks and measures**
- **Special categories in calendar titles** ("Doctor", "Mosque"): titles never leave the phone, so this risk is removed.
- **Busy patterns as a profile:** times are not kept, and only today and tomorrow are sent. Residual: low.
- **Sentry error reports could carry request headers:** already handled. `send_default_pii` is off, and `SentryScrubber` replaces the whole `headers` value of every event, so `Expadu-Busy` never reaches Sentry.

## B. Noticing when you leave Home or Work (designed)

**What is processed.**
- **On the phone:** the app uses iOS region monitoring (`CLMonitor`) for exactly two circles: the person's Home and Work, about 150 m each. iOS wakes the app when the person crosses one, in or out.
- **What it does with that:**
  1. On a day and time when a confirmed routine runs, the app asks the server for the next way to the routine's destination and shows a local notification ("Off to Work? Line 12 … in 4 min").
  2. It records "left Home 08:09, Wed" or "arrived at Work 08:34, Wed" for learning routines.
- **Sent to the server:** the label (Home or Work), in or out, and the time. Never a coordinate, never another place, never the path between.

**Basis.** Consent (Art. 6(1)(a) GDPR) plus § 25(1) TDDDG, through the app's own switch, "Notice when I leave Home or Work".
- It sits under "Learn from my trips" and only works with it on.
- Then comes the one-button Continue screen, then iOS's own upgrade to "Always".
- It doesn't touch the personalisation wording, so nobody is asked to agree again.

**Necessity.** The feature is the notification when you step out, and it can't work with the app closed without "Always". Two circles are the least that does it. No visit monitoring, no significant-change tracking and no continuous updates.

**Risks and measures**

| Risk | Measure | Residual |
|---|---|---|
| A movement profile of the person | Only two places the person set themselves, only in and out times. Kept 90 days with the trips (`trip_history`). Off erases it with the trips. | Low |
| Home and Work reveal sensitive facts (a Work that is a clinic or a church) | Home and Work are the person's own entries, already kept. Crossing times add when, not what. The `Sensitive` check that guards trips applies to Work too: a sensitive Work place is never monitored. | Low |
| Notifications showing private plans on a locked screen | The text names only the line, stop and time, and the routine's label ("Off to Work?"). | Low |
| Expectations: "the app tracks me" | Promises kept: "never exactly where you are" stays true. The switch says what it knows ("only Home and Work, only in or out"). What Expadu knows lists the last crossings it noted. | Low |
| Battery or iOS behaviour making it unreliable | Not a privacy risk. Said honestly in the feature: it may not fire if the app was force-quit (Apple's behaviour is contested). | n/a |

**Not yet allowed:** shipping to the App Store needs the paid Apple account, and App Review must see the feature that justifies "Always". Notes and a screen recording go with the review.

## B2. Composer helps with words (built 19 Nov 2026)

**What changes.** Composer may now translate, explain a German word, letter or form in plain words, explain how everyday things work in Germany, and draft a message for the person to send themselves. The owner approved the canvas "Expadu iPhone: Composer helps with words" on 19 Nov ("build it").
- **Sent to Anthropic:** nothing new by kind. What the person types was already sent under Composer's consent (`composer_assist`). The new part is what they may now paste: a line of a letter, a word from a form.
- **What comes back:** the AI's text, shown on a card marked "Written by Expadu's AI from what you told it. Check it before you use it." It stays in their conversation like any answer, and is deleted with it.
- **The notice** gains one line, "what Composer writes for you when you ask it to translate, explain or draft something, from what you wrote". Its version changes with it, and the app shows the current wording with each request.

**Basis.** Unchanged: consent (Art. 6(1)(a) GDPR), given per request with the notice shown.

**Risks and measures**
- **A pasted letter can hold other people's data or special categories** (a doctor's letter, the Ausländerbehörde). The person chooses what to paste, and nothing is kept beyond the conversation. Reading taken: no new basis is needed, because pasting is their own act under the same consent. Residual: medium, so the notice and the ask screen keep saying what is sent.
- **Wrong legal advice.** Composer may not say what the law requires, whether they must, or by when; that stays with the checked guides and Paperwork. A code check rejects any number in the AI's text (a date, an amount, a deadline) that wasn't in their own message, and has the text written again.
- **AI-generated text passed on as their own** (AI Act Art. 50). Every card says Expadu's AI wrote it. The person sends it themselves; Composer sends nothing.

## C. The draft's open points, decided

1. **Memory, with each message: consent or contract?** Consent stays. Cautious reading: the facts are optional and personal, and Composer works without them, so they are not necessary for the contract (Art. 6(1)(b) read narrowly, as the EDPB does).
2. **Is a self-declared 16+ enough?** Yes, for this risk level. Art. 8 GDPR asks for "reasonable efforts" with verification proportionate to the risk. Nothing here is high-risk to a child, and nothing sensitive is learned. Self-declaration with each yes is kept, and the age is not stored.
3. **Transfer impact assessment for Anthropic?** Yes; write a short one. Cautious reading: SCCs need a TIA (Schrems II), even where the EU–US Data Privacy Framework may also apply.
   - **To do:** `docs/privacy/2026-11-17-tia-anthropic.md`, covering what is sent (words, no coordinates, no ids), US law exposure, the no-training term and deletion times.
4. **AI Act Art. 50.** Decided in `2026-11-16-ai-notice.md`, option A, built in #517.
   - Composer says it's an AI assistant (Claude, by Anthropic) at the point of use. AI text is not deepfake or public-interest text, so Art. 50(4) marking does not apply. Art. 50(2) machine-readable marking applies to providers of the generating system (Anthropic), not to Expadu as a deployer.
   - Nothing else to add: Expadu's API doesn't mark answers in machine-readable form, and as a deployer it doesn't have to. Revisit if Composer's text is ever published outside the app.
5. **Are 90 days for trips and signals proportionate?** Yes. Routines need three weeks of history to find a pattern, and taste needs a few weeks to be stable. 90 days is about three to four times that window, and older items are pruned nightly. Review if the weekly measure shows learning saturates sooner.
6. **Is the founder's access logged?** Not enough. **To do:**
   - production reads go only through `php artisan` on the box, logged by the shell;
   - no admin panel shows personal data;
   - add a line to the record of processing that only the founder has access, with SSH keys only.

## D. Record of processing: entries to add

- Your calendar on Today: busy times, used per request, not stored.
- Noticing Home or Work: crossing times, 90 days, its own consent (when built).
- Interleaving (built, off): which order each idea came from, kept with the list shown (`list_impressions`), 90 days.
- Weekly totals (`personalisation_weeks`): aggregates only, no personal data.
