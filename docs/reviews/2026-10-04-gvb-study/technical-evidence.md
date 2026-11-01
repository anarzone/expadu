# GVB Gappie: technical and data evidence

Research date: **2026-10-04**. Scope: public primary sources; no access to GVB source code, infrastructure, private APIs or a running native app. This is an evidence review, not a verified implementation diagram. No Expadu application changes were made.

“Confirmed” below means explicitly documented by GVB or its supplier. Undated pages were checked on the research date; this does not establish when their content was last revised. Several GVB HTML pages returned 403 to direct retrieval; their text was available through the web search index. Supplier and App Store pages were retrieved directly. Treat indexed operational descriptions as published claims, not observations of the installed app.

## 1. Documented technology and integrations

| Claim | Evidence and date | Boundary |
|---|---|---|
| Infi built the iOS and Android app with React Native. Its documented stack includes Azure with Application Gateway, .NET microservices and Contentful headless CMS. | [Infi GVB case](https://infi.nl/case/gvb/), undated; checked 2026-10-04. It dates the first release to March 2021. | Supplier evidence for the publicly documented stack. No current versions, deployment topology or service contracts are published here. |
| Gappie uses the national 9292 journey planner. | [GVB app overview](https://www.gvb.nl/reisinformatie/gvb-app), undated; checked 2026-10-04. | Confirms the planning provider; does not identify GVB's endpoint, client/server boundary or provider of every other transport feed. |
| Buckaroo supplies the renewed ticket-payment flow. | [Buckaroo Gappie case](https://www.buckaroo.nl/klantcases/gvb-gappie-app-klantcase-naadloze-mobiele-ticketbetalingen-met-buckaroo), undated; checked 2026-10-04; discusses 2025 results. | Supplier says payment is integrated into the app experience. This does not establish whether checkout uses native screens, a browser or an embedded provider component. |

These sources identify components, not their exact wiring. A drawing that puts every integration behind a single GVB gateway would be a reconstruction. There is no evidence here that GVB uses OpenTripPlanner, that Contentful owns transport data, or that React Native calls every provider directly.

## 2. Stops, stations, vehicles and accessibility

GVB documents nearby-stop widgets after location permission, favourites for routes and stops, departure views with live tram/bus/metro/train positions, and a configurable ferry countdown. These are distinct information needs: finding a stop, inspecting a service and watching a vehicle. The source does not explain the underlying stop identifiers, station grouping or vehicle-position feed. [GVB app overview](https://www.gvb.nl/reisinformatie/gvb-app), undated; checked 2026-10-04.

HalteHulp accepts personal requirements including mobility aids, use of a ramp and allowable vehicle boarding height, then shows matching stops. This establishes profile-sensitive stop accessibility rather than one universal accessible/inaccessible flag. [GVB accessible transport](https://www.gvb.nl/toegankelijk-ov), undated; checked 2026-10-04.

Lift/escalator faults can appear in journey advice with a link to the fault and travel advice. Crowding is a **prediction based on recent OV-chipkaart and OVpay data**, may differ from conditions aboard, and has an explicit unavailable state. Neither is proof of an instantaneous physical sensor reading. [GVB disruptions and crowding](https://www.gvb.nl/reisinformatie/verstoringen-omleidingen), undated; checked 2026-10-04.

The developer's release history records improved station-plan refresh in version 2.14.0 (2025-06-02), live vehicle-position improvements in 2.10.0 (2025-02-06), and live journey following in 2.11.0 (2025-03-24). Those descriptions do not reveal GPS polling, progress detection or rerouting mechanics. [GVB iOS release history](https://apps.apple.com/gb/app/gvb-reis-app/id1544439867), checked 2026-10-04.

**Architectural inference:** the experience joins stop/location identity, service departures, vehicle observations, journey legs, accessibility requirements and facility incidents. Those conceptual distinctions are supported by the features; a corresponding GVB database schema is not published.

## 3. Traveller location is a separate boundary

GVB's app privacy statement says location sharing is optional and gives nearby-stop discovery as an example. It also lists device/OS details, IP address, station-search history and device-identification keys. It identifies third-party travel advice, maps, ticket purchase/issuance/validation, analytics and crash analysis. [GVB app privacy statement](https://www.gvb.nl/privacyverklaring/privacyverklaring-gvb-app), undated; checked 2026-10-04.

**Evidence limit:** showing a vehicle moving on a route does not establish continuous tracking of the traveller. The reviewed sources do not specify foreground versus background location permission, update intervals, geofencing, GPS-based boarding detection, off-route detection or automatic rerouting. A “follow journey live” release note cannot establish those details either.

## 4. Notifications: selected interests and time windows

The published Seintje setup asks users to choose transit lines or lift/escalator alerts, relevant lines/stops, travel days and time windows. OS notification permission must be enabled. This documents subscriptions to interests and times; it does not demonstrate a requirement for continuous location tracking. [GVB Seintje instructions](https://www.gvb.nl/reisinformatie/verstoringen-omleidingen), undated; checked 2026-10-04.

GVB's **2026-02-19** announcement confirms the redesigned Seintje, facility alerts and a personalised disruption overview. It describes ProRail facility coverage at shared stations as an expansion planned for later in the year. Do not treat that promise as proof it has shipped. [GVB Seintje announcement](https://over.gvb.nl/nieuws/seintje-in-de-gvb-app-slimmer-persoonlijker-en-nu-ook-voor-liften-en-roltrappen/), checked 2026-10-04.

A **2018-08-15** article says a traffic-control passenger-information officer manually created Seintje messages. This is historical evidence of an operational/editorial source, not proof of the present dispatch mechanism. [GVB historical Seintje explanation](https://over.gvb.nl/nieuws/krijg-een-seintje-als-jouw-lijn-verstoord-is/), checked 2026-10-04.

GVB's 2025 annual report documents resolution notices and further notifications only for relevant incident updates. This establishes the intended user-facing lifecycle, not its implementation. [Annual report, page 33](https://over.gvb.nl/content/uploads/2026/05/GVB-Jaarverslag-2025-27-05-26.pdf).

Unknown: push vendor, subscription persistence, device migration, account requirement, implementation of deduplication/cooldowns, incident lifecycle mechanics and delivery guarantees. The current instructions link to account/device-transfer FAQs, but the retrieved text did not include their answers.

## 5. Accounts and retention must remain distinct

GVB currently describes separate MijnGVB accounts for OV-chipkaart and OVpay. Its help page says only the OV-chipkaart account can be used to sign in to Gappie. Payment-card linking in the app provides GVB trip/cost history and is distinguished from an OVpay account used for Flex subscriptions. GVB Flex users can enable monthly spending notifications. These are separate published integration paths, not evidence of a unified identity service. [GVB account help](https://www.gvb.nl/reisinformatie/mijngvb), undated; checked 2026-10-04.

The app privacy statement specifies current location retention of **one day**, purchase-form personal details of **15 minutes**, and other app data while the app remains installed; withdrawal removes the relevant data. It says HalteHulp information remains local and is deleted when disabled. It says payment details for ticket purchase are processed outside the app. These are policy statements, not an implementation audit; the payment boundary needs confirmation against the renewed checkout. [GVB app privacy statement](https://www.gvb.nl/privacyverklaring/privacyverklaring-gvb-app), undated; checked 2026-10-04.

A separate MijnGVB policy says the account remains active **seven years after the last linked travel product ends or the last balance purchase**, then is deleted. It names cloud/hosting and IT processors. Do not apply the app's one-day or 15-minute windows to account or transaction records. [MijnGVB privacy statement](https://www.gvb.nl/privacyverklaring/mijn-gvb-account), undated; checked 2026-10-04. This report records GVB's policy rather than recommending those periods for Expadu.

## 6. Source drift to preserve in the study

The App Store listing's latest visible release, **2.27.2**, dated “7 Sept” in the listing, phases out the balance checker. The undated GVB overview still promotes it. Use the release note when describing that change; do not treat the overview as an exact inventory of the installed version. The same listing declares usage/diagnostic collection not linked to identity; Apple explicitly says it has not verified the declaration. It should not override GVB's fuller policy. [GVB iOS listing](https://apps.apple.com/gb/app/gvb-reis-app/id1544439867), checked 2026-10-04.

The PDF copy of the [app privacy statement](https://assets.ctfassets.net/d6yaib7us1l3/2Zum8vTe8ACPPoCaS8Iahr/d95988fec173023373eaab1b8790c546/privacyverklaring-gvbapp.pdf) lacks the HalteHulp paragraph present on the HTML page. Neither copy provided an effective date in the reviewed text. Use the HTML wording for that feature and record the mismatch.

## 7. Possible Expadu design, not claims about GVB

These are candidate boundaries for subsequent design, subject to Expadu's existing architecture and provider coverage:

- Model a stop/station and its boarding locations separately where the data supports it. Attach departures and facilities to stable provider identifiers; retain the source and city/network namespace.
- Keep scheduled departures, real-time predictions, vehicle positions and crowding estimates distinguishable. Carry observation time and explicit unknown/stale states rather than presenting every value as live.
- Keep location choice independent of transport observations: a manually selected origin should remain useful without location permission. Request user positioning only for a feature that needs it.
- Treat facility accessibility and facility outages separately. A generally accessible station can have a temporarily unusable lift, and coverage may differ between operators sharing a station.
- Let users save lines/stops and optional time windows before considering GPS-triggered alerts. Explain the selected scope and provide one place to edit or stop notifications.
- Define separate retention and deletion rules for anonymous preferences, active journeys, notification subscriptions, linked accounts and purchases. Do not copy GVB's retention periods or presume account deletion clears provider records.
- Keep provider adapters behind a stable product model so Cologne and future cities can expose different capabilities without inventing missing data.

Open technical questions requiring GVB access or a separate permitted app inspection: stop/line/trip ID mapping; provider feed provenance and freshness; exact topology and API protocols; source of vehicle observations; map SDK; notification delivery/persistence; authentication/session architecture; data storage/retention enforcement; and the present payment and ticket-validation boundaries.
