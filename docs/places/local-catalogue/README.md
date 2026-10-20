# Local Places catalogue workspace

This workspace keeps the complete research inventory, the current application baseline and the validated candidate catalogue distinct. It performs local preparation and rehearsal; it does not deploy or apply changes to staging or production.

## What is here

- `registry.sqlite` preserves every collected OSM, Overture and Wikidata source row, plus exact application links, held records, aliases, containment and identity-review candidates.
- `catalogue.json` is the private catalogue-only staging snapshot from 1 October. It excludes user data and historical operation journals, and redacts administrative identities.
- `candidate-places.jsonl` is the private export of the combined locally verified catalogue. Each row contains the native Places resource and Composer place candidate, including explicit unknowns.
- `supported-package/` contains the dated supported-source additions, unchanged-source index, review holds and a checksum manifest.
- `2026-10-01/` contains aggregate evidence suitable for Git. Original source responses, target packages, catalogue exports and local logs remain ignored under the primary checkout's private storage.

The full research collection was captured on **28 September 2026**. Only the existing 559-facility cohort was independently refreshed from the public OSM API on **1 October**. Neither number establishes a count of unique destinations.

## Operating the workspace

Use PHP 8.4 from Herd, Python 3 and the dedicated loopback database `exp69_local_catalogue_20261001`. The wrapper reads local connection settings without printing them. Never point this workflow at an application database.

From the worktree:

```sh
export PLACES_SOURCE_ROOT=/Users/anar/Projects/Own/Startups/expadu-app
python3 docs/places/local-catalogue/run.py verify-evidence
```

The snapshot and registry are immutable inputs. Existing outputs are refused rather than replaced silently. Preserve an earlier package in a clearly named diagnostic directory before deliberately preparing another.

The preparation order is `snapshot`, `setup`, `restore`, `consolidate`, `verify-rebuild`, `query-checks`, `verify-native`, `prepare`, `rehearse`, the public facility source fetch, `rehearse-facilities`, `rehearse-combined`, then `verify-evidence`. Source fetch requires `PLACES_LOCAL_PRIVATE` to name the private dated workspace. Do not repeat the snapshot or setup for routine verification.

The local research query is available through `query.py --help`. It is explicitly labelled research, not production Composer retrieval. Its results are source records and may overlap. Possible identities are queued for review, never automatically merged.

## Trust and release boundaries

- Unknown cost, access, hours and availability remain unknown. Public access alone does not prove free use.
- Descriptive facility labels are distinguished from source names. Source points are not asserted to be verified entrances.
- Existing aliases, holds and destination relationships survive unchanged.
- A capture already represented in the native catalogue is recorded as unchanged; it is not reimported because of fractional timestamp or empty JSON formatting differences.
- The media publication selector remains authoritative. No provider approval, new Stadt Köln source or image approval occurs here.
- Composer verification covers **place candidates**, not events or full LLM conversations.
- Qualification evidence expires and must be checked again before any live apply. The private candidate export is a local proposal, not a deployed database or a forever-current catalogue.
- The baseline is restored after every rehearsal, including local sequences. The exported candidate catalogue retains the verified trial result for inspection.

See [the dated report](2026-10-01/REPORT.md) for the measured counts and remaining work.
