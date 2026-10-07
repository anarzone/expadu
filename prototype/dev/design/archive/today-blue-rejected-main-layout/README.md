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

## Context and styling refinement — 7 September

The accepted layout and the clarity overview's logic are preserved. The refinement adds clearer blue and peach service/event accents, readable secondary text, stronger progress and connection indicators, and more tactile keep controls. Place cards explain the kind of outing they represent and identify details that still need checking. Event cards distinguish meeting people from an evening activity without inventing dates. Images are visibly labelled as illustrations.

Place and event details now explain the idea's purpose and what to confirm before going. A short discovery hint explains how kept places become Composer context. The greeting date follows the local calendar. A separate refinement stylesheet keeps this pass isolated; the preceding source is backed up under `../archive/today-blue-before-context/`.

Verification: both JavaScript files passed syntax checks; the clarity scenario logic is byte-for-byte identical to the preceding version. Browser checks covered place details, keeping from a sheet, typing on Today, Compose carrying a selected idea and the request, saving, rain reprioritization retaining the saved day, and mobile selection staying on Today. Layouts were inspected at desktop and 390px mobile width; neither had horizontal page overflow. The main Compose button's white text has a calculated contrast ratio of 4.72:1. This is not a full accessibility audit.

## Concise copy and brand cyan — 7 September

- Removed explanatory subtitles, discovery guidance and dock footer copy.
- Replaced card prose with brief metadata and verification labels.
- Applied exact Expadu cyan (#05BADD) to navigation, keep controls and key icons; retained dark text and orange Compose.
- Preserved the clarity panel, scenario ordering and saved selections.
- Checked all five scenarios, retained choices, mobile width and Composer handoff. No browser errors. Both scripts pass syntax checks.

## Service menu readability

White navigation surface, dark labels, and individual icon accents. Hover and keyboard focus strengthen the relevant category color. Labels move below icons on narrower layouts; very narrow screens scroll within the menu. Checked keyboard activation, 390px and 320px mobile layouts, and the wide desktop menu. No horizontal page overflow or browser errors were observed.

### Flat menu refinement

Removed the raised menu container and icon tiles. Colored line icons, a thin divider and hover/focus underlines retain the category cues. Checked keyboard activation, 390px mobile fit, wide desktop layout and browser errors. The preceding menu is backed up under `../archive/today-blue-before-flat-menu/`.

### Flat cards and controls

Extended the flat menu treatment to weather, overview icons, place/event cards, saved-day controls, detail sheets and Composer. Removed raised edges and tilted icon tiles; retained cyan selection/accent states and orange primary actions. Place keep controls now sit over the images. The clarity layout and scenario priorities remain intact.

Checked desktop and mobile widths, card/detail selection, two-item Composer handoff, saving, weather reprioritization and browser error logs. The updated overview script passes its syntax check. The preceding styling is preserved under `../archive/today-blue-before-flat-surfaces/`.

### Density, readability and warmth

Compacted overview spacing while retaining its layout and dynamic order. At 390px, the overview row decreased from 229px to 186px and discovery starts 61px sooner. Keep buttons are now 44px, place metadata is 12px, and event titles and metadata are larger. Slightly shorter place images and cyan/orange event surfaces improve the discovery balance.

Verified 320px and 390px layouts, desktop layout, keep/detail actions, and travel/rain priorities retaining choices. No horizontal page overflow or browser errors were observed. Previous styling is saved under `../archive/today-blue-before-balance/`.

### Experience review — 7 September 2026

Added isolated **First visit** and **Saved day** preview scenarios, **Limited content** fixtures, concise discovery/saved/draft labels, a common cancellable Composer loading flow, retry, result focus and draft undo corrections. The accepted Today layout is preserved. Visitor sessions remain in page memory, not persistent storage.

See [REVIEW.md](./REVIEW.md) for the decision-by-decision assessment and limitations.

Run the focused checks with Node 22:

```sh
node --test prototype/dev/design/today-blue/tests/experience.test.cjs
```

### Main-page layout and styling pass

Research-backed changes live in `main-page.css`; `layout.js` reserves the Composer footprint and keeps keyboard focus visible. See [RESEARCH.md](./RESEARCH.md) for the decisions, source links and comparison with the report. This updates the main opening screen, not only preview scenarios.
