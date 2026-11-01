## 30 September 2026 — reviewed facility search and legacy reference recovery

Draft [PR61](https://github.com/anarzone/expadu/pull/61), code commit `026b9995438fe5e6a00957e046f42e619c63948b`. No live qualification, alias link, import, media approval, merge or deployment.

**New behavior:** reviewed descriptive facilities now appear for explicit fine-category requests in Places, the map API and nearby Composer. Mixed park/playground requests do not admit unrelated qualified facilities. Qualifications require current known unconditional public access, track later access review/withdrawal, retain unknown fees and exclude them from strict-free requests. General discovery retains its policy.

**Recovery completed:** the separate legacy-reference journal wraps native reconciliation, validates every pair before any link, binds source proofs and exact protected snapshots, and compensates only expected link/rating fields. It retains source/user/media rows and native audit history. Later drift, incomplete source proof, blank/relative timestamps, unsupported transaction modes, historical aliases or incoming alias references are refused. New-instance apply/recovery replays matched; no real process crash, database restart or committed cross-process recovery was simulated.

| Observed copied-catalogue result | Count / meaning |
|---|---:|
| Stored rows | 12,063; includes retained legacy/context/held rows |
| Prepared source records | 4,719; not all eligible destinations |
| General eligible destinations | 4,155 |
| Extra reviewed facilities during qualification trial | 90: 74 playgrounds, 11 table-tennis, 3 basketball, 2 pitches |
| Eligible total in that facility trial | 4,245 |
| Older IDs linked to existing places in the identity trial | 484; zero extra unique places |
| Existing verified photo associations in separate media trial | 65; separate limited photo checkpoint |

**All-record evidence:** all 559 descriptive sources had current tags and original map points checked. Eleven bounded OSM reads verified 2,442 geometry nodes; 469 unknown-access facilities stayed held. All 90 admitted facilities passed detail, paginated listing, map and nearby Composer checks, strict-free exclusion and recovered unavailability. Recovery retained 180 qualification/revocation audit records before outer rollback.

Every one of the 484 legacy pairs passed actual detail and Composer saved-ID resolution and then recovered held detail/Composer exclusion. Native audit retained all 484 events; outer rollback restored all 14 protected tables exactly and persisted zero synthetic users. Eligibility/general totals stayed 4,155 throughout that separate trial. Fresh geometry verified 312 nodes, 134 representative way points and 38 earlier Overpass bbox centres; exact point guards were preserved and no coordinates moved. This follows screening all 4,680 source-null legacy rows against 7,712 supported OSM records, then checking 546 current nodes/ways and 5,357 geometry nodes.

**Validation:** facility trust suite 201 tests / 1,035 assertions; final legacy journal/native set 100 tests / 439 assertions. Final normal hooks passed 1,744 tests, one skipped / 7,293 assertions plus formatting and secret scanning. Final exact-head CI: [CI36728994522](https://github.com/anarzone/expadu/actions/runs/36728994522) passed Lint, Test and Browser Tests for the exact code commit. Both image builds and both deployments were skipped. Earlier facility commit `c65075fa` also passed [automatic CI36719797988](https://github.com/anarzone/expadu/actions/runs/36719797988). One independent review and one fix pass per phase; no second facility review. Nine legacy review regressions failed before absolute-time and supported-isolation fixes. One deferred Minor is a save-after-link feedback-endpoint regression; both owner IDs are protected and no present ownership/recovery defect was observed.

**Evidence and rights:** detailed source responses and journal receipts remain in the protected existing lab. Only aggregates were exported; 15 existing private files' permissions/hashes were checked in place, with no credential copy. A new private archive was rejected by automatic approval review because its sensitive payload/destination was not specifically authorized; the aggregate/in-place alternative completed. Supplied checksums/attestations do not independently establish provider authenticity. Production catalogue/media fingerprints, all 89 migrations and both live images remain unchanged. Stadt Köln-origin media exclusions and approved rights/health/place-match gates remain; no blanket provider approval and no new image-coverage claim. The former 75% image target has not been achieved; later product decisions make photos optional.

**Remaining release work, in order:**
1. Prepare and authorize the exact staging/deployment target and package. Refresh current source/drift and backup evidence, coordinate writers and verify runtime/helper hashes before an actual data operation; validate overlapping operation families and recovery order rather than assume separate trial receipts compose automatically.
2. Execute only approved target-bound operations; verify Places and Composer through that environment's API. Keep production rollout separate until staging evidence is accepted. The lab count does not establish production readiness.
3. Resolve the 72 held legacy identity candidates (40 native policy, 20 projection differences, 10 relation geometries, 2 changed sources) with current evidence. Broader supported provider checks are needed for insufficient/no-nearby matches; proximity alone does not prove identity.
4. Continue collecting access/fee evidence for the 469 held descriptive facilities and other activity gaps. Unknown access is not public, unknown fee is not free, source centres are not verified entrances. Do not bulk-enable the 4,680 unknown-origin rows.
5. Continue free, rights-verified image acquisition opportunistically. Existing 65 associations remain a separate limited trial checkpoint; do not count missing or unapproved media as coverage.

Implementation/plan/spec/aggregate results and exhaustive decisions: `docs/places/production-release/2026-09-30-facilities/`; operator guides `FACILITY-QUALIFICATION.md` and `LEGACY-REFERENCE-RECOVERY.md`. Keep EXP-69/72 In Progress and PR61 draft: this closes the implemented/rehearsed preparation, not the unresolved data or live rollout.

**Actual staging target check (read-only, 14:33 UTC):** 11,802 stored rows: 7,278 OSM, 168 Overture and 4,356 source-null. Existing facts: 7,810 observations, zero corrections, 798 reconciliations, 12 destination reviews and 48 operation journals. All 99 candidate migrations are already applied; staging has 101 migrations including two additional bureaucracy migrations that must remain preserved. A separate read-only run of the candidate's native SELECT policies found 6,585 eligible place/component rows, grouped into 6,574 distinct destinations (also 6,574 general destinations). Laravel booted only against the isolated lab; staging was read through a read-only handle. These are policy previews, not fresh-source/name/coordinate/media verification, current live API/Composer behavior, or permission to transplant lab IDs. Runtime/helper drift and current staging-native mappings must be checked before a live operation.

---
### Earlier plans and checkpoints (historical; latest status above governs)


