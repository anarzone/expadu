# Places as a layer — 7 October 2026 (in review)

Places stay out of the main navigation (owner decision, 29 September). They show
up where a need comes up, as a photo-free layer:

- **Composer** answers everyday "near" questions with real places: "Is there a
  pharmacy near my Blue Card appointment?", "a café near Neumarkt that's open
  now", "somewhere to eat near Rheinauhafen". Up to three cards, nearest first,
  then **Show on map**. Quick look shows the listing's facts; Add to plan uses
  the answer's day and time ("after my appointment" starts at 14:30).
- **Today**: under "Also in Mülheim", up to three everyday places (bakery, café,
  pharmacy, groceries) listed as open when the next plan ends, with a link to
  the map. Nothing shows without listed hours.
- **Events**: the sheet's Accessibility row uses the venue's own listing when
  the event doesn't say ("Step-free access (venue listing)").
- **Around here** (`around-here/?kind=pharmacy&near=wiener-platz&time=14:30`):
  one kind near a point, as a list beside a map. Kind chips (parks, lakes and
  woods share "Parks & nature", `kind=green`), "Open at 14:30" beside the count
  (listed hours), places within 2 km plus a few further away where an area is
  thin. Tapping a row opens the shared place sheet. It is opened from Composer,
  Today and the place sheets; it is not a navigation destination.
- Composer also answers "somewhere green near …" (parks plus woods, lakes and
  nature areas, measured to their middle), "a quick bite near …" (fast food)
  and, for libraries, says which list Wi-Fi.

## The card and the facts

`connected/places.js` (model and card) and `connected/place-card.css`:

- A **map picture** instead of a photo (`connected/place-maps/<id>.png`, made by
  `tools/place-map-snippets.cjs` from OpenFreeMap tiles; the real app would use
  its self-hosted tiles). Without it, the kind's icon shows.
- The kind, name, neighbourhood and straight-line distance ("about" for the
  middle of a park), today's **listed** hours ("Open until 18:30", "Opens at
  15:00", "Hours not listed") and wheelchair access only when recorded.
- Restaurants and fast food always carry their cuisine on the kind line
  ("Restaurant · Italian · Pizza", "Cuisine not listed"; on map rows it moves
  to the area line). Cafés, restaurants, bakeries and fast food show "Outdoor
  seating" and one diet chip ("Vegetarian & vegan options") only when
  recorded; a missing tag never reads as "no". Libraries show Wi-Fi or
  public computers when recorded. Woods & nature areas have no hours or
  address slot.
- The sheet and Quick look list Today, Address, Wheelchair, Website and Phone,
  plus Cuisine, Outdoor seating ("Yes, garden") and Diet for food places,
  Wi-Fi for libraries, and Access and Entry fee for parks, nature,
  playgrounds and culture. Unknown values read "Not listed"; sources that
  disagree read "Sources disagree". Every view says the hours are listed, not
  a live check (large areas: the point is approximate), and credits
  OpenStreetMap.
- Composer's assumed category hours (`hoursAssumed`) never show as open.

## Data

`connected/sample-places.js` holds 59 real places picked by
`tools/sample-places.py` from Expadu's local catalogue export of 7 October 2026
(12,409 entries with the new food, Wi-Fi and green-space data; not deployed):
the nearest pharmacies, cafés, bakeries, groceries, playgrounds, parks (lakes
count as parks), woods & nature, libraries, restaurants and fast food around
Neumarkt, Wiener Platz (the appointment), Rheinauhafen, Stadtgarten and
Ehrenfeld, plus the Events venues, Stadtteilbibliothek Nippes, Königsforst,
Decksteiner Weiher and Poller Wiesen. Place data © OpenStreetMap contributors (ODbL). Which kinds appear
where follows the places data review: Today only shows kinds with good hours
coverage; doctors, sports and parks wait for a request.

Regenerate:

```bash
python3 prototype/dev/design/tools/sample-places.py
```

```bash
NODE_PATH=$PWD/node_modules node prototype/dev/design/tools/place-map-snippets.cjs
```

Not yet covered: dogs (no data), a Travel "near this stop" entry and live
opening checks. Stadtteilbibliothek Neubrück isn't a library in current
OpenStreetMap, so it isn't in the sample.

### The new tags (coverage in the 7 October export)

Share of available records with each tag in the export the sample is built
from (the tags now flow through the real pipeline; not deployed):

| Kind | Records | Outdoor seating | Cuisine | Any diet | Vegetarian | Vegan | Wi-Fi |
|---|---:|---:|---:|---:|---:|---:|---:|
| Cafés | 647 | 48% | 28% | 15% | 14% | 8% | 10% |
| Restaurants | 1,520 | 39% | 65% | 20% | 18% | 13% | 4% |
| Fast food | 715 | 39% | 80% | 25% | 22% | 13% | 4% |
| Bars | 531 | 32% | 4% | 1% | 1% | 1% | 2% |
| Bakeries | 405 | 32% | 2% | 2% | 1% | 2% | 5% |
| Libraries | 65 | – | – | – | – | – | 26% |

Halal, kosher and gluten-free are at 1% or less, and dogs at 0%.

Green spaces: 50 new destinations (23 lakes in the parks bucket, 24 woods and
reserves as "Woods & nature", 3 recreation grounds as parks); with parks they
cover 57 of 86 neighbourhoods. Their points are approximate and they have no
hours or address. Libraries: 65 recommendable, with the closed
Zentralbibliothek and an administrative office excluded.

Verification (7 October, place details): connected 164, Composer 113, Today 44,
Events 53, Paperwork 65, Travel 133, Places 14 and Today C 56 tests pass.
Browser checks at 390px and 1440px cover Composer's restaurant, green-space,
fast food and library answers, Quick look, the map view (one chip row from
1280px, "Open at" beside the count, `kind=green`) and the place sheet. Every
other page's default view is pixel-identical.
