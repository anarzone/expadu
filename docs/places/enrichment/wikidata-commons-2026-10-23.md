# Wikidata descriptions and Commons photos — 23 Oct 2026

**Photos:** `spots:fetch-photos --geo=0` (the existing pipeline; links from the place's own
wikidata / wikipedia / wikimedia_commons tags only, no nearby-photo guessing). Every photo goes
through the rights gate: approved only for licences `config/media.php` allows, credited.
55 waiting photo validations had never run — they sit on the database queue, which no local
worker reads (the preview worker reads Redis) — and were validated directly.
OSM `image=` links stay pending (no licence information). Stadt Köln images stay quarantined.

**Descriptions:** `php artisan places:import-wikidata-descriptions` — Wikidata (CC0) English
description, else German, for linked places that list none, as provider `wikidata`. Skipped:
bare categories, "Wikimedia …" pages, and descriptions that only restate kind and place
("Park in Cologne, Germany"). `PlaceFacts` now keeps the place's own description and lets another
provider only fill a gap, so an OSM refresh without one doesn't erase it.

**Result (suggestible places):** photo 119 → 146 (2% → 3%), description 116 → 139.
Woods & nature: photo 0% → 71%. Wikidata links only cover ~150 suggestible places, so this
step is small by nature; most remaining descriptions are short ("German beer brewery").
