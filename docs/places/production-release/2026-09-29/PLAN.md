# Production catalogue qualification — 29 September 2026

Refs EXP-69, EXP-70, EXP-72. The owner requested production validation after the
4,643-record staging package was completed. Staging storage and eligibility counts
are not production readiness counts.

1. Identify the running production image and actual deployment workflow. Read only
   whitelisted catalogue schema, source totals, migration names and application
   hashes. Do not load users, saved plans, sessions or credentials into outputs.
2. Reconcile frozen public source keys against production using a bounded read-only
   transaction. Export counts, hashes and conflicting public source identities;
   retain raw production rows on the server. Never reuse staging numeric IDs or
   assume a matching name establishes identity.
3. Identify application/schema dependencies and compare the proposed production
   release with main. Keep unrelated product work out of a scoped Places release.
4. Classify all 559 package holds against available source evidence and existing
   qualification rules. Preserve unknown access, fees and entrances. Do not admit
   facilities merely to increase counts or fabricate human-reviewed evidence.
5. Prepare a concrete release mapping and recovery/acceptance plan from observed
   production state. Qualify it locally and on staging before any live mutation.
   Production deployment/import is a separate concrete final action after these
   checks; this investigation does not mutate production catalogue data.
6. Publish exact eligible/held counts and remaining blockers in Jira/BookStack and
   this report. Keep photo coverage and useful free-football coverage separate.

Read-only audits use a direct PDO connection from existing cached configuration,
without booting application providers. Enforce production environment/database,
REPEATABLE READ READ ONLY, lock/statement timeouts and whitelisted catalogue tables.
No raw production catalogue or user data is exported.

## Execution findings and decisions

- Production image and source hashes match main `8f7eb597`, last CI deployment
  run 31958321403 from 16 August. The September workflow against the same commit
  was an unrelated manual staging command, not a production deployment.
- Read-only comparison completed: 8,798 stored records; 1,378 unique source-key
  matches and 3,265 absent keys. 1,193 matched refresh IDs differ from staging.
  Old-schema fingerprints are diagnostic and must be rebuilt after migrations.
- Existing identity rules found 749 matching source records with unknown-origin
  legacy duplicates. These are review holds, not automatically merged records.
  3,335 named package records have no detected identity ambiguity; this is not
  production eligibility acceptance. All 559 descriptive-name facilities lack
  fee evidence; 90 have raw public-access tags. No free claim is qualified.
- The new main-based worktree isolates the 16 Places/media source commits from
  unrelated staging product changes. Keep media policy documentation, omit the
  unrelated bureaucracy test edit. The development card fixture retains required
  Place-contract fields, without changing its layout. Documentation
  conflicts caused by omitted staging checkpoint commits use their reviewed
  source versions; no runtime conflict resolution was necessary.
- Ruling: add default-off automation and curated-startup-seeding gates. Existing
  startup seeding can recreate renamed places by old name without provenance.
  This violates the frozen catalogue requirement; an opt-in retains the deliberate
  seed path. Eight catalogue/photo schedules are also gated until target acceptance.
  Cost: those automated jobs remain paused until an operator explicitly enables
  them; the release runbook must make that state visible.
- The entrypoint already migrates before Supervisor. The initial reviewer concern
  about serving new code before migrations was withdrawn after checking startup.
  It also seeds bureaucracy/system data, so deployment effects are wider than a
  catalogue import. No deployment or seed was run against production.
- Baseline initially lacked local PostGIS/vector extensions and a local .env file.
  After fixing the isolated test setup, 229 tests / 750 assertions passed cleanly.
  Both new protection defects were reproduced; the fix passed 3 tests / 25 assertions.

- Code review caught monthly boundary jobs bypassing the gate; both now use it,
  bringing the catalogue/media schedule count to eight. Older serialized media
  jobs now restore with compatible defaults, keep their legacy unique lock, and
  validate current input under the existing concurrent-change guard. Rights are
  never approved by health validation. The review regressions failed first, then
  passed 15 tests / 95 assertions; re-review found no remaining important issue.
- TypeScript exposed an overstrict scope exclusion: the development card sample
  must carry the required Place interface fields. Restored only those data fields
  from the reviewed source commit; its layout and interactions are unchanged.
