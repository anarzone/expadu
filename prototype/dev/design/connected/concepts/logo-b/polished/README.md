# Expadu — original Concept B, lightly polished

This is the correction following the owner's feedback that the connected-E
redesign in `../refined/` departed too far from the original logo. The requested
direction is the original two-piece ribbon, with only light finishing work.
This correction is for review and has not been applied to the app.

## Changes

- Add very small fillets to the upper ribbon's short return, the junction below
  its rounded cap, and the lower ribbon's inner turn and exposed left edge.
- Move the existing outlined wordmark 1 unit closer at the native 124 × 40 size.

Both ribbon pieces, their separation, the distinctive upper cap, the open
cutouts, overall dimensions, orange gradients, wordmark outlines, lettering
size and ink colours are preserved from the original B reconstruction.

The geometry changes are intentionally small. A comparison of binary alpha
masks rendered at 100× gives 99.901% intersection-over-union and identical
painted bounds. This is a shape-preservation check, not a perceptual-quality
score. The polished logo was also inspected against the original artwork and
the Concept B raster reference at the same scale.

## Assets

- `expadu-b-polished-mark.svg` — mark only, 32 × 40 viewBox.
- `expadu-b-polished-lockup.svg` — horizontal logo, 124 × 40 viewBox.
- `expadu-b-polished-lockup-dark.svg` — original orange mark with light lettering.
- `png/` — 12 transparent PNGs at 1×, 2×, 8× and 16× each SVG's native dimensions.
- `preview.png` — original/polished comparison, dark presentation and small sizes.
- `preview.html` — local viewer using existing project fonts and reference artwork.
- `expadu-b-polished-assets.zip` — the three SVGs, twelve PNGs, preview image and
  this guide. The project-dependent HTML viewer is not included.

All SVG artwork consists of editable paths and gradients. Lettering is outlined
and has no font dependency. Cutouts and backgrounds are transparent.

## Checks

Verified the unchanged gradient definitions and letter paths; two separate ribbon
components; correct PNG dimensions and alpha channels; transparent cutouts and
unclipped bounds. Inspected the logo at its header scale and the mark at 20, 24,
32 and 40px tall. The viewer loaded all images/fonts without horizontal overflow
at 1120px and 390px widths. The original deliverables were checked against their
original ZIP and remain byte-for-byte unchanged.

This remains a reconstruction of the supplied raster concept, not a recovered
original vector master. No app logo or shared page code was changed.
