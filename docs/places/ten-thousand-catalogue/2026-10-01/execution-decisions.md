# Execution decisions — 10k local catalogue

Plan: `docs/superpowers/plans/2026-10-01-ten-thousand-place-catalogue.md`.

The existing implementation authorization and latest explicit instruction to keep working until 10k govern this extension. Continue inline in the existing isolated worktree; do not request another permission to proceed. The approved UI remains unchanged. A concrete plan and evidence receipts accompany the work.

Pre-flight: category contract produces supported enums/coarse selectors consumed by selection and native import. Selection produces frozen identities/tags/points consumed by source checks; proof consumed by native geometry validation. Combined export must replay previous cohorts without accepting their old runtime manifest as a claim about new code; generate a new runtime manifest. Exact source proofs and baseline inputs remain independently pinned.

The available diagnostic identified 8,420 named new source objects in a strict visitable allowlist and 8,131 after a preliminary identity screen. These are diagnostic counts, not readiness claims; the versioned selector and fresh native run decide the accepted total.

Identity-chain regression passed under the original screening. A preflight inspection nevertheless identified that manual/source-policy held objects should also participate in the conservative ambiguity screen; screening now includes every named valid source object. The preflight selection is retained separately; it was never imported or source-qualified.

Composer vocabulary integration: 11 regressions failed on missing everyday prompt categories, absent provider tool vocabulary, and the market outdoor flag. After the changes, the affected parser/retrieval/enum suites passed 61 tests and 231 assertions. No new category hours defaults were added.

Source proof: 8,117 conservative selections were re-read in 11 bounded cohorts, each retaining the existing 20,000-node cap. 8,100 matched exact source tags/topology. Native PostGIS accepted 7,365 points; 735 cached representative points differed from fresh complete geometry by more than one metre and remain held. Seventeen other identities changed or disappeared. Keep these holds; the remaining supply can exceed the user target without relaxing accuracy.

Verification corrections during first native trial: Candidate tags are the established flattened key and value list plus resolved evidence, so compare preserved source tags against that contract. Fee-only per-record checks must omit a radius because byIds uses its own origin. Protected table comparisons must use the active native transaction connection rather than an independent snapshot connection. These alter verification, not source acceptance or product behavior.

The first native trial was deliberately cancelled through its own advisory-lock-owned PostgreSQL query after the verifier contract mismatch was identified; its finally block restored rows and sequences. The next native bootstrap verified the snapshot before any mutation. The standalone check first hit the default 128MiB limit; its runner now uses the existing 1GiB catalogue-verification limit. Its runtime manifest is retained as preflight-v1, not an accepted result.

The second trial stopped before starting its mutation transaction because the verifier used an incorrect reconciliation table name. The actual immutable snapshot policy names `place_reconciliations`; the protected-table check now uses that exact table. No catalogue data changed in this preflight failure.

Task 1 verification: native parser/retrieval/enum suites 61 tests / 231 assertions; affected Places, identity/import, additional source guards and Composer endpoint suites 219 tests / 1,059 assertions. Python selection, held-facility, production-pack and consolidation suites: 10 + 14 + 15 + 24 tests passed. Formatting and diff whitespace checks passed. No frontend import/export or design files changed.

Final review root cause: compound category synonyms are matched against the complete unchanged text, so shorter words inside a specific venue phrase add unrelated categories. The sports umbrella also inspects the full phrase. Add exact-result regressions for overlapping plural phrases and explicit separately requested categories. Keep the active trial runtime untouched until its successful export/audit and forced-failure recovery finish; then apply the narrow parser fix and preserve the original runtime receipt.

Final review regression evidence: the nine compound-phrase exact-category cases failed in the native test suite; four explicit separate-request cases passed (13 assertions total). A scratch copy of the proposed longest-phrase implementation passed all thirteen pure-parser checks. This is hypothesis confirmation only; the application fix and its native GREEN run remain pending until the frozen catalogue run completes. The shell output wrapper used the reserved zsh status variable after the tests; the test log itself records the nine real assertion failures and is the RED evidence.

Repeat-import investigation: source tag ordering was checked as a possible churn cause. The actual native elapsed-time replay fixture passed immediately once its incomplete observation/setup was corrected (one test, three assertions); no importer defect was reproduced and no importer change is justified. Spot source tags use JSON storage; the earlier hypothesis about JSONB key ordering is not an assertion about that column. The regression retains an explicit later clock and compares all native row fields plus observation history.

The full native trial projected all 7,365 new venues, then hit PostgreSQL shared lock-memory capacity after 3,500 repeat-import records. It produced no accepted export. Independent recovery at 14:16:19 UTC matched all ten original table hashes with zero user rows. This is a local infrastructure refusal, not a source/identity refusal. Preserve its runtime manifest and receipt as capacity preflight.

Ruling: use a task-owned PostgreSQL container bound only to 127.0.0.1:15434, cloning the exact verified dedicated baseline and increasing max_locks_per_transaction to 4,096 with twenty connections — the shared development instance must remain untouched and native record locks must stay enforced — cost if wrong: extra disposable local disk/memory and another full verification run. Apply the already reproduced parser fix before freezing the new runtime.

Final review fix: nine exact-category cases went RED before implementation. Longest overlapping phrases now own their words while separate requests and standalone sports umbrellas remain supported. Native parser/provider/retrieval/enum/source/replay suites passed 83 tests / 266 assertions after the fix. The full new native trial includes this fixed parser and the larger isolated local database; the original capacity refusal runtime remains preserved.

The owned loopback clone was restored cleanly after removing image-default topology/geocoder extensions that were absent from the source database. A readiness race and one refused clean-extension drop were resolved only on this clone; no ignored pg_restore error remains. Its ten-table baseline hash check passed again; shared development PostgreSQL remains running and unchanged. The native runner is now fixed to the owned loopback port 15434.
