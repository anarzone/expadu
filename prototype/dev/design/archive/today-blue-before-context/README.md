# Today — blue and orange revision

Local interactive mockup: http://127.0.0.1:8765/dev/design/today-blue/

Responds to the request for a complete Today overview, direct access to the existing services, the app's familiar colours, and a separate Composer workspace within thumb reach. The single-event hero and Today/Explore/Everyday Life grouping are replaced.

## Composition

- Greeting and compact weather.
- Direct Places, Events, Bureaucracy, Departures and Services shortcuts.
- A populated overview with personal checklist progress, sample departures and an hourly forecast.
- Two equally weighted places with keep actions.
- An events list, with no promoted hero item.
- Departures, paperwork and updates, plus river conditions.
- A dark Composer dock with an orange action; a separate workspace retains the request and choices.

## Dynamic overview

The Sample day selector demonstrates five situations: ordinary afternoon, paperwork to finish, heading out, rain later and a free afternoon. The overview changes its order and emphasis with a short explanation. Discovery leads on a free afternoon; otherwise the relevant everyday summary leads. Completing the four sample personal preparation items moves discovery up in the paperwork scenario. This is not a legal completion assessment.

The overview is a joined three-column surface on desktop and a swipeable row on mobile. Places and events remain present in every scenario. A compact saved/draft-day strip appears when there are chosen ideas.

Kept discoveries appear as removable context beside Composer. A request such as “Fit these into my afternoon” opens those choices as an editable plan, without claiming verified routes or timings. Changing the sample day preserves the request, choices and checklist. Keeping, removing or undoing a choice on Today stays on Today, including after visiting a draft plan.

Typing in the dock stays on Today. Only submitting a non-empty request opens the Composer workspace; submitting an empty input keeps it focused on Today. These three cases were verified in the browser after the interaction revision.

Colours are grounded in `resources/css/app.css`: orange #FF3902, cyan #05BADD, soft cyan #D8F3FB and ink #211D15. The prototype uses a lighter #F7FBFC canvas. Fonts and the logo come from the existing app. Shared interaction styling is imported from the preceding local prototype and overridden for this direction.

## Verification

JavaScript syntax check passed. Browser checks covered the desktop overview and a 390 × 844 viewport without horizontal document overflow; every service shortcut; keeping a place from Today and saving it; direct typing after closing a service sheet; a café request; filtering indoors while retaining the selection; saving and subsequently removing the saved choice; and carrying the music-event context from details into Composer. Browser error log was empty during the checks. Temporary viewport override was reset after verification.

The dynamic overview was additionally checked in the browser: travel and rain priorities; checklist changes from 2/4 to 4/4 and discovery reprioritization; a free afternoon retaining saved choices and input; keeping two places as Composer context; removal, undo and save; returning from a draft plan without subsequent keep actions reopening Composer; preservation of an existing request when using the weather prompt; and honest handling of an unsupported 30-minute constraint. The final scripts passed JavaScript syntax checks. Mobile checks found no horizontal document overflow; the overview intentionally scrolls within its own row. Browser error logs remained empty.

This is a visual prototype, not a production integration. Its places, events, imagery and weather are illustrative; Composer responses are scripted, live services are disconnected, and state lasts until reload. No user-facing legal instructions are generated. Images are generated fictional scenes, not real venue photos. Production code and data remain unchanged by this mockup.
