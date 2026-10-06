# Friendly connected app — review and implementation

3 October 2026. Local frontend implementation and connected prototype review. Not a deployment.

## What changed

The real application now uses one compact, five-tab top navigation: Today, Events, Paperwork, Travel and Services. Composer keeps its focused workspace. Utility pages retain the navigation even when their content uses the full width. The optional settlement questionnaire no longer blocks entry to the app; authentication and email verification remain in place.

Today reads the existing Composer workspace, prioritises urgent/personal work, keeps travel nested under its activity, and groups practical help with event discovery. Fresh accounts receive optional starting actions. Empty sections collapse rather than inventing plans or content. Personal title, place and time changes create a reviewable change under the current approval setting; fixed event times stay fixed. Changing a place invalidates the previous journey coordinates.

Events uses immersive approved photography when available, compact filters, a featured area independent of category filters, and explicit saved/selected states. No-photo entries do not reserve empty image frames. Exact occurrences enter Composer for review; an unknown end is not guessed. Known short events keep their actual duration. Local bookmarks clearly say that they are saved on this device.

Travel has separate Plan a trip and Timetable views, compact route rows and a connected route detail. Exact origin, destination, time, day and originating activity survive the Composer handoff. A typed place must resolve to a real location before routing. Manual starting points work without granting GPS. Search falls back to the configured city centre and aliases only for relevance; it never sets the person's location or invents a route origin. The board uses the existing nearby-stop current feed; it does not claim arbitrary future timetables or turn-by-turn live tracking. Failed trip persistence leaves the chosen route available for retry.

Services can prepare an optional request or organise an already arranged appointment, without claiming provider search or booking is live. Its request form now fits 320 px without clipping the fields or action. Paperwork has one heading, optional orientation and explicit coverage. An incomplete case no longer claims that it has enough confirmed information. Today's empty event action opens the coming week rather than repeating the empty day.

Composer remains the shared review/plan boundary. Entry links do not send messages automatically. Existing text, another day's proposal and saved plans survive contextual entry. Dismissed context is removed from the URL. Selected journeys retain their schedule and route summary when reviewed. The time wheel now closes correctly and cannot overwrite a chosen time through an old scroll callback.

## Places handoff

The owner-authorised conversation with **Investigate places data quality** confirmed 12,292 local candidates and only 119 approved photos, with uneven area coverage and predominantly unknown fees. These counts describe its local export, not deployed coverage. The larger catalogue and category/backend migration remain separate.

Frontend details now consume optional evidence and routing fields conservatively: canonical destination identity is retained, supplied routing coordinates take precedence, unlocated places are omitted from maps/directions, missing or failed photos collapse, and uncertain hours/fees/access stay uncertain. The local Places resource was also corrected so unmanaged legacy photo fields cannot bypass `PublishedMediaSelector`, and null coordinates stay null instead of becoming zero. No rights were approved, catalogue imported or authority gates weakened.

## Review scenarios exercised through the browser

- New local test account enters Today without a forced settlement questionnaire.
- Create Coffee with Maya on the selected day, apply in Composer, and see it on Today.
- Edit its place on Today: saved plan stays unchanged until review; Composer shows the proposed place and preserves an unsent message.
- Resolve that typed destination explicitly, compare real routes, switch to Timetable and back without losing the selected itinerary, and review it in Composer with original day/arrival/origin.
- Select a manual Timetable starting point without GPS and reload: the chosen location remains. The reviewed feed returned no departures and says so.
- Choose a 15:30 departure, review Line 16 in Composer, and return to Travel: origin, destination and 15:30 departure remain intact; Composer shows the selected 15:31–15:42 route separately from the requested time.
- Service preferences stay separate by category; preparing a request keeps it unsent and does not overwrite existing text. Dismiss/reload does not replay it.
- Time picker trigger toggles closed; independent hour/minute choices remain exact and Done restores focus.
- Prototype appointment edit invalidates the old journey. Event selection sees the current shared day and retains fixed timing. Completion/Undo works for both existing and pending activities; double tap reveals deletion and Undo restores the same activity.
- Current local Events empty states remain honest: no approved future listings were returned for the reviewed range. Rich sample media is reviewed in the clearly labelled prototype, not inserted into real user data.
- A fresh Paperwork case shows “No matched steps yet” and an optional details action, with missing coverage still visible.
- At 320 px, first-visit actions and the agenda remain readable without page overflow. At 390 px and 1440 px, the appointment/journey hierarchy and shared surfaces remain intact. Delay recovery retains the sample appointment's exact day and arrival target.
- Fresh-user “Neumarkt” search now lists Köln Neumarkt first while still allowing other matches. Services fields and its primary action fit inside the 320 px viewport. Today's upcoming-events link opens `window=week` with Next 7 days selected.

## Verification

- 351 prototype regressions passed: shared day, Composer, Today, Events and Travel.
- 48 frontend logic/rendering regressions passed: entry preservation, dates, places evidence, search, Today priorities, event timing, services and Paperwork readiness.
- 125 focused PHP tests passed with 521 assertions: workspace, event selection, candidates, optional onboarding/auth, dashboard and Places API.
- 15 additional search/geocoding PHP tests passed with 76 assertions: explicit-origin precedence, fresh-account fallback, local/English aliases and unchanged personal location. Combined focused PHP total: 140 tests, 597 assertions.
- The separate Places/media check passed 55 tests with 203 assertions; this overlaps the Places API tests above and is not an additional unique-test total.
- Full TypeScript and scoped ESLint checks passed. Production frontend build passed. Existing font-resolution, large-chunk and deprecated service-worker build-option warnings remain.
- Prototype asset versions and whitespace checks passed.
- The final browser pass reported no console errors. Temporary viewport overrides were removed, and both the real app and accepted preview were left available for review.

Added browser regression specifications cover the connected navigation, service/paperwork entry, Events, Travel and time picker. Browser scenarios above were exercised with the app's browser controls; the Playwright CLI suite was not run in this session.

Screenshots (local, sample/test accounts only):

- [Today prototype, desktop](preview-today-desktop.png)
- [Today prototype, mobile](preview-today-mobile.png)
- [First visit, 320 px](preview-first-visit-320.png)
- [Delay priority, mobile](preview-delay-mobile.png)
- [Implemented Today, mobile](actual-today-mobile.png)
- [Implemented Today, desktop](actual-today-desktop.png)
- [Implemented route details, mobile](actual-travel-mobile.png)
- [Incomplete Paperwork case, mobile](actual-paperwork-mobile.png)
- [Services form, 320 px](actual-services-320.png)

Review entry points: [real frontend](http://127.0.0.1:8011/dashboard) and [approved connected preview](http://127.0.0.1:8765/dev/design/today-next/brief/?view=everyday). The real-app tab uses an isolated local review account; its sample personal plan is separate from the owner's account. Local event/approved-media availability is still sparse, so its content density differs from the populated prototype.

Owner-requested password-free local entry: [Open review](http://127.0.0.1:8011/dev/review). This starts a normal session for the designated existing synthetic account. It requires `APP_ENV=local`, an explicit `LOCAL_REVIEW_ENABLED=true`, a loopback host/peer and no proxy headers. The account must use `@expadu.test`, be verified, non-admin and without two-factor credentials. Existing sessions are not replaced. Normal protected routes retain authentication and verification. Disable the shortcut with `LOCAL_REVIEW_ENABLED=false` in the local environment. Its focused authentication checks pass 28 tests / 69 assertions, and browser verification confirmed entry from the logged-out screen and navigation into Travel.

## Remaining release boundaries

- This is local work. No push, merge, deployment or expanded places import was performed.
- Existing Composer planning tools work; unrestricted AI conversation remains explicitly unavailable. This work connects the frontend around Composer, not a new backend engine.
- Provider discovery/booking, broad reviewed paperwork coverage, real-time navigation and the expanded places contract remain backend/data work. Missing capability is shown honestly.
- Account-scoped device bookmarks do not claim cross-device sync.
- The full-app walkthrough remains postponed as requested.
