# Today concept review — 30 September 2026

Scope: refine the selected modern Today concept and compact menu. Keep the logo
for a separate design discussion. These are static visual proposals; this review
does not change the connected application or establish browser-test results.

## What works

The personal day has a clear lead, connected travel information is easy to find,
and photography gives discovery energy without dominating the everyday layout.
Keep the two-row header, visible navigation labels, open composition, orange/cyan
language, restrained lavender and current Composer.

## Findings and corrections

| Finding in menu-v4 | Impact | Correction |
| --- | --- | --- |
| A confirmed appointment has a calendar-plus action. | Invites adding the same commitment again. | Show one detail chevron. Calendar-plus is reserved for adding something that is not scheduled. |
| The saved Language café sits on the agenda timeline. | Saving can be mistaken for adding to the day. | Detach it into a compact Saved for later row; keep its source time and separate Plan action. |
| Travel at 13:24 has its own node below a 14:00 appointment. | A connected timeline appears to run backwards. | Attach travel as context inside the appointment group, without a second agenda node. |
| Add a plan repeats a plus, calendar-plus and text. | Repeated symbols add noise without another action. | One icon and one short label. |
| City help is enclosed by the Paperwork card. | Services and travel appear to belong to paperwork. | End the lavender surface after the process rows; put city shortcuts below on the page canvas. |
| Mobile discovery stacks cards vertically. | Repeats the earlier rejected mobile composition and adds scroll. | One horizontal row, with part of the next card visible. No carousel arrow controls. |
| Composer overlaps event content and controls. | A Save or Plan action can be obscured. | Reserve bottom space after content; keep the existing Composer launcher in a clear corner. |
| Icon colours and action glow vary across scenarios; the owner rejected the old calendar and arrow styling. | Identical controls look like different components and oversized arrows compete with content. | Use Phosphor Regular dates/planning/navigation, small bare detail chevrons, shared department colours and orange action tokens; remove strong glowing shadows. See the exact icon specimen. |
| Sample context is mostly in the outer footer. | A cropped phone image can look like real user or live data. | Put Sample day beside the date in each view; retain Simulated on the delay and illustrative-media context. |
| Mobile delay Alternatives is outlined while desktop is filled. | The main recovery action loses emphasis on the phone. | Use the same compact orange primary action in both views. |

## Interaction rules carried into the revision

- Confirmed commitment → open its details; no add action.
- Saved idea → remains unscheduled until a separate Plan action and review.
- Route body or chevron → Directions. Sparkle → existing Composer with context.
- Preserve an existing Composer draft and conversation; never send automatically.
- Fixed event times remain fixed. Unknown ending times stay unknown.
- Swipe left, double-tap, or an accessible keyboard action reveals Delete beside
  the row. A separate activation removes; Undo remains available.
- Keep the current logo and Composer design. Neither is redesigned by this pass.
- Adaptive priority is stable while a person interacts: relevant disruption,
  exceptional event, ordinary personal overview. Do not jump content around.

## Colour and size specification

These are implementation targets rather than measurements of generated pixels.

- Canvas/white surfaces; primary ink `#20221D`; secondary text `#53656A`.
- Action orange `#D9340A`; cyan selection/travel `#009BBB` with `#E0F5FA` tint.
- Lavender support `#F1EBFC`; yellow is reserved for useful attention.
- Navigation icons: Today `#009BBB`, Events `#8352CF`, Paperwork `#A8760A`,
  Travel `#227DB9`, Services `#666B93`; labels use dark ink.
- Navigation remains Today, Events, Paperwork, Travel, Services in one row.
- Mobile target: 20px icons, 12px regular labels, 48–52px navigation row,
  minimum 44px touch targets. Allow larger text to increase layout height.
- Text and icons must not depend on a bright accent to provide readable contrast.
  Validate component and focus contrast in the working prototype.
- Reserve at least the Composer launcher's height plus its spacing below the
  last actionable content; account for the device safe area. Scrolling can pass
  content behind a floating launcher, but every action must be reachable clear
  of it at the end of the scroll.

Calculated target-palette contrast: white on orange `#D9340A` is 4.72:1;
primary ink on `#F5FAFB` is 15.25:1; secondary text on that canvas is 5.80:1,
and on lavender is 5.25:1. These calculations verify colour pairs only. They do
not measure the generated images or establish a full accessibility pass.

See [icon choices and original vector assets](icon-direction.md).

## Review limits and next verification

Inspect the generated boards for visible consistency, text, action meanings and
desktop/mobile hierarchy. They cannot prove hit areas, responsive overflow,
contrast of rendered text, motion, screen-reader behaviour or tap/scroll safety.
Those need the approved working prototype at 320px and 390×844, desktop around
1440px, larger text and keyboard/reduced-motion settings.

The existing requirements also define an empty agenda, missing paperwork,
unavailable forecast, unknown journey and stale timetable. Do not fill those
states with invented commitments, urgency, legal facts or live values.
