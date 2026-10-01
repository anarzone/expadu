"""Preserve the entire source inventory and reconcile it with a local app snapshot."""
from collections import Counter, defaultdict
from datetime import datetime, timezone
import hashlib
import importlib.util
import json
import math
from pathlib import Path
import re
import sqlite3
import unicodedata

_prepare_spec = importlib.util.spec_from_file_location("place_preparation", Path(__file__).resolve().parents[1]/"production-pack/prepare.py")
_preparation = importlib.util.module_from_spec(_prepare_spec)
_prepare_spec.loader.exec_module(_preparation)
CONTEXT_ROLES = {"supporting_feature","transport_feature","heritage_or_information_feature","outdoor_area"}
ALLOWED_LICENSES = {"osm":{"ODbL-1.0"},"overture":{"CDLA-Permissive-2.0","Apache-2.0","CC0-1.0"},"wikidata":{"CC0-1.0"}}
LABELS = {"pitch":"Sports pitch","playground":"Playground","sports_hall":"Sports hall",
          "fitness_station":"Outdoor fitness area","swimming_pool":"Swimming pool","dog_park":"Dog park",
          "skatepark":"Skatepark","schoolyard":"Schoolyard"}


def digest(path):
    with Path(path).open("rb") as handle:
        return hashlib.file_digest(handle,"sha256").hexdigest()


def canonical(value):
    return json.dumps(value,ensure_ascii=False,sort_keys=True,separators=(",",":"))


def distance_km(lat,lon,other_lat,other_lon):
    a=math.sin(math.radians(other_lat-lat)/2)**2
    a+=math.cos(math.radians(lat))*math.cos(math.radians(other_lat))*math.sin(math.radians(other_lon-lon)/2)**2
    return 6371.0088*2*math.asin(min(1,math.sqrt(max(0,a))))


def normalized_name(value):
    text=unicodedata.normalize("NFKD",str(value or "").casefold().replace("ß","ss"))
    return " ".join(re.findall(r"[a-z0-9]+","".join(c for c in text if not unicodedata.combining(c))))


def entry(row):
    facts=json.loads(row["facts_json"])
    raw=json.loads(row["raw_json"])
    tags=raw.get("tags",{}) if isinstance(raw,dict) else {}
    source_name=row["name"].strip() if isinstance(row["name"],str) else None
    sports=facts.get("sports") or []
    label=LABELS.get(row["category"],(row["category"] or "place").replace("_"," ").capitalize())
    if row["category"]=="pitch":
        label={"soccer":"Football pitch","basketball":"Basketball court","tennis":"Tennis court",
               "table_tennis":"Table tennis","boules":"Boules court"}.get(sports[0] if len(sports)==1 else "",label)
    app_category=(_preparation.osm_category(tags) if row["source"]=="osm"
                  else _preparation.OVERTURE_MAP.get(row["category"]) if row["source"]=="overture" else None)
    state="source_candidate"
    reasons=[]
    lat,lon=row["lat"],row["lon"]
    if lat is None or lon is None or not math.isfinite(lat) or not math.isfinite(lon) or not (-90<=lat<=90 and -180<=lon<=180):
        state="invalid_location"
        reasons.append("missing_or_invalid_source_point")
    elif not row["inside_city"]:
        state="outside_city"
        reasons.append("outside_collection_city")
    elif row["role"] in CONTEXT_ROLES:
        state="supporting_context"
    inactive=(row["operating_status"] in {"inactive_reported","permanently_closed","ended_reported"}
              or facts.get("inactive_reported") is True
              or any(k.startswith(("disused:","abandoned:","demolished:","construction:")) for k in tags)
              or any(str(tags.get(k,"")).lower()=="yes" for k in ("disused","abandoned","demolished","construction"))
              or str(tags.get("opening_hours","")).lower() in {"closed","off"})
    restricted=(facts.get("access_status") in {"restricted_reported","conditional"}
                or bool(tags.get("access:conditional"))
                or str(tags.get("access","")).lower() in _preparation.RESTRICTED)
    booking=facts.get("reservation") not in (None,"","no","yes","recommended") or facts.get("membership") not in (None,"","no")
    if inactive:
        state="inactive"
        reasons.append("inactive_or_closed_source")
    elif restricted:
        state="restricted"
        reasons.append("restricted_or_conditional_access")
    elif booking:
        state="requires_booking_review"
        reasons.append("booking_or_membership_constraint")
    if tags.get("end_date") or tags.get("closing_date"):
        reasons.append("dated_lifecycle_requires_review")
        if state=="source_candidate": state="requires_currentness_review"
    licenses=set(json.loads(row["license_json"]))
    if not licenses or not licenses.issubset(ALLOWED_LICENSES.get(row["source"],set())):
        state="requires_license_review"
        reasons.append("unapproved_or_missing_data_license")
    if row["source"]=="overture" and row["operating_status"]!="open":
        reasons.append("operating_status_unknown")
    if not app_category:
        reasons.append("outside_current_app_category_contract")
    if not source_name and row["role"]!="activity_facility":
        reasons.append("source_name_missing")
    if facts.get("fee_status","unknown")!="free_reported":
        reasons.append("no_confirmed_free_evidence")
    if facts.get("access_status","unknown")!="public_reported":
        reasons.append("no_confirmed_public_access")
    if tags.get("fee:conditional") or tags.get("charge:conditional") or facts.get("fee_status") in {"conditional","conflicting"}:
        reasons.append("conditional_or_conflicting_fee")
    return (row["id"],source_name or label,"source" if source_name else "descriptive",
            row["role"],app_category,state,canonical(sorted(set(reasons))),0)


def source_hash(db):
    result=hashlib.sha256()
    for table,order in [("source_records","id"),("capabilities","record_id,activity"),
                        ("containment","child_id,parent_id"),("source_links","from_id,to_id,basis")]:
        result.update(table.encode()+b"\n")
        for row in db.execute("SELECT * FROM "+table+" ORDER BY "+order):
            result.update(canonical(list(row)).encode()+b"\n")
    return result.hexdigest()


def semantic_hash(db):
    hash_value=hashlib.sha256()
    for table,order in [("app_records","spot_id"),("app_source_links","source_record_id,spot_id"),
                        ("registry_entries","record_id"),("identity_candidates","left_id,right_id")]:
        hash_value.update(table.encode()+b"\n")
        for row in db.execute("SELECT * FROM "+table+" ORDER BY "+order):
            hash_value.update(canonical(list(row)).encode()+b"\n")
    return hash_value.hexdigest()


def build(inventory,snapshot,output,*,inventory_sha256,snapshot_sha256):
    inventory,snapshot,output=map(Path,(inventory,snapshot,output))
    if output.exists():
        raise FileExistsError("Preserve the existing registry; choose a fresh output.")
    if digest(inventory)!=inventory_sha256 or digest(snapshot)!=snapshot_sha256:
        raise ValueError("A frozen input checksum changed.")
    document=json.loads(snapshot.read_text())
    if document.get("schema_version")!=1 or document.get("row_format")!="postgresql_column_text":
        raise ValueError("Unsupported catalogue snapshot representation.")
    spots=document["tables"]["spots"]
    output.parent.mkdir(parents=True,exist_ok=True)
    temporary=output.with_name(output.name+".building")
    if temporary.exists():
        raise FileExistsError("An incomplete build exists; inspect it before retry.")
    source=sqlite3.connect(inventory.resolve().as_uri()+"?mode=ro",uri=True)
    db=sqlite3.connect(temporary)
    try:
        source.backup(db)
        db.executescript("""
          CREATE TABLE app_records(spot_id INTEGER PRIMARY KEY,name TEXT,category TEXT,source TEXT,source_id TEXT,
            canonical_spot_id INTEGER,is_active INTEGER,is_recommendable INTEGER,lat REAL,lon REAL,raw_json TEXT NOT NULL);
          CREATE TABLE app_source_links(source_record_id TEXT NOT NULL,spot_id INTEGER NOT NULL,canonical_spot_id INTEGER,
            PRIMARY KEY(source_record_id,spot_id),FOREIGN KEY(source_record_id) REFERENCES source_records(id),
            FOREIGN KEY(spot_id) REFERENCES app_records(spot_id));
          CREATE TABLE registry_entries(record_id TEXT PRIMARY KEY,display_name TEXT NOT NULL,name_kind TEXT NOT NULL,
            role TEXT NOT NULL,app_category TEXT,source_state TEXT NOT NULL,reasons_json TEXT NOT NULL,
            identity_review_count INTEGER NOT NULL DEFAULT 0,FOREIGN KEY(record_id) REFERENCES source_records(id));
          CREATE TABLE identity_candidates(left_id TEXT NOT NULL,right_id TEXT NOT NULL,reason TEXT NOT NULL,
            distance_m REAL NOT NULL,PRIMARY KEY(left_id,right_id));
          CREATE VIRTUAL TABLE registry_search USING fts5(record_id UNINDEXED,display_name,category);
          CREATE INDEX identity_right ON identity_candidates(right_id);
          CREATE INDEX registry_roles ON registry_entries(role,source_state,app_category);
          CREATE INDEX app_sources ON app_records(source,source_id);
          CREATE INDEX source_spatial_lookup ON source_records(inside_city,lat,lon);
        """)
        for spot in spots:
            integer=lambda key: int(spot[key]) if spot.get(key) is not None else None
            flag=lambda key: int(spot.get(key) in ("t",True,1,"1","true"))
            db.execute("INSERT INTO app_records VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                (int(spot["id"]),spot["name"],spot["category"],spot.get("source"),spot.get("source_id"),
                 integer("canonical_spot_id"),flag("is_active"),flag("is_recommendable"),
                 float(spot["lat"]) if spot.get("lat") is not None else None,
                 float(spot["lng"]) if spot.get("lng") is not None else None,canonical(spot)))
        db.execute("""INSERT INTO app_source_links SELECT s.id,a.spot_id,a.canonical_spot_id
            FROM app_records a JOIN source_records s ON s.id=a.source||':'||a.source_id""")
        source.row_factory=sqlite3.Row
        rows=source.execute("SELECT * FROM source_records ORDER BY id")
        for row in rows:
            item=entry(row)
            db.execute("INSERT INTO registry_entries VALUES(?,?,?,?,?,?,?,?)",item)
            db.execute("INSERT INTO registry_search VALUES(?,?,?)",(item[0],item[1],row["category"]))
        buckets=defaultdict(list)
        for row in db.execute("""SELECT s.id,s.name,s.category,s.lat,s.lon
            FROM source_records s JOIN registry_entries e ON e.record_id=s.id
            WHERE s.inside_city=1 AND s.name IS NOT NULL AND s.lat IS NOT NULL AND s.lon IS NOT NULL
              AND e.role IN ('destination_or_service','activity_facility') AND e.source_state NOT IN ('inactive','invalid_location') ORDER BY s.id"""):
            name=normalized_name(row[1])
            if len(name)<4:
                continue
            key=(name,row[2])
            for other in buckets[key]:
                distance=distance_km(row[3],row[4],other[3],other[4])*1000
                if distance<=80:
                    left,right=sorted((row[0],other[0]))
                    db.execute("INSERT OR IGNORE INTO identity_candidates VALUES(?,?,?,?)",
                        (left,right,"same_name_category_nearby_requires_review",round(distance,2)))
            buckets[key].append(row)
        db.execute("""UPDATE registry_entries SET identity_review_count=(
            SELECT count(*) FROM identity_candidates i WHERE i.left_id=registry_entries.record_id OR i.right_id=registry_entries.record_id)""")
        source_counts=dict(db.execute("SELECT source,count(*) FROM source_records GROUP BY source"))
        source_count=sum(source_counts.values())
        if db.execute("SELECT count(*) FROM registry_entries").fetchone()[0]!=source_count:
            raise RuntimeError("Not every source record received a registry entry.")
        if db.execute("PRAGMA foreign_key_check").fetchall():
            raise RuntimeError("Registry contains a broken source/app reference.")
        result={
            "status":"complete_local_registry_pending_native_qualification",
            "built_at":datetime.now(timezone.utc).isoformat(),"source_records":source_count,
            "by_source":source_counts,"by_role":dict(db.execute("SELECT role,count(*) FROM source_records GROUP BY role")),
            "by_source_state":dict(db.execute("SELECT source_state,count(*) FROM registry_entries GROUP BY source_state")),
            "app_records":len(spots),"exact_source_links":db.execute("SELECT count(*) FROM app_source_links").fetchone()[0],
            "retained_app_aliases":db.execute("SELECT count(*) FROM app_records WHERE canonical_spot_id IS NOT NULL").fetchone()[0],
            "app_only_records":db.execute("SELECT count(*) FROM app_records a WHERE NOT EXISTS(SELECT 1 FROM app_source_links l WHERE l.spot_id=a.spot_id)").fetchone()[0],
            "source_backed_app_rows_without_inventory_match":db.execute("SELECT count(*) FROM app_records a WHERE a.source IS NOT NULL AND NOT EXISTS(SELECT 1 FROM app_source_links l WHERE l.spot_id=a.spot_id)").fetchone()[0],
            "identity_review_pairs":db.execute("SELECT count(*) FROM identity_candidates").fetchone()[0],
            "containment_evidence":db.execute("SELECT count(*) FROM containment").fetchone()[0],
            "explicit_source_links":db.execute("SELECT count(*) FROM source_links").fetchone()[0],
            "semantic_sha256":semantic_hash(db),"inventory_sha256":inventory_sha256,"snapshot_sha256":snapshot_sha256,
            "source_records_are_not_unique_places":True,"all_source_records_retained":True,
            "new_identity_merges":0,"new_media_approvals":0,"remote_changed":False}
        db.commit()
        db.close()
        source.close()
        if digest(inventory)!=inventory_sha256 or digest(snapshot)!=snapshot_sha256:
            raise RuntimeError("An input changed during consolidation.")
        temporary.rename(output)
        return result
    except BaseException:
        db.close()
        source.close()
        temporary.unlink(missing_ok=True)
        raise
