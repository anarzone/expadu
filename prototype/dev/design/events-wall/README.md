# Events: on the wall (owner's choice, 8 October 2026, in review)

Review at `http://127.0.0.1:8765/dev/design/events-wall/`. It opens on the
preview's sample day, Wednesday 30 September 2026. The approved Events page
(`../events-next/`) is unchanged and stays the baseline until the owner
approves this one.

The owner chose concept A (Poster Press) from the
[Events concepts canvas](https://claude.ai/artifact/9L2kfkt4VaeeRzQsR9BCGw)
and asked for concept B (Rhine tonight) to join it, with posters wherever
there's no picture.

## What it does

- **Every event is a poster set from its own listing.** No photograph and
  nothing added: title (and its English meaning), date, time, place,
  admission and category. The category picks the shape (Culture rings, Games
  checks, Outdoors waves, Language speech bubbles) and the hour picks the ink
  (before 17:00 yellow, until 20:00 purple, later night navy with stars; no
  time is plain paper). Unknowns read "Price not listed" or "Time not listed
  yet". Titles use Bricolage Grotesque as poster lettering
  (`fonts/` links to the self-hosted files in `public/fonts/`).
- **The poster column** (a Litfaßsäule) holds the featured pick, "Coming up",
  which stays put while you filter, as the owner decided for Events. The
  chosen day's other events hang on the wall beside it.
- **Turn a poster over** (tap it) for the back: when and where, the quiet facts
  list (Admission, When to arrive, Language, Who it's for, Accessibility from
  the venue's listing, otherwise "Not listed"), "Fits your day" from the
  person's recorded appointment only, and Add to plan, Save and More about it.
  The turn is a 320ms half-turn, without motion when reduced motion is on;
  Escape turns it back.
- **Next in the city's calendar.** A captured listing names a later date in the
  same city series ("City listing for 6 October 2026 in the same series"), so
  those dates hang as paper posters. Their time and price aren't captured, so
  they say so; they can't be planned and link to the city's listing.
- **The week's notes.** A public holiday in the next seven days (3 October,
  German Unity Day) is pinned as a note, with the person's own plan that day.
- **Already taken down.** The captured 8 September programme, faded, with a
  link to that day. Its posters carry "Past" and no Save or Plan.
- **Skyline view** (Wall / Skyline switch, kept in the URL as `?view=skyline`).
  An illustrated Cologne skyline (the Kranhäuser, Groß St. Martin, the TV
  tower, the Dom and the Hohenzollern bridge) with the day's events as lights
  above their district, and the person's 14:00 appointment marked. Afternoon,
  Evening and Late repaint the sky and dim the other lights. Districts that
  aren't drawn (Chorweiler, Junkersdorf) are named at the edge. Below, the
  events as photo cards; an event with no usable picture gets its poster's art
  instead, labelled "Poster from the listing". The sample film night is one:
  its illustration showed mountains and a lake (flagged 7 October).
- The event sheet's cover follows the view: the poster's art on the wall
  (credited "Poster set by Expadu from the listing"), the picture on the
  skyline.

Filters, the week/month calendar, search and no-match messages, saving,
planning (Add to plan, "It isn't confirmed yet", Review plan in Composer),
Get there and the past-day rules are the Events page's own: this page loads
`../events-next/app.js` and swaps only how events are drawn (`wall.js`
replaces `ExpaduEventFestival`'s feature, render and image).

## Files

- `poster-art.js`: the poster generator (ink, shape, front, back, turn).
- `wall.js`: the column, the wall, the later listings, the past strip, the
  skyline and the view switch.
- `wall.css`: posters, column, wall, skyline and the phone layout.
- `index.html`: the Events page's markup with these three files added.

## Checks

- `node --test prototype/dev/design/events-wall/tests/poster-art.test.cjs`:
  ink and shape rules, unknowns, separate Save and Plan, no actions on past
  or later listings, "Fits your day", escaping.
- `node prototype/dev/design/events-wall/tests/wall-browser.cjs` (server on
  8765, or `BASE=`): at 390×844 the column poster's Add to plan and both filter
  rows are on the first screen with nothing under Composer; no sideways
  scroll at 390 or 1440; turning a poster over and back; three later listings
  without planning; Skyline lights, the poster card for the film night and a
  light opening the sheet; the 8 September posters without Save or Plan.
- Checked by eye at 390, 768, 1024 and 1440. Screenshots:
  `docs/reviews/2026-10-08-events-wall/`.

## Open for the owner

- Poster lettering uses Bricolage Grotesque, which is still undecided for the
  app's own titles; page titles here stay Geist.
- The holiday note's list (`wall.js`, `holidays`) is preview data for
  Germany in 2026, not a calendar service.
