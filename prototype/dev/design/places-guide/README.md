# Places as a local field guide

**Integration update, 27 September:** the current connected implementation is
[`places-next/`](../places-next/README.md). This folder preserves the earlier
exploration and its reusable catalogue/model. Its bespoke shell, side detail pane
and native date/time inputs are superseded by the shared patterns recorded in
[Connected design decisions](../connected/design-decisions.md).

Decision approved in the conversation, 27 September 2026. Tracked under EXP-73.
This is a separate design revision at `/dev/design/places-guide/`; Today C and the
earlier Places concepts remain available.

## Design decisions

- Keep Today C's Geist typeface, pale canvas `#f6fafb`, white surfaces, ink
  `#211d15`, cyan `#05badd`, orange `#ff3902` and accessible action `#d9340a`.
- Left-align names, descriptions and actions. Use 32px page type, 27px collection
  titles, 17–19px place names and 14–16px supporting text.
- Start with actual choices, not a map or a large promotional hero. One larger
  park-and-garden collection sits beside two smaller collections. Real places
  within each collection are directly openable. Smaller collections follow.
- Search, category and area controls remain compact. Search covers the entire
  prepared snapshot, including places without images. Save is independent of
  scheduling. Composer is available when the person wants help planning.
- Details occupy an adjacent pane on desktop and the content area on mobile,
  keeping the underlying browsing and unfinished visit form intact. Photos, when
  approved, are optional detail content. Missing photos never reserve space.

```text
Desktop                             Mobile
Expadu / city / Composer            Expadu / Cologne / Composer
Today  Places  Events …             Today Places Events …
Places        [Search] [Saved]      Places
Categories         [Search area]    [Search                 ] [Saved]
┌ Parks & gardens ┐ ┌ Coffee … ┐    [Activity choices →] [Area]
│ actual places  │ └───────────┘    Parks & gardens
│ open / save    │ ┌ Art …    ┐      Volksgarten     [Save]
└────────────────┘ └───────────┘      Rheinpark       [Save]
More ways to explore                [Explore collection]
Browse all places / results         Coffee outside …
```

Self-review before building: a row of identical category tiles followed by a
directory would repeat the rejected concept. The revised composition exposes
named choices inside meaningful collections, varies their emphasis, and uses
facts such as outdoor seating to explain why a place belongs. Large decorative
illustrations, map-first browsing and image placeholders are omitted because
they would push those choices out of the first mobile viewport.

## Data boundary

`prepare-catalogue.py` derives `catalogue.json` from the existing 18 September
research snapshot. `catalogue-manifest.json` records its checksum and exclusions.
The 4,956 records are provisional source identities in a design dataset, not new
imports, a city census or published app inventory. Category coverage is limited
to the explicit discovery kinds in the preparation script. Eight existing
reviewed photos are reused; this work does not increase photo coverage.

Names, coordinates, opening-hours strings, amenity facts, addresses and collection
membership come from the primary source. Missing neighbourhoods are not inferred
from postcodes; unknown hours do not mean closed or open. Museum membership does
not imply free entry. Outdoor seating does not imply availability or good weather.
Source identities remain attached when a place is saved or proposed in Composer.

## Implementation and checks

The working preview includes collections, full-catalogue search, recorded area
filters, saved ideas, place previews, optional credited photography, dated
proposals with Undo and Composer return, and explicit external directions.

See [HANDOFF.md](HANDOFF.md) for the production contract, component boundaries,
API gaps, implementation sequence and staged acceptance criteria. The broader
design ticket remains In Progress; this preview is not a deployed app migration.

Validation is recorded in `tests/verification.json`. Run the focused model and
shared handoff tests with Node, and `tests/verify.cjs` against the existing local
prototype server on port 8765. Browser contexts are isolated from the user's saved
state. Screenshots cover desktop, mobile and details without a photo.

After the first visual pass, the park collection was expanded to expose more
actual choices on desktop, inherited header spacing was removed, and narrow
navigation was made horizontally scrollable. The first place and Save action
remain inside the initial 844px-high viewport at 320, 390 and 768px widths.
