# Interactive Today design prototypes

Two code-based, clickable prototypes, isolated from the Laravel application.

- **Fresh direction (default)**: horizontal navigation, discovery first, no permanent right-hand widgets, and planning through a contextual bottom tray. `/dev/design/?concept=fresh`
- **Refined familiar**: familiar sidebar, serif and warm colours, consolidated city context, quieter paperwork entry and improved planning surface. `/dev/design/?concept=refined`

Working interactions: category filtering, searchable example catalogue, place details, saving places, adding/removing day selections, opening the planner, sample plan choices, and saving exactly the selected items in memory. Menus expose every original destination. Transit, legal tasks, weather and alerts intentionally show disconnected demo states rather than invented live results. Switching variants resets demo state.

Run from the repository root:

```sh
python3 prototype/serve.py
```

Open <http://127.0.0.1:8765/dev/design/>. No dependency installation, application route edits, database access, credentials or deployment is required.

Use this shared server for the connected previews. It handles parallel stylesheet
and script requests from multiple tabs; the plain Python server's five-connection
queue caused intermittent resets and partly styled pages. `--port` is optional
and defaults to 8765. Check the queue regression with
`python3 -m unittest discover -s prototype/tests`.

`index.html`, `styles.css`, and `app.js` implement the prototypes. Fonts are copied from existing project assets. The two generated illustrations in `assets/` are decorative editorial representations, not photographs of verified venues. The rejected initial image concepts and their comparison page are retained only in `archive/`; they are not used as the prototype UI.

Design brief: content first, friendly, lightly playful; preserve the original capabilities while exploring a genuinely different composition. Inspiration concerns qualities (inviting discovery, approachable character, practical city information), not copying a commercial app screen.
