"""Local-first Places preparation. Raw catalogue data never enters Git or stdout."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys

APP = Path(__file__).resolve().parents[3]
HERE = Path(__file__).resolve().parent
DATABASE = "exp69_local_catalogue_20261001"
PHP = "/Users/anar/Library/Application Support/Herd/bin/php"
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--source-root", type=Path, default=Path(os.environ.get("PLACES_SOURCE_ROOT", APP)))
parser.add_argument("action")
args, extra = parser.parse_known_args()
SOURCE = args.source_root.resolve()
PRIVATE = SOURCE / "storage/app/private/places-local/2026-10-01"
REPORTS = HERE / "2026-10-01"
os.umask(0o077)
PRIVATE.mkdir(parents=True, exist_ok=True)
REPORTS.mkdir(parents=True, exist_ok=True)
snapshot_path = PRIVATE / "catalogue.json"


def sha(path):
    with path.open("rb") as handle:
        return hashlib.file_digest(handle, "sha256").hexdigest()


def summary(name, value):
    (REPORTS / name).write_text(json.dumps(value, indent=2, ensure_ascii=False) + "\n")
    print(json.dumps({k:v for k,v in value.items() if k not in {"runtime_files_sha256","evidence_sha256"}}, ensure_ascii=False), flush=True)


def local_environment():
    values = {}
    for line in (SOURCE / ".env").read_text().splitlines():
        if line and not line.startswith("#") and "=" in line:
            key, value = line.split("=", 1)
            values[key] = value.strip('\\\"\'')
    env = os.environ.copy()
    for key in ["DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_USERNAME", "DB_PASSWORD"]:
        if key in values:
            env[key] = values[key]
    if env.get("DB_HOST") not in {"127.0.0.1", "localhost", "::1"}:
        raise RuntimeError("Only loopback local database access is allowed")
    env.update(APP_ENV="testing", APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
        DB_URL="", DB_DATABASE=DATABASE, CACHE_STORE="array", SESSION_DRIVER="array", QUEUE_CONNECTION="sync",
        PLACES_LOCAL_PRIVATE=str(PRIVATE), PLACES_LOCAL_REPORTS=str(REPORTS),
        PLACES_SOURCE_ROOT=str(SOURCE), PLACES_AUTOMATION_ENABLED="false", PLACES_CURATED_SEEDING_ENABLED="false")
    env["PATH"] = str(Path(PHP).parent) + ":" + env["PATH"]
    return env


def local_php(script, *arguments):
    result = subprocess.run([PHP, "-d", "memory_limit=1G", str(HERE/script), *arguments],
        cwd=APP, env=local_environment())
    if result.returncode:
        raise SystemExit(result.returncode)


if args.action == "snapshot":
    if snapshot_path.exists():
        raise SystemExit("Snapshot already exists; preserve it and use verify-snapshot.")
    policy = (HERE/"SnapshotPolicy.php").read_text().replace("<?php", "", 1).replace("declare(strict_types=1);", "", 1)
    schema_text = (HERE/"schema.json").read_text()
    policy = policy.replace("file_get_contents(__DIR__.'/schema.json')", "'" + schema_text.replace("\\","\\\\").replace("'", "\\'") + "'")
    body = (HERE/"snapshot.php").read_text().replace("<?php", "", 1).replace("declare(strict_types=1);", "", 1)
    php = "<?php\ndeclare(strict_types=1);\n" + policy + "\n" + body
    command = ["ssh","-o","BatchMode=yes","hetzner",shlex.join(["docker","exec","-i","staging-app","php","-d","memory_limit=1G"])]
    temporary = PRIVATE/"catalogue.partial.json"
    with temporary.open("xb") as output, (PRIVATE/"snapshot-diagnostics.txt").open("wb") as errors:
        result = subprocess.run(command, input=php.encode(), stdout=output, stderr=errors, timeout=180)
    if result.returncode:
        raise SystemExit("Catalogue snapshot refused; diagnostics retained privately.")
    document = json.loads(temporary.read_text())
    schema = json.loads(schema_text)
    assert document["columns"] == schema and set(document["tables"]) == set(schema)
    assert document["transaction_read_only"] and not document["staging_changed"]
    for table, rows in document["tables"].items():
        expected = document["table_hashes"][table]
        observed = hashlib.sha256(json.dumps(rows,ensure_ascii=False,separators=(",",":")).encode()).hexdigest()
        assert observed == expected, "Snapshot serialization mismatch: "+table
    temporary.rename(snapshot_path)
    summary("snapshot-summary.json", {
        "status":"captured_catalogue_only","exported_at":document["exported_at"],
        "application_commit":document["application_commit"],"snapshot_sha256":sha(snapshot_path),
        "row_format":document["row_format"],"table_counts":{k:len(v) for k,v in document["tables"].items()},
        "redacted_rows":document["redactions"],"table_hashes":document["table_hashes"],
        "user_tables_exported":False,"source_actors_exported":False,"snapshot_in_git":False,
        "staging_changed":False,"production_changed":False})
elif args.action == "consolidate":
    from consolidate import build
    inventory=SOURCE/"docs/places/cologne-expansion/2026-09-28/inventory.sqlite"
    frozen=json.loads((REPORTS/"snapshot-summary.json").read_text())
    result=build(inventory,snapshot_path,PRIVATE/"registry.sqlite",
        inventory_sha256="e89849ab76a6cd640a2561af8ed5a77accd2f5d923694b4bd7381f63b2f82d64",
        snapshot_sha256=frozen["snapshot_sha256"])
    summary("consolidation-summary.json",result)
elif args.action == "verify-rebuild":
    from consolidate import build
    first=json.loads((REPORTS/"consolidation-summary.json").read_text())
    second_path=PRIVATE/"registry-rebuild.sqlite"
    second=build(SOURCE/"docs/places/cologne-expansion/2026-09-28/inventory.sqlite",snapshot_path,second_path,
        inventory_sha256=first["inventory_sha256"],snapshot_sha256=first["snapshot_sha256"])
    if first["semantic_sha256"]!=second["semantic_sha256"]:
        raise RuntimeError("Whole-source rebuild differs.")
    summary("rebuild-verification.json",{"status":"passed","source_records":second["source_records"],
        "semantic_sha256":second["semantic_sha256"],"inputs_unchanged":True,"remote_changed":False})
    second_path.unlink()
elif args.action == "query-checks":
    from query import search
    results=[]
    for origin,lat,lon in [("central",50.9384,6.9600),("north",51.0470,6.8830),("east",50.9600,7.0690)]:
        for field,value in [("category","cafe"),("category","restaurant"),("activity","soccer"),
                            ("activity","basketball"),("activity","tennis")]:
            found=search(PRIVATE/"registry.sqlite",lat,lon,3,**{field:value},limit=200)
            for item in found["results"]+found["confirmed_free_public"]:
                assert item["display_name"] and item["source_url"] and item["collected_at"]
                assert item["availability"]=="unknown"
                assert item["role"] not in {"supporting_feature","transport_feature","heritage_or_information_feature","outdoor_area"}
            for item in found["confirmed_free_public"]:
                assert item["fee_status"]=="free_reported" and item["access_status"]=="public_reported"
                assert item["identity_review_count"]==0
            results.append({"origin":origin,"query":{field:value},"radius_km":3,
                "matched_source_records":found["matched_source_records"],
                "confirmed_free_public_source_evidence":found["confirmed_free_public_count"],
                "needs_checking":found["needs_checking_count"],
                "descriptive_labels_in_displayed_results":sum(x["name_kind"]=="descriptive" for x in found["results"])})
    summary("query-verification.json",{"status":"passed","scope":"local_source_research_not_production_composer",
        "queries":results,"unique_destinations_established":False,"remote_changed":False})
elif args.action == "rehearse-facilities":
    files=subprocess.check_output(["git","ls-files","app","config","bootstrap","routes"],cwd=APP,text=True).splitlines()
    manifest={"files":{name:sha(APP/name) for name in files if (APP/name).is_file()},
        "facility_helper_sha256":sha(HERE/"FacilityQualificationJournal.php")}
    (PRIVATE/"facility-code-manifest.json").write_text(json.dumps(manifest))
    local_php("inspect-facilities.php")
    summary("facility-source-summary.json",json.loads((PRIVATE/"facility-source-summary.json").read_text()))
    summary("facility-target-summary.json",json.loads((PRIVATE/"facility-target-summary.json").read_text()))
    local_php("rehearse-facilities.php")
elif args.action == "prepare":
    from prepare_local import prepare
    local_php("export-baseline.php")
    result=prepare(SOURCE/"docs/places/cologne-expansion/2026-09-28/inventory.sqlite",PRIVATE/"places-only.json",PRIVATE/"supported-package")
    summary("preparation-summary.json",result)
elif args.action == "verify-evidence":
    subprocess.run([sys.executable,"-m","unittest","discover","-s",str(HERE),"-p","test_*.py"],cwd=APP,check=True)
    subprocess.run([PHP,str(HERE/"test-snapshot.php")],cwd=APP,check=True)
    local_php("verify-snapshot.php")
    from consolidate import semantic_hash, source_hash
    import sqlite3
    names=["snapshot-summary","consolidation-summary","rebuild-verification","query-verification",
        "native-verification","preparation-summary","rehearsal-summary","facility-rehearsal-summary","combined-rehearsal-summary"]
    evidence={name:json.loads((REPORTS/(name+".json")).read_text()) for name in names}
    registry=evidence["consolidation-summary"]
    assert sha(snapshot_path)==registry["snapshot_sha256"]==evidence["snapshot-summary"]["snapshot_sha256"]
    assert sha(SOURCE/"docs/places/cologne-expansion/2026-09-28/inventory.sqlite")==registry["inventory_sha256"]
    connection=sqlite3.connect((PRIVATE/"registry.sqlite").as_uri()+"?mode=ro",uri=True)
    assert semantic_hash(connection)==registry["semantic_sha256"]==evidence["rebuild-verification"]["semantic_sha256"]
    original=sqlite3.connect((SOURCE/"docs/places/cologne-expansion/2026-09-28/inventory.sqlite").as_uri()+"?mode=ro",uri=True)
    source_copy_sha256=source_hash(connection)
    assert source_copy_sha256==source_hash(original),"Registry raw source copy changed"
    connection.close(); original.close()
    runtime_files=json.loads((PRIVATE/"facility-code-manifest.json").read_text())["files"]
    assert all(sha(APP/name)==digest for name,digest in runtime_files.items()),"Native application files changed"
    for name in ["rebuild-verification","query-verification","native-verification","rehearsal-summary","facility-rehearsal-summary","combined-rehearsal-summary"]:
        assert evidence[name]["status"]=="passed",name
    preparation=evidence["preparation-summary"]; rehearsal=evidence["rehearsal-summary"]
    assert sha(PRIVATE/"supported-package/records.jsonl")==preparation["records_sha256"]==rehearsal["records_sha256"]
    assert sha(PRIVATE/"places-only.json")==preparation["baseline_sha256"]==rehearsal["baseline_sha256"]
    assert preparation["records"]==rehearsal["records_verified"]
    assert rehearsal["replay_exact_no_op"] and rehearsal["exact_baseline_and_sequence_restore_verified"]
    facility=evidence["facility-rehearsal-summary"]
    assert facility["qualified_facilities_in_trial"]==90 and facility["unknown_access_facilities_held"]==469
    assert facility["source_proof_sha256"]==sha(PRIVATE/"facility-source-private.json")
    assert facility["exact_native_catalogue_restore_verified"] and facility["all_source_place_review_journal_rows_restored"]
    combined=evidence["combined-rehearsal-summary"]
    assert combined["exact_baseline_restore_verified"] and combined["facility_apply_and_recovery_replay_identical"]
    assert combined["candidate_export_sha256"]==sha(PRIVATE/"candidate-places.jsonl")
    candidate_ids=set()
    for line in (PRIVATE/"candidate-places.jsonl").read_text().splitlines():
        record=json.loads(line)
        assert record["id"] not in candidate_ids and record["place"]["name"]==record["composer"]["name"]
        assert record["scope"]=="local_candidate_catalogue_not_deployed"
        candidate_ids.add(record["id"])
    assert len(candidate_ids)==combined["exported_native_candidates"]==combined["eligible_in_combined_trial"]
    assert all(not item.get("remote_changed",False) and not item.get("staging_changed",False) and not item.get("production_changed",False) for item in evidence.values())
    summary("evidence-verification.json",{"status":"passed","scope":"local_preparation_and_rehearsal",
        "raw_source_records_preserved":registry["source_records"],"stored_app_records_preserved":registry["app_records"],
        "unchanged_source_refreshes_avoided":preparation["unchanged_current_source_records"],
        "prepared_additions":preparation["creates"],"locally_rehearsed_public_access_facilities":90,
        "validated_candidate_export_records":len(candidate_ids),"baseline_restored":True,"staging_changed":False,"production_changed":False,
        "raw_source_copy_sha256":source_copy_sha256,"runtime_files_verified":len(runtime_files),
        "runtime_manifest_sha256":sha(PRIVATE/"facility-code-manifest.json"),
        "evidence_sha256":{name:sha(REPORTS/(name+".json")) for name in names}})
elif args.action == "held-public-facilities-proof":
    local_php("../held-facilities/verify-source-proof.php")
elif args.action == "held-public-facilities":
    local_php("../held-facilities/rehearse.php")
elif args.action == "setup":
    env = local_environment()
    code = """$p=new PDO('pgsql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname=postgres',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$s=$p->prepare('SELECT 1 FROM pg_database WHERE datname=?');$s->execute(['exp69_local_catalogue_20261001']);if(!$s->fetchColumn()){$p->exec('CREATE DATABASE exp69_local_catalogue_20261001');}$p=new PDO('pgsql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname=exp69_local_catalogue_20261001',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->exec('CREATE EXTENSION IF NOT EXISTS postgis');$p->exec('CREATE EXTENSION IF NOT EXISTS vector');echo 'Dedicated local catalogue database ready'.PHP_EOL;"""
    subprocess.run([PHP,"-r",code],env=env,check=True,cwd=APP)
    subprocess.run([PHP,"artisan","migrate","--force","--no-interaction"],env=env,check=True,cwd=APP)
elif args.action in {"restore","verify-snapshot","verify-native","rehearse","rehearse-combined"}:
    local_php({"verify-snapshot":"verify-snapshot.php","verify-native":"verify-native.php"}.get(args.action,args.action+".php"),*extra)
else:
    raise SystemExit("Unsupported local catalogue operation: "+args.action)
