# Staging verification helpers — 29 September 2026

These files preserve the exact reviewed helpers used for this staging check.
They run from the application directory and require an explicit private input
directory. They are verification artifacts, not application routes or scheduled
imports. None commits catalogue data.

- `column-snapshot.php` writes native PostgreSQL column text for nine catalogue
  tables to a private server-only archive. Raw values must stay on the server.
- `column-restore.php` verifies the pinned archive by reconstructing temporary
  tables, comparing every column value and rolling back. It never overwrites
  live tables. Foreign keys, application triggers and full-database recovery are
  outside this temporary reconstruction check.
- `candidate-performance.php` compares the deployed baseline with the expanded
  catalogue using the candidate controller only inside its verifier process.
  It asserts each selected controller, fixes request time, isolates cache and
  error reporting, rolls back the import and restores baseline statistics.
- The two `Performance*.php` classes are mechanically renamed application source
  copies. `candidate-code-manifest.json` records original and executed hashes.

The separate performance cleanup helper remains on staging with SHA-256
`07a11abbfa9a48ebed6aa69ce17d02573bd7da262c85e88752fde1a660b1cc2d`.
The runner invokes it even after a bounded main-process timeout. The runner
also verifies eight deployed source hashes before and after the comparison.
Receipts live one directory above. Preserve executed bytes when reproducing;
any change requires a new checksum and review. Sequence allocations and
cumulative analysis statistics are not transactionally restored.

The first grouping candidate result is retained even though its speed gate failed.
The later `batch-v2/` artifacts additionally measure keyed lookup and detail latency,
compare a detail payload, and bind a mechanically renamed PlaceFacts
subclass inside the verifier only. The manifest includes the two unchanged
controller/grouping copies in this parent folder. Copy all three class files into
the explicit private input directory for that run. The first batch-policy shape
was abandoned after its local expanded-data profile exposed a planner regression;
its interrupted staging cleanup is recorded separately.
