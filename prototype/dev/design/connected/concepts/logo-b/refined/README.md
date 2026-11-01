> **Rejected exploration.** The owner rejected this connected-E redesign because it changes the original logo too much. Continue from the two-piece Concept B mark; see `../polished/` for the current correction for review.

# Expadu — refined ribbon E

A new vector refinement of the Concept B reconstruction, following the owner's
approval of the proposed improvements in this chat. This is review artwork;
it has not replaced the logo in the application. The earlier reconstruction
remains in the parent folder, unchanged.

## Design

The mark is one continuous, closed Bézier path. Three rising strokes and a
shorter middle arm make the E explicit. Rounded outer terminals and softened
inner turns retain the welcoming ribbon character. Both channels open to the
right; they are transparent geometry, not white shapes.

The horizontal logo reduces the mark's painted height by about 9% and its gap
to the wordmark by about 16% compared with the B reconstruction. The lettering
remains Geist SemiBold (600), with the earlier optical letter spacing preserved.
Every letter is an editable outline. No fonts are required to use these SVGs.

The solid master uses orange `#FF5C33`, already present in the project's dark
primary palette. Dark lettering uses `#101828`; reversed lettering is white.
The optional tonal version adds a restrained highlight (`#FF8257` → `#FF5C33`
→ `#FF4D24`). The silhouette is identical in every treatment.

## Artwork

| File | Use |
| --- | --- |
| `expadu-ribbon-mark.svg` | Solid orange mark; primary standalone artwork |
| `expadu-ribbon-lockup.svg` | Orange mark with dark wordmark |
| `expadu-ribbon-lockup-dark.svg` | Orange mark with white wordmark |
| `expadu-ribbon-mark-tonal.svg` | Mark with the optional soft highlight |
| `expadu-ribbon-lockup-tonal.svg` | Highlighted mark with dark wordmark |
| `expadu-ribbon-mark-ink.svg` | Single-colour dark mark |
| `expadu-ribbon-mark-white.svg` | Single-colour white mark |
| `expadu-ribbon-lockup-ink.svg` | Entire logo in dark ink |
| `expadu-ribbon-lockup-white.svg` | Entire logo in white |
| `expadu-app-icon.svg` | White mark on an orange square |
| `expadu-app-icon-dark.svg` | Orange mark on a dark square |
| `expadu-favicon.svg` | Transparent square favicon composition |

Mark viewBox: 40 × 48. Horizontal logo viewBox: 120 × 40. Scale proportionally.
Each SVG contains the same named `ribbon-e` path; wordmarks retain independently
editable letter paths. The app icons include intentional opaque backgrounds.
They are exported as full squares; the preview applies rounded display masks.

## PNG exports

The `png/` folder contains 26 exports:

- Five standalone mark treatments, each at 80 × 96 and 400 × 480.
- Five horizontal treatments, each at 240 × 80 and 1200 × 400.
- Two app-icon studies, each at 192 × 192, 512 × 512 and 1024 × 1024.
- One transparent favicon composition at 16 × 16 and 32 × 32.

Logo and favicon exports have transparent backgrounds. App-icon exports are
opaque by design. `expadu-ribbon-refined-assets.zip` includes all SVGs, PNGs,
the two review boards and this guide.

## Review and checks

- `preview.png`: primary logo, dark presentation, app-icon studies, small sizes,
  and before/after comparison.
- `treatments.png`: solid, tonal, dark, white and monochrome treatments.
- `preview.html` and `specimens.html`: local project viewers with download links.
  They use the project's existing fonts; the before/after viewer also reads the
  original logo in the parent folder. These viewers are not in the portable ZIP.

The board was inspected at desktop and phone widths (1280px and 390px). Both
layouts loaded all images/fonts without horizontal overflow. Small marks were
inspected at 16, 24, 32 and 48px. The 16px favicon's solid pixels form one
connected shape. All SVGs were checked for matching mark geometry, editable
paths and absence of raster embeds. PNG dimensions, alpha transparency,
open channels and edge clearance were checked. Opaque app-icon backgrounds
were checked separately.

This refinement evolves a raster-derived concept; it is new vector artwork,
not a recovered original brand master. The preview shows identity studies,
not an implemented application update.
