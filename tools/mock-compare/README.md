# mock-compare: the mock and the app, 1:1

The owner's bar (20 Oct 2026): the app applies `prototype/dev/design/` 1:1. Every UI PR into
`launch/2026-10-05-readiness` attaches this tool's report, and it is re-run before merging.

## What it checks

For each pair of elements (one selector in the mock, one in the app), at each width:

- **Box:** x, y, width and height, within `tolerance` (default 0.5 px, sub-pixel rounding only).
- **Styles, exactly:** font family (the first face), size, weight, line height, letter spacing,
  font smoothing, feature and variation settings, colour, background, borders, radius, padding,
  gap, shadow, opacity. Text styles are only compared where the element itself holds text, and
  border colour/style only where a border is drawn.
- **Pixels:** the two element screenshots are diffed when they are the same size. Any differing
  pixel fails the pair unless the pair says `"contentDiffers": true` (live data that can't match).
- **The viewport:** both pages side by side with a diff, for the eye.

Both browsers run with the same clock (`clock`), time zone, locale, `reducedMotion: reduce`,
device scale 1 and service workers blocked. Phones (< 701 px) get touch and mobile emulation.

## Run it

Start the mock and the app from the same checkout (your worktree), each on your own ports:

```bash
python3 prototype/serve.py --port 8781 &
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8021 -t public .claude/serve-router.php &
node tools/mock-compare/compare.mjs tools/mock-compare/configs/shell.json
node tools/mock-compare/compare.mjs tools/mock-compare/configs/shell.json --only="saved icon"
```

URLs in a config may be paths: they resolve against `MOCK_COMPARE_MOCK` (default
`http://127.0.0.1:8781`) and `MOCK_COMPARE_APP` (default `http://127.0.0.1:8021`), so each session points
the same config at its own servers.

The report lands in `storage/mock-compare/<name>/report.md` with one image per pair and width
(mock · app · diff). Exit code 1 means something differs.

Sign-in uses the E2E account (`MOCK_COMPARE_EMAIL` / `MOCK_COMPARE_PASSWORD` override the config) and
the session is cached in `storage/mock-compare/.session-<port>.json`, so repeated runs don't trip the
login rate limit.

## Config

```json
{
    "name": "events",
    "clock": "2026-09-30T13:10:00+02:00",
    "widths": [360, 390, 768, 1280, 1440, 1512],
    "height": 900,
    "tolerance": 0.5,
    "mock": {
        "url": "http://127.0.0.1:8781/dev/design/events-next/?date=2026-09-30",
        "wait": ".st-root"
    },
    "app": {
        "url": "http://127.0.0.1:8021/events?date=2026-09-30",
        "wait": ".street",
        "login": { "email": "e2e@expadu.test", "password": "e2e-password" }
    },
    "pairs": [
        {
            "name": "date chip",
            "mock": ".date-chip",
            "app": "[data-events-date]"
        },
        {
            "name": "second poster",
            "mock": ".st-poster",
            "app": ".street-poster",
            "index": 1,
            "contentDiffers": true
        },
        {
            "name": "phone only",
            "mock": "...",
            "app": "...",
            "widths": [360, 390]
        }
    ]
}
```

Per pair: `index` (or `mockIndex` / `appIndex`), `widths`, `props` (replaces the default list),
`ignore` (props or `x`/`y`/`w`/`h` to skip, each with a reason in the PR), `pixels: false`,
`contentDiffers: true`. Per side: `wait`, `init` (scripts run before the page), `before`
(`[{ "click": "selector" }, { "wait": 300 }]` to open a menu or sheet first).

## Finding the pairs

`node tools/mock-compare/dump.mjs <url> <root selector> <width> [login]` prints the element tree with
boxes and text, so the mock's and the app's structures can be matched side by side. Cover every
visible component of the slice, including open states (menus, sheets, focus) via `before`.

## Same content

Pixel and wrapping checks only mean something with the same words. Freeze the clock to the mock's
sample time and load the mock's sample day through a **local-only** fixture (a seeder or QA persona
that never runs in production). Where content must stay live, mark the pair `contentDiffers` and
still match its box and styles.
