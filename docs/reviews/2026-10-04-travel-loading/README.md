# Travel refresh consistency — 4 October 2026

The owner reported different broken Travel layouts after refreshing the same
URL. The approved White & Lagoon / side-by-side design was not changed in this
fix.

## Confirmed cause

The running process was the standard Python 3.14.6 `http.server` on
`127.0.0.1:8765`, serving `prototype/`. Its pending-connection queue held five
connections. Travel requests 40 external stylesheets and scripts; overlapping
loads could exceed the queue and receive connection resets.

The cold HTTP reproduction requested all 40 assets in six rounds: three at six
concurrent requests and three at 24 (a multi-tab burst). There were **34 failed
requests out of 240**, including the palette, workspace, header and Composer
assets. Successful responses matched the local files byte for byte. Missing
different combinations of these resources explains the owner's three screenshots:
an old/raw header, absent icons and route rows, or old gray surfaces around new
route markup. See `before.json` for the actual reset errors.

## Fix

- Introduced `prototype/serve.py` as the shared startup command, retaining the
  existing loopback address, port and prototype directory, with a queue of 128.
- Restarted the local server on the same port. Logs go to
  `/tmp/expadu-prototype-8765.log`.
- Updated the startup instructions and routed the older Places test-server
  entry point through the shared server. The earlier fix had been isolated in
  that page's test folder; the usual startup command still used the default.
- Added a socket regression that queues 24 asset requests before accepting them.
  Substituting the old server reproduced **19 resets out of 24**; the corrected
  server delivered all 24 complete stylesheets.

## Verification

- The identical six-round live-server check now gives **240/240 complete,
  matching responses**, zero failures (`after.json`). It does not use browser
  cache or retries to hide failures.
- Five browser reloads at 1440 × 900 consistently showed the white canvas,
  72px header, side-by-side form/results, three mode controls, three sample
  routes and 36 rendered icons. No browser errors or warnings were reported
  (`browser-reloads.json`, `desktop.png`).
- The page also rendered correctly in the browser's normal narrow viewport
  before the desktop check. The temporary viewport override was reset.

Run the checks from the repository root:

```sh
python3 -m unittest discover -s prototype/tests -v
python3 prototype/tests/check_preview_assets.py 8765 /tmp/expadu-preview-load.json
```

This corrects local preview delivery. It does not change the Travel design,
stored user data, real application or deployment.
