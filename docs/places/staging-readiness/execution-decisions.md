# Execution decisions and costs

These are the exhaustive ledger rulings, in order. No deferred Minor findings. The one Important review finding was fixed, not waived.

1. Ruling: continue the existing authorized implementation without repeating a plan approval or selecting a new design — scope is the tested backend port; cost if wrong is an unwanted local draft branch, reversible before release.

2. Ruling: use the latest human-provided project rules and native tools instead of stale copied external-delegation/runtime instructions — preserves current authorization and PHP 8.4; cost if wrong is workflow preference divergence, no deployed behavior change.

3. Final: Ruling: frontend rendering was outside review — the API explicitly reports unavailable; keep presentation open until the current-design client uses it, because this scoped backend port changes no UI — cost if wrong is users missing a new field until client work lands.

4. Final: Ruling: authenticated deployed-server acceptance was outside review — isolated kernel probes prove proposed source against staging data, not public-server authentication or release; keep that acceptance gate open — cost if wrong is an untested deployment integration.

5. Final: Ruling: media growth, useful free-football coverage and pending facility approval were outside review — preserve measured shortfalls and require separate evidence-backed data work, with no inflated readiness claim — cost if wrong is unmet breadth/coverage despite a correct backend.

6. Final: Ruling: direct manual imports/seeds were outside review — retain explicit commands under the documented reviewed-run procedure; schedule/startup gates are not a universal writer lock — cost if wrong is operator recreation of held records.

7. Final: Ruling: historical alias-ID responses were outside review — preserve existing canonical route binding and saved-reference resolution as required, rather than change identity semantics — cost if wrong is an alias ID becoming its existing canonical ID for API clients.

8. Final: Ruling: the reviewer did not independently certify remote/full-suite execution — executor observed commands and safeguards, commits retain aggregate evidence; describe review and execution verification separately — cost if wrong is reliance on executor-observed rather than independently repeated runs.

9. Ruling: align only the CI browser runner calendar with config/app.php rather than change unrelated bureaucracy code — a pre-existing UTC/local-month-boundary failure blocks required release checks; cost if wrong is altered date-dependent test expectations, with product rules and runtime unchanged.
