# Free provider access setup — EXP-70

Latest update (2026-09-23): the full NRW feed is retrieved (2,348 mixed records; 2,148 place records; 244 with image links). ODG delivered the key by email, but access receives Cloudflare 1010 / HTTP 403. The support reply remains an unsent draft at the owner's request. See `../../2026-09-23/tourism-feeds/REPORT.md` for the complete comparison and limits. Earlier status below records the September 19 setup, not the current blocker.

Status (2026-09-19): Mapillary remains operational. Data Hub NRW registration completed with owner-authorized contact details and explicit terms/privacy approval; the Cologne licence key is stored privately and the generated API endpoint returned HTTP 200. Open Data Germany acknowledged the API-key request; no key has been issued on screen. See `mapillary/REPORT.md` for measured Mapillary results and image-quality limitations.

## Mapillary

Developer dashboard: https://www.mapillary.com/dashboard/developers

The owner is signed in as `anarzone`. App `28746308695006643` is registered with READ only. The token is saved in `storage/app/private/places-research/mapillary.env` (git ignored, mode 0600). Keep passwords and tokens out of chat and source-controlled reports.

Registered application name: **Expadu Cologne Places Research**.

Registered purpose: **Evaluate public street-level imagery for matching photos to Cologne places in Expadu. Measure actual venue visibility, preserve attribution and licence evidence, and keep uncertain matches out of publication. Read access only; no uploads or account-management features.**

After sign-in, inspect the actual application form and request only the minimum access needed for public imagery. The existing project reads `MAPILLARY_TOKEN`; do not create a second integration or overwrite unrelated configuration. A registered application/client token is required according to the official MapillaryJS setup documentation: https://mapillary.github.io/mapillary-js/docs/intro/try/

## Data Hub NRW

Form: https://tourismusverband.nrw/themen/datenmanagement/open-data-finder

Observed step 1 fields: salutation, surname/first name, organisation, email, postal code/city of organisation, user type, terms and privacy consent. **Start-Up** is an available user type appropriate to the stated project; the actual legal/contact identity must come from the owner.

After registration, select Cologne city coverage. Retrieve all relevant POI, accommodation and dining categories with their media and per-asset rights fields. Keep Stadt Köln municipal-origin data/images excluded. Retain the source identifier, name, address, coordinate provenance, updated date, image URL, creator, exact licence and credit.

Free API access and the contact/consent requirement are documented at https://tourismusverband.nrw/allgemeine-geschaeftsbedingungen-data-hub-nrw . The owner explicitly approved acceptance and submission on 2026-09-19. Selected Landkreise → kreisfreie Stadt Köln, with no further municipality restriction. The final step issued a licence key, stored in `storage/app/private/places-research/nrw.env` (git ignored, mode 0600; containing directory 0700). Application runtime configuration was not changed.

The provider-generated destination.one / ET2014A JSON query for all data types returned HTTP 200 and status OK: 100 records on the first page, with `overallcount = 2354`. This is a mixed source-record count, not a deduplicated place count or verified image coverage. The first response includes coordinates, media URLs, source credits and licence labels. Full pagination, type separation, exclusion of municipal-origin content, destination matching and per-image checks remain outstanding. The response is held privately in `storage/app/private/places-research/nrw-first-response.json`; no images or records have been imported or approved.

## Open Data Germany

Request form: https://open-data-germany.org/en/request-access/

Submitted on 2026-09-19 using the owner-provided contact identity, Expadu, **Companies and tech service providers**, and **API key** only. The owner explicitly approved the terms/privacy consent, spam check and submission. The site confirmed **“Thank you for your inquiry.”** and reset the form. This establishes receipt, not account approval or operational API access. No key was displayed; the provider's response remains pending. No MCP server access was requested.

## Measurement after access

Use the unchanged 12,857-group offline catalogue and the saved Cologne boundary. Report source inventory separately from matched distinct destinations. Split results by shopping, dining, sights, culture, sport/leisure, accommodation and other categories. Deduplicate building/venue and activity/component records. Camera proximity alone is a lead, never a usable-photo decision. Apply actual subject, currentness, licence, attribution and health checks before counting any photo as publishable. Run the initial acquisition offline; record provider limits and resumable progress. Stop on authentication/rate-limit denials rather than retrying indiscriminately.

Owner contact details and submission approval have been supplied. No further identity input is needed for the submitted forms. Keep personal contact details and credentials out of tracked research reports and ticket comments.
