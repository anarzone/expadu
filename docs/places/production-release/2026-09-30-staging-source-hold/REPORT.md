# Staging source provenance hold — 30 September 2026

Refs EXP-69, EXP-72. **The approved staging hold was committed at
2026-09-30T20:03:06+00:00 and independently verified in a fresh process.**
This is an applied data change, not a new claim that the whole inventory is
production-ready. Production was not changed.

## Result

| Measure | Before | After |
|---|---:|---:|
| Stored place records | 11,802 | 11,802 |
| Records with no source provenance | 4,356 | 4,356 retained, all held |
| Eligible unknown-origin recommendations | 2,350 | 0 |
| Eligible general destination rows | 6,574 | 4,224 |
| Eligible place/component rows for Composer | 6,585 | 4,235 |
| Existing source-null canonical aliases | 798 | 798 retained |

The 4,235 eligible rows comprise 4,067 OSM and 168 Overture records; 11 are
linked components. The running Places list returns **4,139 entries** through its
own category/display filters. That list count and the general eligibility count
use different query contracts and must not be conflated.

The operation changed only `is_active`, `is_recommendable` and `updated_at` for
the complete source-null cohort, advanced the native fact revision, and inserted
one recovery journal. No places were deleted or added. All identity links, names,
coordinates, source-backed rows, fact observations/corrections, destination
reviews, park relations and venue relations passed preservation checks. The
current venue count was 293, rather than the earlier audit's 292; the owned
source-null package still matched exactly. No source matching was broadened.

## Verification against the actual target

The exact 4,356-record staging package remained unchanged. Apply, a new-instance
retry, recovery and a recovery retry passed on staging inside an outer rollback.
The original flags/timestamps and every protected catalogue digest were restored.
The subsequent approved apply committed only after the post-hold eligibility and
preservation checks passed. A fresh-process verification read the committed
receipt, passed replay checks and issued **zero data-write statements**.

All **4,235** eligible rows passed shared Places resource and Composer-by-ID
contracts in both the candidate and an isolated copy of the running staging
source code. Names were non-empty readable strings, map/routing values matched,
facts were shared, unknown fees remained unknown, and published heroes satisfied
the rights/health/provider gates. This does not establish current source
verification of every record or field.

The running-code check verified **791** eligible legacy aliases through the
detail API and Composer. All **798** alias relations were retained; seven point to
currently ineligible canonical records and are not claimed as usable candidates.
All **3,558** unlinked source-null records were excluded from Composer-by-ID.
Twelve held detail links remained HTTP 200 with their original identity.

The earlier candidate-only checks did not prove running-version acceptance. This
continuation explicitly compared the running files and checked their API kernel
in an isolated directory, booted first against the dedicated empty-user lab and
then connected to staging through a guarded READ ONLY snapshot. No environment
file or cached secrets were copied into that source-code copy. A synthetic user
was never saved, real user/review rows were not read, and application writes,
reconnections and external services were refused.

Actual HTTP checks returned **200** for container and public `/up`, and **401**
for unauthenticated `/api/places`. Authenticated external HTTP payload acceptance
was not exercised; the authenticated payload checks used the isolated API kernel
with the running source and real staging data. The running image was not replaced.

## Media and useful factual coverage

On the resulting data, **119 / 4,224 general destinations have a
policy-publishable hero (2.817%)**. This is a fresh post-hold count, not the older
121 numerator reused against a smaller denominator. This operation acquired or
approved no photos and did not freshly validate their remote URLs. Stadt Köln
provider exclusions and the approved-rights/active-health gates were preserved.
The earlier 75% objective remains unmet.

Across the 4,235 eligible rows, access is public for 123 and unknown for 4,112.
Fees are free for 49, paid for 37 and unknown for 4,149. Those are field states,
not promises about every requested activity.

The football discovery path was checked in both code versions at 20:58:18 UTC
with the canonical `activities=[soccer]`, `categories=[pitch]`, a 3 km radius and a clearly labeled
sample Cologne-centre origin. It returned **zero** candidates with the free
budget and **zero** with that budget filter removed. The safety checks passed,
but useful public-football coverage in that scenario remains absent. This sample
does not establish a citywide absence of football places.

Correction: the first check used the unsupported internal activity label
`football`. Its zero results did not verify Composer's football request path.
The original summaries are retained as `*-football-unsupported-label-summary.json`;
the canonical `soccer` rerun is the evidence for the results above. The source
hold and photo counts did not depend on this activity check.

## Runtime and recovery

The reviewed helper belongs to candidate commit
`70d6581686114428c45d193682b697a11d242a14`, whose required CI passed. The running
staging code is different: 40 running PHP files differ from the candidate and
`config/places.php` is absent. The same post-hold fact counts and eligibility
passed on both versions, but all twelve held-detail responses lack
`recommendation_status` on the running version. The candidate correctly reports
`unavailable` for all twelve. The reviewed explicit-facility search and newer
qualification checks also still need a scoped staging code release.

The older scheduler has no global places pause setting. No catalogue importer,
seed or migration process was running at the check. The bounded operation used
table writer locks with a 10-second timeout and rechecked its complete package
under those locks. Unrelated schedules continued. A later import, source edit or
restart can change the recovery prerequisites; recovery must recheck drift and
must not overwrite such changes. This operation did not enable or disable a
global scheduler.

The reviewed package, committed receipt and exact executed helper/operator are
retained privately on persistent server storage with mode 0600. They contain
owned recovery flags/time, IDs and digests; no raw table archive was made or
downloaded. The native database journal is retained as well. Recovery was tested
against the same staging baseline before commit; a committed crash/restart
recovery drill was not simulated.

Operation UUID: `b501a02d-730c-4cfa-9d06-f68d40356524`.

| Bound value | SHA-256 |
|---|---|
| Reviewed staging cohort | `5993618124c8f226bce574dc8347385694366c8c4f1f34e15f8703b98a6c9e72` |
| Candidate runtime | `8889773f5f3b539f5fbce4a67f8e5560bac68dc80600cce57c187479aeb16c72` |
| Source-hold helper | `98c03f5e62ceedae24ca4da7c8217d0fa812051e4caad1ead272c64b36cb1399` |
| Committed receipt content | `14ccfeb8991beaa89093767fe0d70ea7d027e208604743880de37b550818049b` |
| Running staging runtime | `df5d5376d4f9cc0e322ff42e3e61467b08515546663aa731e73893809eb9055b` |

## Remaining work

1. Port the tested place-read, qualification and automation controls into the
   current staging branch without replacing its unrelated design/bureaucracy
   changes. Obtain approval for the concrete code release, then verify the
   authenticated running APIs, including unavailable detail status.
2. Prepare fresh post-hold previews for the 90 source-reviewed public-access
   facilities, rehearse combined operation/recovery ordering and review the
   concrete qualification request. All 90 remain unqualified on staging; their
   fees are unknown. The 21 changed legacy source projections also remain open.
3. Improve source evidence for access, fees and playable activities, including
   useful public/free football coverage. Source-null records can return only
   after supported identity and provenance are established.
4. Continue free, rights-approved media acquisition and fresh URL-health checks;
   report every coverage claim against its stated denominator.
5. Reconcile and qualify production independently, including its own backup,
   mapping, recovery, release approval and actual running API acceptance.

PR61 remains draft and EXP-69/EXP-72 remain In Progress. The staging provenance
hold is complete; the broader production and coverage goals remain open.
