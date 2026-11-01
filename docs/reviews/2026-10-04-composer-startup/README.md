# Composer startup flash — 4 October 2026

The owner clarified the remaining Chrome refresh symptom: an input with an
orange **Compose** button appeared for a split second. This was a separate
startup defect from the asset connection failures documented in
`../2026-10-04-travel-loading/README.md`.

## Reproduction and cause

Travel contains an obsolete `.composer-dock` in its initial HTML. Events,
Paperwork and Services populate the same legacy form inside `#dock` during
shared UI startup. The later, deferred `composer-entry.js` removes that markup
and inserts the approved collapsed Composer tray. Until that script ran,
Chrome could paint the old form.

The isolated preview in `prototype/tests/composer_boot_preview.py` serves the
actual page files but withholds Composer initialization and subsequent scripts.
Before the fix, Chrome displayed the obsolete Travel bar indefinitely: its
rendered height was **66px** and the replacement tray did not exist. See
`before-paused.png` and the before record in `paused-startup.json`.

## Change

The render-blocking shared `composer-entry.css` now hides both legacy roots
immediately. It does not depend on a class set by deferred JavaScript. The
obsolete nodes remain available to existing startup bindings until the shared
entry removes them as before. The new tray uses different selectors and is
unaffected. Active pages and fixtures with versioned stylesheet links request
the updated content hash.

## Verification

- Paused Travel startup: the legacy bar remains in the DOM but has
  `display: none` and **0px** height; no replacement tray exists yet.
- Paused Events, Paperwork and Services: both legacy wrapper and form have
  `display: none` and **0px** height. Today has no legacy dock at this stage.
- The same paused Travel check passes at a **390px** viewport width.
- Normal Chrome loading of all five main pages produces exactly one current
  Composer tray and launcher, with zero legacy docks. Travel was also refreshed.
- Opening Travel's launcher reveals and focuses the approved textarea. Closing
  and reloading returns to the collapsed launcher. No draft was edited or sent.
- The regular port 8765 pages reported no browser warnings or errors in these
  checks. The paused QA origin intentionally omits later dependencies and is
  only used to inspect the pre-Composer visibility state.
- **23/23** focused Composer controls, planning entry and Travel workspace tests
  pass. The changed CSS passes Prettier; the QA helper passes Python compilation.

Evidence: `after-paused.png`, `chrome-travel.png`, `paused-startup.json`,
`mobile-startup.json`, and `normal-startup.json`.

To reproduce the startup regression from the repository root:

```sh
python3 prototype/tests/composer_boot_preview.py --port 0
```

Open the printed local address followed by `/dev/design/departures-next/` in
Chrome. Before the fix the old bar is visible; with the fix no Composer form is
painted, even though initialization never runs. Use navigation to check the
other four pages. This is an isolated QA origin, not the normal preview server.

Focused interaction checks:

```sh
node --test prototype/dev/design/today-c/tests/composer-controls.test.cjs prototype/dev/design/connected/tests/planning-entry.test.cjs prototype/dev/design/departures-next/tests/workspace.test.cjs
node node_modules/prettier/bin/prettier.cjs --check prototype/dev/design/connected/composer-entry.css
```
