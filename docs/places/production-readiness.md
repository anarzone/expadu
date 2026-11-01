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

## 29 September checkpoint and remaining work

The [29 September release report](staging-release/2026-09-29/REPORT.md) records the
historical result, verified at 2026-09-29T20:05:24+00:00: **all 4,643 prepared changes are
saved and independently verified on staging** (1,874 additions, 2,769 refreshes,
48 operations). The catalogue has 11,802 stored records, 6,574 general discovery
identities and 6,585 actual Composer candidates. Of the changed package, 4,084
identities are Composer-retrievable and 559 descriptive-name records remain held.
Request-specific constraints can further narrow Composer candidates.

PR60 recovery support is deployed and verified on staging. All four previously
held batches passed rollback qualification and committed API/Composer readbacks.
The final read-only check verified all saved mappings, snapshots, journal receipts
and recommendation decisions. No package records remain unapplied on staging.
Normal commit checks passed 1,728 tests / 7,221 assertions (one existing skip);
required PR/deployment CI passed. The controlled same-data API comparison passed
payload parity and the p95 regression guard. Earlier performance investigations,
approvals and partial-rollout states remain historical in the release report.

Remaining acceptance work:

1. Reconcile the reviewed package against production's own current records and
   source evidence, qualify recovery and release mapping, then verify the live
   production release. Production has not been changed by this rollout.
2. Qualify evidence for the 559 descriptive-name records before admitting them to
   automatic recommendations. Explicit activity retrieval must preserve usable
   facilities without inventing a name, access, fee or entrance claim.
3. Establish useful verified free/public football coverage. Existing claim-safety
   tests and truthful no-match answers do not establish coverage.
4. Continue approved-media work and report coverage against a stated eligible
   denominator. This rollout approved no new photos; the 75% objective is unmet.

Every changed record passed the shared facts/API checks and exact Composer
membership checks in its batch; private source receipts remain on the staging
host. Raw staging rows and real user plans were not exported. Data retention,
photo rights, unknown facts and source exclusions remain subject to this contract.

This contract supplements the frozen package and its preparation plan; it does
not rewrite its data, checksums or measured results. It applies to subsequent
batches as well, with no fixed catalogue-size target or photo-based exclusion.

Photos remain optional in the data contract. The connected design record reopened
the separate Places-page direction on 28 September; preserve the prototype until
that product decision is made. The earlier 75% image
coverage objective is still unmet, and this requirement does not waive it.
The current EXP-70 source decision excludes Stadt Köln structured place data as
well as its place media. Hosting municipal records on another provider's domain
does not make them eligible. Excluded data and unapproved images must not be
published or reintroduced through source refreshes. The approved shared design reference is
`prototype/dev/design/connected/design-decisions.md` in the primary design checkout
(the prototype directory is not included in this release branch).


## 30 September preparation checkpoint (before the approved hold)

The [actual staging and source-hold report](production-release/2026-09-30-source-hold/REPORT.md)
adds a fresh READ ONLY check against staging data. All 6,585 currently eligible
rows passed the candidate Places/Composer contract checks; this does not establish
fresh source verification of every field or deployed-version HTTP acceptance.
Of the copied legacy pairs, 463 already resolve on staging and 21 differ from the
current reviewed source projections. All 90 reviewed facilities match actual
staging and native qualification previews, but remain unqualified there.

The staging catalogue includes 4,356 source-null records with no owned fact
observations/corrections. Of these, 2,350 remain eligible under the current policy.
The prepared reversible provenance hold retains all 11,802 stored rows and 798
canonical aliases, but projects 4,224 source-backed general destinations plus 11
linked components as eligible. This is a provenance boundary, not a ready count.
The new operator journal passed native regression tests, an isolated 4,680-row
apply/recover trial and exact staging baseline preparation. Its normal hooks passed
1,763 tests with one skip / 7,385 assertions. It has not been applied to live data.

The current pre-quarantine photo-policy count is 121 of 6,574 general destinations
(1.841%), with no fresh URL-health check in this audit. Post-quarantine photo
coverage has not been measured; do not reuse that numerator with a new denominator.
The 75% target and useful verified free/public football coverage remain open.
PR61 remains draft, with explicit live-operation/deployment approval and actual
running-version acceptance still required. No production data change or new image
approval occurred in this continuation.


## Current checkpoint: staging provenance hold committed

The [30 September applied staging report](production-release/2026-09-30-staging-source-hold/REPORT.md)
supersedes the pre-hold counts above. The owner-approved source-null hold was
committed at 2026-09-30T20:03:06+00:00 and verified in a fresh process. All
11,802 records and 798 alias relations were retained; zero unknown-origin rows
remain eligible. There are 4,224 eligible general destination rows plus 11
components. The running Places list's own filters return 4,139 entries.

Both the candidate and an isolated copy of the actual running staging code
passed the shared contracts for all 4,235 eligible rows. The running-code audit
verified 791 eligible alias API/Composer references and all 3,558 unlinked held
Composer exclusions. A staging apply/recover/retry rehearsal restored its exact
baseline before the commit; scoped recovery metadata and the durable receipt
remain privately on persistent server storage. No raw archive was exported.

Post-hold policy-publishable photo coverage is 119 / 4,224 (2.817%), without a
fresh URL-health check. A corrected football discovery test using Composer's
canonical internal activity `soccer`, rerun at 20:58:18 UTC within 3 km of a labeled sample
city-centre origin returned zero supported candidates, with or without a free
budget. This is not a citywide absence claim.

The running image differs from PR61: held detail links remain accessible, but
its tested twelve held responses do not include the candidate's explicit
unavailable status. Scoped staging code promotion, current facility previews
and combined recovery acceptance, actual authenticated external HTTP checks,
useful activity evidence, media coverage and independently qualified production
release remain open. No 90-facility qualification, source rewrite, media approval,
application deployment or production change was made by this operation.
