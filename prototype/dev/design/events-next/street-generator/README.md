# The street's generators

These scripts draw the Events street (the "2 · Stories" board from the
"Events reimagined" canvas) and write the two generated files the Events
page uses:

- `../street-art.js`: the street's templates, its fixed drawings and the
  board's own logic;
- `../street-art.css`: the street's animations (clouds, birds, rain, smoke,
  leaves, pigeons).

Don't edit those two files by hand: change the scripts here and regenerate.
`../street.js` (which runs the street on the page) and `../wall.js` are
written by hand and aren't generated.

## Regenerate the street

From this folder:

```bash
python3 port_street.py ..
```

It prints the size of each file. Then refresh the page's asset versions
(`node tools/asset-versions.mjs` in `prototype/dev/design`) and run
`tests/wall-browser.cjs`.

## What each script does

- `scene_art.py`: the drawings: sky, Cologne skyline, plane-tree branch,
  wall, advertising column, lamp, bench, bike, pigeons, rain.
- `gen_scene.py`: puts the drawings together into the street for an hour of
  the day (sky colours, weather, lights), on desktop and phone.
- `stories_ui.py`, `stories_real.py`: the hour rings and the story viewer,
  with the sample day's real events.
- `gen_apps.py`: the four app models on the canvas (Shelves, Stories, Swipe,
  Scroll the day); the Events page uses Stories.
- `gen_wall.py`, `gen_wall2.py`: the earlier wall options; the scripts above
  still use a few of their parts.
- `mx.py`, `mxcss.py`: the canvas boards' copy of the Events page's own
  posters and details sheet, read from `mx/capture.json`.
- `port_street.py`: turns the Stories board into the page's
  `street-art.js` and `street-art.css`.

## The capture

`mx/capture.json` holds the Events page's posters, sheet states and CSS as
they were on 12 October 2026 (commit `2cdabc10`). `port_street.py` needs
it. To capture again, serve the prototype on port 8792 and run, from the
repo root:

```bash
node prototype/dev/design/events-next/street-generator/mx/capture.cjs prototype/dev/design/events-next/street-generator/mx/capture.json
```

`BASE=http://127.0.0.1:<port>/dev/design/events-next/` points it at another
port. A new capture can change the canvas boards; check the street still
regenerates the same before relying on it.

## The canvas boards

`gen_apps.py canvas-assets.json` and `gen_scene.py canvas-assets.json` write
the canvas boards into `events-canvas/project/` (ignored by git), where a
copy of the canvas project must already be. `canvas-assets.json` maps the
drawings' pictures and fonts to the canvas's uploaded files.

Since 13 October some changes were made only on the Events page, not on the
canvas: the street draws its big moving groups as single pictures (faster),
the tap targets, and a lifted poster that grows open and closes with an X.

These scripts were rebuilt on 13 October 2026 from the session that wrote
them, after its temporary folder was cleared. They regenerate
`street-art.js` and `street-art.css` byte for byte.
