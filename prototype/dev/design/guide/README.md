# Expadu design guide

Open <http://127.0.0.1:8765/dev/design/guide/> (start the preview server with
`python3 prototype/serve.py`).

A Figma-style reference for the connected pages: frames on a canvas, component
sets with variants and states, and the live mock designs.

- **Foundations:** colour palette (values read live from
  `../connected/today-palette.css`, with text-contrast ratings and click to
  copy), colour roles, type scale, spacing with redlines, radius and shapes,
  elevation, icons and motion.
- **Components:** buttons (primary, outline, secondary, text × default, hover,
  pressed, focus, disabled), icon buttons, links, pills and badges, inputs, the
  custom select menu (live), segmented control, and the Today pieces: deadline
  strip, timeline item, What to bring, journey card, advice, plan links,
  alternatives, undo row and event cards.
- **Navigation & feedback:** the live header (cropped from the Today mock),
  Undo row, toast, inline validation and inline confirm.
- **Patterns:** Today page anatomy, breakpoints (9 in use, 4 proposed), sheets
  and dialogs (the surface ladder), empty/loading/error/not-live states,
  content and voice with formats, accessibility checks, and dark mode (open).
- **Mock designs:** the Today prototype at phone and desktop size with a
  scenario switcher, and previews of the Events, Paperwork, Travel, Services and
  Composer mocks (one at a time, so sample states don't mix).

**Tokens:** every value comes from `../connected/tokens.css`. The **Scale
migration** record lists how each old Today value maps onto the scale
(`scale-report.json`, written by `node tools/normalize-scales.mjs --apply`).
All pages, including Travel, share one canvas and header.

The decisions behind it live in `../connected/design-decisions.md` ("Colour
roles"). **Shared components:** the Today components, status pills, journey card,
actions and stop menu render from `../connected/core-components.css` and
`../connected/day-components.css` — the same files the Today mock loads — so
they cannot drift. The guide adds only the app's base link/button/icon rules
(`:where(.today-reference)`, zero specificity). Buttons, badges and event cards
are still guide copies; move them into shared files the same way.

Known issues it surfaces: two icon styles in use, a mismatched Plan icon between
event card styles, and AI sample illustrations that are not of Cologne.
