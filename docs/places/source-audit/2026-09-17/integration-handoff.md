# EXP-70 integration handoff

Reviewed: 2026-09-17

Frozen manifest SHA-256: `1eaf7cbe5a247d5ae46a653788abf9d1236f9dc9b3fe91db0629c21cf03c0b8e`

## Required sequence

### 1. EXP-72 — reviewed staging pilot

Use `baseline-manifest.json` as the immutable selection. Stage the 100 records without publishing new media or changing production.

1. Resolve place identity first. Preserve source rows, choose a canonical destination, and record aliases instead of renaming raw source objects in place.
2. Review the 14 parent-place candidates. A destination may expose tennis, basketball, picnic, playground or cultural activities through explicit memberships. Do not infer membership from proximity alone.
3. Start with the exact and proximity candidates in `sample-results.jsonl`, then resolve against the official source. Use `candidate-inventory.jsonl` for the separate 50-row net-new review. Accept official name, coordinate, district, neighbourhood, street or remarks one field at a time with source URL and observed/reviewed dates.
4. Keep raw Stadt Köln `status` and `objekttyp` values uninterpreted until the city publishes or confirms their code domains.
5. Keep entrance points unknown until a source provides an actual entrance. A map point is not automatically a routable entrance.
6. Show uncertainty to end users and Composer. `unknown`, `not_exposed`, `not_applicable` and `access_blocked` are distinct states.
7. Re-run API checks against the exact 100 IDs and report before/after field coverage, duplicate groups and destination memberships.

Exit evidence: the API returns one reviewed destination for each accepted identity group, activity memberships are sourced, and no pending media is selectable.

### 2. Full-city café import and reconciliation

The current importer is bounded to `50.92,6.92,50.96,6.97`, which explains the zero outer-city result. Expand extraction to the configured city boundary or tiled city coverage, then reconcile before publication.

The reconciliation key must combine source type/id, normalized name and distance. Exact coordinate matches should become review candidates rather than automatic merges: `Das Café` and `Carls` demonstrate that two names can share one point, while other raw response rows demonstrate true duplicates.

Exit evidence: all nine districts can return cafés, exact source duplicates are idempotent across repeat imports, and disputed merges remain unmerged with a review reason.

### 3. Stadt Köln structured park importer

Build this as a separate provider adapter with field provenance. Use the ArcGIS layer at `freizeit_natur_sport/parkanlagen/MapServer/0`, DL-DE Zero 2.0, and a stable external key based on the provider plus `objectid` (retain `klrid` as a secondary identifier).

Do not import linked page images. Do not copy page prose unless its rights are separately established. Preserve raw numeric codes and nullable fields.

Exit evidence: repeat imports are idempotent; source removal is non-destructive until reviewed; changed fields produce reviewable provenance; no media row is created.

### 4. Data Hub NRW access decision

Assign a business or legal owner to review the current B2B terms and decide whether Expadu should submit organizational contact details and accept them. Store any resulting destination.meta key in the environment, never in the repository or ticket.

After access exists, run the same 40-row provider sample with no more than 50 requests. Capture record ID, updated timestamp, canonical URL, field-level licence, media author, media licence and media source URL. Treat missing values as missing; do not convert them to an empty or free/public claim.

Exit evidence: written owner decision, configured secret if approved, and a rerunnable 40-row result file.

### 5. Media replacement pipeline

Continue `PublishedMediaSelector` gates: `rights_status = approved` and `health_status = active`. For Commons, Data Hub or Mapillary, approval is per exact asset and requires author, source URL, licence identifier/URL and evidence timestamp. A provider name alone never approves an asset.

All 20 records in `asset-rights.jsonl` stay pending and excluded. Replace them with an approved asset or retain the product's media-missing state. Do not copy Stadt Köln page photos into storage.

Exit evidence: the API never returns a pending Stadt Köln asset and every returned external photo has a complete attribution payload.

## API acceptance checks

- `GET /api/places` reports stable canonical IDs, coordinates and reviewed memberships for the pilot.
- Repeating the same import produces no additional source records or media rows.
- Destination membership changes are visible through the API and contain review provenance.
- Missing media remains `null`; it is never hidden behind an unlicensed fallback.
- Café queries against representative Innenstadt, Porz, Chorweiler, Kalk and Mülheim bounding boxes return documented counts.
- Blocked providers remain `not_evaluated_access_blocked`, not `0 matches`.

## Production boundary

EXP-70 is research evidence only. EXP-72 is the staging pilot. Production import, bulk merge, provider contract acceptance and media approval require their own reviewed change.
