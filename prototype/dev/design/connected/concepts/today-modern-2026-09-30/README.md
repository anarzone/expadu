# Today: a modern daily assistant

Visual proposal for review. These are static concept boards, not a working app.

[Compare seven versions side by side](comparison.html). Select a scenario,
then click any board to enlarge it. The viewer supports zoom, drag to pan,
previous/next versions and Escape to close. The archived v6 boards are included
for comparison and remain rejected. v1, v2 and v3
are excluded from the review gallery; their original files remain archived.

The latest [fresh redraw, v10](fresh-v10/README.md), applies the owner's
1 October corrections to the preferred v9 composition. The appointment and its
journey form one group; recovery controls use white/orange outlines; a cyan
directional connection joins the journey times; City help has a plain heading.
All four selected images are generated from a blank canvas rather than edited
from earlier screenshots. v9 stays available alongside this refinement.

The menu remains above the content in its current two-row arrangement. Its revised treatment uses a compact cyan selection pill, aligned outlined icons and city/Save/account utilities. Weather appears once in Today’s content. Composer remains the existing design.

The selected menu is **Today · Events · Paperwork · Travel · Services**, in desktop and mobile concepts. The owner selected the compact labelled mobile version: smaller regular-weight labels, 20px icons and a 48–52px row with at least 44px touch targets. These values are design targets to measure in an approved working prototype.

The page body becomes a connected day: appointment → journey → later plans. Paperwork uses direct process rows; city tools stay compact. Events sit below practical information except when an exceptional highlight earns the lead.

The final refinement restores useful identifiers: a leading train beside journey times, a document/form beside City registration, and a simple plus before Add a plan. Paperwork rows, route summaries and event cards still open directly with a tap/click. A planned appointment has a small dots menu for Details and Remove, preserving its swipe/double-tap delete reveal. Useful Save and Plan controls remain separate. Saved ideas stay outside the agenda, city tools stay outside Paperwork, and mobile discovery uses one horizontal row. The logo is reserved for a separate discussion.

[Current interaction rules](fresh-v10/action-rules.md) · [Fresh-generation prompts](fresh-v10/final-prompts.json) · [Previous review findings](review-v5/design-review.md)

Familiar bookmark Save and labelled navigation remain. Today has one global Composer launcher. Contextual Composer access is available inside route details; the existing Composer design, draft preservation and review flow stay intact. Ordinary content opens with one tap. Planned activity bodies retain deletion gestures, while their menu exposes Details and Remove; opening details does not compete with a double-tap.

Orange identifies actions, cyan connects travel and selection, yellow highlights a significant event or disruption, and lavender supports secondary context. Near-white surfaces and dark text carry most of the page.

| Situation | Leading content | Board |
| --- | --- | --- |
| Everyday | Next appointment and its journey | [Desktop + mobile](fresh-v10/01-everyday.png) |
| Important event | One compact event spotlight, then the personal day | [Desktop + mobile](fresh-v10/02-important-event.png) |
| Route disruption | One appointment with its delayed journey and recovery actions | [Desktop + mobile](fresh-v10/03-route-delay.png) |

[Interaction storyboard](fresh-v10/04-interactions.png): separate Save/Plan,
direct process opening, one appointment with connected travel, contextual
Composer recovery and the appointment menu/gesture reveal before removal.

![Everyday proposal](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/connected/concepts/today-modern-2026-09-30/final-v9/01-everyday.png)

![Important-event proposal](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/connected/concepts/today-modern-2026-09-30/final-v9/02-important-event.png)

![Route-delay proposal](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/connected/concepts/today-modern-2026-09-30/final-v9/03-route-delay.png)

![Interaction storyboard](/Users/anar/Projects/Own/Startups/expadu-app/prototype/dev/design/connected/concepts/today-modern-2026-09-30/final-v9/04-interactions.png)

The three original boards remain available for comparison: [everyday](01-everyday.png), [important event](02-important-event.png), [route delay](03-route-delay.png).

The revised boards were edited with the built-in image tool. [Header revision prompts](header-v2/generation-prompts.md).

[Paperwork rename prompts](menu-v3/generation-prompts.md). [Mobile menu options](mobile-menu-options.md).

[Selected compact menu + Travel prompts](menu-v4/generation-prompts.md).

[Review and icon-refinement prompts](review-v5/generation-prompts.md). The previous [menu-v4 everyday board](menu-v4/01-everyday.png) and its other views remain available for comparison. Exact icons are official vector assets; the revised page images were edited with the built-in image tool.

[Final icon refinement prompts](final-v9/generation-prompts.md), using the built-in image tool. The [direct-opening pack](direct-actions-v8/01-everyday.png) and its [prompts](direct-actions-v8/generation-prompts.md), plus the preceding [icon-heavy proposal](action-icons-v7/01-everyday.png), remain for comparison. The final pack restores the useful train, document/form and plus identifiers without returning information/map/folder opening controls. Official Phosphor SVGs remain in `action-icons-v7/icons/`, with the dots asset in `direct-actions-v8/icons/`. The text-button proposal in `simplified-v6/` was rejected and is not a current reference.

## Design references

These informed principles rather than copied layouts:

- [Linear, March 2026](https://linear.app/now/behind-the-latest-design-refresh): stronger task hierarchy, predictable controls and quieter separators.
- [Google's expressive design research](https://design.google/library/expressive-material-design-google-research): colour, size, shape and motion should make useful actions easier to find; familiar behaviour remains important.
- [Apple materials guidance](https://developer.apple.com/design/human-interface-guidelines/materials): selective material treatment for functional controls. The current Composer and translucent event Save control are retained.

## Review limits

The images are generated visual proposals. All schedules, progress, alerts and photos are illustrative. The delay is explicitly simulated. No legal requirements, fees or deadlines are introduced.

The mobile boards show reading order and the next-card edge in a horizontal discovery row. They are not measured responsive layouts. Individual glyphs in raster boards remain approximations; use original SVG assets for the controls retained by the current interaction rules. Contrast, 320px layout, direct opening, menus, keyboard interaction, touch targets, gestures and motion still need browser validation in an approved prototype.

For implementation, reuse the existing Composer component and its exact behaviour. The storyboard illustrates context entry, not permission to redesign Composer. See [constraints and interaction rules](requirements.md).
