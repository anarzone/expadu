# Requirements for this revised proposal

Latest user correction takes precedence over the original visual-freedom brief.

## Locked anchors

- Keep the header and horizontal department menu above the content on desktop and mobile. The owner approved retouching their appearance in place. No sidebar or replacement bottom menu.
- Keep the existing Composer launcher, input tray, controls and conversation workspace. Contextual content may enter through the existing flow; no new assistant panel.
- Preserve the accepted application while exploring this separate concept.
- This stage is visual design, not application implementation.

## Header retouch

- Preserve the two-row arrangement: brand/utilities above the department menu.
- Keep Today, Events, Paperwork, Travel and Services in that order. The owner requested Bureaucracy → Paperwork and Departures → Travel.
- Use compact desktop icon/label links; mobile keeps one horizontal icon/label row.
- Use a soft cyan selected pill without a second underline. Keep consistent icon geometry.
- Keep city, Save and account utilities compact. Show weather once in Today’s content.
- Separate header and body with spacing and a quiet fading divider.
- In a future prototype, use 44px touch targets and short local press/selection feedback. Reduced motion removes movement.
- Reuse the current Composer unchanged; header revisions do not authorise a Composer redesign.
- Render the final implementation from one shared header component. Generated boards are visual references, not pixel-perfect responsive measurements.
- Selected mobile treatment: 20px icons, regular-weight 12px labels, a 48–52px row and at least 44px touch areas. The menu-v4 boards illustrate this treatment; dimensions still need verification in a working prototype.
- Keep all five mobile labels visible, with no wrapping, truncation or horizontal scrolling in the default layout.

## Composition

- Roughly 70% practical city-assistant information and 30% discovery.
- Connect the next commitment to its journey. Avoid duplicating those facts in unrelated tiles.
- Attach the journey as context for the commitment; do not put an earlier departure time on a later agenda node.
- Keep saved ideas outside the confirmed agenda timeline. Show a compact Saved for later row with a separate Plan action.
- Use a compact greeting, useful information in the initial phone viewport and one clear primary action.
- Use direct process rows with named progress. Tap/click the row to open; no trailing opening icon, paperwork carousel or pagination.
- City utilities remain secondary; weather appears once.
- End the lavender Paperwork surface after its process rows. City tools sit separately on the page canvas.
- Mobile discovery is one horizontal event row with a visible next-card edge, not vertically stacked cards. Desktop may show the cards side by side.
- Reserve bottom clearance for the existing Composer launcher so the last card and its actions can scroll fully clear of it.
- Colour: orange actions, cyan selection/travel, limited yellow attention and purple supporting context, dark text on near-white/pale-ice surfaces.
- Modernity comes from hierarchy, typography, open composition, local feedback and restrained colour grading. No decorative skyline, tram illustration, paper-folder stack, heavy black border or repeated equal widget boxes.

## Adaptive states

- Everyday: personal day → paperwork/city tools → discovery.
- Exceptional event: one concise spotlight → personal day and practical tools. Do not repeat the same event.
- Relevant route disruption: delay, scheduled/expected times and alternatives lead; keep the affected commitment visible.
- Personal urgency takes precedence over entertainment.
- Choose priority on entry/refresh. Do not move content while the user is interacting.

## Actions

- Tap/click the travel summary itself to open the route and stops. Leave and Arrive labels carry direction; omit trailing map/arrow/menu icons and the contextual sparkle from this summary.
- Route details include contextual access through the established Composer symbol. It uses the existing Composer flow; the global launcher stays unchanged. This supersedes the earlier route-strip sparkle.
- Context entry keeps the existing draft/conversation and does not send automatically.
- Save, proposed Plan and confirmed agenda entries remain distinct.
- A planned appointment uses a small neutral horizontal dots menu with Details and Remove. Its body keeps the agreed swipe/double-tap gesture. Do not also navigate on the first body tap or delay ordinary single-tap navigation elsewhere to detect a double-click.
- Paperwork and route rows open directly without trailing controls. Event cards and saved ideas open details when their body is tapped; preserve distinct bookmark Save and calendar-plus Plan controls. Horizontal dragging must not trigger a card action.
- Keep a simple orange plus beside Add a plan as one action. Keep the lower Events link without decorative symbols. On event photos, retain the compact orange circular calendar-plus control.
- On a disruption, keep the existing short Alternatives label without the redundant signpost. Keep this recovery action visible on mobile as well as desktop.
- Keep a leading train/mode identifier beside the travel times and a small document/form symbol beside City registration. These belong to their tappable rows; they are not separate buttons. Use the same placement and meaning across everyday, important-event and delay states.
- Use icons when they identify useful content or expose a separate action. Do not replace unnecessary opening icons with text buttons or dots on every row. The final-v9 rules retain v8's direct opening and restore meaningful identifiers and the plus in Add a plan. Suitable repeated icons consistently represent the same meaning.
- Give every icon control a precise accessible name and a tooltip on focus/hover. Do not rely on a tooltip for touch use: surrounding content must establish what the action applies to. Keep icons in 44px minimum targets without enlarging their visible glyphs.
- Remove decorative section-heading calendars, Saved for later bookmarks and the City help sun; keep meaningful Save controls, the weather sun and the menu's Services icon for the Services shortcut.
- Fixed event start/end times are respected. No fabricated time suggestions.
- Paperwork opens the exact named process and keeps return position.
- Swipe left, double-tap or the appointment menu's Remove choice reveals the same compact red trash action beside a planned item. The item stays recognizable. Revealing does not delete.
- Provide an equivalent keyboard/accessible reveal action. A separate activation removes; Undo is available.
- Short, interruptible 160–200ms motion. Reduced motion removes the slide; important controls never depend on hover.
- No new global search, mandatory onboarding or walkthrough in this proposal.

## Content and missing states

- All concept content is sample. Event photography is illustrative, not documentation of a real event.
- Show Sample day beside the date in each review view, and Simulated on the delay example, so a cropped mobile image retains its context.
- No new legal facts, requirements, fees, deadlines or rules.
- Empty agenda: short 'No plans yet' with + Add a plan; preserve paperwork/city access and discovery rather than fabricate urgency.
- Unknown journey: 'Check route' rather than an invented arrival time.
- Stale timetable: show its timestamp and Refresh; no live badge.
- Missing paperwork: optional 'Get started'; do not claim recorded progress.
- Unavailable forecast: omit numeric values and retain Forecast access.
- Missing event image: a restrained category colour/icon treatment; never substitute an unrelated photograph as factual media.

## Verification required after approval

- Actual 390×844 and 320px layouts, desktop around 1440px.
- Contrast and focus states; text expansion and touch targets.
- Direct row/card opening, focus order, accessible menu/icon names and keyboard reveal/remove/Undo. No drag-triggered navigation or single/double-tap conflicts.
- Composer draft persistence, context handoff and no automatic send.
- Fixed schedule and Save/Plan/Confirmed semantics.
- Disruption priority and stable reading position.
- This visual stage does not establish live data, usability or accessibility test results.
