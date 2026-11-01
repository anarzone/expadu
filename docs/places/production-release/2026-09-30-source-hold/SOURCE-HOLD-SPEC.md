# Source provenance hold specification

The existing production-readiness plan excludes source-null places from discovery while preserving every ID and saved reference. The earlier rehearsal implementation is isolated-lab-only; provide a separately reviewed operator helper for a real target, without executing it on staging or production in this task.

- Target the complete current source-null cohort, bounded to 1–5,000 spots. Reject partial, changed or oversized cohorts.
- Change only `is_active`, `is_recommendable` and `updated_at`; keep both flags false. Preserve every other place field, canonical pointer, incoming relationship, source fact and native audit.
- Do not read or copy real user records, review text, media bodies or credentials into the journal or exported evidence. Hash protected source/history rows in place; retain only owner IDs, original flags/timestamps and digests.
- Require a caller-owned READ COMMITTED transaction, target database, exact helper/runtime/package SHA-256, UUID, actor and a documented reason. Lock writers before the current-state check.
- Persist apply and recovery receipts in `place_catalogue_operations`. A new helper instance can replay the same applied request or recovered request. A recovered UUID cannot apply again.
- Recovery restores only owned flags/timestamps when the complete current snapshot matches the recorded after-state. Source binding, extra unknown records, owned evidence or relationship drift must refuse atomically. Retain the journal and monotonic facts revision.
- Native Places and Composer must exclude unresolved held records, retain source-backed destinations and resolve existing aliases to those destinations. Existing saved references remain stored unchanged.
- Reconcile identities before this hold. Qualify source-backed facilities afterward. Do not infer that independent journal recovery ordering is safe without evidence; operational compatibility remains a release gate.
- PHP 8.4, PostgreSQL/PostGIS and existing Laravel 13 services; no new dependency, schema change or live mutation.

Acceptance: focused real-database regressions, one independent review of this addition, a lab apply/recover rehearsal and a read-only exact-staging baseline. Publish aggregate results, distinguish preparation from rollout, retain draft PR61 and both tickets In Progress.
