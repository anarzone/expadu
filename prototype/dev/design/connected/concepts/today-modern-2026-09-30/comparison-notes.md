# Comparison gallery

Open [comparison.html](comparison.html) to review seven versions (v4–v10) of the
modern Today concept. v1, v2 and v3 are excluded to simplify review; their original
files remain archived. Four scenario controls keep equivalent views together. Desktop
supports two or three cards per row; phones use one readable column.

Click any image to open a native dialog. The viewer includes zoom, fit, original
size, previous/next versions, mouse panning, double-click zoom and Escape to
close. Closing restores focus to the originating card. Rejected v6 stays clearly
labelled. All seven versions include an interaction board. v10 is a fresh
generation from a blank canvas; v9 remains alongside it for direct comparison.

The gallery works with relative local assets and no external dependencies.
For a browser preview, serve this concept directory on loopback:

```sh
python3 -m http.server 8766 --bind 127.0.0.1 --directory /Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/connected/concepts/today-modern-2026-09-30
```

Then open http://127.0.0.1:8766/comparison.html.

## Verification

- JavaScript syntax check passed.
- All 28 image references exist and have valid PNG dimensions.
- Browser checks: card click opens the correct enlarged image; original size
  renders at its intrinsic width; next-version navigation changes the board;
  Escape closes and restores focus.
- Scenario switching, archived v6 access, version ordering and two/three column
  switching checked in the browser.
- Narrow mobile viewport checked for page overflow and usable zoom/close controls.
- Desktop viewport restored after mobile checks.
- Screenshot: [comparison-preview.jpg](comparison-preview.jpg).

These checks cover the gallery, not the interactions illustrated within the
static app mockups. The product's accepted design and app code are unchanged.
