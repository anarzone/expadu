# Travel colour and motion study

3 October 2026 · Ten alternatives for review · No production palette selected

Open [the interactive gallery](http://127.0.0.1:8765/dev/design/travel-lab/).

## The problem to solve

The owner rejected the cyan-on-peach Travel preview. Earlier iterations also
created an alternating light header, darker canvas and light cards. Repeated
surface changes made the screen feel fragmented; changing one hex value at a
time did not establish a coherent system.

The next decision should therefore compare **colour roles**, not just colour
names. All ten examples use the same travel content, type, navigation position
and default composition. This makes the colour comparison fair. The expanded
preview also provides two desktop compositions and a phone width.

These are original Expadu proposals. They do not claim to reproduce any brand's
exact tokens or to prove a particular palette is more usable. The current
`departures-next` page is preserved for comparison.

## What was actually researched

Public product screens, official design articles and official release pages
were reviewed. Airbnb's published Trips image, Notion Calendar's product
screens, Wise's public visual direction and Citymapper's published route
illustrations were also inspected in the browser. No logged-in competitor app
was usability-tested. Historical material is identified below.

| Reference                                                                                                                                                                                                             | Evidence and useful principle                                                                                                   | Translation to Expadu                                                                              |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| [Linear, March 2026](https://linear.app/now/behind-the-latest-design-refresh)                                                                                                                                         | Recent refresh: quieter supporting UI, fewer unnecessary icon containers and separators; overly warm neutrals can become muddy. | One continuous canvas. Avoid adding a new fill for every nested group.                             |
| [Airbnb, May 2025 release](https://news.airbnb.com/product-releases/airbnb-2025-summer-release) and [published Trips screen](https://news-assets.withairbnb.com/wp-content/uploads/sites/21/2025/05/Trips-tab-US.jpg) | Light surfaces, charcoal type and limited accent markers in the published itinerary UI.                                         | White & Berry tests a friendly accent without a pink page.                                         |
| [Wise public direction](https://wise.design/direction)                                                                                                                                                                | High-energy green sits with a strong dark partner and white. Visual reference only; private token documentation was not used.   | Lime & Ink uses lime for selection, deep ink for reading and actions.                              |
| [Notion Calendar product](https://www.notion.com/en-gb/product/calendar)                                                                                                                                              | Published screens use colour inside calendar records while much of the interface remains neutral.                               | Chalk & Colour moves the colour into route badges, not the enclosing panels.                       |
| [Citymapper route UI](https://citymapper.com/news/2521/our-global-walk-routing)                                                                                                                                       | Map and instructions stay related while moving through route steps.                                                             | Keep the connected route line and reveal details in context. The forest palette is our adaptation. |
| [Google Maps colour case study](https://design.google/library/exploring-color-google-maps)                                                                                                                            | Historical account of simplifying a complex palette while keeping information legible.                                          | Reduce palette roles instead of introducing another tint for each module.                          |
| [Headspace brand refresh, October 2023](https://organizations.headspace.com/blog/headspace-unveils-refreshed-brand-and-expands-offerings-for-lifelong-mental-health-support)                                          | An expressive, approachable identity is a useful warmth reference.                                                              | Butter & Orange explores a sunny highlight while retaining a white canvas.                         |
| [Duolingo motion announcement, August 2026](https://investors.duolingo.com/news-releases/news-release-details/duolingo-invests-world-class-motion-design-acquisition-animade)                                         | Official emphasis on motion as part of an understandable, enjoyable product.                                                    | Brief completion feedback. No mascots, streak pressure or unrelated animation.                     |
| [Revolut 10, October 2023](https://www.revolut.com/blog/post/revolut-10/)                                                                                                                                             | Historical reference for simplifying the home screen and quick switching. Not a current palette specification.                  | Porcelain & Cobalt explores a crisp, single-accent utility direction.                              |
| [Material 3 Expressive research, 2025](https://design.google/library/expressive-material-design-google-research)                                                                                                      | Colour, shape, size and motion work together to direct attention. Expression still needs familiarity and usability.             | Place expression on a clear action or a changed state, not all surfaces at once.                   |
| [IBM animation guidance](https://www.ibm.com/design/language/animation/overview/)                                                                                                                                     | Distinguishes efficient task motion and more expressive moments.                                                                | Fast tabs and disclosures; slightly more expressive save feedback.                                 |

Uber's current Color 2.0 page was also checked but is private. No claims or
tokens from that page are used in this study. The older Wise colour URL was
unavailable; its public direction page is the reference used instead.

## The ten proposals

Every option uses `#FFFFFF` for the continuous page and header. This holds the
owner's preference for a lighter base constant while testing secondary colours.
The page is never tinted peach, blue or grey from edge to edge.

| #   | Direction          | Quiet grouping | Selection | Accent / route | Primary action | Primary text |
| --- | ------------------ | -------------- | --------- | -------------- | -------------- | ------------ |
| 01  | Paper & Ember      | `#F7F7F5`      | `#FFF0E8` | `#B9380E`      | `#BD3B10`      | `#252523`    |
| 02  | White & Lagoon     | `#F7FAFA`      | `#DEF5F8` | `#00768B`      | `#C23B0E`      | `#202A2D`    |
| 03  | Apricot & Ink      | `#FFF6F0`      | `#FFE6D4` | `#8C3B1B`      | `#2E2521`      | `#2E2521`    |
| 04  | Porcelain & Cobalt | `#F5F7FC`      | `#E9EEFF` | `#294BCC`      | `#294BCC`      | `#202734`    |
| 05  | Lilac & Plum       | `#FAF8FD`      | `#F0E7FB` | `#7545A7`      | `#7545A7`      | `#2D2538`    |
| 06  | White & Berry      | `#FAF7F8`      | `#FBE8EE` | `#B32658`      | `#B32658`      | `#302329`    |
| 07  | Mint & Forest      | `#F5FAF7`      | `#DDF4E7` | `#1B7451`      | `#176B4B`      | `#20382D`    |
| 08  | Butter & Orange    | `#FFFCF2`      | `#FFF0B8` | `#96510A`      | `#B34413`      | `#30281D`    |
| 09  | Lime & Ink         | `#F8FAF3`      | `#DEFAA9` | `#45682B`      | `#243C20`      | `#243322`    |
| 10  | Chalk & Colour     | `#F8F8F8`      | `#EEEEEC` | `#555555`      | `#242424`      | `#242424`    |

Option 10 adds three route-chip pairs: cyan `#DFF3F7` / `#06657A`,
violet `#EFE5FB` / `#77439E`, and amber `#FFF0C4` / `#775414`.
These are visual grouping experiments, not official transport-line colours.

## How to review

1. Open a direction and compare its ordinary state and expanded route.
2. Try Plan a trip / Timetable, Journey details and Save.
3. Play the short motion sequence; stop it or reduce motion at any time.
4. Switch between desktop and phone. On desktop, compare the two compositions.
5. Select two directions in the gallery for an independent, side-by-side phone comparison.

The gallery deliberately holds the sample journey fixed. It does not geolocate,
search a live transport service or alter Saved in the actual app. Composer shows
an inline handoff explanation and sends nothing. Top app navigation is shown
for colour context and is not interactive in this study.

## Motion specification

- Precise options (01, 04, 10): 180 ms selection underline and disclosure.
- Flow options (02, 07): 220 ms selection/disclosure, 320 ms route-line reveal.
- Gentle options (03, 05): 240 ms selection/disclosure.
- Tactile options (06, 08, 09): 220 ms selection/disclosure and 280 ms save response.
- Other save responses: 240 ms, small scale change.
- Result cards enter with 45 ms stagger; no continuous movement.
- All transitions respond immediately to input. Manual interaction cancels the
  scripted demo. Closing a preview, hiding the tab or disabling motion cancels it.
- The system's reduced-motion preference and the gallery's Motion switch disable
  animation without disabling the controls.

These timings are proposed values, not timings extracted from the referenced apps.

## Initial recommendation

**01 Paper & Ember** is the strongest restrained starting point: orange keeps
its purpose, and the surrounding interface does not compete with it.

**02 White & Lagoon** is the strongest continuity option: retain Expadu's cyan
and orange but give them separate jobs on white. This avoids the rejected
cyan-on-peach relationship rather than merely adjusting its saturation.

**06 White & Berry** is the most promising friendlier brand alternative. It is
a larger identity change and should be assessed across Today, Events and
Paperwork before adoption.

These are design judgements, not user-test findings. A selection should be
validated on the existing full Travel page, including empty, loading, error,
disruption and active-journey states, before other pages are changed.

## Verification scope

The source palette check covers normal text on white, secondary text on white
and its grouping surface, accent text on selection, primary button text, and
primary text on selection. All these pairs exceed 4.5:1; the lowest is 4.67:1.
This is not a full accessibility certification. Browser interaction and viewport
checks are recorded in the associated review notes when completed.
