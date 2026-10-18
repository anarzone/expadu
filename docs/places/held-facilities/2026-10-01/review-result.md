# Independent review and fix disposition

The single fresh-context review examined commits `1f4c992..6c101dc3`, the plan, private source evidence and native safeguards. It found **one Important issue, no Critical issues and no deferred Minors**. Its verdict was local-preparation completion with fixes, without any live-release approval.

## Finding fixed

Fresh way reads were compared with one another but not against the original frozen node sequence. A change made before both fresh reads could pass if the representative point did not move. Both the Python fetch guard and native PHP guard now require the exact frozen node sequence and refuse missing/empty topology.

Evidence:

- New Python regression reproduced four refusal failures (changed, missing, empty and null frozen topology); all 14 selection/source tests now pass.
- Native regression initially needed the complete database-default fixture, then reproduced the actual defect with 3 incorrect acceptances and 1 valid acceptance. After the fix, all 4 cases pass / 7 assertions using the real PostGIS/native guard.
- The reviewer independently inspected all 542 selected ways and found no existing artifact mismatch. The final verification reran both tightened guards over all 600 selected records; native checks ran in an explicit READ ONLY transaction. Prepared records are identical to the completed rehearsal, and the original 4,927-record export hash remains unchanged.
- Source, selection, runtime and export hashes plus all baseline table checks still pass. `source-guard-verification.json` pins the revised native guard and read-only recheck.
- The fix does not change the application runtime, import payload or consumer output. The full successful source/apply/replay/recovery trial is preserved rather than overwritten. Normal commit hooks verify the final fix's full test suite; exact counts are recorded in `verification-summary.json`.

The implementer accepted the Important severity and fixed the finding in one regression-backed pass. No second reviewer was dispatched.

## Scope decisions for the review's declined items

- Live apply, deployment and committed-process crash recovery remain release gates. This local rolled-back proposal does not establish them; treating it as a live operator would risk unrecoverable partial updates.
- Full LLM conversations, events and live authenticated HTTP behavior remain separate acceptance work. This evidence establishes native place-candidate contracts; claiming more would overstate integration coverage.
- Fresh photo health and new media rights remain open. No acquisition/approval occurred; publishing new unverified assets would violate the unchanged media gates.
- Earlier dependencies were reviewed in previous passes. Relevant integrations were inspected and the full suite runs here, but those whole reviews were not repeated; previously unknown dependency defects remain possible and live acceptance remains required.

The full execution decisions are retained in `execution-decisions.md`. EXP-69/ 72 remain In Progress.
