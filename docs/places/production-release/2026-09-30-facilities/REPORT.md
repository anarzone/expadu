# Facility search and legacy identity recovery — 30 September 2026

Refs EXP-69, EXP-72. Continues PR61 and the earlier source/media recovery work.
The exact tested changes are in the isolated production candidate. Live production
and staging remain unchanged. This is release evidence, not a claim that all
12,063 stored records are usable unique places.

## Newly implemented and verified

Fixed a real discovery omission: native qualified descriptive facilities could
open by ID but could not appear for explicit facility-category requests. Places,
the map API and Composer now include current qualifications for explicit fine
facility categories. Unfiltered discovery and broad park selection preserve their
existing policy. Supported sport, access, fee and condition checks remain in force
for sport requests. Playgrounds do not acquire an invented sport tag.

Added an operator qualification/recovery journal using the native fact review API.
It binds the exact target, package, application/helper hashes, native previews and
fresh source evidence. Apply and recovery are atomic and replayable. Later source,
review, parent, alias or overlapping source-identity changes cause refusal. Source
values, IDs, general recommendation flags and media rights are preserved. Recovery
retains native qualification/revocation audit history and advances fact revision.

Five consumer regressions failed before the search fix. A further regression
proved that a newly inserted overlapping source identity could bypass the old
screen; the journal now rechecks overlaps under locks. Final independent review
identified mixed-category leakage, proof/effective-fact disagreement and access
reviews leaving old qualifications valid. All nine new regressions failed before
the fixes. A mixed park/playground request now admits only the explicitly selected
qualified facility kind; the operator refuses contradictory source access, fees
or map points; and any later access review, including its withdrawal, requires a
fresh public-access qualification. Native qualification requires currently known,
unconditional public access. Combined public-access and qualification reviews
remain atomic and valid; a combined unknown-access review is refused atomically.

The focused and related trust suite passed **201 tests / 1,035 assertions**.
Corrected invalid-batch fixtures now exercise each intended second-record refusal
and its exact reason. One independent review and one regression-backed fix pass
were completed. The operator guide documents proof limits and independently
verified application files; a supplied checksum or geometry-attestation boolean
alone is not evidence. See review-result.md for every review disposition.

## Facility evidence and observed consumer coverage

All **559** prepared descriptive facilities have the same current source tags and
locations. Eleven bounded OSM reads checked source objects and 2,442 geometry
nodes. The 73 midpoint differences were different centre methods, not moved
places: the original area representative points were recomputed and verified.

Exactly **90** pass the public-access cohort checks: **74 playgrounds, 11 table-tennis facilities, 3 basketball facilities and 2 pitches**. All fees remain
unknown. The other **469** retain explicit unknown-access holds.

| Actual trial check | Verified |
|---|---:|
| Place detail API, native facts and availability status | 90 / 90 |
| Paginated Places category discovery | 90 / 90 |
| Map category discovery at each facility | 90 / 90 |
| Nearby Composer category discovery at each facility | 90 / 90 |
| Unknown fee excluded from strict free requests | 90 / 90 |
| Details unavailable again after native recovery | 90 / 90 |
| Native qualification and revocation audit records in trial | 180 |

Eligibility rose from **4,155 to 4,245** during the reversible trial. General
eligible destinations stayed **4,155**. Every source/place/review/operation row
was restored by the final outer rollback; no synthetic user persisted.
Apply/recovery replay used a new helper instance and the database journal. No
actual process crash was simulated. Automated map calls were paced within the
existing 60/minute search limit; middleware was preserved.

## What the older 12k inventory contains

Screened all **4,680** source-null legacy records against **7,712** supported OSM
records from the broader public research inventory. There are **556** unique
bidirectional identity-review candidates with existing source-backed counterparts;
**2,771** other legacy records have only insufficient nearby matches and **1,353**
have no nearby source within this supported OSM category screen. The latter are
not claimed to be absent from Cologne or from other providers.

Rechecked **546** candidate nodes/ways and **5,357** geometry nodes through 16
bounded current-source reads: **544 unchanged, 2 with changed tags**. Ten relation
geometries require separate review. The native reconciliation policy was retained.

**484** links passed current source/tag/point checks, one-to-one identity screening,
native reconciliation guards, the legacy-ID detail API and Composer saved-reference
retrieval. Each old ID resolved to its source-backed counterpart in the trial.
Native identity audit entries were created; every source/place/identity/parent/venue
row was restored by outer rollback. No automatic source assignment or live merge
occurred. These are **484 recoverable older references, zero additional unique
places**. The separate durable operator protocol is now implemented and its all-pair
trial passed: all 484 applied API details and Composer saved IDs resolved correctly,
and all 484 recovered details returned to their original held state with Composer
exclusion. The final review-fixed trial passed at14:16UTC. New-instance apply/recovery replay
matched. Native identity audit entries
were retained; the final outer rollback restored all **14** protected tables and
left zero synthetic users. Recovery preserves source facts, reviews, feedback,
media and saved references; later changes cause refusal. The journal/native test
set passed **100 tests / 439 assertions** after review fixes. Independent review
found blank/relative timestamp acceptance; seven regressions now require absolute
ISO timestamps with explicit timezone and valid dates. Two further regressions
bound drift checks to READ COMMITTED; other isolation modes are explicitly held.
One endpoint-level save-after-link test remains a deferred Minor coverage item;
the reviewer found no current ownership or recovery defect. See legacy-review-result.md.

Fresh geometry proof now uses the documented original point method: 312 source
nodes, 134 representative way points and 38 earlier Overpass bounding-box centres.
Both way methods were independently computed from current complete nodes. A
bounding-box midpoint is not substituted for an area representative point, and
the exact stored/effective coordinate guard was not relaxed. No coordinates moved.
The operator remains bounded to 500 disjoint pairs; aliases with earlier native
audit events or incoming child/reference/fact history require separate handling.

The remaining **72** candidate links stay held: **40** native identity refusals,
**20** differing source projections, **10** relation-geometry reviews and **2**
changed sources. This batch adds no media approvals or free-play/booking claims.

## Release boundary and next work

The catalogue retains **12,063 rows** and **4,719 prepared source records**.
The useful reviewed facility candidate is **4,245 eligible places during trial**;
484 legacy reference resolutions must not be added to that unique-place total.
The existing photo checkpoint remains separate (65 verified associations during
its reversible trial); Stadt Köln exclusions and all media rights gates remain.

Facility independent review and its single fix pass are complete. Facility normal
hooks passed **1,706 tests, one skipped / 7,115 assertions** and exact-head CI
[36719797988](https://github.com/anarzone/expadu/actions/runs/36719797988) passed
`c65075fa`; Lint, Test and Browser Tests passed, while image builds and deployments
were skipped. The separate legacy journal's review and single fix pass are complete. Its normal
hooks and exact-head CI are recorded on PR61 and the linked work-log pages after
commit; this document does not attribute executor checks to the reviewer. Before any live operation, refresh
the intended target's source/backup/drift evidence and approve its exact deployment
and import. Keep EXP-69/72 In Progress and PR61 draft until the release conditions
are met. Continue the identity holds with current evidence; never bulk re-enable
unknown-origin records or infer access, price or identity from proximity.

## Evidence

- PLAN.md, FACILITY-QUALIFICATION.md and review-result.md record intent, safeguards, rulings and review dispositions.
- source-summary.json and target-summary.json contain the 559 source/target checks.
- rehearsal-summary.json records actual facility discovery and native recovery.
- legacy-identity-summary.json, legacy-fresh-source-summary.json and
  legacy-links-summary.json record the initial legacy-reference screening.
- legacy-journal-summary.json, legacy-code-install-summary.json and the separate
  LEGACY-REFERENCE-SPEC.md/PLAN.md record all-pair durable recovery verification.
- Detailed source geometry, target rows and native receipts remain private in the
  existing isolated lab. Existing private evidence permissions and hashes were
verified in place; no private rows or credentials were copied. Automatic approval
review rejected a new private archive because it could include private rows and
credentials without specific payload/destination authorization. Only aggregate
and public-source evidence is exported.

Read-only verification at 14:02 UTC confirmed the original production catalogue
and all 89 production migrations are unchanged. Both live application images
match the earlier checkpoint; see live-preservation-summary.json and
preservation-summary.json. The lab count includes the prepared package and
retained legacy/context rows and is distinct from the live production count.

## Final commit validation

Commit `026b9995438fe5e6a00957e046f42e619c63948b` passed normal secret-scanning,
formatting and test hooks: **1,744 tests, one skipped / 7,293 assertions** in
159.97s. The automatic exact-head PR CI run is
[36728994522](https://github.com/anarzone/expadu/actions/runs/36728994522);
it passed Lint, Test and Browser Tests for that exact head. Both image builds
and both deployments were skipped. Its final outcome is in ci-summary.json. Code/helper evidence is unchanged from the final14:16UTC trial.

## Actual staging target, distinct from the copied catalogue

Read-only checks at14:33/14:38UTC found **11,802** stored staging rows, including
7,278 OSM,168 Overture and4,356 source-null records. All99 candidate migrations
are applied; staging has101 total, including two additional bureaucracy migrations
that must remain. It has798 reconciliations and48 operation journal rows already.
Do not transplant the copied catalogue's numeric IDs or recovery histories.

The candidate's actual native eligibility SELECTs, compiled inside the isolated
lab and run on a read-only staging handle, select6,585 place/component rows and
**6,574 distinct destination groups** (also6,574 general destinations). This is a
policy preview, **not** current staging API/Composer verification or a fresh-source
production-ready total. No Laravel application was booted against staging for
this audit; no live rows changed or private rows were exported. See
staging-target-check-summary.json and staging-native-policy-summary.json.

PR61, EXP-69/72 and BookStack33/36 now have the final implementation, checks,
separate environment counts, unresolved data and release steps. Full ticket/wiki
readbacks preserved prior history; tickets remain In Progress and PR61 remains
draft. See publication-readback-summary.json. Preparation is implemented and
rehearsed; live target mapping, current evidence, coordinated operation/recovery
sequencing, staging API verification and approved rollout remain release work.

GitHub Actions CI is green and the normal pre-commit secret scan passed. The
separate GitGuardian check completed successfully at15:04:55UTC; no security override,
merge or deployment was performed. The overall production release remains open.
All implementation rulings and their consequences are retained in
legacy-execution-decisions.md; the deferred Minor coverage item is in
legacy-review-result.md.


## Later actual-staging and source-hold checkpoint

The [actual-target report](../2026-09-30-source-hold/REPORT.md) supersedes any earlier
pending actual-staging mapping/readback statements in this history. All 6,585
eligible staging rows passed candidate shared people/Composer contracts, 90
facilities passed current mapping/native previews and 463 older references already
resolve on staging. Twenty-one reviewed source projections differ and need refresh.
The complete source-null cohort is 4,356 rows, with 798 existing aliases and 2,350
currently eligible unknown-origin rows. A SELECT-only hold projects 4,224 sourced
general destinations plus 11 linked components, retaining all 11,802 stored rows.

The new reversible source-hold helper is reviewed, committed and pushed at
`70d6581686114428c45d193682b697a11d242a14`; normal hooks passed 1,763 tests, one skip /
7,385 assertions, and exact-head CI/security passed. Its isolated 4,680-row trial
restored state and all 14 checked tables. The exact staging baseline is prepared
read-only. No live hold, merge or deployment occurred. PR61 stays draft and the
current ticket/wiki checkpoint has been fully read back with previous history
preserved. Photo policy coverage remains 121/6,574 (1.841%) before quarantine,
without a fresh URL-health check; post-quarantine coverage remains unmeasured.
