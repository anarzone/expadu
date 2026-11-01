# Event discovery research — 29 September 2026

Status: the owner approved this researched direction for implementation on
29 September. Today and Events now have a prototype refinement ready for visual
review. This records the research that preceded the changes, not a production
implementation sign-off. See the current connected design decisions and page
READMEs for the implementation and verification.

## Method

Inspected real public pages in the browser at desktop and phone widths, including
the content below the opening section. Compared discovery homepages separately
from event search results. These observations describe the inspected versions;
they are not evidence of conversion or usability performance.

## References and useful patterns

| Reference                                                                                                                                                                   | Observed pattern                                                                                                                                                                                               | Application to Expadu                                                                                                                    |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| [Fever, Cologne](https://feverup.com/en/koln)                                                                                                                               | Large photographic city opener; visual collections for highlights, seasonal activities and types of experience; event images followed by concise facts.                                                        | Give discovery a visual lead and organise the programme into useful collections. Preserve Expadu's own shell and colour language.        |
| [Rausgegangen, Cologne](https://rausgegangen.de/en/cologne/)                                                                                                                | A prominent daily pick and supporting items on desktop; broad cards with the next card visible on mobile; a separate city-news/blog section, including seasonal guides.                                        | Strongest structural reference for local happenings: one lead, supporting discoveries, and clearly identified editorial stories.         |
| [Eventbrite](https://www.eventbrite.com/) and [its New York city catalogue](https://www.eventbrite.com/d/ny--new-york/events/)                                              | A wide photographic campaign hero; category entry points; themed collections; landscape event images with title, date and venue below. Mobile collection cards are substantially wider than half the viewport. | Use generous card width and a stable hierarchy. Let the image communicate the experience while text remains readable on a plain surface. |
| [DICE browse](https://dice.fm/browse) and [official discovery guidance](https://dicefm.zendesk.com/hc/en-gb/articles/22365220986897-How-to-find-events-you-ll-love-on-DICE) | Compact date/location/price controls; clear event title, date, venue and price. The inspected mobile web catalogue used rows, while the documented app feed includes Tonight, This week and New shows.         | Borrow clear facts and time-based entry points. The web list is not the visual direction requested for Expadu.                           |
| [Resident Advisor](https://ra.co/)                                                                                                                                          | Large editorial imagery; distinct news, popular-events and feature sections. The inspected homepage used an automatically changing, very tall mobile hero.                                                     | Learn from the distinction between an event and a story. Expadu should keep the visual hierarchy without the tall autoplay experience.   |

These are layout references, not approved image suppliers. No reference-site
photographs, posters or event records were imported into the prototype.

## Why the rejected iteration falls short

- It retained the old small Places slots, so event names and actions were squeezed
  into narrow mobile tiles. Changing the content did not change the hierarchy.
- Date, category, title, time, bookmark, planning and next-slide controls competed
  for the same image area. Dark overlays covered much of the artwork.
- The Events hero promoted the first sorted record automatically. A long all-day
  exhibition became the lead simply because it started earliest. Visual size
  implied editorial importance without a selection reason.
- The permanent desktop calendar occupied a full column while the feature was
  constrained to the remaining gallery width.
- Repeating the same abstract illustration treatment did not convey the variety
  of concerts, films, games, sports and city celebrations.

## Proposed Expadu structure

### Today

Keep the shared header, compact personal overview and Composer. Rework the
discovery composition rather than substituting content in every old card slot.

1. One clear event lead: a generous image, readable title, date/time and a single
   primary action, with a separate bookmark. The lead must have a real selection
   reason and valid occurrence; an earlier start alone is insufficient.
2. A small set of supporting event discoveries with different categories and a
   link to all events. On desktop, one larger lead and two supporting cards can
   share a row. On phones, one wide lead is followed by a swipeable collection
   with a visible next-card edge.
3. Keep personal tasks, journeys and the day plan visually distinct from leisure
   discovery. Existing records must remain easily reachable.

### Events

Open with a featured event or timely city collection using the main content
width. Follow it with a compact time/category discovery bar and useful sections
such as This weekend, Live music, Film, Games, Sport and City celebrations.
Only populate sections supported by real records; do not create empty taxonomy
rows merely to make the page look busy.

Keep one week/month calendar, opened on demand. Detailed area, starting-point and
sort controls remain compact and separate. When the user explicitly searches or
filters, prioritise the matching gallery and chosen date instead of retaining
an unrelated promotional hero. Returning to discovery restores browsing context.

### Card family

- Feature: a distinct lead composition, with substantially more image area than
  supporting cards. Avoid a large coloured box used only to fill space.
- Standard event: image, title, date/time, venue, admission. Put most text below
  the image. A card opens details; its bookmark remains independent. A planning
  shortcut can use the established plus icon without creating another large
  action bar on every card.
- Story/collection: editorial headline and attribution, leading to a guide or
  related events. Do not present a news article as a scheduled event or let it
  create a timed Composer activity.
- Use approved event photography or organiser artwork when available. Preserve
  whole posters when cropping would remove their main content. The current
  original illustrations remain explicitly labelled sample/fallback material.
- Purple/yellow can identify a feature or collection; the surrounding UI retains
  Expadu's pale canvas, dark text, cyan selections and orange actions.

## Review criteria for the next concept

At 393px: readable event titles, one useful event and its action visible early,
44px actions, no two squeezed poster columns as the main discovery experience.
At desktop: a clear lead and supporting hierarchy across the available content
width, with no permanent calendar column consuming discovery space.

Preserve shared event details, exact occurrences, fixed schedules, independent
Save and Plan actions, Composer review, honest unknowns and reduced motion.
Review a separate visual concept before treating the rejected revision as the
new accepted baseline. Do not change the approved shared shell during this work.
