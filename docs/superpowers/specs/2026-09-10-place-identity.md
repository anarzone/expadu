# EXP-67: Place identity reconciliation

## Outcome

A physical place has one discovery identity. Older duplicate records remain addressable, retain their source evidence and user history, and resolve to the canonical place. Facilities at the same destination are not identity duplicates: that relationship is EXP-68.

## Evidence and scope

The production audit found 3,310 eligible legacy rows with an active same-name/category OSM neighbour within 10 metres. This is a candidate count, not a confirmed duplicate count. Import already updates typed source IDs (`node/…`, `way/…`) independently; it does not reconcile source-null legacy rows. An audit must expose ambiguities and conflicting eligibility instead of approving matches from proximity alone.

The work starts with a read-only JSON audit, then adds explicit, fingerprint-checked reconciliation. No migration merges data. No scheduled task merges candidates. A reviewed identity match is distinct from a media-rights approval.

## Required behavior

- Keep old Spot IDs and original source/name/location attributes. A nullable, indexed `canonical_spot_id` marks aliases. Canonical targets cannot themselves be aliases. Reject self-links and cycles.
- Store reconciliation evidence and a snapshot in an audit record. Lock both identities before applying and reject a changed fingerprint. Repeating the same completed mapping is idempotent.
- Discovery scopes, raw Composer ranking, map search, venue matching and proximity detection omit aliases. Refreshing an alias cannot make it discoverable again.
- Old detail/context/feedback/review routes resolve to the canonical place. Unknown IDs retain 404 behavior.
- Preserve all feedback/review rows. Read the most recently updated row per user and canonical identity; canonical rows win equal timestamps, then highest row ID. New writes use the canonical ID. Clearing feedback clears the whole identity family, preventing old feedback resurfacing.
- Merge neither image assets nor rights statuses. Retain attachments and manual choices; canonical selection can use family attachments while preserving canonical manual priority. Pending or broken assets remain unpublished.
- Preserve parent/area/venue references. Reject a mapping that makes a place contain itself; map existing parent references to the canonical identity within the reconciliation transaction.
- Normalize Composer pins, locks, exclusions and rejected choices to canonical IDs. Previously stored slots stay addressable without dropping an alias merely because it is outside the nearest-candidate pool. Preserve stored plan timing when resolving IDs.
- Keep cache contents scalar. Discovery cache invalidation must cover location cells after reconciliation.
- This first reconciliation path accepts source-null aliases only. Reject a proposed alias that already owns aliases; do not create chains. Refreshes of sourced area parents therefore keep their identity.
- Existing catalogue prune/sanitize/load commands must refuse destructive operations affecting retained identity families or reconciliation history. Recompute the canonical rating when linking a family.
- Media existence and selection both include the family, including eager-loaded paths. A pending alias attachment must prevent a canonical legacy-photo fallback. Place-events links and raw neighbourhood-directory counts require canonical identity coverage too.

## Constraints

- PHP 8.4 and Node 22; PostgreSQL/PostGIS; Pest regression tests.
- Preserve unrelated working-tree changes; implementation is isolated from the dirty staging checkout.
- Stadt Köln image rights remain pending without documented permission.
- Product presentation reference: `http://127.0.0.1:8765/dev/design/today-c/`. No frontend redesign in EXP-67.
- Feature branch starts from `staging`. Pilot data changes occur on staging only. Production requires owner review of EXP-72.
- Do not label EXP-67 complete until the reference and import regressions pass.
