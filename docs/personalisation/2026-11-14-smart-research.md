# Smart features: research brief (as of 14 Nov 2026)

Scope: A) the app reaches out (notifications), B) learns your routine, C) personalised suggestions, plus 6) signals from outside the app and 7) assistant personality and trust. Each claim has its source. "Older" marks pre-2024 material.

## TL;DR
- **Free Personal Team:** no APNs push, no Time Sensitive, no WeatherKit, no Siri, no Focus status. Local notifications, background location, geofences, visits, Live Activities and App Groups all work. Builds expire after 7 days. ([Apple capability table](https://developer.apple.com/help/account/reference/supported-capabilities-ios), [Apple memberships](https://developer.apple.com/support/compare-memberships))
- **Background refresh can't be scheduled reliably.** iOS has no way to run code at a specific time. Being on time comes from three things: local notifications computed ahead, location wake-ups (visit, geofence, significant change), and keeping the app alive with background location during a trip. ([Quinn, Apple DTS, updated Jan 2026](https://developer.apple.com/forums/thread/685525))
- **Routine features are opt-in.** Every reference app asks before learning a routine, lets the person name and pause it, and alerts only on significant deviations. Apple Maps (iOS 26) and Google Timeline (2024–25) both moved the learning on-device.
- **Trust comes from a visible, editable memory, an explanation for every suggestion, no sensitive inferences, and easy dismissal.**

---

## 1. How the reference apps do A and B

| App | What it notifies, and when | Permission and setup | Controls and anti-fatigue | User feedback |
|---|---|---|---|---|
| **Transit** | GO trips (only for departures within 60 min): "Go in … minutes", "Hurry up!", a stop countdown, "Your stop is next!", "This is your stop!", plus an optional "Send reminder" before leaving. A Live Activity covers the whole trip, and the phone wakes only for action alerts such as "Leave now" or "Get off in 2 stops". ([help 549](https://help.transitapp.com/article/549-how-to-use-go)) Line alerts come from agency service alerts at red/yellow severity. ([help 96](https://help.transitapp.com/article/96-get-notifications-about-disruptions-on-your-line)) | Alerts are set per line with the bell icon, so the ask comes in context. | Line alerts default to weekdays 7–9 and 16–19. A day×hour grid sets custom hours, one schedule can cover many lines, and "Never" turns a line off. Info-level alerts are never pushed. ([help 96](https://help.transitapp.com/article/96-get-notifications-about-disruptions-on-your-line)) | Reviewers praise the Live Activity, which expands at 2 stops and again at your stop. ([MacStories 2024](https://www.macstories.net/reviews/transit-is-still-the-best-designed-transit-app-on-the-iphone-in-2024/)) Complaints are about prediction accuracy, not alert volume. ([OnClarity](https://www.onclarity.com/leaderboard/insight/transitapp)) |
| **Citymapper** | Line-status/disruption alerts, with a "commute only" window that limits alerts to regular commuting hours ([older post](https://content.citymapper.com/news/858/disruption-notification-for-septa)). Calendar sync shows when to leave for events, in-app and not pushed ([Citymapper](https://www1.citymapper.com/i/2490/sync-your-calendar-with-citymapper)). From 2016, Smart Commute showed a live lock-screen card each morning ([TNW 2016, older](https://thenextweb.com/news/citymappers-new-smart-commute-makes-your-journey-to-work-a-little-less-painful)). | Onboarding uses pre-permission screens. Location: "get from A to B". Notifications: concrete examples ("next stop", "delays on your line") with a soft "Maybe Later". ([Lazyweb flow](https://design.lazyweb.com/canvas/flows/citymapper/location-and-notification-opt-ins)) | Settings → Notifications in the app, which links to iOS settings. ([Citymapper help](https://citymapper.com/news/2397/terms)) | The founder: "Humour plays a big role", and the app shows "thinking" rather than "loading". ([Tempus 2018](https://tempusmagazine.co.uk/news/discover-the-design-hotlist-citymapper-founder-gilbert-wedam)) |
| **Google Maps** | On iOS: "Reminders for when to leave for an event", built from calendar events with a location ([help](https://support.google.com/maps/answer/6149565?hl=en&co=GENIE.Platform%3DiOS)). From 2018, commute delay notifications for mixed car and transit trips, Android only ([Google 2018, older](https://blog.google/products/maps/take-control-your-commute-google-maps/)). The Go tab offers "suggested trips" that you pin (driving and transit only) ([help](https://support.google.com/maps/answer/10271256)). On Android, "Traffic updates when not using Maps" needs Always location, precise location and Physical activity permission ([help](https://support.google.com/maps/answer/7667679?hl=en)). | Home and work are set by the user. | Every notification type has its own switch. ([help](https://support.google.com/maps/answer/6149565)) | People are confused by "random" Time-to-leave alerts, which turn out to come from calendar locations ([Reddit mirror](https://redlib.hbubli.cc/r/AndroidQuestions/comments/run0kh/getting_random_time_to_leave_for_notifications)). Departure alerts broke silently in 2018 ([Android Police, older](https://www.androidpolice.com/2018/04/23/google-departure-time-notifications-stopped-working-many-users/)). |
| **Apple Maps / iOS 26** | Preferred Routes learns your regular routes on-device and alerts "about significant delays, even before you head out" ([9to5Mac](https://9to5mac.com/2025/12/09/ios-26s-apple-maps-feature-can-save-you-from-traffic-heres-how-to-set-it-up/), [Apple](https://www.apple.com/newsroom/2025/06/apple-elevates-the-iphone-experience-with-ios-26/)). The Maps Suggestions widget appears only when there's a route issue ([MacRumors](https://www.macrumors.com/guide/ios-26-maps/)). Calendar's Time to Leave uses your likely location plus traffic or transit, and skips trips over 3 h ([Apple, Mac guide, older](https://support.apple.com/guide/calendar/icl43600/mac)). Weather sends severe-weather alerts for predicted destinations, which needs Significant Locations and Always location ([MacRumors](https://www.macrumors.com/2025/06/10/ios-26-severe-weather-predicted-destinations/)). | Preferred Routes and Visited Places use an in-app opt-in prompt (Continue → Allow). | Settings → Apps → Maps → Location → "Preferred Routes & Predicted Destinations". Visited Places keeps history for 3 months, 1 year or forever, swipe deletes one place, and Clear History deletes all. ([MacRumors](https://www.macrumors.com/guide/ios-26-maps/)) Significant Locations & Routes is end-to-end encrypted and "cannot be read by Apple". ([Apple](https://www.apple.com/legal/privacy/data/en/location-services/)) | Reaction is split. One headline: "Apple Maps Is Tracking You" ([BGR](https://bgr.com/2004060/how-to-turn-off-apple-maps-visited-places-ios-26-feature-spying)). Forum users push back that it is opt-in, and some never saw the prompt. ([MacRumors forum](https://forums.macrumors.com/threads/apple-maps-may-be-logging-places-you-visit-how-to-disable.2468205)) |
| **DB Navigator** | "Reise merken" and "Wiederholende Reise" (for commuters) send timetable changes and boarding/transfer reminders. Notifications start at the earliest 14 days before travel. The first train triggers one if it is more than 5 min late before departure. Later changes are sent only 2 h before arrival, unless a connection is at risk, which is sent immediately. There's no alert if the transfer still works. All notifications lapse at the December timetable change. The stated aim: "nur so viele Benachrichtigungen wie nötig". ([bahn.de](https://www.bahn.de/bahnbusiness/faq/pk/service/fahrplaene/benachrichtigungen)) | A DB login is required. iOS permission is asked on first activation. ([bahn.de](https://www.bahn.de/p/view/service/auskunft/verspaetungsalarm-push.shtml)) | You pick which notification types and which weekdays, can name the trip (e.g. "Zur Arbeit"), and can pause it. Taking an alternative pauses the routine for that day. ([bahn.de](https://www.bahn.de/faq/gk/info-services/dbnavigator/funktionen)) | An anonymous comment says the commuter option was missing ([iphone-ticker](https://www.iphone-ticker.de/db-navigator-mit-pendler-funktionen-andere-bahn-apps-werden-belanglos-234141/)). A bug produced false disruption notices ([Utopia](https://utopia.de/news/wegen-systemfehler-db-navigator-zeigt-falsche-info-an_712552)). |
| **KVB-App** | The store listing doesn't mention push. Disruptions appear only under "News" (developer reply). | n/a | n/a | Rated 1.6★ from 719 ratings. Reviewers say the "live" times are just the timetable, and that carnival changes and cancellations are missing. ([App Store DE](https://apps.apple.com/de/app/kvb-app/id1441639226)) |
| **VRS app** | Event notifications for individual lines, optionally as push. Connections can be pinned to the dashboard. ([vrs.de](https://www.vrs.de/en/connections/the-vrs-app)) | n/a | n/a | n/a |

**Patterns these apps share:** permission is asked when the person sets up a trip or line alert. Alerts are scoped to their own lines and their own hours. Only significant deviations trigger an alert (DB uses 5 min, Apple says "significant delays"). One trip is a single evolving object, a Live Activity or a single notification, not a stream of pushes.

---

## 2. Apple guidance (HIG and docs)

**Interruption levels** ([HIG: Managing notifications](https://developer.apple.com/design/human-interface-guidelines/managing-notifications)):

| Level | Apple's example | Overrides scheduled summary | Breaks through Focus | Overrides silent switch |
|---|---|---|---|---|
| Passive | Restaurant recommendation | No | No | No |
| Active (default) | Sports score | No | No | No |
| Time Sensitive | Security issue, package delivery | Yes | Yes | No |
| Critical | Health or safety (needs an entitlement) | Yes | Yes | Yes |

- Time Sensitive is "only for notifications that are relevant in the moment", meaning events happening now or within an hour. iOS explains the level the first time one arrives and offers a way to turn it off, then asks again periodically. Marketing must never use it. ([HIG](https://developer.apple.com/design/human-interface-guidelines/managing-notifications))
- Notification summary: `relevanceScore` (0–1) decides which notification is featured in the summary. ([Apple](https://developer.apple.com/documentation/usernotifications/unmutablenotificationcontent/relevancescore)) Apple Intelligence "Prioritize Notifications" (since iOS 18.4) is a separate on-device ranking that users can switch off per app. ([iDrop News](https://www.idropnews.com/news/ios-184-delivers-priority-notifications-heres-how-they-work/243601))
- Best practice: "Avoid sending multiple notifications for the same thing", or people "may turn off all notifications". Don't put private information in the body. Provide a placeholder text for hidden previews. Offer up to 4 actions, prefer non-destructive ones, and avoid an action that only opens the app. ([HIG: Notifications](https://developer.apple.com/design/human-interface-guidelines/notifications)) Group related notifications with `threadIdentifier`. ([Apple](https://developer.apple.com/documentation/usernotifications/unmutablenotificationcontent/threadidentifier))
- Permission: ask in context, for example "after the person schedules a first task", not at first launch. **Provisional** authorisation needs no prompt. Those notifications land quietly in Notification Center with Keep and Turn Off buttons. ([Apple](https://developer.apple.com/documentation/usernotifications/asking-permission-to-use-notifications)) Location permission is also requested only when someone engages a feature that needs it. ([Apple](https://developer.apple.com/documentation/corelocation/requesting-authorization-to-use-location-services))
- **Live Activities vs notifications:** use a Live Activity for tasks with a defined start and end, up to 8 h. Alert only for essential updates, and "don't use push notifications alongside Live Activities for the same updates". End it as soon as the task ends; a 15–30 min dismissal is usually enough. Give people a way to turn it off in the app. ([HIG](https://developer.apple.com/design/human-interface-guidelines/live-activities)) Technical limits ([ActivityKit](https://developer.apple.com/documentation/activitykit/displaying-live-data-with-live-activities)):
  - Up to 8 h active, at most 12 h on the Lock Screen, 4 KB of data.
  - It can't use the network or location itself.
  - It normally starts in the foreground, or from a `LiveActivityIntent`.
  - The app can update it while running in the background.
  - Since iOS 26.0 it can be scheduled with a `startDate` and an `AlertConfiguration`. ([API](https://developer.apple.com/documentation/activitykit/activity/request(attributes:content:pushtype:style:alertconfiguration:startdate:)))

---

## 3. Free Personal Team: what works

Source for capabilities: [Apple, Supported capabilities (iOS)](https://developer.apple.com/help/account/reference/supported-capabilities-ios). The free "Apple Developer" column includes only: App Groups, Background Modes, Data Protection, HealthKit, HomeKit, Inter-App Audio, Keychain Sharing, Maps (without MapKit routing directions) and Wireless Accessory Configuration.

| Feature | Status | Caveats |
|---|---|---|
| Local notifications (time, calendar, location triggers) | **Works** | Limit of 64 pending per app ([forum](https://developer.apple.com/forums/thread/811171)). `UNLocationNotificationTrigger` needs only When-in-Use, because the system monitors the region, but the content is fixed when scheduled ([Apple](https://developer.apple.com/documentation/usernotifications/unlocationnotificationtrigger)). |
| Time Sensitive interruption level | **Doesn't** | The capability is paid-only. Developers report `.timeSensitive` doesn't work without it ([forum](https://developer.apple.com/forums/thread/683630)), so alerts can't break through Focus or the summary. |
| Critical alerts | **Doesn't** | Needs an Apple-approved entitlement. |
| APNs push, silent push, ActivityKit push, Notification Service Extension | **Doesn't** | No push capability on a free team. |
| Background Modes (location, fetch, processing) | **Works** | Use them for their intended purpose only, per App Review 2.5.4. |
| BGAppRefreshTask / BGProcessingTask | **Works, unreliable** | See §4. |
| Significant-location-change, visits, region monitoring / `CLMonitor` | **Works** (no entitlement) | Relaunching a terminated app requires **Always** authorisation ([Apple](https://developer.apple.com/documentation/corelocation/requesting-authorization-to-use-location-services)). Limits: 20 conditions per app ([Apple](https://developer.apple.com/documentation/corelocation/monitoring-the-user-s-proximity-to-geographic-regions)), radius effectively 100–500 m, relaunch throttled to once every 3–5 min. Region monitoring relaunches the app even after a force-quit ([Apple engineer, Mar 2026](https://developer.apple.com/forums/thread/818908)). For SLC after a force-quit, one unanswered report says it doesn't relaunch on iOS 26.4.2 ([forum, May 2026](https://developer.apple.com/forums/thread/826964)). |
| Continuous background location (`CLBackgroundActivitySession`) | **Works** | Works with When-in-Use and shows the system location indicator ([Apple](https://developer.apple.com/documentation/corelocation/handling-location-updates-in-the-background)). |
| Live Activities | **Works** | Needs only the `NSSupportsLiveActivities` key. Updates come from the app only, not push. |
| App Groups (sharing data with widgets and Live Activities) | **Works** | |
| WeatherKit, Siri (SiriKit), Communication Notifications (so no Focus status), iCloud, Sign in with Apple, Associated Domains, Journaling Suggestions | **Doesn't** | Paid-only. |
| Expiry | Every 7 days | Profiles expire 7 days after issue, so rebuild and reinstall. Limits: 3 apps per device, 10 App IDs per 7 days, 3 devices. ([Apple](https://developer.apple.com/support/compare-memberships)) What happens to pending notifications after expiry isn't documented, so assume everything stops. |

---

## 4. Background reliability, and what to use instead

- Apple DTS: refresh is not regular. "There are common scenarios where it won't grant you any background execution time at all!" There is no general mechanism for "running code at some specific time" or "periodically at a guaranteed interval". A force-quit sets a flag that blocks background launches until the user opens the app again. ([thread 685525, updated 2026-01-09](https://developer.apple.com/forums/thread/685525))
- Apple says timing is "primarily driven by device condition and user activity". "An app that the user runs infrequently is likely to receive no app refresh time", and processing tasks run "typically overnight" on power. ([thread 725675, 2023](https://developer.apple.com/forums/thread/725675)) WWDC20 lists 7 factors: critically low battery, Low Power Mode, app usage, App Switcher (force-quit), the Background App Refresh switch, system budgets and rate limiting. ([WWDC20 10063 notes](https://wwdcnotes.com/documentation/wwdc20-10063-background-execution-demystified/))
- A refresh task gets up to 30 s. ([Apple](https://developer.apple.com/documentation/backgroundtasks/choosing-background-strategies-for-your-app)) After any wake, iOS may suspend the app right away. `beginBackgroundTask` gives about 30 s, and the wake reason doesn't restrict what the app can do in that time. ([Apple engineer, Feb 2026](https://developer.apple.com/forums/thread/815618))
- Significant-location-change fires after about 500 m and no more often than every 5 min. ([Apple](https://developer.apple.com/documentation/corelocation/cllocationmanager/startmonitoringsignificantlocationchanges()))
- What transit apps do instead: DB, Citymapper and Google push from their servers, which isn't possible on a free team. Transit's GO starts in the foreground and then keeps a Live Activity running throughout the trip.

A free-team pattern built from the documented pieces:
1. **Leave-at times:** compute ahead and schedule `UNCalendarNotificationTrigger` notifications. Reuse the same identifier so each reschedule replaces the old one.
2. **Morning:** use the visit departure from home and a geofence exit at home as wake points. Each gives about 30 s to fetch live data and reschedule or post the notification.
3. **During a trip:** a `CLBackgroundActivitySession` keeps the app alive. Poll real-time data, update the Live Activity, and post local notifications for delays and connections at risk.
4. **Morning brief and rain:** only the delivery time is reliable. Content can be no fresher than the last wake. Weather has to come from the server (DWD via e.g. [Bright Sky](https://brightsky.dev/)), because WeatherKit isn't available.

---

## 5. Routine detection in practice

- **Apple:** Significant Locations & Routes records "places you have recently been, how often and when you visited them, and the routes you take". It is synced end-to-end encrypted. ([Apple](https://www.apple.com/legal/privacy/data/en/location-services/)) Apple's privacy text mentions sharing Preferred Routes and Visited Places "with an app", but **I found no third-party developer API for this.**
- **Google:** Timeline moved on-device between 2024 and June 2025, with auto-delete after 3, 18 or 36 months. ([Google](https://support.google.com/maps/answer/14169818))
- **Building blocks:**
  - `CLVisit` gives arrival and departure (sometimes only arrival), at reduced accuracy without precise location, and relaunches the app. ([Apple](https://developer.apple.com/documentation/corelocation/clvisit), [Apple](https://developer.apple.com/documentation/corelocation/cllocationmanager/startmonitoringvisits()))
  - The literature defines a stay point as staying within about 200 m for 20–30 min. Conservative settings (100 m, 3 min) found most true significant places. ([arXiv 1904.09357](https://arxiv.org/pdf/1904.09357))
  - Stay points are then clustered into places, home and work are labelled by night and weekday-day dwell, and weekday×hour histograms of A→B departures are built.
  - Eigenbehaviors, an older method, predicted the rest of the day at 79% from its first half. ([Eagle & Pentland 2009](https://dspace.mit.edu/handle/1721.1/49446))
- **Why raw traces should stay on the device:** 4 spatio-temporal points uniquely identify 95% of 1.5 M people. ([de Montjoye 2013](https://www.nature.com/articles/srep01376))
- **EU expectations:**
  - The EDPB (June 2026, mobility apps) says location data "can be particularly revealing", hinting at religion, sexual orientation or lifestyle. It says to prefer local processing and notes that ePrivacy requires prior consent for terminal access. ([EDPB](https://www.edpb.europa.eu/system/files/2026-11/edpb-guidelines-at-a-glance-connected-vehicles-mobility-data_en.pdf))
  - In Germany the terminal-access rule is §25 TDDDG, formerly TTDSG. ([gesetze-im-internet](https://www.gesetze-im-internet.de/ttdsg/__25.html))
  - WP29 (2011, older): prior consent, location off by default, and consent renewed about yearly. ([summary](https://www.hunton.com/privacy-and-cybersecurity-law-blog/article-29-working-party-opines-on-geolocation-services))
  - GDPR Art. 7(3) says withdrawal must be as easy as consent, and Art. 25 requires data protection by design. ([GDPR](https://eur-lex.europa.eu/eli/reg/2016/679/oj))
- **Phrasing in the wild:**
  - DB lets people name a routine ("Zur Arbeit").
  - Apple alerts only on "significant delays".
  - Transit uses short imperatives ("Hurry up!").
  - Apple's HIG recommends an attribution like "Because you've read mysteries". ([HIG ML](https://developer.apple.com/design/human-interface-guidelines/machine-learning))

---

## 6. Signals from outside the app

| Signal | API | Permission / Info.plist | Background behaviour | Free team |
|---|---|---|---|---|
| Visits | `startMonitoringVisits` / `CLVisit` | `NSLocationWhenInUseUsageDescription` + `NSLocationAlwaysAndWhenInUseUsageDescription` | Relaunches the app (with Always) | Yes |
| Significant change | `startMonitoringSignificantLocationChanges` | Same keys | Relaunches (with Always); ~500 m / 5 min | Yes |
| Geofences | `CLMonitor` | Same keys | Relaunches (with Always), even after force-quit; 20 max | Yes |
| Location-triggered notification | `UNLocationNotificationTrigger` | When-in-Use | The system fires it without waking the app | Yes |
| Motion activity | `CMMotionActivityManager` | `NSMotionUsageDescription` (the app crashes without it) | Can query the last 7 days, so trips can be reconstructed when the app is next opened. Classes: walking, running, cycling, automotive, stationary. There is no transit class. ([Apple](https://developer.apple.com/documentation/coremotion/cmmotionactivitymanager), [Apple](https://developer.apple.com/documentation/coremotion/cmmotionactivity)) | Yes |
| Calendar and Reminders | EventKit | iOS 17+: `NSCalendarsFullAccessUsageDescription` (needed to read; write-only can't read), `NSRemindersFullAccessUsageDescription` ([Apple](https://developer.apple.com/documentation/eventkit/accessing-the-event-store)) | Read when the app runs | Yes |
| Focus status | `INFocusStatusCenter` | `NSFocusStatusUsageDescription` plus the Communication Notifications capability ([forum](https://developer.apple.com/forums/thread/682143)), intended for communication apps | n/a | **No** |
| Shortcuts / Siri Suggestions | `IntentDonationManager` | None documented. iOS learns patterns and suggests the action on the Lock Screen, in Siri Suggestions and in Search. Donate only after the user completes an action, and delete stale donations. ([Apple](https://developer.apple.com/documentation/appintents/intentdonationmanager)) | System-side | Likely (App Intents need no capability row). Verify on a device. |
| Steps | HealthKit | Health usage keys | Observer queries | Yes (low value) |
| Weather | WeatherKit | n/a | n/a | **No**; use the server |
| Significant Locations, Visited Places, Screen Time | none public | n/a | n/a | Not accessible |

**App Review** ([guidelines](https://developer.apple.com/app-store/review/guidelines/)):
- **5.1.1(ii):** get consent, provide an easy way to withdraw it, and write purpose strings that "clearly and completely" describe the use.
- **5.1.1(iii):** collect only the data that's needed.
- **5.1.1(iv):** offer alternatives when permission is declined, such as entering an address manually.
- **5.1.2(i):** get permission before using data, and get explicit permission before sharing with third-party AI. Apps may not require push or location to unlock functionality.
- **5.1.5:** use location only when it is directly relevant, with notice and consent.
- **2.5.4:** use background modes only for their intended purpose.
- **4.5.4:** notifications must not be required, and marketing needs an opt-in plus an in-app opt-out.

---

## 7. Assistant personality and trust

**How others did it:**
- **Google Now (2012, older):** proactive cards from time and location. It was called "a fine line between cool and creepy" ([TechCrunch](https://techcrunch.com/2012/07/22/google-now-2/)). A Sunday drive-time card to a church made people wonder how Google knew about the church, which is exactly the inference the EDPB warns about ([Celent](https://celent.com/insights/852651959)).
- **Cortana Notebook (2014, older):** "where she keeps track of everything she knows about you", including interests, which people could edit and clear. It was launched as a privacy feature. ([Windows Central](https://www.windowscentral.com/cortana-privacy-prioritized-notebook))
- **ChatGPT memory:**
  - A "Memory updated" notice links to "Manage memories", and you can ask ChatGPT what it remembers.
  - OpenAI steers it away from proactively remembering sensitive information "like your health details - unless you explicitly ask it to".
  - Temporary chats don't use or create memories, and deleting a chat doesn't delete the memory. ([OpenAI 2024](https://openai.com/blog/memory-and-new-controls-for-chatgpt), [Memory FAQ](https://help.openai.com/en/articles/8590148-memory-faq))
- **Gemini:** memory sits under Settings → Personal Intelligence, with a global switch, a per-chat off switch, opt-in connected apps and temporary chats. ([Google](https://support.google.com/gemini/answer/16598469))
- **Apple Intelligence personal context:** announced in 2024 and delayed in March 2025 ([MacRumors](https://www.macrumors.com/2025/03/07/apple-intelligence-siri-features-delayed/)). It hadn't been confirmed as shipped as of 2026 reports. That is a signal of how hard personal context is to do privately and well.
- **Citymapper:** its personality lives in its copy, with humour and the "thinking" wording. ([Tempus](https://tempusmagazine.co.uk/news/discover-the-design-hotlist-citymapper-founder-gilbert-wedam))

**Documented design rules:**
- **Apple HIG, Machine learning** ([link](https://developer.apple.com/design/human-interface-guidelines/machine-learning)):
  - People tolerate low-quality results less in proactive features.
  - Use attributions ("Because you…") to build trust and soften mistakes.
  - Withhold private or sensitive suggestions, especially on shared devices.
  - Give recent feedback more weight.
  - Don't let implicit feedback narrow exploration; beware confirmation bias.
  - Tell people how information flows and let them restrict it.
  - Count favourites as implicit feedback, and ask for explicit feedback only when necessary.
- **Apple HIG, Generative AI** (updated 8 Jun 2026, [link](https://developer.apple.com/design/human-interface-guidelines/generative-ai)):
  - Ask before using personal information, and prefer on-device processing.
  - Disclose what goes to a server.
  - Offer Edit, Undo and Retry, and acknowledge corrections.
  - Show specific progress text ("Finding substitutions…" rather than "Processing…").
- **Microsoft HAX, 18 guidelines** ([HAX](https://www.microsoft.com/en-us/haxtoolkit/ai-guidelines/), [paper](https://www.microsoft.com/en-us/research/publication/guidelines-for-human-ai-interaction/)): time services based on context, support efficient dismissal and correction, scope services when in doubt, make clear why the system did what it did, update and adapt cautiously, encourage granular feedback, provide global controls, and notify users about changes.

**What creeps people out:** asked about location awareness, people's top words were "Creepy, Scary, Annoying, Bad"; "Convenient" was the only positive. ([Internet Safety Labs 2021](https://internetsafetylabs.org/wp-content/uploads/2021/11/spotlight-report-3-location-tolerance.pdf)) Inferences people never stated (church, health), alerts that seem to come from nowhere (Google's Time-to-leave alerts), and logging framed as "tracking" (Visited Places) all draw complaints.

**What people praise:** opt-in prompts, on-device processing with end-to-end encryption, one-swipe deletion (Apple Maps), and one evolving trip object that alerts only when action is needed (Transit).

**Patterns that recur across these sources:**
1. A visible, editable "What Expadu knows" list, with every entry deletable.
2. A reason shown with every proactive item ("Because you usually leave at 8:10 on weekdays").
3. Confirm before saving a routine ("Is this your work?"), and ask before any sensitive category.
4. Dismissing an item teaches the model, like DB's per-day pause.
5. A global pause.
6. A consistent voice, with humour kept away from disruption messages. This last point is an inference from the Citymapper and [Monzo](https://monzo.com/tone-of-voice/) practice, not a cited rule.
