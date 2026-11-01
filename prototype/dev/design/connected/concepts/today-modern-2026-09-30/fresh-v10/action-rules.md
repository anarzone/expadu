# Fresh redraw — interaction rules

This refines v9 following the owner's 1 October 2026 corrections. It is a static
visual proposal. The existing app and Composer have not been replaced.

## Appointment and journey

One confirmed appointment is one agenda item. Its scheduled appointment time,
title, location and confirmation are the anchor; its journey is nested below
those details. A route does not become a second appointment, timeline node or
equal-weight agenda row. This grouping stays the same in normal and delay states.

Use one leading train/mode identifier. Connect departure and arrival with a thin
cyan line, endpoint dots and one directional arrowhead. This conveys travel
direction and is not a separate opening action. The entire route summary opens
the associated route details.

In the simulated delay sample, retain scheduled 13:24 / 13:52 as struck-through
values and expected 13:36 / 14:04. The appointment remains scheduled for 14:00;
its separate impact badge says 4 min late. Keep 12 min delay and Simulated visible.

## Recovery and Composer

Place the recovery row below the cyan journey summary, separated with spacing
and a subtle fading divider, within the same appointment group. Alternatives
uses white fill, orange outline and orange label. Its adjacent contextual
Composer action uses white fill, the same orange outline and the familiar orange
sparkle. These controls do not form another agenda item.

Alternatives keeps the exact appointment, destination, date and original route
context. An alternative is proposed for review, not silently added as another
activity. The contextual Composer entry opens the existing component with that
route context; preserve the current draft and conversation and never send
automatically. The existing global dark-teal launcher remains unchanged.

This contextual recovery entry is the owner's latest addition to the earlier
route-details entry. It does not require redesigning the Composer tray.

## Other retained behaviour

- City help is a plain text heading. The sun identifies weather/Forecast only.
- Keep useful train, document/form and simple Add a plan plus identifiers.
- Paperwork rows open directly. City registration has no invented progress;
  EU Blue Card retains the sample's recorded 1/5.
- Save and calendar-plus Plan are separate event actions. Plan opens review and
  does not imply booked admission or confirmed attendance.
- Saved ideas remain separate from confirmed agenda entries.
- Planned appointments keep the neutral Details / Remove dots menu. Swipe,
  double-tap or menu Remove reveals the narrow red action; a separate activation
  removes, with Undo. Preserve row recognition and dismissible reveal.
- Normal days lead with the personal day. An exceptional event can lead with
  one compact spotlight; no duplicate hero or second discovery grid in that state.
- Keep compact labelled top navigation, the current Composer, subtle immediate
  touch feedback, 44px minimum action targets and reduced-motion support.

The generated icons, geometry and type are visual approximations. Use existing
vector icons and components during implementation. Layout, focus, accessibility,
motion, gestures and Composer continuity still need validation in working UI.
