# Travel colour and motion study — verification

3 October 2026

Deliverable: [ten-option gallery](http://127.0.0.1:8765/dev/design/travel-lab/).
Research, colour values, motion specification and tradeoffs are in
`prototype/dev/design/travel-lab/RESEARCH.md`.

This is a separate review prototype. No current Travel, Today, shared palette,
shared header, real-app or backend files were changed for this study.

## Checks completed

- JavaScript syntax checks pass for `lab.js` and `palettes.mjs`.
- Prettier check passes for all five gallery files.
- Palette calculation: all ten pass 4.5:1 for primary text on canvas, secondary
  text on canvas/group, accent text on selection, primary-action text and primary
  text on selection. Lowest tested pair: 4.67:1. This is a role-level check,
  not a complete WCAG audit of the app.
- Browser: all ten colour directions render at a 390 × 844 viewport with a
  364 px preview interior and no horizontal overflow.
- Narrow-phone check at 320 × 780 found a small route-summary overflow. Added
  a narrow-container wrapping rule and verified no remaining visible overflow.
- Desktop checked at 1280 × 720 and 1440 × 1000, including both side-by-side
  and search-above-results compositions. Fixed an incorrectly hidden time label
  in the second composition, then verified it and the inline Composer handoff.
- Six timetable rows and after-midnight labels render. Pointer and keyboard
  tab changes select the appropriate panel.
- Journey details expand; saving changes the icon, pressed state and preview
  status. No app-level Saved records are written.
- The motion demo reaches its expanded-and-saved state and can be stopped.
  Manual interaction cancels the sequence. With Motion off, the demo button is
  disabled, computed transition duration is 0s and animation is none; controls
  continue to work. System reduced motion is handled by CSS and a media-query
  listener, but the OS preference was not changed during this check.
- A/B comparison keeps the two instances independent. Switching the first to
  Timetable leaves the second on Plan a trip. Each phone preview is 390 px
  including its border.
- Escape closes the review and focus returns to the originating gallery button.
- No error or warning entries reported in the gallery browser log.

## Evidence

- `gallery.png` — desktop overview and comparison controls.
- `phone-lagoon.png` — phone preview of direction 02.
- `compare-ember-berry.png` — two independent phone examples, directions 01/06.

## Preserved baseline fingerprints

These match the pre-study Travel implementation. They document isolation, not
approval of that rejected palette.

| Existing file                      | SHA-256 prefix |
| ---------------------------------- | -------------- |
| `departures-next/workspace.css`    | `987c3e1bd8ae` |
| `departures-next/soft-palette.css` | `625754c4404f` |
| `departures-next/app.js`           | `eebf5ac317c4` |
| `connected/today-palette.css`      | `2cfcc07b55a9` |
| `connected/today-header.css`       | `710e50e65104` |

No deployment, data migration, full application regression run or claim of
shipping readiness is part of this colour study.
