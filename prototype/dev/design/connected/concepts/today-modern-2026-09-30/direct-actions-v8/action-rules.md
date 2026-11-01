# Direct opening, secondary actions on demand

The owner asked to remove icons whose only job is to open existing content.
Use a direct tap/click for one obvious destination. Use a dots menu when an item
has secondary actions. Keep useful state-changing controls such as Save and Plan.

This revision supersedes the information, folder and map action controls in
action-icons-v7. It does not replace them with text buttons or a dots menu on
every row. The page composition, content and current Composer stay intact.

## Interaction map

| Surface | Primary interaction | Visible controls |
| --- | --- | --- |
| Paperwork process | Tap/click the named row to open that exact process. | No trailing icon; retain recorded progress. |
| Cyan journey summary | Tap/click the strip to open the route and stops. | No map, arrow or dots at the end. |
| Event photo/card | Tap/click the card body for event details. | Keep independent bookmark Save and calendar-plus Plan. |
| Saved idea | Tap/click its title/body for details. | Keep its separate planning control. |
| Planned appointment | Dots menu → Details. | One small neutral horizontal dots control. |
| Planned appointment removal | Dots menu → Remove, or swipe left/double-tap the body. | Reveal the existing red circular delete action; separate activation removes, with Undo. |
| Route disruption | Alternatives opens competing routes. | Keep the existing compact labelled action, without a decorative signpost. |

Paperwork and route rows have one destination, so they do not need a menu.
The appointment menu is justified by its separate Details and Remove actions.
Do not use double-click as the only way to reach essential information.

## Preserve gesture meaning

A planned appointment's body remains the deletion gesture surface. It does not
navigate on the first tap; otherwise a double-tap could navigate before the
second tap arrives. Details is available through its explicit dots menu.

The dots menu contains only existing actions: Details and Remove. Selecting Remove
reveals the same compact red action as a swipe or double-tap; it does not delete
immediately. Escape, outside activation or swipe back dismisses the reveal.
Keep keyboard access, separate confirmation and Undo. Do not add an edit action
without a defined editing flow.

Other content uses one tap/click, with no waiting to detect a double-click.
Horizontal event dragging must not activate a card or Save/Plan when released.

## Quiet controls

- Remove trailing information, map and open-folder icons used only for opening.
- Do not replace those symbols with Open/Details text buttons on every row.
- Dots appear only where secondary actions exist, not on every piece of content.
- Keep dots neutral and small, within an eventual 44px minimum touch target.
- Keep the contextual menu compact and anchored to its item. No page-blocking
  modal, blur or large confirmation banner.
- Remove redundant decorative symbols beside existing Saved for later, Add a
  plan and lower Events labels. Do not add more explanatory copy to the page.
- Preserve header/navigation icons with their labels, city tools, weather,
  meaningful status and photo time/location metadata.
- Preserve the current frosted Save treatment and distinct calendar-plus Plan.
- Preserve the logo, global Composer and contextual Composer in route details.

## Implementation requirements

Use semantic links or buttons for direct-opening surfaces; make the whole row
the hit area without nested interactive controls. Event content, Save and Plan
remain separate sibling targets so saving never also opens the event.

Provide keyboard focus and accessible names. The appointment menu button needs
an item-specific name such as 'Actions for Your appointment' and expanded state.
Focus enters the menu when opened and returns to the trigger when dismissed.
The two menu items remain readable words; no unfamiliar extra symbols are needed.

Give tappable rows subtle immediate press feedback and restrained focus treatment.
Hover may support desktop discoverability but is not required for operation.
Keep motion short, interruptible and reduced-motion aware. Preserve return scroll
position and stable adaptive ordering.

## Scope and assets

These are static visual proposals, not implemented or browser-tested behaviour.
All content, schedules and progress remain sample, and the delay remains labelled
Simulated. No legal content or backend work is introduced.

The new `dots-three` SVG is official Phosphor Regular with its MIT licence and
source hash in `icons/`. Existing shared icons remain in
`../action-icons-v7/icons/`; the removed symbols stay as historical assets and
must not reappear merely to open a row.

The generated images illustrate the treatment. The interaction rules above,
native SVGs and existing Composer component govern implementation.

