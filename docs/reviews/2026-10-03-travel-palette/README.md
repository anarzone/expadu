# Travel-only colour trial

## Current review: approved soft peach

3 October 2026. The owner selected **Soft peach** after reviewing the same Travel
panel with peach, cyan mist and the current neutral surface. Use `#fff5ef` for
functional groups, `#ffebde` for hover/press and `#eadbd2` for dividers. The
continuous page/header remain white (`#ffffff`), with existing cyan selections
and travel cues, orange actions and text colours. Departure-detail footer fades
now match the peach surface. Travel's local stylesheet and its versioned entry
are the only implementation changes.

Verified the planner at 1440 × 900 and 390 × 844 CSS pixels, and the phone
timetable and departure details. Computed colours confirmed the peach groups,
transparent nested fields, unchanged cyan/orange accents and matching sheet
footer. No horizontal overflow was observed; Find a route remains in the initial
phone viewport. Timetable switching and departure detail open/close still work.
The preview was returned to Plan a trip and temporary viewport overrides reset.

Screenshots and computed styles are in `soft-peach/`; `travel-current.png` is the
final native-size preview. Shared palette/header assets and other pages remain
unchanged. Formatting and stylesheet-reference checks pass. This approval is
local to Travel; it does not approve rollout to other departments.

## Previous review: whiter canvas and panels

3 October 2026. The owner requested lighter grading for both neutral tones with
more contrast between them. Page/header are now white (`#ffffff`); functional
groups are a less creamy near-white (`#f7f7f5`). Both are lighter than the preceding
revision. Hover/press uses `#f1f1ef` and dividers `#e3e3e0`. Updated the corresponding
sheet/header fades. Cyan/orange accents, text, geometry and interactions remain.

Verified the planner visually at phone and desktop sizes, including computed
page/header/panel colours and no horizontal overflow. Screenshots are in
`white-canvas/`. This remains local to Travel's stylesheet and versioned entry;
shared styles and other pages are unchanged.

## Previous review: lighter surfaces

3 October 2026. The owner rejected the previous gray groups as too dark.
Keep the same composition and colour roles, with barely tinted near-white groups
(`#f6f5f2`), lighter hover/press feedback (`#f0efeb`) and softer dividers
(`#e3e0d9`). Page/header remain `#faf9f6`; cyan selections and orange actions stay.
Updated the matching sheet fade and Travel's stylesheet version.

Visually checked the planner on phone and desktop, and the phone timetable.
Computed styles confirmed the lighter group surface, matching page/header,
transparent nested fields and unchanged accent colours. No horizontal overflow
was observed. Screenshots are in `lighter-surfaces/`. Other pages and shared
styles remain unchanged; this is still a Travel-only review.

## Previous review: light canvas, darker functional groups

3 October 2026. The owner requested implementation after discussing the surface
hierarchy and reviewing design-system guidance. This replaces the earlier trial
below on Travel only; it is not approval to change other pages.

- Page and header: `#faf9f6`.
- Planner, timetable and detail groups: `#efede8`.
- From/To fields, transport-mode rail and swap control share the planner surface.
  Their existing layout and fine divider remain.
- Selected segmented controls use cyan `#dff3f8` with teal `#00758c` labels.
- The sample route stays cyan; its diagram and opening symbol no longer get
  separate neutral blocks. Orange primary actions remain `#d9340a`.
- Neutral hover/press feedback uses `#e5e2dc`; focus and semantic warnings remain.

The local CSS and its versioned Travel reference are the only implementation
changes. No JavaScript, shared styles, content, page geometry or other page
entries were edited.

Browser review covered the planner at 320, 390 and 1440 CSS pixels, timetable at
390 and 1440, phone departure details, desktop route results and journey/map
details. Controls retain their existing approximately 44px minimum targets.
No horizontal overflow was observed at the checked sizes. The main planner action
was visible in the initial phone viewport. Switching Transit/Cycle updated the
cyan selected state; timetable rows and journey details opened normally. Existing
sample labels remain. The preview was returned to the original planner state and
the temporary viewport override was reset.

Measured text contrast: primary text on the neutral group 13.21:1, secondary text
5.41:1, teal on cyan 4.67:1 and white on orange 4.72:1. These are palette checks,
not a full accessibility audit. Screenshots and computed-style observations are
in `light-canvas/`. The shared palette/header and base Travel styles retain their
previous checksums. Formatting and stylesheet-reference checks pass.

Research informing the decision:

- [Radix colour roles](https://www.radix-ui.com/colors/docs/palette-composition/understanding-the-scale):
  separate page backgrounds, component backgrounds and interaction states.
- [Atlassian elevation](https://atlassian.design/foundations/elevation/):
  use raised surfaces intentionally; spacing or borders can group content.
- [Apple colour guidance](https://developer.apple.com/design/human-interface-guidelines/color):
  use consistent colour meanings and check foreground/background relationships.

The precise neutral shades are Expadu review choices, not values prescribed by
these sources. The owner rejected this heavier gray; the current revision above
retains the roles and lightens the surfaces.

## Earlier trial, retained for comparison

3 October 2026. Requested after reviewing the repeated light/dark surface bands.

`departures-next/soft-palette.css` is loaded only by Travel's main entry page.
Header, page and inset fields now use `#f3f2ee`; cards use `#fbfaf7`.
The existing cyan, orange, text and semantic status colours remain.
Sheet fades use the corresponding new neutral. No layout or behaviour changed.
This is a single-page review, not approval to roll out the palette elsewhere.

Browser checks covered the planner at 390 and 1440 CSS pixels and the phone
timetable. Header, page and field backgrounds were confirmed identical through
computed styles, including the header's transparent descendants. No horizontal
overflow was observed. Screenshots are included in this directory.

The shared palette, Today, Paperwork, Events, Services and Composer entry files
were not edited for this trial. Formatting and stylesheet-reference checks pass.
