# Final icon balance — v9

Approved scope: retain v8's direct opening and secondary-action menus while
restoring useful content identifiers and the plus in Add a plan.

This is the final visual refinement of this concept pack. These are static
boards, not an implemented or browser-tested app. All content remains sample;
the delay is explicitly simulated.

## Identification, actions and opening

| Element | Symbol | Behaviour |
| --- | --- | --- |
| Journey summary | One leading train/mode symbol beside the departure and arrival times. | The whole summary opens route details. The symbol is not a separate button. |
| City registration | Small leading document/form symbol beside the name. | The whole row opens the exact process. The symbol is not a separate button. |
| Add a plan | Simple orange plus followed by Add a plan. | One creation action and one hit area. No extra circle, calendar or opening arrow. |
| Saved event | Bookmark Save with its existing saved state. | Saves independently of planning or opening details. |
| Event planning | Calendar-plus Plan. | Opens the existing proposal review. Does not imply confirmed attendance. |
| Planned appointment | Neutral horizontal dots. | Opens the compact Details / Remove menu. |

Use the same symbol for the same meaning across adaptive states. A disruption
changes urgency and content order, not the meaning or placement of its mode
identifier. In the delay view, the train moves into the cyan time strip; do not
also repeat it beside the delay badge.

Keep necessary navigation, weather, status, city-tool and event metadata symbols.
Keep generic headings quiet. Do not add icons only to fill empty space.

## Opening and secondary actions

Paperwork rows, journey summaries, saved idea bodies and event card bodies open
directly with one tap/click. No trailing information, map, folder or arrow
buttons; no repeated Open/Details text buttons. Dots appear only where actual
secondary actions exist.

A planned appointment is the exception: its body preserves swipe/double-tap
deletion gestures and its dots menu exposes Details and Remove. Do not navigate
on the first tap and thereby intercept the second tap. Do not delay ordinary
single-tap opening elsewhere.

Remove from the menu, swipe left or double-tap reveals the same narrow red
circular delete action. A separate activation removes the item, with Undo.
Escape, outside activation or swipe back dismisses the reveal. The row remains
recognizable and is never fully covered by a confirmation banner.

## Keep the accepted composition

- Everyday personal day first; important event or relevant interruption may lead.
- Saved ideas remain separate from confirmed agenda entries.
- City tools remain outside the Paperwork surface.
- Mobile uses one horizontal event rail with a visible next-card edge.
- Existing top menu: Today, Events, Paperwork, Travel, Services.
- Existing Composer remains unchanged, with one global launcher. Route details
  retain contextual entry with draft/conversation preservation and no automatic send.
- Keep known progress only. City registration has no progress indicators in this
  sample; the EU Blue Card retains its recorded 1/5.
- Keep fixed event schedules, sample labels and simulated delay context.

## Implementation handoff

Use the existing Phosphor Regular SVG assets; the generated raster glyphs are
visual approximations. Exact train, file-text and plus assets are in
`../action-icons-v7/icons/`, with their MIT licence and source hashes.
The dots asset remains in `../direct-actions-v8/icons/`.

Leading identifiers belong to their row and are hidden from assistive technology
when the adjacent text already provides their meaning. They do not create extra
focus stops. Add a plan is one accessible action containing a decorative plus
and visible words. Separate icon actions need precise accessible names.

Direct-opening surfaces need semantic links/buttons, visible keyboard focus and
subtle immediate press feedback. Keep event body, Save and Plan as separate
targets. Dragging must not open cards or activate their controls.

Maintain compact 44px minimum touch targets, short interruptible motion and
reduced-motion support. Preserve return position. Mobile layout, contrast,
gestures, focus and Composer handoff still require validation in a working
prototype; these image edits do not establish those results.

