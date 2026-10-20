# Bureaucracy v2 — cutover and recovery checklist

Status: **pre-release draft, 8 September 2026; not permission to deploy**.
This describes the commands and boundaries implemented in
`codex/bureaucracy-backend-v2`. It does not assert that the release gates below pass.

## Preserve

- Account identities, genuine confirmed answers and disputed history.
- Separate family records, accepted scopes and revocation/erasure state.
- New process occurrences, append-only events and scoped evidence.
- Existing task/document progress remains untouched but inert. The owner waived
  transfer; this does not authorise deletion or copying old appointments/submissions.

Never run a fresh migration, truncate, prune people, reset personas on real accounts,
or restore an old database merely to make the new plan look clean.
The legacy `qa:seed-personas` command is not a v2 cutover operation: it still
upserts accounts by email and can replace credentials/profile data. Do not use it
to test or migrate existing accounts. Its provisioning workflow is separate from
the non-mutating preview API and was not changed by retiring the HTTP reset routes.
`user:reset-journey` now refuses a target with either a person or dossier record,
including `--force` and `--keep-tasks`. It remains a legacy-only profile tool,
not a canonical reset/erasure operation. Do not remove this guard during cutover.

## Gates before requesting deployment

1. No unexplained full-suite failures. Focused passes are not a release pass; use
   `docs/verification/bureaucracy-backend-release.md` for current evidence.
2. The separate UI must use the shared plan and explicit commands. The old
   `/bureaucracy` HTML controller still has a competing payload and GET writes.
   `GET /bureaucracy/v2/plan` does not itself replace it. Old QA mutation endpoints
   now return an explicit 410 without touching data. The frontend switcher and
   old HTML demo still need integration with the read-only canonical preview;
   the 410 is not a finished user experience or a rollout flag.
3. Complete source/criteria review of intended units. Compilation validates
   structure, not legal completeness. Keep partial/unsupported gaps visible.
4. Review family/processor notices, retention operations and dependent authority.
   `bureaucracy_family.guardian_policy_version` is null: do not set it without the
   actual reviewed procedure and individual authority evidence.
5. Complete T16 browser/date-input/accessibility verification. Server tests cannot
   certify the visual calendar or mobile navigation.
6. Implement and rehearse the intended cohort/read-mode control. There is currently
   no `config/bureaucracy.php` cutover flag. Do not invent an environment variable
   or treat the JSON route as an activation switch.
7. Obtain explicit deployment/activation authority for the target environment.
   Live AI consent and production migration permission are not implied.

## Rehearsal

Use verified PHP 8.4/Node 22, the isolated test manifest and dedicated test services.
Do not run the test suite against a normal local, staging or production database.
These application commands are read-only in the indicated modes, but still require
an explicitly selected, authorised environment:

```sh
php artisan bureaucracy:migrate-dossiers --dry-run --after=0 --limit=100
php artisan bureaucracy:compile-catalogue --dry-run
php artisan bureaucracy:coverage --manifest
php artisan bureaucracy:coverage --manifest --fail-on-gap
```

The dossier report contains counts, statuses and `next_cursor`, not fact values.
Continue bounded batches from that cursor; investigate mismatches and inactive or
erased records rather than forcing attachment.

The strict manifest audit intentionally fails for partial, unreviewed, unidentified
or empty inventory. Do not relabel units as covered to pass it. An intentionally
partial release needs explicit approved scope and visible gaps, not a claim that
the strict audit passed. The coverage command without `--manifest` still exercises
the legacy path engine and cannot certify v2 legal coverage.

Rehearse interrupted/repeated attachment, source withdrawal, changed facts, family
revocation, process/evidence isolation and question retries. Compare person IDs,
exact actions/dates, uncertainty and progress IDs—not just card totals.

## Future authorised staging cutover

Record the target, exact commit, reviewed catalogue hash and recoverable backup
evidence. Keep personal exports out of build logs.

1. Deploy additive schema and reviewed code. Check workflow results first. Current
   CI imports with `--retire-missing`, not `--prune`; verify this on the deployed revision.
2. Rehearse dossier attachment on the target. After review, run only authorised
   bounded `--apply` batches. This attaches self records; it does not create spouses
   from reported names or transfer old progress/appointments/document readiness.
3. Import reviewed content, validate and stage it. `bureaucracy:compile-catalogue`
   without `--dry-run` writes an immutable release but does not activate it.
4. Activate only the approved artifact through `bureaucracy:compile-catalogue
   --activate --expected-current=<current-hash>`. Use `none` only for a verified
   first activation. A concurrent change needs review, not a blind retry.
5. Do not proceed until the read-mode/cohort control and UI integration are
   implemented and rehearsed. Confirm UI and backend use the same person,
   catalogue and assessment revision. This step is not implemented by catalogue
   pointer recovery alone.
6. Verify authorised running records: known steps, unanswered date, overdue work,
   independent Paperwork readiness and family/revoked access. `/up` is insufficient.
7. Check reassessment/erasure worker retries and suppressed notifications without
   logging personal text. Live AI needs its separate provider/privacy activation.

## Recovery constraints

- Prefer reverting presentation exposure while keeping additive tables and all
  new events. Never silently restore the old decision engine as a fallback.
- An earlier catalogue needs compatible schema/registry and current valid sources.
  Pointer rollback cannot revive withdrawn content or revoked family access.
- Catalogue-only recovery uses `bureaucracy:recover-catalogue --suspend
  --expected-current=<exact-active-hash>`. It clears the active pointer, emits
  durable reassessment work, and preserves releases, facts and process history.
  It deliberately works even if the active artifact is damaged. This is not a
  global maintenance switch: fact edits, privacy controls and orientation remain
  available; legacy consumers still need their separate cutover.
- Restore an explicitly selected immutable release using
  `bureaucracy:recover-catalogue --release=<release-id>
  --expected-current=none` after suspension, or the exact currently active hash
  when replacing a different active release. A concurrent replacement fails
  rather than overwriting it. Same-release retries still check artifact integrity.
  Current source withdrawals remain enforced after restoring the pointer.
- These commands require separate operational authorisation for the chosen
  environment. Do not improvise direct pointer writes or drop migrations.
- If no reader can safely represent current family state, expose unavailable and
  contain affected writes. Retain current facts/events instead of serving an old plan.
- Backup restoration needs separate authority and reconciliation of post-backup
  answers/events/access changes. Never overwrite them incidentally during rollback.

## Evidence

Record environment, commit, runtimes, active catalogue hash, source/notice approvals,
exact test results/artifacts, attachment counts/cursors, backup recovery proof,
observed running revision, unresolved gaps and rehearsed recovery route. Report
backend-ready, UI-integrated and deployed as separate states.
