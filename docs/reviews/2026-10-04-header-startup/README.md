# Header startup flash — 4 October 2026

The owner reported the same startup flash in the header after the obsolete
Composer dock was fixed. The approved appearance and page compositions remain
unchanged.

## Confirmed cause

Travel's initial HTML contained the older header plus a separate navigation
row. Shared UI generated that older structure on the other departments and
Today; Composer generated its own copy. Deferred header enhancement later
replaced icons and labels, moved navigation into the header, grouped utilities
and added the account control. Chrome could paint each intermediate layout.

With all scripts withheld, Travel visibly showed the old two-row header with
no navigation icons. Its page heading started at **177.75px**. Once normal
startup finished, the heading started at **98.75px**: a **79px** jump.
`before-initial.png` captures the actual initial markup in Chrome.

## Fix

- The six active entry pages now contain the final header HTML, SVG icons,
  navigation labels, destinations and utility controls before scripts run.
- `connected/prerender-headers.cjs` generates those blocks from one maintained
  template and the existing approved icon source. City and profile defaults
  are explicit preview configuration.
- Shared UI, Today and Composer retain an existing static header instead of
  replacing it. Shared header enhancement only binds account behavior and
  updates Saved counts for these pages.
- Older alternatives retain their existing fallback construction. Their
  layouts were not redesigned.
- References to the four modified runtime scripts use updated content hashes.

This does not hide the header or delay the whole page. Navigation links and
icons are present even while JavaScript is unavailable.

## Verification

The new initial-HTML regression failed on all six pages before implementation
and passes afterward. It checks that one usable app header contains all five
labelled links, inline icons and utility controls before enhancement.

Chrome checks compared actual initial HTML with the fully loaded pages:

| Viewport             | Initial header | Loaded header | Screens checked                                      |
| -------------------- | -------------- | ------------- | ---------------------------------------------------- |
| Desktop, 1512px wide | 72px           | 72px          | Travel, Events, Paperwork, Services, Today, Composer |
| Phone, 390px wide    | 120px          | 120px         | The same six screens                                 |

Each screen kept one header, one utility group and five navigation icons.
Phone navigation stayed within the viewport. Travel's heading remained at
98.75px before and after startup. Three subsequent Chrome reloads retained
that position and header height, with no legacy Composer dock.

Menu navigation, Travel's city popover, department account popover, Today's
existing account dialog and Saved opening Composer were exercised. No plan,
draft, city selection or account setting was edited. No browser warnings or
errors were reported in these checks.

- 309/309 relevant Node tests passed. An existing shared-UI test double was
  updated to expose the real shell element's `querySelector` method.
- 2/2 Python preview regressions passed, including asset delivery.
- Modified JavaScript passes syntax checks; JS/HTML formatting and header
  regeneration checks pass.

Evidence: `browser-checks.json`, `before-initial.png`, `after-initial.png`, and
`chrome-travel.png`.

## Reproduce or maintain

```sh
# Serve actual markup with runtime scripts withheld, on an isolated local port.
python3 prototype/tests/composer_boot_preview.py --phase initial --port 0

# Regenerate headers after an approved shared-header edit.
node prototype/dev/design/connected/prerender-headers.cjs
node prototype/dev/design/connected/prerender-headers.cjs --check

python3 -m unittest discover -s prototype/tests -v
```

At the printed QA origin, open `/dev/design/departures-next/`, then use its
navigation to inspect the other initial headers. This QA mode intentionally
withholds dynamic page content and interactive handlers. Normal previews
continue to run on port 8765.
