# Production catalogue rehearsal — 30 September 2026

Refs EXP-69, EXP-72. Continues the approved 29 September release plan.

1. Pin PR #61 commit 554eab971d3b2b43ec9312a893d292302bb6b0b3 and the unchanged
   production/staging images. Prepare an isolated application directory on the
   same server, with matching dependencies, private logs and no worker/scheduler.
2. Create a dedicated empty rehearsal database, apply production-baseline schema,
   then copy only places, neighbourhoods, destination boundaries and spot-linked
   media using a read-only production connection. All source rows stay server-side.
   Assert empty account/personal tables in the target; do not read them in production.
3. Run the ten candidate migrations on this populated copy and measure the actual
   eligibility and media effect. Recompute source mappings and duplicate evidence.
4. Keep unproven duplicates held. Rehearse the nonambiguous package against fresh
   target IDs/fingerprints using the existing durable journal/recovery helpers.
   Verify replay, withdrawal/recovery, unchanged unrelated rows and API/Composer
   contracts. Export only aggregate summaries and public source-key hold reasons.
5. Publish exact results, unresolved release gates and the proposed live operation
   in Jira/BookStack. Leave PR draft and production unchanged until final approval.

## Execution ledger

- Preflight: both application images match the prior audit; PHP 8.4.26 on server,
  PHP 8.4.23 and Node 22.23.1 locally. Server/candidate composer.lock hashes match.
- Ruling: use the existing staging PHP runtime with a separate candidate directory
  and database. Dependencies must load candidate application classes, so do not
  symlink the optimized vendor autoloader into the live staging application.
  No runtime application code or configuration is changed.
- Ruling: reuse the existing reviewed importer/journal and source package; regenerate
  all target mappings. Prior staging IDs and receipts cannot qualify production.
- Ruling after the first rehearsal: do not merge the 749 uncertain legacy/source
  pairs. Hold all source-null legacy rows with a separate recoverable journal,
  retain their IDs/references, and refresh the uniquely keyed source records.
  This permits all remaining 1,308 source observations to be rehearsed without
  promoting descriptive facilities or showing the unknown legacy counterparts.
  Cost: legacy-only places disappear from discovery until their provenance is
  established. The exact availability impact must accompany final release approval.

## Completed checkpoint

All 4,643 source records, 48 source operations and the 4,680-record legacy hold
were rehearsed. API/Composer membership, full recovery and four interrupted-prefix
recovery simulations passed. The final catalogue has 4,182 candidates; 69 have a
proved photograph in the rolled-back trial. Fresh screening of 103 older sources
and synthetic held-reference checks exposed the remaining release conditions.
See REPORT.md and STATUS.json. Keep production unchanged and PR #61 draft.
