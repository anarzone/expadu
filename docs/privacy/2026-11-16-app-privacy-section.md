# Privacy policy: the iPhone app's part (draft, 16 Nov 2026)

**Status:** on the site since 17 Nov 2026 (owner's OK for the release). A lawyer hour is still advised.

The live policy (`resources/views/marketing/datenschutz.blade.php`, §6 "The Expadu app") describes the web app: browser location, browser push, and personalisation without memory or routines. The text below adds what the iPhone app does. It must be on the site before memory (#494), the iPhone personalisation switch (#495), the "What Expadu knows" screen (#497), moments (#499) or routines (#501) reach production. Until then, those stay on staging.

Proposed wording follows, in the policy's voice. Each part says where it goes.

---

## §6, after "The Expadu app" first paragraph: **The iPhone app**

The Expadu app for iPhone uses the same account and the same data as the web app. You can sign in with your email address, with Google or with Apple. With Apple, we receive an identifier from Apple and, if you choose to share it, your email address (or Apple's private relay address).

If you allow location access on your iPhone (while using the app), the app sends your position with the requests that need it: departures near you, routes, and when to leave. It is used for that request only. A trip you start keeps where it started for at most 24 hours.

While a trip you started is running, the app keeps following your position on the iPhone, even with the screen off. iOS shows this in the status bar. It uses it to tell you where to get off and when a change is at risk. Your position stays on the phone. To work out which tram you're on, only the stop you left and the time you left it are sent to our server, and they are not kept after the trip.

## §6 "Notifications", add: **On the iPhone**

On the iPhone, Expadu doesn't use a push service. When you open the app, it asks our server what needs telling in the next 36 hours, and your iPhone schedules those notifications itself:
- when to leave for a trip you set a reminder on, or for a routine you confirmed;
- an event you asked to be reminded of;
- a change to something you planned;
- the morning brief, if you keep it on.

They are shown by your iPhone, not sent through Apple's or anyone else's push service. A change after you last opened the app (a cancelled tram) reaches you the next time you open it.

Which kinds you get, and your quiet hours, are set in the app. Whether Expadu may notify you at all is set in your iPhone's Settings. Legal basis: contract performance (Art. 6(1)(b) GDPR), and your permission on the iPhone.

## §6 "Personalisation (opt-in)", add after its first paragraph: **What Composer remembers**

With personalisation on, you can tell Composer something lasting about yourself, such as "I'm vegetarian" or "we have a three-year-old". You can also add it yourself on "What Expadu knows". Composer keeps it under a pseudonymous key. With each message you send it, Composer then sends to Anthropic (Claude Haiku):
- what it remembers about you;
- what you tend to like and skip;
- the kinds of things you picked as what you like ("sports", "meeting people");
- your situation and German level.

This is so its answers fit you. An answer that relied on something you told it says so ("From what you told me: Vegetarian").

Expadu never keeps anything about health, religion or beliefs, sexuality, politics or a union. Composer refuses to remember it, and so does the screen.

You see everything on "What Expadu knows". You can change or forget any of it there, or turn personalisation off to erase all of it. Legal basis: your consent (Art. 6(1)(a) GDPR), given when you turn personalisation on, and again for each Composer message.

## §6, new paragraph: **Routines (opt-in)**

You can let Expadu learn your routines from the trips you start in the app. This is a separate choice from personalisation, it is off unless you turn it on, and it needs personalisation to be on. With it on, for each trip you start we keep:
- where it went: the place's name, or "Home" or "Work";
- whether it set off from your Home or Work. We keep nothing else about where it started;
- when.

Trips to health services, places of worship and LGBTQ+ venues are never kept. Trips are kept under a pseudonymous key for 90 days.

When the same trip comes up three times in three weeks at about the same time, the app offers it as a routine ("You often go to Work around 08:10"). It never sets one up without your yes. A routine you confirm tells you when to leave. You can change it, pause it or forget it, and turning routine learning off erases the trips and routines kept for it. Legal basis: your consent (Art. 6(1)(a) GDPR).

## §6, new paragraph: **Today's line about your day**

The line under the date on Today is written by our own code from what is on your day: your plans, appointments and trips, or what's on in the city. No AI writes it, and nothing is sent to anyone for it.

## §7 "Retention & deletion", add rows

- What Composer remembers: until you forget it, turn personalisation off or delete your account.
- Trips kept for routines: 90 days; routines until you forget them. Both are erased when you turn routine learning or personalisation off.
- Notifications on the iPhone: scheduled on your phone only; signing out removes them.
