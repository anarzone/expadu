"""Select additional named OSM venues without changing existing identities."""
from collections import Counter, defaultdict
import importlib.util
import json
import math
from pathlib import Path
from categories import OSM_TYPES

spec = importlib.util.spec_from_file_location("catalogue_pack", Path(__file__).resolve().parents[1]/"production-pack/prepare.py")
pack = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pack)
HISTORICAL_HOLDS = {"node/11142034363", "node/2982804906", "way/1387586334", "node/2984033232", "node/823682613", "node/297870881", "node/2061501211", "node/2104817209", "node/14085362440", "node/1252516748", "way/151648492", "node/6063585731"}


def normalize(row):
    if row["source"] != "osm" or not row["inside_city"]:
        return None
    raw = json.loads(row["raw_json"])
    tags = raw.get("tags", {})
    # The previous package owns all previously supported category refreshes.
    if pack.osm_category(tags) is not None:
        return None
    kinds = [(key, tags[key], mapping[tags[key]]) for key, mapping in OSM_TYPES.items() if tags.get(key) in mapping]
    if not kinds:
        return None
    category = kinds[0][2]
    name = pack.text(row["name"])
    holds = pack.tag_holds(tags)
    if len({kind[2] for kind in kinds}) > 1:
        holds.append("conflicting_place_types")
    source_id = row["source_key"]
    if raw.get("type") not in {"node", "way"}:
        holds.append("unsupported_geometry_type")
    if source_id != str(raw.get("type"))+"/"+str(raw.get("id")) or row["id"] != "osm:"+source_id or row["source_url"] != "https://www.openstreetmap.org/"+source_id:
        holds.append("source_identity_differs")
    if not name or len(pack.norm(name)) < 2 or "http" in name.casefold() or "<" in name or name != pack.text(tags.get("name")):
        holds.append("missing_or_invalid_source_name")
    licences = json.loads(row["license_json"])
    if licences != ["ODbL-1.0"]:
        holds.append("unapproved_data_license")
    point = [row["lat"], row["lon"]]
    if not all(isinstance(x, (float, int)) and math.isfinite(x) for x in point) or not (-90 <= point[0] <= 90 and -180 <= point[1] <= 180):
        holds.append("invalid_source_point")
    record = {"key": row["id"], "source": "osm", "source_id": source_id, "source_url": row["source_url"],
              "observed_at": row["retrieved_at"], "licenses": licences, "category": category,
              "source_name": name, "name": name, "name_kind": "source", "role": "destination",
              "lat": point[0], "lng": point[1], "point_kind": "source_node" if raw.get("type") == "node" else "source_center",
              "address": pack.address(tags), "website": pack.http_url(tags.get("contact:website") or tags.get("website")),
              "phone": pack.text(tags.get("contact:phone") or tags.get("phone"), 500), "tags": tags,
              "facts": json.loads(row["facts_json"]), "raw": raw, "holds": holds,
              "source_type": kinds[0][0]+":"+kinds[0][1], "existing_id": None, "expected_existing_sha256": None}
    record["observation"] = pack.payload(record, tags)
    record["observation"]["fee"] |= {"conditional": tags.get("fee:conditional"), "charge": tags.get("charge"), "charge_conditional": tags.get("charge:conditional")}
    record["record_sha256"] = pack.sha(raw)
    return record


def select_candidates(rows, spots, additions, manual_holds):
    records = [record for row in rows if (record := normalize(row)) is not None]
    by_key = {record["key"]: record for record in records}
    if len(by_key) != len(records):
        raise ValueError("Duplicate inventory source identity")
    owners = {(spot.get("source"), spot.get("source_id")) for spot in spots+additions if spot.get("source") and spot.get("source_id")}
    for record in records:
        if (record["source"], record["source_id"]) in owners:
            record["holds"].append("existing_source_identity_requires_review")
        if record["key"] in manual_holds or record["source_id"] in HISTORICAL_HOLDS:
            record["holds"].append("manual_identity_review")
    grid = defaultdict(list)
    def cell(record):
        return math.floor(record["lat"]/.002), math.floor(record["lng"]/.003)
    # Include inactive legacy rows and aliases: their identities are not silently reused.
    for index, spot in enumerate(spots+additions):
        if spot.get("lat") is None or spot.get("lng") is None:
            continue
        tags = spot.get("tags") or {}
        baseline = {**spot, "key": "existing:"+str(index), "source_name": spot.get("source_name") or spot["name"],
                    "address": spot.get("address") or pack.address(tags),
                    "website": spot.get("website") or pack.http_url(tags.get("contact:website") or tags.get("website")),
                    "phone": spot.get("phone") or pack.text(tags.get("contact:phone") or tags.get("phone"), 500), "tags": tags,
                    "lat": float(spot["lat"]), "lng": float(spot["lng"])}
        grid[cell(baseline)].append(pack.comparable(baseline))
    # Every named source object participates, including a source held earlier in a chain.
    for record in records:
        if any(reason in record["holds"] for reason in ["missing_or_invalid_source_name", "invalid_source_point", "source_identity_differs"]):
            continue
        grid[cell(record)].append(pack.comparable(record))
    pairs = []
    seen_pairs = set()
    for record in records:
        if record["holds"]:
            continue
        candidate = pack.comparable(record)
        x, y = cell(candidate)
        for dx in (-1, 0, 1):
            for dy in (-1, 0, 1):
                for other in grid.get((x+dx, y+dy), []):
                    reason = pack.potential_duplicate(candidate, other)
                    if reason:
                        record["holds"].append("identity_ambiguity")
                        pair_key = tuple(sorted([candidate["key"], other["key"]]))
                        if pair_key not in seen_pairs:
                            seen_pairs.add(pair_key)
                            pairs.append({"a": candidate["key"], "b": other["key"], "reason": reason,
                                          "distance_m": round(pack.metres((candidate["lat"],candidate["lng"]),(other["lat"],other["lng"])), 3)})
                        if other["key"] in by_key:
                            by_key[other["key"]]["holds"].append("identity_ambiguity")
    for record in records:
        record["holds"] = sorted(set(record["holds"]))
        record["is_recommendable"] = not record["holds"]
    ready = [record for record in records if not record["holds"]]
    held = [{key: record[key] for key in ("key", "source_id", "name", "category", "source_url", "holds")} for record in records if record["holds"]]
    return {"records": ready, "held": held, "identity_pairs": pairs,
            "summary": {"additional_supported_source_objects": len(records), "selected": len(ready), "held": len(held),
                        "hold_reasons": dict(Counter(reason for record in held for reason in record["holds"])),
                        "by_category": dict(Counter(record["category"] for record in ready)),
                        "by_source_type": dict(Counter(record["source_type"] for record in ready)),
                        "all_new_records_have_source_names": all(record["name_kind"] == "source" for record in ready),
                        "existing_identities_changed": False, "automatic_merges": 0}}
