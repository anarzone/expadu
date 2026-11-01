# Existing-photo recovery — 30 September 2026

Refs EXP-69, EXP-72. Continues [the source correction checkpoint](../2026-09-30-corrections/REPORT.md)
on [draft PR #61](https://github.com/anarzone/expadu/pull/61). No production merge,
deployment, live import or live media approval took place.

## Newly completed

Implemented and rehearsed the missing recoverable operation for the already
verified photos. It refreshes current Commons metadata, validates actual image
bytes, records the place-match review, and saves original/updated database state
atomically. Repeated execution returns the saved result; undo restores original
media while retaining health and review audit history. Later changes cause refusal
instead of being overwritten.

Independent review found two issues and both were reproduced and fixed: shared
photo owners outside the package must be included in recovery guards, and later
records must not silently replace a photo already checked earlier in the batch.
The operation now guards all 218 relevant owner/family rows in this catalogue and
checks selected heroes after the whole batch. It refuses other owner types and
remains bounded to 100 associations and 2,500 rows per media table/owner closure.

This is an operator helper, not an application endpoint or automatic source of
new photos. The reviewed public-source evidence remains an explicit input.
The caller verifies application files; a supplied application hash is not itself
proof of the installed build. The rehearsal verified all 467 relevant application
files before execution. The guide documents writer coordination, table locks,
source-proof requirements and conservative refusal on unrelated media drift.

## Observed results

| Check | Result in isolated production-copy lab |
|---|---|
| Existing photo source references checked against OSM | 73 unchanged |
| Fresh Commons metadata files | 73 |
| Eligible associations successfully applied in trial | 65 |
| Ineligible associations deliberately left held | 8 |
| Real API details showing exact photo and rights fields | 65 / 65 |
| Held API details showing no photo | 8 / 8 |
| API details hiding photos again after recovery | 65 / 65 |
| New audited match reviews / byte validations in trial | 65 / 65 |
| Apply replay and recovery replay | Identical saved receipts |
| Final outer rollback | All media, audit, operation and place rows restored |
| Persistent approvals added | 0 |

The three official OSM multi-fetch requests returned 200 after the Overpass
endpoint timed out. The procedure follows the documented
[OSM multi-fetch API](https://wiki.openstreetmap.org/wiki/API_v0.6#Multi_fetch).
Raw source responses and full recovery receipts remain private on the server;
exports contain aggregate evidence. Recovery was exercised through a new service
instance reading its database journal. **No actual process crash was simulated**
and the outer transaction was never committed.

The complete media group passed **118 tests / 514 assertions**, including
**19 helper regressions / 64 assertions**. Initial failures were observed before
implementation and before the review fixes. Normal commit hooks passed **1,675 tests / 6,987 assertions**, formatting and
secret scanning. Commit `75b2e6f8adf88894aa949ca4de876838fc39f51d` is pushed
to the existing draft PR. [Exact-head CI](https://github.com/anarzone/expadu/actions/runs/36699234712)
passed Lint, Test and Browser Tests. GitGuardian passed; image builds and both
deployments were skipped. See [status](STATUS.json) and [CI receipt](ci-summary.json).

## Counts and unresolved coverage

The catalogue totals do not increase in this checkpoint: **12,063 stored records,
4,719 prepared source records, 4,155 eligible Composer candidates**. The 65 eligible
existing photos correspond to **1.56% of 4,155**, only during the reversible trial.
This work makes those associations safely executable and recoverable; it does not
claim new photo acquisition or achievement of the earlier 75% objective. Stadt
Köln-origin exclusions and all licence, health and place-match gates remain enforced.

Also audited the 559 held descriptive facilities in the existing source package:
90 report public access (74 playgrounds, 11 table-tennis facilities, three basketball
facilities and two pitches); 469 have unknown access. **All 559 have unknown fees.**
This is an inventory of the prepared evidence, not a fresh public-source check.
It adds no qualifications and cannot establish free football availability.

Remaining release work is the sports/playground provenance and availability
decision, then refreshed target evidence, live drift/backup checks and explicit
approval of the exact production deployment/import. The separate 4,680-record
unknown-origin legacy hold is still lab-only; do not silently apply that reduction
to production or call stored records ready. EXP-69/72 stay In Progress and PR61
stays draft. No-photo product/design decisions do not make uncertain facts known.

## Evidence

- [Plan and review rulings](PLAN.md), [application file manifest](code-manifest.json).
- [Fresh source checks](fresh-source-summary.json), [full operation/API/recovery result](media-journal-summary.json).
- [Held facility evidence](held-facility-evidence-summary.json).
- [Read-only live preservation](live-preservation-summary.json), [private archive receipt](preservation-summary.json).

The live production table fingerprints and 89 migrations are unchanged. Both live
application images are unchanged. The private archive uses mode 0600 inside its
existing 0700 directory; credentials and raw catalogue rows were not exported.
