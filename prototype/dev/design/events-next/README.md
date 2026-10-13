# Events design preview

Review at `http://127.0.0.1:8765/dev/design/events-next/`. It opens on the
preview's sample day, Wednesday 30 September 2026; the captured programme is at
`?date=2026-09-08` and is shown as past. Other dates can legitimately have no
sample events; this is not a live catalogue.

## No caption on the column — 13 October 2026

Owner request. The "Tonight · 1 of 2" caption on the column's base is
gone; the base's lamps still show which poster faces you (and say so to
screen readers). The round drum stays on phones too.

## Faster again — 13 October 2026

Owner request (the page felt slow). Measured against this morning's
version (desktop, and a phone slowed four times):

- The rain is one shape with a stroke per drop instead of 2,100 lines;
  those lines made each of the first seconds' frames take 265-430ms on a
  desktop. The street now shows in 0.35s and the page is never blocked
  (it was 0.55s and 0.4s this morning, 1.8s and 2.7s before this fix).
- What the hour hides (stars by day, the lit windows, the plane, a lamp's
  flame) leaves the drawing, so its 75 animations no longer cost frames.
- The big moving groups wait, still, until each is one picture.
- The wide street's cobbles are one stretch of setts repeated, and it is
  drawn up to 2240 across (2560 before).
- A phone's drum draws only the three posters you can see, in three
  slices each, and every drum turns before its posters are redrawn.

## The whole day on one wall — 13 October 2026

Owner request: no arrows. On a desktop the wall shows the whole day at
once: the day's hours share out its width, spread out on a quiet day;
on a day with more hours than fit, their posters overlap like posters
pasted over each other, and the one you point at comes to the front.
The wall's posters no longer drag (there is nowhere to go), "Back to
now" is gone, and an hour with more posters than its two spots shows
"+N more", which opens that hour's stories. Phones keep paging up and
down.

## A round drum on the column — 13 October 2026

Owner request ("a proper 3D shape, not flat"). The column's drum is a
real cylinder: five posters round it, each bent round the curve in four
slices, with the ones beside the front poster turning away at the sides.
Rolling turns the drum (by hand, the dots or the timer); a drag turns it
straight away and settles on the next or previous poster. Every poster
you can see takes taps and opens its own details. On a phone slowed four
times it runs as smoothly as the flat column did.

## The column rolls under your finger, without dark edges — 13 October 2026

Owner request. A drag that starts anywhere on the advertising column (its
posters, the old ones below, the lamps, the dome) now rolls the column (it follows the finger, then settles on the next or previous
poster; a short drag springs back) instead of moving the wall's posters,
and the timed roll waits its full six seconds after it. Only the wall's poster
area moves the wall's posters: a drag on bare wall beside the column, the
sky or the pavement does nothing, and past either end of the posters the
wall gives only a little, as an iOS list does. The column's
posters wrap round the drum edge to edge, so the neighbours show at its
sides under a light curve instead of dark green bands; only the poster
facing you takes taps.

## The street fills the screen — 13 October 2026

Owner request (a 14-inch MacBook showed empty space beside the street).
On a desktop the street is as tall as the screen allows and now also as
wide as the page: on a wide, short screen it is wider, with more wall,
sky and pavement and room for more hours of posters at once. It is drawn
up to 2560 across and shown from the board's 1361 up; the drainpipe, the
stencil, the arrows and the poster area keep to its right edge. With the
street showing, the page ends 20px under it, so a desktop screen holds it
all without scrolling. Phones are unchanged.

## About first, then when and where — 13 October 2026

Owner request. The details read in order: About (what it is), then the
times (When, When to arrive), then Where, then the rest (how it fits
your day, what isn't listed, the reminder, the listing). Where is three
lines like When: the label, the place with its area, and where you'd
set off and how far away it is ("From Neumarkt · 2.0 km away"); why
you'd start there is on the Get there page.

## Buttons that say what they do — 13 October 2026

Owner request, after a review of every button's words.

- **Add to plan adds**: an event with a listed start and end goes into
  your plan in one tap, with Undo above the bar; the button then reads
  "In your plan" and opens what's planned (Remove, Review in Composer).
  An event whose finish or visit time you choose says "Choose a time"
  and opens the planning page.
- **Where**: the label is just "Where"; its blue line says where you'd
  set off and how far away it is ("Get there from Neumarkt · 2.0 km
  away", or "from Mülheim, after your appointment"). The arrival time is
  the When row's, so it's no longer repeated.
- **Get there stays in the app**: a map of the venue (and where you'd set
  off) on the page instead of a link to OpenStreetMap; "Routes in Travel"
  and "Open in Travel", as the nav names it; the ways to travel labelled
  (Transit, Walk, Bike).
- **The planning page says "plan"** throughout: "Your plan · nothing
  planned yet", the event "Not added yet", no second line repeating the
  listed times.
- **No Composer teal**: the start dot is the Where blue, the date square
  the action colour, the small tags warm; focus rings are blue and show
  for the keyboard only.

## No big gaps in the details — 13 October 2026

Owner request, with Apple's spacing as the reference: one rhythm of 16
under the poster, 12 between blocks and 20 above the bottom bar. Rows are
44 tall at least, with smaller icon tiles. On a desktop, Get there and Add
to plan are as tall as what's on them instead of leaving the rest of the
screen empty.

## No tabs: Get there and Add to plan open inside, with Back — 13 October 2026

Owner request. The About, Plan and Get there tabs are gone; the page is
the details.

- **Where is the way there** (owner: they were the same button): the row
  is tinted blue and adds how you'd set off ("From …", arrive by the
  start); it opens the Get there page inside the details, with Back and
  its title in a bar at the top.
- **Add to plan** in the bottom bar opens the planning page the same way.
- On those pages the poster header steps aside, Close stays at the top
  right, and the map link in Get there's bar is named "Map".

## The details page in one view: one scroll, a full bottom bar — 13 October 2026

Owner request, from a preview. The first view showed the poster header,
then repeated its venue and times in the rows below, with room for one
and a half rows in a small scroll box, and a bottom bar with a lone Save
icon far from Add to plan.

- **A slimmer poster header** (about 200px): its art, category, date tape,
  title and the big time. Where and until when are in the rows.
- **One scroll for the whole page**: the poster scrolls away, the tabs
  stick at the top (Close moves beside them), and on a desktop the page is
  as tall as the screen allows. First view on a 14-inch MacBook and on a
  phone: For you, Where, When, When to arrive and Not listed.
- **The bottom bar is filled**: Save (shown as Save or Saved) and the wider
  Add to plan, labelled buttons side by side, above a hairline.

## One step: a poster opens its details — 13 October 2026

Owner request; the header chosen from two previews.

- **A poster opens its details page directly**, on the wall, the column
  or in a story: no lifted card and no turning over (the lifted card of
  12 October is gone). A story waits underneath while the page is open.
  The posters' label reads "Open details".
- **The page starts with the poster itself**: its art full width, the
  category, the date tape, the title in the poster's type, the venue (and
  its distance when a starting point is set), the big start time and when
  it ends, with Close floating on it. Light posters (day, paper) keep dark
  type on a light shade. The title is read out from the dialog's own
  heading.

`wall.js` opens the page and builds the header; the street generator's
converter makes a story wait while the page is open.

## A readable About tab — 13 October 2026

Owner request, from a preview. The event sheet's About tab was the same
grey (13px) almost everywhere, its cards barely lighter than the sheet,
and nothing said what mattered most.

- **For you** leads: how the event fits your day, dark on a warm
  highlight.
- **Labelled rows** split by hairlines, each with its icon: Where (the
  venue, its area and distance; it opens Get there), When (the date and
  listed times), what the listing says (When to arrive, and any other
  known fact) and **Not listed** (quieter, with "Check with the organiser
  before you go").
- The description under its own **About** label, in dark 16px text; the
  reminder as a row, not a floating card; Listing details last.

`wall.js` builds the rows on the Events page (`aboutRows`); other pages'
sheets are unchanged.

## A smoother, simpler story — 13 October 2026

Owner request.

- **The progress bar glides**: the current bar runs to its end with one
  CSS transition instead of being redrawn every 120ms, so it no longer
  steps, and the street isn't redrawn while a story plays. It holds while
  the story is pressed or its poster is turned over.
- **Nothing under the poster**: the caption and its Save and More about it
  went; the poster's own back has Save, Add to plan and More about it,
  and More about it opens the sheet over the story, which waits.
- **No turn button in a story**: the poster turns over with a tap on it
  and back with a tap on its back (or Escape).
- **A pause button** beside Close holds the story (its bar and the next
  poster) until Play; a new story starts playing.

The viewer's template and logic are in `street-generator/gen_apps.py`
(regenerated); the tap on the back is in `wall.js`.

## Stories in the sky; the street fits the screen — 13 October 2026

Owner request, chosen from two previews. On a desktop or tablet the space
between the filters and the street looked empty (two story rings in their
own row, and a note starting with a stray "·"), and on a 14-inch MacBook
the street ran below the screen.

- **The hour rings float in the street's sky**, a little smaller, with
  their hour on a light label; no row stands between the filters and the
  street. Phones keep the rings above the street.
- **The whole street fits the screen's height** (never below 0.45 of the
  board's size), centred; on a 14-inch MacBook it shows at about 0.68.
- **The distance note** ("Distances from Neumarkt, in a straight line",
  only when a starting point is set) loses its leading dot and sits as a
  slim line above the street's right edge.

`tests/wall-browser.cjs` checks the street fits the screen and the rings
sit in its sky.

## The lifted card closes with an X — 13 October 2026

Owner request: a lifted poster isn't turned over in its place, so its way
back is Close, an X like the sheet's, instead of the turn icon, and it no
longer turns over on the way: it grows from its place into the card while
its front fades into the back, and closing shrinks it back into its place
as the front returns. Posters turned over in place (in a story) keep the
turn icon and the turn.

## No old layout on a reload — 13 October 2026

Owner report: a reload showed the older layout (the big column, "Good things
to turn up for.", "Find your kind of day") for a split second. app.js draws
it first and wall.js rebuilds it as the street once every script has
loaded; when the browser painted in between, that first drawing showed. The
page below the header now stays blank until wall.js has built it (opacity,
since the old column sets its posters visible), and shows anyway after 2s if
a script never loads.

## Phones and tablets: taps and the calendar — 13 October 2026

Checked at 320, 360, 375, 390, 414 and 430px wide, turned too, and at
768px: the page never scrolls sideways, and the lifted card and the
full-screen sheet fit every phone.

- **The date's calendar** opened from the date's right edge and ran
  130–170px off a phone's left edge, hiding Monday to Wednesday and
  Week/Month. It opens from its left on every screen, as on a desktop.
- **Small buttons on the street.** The street is scaled to the screen (the
  column's posters twice), so a poster's Save and Plan were 12–37px on a
  phone and Earlier/Later 24px on a tablet. Each now has an invisible
  area of 44px on screen round it, kept on its poster and no wider than
  the gap to its neighbour; the page and column dots are as tall or wide
  as their spacing allows. The lifted card's buttons keep 44px when the
  card is zoomed below 1.

## The street no longer stutters — 13 October 2026

Owner report: the street's animations lagged. Three of its moving groups
hold many shapes (the rain's 1,275 lines, the plane tree's 1,500 leaves,
the clouds), and Chrome re-sorted the street's layers for them on every
frame the page drew, about 100ms a frame on a desktop. Whenever something
else moved (walking the day, a hover, a story, a lifted poster, scrolling)
the page ran at about 10 frames a second.

- `street.js` draws each such group as one picture of exactly the same
  shapes, with the styles they had on the page, once the street is drawn.
  The group and its motion stay. With the motion stopped, the street
  matches the committed one pixel for pixel apart from edge smoothing.
- The old column's once-a-session turn no longer runs while it is hidden
  (the street has its own column).

Measured in Chromium with the graphics card on: on a desktop, walking,
hovering, a story, lifting a poster and scrolling went from 10 to 120
frames a second; on a phone with its processor slowed four times, from
35–39 to 89–114.

## The street: the Stories board, as it is — 12 October 2026

Owner decision. The Events page is now the "2 · Stories" board from the
"Events reimagined" canvas, built as it is on desktop and phone:

- **The living street.** A drawn Cologne street: the hour's sky with
  drifting clouds, birds and a plane, the skyline (Kranhäuser, Colonius,
  Groß St. Martin, the cathedral), a plane-tree branch dropping leaves, a
  plastered wall with repairs, bricks and its stencil, the gas lamp, bench,
  bike and pigeons, and rain and puddles when the sample forecast says so.
  The sky follows the hour you look at; a past day says the weather wasn't
  recorded.
- **The column** rolls the day's posters every six seconds (its lamps pick
  one; it waits while a poster is lifted, a story or the sheet is open).
- **The day's posters by the hour.** A desktop walks the day an hour at a
  time (arrows or drag, Back to now); a phone pages the posters up and down
  beside the column.
- **Stories.** Each hour with events is a ring above the street; it plays
  its posters in turn (tap the sides or swipe, hold to pause), with Save
  and More about it.
- The posters, their lifted card, the details sheet, saving, planning and
  the filters are the page's own, as before. The header and filters stay
  the page's; Composer's button and the bottom bar too.

`street-art.js` and `street-art.css` are generated from the board's own
generators (its templates, drawings and logic), kept in `street-generator/`
(see its README; `python3 port_street.py ..` regenerates them); `street.js`
runs them here, filling the poster slots with the page's posters. The old corner and
column remain only for the Skyline view (`?view=skyline`). `?at=` still
moves the posters' On now and Over flags; the street's sky keeps the
board's hour. Tablets (701–999px) show the desktop street scaled down.
`tests/wall-browser.cjs` covers the street, its stories, column, walk and
paging, the past day, lifting, the sheet and the Skyline.

## A turned poster lifts into a large card; the sheet fills a phone — 12 October 2026

Owner request, tried first on the "Events reimagined" canvas. A poster on
the wall or the column was too small for its back: on a phone More about
it ran 62px past the poster and the page scrolled sideways.

- **Turning a poster lifts it.** It flies from its place to the middle of
  the screen while it turns (the poster's own turn), over a dimmed street,
  at 300 x 470 zoomed to the screen (380px wide on a desktop, the screen
  less 50px on a phone). At that size every back fits without scrolling,
  with all its buttons. Its place on the wall stays empty until it goes
  back the same way: its turn button, a tap outside or Escape. Focus stays
  on the card. More about it opens the sheet over it; the card waits.
- **On a phone the event sheet is the whole screen** on the Events page,
  clear of the status bar and the home indicator. It rises from the
  bottom and goes back down, like Today's sheets, and a swipe down on its
  header closes it. Desktop keeps the centred sheet; Today's pages keep
  theirs.

`tests/wall-browser.cjs` checks the lifted back fits with every button, the
page doesn't widen, Escape puts the poster back, and on a phone that the
sheet fills the screen and a swipe down closes it.

## A rounded card on phones, and fixes on narrower desktops — 9 October 2026

Owner request. The wall is a rounded card on every screen: on a phone 12px
from the screen's edges (22px corners), on a tablet inside the page margin
(28px). On a phone the lantern has a 14px strip of wall left of the column
and the dome overhangs it by 12px, so the posters keep about 135px at
390px; phone posters are 1.9 times as tall as wide.

From the owner's screenshot of a narrower desktop (about 1350px):
- the date's calendar opens from the chip's left, on screen (it was still
  anchored to the right, from when the date sat at the row's end);
- the date and search keep at least 440px, and between 1000 and 1399px the
  categories drop their icons, so "Search events" has room;
- the bench stands inside the wall (it was 16px outside);
- a flag (On now, Rain likely) takes its own line under the category with
  the poster's full width, and a poster's top row no longer shrinks, so a
  flag never spills out of its pill or under the date tape.

`tests/wall-browser.cjs` checks the card's edges and corners on a phone and,
at 1347px, the calendar, the search's width, the bench and the flag.

## The whole wall on phones and tablets — 9 October 2026

Owner follow-up: a phone shows the whole desktop street, not only the
column and posters. The wall's decor (bricks, torn older posters with tape,
stickers, cracks, paste marks, streaks, the drainpipe, house number 17, the
stone base and "Plakatieren verboten") keeps its percentage places at half
size (0.7 on a tablet); the house number hangs lower by the pipe. The
lantern stands at the wall's edge as tall as the column (0.5), the bench in
front of it and the bike leaning on the plinth (0.44), with pigeons, on a
strip of cobbles from the wall's edge to past the bike. Today's sky is
120px. The posters keep their width. `tests/wall-browser.cjs` checks the
street's pieces are on a phone.

## Phones and tablets get the desktop's street corner — 9 October 2026

Owner request: on a phone the Wall reads like a smaller desktop. The filters
lead the page; under them one wall runs to the screen's edges with today's
sky along its top (today's posters only, as before), the poster column
standing at its left in front of it and the day's posters pasted beside it,
then two across below it. On a phone the column and the posters share one
compact width (`--corner-w`, from the wall's own width in container units,
124–190px), and the posters are set for it: smaller category and flags (a
flag takes its own line), 11px place and price lines across the full width
with only the price clear of a 36px Add to plan, and 30px save, both with a
44px touch area. A past poster drops its translation on a phone. The
caption keeps to "1 of 4 · swipe to turn" (the column's region is named
"Coming up"); the tilt button sits under it. Tablets use the same
composition with a 260px column and full-size posters. Only a desktop fits
the corner to the screen's height. `tests/wall-browser.cjs` checks the
filters lead, the column is about 40% of the screen with a poster beside it,
the wall reaches the edges and no poster's text is cut off.

## Pickers are bottom sheets on phones and tablets — 9 October 2026

Owner request. Below 1000px the filter row sits low on the first screen, so
Category, Area, Starting point and Sort opened mostly below the fold. Each
is now a bottom sheet (full width on a phone, 560px centred on a tablet),
like Today's sheets and the Event sheet: it slides up over a light scrim
with no blur, and closes with the scrim, the close button, Escape, Done or a
drag down on its top edge (handle). The page's tap can't fall through the
scrim. The address field isn't focused on opening, so the keyboard doesn't
cover the sheet. Reduced motion skips the slide. Desktop keeps the anchored
panels. `tests/wall-browser.cjs` checks each sheet sits on the screen's
bottom edge and closes by the scrim and by dragging.

## Filter panels match the shared pickers — 9 October 2026

Owner correction: on a desktop the Area and Starting point panels took the
width of the small control group (about 200px), so names and the field's
hint wrapped. They now open at the shared picker width (320px) from the
row's right edge, styled like Places' pickers: hairline border, 16px corners,
13px heading and rows, a 16px address field, the selected row cyan with a
teal check, the chosen travel mode cyan with a teal underline. An open
control is only pressed (neutral) on every screen; cyan means a value is
chosen.

## No events count row — 9 October 2026

Owner request: the "2 events · Sample · 1 on the poster column" line above
the wall is gone, so the wall starts right under the filters. The count is
still announced to screen readers; the column's caption already says what it
holds, the posters' credit and the weather chip say the programme is sample
data, and an empty day has its own message. The line comes back only when a
starting point is set, to say distances are in a straight line. Reset
filters (desktop) now ends the filter row, and the phone tilt button sits on
the pavement's left, level with the column's caption.

## The street corner fits the screen — 9 October 2026

Owner request: the artwork works at every desktop size. On a desktop the
corner (column, lantern, bench, bike, cobbles), the posters on the wall and
the sky above scale together to the screen's height (`fitWall` in `wall.js`
sets `--wall-fit`; CSS `zoom` in `wall.css`), so the whole picture, from the
wall's top edge to the caption under the column, fits the first screen
under the date and filters, without scrolling (owner follow-up, the same
day): 0.59 at 1280 × 720, 0.64 at 1366 × 768 and 1512 × 765, 0.76 at
1497 × 863, 0.80 at 1440 × 900, 0.90 at 1512 × 982, full size at 1920 ×
1080. It never grows past its drawn size or shrinks below 0.55; the column's
caption keeps its text size.
The column still lengthens to meet the posters beside it (measured with
their scale) and turns by drag at any scale. Phones are unchanged.

No page title on screen (owner decision, the same day): the date chip leads
the search row; on a phone Sort joins the starting point's row and tilting
sits beside the events count.

## The Wall only — 9 October 2026

Owner reminder: the Events page is the Wall, and the Skyline stays only in
this preview, as a reference. The page no longer offers the Wall / Skyline
switch. The Skyline opens only at `?view=skyline`, and a view remembered
from an earlier visit is ignored.

The Wall keeps what the owner chose to borrow from the Skyline on
8 October: the hour's sky over the city, the weather chip, rain warnings
and today's states. The App in colour concept's time switch now previews
the Wall's sky and street.

The browser test checks that the page has no view switch, even with a
remembered Skyline. It reaches the Skyline by its address. Its phone
first-screen check was dropped, because the Skyline isn't the Events page.

## Filters to the edge, on a phone — 9 October 2026

Owner request: the search could be bigger, and the right side of the
filters looked empty. Both rows run to the edge again, and Composer's button
still covers nothing that matters:

- Sort leads the search row, and the starting point leads Category and
  Area. These icon buttons sit in a column on the left.
- The search field and Area stretch to the right edge. Their text, the
  search's clear button and Area's arrow stop 52px short of it, so the
  button only ever floats over an empty end. On a 390px phone the search
  field is 52px wider.
- The Sort and starting-point menus open under their buttons, on the left.

The browser test now checks what each control shows (its icon, label or
typed text), not its box. It also checks that the search and Area reach
the edge. Composer's button covers none of them at any of the 15 phone
sizes.

## A slim column, and filters clear of Composer, on a phone — 9 October 2026

The owner chose both sketches. (The filter rows were refined the same day;
see above.)

- **Filters.** Composer's button floats over the right edge of the screen.
  On mid-height phones it used to cover Saved or Sort on the first screen:
  6 of 15 common sizes, between about 690 and 830px tall (412×732 in
  Chrome, 390×753 with Safari's bars hidden, 390×797 as a home-screen
  app). On a phone:
  - Sort sits in the search row.
  - Category, Area and the starting point make the second row.
  - Free and Saved are in the Category menu under "Show only". A dot on
    Category shows when one is on.

  The controls are moved, not copied, so the order for keyboards and screen
  readers matches what you see. Tablets and desktop keep Free and Saved in
  the search row. The button now covers no filter at any of the 15 sizes.
- **Column.** On a phone the column stands slim, as on a tablet: about 60%
  of the screen (216–260px), with its finial, dome, gold arches and stone
  plinth, and the wall showing on both sides. A flagged poster ("On now",
  "Over", "Rain likely") sets its title a little smaller, so the flag keeps
  its own line above the tape.
- The first screen at 390×844 still shows the poster's Add to plan, Search
  and Sort. The browser test checks:
  - the column's width;
  - Sort in the search row, and Free in the Category menu with its dot;
  - the filters moving back on a tablet;
  - the filters staying clear of Composer's button at 360×720 and 390×753;
  - the flag at dusk.

## The street on a phone — 9 October 2026

The owner chose from three sketches: A put the sky above the column, B a
wall for the day's posters, and C was B plus the column on plaster. The
choice was C, with the column as it already looks on phones.

- On phones and tablets (up to 999px) the column stands on the wall's
  plaster at the top of the page, with cobbles at its foot. Below the
  filters, the day's posters hang on a strip of the same wall, also with
  cobbles at its foot.
- A slim band of today's sky over the city runs above today's posters,
  with its weather chip ("16° Showers · 19:30 · Sample weather"). Another
  day's posters hang without it, because that weather isn't theirs. A day
  with no posters shows no wall.
- The plaster and the row of posters run to the screen's edges. The
  plaster darkens with the street at dusk and at night; the posters don't.
- The column's caption and the credit sit on the page under the plaster.
  On the plaster they dropped to about 2.6:1 contrast at dusk and dawn. On
  the page they read at 5.3:1 at every hour.
- On tablets the column's caption had been hidden behind its stone plinth.
  It now shows under the cobbles.
- On tablets the wall starts above the column's finial; the finial had been
  poking into the heading beside the Skyline toggle. The two filter rows
  also stop 40px short of the right edge, where Composer's button floats.
  Before, the button covered Saved or Sort on tablets between about 970
  and 1120px tall (768×1024, 810×1080, 744×1133). Phones keep full-width
  filters, since their first screen already keeps the button clear of
  them.
- The phone's first screen is unchanged. The column's poster and its Add
  to plan, search and sort are all visible. Nothing sits under Composer,
  and there is no sideways scroll. The browser test checks:
  - the plaster reaching the screen's edges;
  - today's sky only over today's posters, with its chip;
  - the captions off the plaster;
  - the plaster darkening at dusk.

## The phone Wall, checked — 8 October 2026

- At dusk and night the column's caption and the credit stayed light for
  the dark wall, which phones don't have; they now stay dark on the cream
  page (the light text is for the desktop wall only).
- The line of cobbles lies right under the column, with the caption
  below it; rain falls more lightly across the poster.
- Today's flags ("On now", "Over", "Rain likely") sit in the poster's top
  row beside its category, and a flagged poster sets its title a little
  smaller, so its own time and place are never cut off. The column's
  poster on a phone and long titles on the narrowest (272px) posters are
  a touch smaller too; the browser test checks no poster cuts off its text.

## "More about it": the poster opened up — 8 October 2026

Owner review of the details sheet; all six suggestions chosen. On the
Events page the shared sheet (`event-sheet.js`, also used by Today) is
dressed by `wall.js` when it opens; Today's sheet is unchanged.

1. **The Wall's colours.** Cream paper and dark text instead of the old
   lavender and violet; cyan only for the selected tab and selections,
   orange only for Add to plan.
2. **The poster on the cover.** The poster's art with its yellow date tape
   ("Tonight · 30 Sept") and the start time big in the poster's outlined
   numerals, "until 22:00" small beneath. (A bug where the art covered
   the date and time is fixed; the test checks the time is on top.)
3. **The same words.** The sheet's main button says Add to plan, as the
   posters do (it said "Plan to go").
4. **Today first.** "On now · until 21:00" or "Over", "Rain likely at
   20:00 · sample forecast" for an outdoor event, and how it fits your day
   ("It starts 5 hours 30 min after your Blue Card appointment ends").
5. **Unknowns on one line.** What the listing says stays as rows; what it
   doesn't goes on one line: "Not listed: admission, language, who it's
   for and accessibility. Check with the organiser before you go."
6. **Nothing to plan when it's over.** A past event, or one that's over
   today, has no Plan tab, no Add to plan and no reminder.

## A real wall, and no bare wall under the corner — 8 October 2026

Owner review: an empty patch of wall was left under the street corner, and
the wall should look more like a real one.

- **No bare wall.** The corner now ends where the row of posters beside it
  ends: the column grows taller to meet it, and its lower part carries a
  row of older posters (faded, torn at a corner, a title bar and small
  print), as a real Litfaßsäule is covered top to bottom. On desktops
  from 1000 to 1399px the corner and the wall's posters are a little
  smaller, so two posters fit beside it.
- **A real wall.** Large damp patches and grime towards the foot on top of
  the plaster's grain; water streaks run down from the ledge; the plaster
  has fallen away to the bricks in two places; a few hairline cracks; the
  torn older posters show white paper at the tears and a fragment of their
  big type, held by yellowed tape and a staple; a round and a strip
  sticker; a drainpipe with brackets down the right; a blue enamel house
  number; a darker stone base along the foot carrying the stencil. All of
  it dims with the street at night.
- **The back of a narrow poster** (the wall's are 272–300px wide) keeps
  one row: Add to plan and Save as icons, as on the poster's front, and
  More about it in words (owner's call: no text in narrow spaces). The
  icons keep their names for screen readers and as tooltips. The head
  keeps its date and the way back without "Back of the poster". Wider
  posters (the column's) keep their labels. Laid out by the poster's own
  width, so it holds in any window.

## The Wall is the Events page; it borrows the Skyline's best — 8 October 2026

Owner decision: **the Wall is the Events page.** The Skyline view stays in
this preview as a reference, not for the app. The Wall takes what looks and
works best from it:

- **The hour's sky over the city, as the backdrop.** A band across the top
  of the wall shows the same sky as the Skyline (time, weather, sun and
  moon, clouds, rain, stars) behind the Dom, Colonius, Groß St. Martin and
  the Kranhäuser, above the wall's stone ledge. The column's dome and the
  lantern rise into it, like a set in front of a painted backdrop. Its
  chip says what it shows: "21° Sunny · 13:10 · Sample weather".
- **Rain warning on posters.** An outdoor event with rain likely at its
  hour carries "Rain likely · sample forecast" under its category.
- **Today's posters follow the clock.** "On now · until 21:00" with a
  pulsing light while an event is on; once it's over the poster fades,
  says "Over", and can no longer be added to a plan.

The street, the backdrop and the posters all read the same hour: the
sample day's 13:10, `?at=`, or the App in colour time switch. Phones keep
the slim corner without the backdrop.

## The column on a street corner — 8 October 2026

Owner request: no arrows under the poster column; and instead of a list,
make the scene bigger and better by decorating its surroundings (ideas 1–4
chosen: a bigger column, a street corner, time and weather, a wall). An
"On the column" list tried first was rejected and removed.

- **No arrows.** The column turns by dragging (swiping on a phone) or the
  arrow keys: "Coming up · 1 of 4 · drag to turn".
- **A bigger, more real column.** A taller dome with a finial, a band of
  small gold arches under it, a stone plinth, and torn edges of older
  posters peeking out above and below the one facing you; the drum is old
  paper where the posters turn away.
- **A street corner (desktop).** The column stands on cobbles with a kerb
  and its shadow; an old lantern behind a bench at its left, a bike
  leaning on it, two pigeons (one pecks now and then). Drawn flat, like
  the skyline; none of it is a control.
- **Time and weather.** The street keeps today's hour and weather, from
  the same clock as the skyline (the sample day's 13:10, `?at=`, or the
  App in colour time switch): the lantern lights at dusk and casts a pool
  of light, the wall darkens at night (text on it turns light), rain falls
  on the corner, puddles stay a couple of hours after it and catch the
  column's colour, and posters shine wet. At 13:10 it's a dry afternoon;
  try `?at=19:30` for dusk in the showers or `?at=22:40` for night.
- **A plastered wall.** The board has a little grain, softer and lighter
  patches, faded scraps of older posters, paste marks where others were,
  and a "Plakatieren verboten" ("no posters") stencil at its foot.
- **The day flows round the corner.** On desktop the posters are laid like
  words, beside the corner and then across the full width below it, so a
  long day doesn't leave the left side empty. The corner stays above the
  list so its column can be used.
- **Phones** keep the column on a slim line of cobbles (with puddles and
  rain); the lantern, bench, bike and wall stay on larger screens, so the
  first poster, search and sort stay on the first screen, clear of
  Composer's button.

## A sky that follows the time and the weather — 8 October 2026

Owner request: remove the Afternoon / Evening / Late switch and let the sky
follow the time of day and the weather by itself, with all five ideas
proposed (`sky.js`, `sample-weather.js`).

- **The sky follows the clock.** Today it shows now (the sample day's
  13:10); another day, the hour its first event starts. Its colour blends
  continuously by how high the sun stands, with sunrise and sunset worked
  out from the date and Cologne's position (30 September: 07:29 and 19:15;
  the October clock change is honoured). The sun crosses the drawing from
  rising to setting and sinks behind the city; the moon shows when it's up,
  in its real phase (from the average month, right to within about a day).
  Windows light as it gets dark; stars come out on a clear night.
- **Weather on the skyline.** Clouds come in as the cover grows and drift,
  rain falls, mist lies on the river. A chip where the switch was says the
  hour, the weather and its source: "21° Sunny · 13:10 · Sample weather".
  The prototype's hourly weather is fictional sample data agreeing with the
  header's 21° at 13:10; in the app it is the hourly forecast. A past day
  says "Weather not recorded" and gets a plain sky; a day beyond the
  forecast says "No forecast yet".
- **Rain where it matters.** An outdoor event (category Outdoors, or a
  listing that says outdoor or open-air) with a 50% chance of rain or more
  at its start hour gets a drop on its light and "Rain likely at 20:00 ·
  sample forecast" on its label. Other events are left alone: we don't know
  whether their venues are indoors.
- **Today's lights follow the time.** Over: lights out ("already over");
  on now (between its start and listed end): "Now", pulsing; still to come:
  lit. Preview another time with `?at=19:30`.
- **A light shows its hour.** Pointing at a light, or focusing it, turns the
  sky to its hour and that hour's weather (the sun and moon travel there);
  leaving brings it back. On a touch screen the first tap does this and the
  second opens the event; tapping elsewhere goes back.
- **The App in colour concept's time switch** (13:10, Morning … Night)
  moves the skyline too: each part of the day shows the sky at a time
  inside it (08:30, 12:30, 16:00, 19:30, 22:30; 13:10 is the sample day's
  time), today's lights follow, and the sky glides to the new hour.
- **Reduced motion:** nothing drifts, falls or pulses, and the sky changes
  without gliding.

`tests/sky.test.cjs` checks sunrise, sunset, the clock change, the moon's
phase, the palettes, the weather and the chip; `tests/wall-browser.cjs`
checks the chip, the rain note, hover and tap previews and today's states.

## The skyline on its own — 8 October 2026

Owner request: the Skyline view can go without the poster column, and the
skyline should be bigger.

- **No column beside the city.** In the Skyline view the board is the
  skyline across its full width, then the day's cards. The column (and the
  later city listings on it) stays on the Wall.
- **The lights are the chosen day's events,** and each opens its event.
  The rings for posters coming up, and the glow that followed the column as
  it turned, went with the column. A past day still has its lights out.
- **Bigger.** The drawing is up to 620px tall on a desktop (62% of the
  window's height, at least 380px) and never narrower than its stage, so on
  a wide screen it fills the board and pans; at most 60% of the stage's
  width on a tablet. Phones get 384px (was 340px). It opens framing the
  day's lights, or where most of them are when they don't all fit.
- **The cards are the whole day,** including the featured event the column
  used to carry; the count line no longer says "on the poster column" here.
- **Composer's corner stays free on phones.** The Afternoon/Evening/Late
  switch stops short of the bottom-right corner, and the first card starts
  below Composer's button on a 390×844 phone (checked in
  `tests/wall-browser.cjs`).

## A bigger skyline you pan — 8 October 2026

Owner review: the list under the drawing ("Further north …") wasn't needed,
the skyline itself should be bigger, and moving the mouse from one light to
its neighbour didn't focus the neighbour.

- **No list under the drawing.** Chorweiler (far north: tower blocks at the
  right end) and the west (Junkersdorf: woods behind the city, on the
  horizon) are drawn in, with smaller lights. The note sits on the river.
- **The skyline is drawn large and pans.** The empty river and sky are
  cropped; the drawing's height is set and its width follows, so it's wider
  than its panel. It opens framing the day's events (and the facing poster)
  when they fit, glides to the facing poster's light when you turn the
  column to one further away, follows a mouse drag (touch scrolls), and its
  edges fade while there's more beyond. Beside the skyline the column is a
  little smaller, so tonight's two events fit in one view.
- **Every light can be reached.** Only a light's dot (with a 50px hit
  area) and a fully shown name respond to the pointer, so a focused light
  never blocks its neighbour; lights from different districts never land on
  the same spot. `tests/wall-browser.cjs` moves the mouse across every
  light on the past day and checks each one is the one focused.

## Weathered past posters, and a calmer skyline — 8 October 2026

Owner request, after reviewing the skyline on the past day (8 September),
where labels piled on top of each other and "Further north/west" names
covered the city.

- **Past posters are weathered.** A day that has already happened hangs as
  sun-faded paper with creases and a stain, and each poster wears in its own
  way: a torn edge, a nicked side with a peeling corner, or a torn top
  (`data-wear`, from the event, so it always looks the same). The "Already
  taken down" strip wears the same way. Still readable; still no Save or
  Plan.
- **On the skyline, a past day's lights are out:** grey, unlit windows, a
  paler city and "These have already happened, so their lights are out."
- **Labels never pile up.** The poster facing you is named first, then the
  day's events in time order; a label that would cover another label or
  another light shortens to its time, or hides. A light always names itself
  on hover, focus and to screen readers. Checked at 390, 1024 and 1440 with
  the column on each of its posters.
- **Labels read on any sky.** They're solid paper with dark text and a
  small tail pointing at their light (they were see-through dark pills,
  hard to read on the pale afternoon sky). Past lights are grey with a white
  ring; lights for another part of the day stay solid but unlit.
- **Pointing at a light focuses it:** its full name with a cyan ring, a
  bigger dot, and the other lights step back.
- **Coming-up rings are quiet** until their poster faces you.

`tests/wall-browser.cjs` checks the past day's posters are weathered and its
skyline has no overlapping labels; `tests/poster-art.test.cjs` checks the
wear is stable and only on past posters.

## Tilt your phone — 8 October 2026

Owner request. On a touch screen, tilting the phone makes the wall's posters
lean with it (with a moving highlight), the poster column lean, and the
skyline's layers shift: stars and the far city drift against the tilt, the
near city and its lights move with it. It follows how you hold the phone,
which slowly becomes the new rest, so holding it at any angle settles back
to still. A phone button beside Wall/Skyline turns it off and on (the choice
is remembered on this device). Phones that ask for motion access (iPhone)
start it from that button; others start it straight away. Never with reduced
motion. `tests/wall-browser.cjs` sends tilt readings and checks the posters
follow and the button stops it.

## The skyline answers the column — 8 October 2026

Owner request. In the Skyline view the column now stands beside the city, as
on the wall. Every poster on the column has a light on the skyline (the
day's events, and the later listings as rings until they face you); a
listing outside the drawing is named at the edge ("Further north: …").
Turning the column brightens the light of the poster facing you, smoothly as
it turns, and dims the others; tapping one of those lights turns the column
to its poster the short way round (bringing the column into view first on a
phone). Lights for the day's other events still open their details. The
featured pick isn't repeated as a card below the sky, and the count line
says "on the poster column" in both views. Beside the column the sky's title
sits above the drawing.

## The column turns, and the wall comes alive — 8 October 2026

The owner liked the poster wall and asked for it to be more interactive and
moving.

- **The column turns like a real Litfaßsäule.** It now carries everything
  coming up: the featured pick, then the later listings in the same city
  series (which moved off the wall). Drag or swipe sideways, use the arrows
  under it ("1 / 4") or the arrow keys. Posters sit a quarter turn apart;
  seen from the front, a poster at angle θ is centred at R·sin θ and narrowed
  to cos θ, so turning bends them round the column's sides. A flick turns one
  poster, a long drag lands on the nearest, and only the poster facing you
  can be used (the others are inert). Once a session the column turns a
  little and back to show it can; never with reduced motion.
- **A lamp under the cap** lights while an evening or night poster faces you.
- **Posters are pasted onto the wall** one after another as a day loads
  (360ms, 70ms apart), and turn over in depth.
- **With a mouse, a wall poster leans towards the pointer** and catches the
  light. Touch and reduced motion keep it still.
- **Your choices show on the posters.** Saving pins a poster with a push pin;
  once an event is in your plan for its day it gets a stamp: "In your plan",
  or "Added · not confirmed" while it's a proposal. A sample plan you didn't
  make isn't stamped.
- The week's notes (the 3 October holiday) stay pinned to the wall.

`tests/wall-browser.cjs` now also checks the column's four posters, that the
listings can't be planned, turning with the arrows and that only the front
poster is usable.

## On the wall: the Events page since 8 October 2026

The owner approved the poster wall and asked for it to replace Events
(8 October 2026). It was reviewed first as a separate page; `../events-wall/`
now forwards here. `tests/rail-height-browser.cjs` was removed with the poster
rows it checked; `tests/wall-browser.cjs` covers the wall.

The owner chose concept A (Poster Press) from the
[Events concepts canvas](https://claude.ai/artifact/9L2kfkt4VaeeRzQsR9BCGw)
and asked for concept B (Rhine tonight) to join it, with posters wherever
there's no picture.

### What it does

- **Every event is a poster set from its own listing.** No photograph and
  nothing added: title (and its English meaning), date, time, place,
  admission and category. The category picks the shape (Culture rings, Games
  checks, Outdoors waves, Language speech bubbles) and the hour picks the ink
  (before 17:00 yellow, until 20:00 purple, later night navy with stars; no
  time is plain paper). Unknowns read "Price not listed" or "Time not listed
  yet". Titles use Bricolage Grotesque as poster lettering
  (`fonts/` links to the self-hosted files in `public/fonts/`).
- **The poster column** (a Litfaßsäule) holds what's coming up: the
  featured pick and the later city listings. It stays put while you filter,
  as the owner decided for Events, and turns (see the section above). The
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
- **Skyline view**, a reference in this preview only, at `?view=skyline`
  (since 9 October the page has no switch to it).
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
Get there and the past-day rules are the Events page's own (`app.js`); `wall.js` swaps only how events are
drawn by replacing `ExpaduEventFestival`'s feature, render and image. The
Festival cards (`festival.js`) still draw Today's events and the Skyline view's
photo cards.

### Files

- `poster-art.js`: the poster generator (ink, shape, front, back, turn).
- `wall.js`: the column, the wall, the later listings, the past strip, and
  the skyline kept for reference (`?view=skyline`).
- `wall.css`: posters, column, wall, skyline and the phone layout.
- `fonts/`: links to the self-hosted Bricolage Grotesque in `public/fonts/`.

### Checks

- `node --test prototype/dev/design/events-next/tests/poster-art.test.cjs`:
  ink and shape rules, unknowns, separate Save and Plan, no actions on past
  or later listings, "Fits your day", escaping.
- `node prototype/dev/design/events-next/tests/wall-browser.cjs` (server on
  8765, or `BASE=`): at 390×844 the column poster's Add to plan and both filter
  rows are on the first screen with nothing under Composer; no sideways
  scroll at 390 or 1440; turning a poster over and back; three later listings
  without planning; Skyline lights, the poster card for the film night and a
  light opening the sheet; the 8 September posters without Save or Plan.
- Checked by eye at 390, 768, 1024 and 1440. Screenshots:
  `docs/reviews/2026-10-08-events-wall/`.

### Open for the owner

- Poster lettering uses Bricolage Grotesque, which is still undecided for the
  app's own titles; page titles here stay Geist.
- The holiday note's list (`wall.js`, `holidays`) is preview data for
  Germany in 2026, not a calendar service.

## Poster rows no taller than the featured poster — 7 October 2026

On the sample day the featured poster takes Street culture night, so "Let the
evening happen" held one card. It spanned the whole row and its 4:5 shape made
it 1,701px tall at 1440px (1,181px at 1024px): nearly two screens of one
picture. Row cards are now capped at the featured poster's 420px; a lone card
keeps its 4:5 poster shape (336 × 420) and two to four cards fill their
columns. Phones are unchanged (307 × 384). `tests/rail-height-browser.cjs`
checks one to four cards at 1440, 1024 and 390px. Evidence:
`docs/reviews/2026-10-07-app-in-colour/partb-events-*.png`.

Flagged, not changed: `photos/film.webp` (Cinema under the stars, Stadtgarten)
shows Alpine peaks and a lake behind the audience, so it reads as somewhere
else. It's labelled as an AI illustration of the activity, but a Cologne-like
scene would fit better: a park lawn under old trees at dusk, string lights, a
screen showing an abstract film still, a low city skyline with no mountains or
lake. That needs a new image with the same labelling; none is generated here.

## Quieter facts, clearer filters and messages — 7 October 2026

From the owner's Events review (items 7–12):

7. **Facts without alarm.** The big yellow "Price to check" and lavender
   "Entry: To be confirmed" tiles are gone. The sheet has one quiet list:
   Admission, When to arrive ("By the start time", "Any time while it's open"
   or "Not listed"), Language, Who it's for and Accessibility, with "Not listed"
   in soft text and one line asking to check those with the organiser. The
   reminder reads "Remind me before it starts · Reminders aren't sent in this
   preview". Cards say "Price not listed".
8. **No-match messages.** A search that finds nothing names the word and the
   day ("No events match “jazz” on Wed 30 Sept"), suggests another word, and
   offers Clear search. When the same search or filters match another day, it
   says so and links there (for example "chess" on 30 September points to
   8 September, which has already happened).
9. **Featured is "Coming up".** The featured poster stays independent of the
   chosen date and its caption now says "Coming up · sample programme, AI
   illustrations", so it doesn't read as that day's programme.
10. **One icon per category** on cards, chips and the event sheet (Culture is
    always the museum icon). The sample day's film night is Culture, like the
    captured film listing, so there's no separate Film chip.
11. **Long German titles** wrap at whole words. Only words of 13+ letters may
    hyphenate, keeping six letters on each side ("Feierabend-rundgang", never
    "Fei-erabendrundgang" or "Klima-wandel").
12. **Compact filters (owner rework).** Free, Saved, Travel and Sort are 44px
    icon buttons with tooltips and spoken names, so the search keeps the room
    (about 230px on a 390px phone). Category and Area are dropdowns: they name
    their filter ("Category", "Area") until something is chosen, show a chevron,
    and turn cyan with the chosen value (on phones the value replaces the
    icon). Two aligned rows fit at 375px and wider; on narrower phones long
    values shorten with an ellipsis. Desktop keeps its row, with "Area" and a
    chevron.

## Sample day, past listings and clearer details — 7 October 2026

From the owner's Events review (items 1–6):

1. **One sample day.** Events opens on Wednesday 30 September, the day Today and
   Composer use (`connected/sample-day.js`), with its two evening events. The
   captured 8 September programme is a past sample: "Past · 8 Sept" markers,
   no Save or Plan, a note above the results and "Past sample" beside the count.
   Their sheet keeps the same three tabs: About says it already happened, Plan
   explains why it can't be planned and links to upcoming events, and Get there
   gives directions to the venue only (from today's starting point, with no
   event date or arrival time). Empty dates name both days.
   The calendar's Today button goes to the sample day.
2. **What an event is.** Cards and the sheet show the English meaning of German
   titles. The sheet shows the city's description where Expadu's local catalogue
   has one, translated and labelled with the later listing in the same series
   it comes from, and a Good to know list: language, who it's for (ages) and
   accessibility, each "Not listed" when unknown. Search also matches English
   titles (for example "chess").
3. **Planning in plain words.** "Add to plan"; after adding, "Added to your
   plan. It isn't confirmed yet." The Museum Ludwig exhibition is a flexible
   visit within its opening hours. Get there starts from your chosen starting
   point, otherwise from where you'll be (after the Blue Card appointment, or
   the sample day's starting point) with a short reason.
4. **Starting point.** Districts, Neumarkt and listed venues are recognised
   (`distance.js`: district centres and venue points read from Expadu's local
   catalogue on 7 October 2026). Cards then show a straight-line distance, the
   count line says where it's measured from, and "Closest first" appears in
   Sort. Unrecognised places and the film venue (no captured location) show no
   distance and sort last. No travel times are claimed.
5. **Composer button.** With the shorter poster, no control sits under it on
   first load at 390×844, 375×812, 393×852, 820×1180, 1280×800, 1366×768 or
   1440×900. On a 375×667 phone the Saved filter button still starts beneath it.
6. **Featured once.** The featured poster shows upcoming events only and is
   shorter (about 380px on phones, 420px on desktop); while browsing, featured
   events are left out of the rails ("1 featured above"), while filtered
   results list every match.

Verification: Events 51 tests (6 new for these rules), connected 153, Today 44,
Composer 111, Paperwork 65, Travel 124, Places 14 and Today C 56 pass. Every
other page's default view at 390px and 1440px is pixel-identical to the previous
commit. Browser checks covered the flows above at phone and desktop sizes with
no console errors.

## Connected review and frontend — 3 October 2026

Selecting an event now ends with a direct **Review plan** action that retains its
date. Reopening a pending selection or proposal still describes it as a proposal;
it never becomes a confirmed day merely because the sheet was closed. Undo and
the separate Get there tab remain available. The listed schedule stays fixed.

The real `/events` page now uses the same immersive card direction, with a
separately loaded upcoming carousel that stays in place while results are
filtered. It reads existing occurrence APIs and their rights-filtered media.
Missing or failed media becomes a compact information card. Unknown prices,
finishes and venue coordinates remain explicit. Empty programmes do not claim
that all events have finished or promise more listings on another day.

Real selections pass `event`, the original ISO `start`, and its Berlin `date` to
Composer for review. This action neither submits a prompt nor changes a saved
plan. Composer owns the later explicit addition and confirmation. Device-only
bookmarks store occurrence IDs under the current account, labelled **Saved on
this device**; they are separate from Composer's saved plan and from reminders.
No new backend endpoint, catalogue import, deployment or sample image is involved.

Verification: the 45 prototype Events tests and four real presentation/handoff
tests pass; scoped ESLint and the application typecheck pass. Browser scenarios
are in `tests/Browser/events-connected.spec.ts` for the root integration review:
hero preservation during filtering, dated review without mutation, unknown
facts/media, feed retry, failed device saving and failed reminder persistence.
This note does not claim that those browser scenarios have run yet.

## Festival implementation for review — 29 September 2026

The owner rejected the split image/white-information card treatment and the hero
disappearing on filter changes, and prefers
[Concept B / Festival](../connected/concepts/events-festival-b.png). The Events
prototype now implements that direction: full-image cards, brush lettering on
the featured carousel, yellow date markers and separate compact Save and
calendar-plus actions. See the
[shared decisions](../connected/design-decisions.md#preferred-event-direction-concept-b--festival--29-september-2026).

Three explicitly chosen sample events form a swipeable featured programme.
Mouse dragging, arrows, dots and keyboard navigation work without autoplay.
The hero and selected slide stay in place while filters update results below.
The calendar belongs to that dated result section, not the independent featured
programme. The sample date remains 8 September; other dates do not invent events.

Collections use the desktop width and become horizontal rails with a visible
next card on phones. Filtered results keep the selected order. The first hero's
planning action clears the Composer button even on a short phone. The date
picker remains within the viewport when expanded to its month view.

The existing fixed-time planner, details, directions, proposal handoff and
swipe/double-tap Delete reveal remain connected. Today now shares the Festival
cards, photo treatment and frosted Save controls. The new app search entry is deferred; the existing Events
search remains. The separate logo task has not been applied to the app shell.

Photos are clearly labelled AI illustrations, not photographs of the actual
events or venues. They are prototype assets and do not bypass media approval;
approved healthy media with source and credit still takes priority. See
[asset provenance](photos/README.md).

Browser checks cover 320px, 393px and 1440px layouts, carousel drag/arrows,
retained hero state during filtering, Save/Saved, fixed-time planning, the day
Delete reveal and adding an event to the proposal. Review screenshots:
[phone](festival-mobile.png), [desktop](festival-desktop.png).

## Superseded event-first refinement — 29 September 2026

The owner approved the [researched discovery direction](../connected/event-discovery-research-2026-09-29.md).
This iteration was implemented for visual review and subsequently rejected.
The notes below describe that earlier iteration, not the current visual direction.
It changes the connected prototype, not the Laravel application.

One explicit editorial sample pick uses the page width, followed by afternoon
and evening collections. The calendar is an anchored week/month picker at all
sizes. Search, category, area, Free, Saved or a non-default sort switch to ordered
matching results without a feature. Reset returns to discovery. The controls stay
visible when the feature collapses. Filter state, dates, Back/Forward and empty-day
recovery retain the existing behavior.

Event details sit below the artwork. Save and the compact planning action are
independent. Original illustrations remain labelled samples; no unapproved
photographs or invented news items were added. Media with approved rights, active
health and attribution uses the shared image path, with a failed-image fallback.

Browser review covers phone, tablet and desktop sizes, separate Save/Plan actions,
fixed-time planning and the existing timeline Delete reveal. The Today, Events
and connected regression suites pass. No console errors were observed in the
reviewed pages. Final review screenshots: [phone](discovery-mobile.png) and
[desktop](discovery-desktop.png).

## Bureaucracy consistency pass — 28 September 2026

Events is the first review of the owner's Bureaucracy reference for proportions
and visual consistency. Changes are scoped to Events styles:

- 44px touch targets, 14px discovery labels and 18px control icons.
- Soft rails and white selected surfaces for category, calendar view, detail
  tabs, travel modes and visit suggestions; cyan still identifies chosen dates
  and filter options.
- A calmer shared page canvas, clearer event metadata and rounded cards.
- More readable detail labels, stable cover height and compact faded footers.
- Narrow-phone spacing keeps the default filter labels visible at 320px.

The existing gallery, artwork, occurrence data, single week/month calendar,
Composer connections and planning behavior are preserved. Bureaucracy, Places
and Departures are not changed by this pass. Existing motion and reduced-motion
rules remain in place; no decorative animation was added.

## Verification

- The 25 existing Events tests pass, covering dates, filtering, calendar
  navigation/dismissal, poster actions and swipes.
- Browser review at 320px, 393px and 1280px: no page overflow, default mobile
  labels fit, and the first event action fits in the phone viewport.
- Area, Free and search filtering work; Escape closes the travel picker and
  restores focus. The input uses its inset focus edge without an outer ring.
- Week/month views retain the chosen day. About, Plan to go and Get there keep
  the same 104px cover at the tested phone height. The three time suggestions
  remain in one row and update the form/summary without adding a proposal.

Review images: [phone](review-mobile.png), [desktop](review-desktop.png),
[plan](review-plan.png).

## Your day removal follow-up — 28 September 2026 (superseded below)

The review found that a provisional visit had a separate Clear action, which
skipped both the warning and the swipe interaction. It now uses the same
right-to-left inline confirmation as a planned activity: drag left or tap the
Remove icon, then choose Cancel or Remove. Cancel and Escape keep the times.
Confirming a preview removal clears only the unfinished time choice; confirming
removal of a planned activity keeps its guarded Undo action. Changing the
preview times invalidates an older removal confirmation.

Verified both entry paths on preview and planned rows in the memory-only Events
fixture, plus cancellation, confirmed removal, Undo and the single-line warning
at 320px. The connected/Events/asset checks pass (149 tests). The fixture tests
do not read or write the user's stored plans.

## Event timing and day cards — 28 September 2026

Planning now keeps the listed start/end by default and presents them as a compact
schedule, without time pickers or suggestions. Only an explicit visiting window
offers flexible attendance. The captured programme's unknown attendance policies
remain unknown; the UI no longer interprets them as permission to suggest shorter
visits. An unlisted finish asks for an expected end while preserving the start,
and the plan carries that finish as an estimate.

Your day uses soft pale cards, dark time labels and a small cyan Preview badge.
The purple outline and left border are removed. Planned activities and all new
previews retain their Remove action and swipe confirmation. Removing a fixed-time
preview dismisses that selection without clearing its listed schedule. Undo
restores it. A flexible preview still clears its chosen times after confirmation.

Verified scheduled-time planning, an estimated finish, retained arrival time,
and swipe/button cancellation in the memory-only fixture at phone widths,
including 320px. Fixed-event conflicts ask to review the day, not change the
event's start. The connected, Events and asset suites pass (154 tests).
Review: [scheduled event and day preview](review-fixed-time.png).

Fixed-preview follow-up: reproduced the missing gesture on SchachXperten, then
separated preview removal from time editing. Tests cover fixed, unknown and
default event policies, cancellation, confirmed dismissal, Undo and explicit
addition with the original schedule. The previous exclusion of fixed previews
was incorrect.

Verified a real left drag and the Remove button on SchachXperten, followed by
Cancel, confirmation, tab switching and Undo. Its 16:00–17:30 schedule remains
unchanged. The warning stays on one line at 320px, and Escape dismisses only the
warning. The connected, Events and asset suites pass (158 tests).
Review: [fixed-preview removal confirmation](review-fixed-preview-removal.png).

## Your day: swipe actions — 28 September 2026

The later Apple Notes reference replaces the full-width confirmation and permanent
trash control. Swipe left or double-tap an activity to slide it 64px aside and
reveal a red circular Delete action; the time column stays still and the activity
remains partly visible. Only tapping Delete removes it. Swipe right, tap the
activity or elsewhere in the sheet, or use Escape to close the action. Keyboard
and screen-reader users can activate the activity to reveal the same action.

Motion tracks the finger, settles over 300ms, and respects reduced motion. Short
or interrupted gestures restore the row, vertical scrolling stays native, and the
release click cannot delete anything. Fixed-time preview dismissal and planned
activity Undo retain their existing safeguards. No backend change is involved.

Verified in the memory-only fixture: single tap, double-tap, left swipe, swipe
back, tap-away, Escape, keyboard Space, deleting previews and planned activities,
and Undo. Reviewed at 320px, 393px and 1280px. The connected, Events and asset
checks pass (163 tests). Review: [compact swipe action](review-day-swipe-action.png).

## Date continuity — 29 September 2026

The selected programme date is retained in the URL. Choosing a date or viewing
the sample programme updates that date; refresh and browser Back/Forward restore
the corresponding programme without adding duplicate history entries. Unrelated
query parameters and filters remain intact. Regression tests and browser checks
cover sample-date recovery, reload and Back/Forward.

Today now opens this same event sheet for event details and planning. Artwork
paths resolve from both entry points, and fixed schedules, directions and day
actions share the same behavior.
