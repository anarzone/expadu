# Held public facilities — local preparation only

Continue the approved local-first catalogue preparation. This directory selects exact existing OSM facility identities held solely by overlaps with unavailable legacy rows. It never merges those rows or turns public access into a free-fee claim.

## Run order

Use the existing `codex/EXP-69-staging-readiness` worktree. Python 3 and the repository's PHP 8.4/Herd runtime are required. Raw inputs and outputs stay in the ignored primary-checkout directory `storage/app/private/places-local/2026-10-01/held-public-facilities/`.

```sh
python3 -m unittest discover -s docs/places/held-facilities -p 'test_*.py'
python3 docs/places/held-facilities/run.py select
python3 docs/places/held-facilities/run.py fetch
python3 docs/places/held-facilities/run.py freeze-runtime
python3 docs/places/held-facilities/run.py rehearse
python3 docs/places/held-facilities/run.py verify
```

Selection, source proof, runtime and a successful export refuse overwrite. Preserve them before preparing a new dated run. `select` and `fetch` are already completed for this cohort; do not repeat them over existing evidence. `fetch` performs bounded public OSM reads; rehearsal runs with outbound requests blocked.

The rehearsal uses only the loopback `exp69_local_catalogue_20261001` database, an unsaved synthetic user and isolated array cache. It acquires the shared rehearsal lock before baseline and sequence capture. Refresh, qualification, replay, consumer checks, combined export and qualification reversal run inside one outer transaction. The outer rollback restores original rows and sequence values. A failed run removes its partial export; its exception still runs rollback and sequence cleanup. This is not a durable live apply command or a committed-process crash-recovery guarantee.

## Evidence boundaries

- Fresh node/way source objects, full geometry nodes and repeated way topology must agree with frozen evidence. Current geometry must reproduce the selected point within 1m.
- A source-backed stored representative point may update by at most 25m; all measured updates in this cohort are under 12m. These are source points, not verified entrances. The native importer preserves prior source observations.
- Active/source-backed competitors, missing ambiguity edges, changed identity/category, active reviews, newer or withdrawn source history, conditional/restricted access and conflicting fees remain held.
- No legacy identity is reactivated or merged. Multiple sports remain capabilities of their single record. Existing unrelated records, aliases, memberships and media are retained.
- “Public” describes access. Missing fee evidence remains unknown and fails a strict-free request. Only explicit `fee=no` without conflicting charge/conditional evidence can pass free discovery.
- The old immutable runtime manifest remains historical evidence. This pass creates a separate manifest after the reproduced JSONB approval replay fix.

The dated report and aggregate receipts state the achieved scope. The 23 unresolved source/category/large-point cases are a queue, not an accepted update package. Full LLM conversations, live deployment and media acquisition are outside this local check.
