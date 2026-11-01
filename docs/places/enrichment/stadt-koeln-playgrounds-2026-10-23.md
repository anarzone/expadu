# Stadt Köln playgrounds and ball courts — 23 Oct 2026

**Source:** "Spiel- und Sportplätze in Köln", Amt für Kinder, Jugend und Familie, Stadt Köln
(updated 27 Nov 2025), geoportal layer `freizeit_natur_sport/spielangebote`.
**Licence:** Datenlizenz Deutschland – Zero – 2.0 (no attribution required). Data only: the
dataset's photos are not used (Stadt Köln images stay quarantined).

**What it has:** 705 entries (704 named): type (Spielplatz, Spiel- und Sportangebot, Bolzplatz,
Sportanlage), official name, district, and equipment — football goals, basketball hoops, table
tennis tables, boules, skate elements, pitch surface.

**Method** (`php artisan places:import-koeln-playgrounds`): each entry matched one to one with the
nearest active OSM playground or pitch within 80 m (playgrounds preferred for play areas, pitches
for Bolzplatz/Sportanlage). Recorded as provider `stadt-koeln`:

- `name` — "Spielplatz Marienplatz/Mühlenbach". `PlaceFacts` uses another provider's name only
  where the place's own source gives a generic label ("Spielplatz"); a real OSM name always wins.
- `access` yes (the city's register of its public play areas) — only where OSM says nothing.
- `practical.sport` from the equipment and `practical.surface` — only where OSM says nothing, so
  they never contradict it.

Places named and public by this become suggestible, as the OSM import would decide.

**Result:** 652 matched, 312 gained sports. Suggestible places 5,275 → 5,795: playgrounds
143 → 585, pitches 17 → 59, table tennis 1 → 21, basketball 0 → 11, skateparks 5 → 9.
52 city entries had no OSM place within 80 m (not added).
