# Places with optional photos — EXP-73

Review: <http://127.0.0.1:8765/dev/design/places-optional/>

All photos hidden: <http://127.0.0.1:8765/dev/design/places-optional/?photos=none>

## Decision being demonstrated

Keep real, rights-approved photos when available. Make finding, understanding,
saving and planning a place work without them. A missing image does not exclude a
place, change its ranking or leave a placeholder panel.

The user authorised this separate concept after asking whether Places should be
rethought around the shortage of reusable photos. This is a bounded prototype,
not acceptance of a lower catalogue quality or photo-coverage target.

## Design

The Today C visual language is retained: Geist, pale canvas, white surfaces, dark
text, cyan selections and orange actions. Compact factual rows are the default;
the map shows the same filtered records. Optional thumbnail images are secondary
to names, categories, neighbourhood/address, recorded amenities and actions.

Search, area, travel preferences and sorting remain separate controls. Details
open beside the list on desktop and immediately below the selected row on mobile.
They do not block the rest of the page. Filter changes preserve an unfinished
visit form. Save is distinct from scheduling; a date and time create a proposal
that still needs confirmation in Composer.

Uncertainty stays visible: captured opening hours are not live availability,
coordinates are not verified entrances, and absent entry/access information is
not inferred. No travel time, price, atmosphere or suitability is invented.

## Sample and image provenance

`sample.json` contains 16 real public source records across cafés, restaurants,
parks, playgrounds, culture and activities. Eight have reviewed photographs and
eight deliberately do not. **This balanced design sample is not a citywide
coverage measurement.**

`prepare-sample.py` builds the sample from these existing evidence files:

- `docs/places/cologne-expansion/2026-09-18/places-named.jsonl`
- `docs/places/cologne-expansion/2026-09-24/photo-delivery/reviewed-batch.json`
- `docs/places/cologne-expansion/2026-09-24/photo-delivery/staging-evidence/api-verification-postdeploy.json`

The eight local images are unchanged copies of individually reviewed assets.
Their SHA-256 hashes are checked against that approved manifest on preparation
and during verification. Image details show author, source and licence links;
the thumbnail links directly to those credits. CSS crops the display. Failed
images remove the image area rather than leaving a broken image or placeholder.
No additional provider or Stadt Köln municipal-origin image has been approved.

Each place retains its OSM identifier, coordinates, source link, capture date and
raw fact tags. The page includes OpenStreetMap attribution. MapLibre is copied
from the installed dependency with its licence; the map uses the application's
existing [OpenFreeMap basemap](https://openfreemap.org/quick_start/) with attribution.
The map library loads only when requested. The list remains usable if it fails.

## Existing prototype integration

The new interface lives entirely in `places-optional`. Today C and Places Next
layouts were not edited. Two necessary nonvisual Composer corrections were made:

1. `composer-studio/app.js` retains complete place records brought from a
   department or existing plans. Previously, opening Composer silently removed
   saved places outside its six built-in fixtures.
2. `composer-studio/model.js` accepts those sourced places when creating an
   unconfirmed proposal from Saved. Previously, these selections produced no
   planned item. It carries names, location and provenance into the proposal.

The corresponding script fingerprints were updated in Composer and My Plans.
Regression tests reproduced both failures before the fixes. Existing fixture
behaviour and confirmation gates remain covered by the focused Composer tests.

The existing local prototype storage is shared. Tests use separate browser
contexts and do not alter the user's saved plans. A prepared question opens in
the existing scripted Composer preview; there is no live AI, route calculation,
booking, reminder or outgoing message. This does not deploy application code.

## Verification

Serve the prototype tree from the repository root (port 8765):

```sh
python3 prototype/dev/design/places-optional/tests/serve.py
```

Run verification in another terminal:

```sh
node prototype/dev/design/places-optional/tests/verify.cjs
node --test prototype/dev/design/places-optional/tests/composer-records.test.cjs prototype/dev/design/composer-studio/tests/model.test.cjs prototype/dev/design/composer-studio/tests/integration.test.cjs prototype/dev/design/composer-studio/tests/asset-version.test.cjs
```

Observed on 27 September 2026:

- Twelve interaction/data checks passed, including source hashes, filtering,
  saved records, scheduling, undo, Composer handoff, photo failure, all-photos-hidden
  mode, keyboard return and map failure fallback. No browser runtime errors.
- Forty-six focused Composer and integration tests passed.
- No page overflow at 320, 390, 768 and 1440 pixels. At 390 × 844 the first useful
  place and its planning action fit in the initial viewport.
- The real OpenFreeMap style returned HTTP 200; the map loaded with 16 pins,
  thirteen successful provider responses and no failed responses/runtime errors.
  Routine interaction tests use the actual MapLibre renderer with a local empty
  style to avoid repeatedly downloading tiles.

Machine-readable evidence: `tests/verification.json` and
`tests/map-provider-check.json`. This is focused prototype verification, not an
application-wide test or deployment claim.

The existing local Python preview server had a five-connection queue and
intermittently reset asset requests during cold loads. It was restarted on the
same localhost port and prototype directory with a queue of 128. Verification
also rejects unexpected failed asset requests; intentional photo/map failures
are tested separately.

## What this does not finish

The broader Places catalogue and free-photo sourcing work remain open. No new
production destinations were imported, and the 75% photo target has not been
waived or achieved by this concept. The owner still needs to choose whether to
adopt this presentation and change the rollout criterion explicitly. A live-app
implementation would use the Places API and existing approved-media selector,
pagination and production image delivery, rather than this frozen sample.
