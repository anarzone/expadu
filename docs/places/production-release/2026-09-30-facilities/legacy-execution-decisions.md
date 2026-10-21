# Legacy execution decisions and deferred check

All rulings from this plan, in recorded order, including consequences and limits.

- Ruling: keep native reconciliation audit events immutable, refuse aliases with historical native events — native alias ID is unique; overwriting the event would hide history — if wrong: add a separately reviewed continuation protocol before reusing a recovered alias.

- Ruling: snapshot relevant review/feedback/media rows as drift guards, never restore or move them — alias resolution changes user-facing identity without requiring reference rewrites — if wrong: tighten the release scope before live execution.

- Ruling: use one bounded operation of at most 500 reviewed pairs for this 484-pair cohort — separate operations sharing ancestor families can retain audit events that invalidate earlier recovery snapshots — if wrong: tighten the live transaction scope or partition by disjoint identity families before rollout. The copied-catalogue trial stays atomic and rolled back.

- Ruling: recovery restores the alias pointer while retaining its post-link timestamp, and restores the canonical's original rating/updated_at — only the known native change is compensated, no entire row rewrite — if wrong: a separately reviewed timestamp policy can change metadata without changing source facts.

- Ruling: use the original representative point method for fresh way-geometry proof — a bounding-box midpoint and a point on the actual area differ without source movement; independently recompute from all fresh nodes — if wrong: retain a hold, never move or silently repair the target coordinates.

- Ruling: independently compute both documented source-centre methods and require an exact match to the existing canonical and effective point — the catalogue contains earlier Overpass bbox-centre imports and newer representative-point imports; do not invent a new point or relax the helper tolerance — if neither matches, hold the pair. Centre proof still does not establish a verified entrance.

- Final: minor (deferred): add an endpoint-level save-after-link regression; current code snapshots alias+canonical owners and has no observed defect, so no speculative implementation fix.

- Final: Ruling: non-default transaction isolation from declined review is Important — a caller's old repeatable snapshot cannot establish current drift; explicitly require READ COMMITTED used by the trial — if wrong: separately prove another mode before allowing it.

- Final: Ruling: earlier unchanged phases keep existing independent reviews/CI — review their interactions, do not repeat completed facility work — if wrong: any new runtime change must add its own regression and review scope.

- Final: Ruling: private authenticity and helper checksums are distinct — executor fetched current public source/tag/geometry evidence; reviewer inspected code/aggregates only — if wrong: live operation must be held for independently verified source evidence.

- Final: Ruling: new-instance database-journal replay is the observed durability boundary — no real crash/restart/cross-process committed recovery simulated — if wrong: do a separately authorized durability drill before making stronger claims.

- Final: Ruling: live deployment/backup/privilege/writer and contention checks remain release gates — no live execution in this phase; source freshness, exact target and coordinated writer conditions require a current approved operator run — if wrong: keep PR draft and prohibit the live operation.

- Final: Ruling: broader/history/reference migration cases stay held — bounded protocol refuses them instead of guessing undo — if wrong: add a separately reviewed continuation before reusing them.

- Final: Ruling: no new media rights, entrance, access or fee claim — reference links affect identity only — if wrong: withdraw the unsupported fact via its native review protocol.

- Final: Ruling: tests/CI evidence is executor-observed, not reviewer-run — reviewer was read-only/no network/no database execution — if wrong: correct publication to the exact observed checks.
