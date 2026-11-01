# Today action icons

**Opening controls superseded:** the latest decision uses direct taps/clicks for
content and dots menus only for secondary actions. Follow
[direct-actions-v8](../direct-actions-v8/action-rules.md). This file records the
earlier icon-heavy proposal; its information/map/folder opening controls are no
longer the current direction. Meaningful Save and Plan icons remain.

Current correction: use suitable, distinct symbols. The owner explicitly rejected
replacing icons with text buttons. This supersedes the abandoned simplified-v6
proposal. Keep the accepted composition, content and compact labelled menu.

## Action map

| Context | Visible symbol | Result / accessible name |
| --- | --- | --- |
| Confirmed appointment | `info` — circled i | View details for this appointment. |
| Journey summary | `map-trifold` — folded map | View route to this destination. |
| Route disruption | `signpost` with the existing Alternatives label | Compare alternative routes for the same destination and arrival goal. |
| Paperwork process | `folder-open` | Open this named process and its recorded documents. |
| Saved idea or event | `calendar-plus` | Review a proposed plan for this event. |
| Event photo | `bookmark-simple` | Save or unsave this event. |
| Add a plan | `plus` beside the existing label | Open the planning flow. |
| Services shortcut | `squares-four` | Same destination and symbol as Services in navigation. |
| Composer | Existing outlined sparkle | Existing Composer, unchanged. |

Reuse a symbol only for the same action. Two calendar-plus controls on two events
are consistent; using the same forward arrow for appointment information,
paperwork, route details and a carousel is ambiguous.

Keep labelled navigation, ordinary back/close/disclosure controls and status
indicators. Remove decorative heading calendars, the sun at City help and arrows
between already-labelled Leave/Arrive times. Keep the actual forecast sun.

## Visual treatment

- One family: official Phosphor Regular, 20–24px glyphs in the eventual app.
- Orange actions, cyan travel context, neutral Save. Preserve the existing
  translucent blurred Save control on photography.
- No giant circles, extra labels replacing action symbols, heavy focus rings or
  decorative glow. Event planning retains its compact orange circle.
- Do not add symbols to every heading. Content and an action need not both carry
  the same icon.
- Keep all five top menu labels: Today, Events, Paperwork, Travel, Services.
- Keep the logo and the established Composer component.

## Interaction constraints for implementation

Icon meaning must be clear from its surrounding item, not only from a tooltip.
Give each control a specific accessible name containing its target where helpful;
show a small tooltip on keyboard focus or hover. Keep targets at least 44px without
making the glyphs oversized. These are implementation requirements, not measured
results from the raster concepts.

Appointment information is a separate control from the body gesture surface.
Swipe left, double-tap or the keyboard equivalent reveals a narrow red circular
Delete action beside the recognisable item; revealing never deletes. A separate
activation removes it, with Undo. Do not open appointment details on the first
body tap and thereby block double-tap.

A paperwork row opens its exact process and restores position on return. Use one
link for the row, not a nested icon button and duplicate link.

The photo body opens event details. Bookmark and calendar-plus are independent
sibling controls. Saved, proposed and confirmed remain distinct. Respect fixed
event times; planning does not book entry or silently change the agenda.

The route map opens the route and stops for the current commitment. Contextual
Composer access lives in route details; Today retains one global launcher. Keep
the existing draft and conversation and do not send automatically. The map must
not imply starting live navigation.

Keep immediate local press feedback, 160–200ms interruptible motion and
reduced-motion support. Preserve the mobile Alternatives action.

## Assets and limits

[Exact symbol reference](icon-reference.png) · [Editable SVG](icon-reference.svg)

The original assets in `icons/` include the MIT licence, source URLs and SHA-256
hashes. Existing core icons are indexed by `sources.json`; the four added symbols
by `additional-sources.json`. Sources: [Phosphor](https://phosphoricons.com/) and its
[official repository](https://github.com/phosphor-icons/core).

The three page boards and interaction sheet are static visual concepts edited
with the built-in image tool. Their glyphs may approximate the exact SVGs. They
do not validate focus, touch targets, behaviour, contrast or responsive layout.
All sample dates, progress, disruptions and photographs remain illustrative.
