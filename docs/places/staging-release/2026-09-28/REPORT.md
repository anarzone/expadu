# Places release and data review — 28 September 2026

Refs EXP-69, EXP-70, EXP-72.

**The application changes are deployed and verified on staging. The 4,643-entry
prepared package has not been imported into staging or production.** Current
staging data reconciliation remains pending explicit permission after automatic
approval review rejected both a raw export and a summary-only comparison.

## Application release completed

[PR #57](https://github.com/anarzone/expadu/pull/57) merged into staging as
`f81e2d3bfb597aec7050a3b87f72ed51a8b29cd4` at 19:12 UTC.
[CI run 36470573038](https://github.com/anarzone/expadu/actions/runs/36470573038)
passed lint, application tests, browser tests, image build and staging deployment.
Production deployment was skipped.

At 19:21 UTC, the running staging image was
`sha256:6d15ded6d90a7fa3b587107fe7e0728444131fca7eb18292accb3c135ac339a6`.
Six deployed Places/Composer files matched the tested source; the application
health endpoint returned HTTP 200. The existing container probe targets `/`
instead of `/up`; that separate probe configuration was not changed.

The released behavior keeps free-only, activity and radius constraints through
Composer; supplies the same practical facts and provenance to Places and Composer;
requires audited qualification for otherwise hidden facilities; and revalidates
saved visits against current facts. Older saved snapshots without the original
constraints require fresh composition while retaining their references. See the
[implementation report](../../composer-readiness/2026-09-28/REPORT.md) for the
full test record and the unchanged prepared-package acceptance results.

This continuation verified the running application through code checksums and its
health endpoint. It did **not** inspect current staging records or establish new
live catalogue/API counts. Evidence: `release.json`.

## Full-package staging rehearsal prepared and verified locally

The renewed summary-only staging check was rejected before execution. Automatic
approval review did not accept the owner's “go” as specific authorization for
staging data inspection and summary export. The exact-scope approval question is
still pending. No alternate execution path was used. See `approval-status.json`.

Independent preparation is complete: the new transaction-only rehearsal pins the
reviewed package, importer, manifest and expected fingerprints. It has no commit
mode. Its four guard probes reject a commit flag, the wrong target, modified
executable importer and modified identity fingerprints. Local source review found
no remaining material issues after input, visibility and error-isolation fixes.

The full native run against the existing isolated public-data copy passed:

- **4,643 records verified:** 1,874 created and 2,769 refreshed inside the transaction.
- **4,084 prepared identities available to Composer**, without new activity
  qualifications; 559 remain outside automatic recommendations. The earlier
  4,100 result included 16 provisional qualifications, which are not applied here.
- Zero losses of ordinary or activity visibility. Every existing place ID,
  grouping relationship and media reference was preserved. Unrelated places and
  existing observation history stayed unchanged.
- Eight Places category endpoints and 28 detail samples passed. Every returned
  Composer candidate used the same resolved facts as Places. Synthetic saved
  references remained intact; existing private accounts/plans were not loaded.
- **Repeat import made no changes; rollback restored all nine checked tables
  exactly.** PostgreSQL sequence allocations are not rolled back or reset.
- Forty-six prepared candidates satisfy the explicit free filter. Verified
  free/public football remains **zero**. These are local preparation results,
  not newly published coverage or guarantees of opening/availability.

Evidence: `local-native-rehearsal.json`, `rehearsal-guard-checks.json` and
`rehearsal-code-review.json`. No photos were added or approved.

One local Places pagination query was still active after 23 seconds, with no
blocking session. This was an expanded-data transaction before a fresh statistics
pass, not a staging or production latency measurement. Target performance must be
measured before a production-ready claim. See `local-performance-observation.json`.
Repository checks initially hit PostgreSQL shared-memory/lock capacity when run
alongside the long rehearsal. The standalone rerun passed **1,677 tests, 7,009
assertions and two skips**, plus secret scanning and formatting. Commit-message
formatting was rejected afterward; normal hooks are being rerun with the corrected
message. No hooks were bypassed or server configuration changed. Run heavy checks
sequentially. See `repository-checks.json`.

## Duplicate review completed locally

The first pass screened 2,428 held existing identities and proposed 624 pairs from
the retained public baseline. A native transaction-only trial found that 611
would lose activity recommendation visibility if merged into their hidden OSM
counterpart. Those operations were not applied to any live catalogue.

Independent review found another weakness: matching two old database copies does
not prove continuity with the newer source name. In particular, “Cuja Coffee” /
“No Depresso” and “Meinstein Coffee” / “Meine Ecke Specialty Coffee” need separate
rename-versus-replacement evidence. They remain held.

The corrected proposal requires matching current retained source names as well
as category, practical tags, unique pairing and coordinates within one metre.
It produces 12 candidates. Native checks hold one because visibility would be
lost; the separate 11-pair subset passes both ordinary and activity visibility,
old detail links, synthetic saved references, idempotent replay and exact table
rollback. No place IDs are deleted and no unrelated user or media rows change.

These 11 operations are **local proposals requiring fresh target reconciliation**.
They are not 11 newly published destinations. Evidence:
`identity-proposals.json`, `identity-rehearsal.json`,
`identity-safe-operations.json`, `identity-safe-rehearsal.json`.

Superseded 624/13-pair outputs are retained under `superseded-review/` with explicit
warnings. The original 624-pair experiment checked activity visibility; it predates
the additional ordinary-browse check and must not be described as covering both.

## Football source leads remain held

The retained whole-city OSM inventory contains 365 pitch records with the exact
`soccer` activity token. None contains an explicit `fee=no` tag. Of these, 20 have
`access=yes`; access and price are separate facts. A substring search would also
include two table-football records and incorrectly report 367 football pitches.

Seventeen public NRW-hosted listings were inspected for limited fee, audience,
activity and location evidence. All are published by the municipal provider
excluded under EXP-70. A different hosting domain does not change the provider
decision. Their free/all-age metadata also does not establish unrestricted public
access, booking requirements or current availability.

The source preflight now accepts **zero** fact reviews from these listings and
does not start catalogue mutations. No new verified free-football coverage is
claimed. Four specific OSM geometry checks remain research evidence only; no
provider map point is substituted for a source coordinate or verified entrance.

An earlier local experiment using inferred access was interrupted before its API
checks completed. A subsequent read-only comparison verified every row of all
five frozen tables against the original baseline: 9,928 spots, 86 neighbourhoods,
4,085 observations, zero corrections and one revision row. No synthetic users
remain. This is an exact local rollback check, not a live staging audit.

Evidence: `football-source-evidence.json`, `football-geometry-review.json`,
`football-rehearsal.json`, `interrupted-experiment-rollback.json`.

## Package and release boundary

The frozen package remains unchanged: 4,643 prepared entries, comprising 3,908
destinations and 735 facilities; 1,874 new identities and 2,769 refreshes. These
are preparation counts, not a current production denominator.

- No package import, identity reconciliation or fact review from this continuation
  was committed to staging or production.
- No private staging records were exported. The pending request permits only
  counts, checksums and conflicting public place IDs, with raw records staying
  inside the server.
- No images were downloaded or approved by this continuation. The earlier 75%
  photo-coverage goal remains unmet; optional-photo design does not waive it.
- The application release is complete. EXP-69, EXP-70 and EXP-72 remain In Progress
  because data promotion, useful evidence coverage and production verification
  remain open.

Next: obtain permission for the already prepared read-only staging comparison;
reconcile the immutable package; rehearse it on the server with backup/recovery
evidence; import a canary; verify Places and Composer APIs, references and source
refresh; then expand the unchanged manifest. Production is a separate reviewed
release. Do not force stale matches, promote excluded sources or treat the local
identity subset as a citywide completion result.
