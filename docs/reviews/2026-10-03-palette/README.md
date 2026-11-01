# Connected preview palette

3 October 2026. Colour-only alignment with the approved Today brief.

Events, Paperwork, Travel, Services and Composer opt into
`prototype/dev/design/connected/today-palette.css`. The canvas is `#ece9e4`,
surfaces `#fcfaf6`, quiet controls `#f4f0e9` and header `#f6f3ee`, matching Today.
Cyan, orange, lavender, photographs and frosted photo actions retain their
existing treatments. Today, historical alternatives and the real app are not
changed by this preview pass.

Browser review covered all five pages at 390 px and 1440 px, with no horizontal
page overflow observed. Travel's Timetable retains its selection and row
contrast. Services' selected category and workspace were checked after resolving
their more specific colour rules. Screenshots accompany this record.

Formatting and whitespace checks pass. A stylesheet check confirms that the
new file only changes colours, its shared neutral tokens equal Today's values,
and only the five intended entry pages opt in. Muted text has 5.22:1 contrast on
the gray canvas and 6.07:1 on the ivory surface. This is not a full accessibility
audit or a new functional review.

Refresh previously opened tabs to load their updated stylesheet link.

## Contrast follow-up, 3 October

The first pass aligned large surfaces but left independent foreground colours
and small pure-white controls. Travel consequently mixed blue-gray captions with
Paperwork's neutral text, and its placeholders measured only 3.83:1 against the
ivory input surface.

The same opt-in stylesheet now also defines common cyan, teal, action, focus and
status colour roles. Travel descriptions/placeholders use the shared muted ink;
transport symbols use teal; small orange actions use the darker action ink.
Saved controls, route badges, timetable details and the journey workspace use
ivory surfaces. Paperwork and timetable dividers use the shared warm border.
Information badges share a cyan surface; warning colours match Paperwork.
Event filter rails use the quiet ivory token, and neutral form placeholders
are consistent across the five previews. Photographic overlays, frosted Save
buttons and distinct completed/waiting states remain intentional exceptions.

Browser checks covered Paperwork and Travel at 1440 and 390 CSS pixels, plus
phone checks of Events, Services and Composer. Travel's timetable, departure
details and expanded journey itinerary were exercised. No horizontal page
overflow was observed. `contrast/` contains screenshots and computed shared
colour values. This remains a visual check, not a full accessibility audit.

Measured text pairs:

| Role | Foreground / background | Contrast |
| --- | --- | --- |
| Travel placeholder | `#645f58` / `#f4f0e9` | 5.57:1 |
| Secondary text on canvas | `#645f58` / `#ece9e4` | 5.22:1 |
| Secondary text on ivory | `#645f58` / `#fcfaf6` | 6.07:1 |
| Secondary text on cyan | `#645f58` / `#dff3f8` | 5.52:1 |
| Teal on cyan | `#00758c` / `#dff3f8` | 4.67:1 |
| Orange text on canvas | `#b9300b` / `#ece9e4` | 4.97:1 |
| Filled action | `#ffffff` / `#d9340a` | 4.72:1 |

Only the shared palette and its five entry-page cache versions changed. No
page structure, font sizes, interactions or application code changed.
