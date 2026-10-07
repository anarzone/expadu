# Dayboard — second Today concept

Review at `http://127.0.0.1:8765/dev/design/dayboard/`.

## Direction

Keep the second design’s expressive mixed board and thumb-level cyan Composer. Give it the informative content of the first direction: named discoveries with neighbourhood context, practical service actions, and a saved-day card that reflects the user’s actual choices.

## Content provenance

The displayed place records, neighbourhoods, photography URLs and credits come from the captured app view in `prototype/current/01-today.html`, dated 26 June 2026. These are an archived snapshot, not current recommendations. The preview labels that date prominently.

- Blücherpark — Neuehrenfeld; captured in “Around Ehrenfeld”. Photo: Superbass, CC BY-SA 3.0, Wikimedia Commons.
- Innerer Grüngürtel — Ehrenfeld; captured in “Friday, sorted”. Photo: Carla Kolumna, CC BY-SA 4.0, Wikimedia Commons.
- Events: the captured Events page (`prototype/current/06-events.html`) had zero Today results. The mock offers date exploration instead of fabricated gatherings.
- Weather: the captured Today view reported unavailable. No invented temperature is supplied.
- Departures: no relevant trip or live feed is connected; ask for a stop or destination instead of displaying an arbitrary countdown.
- Bureaucracy: the captured catalogue contains “Register your address (Anmeldung)”. Current approval and the user’s progress are not loaded; no requirements, deadlines or completion figures are inferred.

Source and licence links accompany the photographs. A failed photo loads an explicit unavailable state. No generated scene is presented as a real venue photo.

## Report mapping

[Composer audit](../composer-first/report.md): preserve requests, distinguish service responses, carry selected places into Composer, and save exactly the remaining choices. Quietness and travel time are not inferred from a park category.

[Today needs engine](../../../../docs/today-needs-engine.md): service information must intersect with a person’s actual day. Generic events and arbitrary transit delays should not become urgent tiles. This mock is not the implemented relevance engine; live providers and personal task context remain required.

The archive’s location is data, not city-specific branding. Today Blue is preserved separately.

## Interactions and limits

Keep either real place, submit “Plan these together”, remove a choice and save. The dark day card and Composer context update from those actions. Input focus remains on Today. Events has Today/Tomorrow/Weekend states; unsupported dates ask for fresh listings. Composer matching remains scripted and saved choices last until reload.

Run `node --test prototype/dev/design/dayboard/tests/model.test.cjs` for focused save, routing and constraint checks. Review at desktop and narrow mobile widths before accepting the design.
