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
  one kind near a point, as a list beside a map. Kind chips, "Open at 14:30"
  (listed hours), places within 2 km plus a few further away where an area is
  thin. Tapping a row opens the shared place sheet. It is opened from Composer,
  Today and the place sheets; it is not a navigation destination.

## The card and the facts

`connected/places.js` (model and card) and `connected/place-card.css`:

- A **map picture** instead of a photo (`connected/place-maps/<id>.png`, made by
  `tools/place-map-snippets.cjs` from OpenFreeMap tiles; the real app would use
  its self-hosted tiles). Without it, the kind's icon shows.
- The kind, name, neighbourhood and straight-line distance ("about" for the
  middle of a park), today's **listed** hours ("Open until 18:30", "Opens at
  15:00", "Hours not listed") and wheelchair access only when recorded.
- The sheet and Quick look list Today, Address, Wheelchair, Website and Phone,
  plus Access and Entry fee for parks, playgrounds and culture. Unknown values
  read "Not listed". Every view says the hours are listed, not a live check, and
  credits OpenStreetMap.
- Composer's assumed category hours (`hoursAssumed`) never show as open.

## Data

`connected/sample-places.js` holds 47 real places picked by
`tools/sample-places.py` from Expadu's local catalogue export of 1 October 2026
(12,292 entries; not deployed): the nearest pharmacies, cafés, bakeries,
groceries, playgrounds, parks, libraries and restaurants around Neumarkt, Wiener
Platz (the appointment), Rheinauhafen, Stadtgarten and Ehrenfeld, plus the
Events venues. Place data © OpenStreetMap contributors (ODbL). Which kinds appear
where follows the places data review: Today only shows kinds with good hours
coverage; doctors, sports and parks wait for a request.

Regenerate:

```bash
python3 prototype/dev/design/tools/sample-places.py
```

```bash
NODE_PATH=$PWD/node_modules node prototype/dev/design/tools/place-map-snippets.cjs
```

Not yet covered: Wi-Fi, outdoor seating, cuisine, diet and dogs (the places
session is adding them to the pipeline; Composer says Wi-Fi isn't recorded), a
Travel "near this stop" entry, and live opening checks.

### Slots for the new tags (source coverage, 7 October)

Measured on a fresh OpenStreetMap pull joined to the 12,292 entries; the
pipeline doesn't store these yet.

| Kind | Outdoor seating | Cuisine | Any diet tag | Wi-Fi |
|---|---:|---:|---:|---:|
| Cafés | 44% | 25% | 14% | 9% |
| Restaurants | 41% | 70% | 21% | 4% |
| Fast food | 38% | 76% | 23% | 4% |
| Bakeries | 29% | 3% | 1% | 6% |
| Libraries | 0% | 0% | 0% | 20% |

- Restaurants and fast food: cuisine can be a fixed slot on the card.
- Outdoor seating, vegetarian and vegan: a chip only when recorded; a missing
  tag never means "no".
- Wi-Fi, dogs (17 entries in total), halal and gluten-free (1% or less): no
  slot; show only when present. Composer keeps saying Wi-Fi isn't recorded when
  it isn't.
- A fact whose sources disagree has the status `conflicting`; show it as "Sources
  disagree", not as a value or "Not listed".

Collection gaps: three Stadtteilbibliotheken (Nippes, Ehrenfeld, Neubrück) are
being added, and green spaces not tagged as parks (Königsforst, Mediapark, Poller
Wiesen, Decksteiner Weiher, Fühlinger See) plus descriptive names for 215
unnamed parks are in progress.

Verification: connected 159, Composer 112, Today 44, Events 53, Paperwork 65,
Travel 124, Places 14 and Today C 56 tests pass. Browser checks at 390px and
1440px cover the map view, Composer answers, Quick look, Save and Add (day and
time), Today's list and place sheet, and Events venue accessibility. Other pages'
default views are unchanged; Today's desktop view changes only in the "Also in
Mülheim" card.
