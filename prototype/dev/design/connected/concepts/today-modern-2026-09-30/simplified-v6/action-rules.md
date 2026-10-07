# Today: simpler actions

**Rejected direction.** The owner clarified that appropriate, distinct icons are
required, not text-button replacements. This file records the abandoned proposal.
Use [action-icons-v7](../action-icons-v7/action-rules.md) for the current direction.

The owner rejected repeated arrows and ambiguous icon-only actions. This revision
applies KISS to the selected modern concept: familiar controls where their meaning
is clear, short action labels where a symbol would need explaining.

This supersedes the action assignments in review-v5. The layout, palette, compact
top menu, photo treatment, adaptive priorities, current Composer and logo stay
within the existing design direction. This is a concept revision, not application
implementation.

## Action map

| Context | Visible control | Intended result |
| --- | --- | --- |
| Confirmed appointment | Details | Open this appointment. |
| Travel summary | View route | Open the route and stops for this commitment. |
| Route disruption | Other routes | Compare alternative journeys for the same destination and arrival goal. |
| Paperwork process | Open | Open the named process, preserving person and return position. |
| Saved idea or event photo | Plan | Review a proposal for the event's actual schedule. |
| Empty or available agenda | Add a plan | Open the planning flow. |
| Event photo | Bookmark | Save or unsave the idea. |
| Route details | Ask Composer | Open existing Composer with this journey's context. |
| Global Composer | Existing launcher | Open the existing shared Composer. |

Plan never silently confirms a booking or overwrites a confirmed agenda entry.
Save remains independent of planning. Keep source identity and fixed event times.

## Remove the ambiguity

- No generic forward chevrons next to appointment and paperwork rows.
- No collection of route arrow, route chevron and contextual sparkle in the same
  summary. Leave and Arrive labels already express the relationship between times.
- No repeated calendar-plus controls. Compact Plan labels replace them on event
  photos and saved ideas; Add a plan is named directly.
- No decorative calendars beside every section heading. Keep meaningful
  navigation icons with their visible names.
- No sun beside City help: the sun belongs to weather. Services uses the same
  four-square symbol as its navigation destination.
- Keep one global Composer launcher. Route-specific help is available inside
  route details through Ask Composer; the Composer component itself is unchanged.
- Keep familiar bookmark Save, date/navigation icons with labels, close/back
  controls and the city dropdown. Reuse an icon only when its meaning stays the
  same. The user did not request removal of all icons.

## Touch, focus and gestures

- Paperwork is one link per process row, with Open as its visible action.
  The label and row lead to the same destination; do not create nested links.
- An appointment's Details control is separate from its gesture surface. Do not
  open details on the first tap of the row body, because that would interfere
  with the approved double-tap Delete reveal.
- Swipe left or double-tap the appointment body reveals a compact red circular
  Delete action beside the item. Revealing does not delete. Separate activation
  removes it, with Undo.
- Provide equivalent keyboard and accessible actions. Focus remains visible,
  using the existing restrained focus treatment.
- Short labels still need minimum 44px touch areas. Never reduce their target
  to the text's glyph bounds.
- Keep immediate subtle press feedback, 160–200ms interruptible transitions and
  reduced-motion support. No action depends on hover.
- Photo body opens event details; Save and Plan have distinct sibling controls
  with meaningful accessible names.

## Composer handoff

1. View route opens the existing route detail flow with the exact destination
   and event/appointment arrival context.
2. Ask Composer inside that detail flow preserves the current conversation and
   any draft, attaching the route context without sending automatically.
3. Back returns to the same Today item and scroll position.
4. The global launcher remains available throughout.

This replaces the earlier Today route-strip sparkle. It does not remove the
Composer connection or authorise a different input tray, conversation design or
planning confirmation model.

## Visual limits

The boards show placement, hierarchy and action wording. They do not prove
behaviour, hit areas, focus order, accessible names, responsive overflow or
contrast. Those require the approved working prototype. All schedules, process
progress, disruption and photos remain illustrative sample content.

The earlier icon assets remain available in review-v5/icons for familiar,
appropriate uses. Do not carry its superseded icon-to-action mapping into an
implementation.
