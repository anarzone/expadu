# Production Places release gates

Refs EXP-69, EXP-70, EXP-72. This is a release candidate, not production approval.

## Candidate and target

Use the scoped `codex/EXP-72-production-qualification` branch based on production
main `8f7eb597b073c025fb8ab0f0011eb1313657d6ac`. Do not merge all of staging.
The running production image is recorded in `runtime-schema.json` and
`reconciliation.json`. Recheck the target before any operation; earlier source-key
comparisons are diagnostic, not an executable production mapping.

The reviewed code adds identity, destination membership, shared facts, media match
review, acquisition/revalidation history and durable catalogue recovery. Existing
media attachments migrate to pending match status. That intentionally prevents
unreviewed photographs from publishing but can remove existing visible photos.
Record that impact before releasing; never bulk-approve attachments to preserve a
coverage number. Stadt Köln place data and images remain excluded.

## Controls during qualification

Keep both settings false until target acceptance:

- `PLACES_AUTOMATION_ENABLED=false`: pauses the eight scheduled boundary,
  catalogue, photo acquisition and photo revalidation commands. Monitoring and
  unrelated event/transit schedules continue. This is not a global write lock:
  manual commands, queued jobs and application writes need separate coordination.
- `PLACES_CURATED_SEEDING_ENABLED=false`: startup skips legacy name-only place
  seeds, which otherwise can recreate renamed records. Explicit seeding remains
  available for deliberate development setup.

The container entrypoint migrates and runs DatabaseSeeder before starting
Supervisor. Other startup seed actions still exist, including bureaucracy import
and a system user. Do not run the entrypoint as a supposed migration-only command.
The deployment workflow also invokes migration/import after a fixed sleep; this
redundant invocation is an operational risk to consider before release, not proof
that the new app serves before migration.

Old queued photo-validation payloads are supported by the candidate. They retain
their old unique-lock identity, snapshot the current asset on validation and use
the same stale-response guard. Health validation does not approve rights. Review
queue state and avoid overlapping qualification with unrelated writes.

## Required acceptance before live import

1. Pass the scoped branch's build, PHP tests, lint and required CI browser checks.
   Record the exact commit/image. Keep the pull request draft until the data and
   media gates below are satisfied.
2. Rehearse all required migrations against an isolated catalogue on the server.
   Keep user tables empty; export summaries only. Measure new-policy eligibility,
   media visibility and API/Composer behaviour on that catalogue.
3. Reconcile every public source key again after schema preparation. Generate
   production-specific IDs, fingerprints and recovery journals. Never reuse the
   staging mapping, IDs, journal UUIDs or old-schema row hashes.
4. Keep the 749 possible legacy duplicates held pending identity evidence. A
   similar name near the same point is not proof of identity. Keep the 559
   descriptive-name facilities available for evidence work; the current package
   does not establish their free-access claims. The 3,335 other named records
   are a qualification cohort, not a promised production-eligible count.
5. Rehearse import, retry, source withdrawal and complete restoration. Test the
   existing source references, shared facts, discovery/detail contracts and actual
   Composer candidate membership. Exercise free-only football, private/paid,
   conditional access, missing hours and no-photo cases. Report truthful no-match
   results separately from useful activity coverage.
6. Record exact approved/held counts, checksums, mapping and rollback evidence in
   Jira and BookStack, then review the concrete production operation. Capture a
   production backup and recheck drift immediately before applying it.
7. After authorized release/import, verify the running image and live APIs, exact
   journal snapshots and real candidate membership. Enable only the automation
   workflows whose source, identity, recovery and media behaviour passed.

## Recovery and stop conditions

Stop on source-key ambiguity, drift, unexpected rows, missing evidence, request
contract mismatch or a failed recovery check. Do not broaden matching or relax
rights/access rules to meet a count. Catalogue recovery uses reviewed durable
journals; schema rollback is not a data recovery tool. The catalogue-operation
migration explicitly refuses rollback after its journal is populated.

A successful branch push, migration or row-count increase is not production
acceptance. The 75% photo objective and useful verified free-football coverage
remain open requirements with separate denominators.
