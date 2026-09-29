"""Publish the dated report only when the native rehearsal has passed."""
from collections import Counter
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path


ROOT = Path(__file__).resolve().parent
OUT = ROOT / "2026-09-28"
manifest = json.loads((OUT / "manifest.json").read_text())
rehearsal = json.loads((OUT / "rehearsal.json").read_text())
code = json.loads((OUT / "code-verification.json").read_text())
websites = json.loads((OUT / "website-verification.json").read_text())
records = [json.loads(line) for line in (OUT / "records.jsonl").read_text().splitlines()]
digest = hashlib.sha256((OUT / "records.jsonl").read_bytes()).hexdigest()
assert digest == manifest["records_sha256"] == rehearsal["input_records_sha256"]
assert manifest["baseline_sha256"] == rehearsal["baseline_sha256"]
assert len(records) == manifest["records"] == rehearsal["records_verified"]
assert manifest["creates"] == rehearsal["created"] and manifest["refreshes"] == rehearsal["refreshed"]
assert all(rehearsal[k] for k in ("replay_exact_no_op", "unrelated_spots_unchanged", "protected_tables_unchanged", "exact_rollback_verified"))
assert not rehearsal["production_changed"] and not rehearsal["staging_changed"]
assert websites["passed"] and websites["checked"] == manifest["by_source"]["osm"]
assert hashlib.sha256((OUT / "refresh-retention.patch").read_bytes()).hexdigest() == code["patch_sha256"]
assert all(not record["holds"] for record in records)
assert all(hashlib.sha256((ROOT / path).read_bytes()).hexdigest() == digest for path, digest in rehearsal["verification_code_sha256"].items())

actions = Counter((r["role"], "new" if r["existing_id"] is None else "refresh") for r in records)
facts = {k: sum(bool(r["tags"].get(k)) for r in records) for k in ("sport", "surface", "lit", "access", "fee", "opening_hours", "wheelchair", "cuisine", "reservation")}
facts.update({"website": sum(bool(r["website"]) for r in records), "address": sum(bool(r["address"]) for r in records), "phone": sum(bool(r["phone"]) for r in records)})
manifest.update(status="native_verified_local_pack", verified_at=datetime.now(timezone.utc).isoformat(), rehearsal="rehearsal.json", production_imported=False, staging_imported=False)
(OUT / "manifest.json").write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n")

lines = [
    "# Cologne places — prepared catalogue release, 28 September 2026",
    "",
    f"**{len(records):,} source-backed records prepared and verified in the application: {manifest['destinations']:,} destinations and {manifest['activity_facilities']:,} supporting activity facilities.**",
    "",
    "| Records | New | Refreshed | Total |",
    "|---|---:|---:|---:|",
    f"| Destinations | {actions[('destination', 'new')]:,} | {actions[('destination', 'refresh')]:,} | {manifest['destinations']:,} |",
    f"| Activity facilities | {actions[('activity_facility', 'new')]:,} | {actions[('activity_facility', 'refresh')]:,} | {manifest['activity_facilities']:,} |",
    f"| **Total** | **{manifest['creates']:,}** | **{manifest['refreshes']:,}** | **{manifest['records']:,}** |",
    "",
    "This is a qualified import package, not a live production count. Staging and production were not changed. The source-ID mapping was checked against a fresh staging catalogue export; production requires reconciliation against its own current baseline before import. Readiness means the source, identity, boundary, supported-category and native integration checks passed. It does not mean every source assertion was independently verified on site.",
    "",
    "## What changed",
    "",
    f"The release uses {manifest['by_source']['osm']:,} OSM records and {manifest['by_source']['overture']:,} Overture records. It retains the full original record, source identity, source URL, observation date, licences, practical tags and explicit unknowns. Sources were screened against the current catalogue and each other; detected ambiguous identities, restricted/closed records, unsupported conditions and questionable category assignments were held for review. Review tightened the closure-date and same-address/name-overlap checks and held eight additional records before this final release.",
    "",
    f"There are {manifest['named_records']:,} source-named records and {manifest['descriptively_named_facilities']:,} facilities with descriptive labels. A descriptive label is not presented as an official place name. The batch spans {len(rehearsal['by_neighbourhood'])} neighbourhoods and {len(manifest['by_category'])} supported categories. Facility containment evidence remains reviewable and was not automatically converted into destination membership.",
    "",
    "The accompanying importer patch retains practical source tags on future refreshes, covers libraries and coworking across the full city and all OSM element types, removes the 200-attraction response limit, and preserves international website URLs. Acquisition bounds now come from the official city polygons, so accepted edge locations are not lost through a smaller handwritten search rectangle. These code changes are prepared and tested, not deployed. The package import also respects the coordinate precision of the database while retaining the full original source point, so replay cannot churn timestamps from rounding alone.",
    "",
    "## Evidence collected",
    "",
    "| Source fact | Records carrying it |",
    "|---|---:|",
]
for name, key in (("Opening hours", "opening_hours"), ("Address", "address"), ("Website", "website"), ("Phone", "phone"), ("Wheelchair accessibility", "wheelchair"), ("Cuisine", "cuisine"), ("Sport", "sport"), ("Surface", "surface"), ("Lighting", "lit"), ("Access", "access"), ("Fee", "fee")):
    lines.append(f"| {name} | {facts[key]:,} |")
lines += [
    "",
    f"Counts mean a source value is retained, not that every value is complete, current or positive. For example, accessibility can be limited or unavailable. Of the selected records, {sum(r['observation']['fee']['raw'] is None for r in records):,} have no explicit fee evidence; {sum(r['observation']['access']['raw'] is None for r in records):,} have no explicit access evidence. Missing values stay unknown. No record becomes free, public or open now merely because it is on the map.",
    "",
    "## Native verification",
    "",
    f"- Application base: `{rehearsal['application_commit']}`; the tested importer patch is separately checksummed.",
    f"- GitHub's revision comparison confirms that this base has the same file tree as the latest successful staging revision `{code['staging_comparison']['latest_successful_staging_commit']}`.",
    "- Started from the freshly exported public places data in a new isolated PostgreSQL/PostGIS database. No users, private plans, saved places or sessions were copied.",
    f"- Verified all {rehearsal['records_verified']:,} identities, source observations, coordinates, raw tags, access/fee handling and name kinds through the application's native services.",
    f"- Verified {rehearsal['native_composer_identities_verified']:,} eligible identities through Composer's candidate repository; {rehearsal['supporting_records_not_recommended']:,} selected records remain outside automatic recommendations. Tested the strict free-cost filter without turning missing fees into free claims.",
    "- Checked eight native Places list API categories and new-record detail samples. The same baseline and API controller were used for before/after comparison.",
    "- Replayed every payload against its post-import source IDs and expected rows: no changed place rows, extra observations or revision changes. The original frozen manifest intentionally rejects stale preconditions; it is not blindly reapplied. Unrelated place rows and existing identity/grouping/media fields stayed unchanged.",
    "- Rolled the transaction back and verified the original table contents. No live catalogue mutation took place. PostgreSQL sequence gaps are not imported data.",
    f"- {code['php_tests_passed']} application tests passed ({code['php_assertions']} assertions), plus {code['python_tests_passed']} preparation tests and {code['expected_state_checks_passed']} exact-state comparison checks. All {websites['checked']:,} selected OSM website projections match the refresh importer.",
    "",
    "| Native Places list category | Before | After local import |",
    "|---|---:|---:|",
]
for category, before in rehearsal["before_api"].items():
    lines.append(f"| {category.replace('_', ' ')} | {before:,} | {rehearsal['after_api'][category]:,} |")
lines += [
    "",
    "These are category-specific API results, not a citywide unique-destination denominator. They must not be summed or quoted as total Cologne coverage.",
    "",
    "## Photos and remaining work",
    "",
    "**New approved photos: 0.** Photo rights, relevance and health were not changed by this data release. Original image references remain unapproved. No Stadt Köln structured dataset or image provider was added. The package does not establish 75% photo coverage.",
    "",
    f"**{manifest['held']:,} relevant-category source records remain held**, with explicit reasons in `holds.jsonl`. Larger OSM, Overture and Wikidata inventories remain available for subsequent category expansion and review. These overlapping raw-source totals are not production-ready place counts.",
    "",
    "The complete Composer request was not exercised in this data rehearsal. Explicit activity retrieval for unnamed facilities, fee/access/booking conditions and the controller's budget-relaxation behaviour still need application work. The broader inventory includes 365 soccer pitches but no explicit fee or hours tags for those pitches; this release does not claim a verified free-football network.",
    "",
    "Next release steps are: review the package, reconcile it against the target environment's current catalogue, merge/deploy the tested refresh fixes, perform the controlled staging import and live acceptance checks, then promote a newly reconciled production manifest. EXP-69 and EXP-72 remain In Progress; the wider programme is not marked complete by this batch.",
    "",
    "## Category breakdown",
    "",
    "| Category | Selected records |",
    "|---|---:|",
]
for category, count in sorted(manifest["by_category"].items(), key=lambda pair: (-pair[1], pair[0])):
    lines.append(f"| {category.replace('_', ' ')} | {count:,} |")
lines += [
    "",
    "## Reproducible evidence",
    "",
    f"- Selected data SHA-256: `{digest}`.",
    f"- Places-only baseline SHA-256: `{manifest['baseline_sha256']}`; exported `{manifest['baseline_exported_at']}`.",
    f"- Research inventory SHA-256: `{manifest['inventory_sha256']}`.",
    "- `manifest.json`, `rehearsal.json`, `code-verification.json`, `website-verification.json` and `php-test-results.txt` record the measurements.",
    "- `records.jsonl` is the selected import input; held records and containment evidence are separate files.",
    "- See `../README.md`, `../PLAN.md` and `../NOTICE.md` for the procedure, release boundary and source attribution.",
    "",
]
(OUT / "REPORT.md").write_text("\n".join(lines))
print(json.dumps({"records": len(records), "destinations": manifest["destinations"], "facilities": manifest["activity_facilities"], "report": str(OUT / "REPORT.md")}))
