# Verified media recovery and facility evidence

Refs EXP-69, EXP-72. Continue the authorized release preparation under the existing
plan. Use executing-plans, Laravel patterns and Pest tests; one final independent
review. Existing draft PR61 / candidate f779ce52; no live deployment or data import.

## Design decision

All 73 proved existing photos have changed remote URLs. A match-only flag update
would skip their current health inputs. Build a bounded operational journal around
native pending capture, byte validation and audited match review. It is an operator
helper in docs, not an exposed application endpoint or scheduler. For the initial
small production catalogue, lock the relevant media/spot tables for the batch;
therefore a live run requires the planned maintenance/automation pause. Reads stay
available. No new assets/attachments may be created by this recovery operation.

Scope is the known existing associations. New photo acquisition is separate. The
operation stores before/after media rows plus all affected owners; checksum guards
and source-key/target-ID matching prevent stale approval or identity remapping.
Recovery restores assets and attachments, retains validation/review audit history,
and refuses any later media/owner drift. Replay is a no-write read of its receipt.
Source, metadata and health proof are distinct; never promote metadata SHA-1 to
validated-byte SHA-256. Stadt Köln exclusions and all publication gates remain.

## Steps

- [x] Add focused Pest regressions for durable apply/replay/recover/recover replay;
  wrong target/changed source tags; whole-batch rollback on health failure; shared
  assets/sibling priority; drift after apply; and recovery from the journal through a new service instance without
  a progress file (not an actual process-crash test). Observe red before implementation.
- [x] Implement docs/places/production-release/MediaRecoveryJournal.php using the
  existing catalogue-operation table with a separate kind and schema. Bound at
  100 records and 2,500 rows per media table. Capture pending using existing
  attachment priority/primary values and shouldValidate=false; explicitly call
  validator and ReviewMediaMatch, then assert the selected asset is exact.
- [x] Independently inspect held facility evidence without promoting uncertain
  costs/access or merging same-area facilities. Export counts and source keys only.
- [x] Recheck exact public photo source IDs and references, refresh Commons metadata,
  build target-specific baseline, then apply/replay/recover in the isolated lab
  under an outer rollback. All media values and audit counts must match afterward.
- [x] Review, fix important findings with regression tests, run normal hooks and CI,
  update draft PR and Jira/BookStack with actual outcomes and remaining limitations.

## Review focus

Shared assets and primary siblings; manual locks/rejected matches; mid-batch failure;
changed owner/source/metadata/health; recovered operation replay; no source lookup
or network on replay/recovery; receipt integrity and retained audit history.

## Progress and rulings

- Ruling: continue the already authorized release plan without another design
  approval round. This operational helper realizes its explicit photo-recovery
  condition; live execution remains a separately approved operation.
- Source inspection: 73 existing matches/assets; fresh remote URLs differ for all
  73, source page differs for one, rights attribution fields otherwise match.
- Facility inspection: 559 descriptive facilities; 90 public-access, 469 unknown
  access; all have unknown fee. This cannot establish free-football coverage.

- Review: two important findings, no critical findings. Added five failing
  regressions (shared owner, canonical alias, parent drift, unsupported owner type,
  and later hero displacement); fixed in one pass. All 19 focused tests pass with
  64 assertions; complete media suite passes 118 tests / 514 assertions.
- Owner snapshots now include every place-owned media attachment and its canonical
  family/parent/destination closure. Unsupported morph types are refused. Final
  hero selection is checked only after the entire batch has been applied.
- Rehearsal: all 467 candidate application files matched the isolated lab. All 73
  current OSM references and 73 fresh Commons metadata responses were checked.
  All 65 eligible associations passed apply, API display with credit/source/licence,
  replay, recovery and recovered API hiding. Eight held owners remained hidden.
  Recovery guards 218 owner/family rows and retains 65 match plus 65 health audits;
  the outer rollback restored every media, audit, journal and place row.
- New-service-instance recovery verifies database journal lookup; no process was
  killed and no persistent media approval was committed. This limitation is kept
  explicit rather than counted as crash recovery.
- The 559 descriptive-facility audit found 90 public-access claims, comprising
  74 playgrounds, 11 table-tennis facilities, three basketball facilities and two
  pitches. All 559 have unknown fees. This package audit is not a fresh source
  recheck and creates zero qualifications or free-football claims.
- Private evidence archived on its original host with 0600 mode; both app images,
  five production catalogue/media table fingerprints and 89 migrations unchanged.

- Final commit 75b2e6f8adf88894aa949ca4de876838fc39f51d pushed to existing draft
  PR61. Normal hooks passed 1,675 tests / 6,987 assertions. Exact-head CI run
  36699234712 passed Lint, Test and Browser Tests; GitGuardian passed; builds and
  deployments skipped. Jira EXP-69/72 and BookStack pages 33/36 updated and read
  back successfully. No live import/deployment or broad-coverage completion claim.
