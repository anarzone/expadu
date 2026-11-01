# Travel planner startup stability

The approved White & Lagoon layout is unchanged.

## Cause and change

The initial HTML contained an empty travel-mode row. JavaScript then inserted
Transit, Cycle and Walk, adding 44px above Find a route. The initial page now
contains those controls and the first-screen icons from the existing icon set.
Initialization updates selection without rebuilding controls or discarding focus.
Older isolated fixtures retain their runtime-rendering fallback.

## Verification

- Reproduced in Chrome: the desktop button moved from y482.84 to y526.84.
- After the fix, initial and enhanced button positions match: y526.84 at
  1512px wide and y551.70 at 390px wide. Form and field dimensions also match.
- Three desktop reloads and one phone reload retained those document positions.
  `browser-checks.json` records viewport coordinates and scroll offsets separately.
- Selecting Cycle, Walk and Transit retained focus and the button position.
- An empty submission showed validation and focused the origin field.
- No Chrome warnings or errors in the checks.
- The new initial-HTML and control-preservation tests failed before the fix;
  both pass after it. All 38 Travel tests and both startup test modules pass.
- JavaScript syntax, formatting and generated-header checks pass.

Reproduce initial rendering with
`python3 prototype/tests/composer_boot_preview.py --phase initial --port 0`.
The folder URL withholds scripts; its `index.html` URL loads normally on the same
isolated origin. Compare identical viewport sizes. Stop the temporary server
after testing; the main preview stays on 8765.

This fixes the planner's startup movement. Sample route results still render
through JavaScript. A wider app-continuity pass should address initial result
loading, persistent navigation/Composer, state restoration and local feedback.
This turn does not migrate the preview into the Laravel application.

Evidence: `before-initial.png`, `chrome-travel.png`, `browser-checks.json`.
