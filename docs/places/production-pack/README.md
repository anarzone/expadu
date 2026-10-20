# Cologne catalogue preparation

Refs EXP-69, EXP-70, EXP-72. The dated report records the measured outcome. A
research inventory count is not a count of ready destinations.

This package prepares an additive catalogue release using OSM and Overture
source records. It does not deploy the application, write to a live catalogue,
approve photos, or mark every source assertion independently verified.

## Contents

- `2026-09-28/records.jsonl`: selected destination and facility records, original
  provider records, observation payloads, source URLs, dates and licence IDs.
- `2026-09-28/manifest.json`: frozen inputs, counts, checksums and selection state.
- `2026-09-28/holds.jsonl` and `identity-review.jsonl`: excluded records and the
  reasons they need review. They are not import input.
- `2026-09-28/containment-evidence.jsonl`: possible facility relationships;
  these are evidence, not automatically approved memberships.
- `2026-09-28/rehearsal.json`: written only after native application checks,
  repeat-import stability and rollback all pass.
- `2026-09-28/refresh-retention.patch`: scoped importer fixes and regression tests
  against the application commit named in `code-verification.json`.
- `NOTICE.md` and `licenses/`: source attribution and reuse information. Media
  permissions are separate from place-data licensing.

## Reproduction and promotion

The builder reads the immutable research SQLite inventory and a places-only
catalogue export. It never reads users, private plans or credentials. Run its
unit tests with `python3 -m unittest discover -s docs/places/production-pack`.

`run-local.py` configures the application only for the specifically named local
rehearsal database. `load-baseline.php`, `rehearse.php` and the diagnostic tool
reject other databases. They are verification tools, not deployment commands.
Database credentials stay outside the package.

`apply-pack.php` requires a caller-owned transaction and checks expected existing
records before touching them. It writes through the application's place and
observation services. It neither retires missing records nor merges identities.
The replay and rollback checks use complete table comparisons, including
timestamps; changes are not hidden by ignoring audit fields.

The prepared ID mapping targets the frozen staging catalogue. Production is a
separate environment: export its current places-only baseline, reconcile source
identities and existing reviews, rebuild the create/update decisions and rerun
the native checks before a reviewed promotion. Never use staging row IDs against
production or force the import past an expected-state mismatch. Refresh stale
source evidence if promotion is delayed.

## What “ready” means here

Selected destinations have a usable name, city-contained coordinates, a category
the application supports, traceable source identity and no detected unresolved
identity, closure or restricted-access conflict. Overture-only selections also
require reported-open status, high source confidence, address and contact
evidence. This is a reproducible automated qualification, not a physical visit
or an independent check of every business.

Activity facilities are counted separately. An unnamed court can be valuable
source data without being a separate browse destination. Explicit activity
retrieval and the complete “free football nearby” workflow still need their own
application acceptance checks. Missing price, access, hours or reservation
evidence must not become “free”, “public” or “available now”.

Photos are optional. This release approves no images and adds no Stadt Köln
structured dataset or image provider. It preserves the application's media
rights gate and existing reviewed identity/grouping decisions.
