# Places release continuation — 29 September 2026

Refs EXP-69, EXP-72. Continues the [28 September release report](../2026-09-28/REPORT.md).

**The prepared 4,643-record package is still not imported into staging or
production.** The application release is already on staging. The specific
read-only staging comparison remains pending owner approval after automatic
approval review rejected the data inspection and summary export. No alternative
remote inspection was attempted in this continuation.

## Local performance finding

A controlled check reproduced a multi-second slowdown in the expanded local
catalogue, but not the exact earlier 23-second observation. The diagnostic used
the same immutable package and native importer inside a transaction on the
existing isolated public-data copy. No real user accounts or plans were loaded.
It captured the actual food-and-drink first-page and total-count queries from the
Places controller, without an origin and with the synthetic user's empty feedback.

| Local expanded-data condition | Count query | First-page query |
|---|---:|---:|
| Existing statistics, default JIT | 6,200 ms | 5,801 ms |
| Fresh statistics, default JIT | 5,329 ms | 5,465 ms |
| Fresh statistics, transaction-local JIT disabled | 572 ms | 558 ms |

`EXPLAIN ANALYZE` attributed 4.75–5.39 seconds per default query to JIT compilation,
mostly optimization and emission. Updated statistics alone did not remove the
slowdown. The query's estimated cost crossed the threshold that enabled expensive
JIT optimization; the smaller baseline used less costly compilation. This points
to compilation overhead as the dominant measured cost for these two local queries.

The results of each query were exactly identical across all three variants,
verified by checksums. Rollback restored all nine checked tables exactly, including
9,928 baseline places and 4,085 observations. Sequence allocations are not rolled
back. `jit=off` was set only inside the local diagnostic transaction; no persistent
configuration or runtime application code was changed. The frozen package hash is
unchanged.

The baseline controller samples were 1,488 ms for food/drink, 519 ms for pitches
and 661 ms for playgrounds. These include controller/resource processing but not
an external HTTP round trip. The expanded-data table above measures individual
SQL statements, not full API latency. Neither set is p95 or a staging measurement.
The import itself took approximately 415 seconds locally.

Evidence: [measured summary](performance-diagnostic.json). Full query plans and
reproduction scripts are retained locally under
`storage/app/private/places-research/performance-2026-09-29/`; their checksums are
recorded in the summary. They contain the controlled local diagnostic, not a
fresh staging export.

## Release implications

1. Keep the target performance gate open. Do not describe the diagnostic
   `jit=off` sample as an adopted or verified live fix.
2. After the approved staging comparison and environment reconciliation, measure
   the default target queries with current statistics. If the same compilation
   overhead occurs, prepare and review a narrowly scoped remedy, preserving every
   eligibility, identity, feedback and grouping condition. Validate pagination,
   representative filters and Composer before rollout.
3. Use at least 30 comparable target reads for the existing p95/regression gate.
   Do not disable a database-wide setting or hide a regression by quoting only
   the diagnostic variant.
4. Continue with server-side rehearsal, recovery evidence, deterministic canary,
   remaining package and live verification once the required gates pass.

The living [production acceptance document](../../production-readiness.md) now
correctly distinguishes the verified staging application release from the
unimported data package, and the current 4,084 Composer-eligible records from the
older 4,100 result that included provisional qualifications. It also states that
EXP-70 excludes Stadt Köln structured place data as well as media.

No new place identities, activity qualifications, photos or verified free/public
football matches were published. The 559 prepared records outside automatic
recommendations, 10,234 other held candidates and unmet 75% photo objective remain
open. EXP-69 and EXP-72 remain In Progress.
