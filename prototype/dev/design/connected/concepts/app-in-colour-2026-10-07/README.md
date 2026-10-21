# App in colour — concept, 7 October 2026

**Status: concept for review, not approved.** The approved pages are
unchanged. Moving away from cream on every page (the 6 October palette
decision) is the owner's call; `design-decisions.md` and `tokens.css` stay as
they are until then.

Brings some of the marketing page's colour ("Cologne in colour",
`resources/css/marketing-landing.css`) into the app, carefully: colour goes
in one place per page, cards stay light, motion stays at the app's
120/180/280ms with the ease-settle curve.

- Compare current and concept side by side in the Design canvas
  <https://claude.ai/artifact/7g3WeH5wdAkjaSeXk4PXt1>.
- Try it live: `python3 prototype/serve.py`, then
  <http://127.0.0.1:8765/dev/design/connected/concepts/app-in-colour-2026-10-07/>.
  The bar switches page, Concept / Current / Side by side, 390 / Desktop, the
  time of day (Today) and Geist / Bricolage. Settings are in the URL, e.g.
  `?page=today&mode=both&width=phone&time=evening`.
- Screenshots: `docs/reviews/2026-10-07-app-in-colour/`.

## How it's built

`colour.js` adds `colour.css` and a few classes to an approved page; nothing
is copied. Everything is scoped under `html.ac`, so a page without the script
renders exactly as before. The viewer (`index.html`) loads the real pages in
frames and adds the script to the Concept frame. Composer's starter cards and
event posters are added to its rendered markup by the script; its own data
(`connected/sample-day.js`) supplies every time, place and count. `fonts/`
links to the self-hosted Bricolage Grotesque files in `public/fonts/` (SIL OFL
1.1, licence alongside).

## 1. Today: colour follows the day

Only the top band changes colour: greeting, date, the visa deadline and "Your
day" on phones; greeting, date and deadline on larger screens, where the
workspace card overlaps the band so its three column headings stay on one
light surface. Cards stay light. The deadline becomes a light card on every
band; its amber tile and arrow keep the attention colour.

| Time | Band | Text | Detail text |
| --- | --- | --- | --- |
| Morning 05–11 | peach `#ffe0d4` | `#211d15` 13.5:1 | `#5c4636` 7.1:1 |
| Midday 11–14 (sample 13:10) | yellow `#ffe680` | `#211d15` 13.5:1 | `#5a4a14` 7.0:1 |
| Afternoon 14–18 | deep teal `#0f3a42` | `#fffdf8` 12.1:1 | `#b9d3d7` 7.8:1 |
| Evening 18–22 | dusk `#2b1d3f` | `#fffdf8` 15.3:1 | `#cdbfe3` 9.0:1 |
| Night 22–05 | ink `#0b1a2b` | `#fffdf8` 17.3:1 | `#a9c4dd` 9.7:1 |

Tuning: midday is softened from the marketing yellow `#ffe363` to `#ffe680`
for a band seen every day; the others are the marketing values. The name's
orange full stop becomes the marketing sun `#ff7a3d` on dark bands.

The time switch previews colour, and on Events the Wall's sky and street
at a time in that part of the day (08:30, 12:30, 16:00, 19:30 or 22:30;
since 8 October; the Wall since 9 October, when the Events page stopped
offering the Skyline);
the sample day stays Wednesday 30 September, 13:10. Page height is identical at every time (2,226px at 390,
1,501px at 1440). Colour changes fade over 280ms, with no transition for
reduced motion. The band is measured once per layout, never per time.

**Dark mode (not built):** `html.dark` would keep the same five moments but
start from the dark canvas: morning and midday become deep tints (about
`#3a2620` and `#3a3312`) with light text, so the band never glows white at
night; afternoon and evening keep their colours; night lifts a step to
`#13263b` so it still reads as a band against a dark canvas. Cards use the
dark surface, and the same 4.5:1 rule applies to every pair.

## 2. Composer: the brightest page

- A white stage (`#ffffff`) like the marketing demo; no beige fills. The input
  sits on white with a 3:1 border. Your messages use the pale cyan bubble.
- The single sparkle is replaced by starter cards in their section colour:
  **Travel** (cyan `#c9f1fa`, teal ink): "When to leave for 14:00", Neumarkt →
  Mülheim · line 4. **Paperwork** (lavender `#e4d8fb`, violet ink): "What to
  bring at 14:00", your EU Blue Card checklist. **Events** (dusk `#2b1d3f`,
  yellow marker date stamp): "What's on tonight", 2 sample events from 18:30.
  Plan my Saturday and A little time outdoors stay as outlined ink chips. Each
  detail comes from the sample day; nothing is invented. Phones show the three
  as compact rows with both chips on one line, all above the input.
- Event answers carry the Events poster picture with its yellow date stamp,
  labelled "AI illustration · sample". Place answers keep the existing map
  pictures from `connected/place-maps/` (no photos).
- Add to plan is orange; Added stays cyan. (Also fixed on the approved page in
  Part B.)

The Travel starter asks "When should I leave for my 14:00?", which answers
with Today's 10-minute rule. The Paperwork starter gets Composer's honest
"no verified guidance" reply with the process link; that's correct for now.

## 3. One colour per section

Each section's colour sits in its heading area and its icon tiles, nowhere
else. Selection stays cyan, actions orange, attention amber, conflict coral.

- **Paperwork:** lavender `#efe7fb` heading area; icon tiles `#e4d8fb` with
  violet `#67409a` (5.6:1); To do / Preparing badges lavender. The cyan tiles,
  badges and "One step at a time" banner (which looked like Travel) are gone;
  the banner is a light row with Composer's teal mark.
- **Travel:** cyan/Lagoon, unchanged. **Events:** dusk and the yellow marker,
  unchanged.
- **Services (proposal): sage** `#e2f3e8`, tiles `#cdebd8` with `#1f6b45`
  (5.1:1). Not orange (action), amber or coral (attention, conflict), and
  clear of cyan, lavender and dusk. Green reads as everyday help (doctors,
  pharmacies). The beige side menu and field fills are gone: the menu is a
  plain list with the cyan selection, fields are white with a 3:1 border, the
  bookmark is outlined ink.

## 4. Bricolage Grotesque for big titles only

Page titles ("Today, Anar.", "Paperwork", "Good things to turn up for.",
"Travel", "What's on your mind?") and big times ("14:00") at weight 800.
Body text and controls stay Geist. The canvas has every title with and
without it.

## Not brought over

The scroll-story animations, full-colour sections on every part of a page and
decorative loops. One band per page, no animated art.

## Findings for the colour roles

- Composer's event time row ("Wed 30 Sept · 18:30–21:00", "Fixed time") is
  violet and lavender, the Paperwork colour. The concept makes it ink.
- Midday yellow sits close to the attention amber. That is why the deadline
  becomes a light card with an amber tile on every band; the band is never
  used for chips or badges.
- Evening dusk is also the Events colour. On Today it only fills the band in
  the evening, so it reads as the time of day.

## Redesign: Today and Composer (owner request, 7 October)

**Owner review, 7 October:** the Today redesign is not taken. The current
Today is better; this version rearranged the same blocks, and its day
timeline is close to the timeline direction rejected on 2 October. Today
keeps its approved two-column layout. The Composer redesign's rich answers,
coloured starters and Your day panel are built into `composer-studio` and
approved for implementation (7 October). The files stay here for reference.

The colour pass above keeps the approved layouts. This goes further: new
compositions for Today and Composer, as standalone mock pages in `redesign/`.
They use the approved header (a copy of the prerendered block, with absolute
links), `tokens.css`, Geist, Bricolage and the sample day's content only.
Nothing in them is connected; buttons are for review.

- Today: `redesign/today.html`. Composer: `redesign/composer.html`
  (`?state=chat&answer=leave|bring|near|tonight|stepfree|all`).
- Both are in the viewer as "Today redesign" and "Composer redesign", beside
  the approved page. `?time=` changes Today's band, `?display=geist` drops
  Bricolage and `?tram=planned` leads with the planned 13:24 instead.

**Today is your Wednesday, not a dashboard.** The time-of-day band holds the
greeting, the sample forecast and the visa deadline. The next plan comes
first, as one card across the band: 14:00 in large type, the appointment and
place, what to bring, and the trip beside it ("Leave now", the three steps,
"At your appointment 13:48 · 12 min to spare", and the planned 13:24 noted).
Below, the rest of the day is a timeline with large times, like the marketing
page's "One Wednesday": 14:30, places listed as open near Wiener Platz (map
pictures, listed hours); 15:00, Language café; 17:00, the riverside walk and
chess; 18:30, tonight's two posters; then Add a plan, the section's one
orange action. Ideas say "Idea · sample", and the page says nothing else is
in the plan; it never says you're free. A side column holds Paperwork
(lavender), Getting around (cyan), Coming up, Need a hand? (sage) and the
city links. Phones show the greeting, deadline, 14:00 and its trip with
Journey details in the first 844px; the timeline and side cards follow.

**Composer sits beside your day.** On desktop, "Your Wednesday" on the left
lists what Composer plans with (the trip to take now, the 14:00 appointment,
the visa deadline, near Wiener Platz after 14:30, tonight's events, coming
up); tapping one asks about it. The conversation is on a white stage: "What's
on your mind?" with four starters in their section's colour (Travel cyan,
Paperwork lavender, Events dusk with the date stamp, Nearby with a map
picture) and chips. Answers are rich cards with a large one-line verdict:
the trip steps in cyan with Open in Travel; your checklist ("1 of 5 marked
ready", your own records, no legal guidance); three pharmacies with map
pictures and listed hours; the two posters with orange Add to plan; and
"Not listed." with the facts that are and aren't known. On phones your day
becomes a scrolling row of context above the chat.

Kept from the approved pages: the big 14:00, the Events posters and marker
lettering, the trip card's content, the honest sample labels and the colour
roles (one orange main action, cyan selection and travel, lavender
paperwork, amber attention). The tram that leads follows decision 4 (the
13:14 here; `?tram=planned` shows the alternative).

## Today: the shape of your day (one new idea on the current layout, 7 October)

**Owner review, 7 October: not taken.** Today stays exactly as approved; no
Today redesign is being pursued. The files stay here for reference only.

The owner kept Today's layout and asked for one original idea. `today-ribbon.js`
(with `today-ribbon.css`) adds it to the approved Today page in the viewer
("Today · shape of the day"); the page itself is unchanged.

- **A ribbon at the top of the workspace** shows the whole day at a glance:
  the morning behind you shaded, a "Now 13:10" marker, the trip (cyan, from
  leave-by 13:20 to 13:58 at the appointment) and the 14:00–14:30 Blue Card
  appointment (lavender), then "Nothing else in your plan" for the rest of the
  day, with tonight's two sample events as outlined ideas inside it. Phones
  show 12:00–24:00 with one line of caption. It reads the shared sample day
  and Today's leave-by rule, so it agrees with the cards; it shows only for
  the Everyday preview scenario. It's a picture with a full spoken label, not
  another set of buttons.
- **The next thing follows you.** Once the journey card's "Leave by" line has
  scrolled away, a slim bar under the header keeps it in view ("Leave by 13:20
  · Line 4 at 13:24 from Neumarkt · for 14:00 Blue Card appointment"). Tapping
  it brings the appointment back. It fades in over 180ms, with no motion when
  reduced motion is on.

Why: Today's cards say what each thing is; nothing shows how the day fits
together, or that the afternoon is open. The ribbon adds that in about 90px
on desktop, and the bar keeps the time-critical action in view while you
browse events lower down. Screenshots: `docs/reviews/2026-10-07-app-in-colour/today-ribbon-*.png`.

## Travel: your day, your location and a stop display (concept, 9 October)

**Status: concept for review, not approved.** The Travel page is unchanged.
This concept was built straight into the Travel mock by mistake and then
reverted. It is now compared here first.

`travel-day.js` (with `travel-day.css`, everything under `html.tc`) is added
to the approved Travel page. In the viewer, choose **Travel · your day** or
**Travel · stop display** (`?page=travel-day` / `?page=travel-display`).
Current and concept are on the Design canvas, in the two Travel rows.

- **Your day.** The sample day's trips as tickets:
  - the 14:00 appointment with how to get there (leave now, walk to
    Neumarkt, tram 4 at 13:14, 12 min to spare, the plan's tight 13:24) and
    Start;
  - the evening's events, with no made-up times but with the labelled sample
    forecast.

  They take the captured night example's place, which stays one link away.
- **Your location.** From starts where the sample day puts you, near Köln
  Neumarkt. The suggestions offer your location, Home and Work (not
  connected) and your own recent trips.
- **A stop display.** The timetable becomes the stop's dark display: the
  sign, the clock, minutes to each departure from the sample clock, and your
  appointment's trams marked. On desktop it stands beside the stop map.
