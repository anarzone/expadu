# Places field guide: implementation handoff

> Integration correction — 27 September 2026: use the current
> [`places-next/`](../places-next/README.md) composition and
> [shared design decisions](../connected/design-decisions.md). The separate Guide
> shell, adjacent detail pane and native date/time controls are historical. Reuse
> the existing shared shell, anchored discovery controls, three-section place
> sheet, date/time inputs and Composer launcher when porting the content model.

Approved direction: a separate Places discovery section that works with existing
place facts and optional, approved photography. Composer remains an optional
planning companion. Reference: EXP-73 and the owner’s 27 September conversation.

## What is implemented in the preview

| Surface     | Behavior                                                                                                                                                                                                                            |
| ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Discovery   | Three prominent collections show real named choices immediately; four smaller collections offer further ways to browse.                                                                                                             |
| Categories  | Everything, Eat & drink, Outdoors, Culture, Sport & play. Café and restaurant records are included.                                                                                                                                 |
| Search      | Matches all words against normalized names, aliases, streets, recorded areas, postcodes, types and amenity facts. Accents and German ß are normalized. Includes places without media.                                               |
| Area        | Independent compact scope control. Matches recorded neighbourhoods or postcodes exactly; missing areas are not fabricated.                                                                                                          |
| Results     | Alphabetical ordering, or explicitly selected examples first inside a collection. Twenty-four rows per batch. Counts derive from the selected snapshot and filters. No popularity, open-now or travel-time sorting is invented.     |
| Detail      | Adjacent desktop pane; in-page mobile view with Back and Escape. The underlying search, collection and loaded batch are preserved. Unfinished visit fields survive closing, reopening and return from Composer in the same session. |
| Save        | Stores the full source record as a saved idea. Does not create a scheduled visit, confirmed plan or reminder.                                                                                                                       |
| Add to plan | Choose date/start/end, validate against the shared proposal, add an unconfirmed visit, Undo or review in Composer. Source identity and facts stay attached.                                                                         |
| Directions  | Starting point and mode use the existing shared preferences. The explicitly labelled external Google Maps action uses the destination coordinates. OpenStreetMap shows the exact recorded point. No live route is simulated.        |
| Media       | Approved existing photo in details with full attribution, source and licence links. Missing, disabled or failed images remove the image block completely.                                                                           |
| Failure     | Catalogue load failure has Retry; empty search suggests a broader scope; storage failure remains visible.                                                                                                                           |

The prototype deliberately uses a recorded JSON snapshot and browser storage.
It does not import new records or replace the production application’s data layer.
External directions open only on the person’s explicit action; the preview does
not issue third-party searches or routing requests while browsing.

## Collection definitions

Membership uses facts belonging to the primary source, not another facility in
the same group. Counts below describe this frozen design snapshot only.

| Collection          | Required evidence                               |
| ------------------- | ----------------------------------------------- |
| A little fresh air  | `leisure=park` or `leisure=garden`              |
| Coffee outside      | `amenity=cafe` and `outdoor_seating=yes`        |
| Room for discovery  | `tourism=museum` or `tourism=gallery`           |
| Find your next read | `shop=books` or `amenity=library`               |
| Time to play        | `leisure=playground`                            |
| Vegan on the menu   | Food place plus `diet:vegan=yes` or `only`      |
| Go for a swim       | Swimming pool, swimming area or water park type |

Membership is not a recommendation, live availability check, price guarantee or
accessibility assessment. “Quiet,” “best,” “family friendly,” “open now” and similar
claims require separate evidence and are not collection rules here.

## Production contract and files to change next

The following is a prepared implementation sequence, not a claim that these live
app changes have already been made. The workspace has unrelated changes in these
files; preserve them when moving the approved design into the application.

1. **Extend the eligible catalogue without changing destination identity.**
   `app/Enums/SpotCategory.php`, catalogue import configuration and
   `app/Http/Controllers/Api/PlacesController.php` must consistently admit the
   approved dining and cultural types. The current controller validates a leisure
   category list and does not accept the guide’s four groups, free-text query or
   collection keys. Retain `recommendationEligible()`, `parent_spot_id` and the
   venue/facility grouping rules. A park with multiple sports remains one
   destination with activities; do not merge separate businesses by name alone.
   Coordinate source coverage and reconciliation with EXP-68/EXP-72.

2. **Add the guide query and collection contract to the existing API.**
   Extend `GET /api/places`, rather than filtering a partial loaded page in the
   client. Validate `q`, activity group, collection key, area and sort. Return
   server pagination and counts after the same eligibility and identity rules.
   Collection membership must use source-backed facts; a collection count cannot
   count child facilities separately. Provide collection summaries through a
   small, cacheable response or companion endpoint. Keep city scope configurable.
   Extend the existing detail endpoint instead of introducing another place ID.

3. **Make the fact contract honest before reusing it.**
   `app/Http/Resources/PlaceResource.php` currently has category-based open/free
   defaults and generic tips. Do not use these as verified collection evidence or
   show them as checked live facts. Add provenance and freshness to useful facts
   (source ID, source URL, captured/verified time), website, structured address,
   fine category and aliases. Keep unknown opening, price and access values null.
   Do not pass raw source HTML into the page. Translation should retain the source
   language and original text when an English description is unavailable.

4. **Carry the complete approved media contract.**
   Continue using `PublishedMediaSelector` with approved rights and active health.
   Extend `resources/js/components/places/types.ts` to include the response’s
   photo source/licence URLs and the required licence/credit metadata. Remove any
   unreviewed legacy-photo route from this new view. Stadt Köln municipal-origin
   images remain excluded without documented permission. The empty-media design
   is not permission to approve uncertain rights or close EXP-70/EXP-71.

5. **Port the visual surfaces to shared application components.**
   Reuse the approved app shell and tokens. Suggested component boundaries:
   `PlacesDiscoveryControls`, `PlaceCollection`, `PlacesResults`,
   `PlacePreview`, `PlacePhoto` and the existing visit-selection flow. Replace the
   current page composition with the approved field guide after design review.
   Keep one page heading and no widgets rail. On a typical phone, the first named
   place and its action must be visible without scrolling. Search, scope and sort
   remain independent controls. Show collection reasons as plain language.

6. **Connect real persistence and journeys.**
   Map Save to existing place feedback/bookmark persistence; preserve distinct
   Save versus Add-to-plan semantics. Use canonical server IDs in Composer,
   Saved and plans, never prototype `sample-*` IDs. Carry date, times and source
   facts into an unconfirmed proposal. Reuse the application’s shared starting
   point, transport mode and routing service, preserving exact destination
   coordinates. Offer the external route as a clearly labelled fallback if real
   routing is unavailable. Preserve query, result position and detail state on
   return, including browser/mobile navigation.

7. **Release through staging with evidence.**
   Focused API regressions must cover dining eligibility, collection predicates,
   null/negative amenities, duplicate facilities, pagination totals, photo rights,
   stale hours and scope. Verify authenticated Save and Composer handoff through
   their actual APIs, then check the final mobile composition. Run required build
   and CI checks before an authorized staging deployment. Record staging counts
   separately from snapshot counts. Production rollout remains a separate action.

## Existing API → guide mapping

| Existing response                                                         | Guide use / required addition                                                                                  |
| ------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| `id`, `name`, `lat`, `lng`                                                | Canonical identity, title, map point; retain source identities and aliases separately.                         |
| `category`, `fine_label`, `activities`                                    | Map fine types into display groups without losing child-activity semantics.                                    |
| `veedel`, `park`                                                          | Recorded location context; add structured address/postcode. Do not infer a neighbourhood from a missing value. |
| `facts`, `feature_chips`                                                  | Render only supported facts; add machine-readable evidence for collection queries.                             |
| `opening_hours_text`, `open_now`, `price_text`                            | Show raw/parsed recorded hours with freshness; remove category-derived certainty from guide use.               |
| `summary`, `tip`, `tip_is_generic`                                        | Use sourced factual summaries; exclude generic tips from evidence and recommendation claims.                   |
| `photo_url`, `photo_attribution`, `photo_source_url`, `photo_license_url` | Optional photo block after the approved/active media gate, with the complete credit and licence.               |
| `feedback_state`                                                          | Persisted Save state, separate from draft or confirmed plans.                                                  |
| `distance_*`, `transit_hint`                                              | Only display when returned by the real service with a valid origin; do not infer from layout fixtures.         |

## Files delivered in this revision

- `index.html`, `style.css`, `app.js`: separate interactive field-guide concept.
- `model.js`: data-driven collection rules, filtering and safe supporting links.
- `prepare-catalogue.py`, `catalogue.json`, `catalogue-manifest.json`: reproducible
  frozen data preparation with original source IDs and reviewed photo hashes.
- `tests/model.test.cjs`, `tests/verify.cjs`, `tests/verification.json`: behavior
  checks and observed results; desktop, mobile and no-photo-detail screenshots.
- Shared `connected/store.js`: one additional allowed return route for this guide.
  Existing destinations stay unchanged. Eight consuming pages receive only the
  new store fingerprint; accepted layouts and previous concepts are preserved.

The prepared snapshot remains provisional and is not a substitute for validating
names, current businesses, coordinates and parent relationships in the live
catalogue. Optional photography removes a presentation dependency; it does not
establish that the earlier 75% photo-coverage target has been achieved or waived.
