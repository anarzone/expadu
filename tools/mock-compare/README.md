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

Sign-in uses the E2E account (`MOCK_COMPARE_EMAIL` / `MOCK_COMPARE_PASSWORD` override the config, except
for a `login` with `"own": true`, which always signs in as its own account, e.g. an unverified one) and
the session is cached per port and account in `storage/mock-compare/.session-<port>-<account>.json`, so
repeated runs don't trip the login rate limit. Sign-in sends the side's `cookies` too, so a page loaded
while signing in already has the mock's clock; the cached session keeps none of them. A config that needs
an untouched account sets `"sibling"` in its `login`: it signs in as `<name>+<sibling>@…` of that
account instead, which its seed makes.

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
`contentDiffers: true`, `pixelThreshold` (the share of differing pixels this pair may have, e.g. `0.03`
for a tilted, zoomed part that anti-aliases differently while its box and styles match; the config-wide
`pixelThreshold` applies otherwise, default 0), `before` (steps run on both pages just before this pair, and left done for
the pairs after it, so open states go last; `mockBefore` / `appBefore` when the selectors differ).
Per side: `wait`, `init` (scripts run before the page), `before` (`[{ "click": "selector" },
{ "key": "Escape" }, { "wait": 300 }]` to open a menu or sheet first), `cookies` (`{ "name": "value" }`
on that side's origin, e.g. the app's `mock_clock`; PHP decodes cookie values, so write `+` as `%2B`),
`screenshotStyle` (CSS applied only while element screenshots are taken, to hide that side's own
chrome that can sit over an element: a sticky header, the floating Composer button, the local QA
switcher; boxes and styles are measured without it).

## Finding the pairs

`node tools/mock-compare/dump.mjs <url> <root selector> <width> [login]` prints the element tree with
boxes and text, so the mock's and the app's structures can be matched side by side. Cover every
visible component of the slice, including open states (menus, sheets, focus) via `before`.
The shell's open states have a config each: `shell-city`, `shell-account`, `shell-composer` (the
tray and its + menu, on Paperwork, where both trays name the page), `shell-focus` (each pair tabs
once more and compares the ring on `:focus`) and `shell-scroll` (phones: `PageDown` / `PageUp` steps;
a pair is measured before its shot scrolls back to the top). A config's `pixelThreshold` lets that
share of a shot differ, with its reason in a note.

## Same content

Pixel and wrapping checks only mean something with the same words. Freeze the clock to the mock's
sample time and load the mock's sample day through a **local-only** fixture (a seeder or QA persona
that never runs in production). Where content must stay live, mark the pair `contentDiffers` and
still match its box and styles.

On your machine (`APP_ENV=local` only):

- **Clock:** the server reads the plain `mock_clock` cookie (`App\Support\MockClock`). Set it from
  the app side's `init`, with the same moment as `clock`:
  `"init": ["document.cookie = 'mock_clock=2026-09-30T13:10:00%2B02:00; path=/'"]`
  or, so the very first request carries it too, from the side's `cookies`:
  `"cookies": { "mock_clock": "2026-09-30T13:10:00%2B02:00" }`. Use `cookies` when the page's first
  response depends on the day (Events works out a past day from it); `init` is enough where the
  day's data is deferred, as on Today.
- **Sample day:** `php artisan mock:sample-day {everyday|busy|quiet|travel} --email=<account>` writes
  the mock's plans for 30 Sept, 1 and 3 Oct (other dates stay). A config names the view it needs in
  `seed`; run that first.
- **Your own account:** sessions share the local database, so compare as your own account rather
  than the E2E one (session n: `mock-s<n>@expadu.test`). `php artisan mock:sample-day <view>
--email=mock-s<n>@expadu.test` creates it when it's missing (local only; verified and onboarded,
  with the E2E password), so no seeder is needed first. Run with
  `MOCK_COMPARE_EMAIL=mock-s<n>@expadu.test`, and delete
  `storage/mock-compare/.session-<port>-<account>.json` once so it signs in again.
- **Paperwork's sample record:** `E2E_EMAIL=<you>@expadu.test php artisan db:seed
--class='Database\Seeders\MockSample\PaperworkSeeder'` (after E2ETestUserSeeder for that account).
  It imports this checkout's catalogue, publishes its checked cards as the browser tests do and
  compiles the release itself, so it needs nothing else first, then replaces that account's
  Paperwork record through the v2 commands. `configs/paperwork.json` and the Today configs expect it
  (`PAPERWORK_SAMPLE=quiet` for `today-quiet`): the Blue Card appointment on the sample day is that
  record, not a plan of `mock:sample-day`'s.
- **Paperwork's entry states:** `paperwork-setup`, `paperwork-unavailable`, `paperwork-outside` and
  `paperwork-outside-joined` and `paperwork-eu` sign in as a sibling of your account (`<name>+setup@…`, `+unavailable`,
  `+outside`). `PAPERWORK_ENTRY=<entry> E2E_EMAIL=<you>@expadu.test php artisan db:seed
--class='Database\Seeders\MockSample\PaperworkEntrySeeder'` puts it in that state (no record; the
  sample record paused; the sample record outside the catalogue's cities; for `eu`, PaperworkSeeder's EU persona, `PAPERWORK_SAMPLE=eu`), and
  `PAPERWORK_WAITLIST=unconfirmed` adds the outside sibling to the city waitlist as after the tap. The
  mock picks the matching Review persona itself.
- **Places and events:** Today's discovery compares against the mock's sample places around Wiener
  Platz (`php artisan db:seed --class='Database\Seeders\MockSample\PlacesSeeder'`, the mock's own
  export of our 7 Oct catalogue; it adds missing places and never changes one) and its two evening
  events (`E2E_EMAIL=<you>@expadu.test php artisan db:seed
--class='Database\Seeders\MockSample\EventsSeeder'`, Events' seeder). Both are local only.
- **Saved ideas and a conversation:** `today-continuity` needs both sides seeded. The mock keeps them
  in its own store, which the config's mock `init` fills before load. The app gets the same from
  `E2E_EMAIL=<you>@expadu.test php artisan db:seed --class='Database\Seeders\MockSample\TodaySeeder'`
  (after PlacesSeeder and EventsSeeder); `today-phone` measures a phone's one column with the same
  seed. `today-first-visit` needs an account with nothing on it, so it signs in as your account's
  sibling (`<name>+first-visit@…`): `mock:sample-day first-visit --email=<account>` makes it when
  missing, with no plans at all, and never clears your account. It stops with a message if the
  sibling has picked up a plan, a save, a conversation or a Paperwork record.
- **Your own database** keeps a comparison reproducible: `DB_DATABASE=<yours> php artisan migrate
--seed` (create it with the postgis and vector extensions first), and start the compare app server
  with the same `DB_DATABASE`. Never `migrate:fresh` the shared one. Put `DB_DATABASE` (and your
  own `REDIS_PREFIX`) in the worktree's `.env`: a variable set on the command line doesn't reach
  Laravel under `php -S` on every setup, so the server can quietly read the shared database.
