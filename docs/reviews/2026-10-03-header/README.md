# Connected preview header alignment — 3 October 2026

The approved Today brief remains the reference. This pass applies its shared
header to Events, Paperwork, Travel, Services and Composer on port 8765.
The actual Laravel app and the approved Today files were not changed.

## Result

- One 72px desktop row: existing logo, five labelled destinations, city,
  Saved and account. Paperwork and Travel replace the older menu labels.
- The same approved Phosphor icons and warm header surface, with cyan selection.
- A 120px phone header with 44px utility targets and 52px navigation targets.
  The utility row scrolls away, leaving the five destinations visible. Keyboard
  focus brings the full header back into view.
- At narrow widths, the city name becomes an accessible location button.
- Paperwork's page heading matches the navigation label.
- Account opens a compact preview popover. City selections remain explicitly
  labelled as demo choices. Saved continues to open the existing Composer panel.

The active entry pages opt in through `today-header`. Archived previews retain
`scroll-header.js`; the new shared header keeps the `.scroll-header` measurement
hook used by Composer's sticky toolbar. The city picker supports the new label
span as well as the older text node and updates its accessible name on selection.

## Verification

- Reviewed all five pages at desktop and phone sizes; screenshots are beside
  this file. Header heights: 72px desktop, 120px phone; no observed horizontal
  page overflow. Events was also checked at 320px; Paperwork at an effective
  291px and 390px; Travel at 1024px.
- Followed navigation between Events, Paperwork, Travel and Services. Verified
  correct destinations and selected tabs. Home points to `today-next/brief/`.
- Searched for Bonn in the city picker, selected it, verified the visible and
  accessible names, then restored Cologne. Account opens and dismisses with
  Escape. Phone navigation stays visible after scrolling.
- Switched Travel from Plan a trip to Timetable. Its header stays consistent.
- Opened Saved from Services, verified the existing saved item in Composer,
  then closed the panel. Composer's toolbar begins directly below the header
  (120px on phone) and its input remains available.
- Seven existing city-picker and legacy scroll-header tests pass. Changed
  JavaScript passes syntax checks; changed files pass Prettier. Entry-page asset
  paths and content hashes were checked for all five previews.

No booking, account authentication, live data, backend or page-content redesign
is included in this pass.

## Follow-up: menu state consistency

The owner noticed a font/style change when a navigation item became active.
Browser measurements showed identical font size, weight, spacing and glyph
widths in selected and unselected states. The actual state differences were teal
selected text and a 0.97 press scale. There was also a desktop geometry mismatch:
Paperwork was 125.48px wide on Today but 118px on the other pages.

The correction keeps labels dark in every state and removes press scaling.
Cyan selection, section icon colours and keyboard focus indication remain.
The active previews now use the same font fallback stack, brand line height and
desktop minimum widths as Today. Phones retain equal-width tabs.

Repeated navigation through Today, Events, Paperwork, Travel and Services was
checked at effective 1440px and 320px viewport widths. For every link, font,
text colour, horizontal position and width match the same link on Today (within
0.05px for fractional rendering). Desktop labels use Geist 500 at 14px; phone
labels use Geist 400 at 12px. The runtime measurements are in
`menu-state-checks.json`; the updated header capture is `menu-state-fixed.png`.
