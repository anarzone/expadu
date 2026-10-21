# Expadu — Concept B / Festival logo reconstruction

Reviewable assets reconstructed on 29 September 2026 from the orange logo in
`../events-festival-b.png`. This is a deliberate vector reconstruction from a
small raster concept, **not the original vector source**. It is separate from
the application's current logo.

## Editable artwork

- `expadu-b-mark.svg` — orange mark only, 32 × 40 viewBox.
- `expadu-b-lockup.svg` — horizontal lockup with dark lettering, 124 × 40 viewBox.
- `expadu-b-lockup-dark.svg` — the same lockup with light lettering for dark backgrounds.

The mark contains two independently editable Bézier paths. Both open cutouts
and the outside canvas are genuinely transparent; there are no white patches,
embedded raster images, filters or background rectangles. Gradients preserve
the soft orange highlight in the concept. The mark is the same on both themes.

The wordmark uses the project's **Geist SemiBold (600)**, with optical spacing
and scale adjusted to the reference. It is an approximation of the lettering
in the generated concept. Every letter is an editable vector path; the SVGs
have no font dependency. Change the `wordmark` group's fill to recolour it.
The mark's `orange-upper` and `orange-lower` gradients control its tonal treatment.

## Transparent PNGs

All 12 exports in `png/` are RGBA, with transparent backgrounds and cutouts.

| Artwork | Export dimensions |
| --- | --- |
| Mark | 32 × 40, 64 × 80, 256 × 320, 512 × 640 |
| Dark-letter lockup | 124 × 40, 248 × 80, 992 × 320, 1984 × 640 |
| Light-letter lockup | 124 × 40, 248 × 80, 992 × 320, 1984 × 640 |

Use SVG when possible. PNG exports retain the SVG canvas padding. The 40px-tall
canvas contains approximately 34px of painted mark, matching the concept's
header proportions. Enlarge proportionally; do not stretch width and height
independently.

## Review

- `preview.png` — light/dark board with small-size and reference comparisons.
- `preview.html` — responsive local viewer and download links. It uses the
  existing project fonts and the original concept image via relative paths.
- `expadu-b-assets.zip` — the three SVGs, twelve PNGs, preview image and this guide.
  The project-dependent HTML viewer is not included in the portable archive.

Checked against the original raster at its header size and in an enlarged
comparison. The mark was visually inspected at 20, 24, 32 and 40px canvas heights
on light and dark backgrounds. The preview was checked at 1200px and 390px wide:
all images/fonts loaded and neither layout overflowed horizontally. Every PNG
was checked for dimensions, alpha transparency, transparent cutouts and unclipped
artwork. Every SVG was checked for editable paths and absence of raster embeds.

Only new deliverables in this folder were created. No app logo, shared page code,
design decisions or other application files were changed by this task.
