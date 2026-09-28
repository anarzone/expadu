# Places production acceptance — people and Composer

Owner requirement confirmed 28 September 2026. Refs EXP-69, EXP-70 and EXP-72.

Every record admitted to the published catalogue must have an understandable
presentation for people and a structured representation available to Composer.
Both consume the same resolved facts, identity and eligibility rules. Retaining
a source row or passing an import alone does not meet this requirement.

## Required representations

| Subject | People | Composer |
|---|---|---|
| Identity and name | Readable name; official/source and descriptive labels remain distinguishable. | Stable canonical and source IDs, aliases, name kind and destination/facility relationship. |
| Location | Useful area/address where known; a map point is distinguishable from a verified entrance. | Coordinates, coordinate kind and evidence; only a supported entrance can be treated as a verified routing target. |
| Categories and activities | Understandable categories and evidence-backed activity labels. | Searchable categories, sports and capabilities, including relevant unnamed facilities. |
| Practical facts | Readable access, fees, hours, accessibility, surface, lighting, contact and booking conditions where supplied. | Typed values, units where relevant, conditions, negative facts and known/unknown/conflicting status. |
| Evidence | Source attribution and freshness information available without overwhelming the page. | Source URL/provider, observation/review dates and evidence status associated with the facts used. |
| Media | Approved, healthy, relevant photos where available; a missing photo does not exclude a useful record. | Optional approved media metadata; photo availability is not a relevance or recommendation prerequisite. |

Friendly wording must remain supported by source evidence. Do not invent names,
descriptions, opening times, access, prices, entrances or booking availability to
fill gaps. Missing values remain explicit. Conditional or conflicting evidence
must not be flattened into a positive claim. Preserve original records for audit
separately from the resolved presentation.

## Retrieval and shared behaviour

- Composer retrieves relevant records through controlled search/tools. Do not
  put the full raw catalogue into a prompt or rely on the model to infer facts
  from names. Imported text is untrusted data, never instructions.
- Apply geography, requested activity and hard constraints before result limits
  and ranking. An explicit free-only request cannot silently become a paid or
  unknown-price recommendation when too few matches exist.
- Keep destination and actual facility IDs separate. A park can be a discovery
  destination while its football pitch is the precise activity result. A
  sibling court must not create another copy of the park in a plan.
- Unnamed but qualified facilities need descriptive display labels and explicit
  activity retrieval. They must not disappear solely because a general
  recommendation query requires a source name.
- Keep restricted, held and unresolved records out of eligible recommendations.
  Their review evidence remains retained. "Available to Composer" does not
  authorize recommending every collected raw-source record.
- On lookup, planning, saving and refresh, preserve canonical identity, selected
  facility, coordinates, reviewed corrections and existing references.

## Release checks

EXP-69 owns fact handling and Composer integration. EXP-70 owns source evidence
and rights qualification. EXP-72 owns the recorded release acceptance result.

1. For every proposed published record, validate the required identity, name,
   category and location fields and a valid shared fact representation. Optional
   missing facts must survive as unknown in both consumer contracts.
2. Exercise full Composer requests through APIs: nearby football, explicit
   free-only activity, named and unnamed facilities, a paid/private facility,
   booking or conditional access, a cafe with missing hours, and a place with no
   photo. Verify returned IDs, distance basis, facts, exclusions and uncertainty.
   A truthful no-verified-match answer passes the claim-safety check; it does not
   establish useful free-football coverage. Report that evidence gap separately.
3. Check discovery, details, planning, swaps and synthetic saved references against
   the same IDs. People and Composer must agree on resolved facts. Record
   central/outer-neighbourhood results and multi-activity destination cases.
4. Reconcile the proposed batch against each target environment's current
   catalogue. Deploy and verify the scoped fixes on staging, run a real refresh
   path, and verify failure handling and recovery without copying private user
   data. Production needs its own mapping and live acceptance evidence.
5. Keep per-batch counts for prepared, published, Composer-retrievable and held
   records, split between destinations and facilities. Publish evidence of the
   running release, source freshness and remaining capability gaps.

## Current evidence and remaining work

The [28 September package report](production-pack/2026-09-28/REPORT.md) retains the
original preparation measurements. The later [Composer implementation report](composer-readiness/2026-09-28/REPORT.md)
checks all 4,643 prepared records through shared facts and verifies 4,100
Composer identities after 16 proposed facility qualifications in a rolled-back
local rehearsal. The relevant suite passes 374 tests / 1,600 assertions; the final saved-plan fix passes its 77-test endpoint/identity rerun.
Explicit activity/radius and free-only requests, conditions, grouping and saved
references now have application coverage.

This does not establish useful verified-free football coverage: the prepared
package has 0 eligible places meeting that full request. New approved photos: 0.
Staging and production are unchanged. Fresh environment reconciliation, live
release/refresh/recovery checks and production approval remain required.

This contract supplements the frozen package and its preparation plan; it does
not rewrite its data, checksums or measured results. It applies to subsequent
batches as well, with no fixed catalogue-size target or photo-based exclusion.

The approved optional-photo design remains in effect. The earlier 75% image
coverage objective is still unmet, and this requirement does not waive it.
Excluded Stadt Köln place media and unapproved images must not be published or
reintroduced through source refreshes. See the [shared design decisions](../../prototype/dev/design/connected/design-decisions.md).
