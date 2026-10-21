# Expadu — ribbon form studies in the approved palette

The owner requested braver changes to the original logo while keeping Expadu's
approved colour palette. These studies change the ribbon's contours, openings,
fold relationships and lettering. They retain the original two-piece structure
and rounded upper cap. No application logo or page code has been changed.

## Shape directions

- **Fold:** a fuller body, rounded middle return and wider lower piece.
- **Interlock — recommended for review:** a significantly longer middle return
  that aligns with the lower fold, defining a diagonal passage between the two
  pieces. Its centre is more open and its silhouette is wider than the original.
- **Loop:** a wider upper opening and a sweeping lower curve with a softer rising
  terminal. It explores a more rounded interpretation of the original mark.

The board includes the original and all three new shapes in the same ink colour
and at equal canvas sizes, so their structural differences can be judged apart
from the colour treatment. The recommendation is the assistant's; the owner has
not selected or approved a replacement identity.

## Palette

Only these base colours appear in the new logo assets:

| Role | Hex |
| --- | --- |
| Dominant ribbon orange | `#FF3902` |
| Turned-back ribbon face | `#05BADD` |
| Wordmark / monochrome / dark surface | `#211D15` |
| Reversed lettering / translucent highlight | `#FFFFFF` |
| App-icon study background | `#F6FAFB` |

Sources: `prototype/dev/design/today-c/style.css`, the palette paragraph in
`prototype/dev/design/today-c/README.md`, and
`prototype/dev/design/connected/shell-review.css`. Supporting UI accent colours
are not used. Highlights and shadows use white/ink transparency over the core
orange, rather than introducing additional base colours. The cyan face is a
small structural accent; orange remains the dominant colour.

The palette was also confirmed by the **Expadu product design review** chat on
2026-09-29 (thread `01a071b7-23e0-7502-82aa-67ade1312403`). That review recommended
dominant orange, a small cyan underside and an ink wordmark, with subtle
white/ink blending and a flat small-size version. This confirms the palette and
provides design guidance; it is not owner approval of a finished logo.

Geist Bold (700) supplies the outlined wordmark. There are no font dependencies
in the exported SVGs.

## Assets

Each of `a-fold/`, `b-interlock/` and `c-loop/` contains:

- `mark.svg`: colour mark with internal fold planes; transparent 32 × 40 canvas.
- `mark-orange.svg`: solid orange mark, useful at small sizes.
- `mark-ink.svg`: monochrome mark for evaluating and reproducing the silhouette.
- `logo.svg`: orange/cyan mark with dark lettering, 124 × 40 canvas.
- `logo-dark.svg`: colour mark with white lettering for dark backgrounds.
- `icon.svg`: opaque square app-icon study on the pale canvas.
- `png/`: twelve exports, two sizes for each SVG.

Standalone marks are exported at 32 × 40 and 256 × 320. Logos are 248 × 80 and
992 × 320. App icons are 128 × 128 and 512 × 512. App icons retain square corners;
the preview applies a rounded display mask.

`original-reference.svg` is the starting B silhouette in ink, for comparison.
`geometry.json` records the editable contour coordinates; `palette.json` records
the source colours. The portable ZIP includes SVGs, PNGs, the review board and
these supporting files. The HTML viewer remains local because it uses the
project's existing font files.

## Verification

Inspected the board at 1280px and 390px widths. All images/fonts loaded, with no
horizontal overflow. Verified that every new SVG colour comes from the listed
core palette, all SVG IDs are unique, and each mark has separate upper/lower
ribbon groups. Checked all 36 PNG dimensions, expected alpha channels and edge
clearance. All three solid silhouettes retain two separate connected pieces
at 16, 24, 32 and 40px canvas heights.

The original B asset files remain unchanged. Earlier continuous-E, minimal
polish and off-palette colour explorations remain available as history.
