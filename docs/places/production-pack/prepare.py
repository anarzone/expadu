"""Prepare a frozen, additive place release; never mutate the application DB.

Matching flags ambiguity for review. It never merges distinct source identities.
"""
import argparse
from collections import Counter, defaultdict
from datetime import datetime, timezone
from difflib import SequenceMatcher
import hashlib
import json
import math
from pathlib import Path
import re
import sqlite3
import unicodedata
from urllib.parse import quote, urlsplit, urlunsplit


OSM_DIRECT = {
    "amenity": {k: k for k in ("cafe", "restaurant", "fast_food", "bar", "library", "bbq")},
    "shop": {"bakery": "bakery"},
    "leisure": {k: k for k in ("park", "playground", "sports_centre", "dog_park", "skatepark")},
    "tourism": {k: k for k in ("museum", "gallery", "attraction", "viewpoint", "zoo")},
}
OVERTURE_MAP = {
    "restaurant": "restaurant", "cafe": "cafe", "coffee_shop": "cafe",
    "fast_food_restaurant": "fast_food", "bar": "bar", "art_gallery": "gallery",
    "museum": "museum", "park": "park", "playground": "playground",
    "sports_complex": "sports_centre", "library": "library", "zoo": "zoo",
    "dog_park": "dog_park", "skate_park": "skatepark", "swimming_pool": "swimming",
}
SPORT_MAP = {"soccer": "pitch", "football": "pitch", "basketball": "basketball", "tennis": "tennis", "table_tennis": "table_tennis", "boules": "boules", "skateboard": "skatepark", "multi": "pitch"}
FACILITIES = {"playground", "pitch", "basketball", "tennis", "table_tennis", "boules", "dog_park", "bbq", "picnic", "skatepark"}
LABELS = {"playground": "Playground", "pitch": "Sports pitch", "basketball": "Basketball court", "tennis": "Tennis court", "table_tennis": "Table tennis", "boules": "Boules court", "dog_park": "Dog park", "bbq": "BBQ area", "picnic": "Picnic area", "skatepark": "Skatepark"}
RESTRICTED = {"no", "private", "customers", "members", "permit", "destination", "agricultural", "forestry", "delivery"}
IGNORED_NAME_WORDS = {"cafe", "restaurant", "bar", "koeln", "cologne", "gmbh", "und", "the", "café"}
SOURCE_LICENSES = {"osm": ["ODbL-1.0"], "overture": ["CDLA-Permissive-2.0", "Apache-2.0", "CC0-1.0"]}


def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def sha(value):
    return hashlib.sha256(canonical(value).encode()).hexdigest()


def file_sha(path):
    with path.open("rb") as handle:
        return hashlib.file_digest(handle, "sha256").hexdigest()


def norm(value):
    value = unicodedata.normalize("NFKD", str(value or "").casefold().replace("ß", "ss"))
    return " ".join(re.findall(r"[a-z0-9]+", "".join(c for c in value if not unicodedata.combining(c))))


def core_name(value):
    normalized = norm(value)
    return " ".join(w for w in normalized.split() if w not in IGNORED_NAME_WORDS) or normalized


def text(value, limit=255):
    value = value.strip() if isinstance(value, str) else None
    return value if value and len(value) <= limit else None


def http_url(value):
    value = text(value, 500)
    if not value:
        return None
    try:
        parsed = urlsplit(value)
        if parsed.scheme not in {"http", "https"} or not parsed.hostname or parsed.username or parsed.password:
            return None
        host = parsed.hostname.encode("idna").decode("ascii")
        if not re.fullmatch(r"[A-Za-z0-9.-]+", host):
            return None
        netloc = host + (":" + str(parsed.port) if parsed.port else "")
        return urlunsplit((parsed.scheme, netloc, quote(parsed.path, safe="/%:@!$&'()*+,;=-._~"), quote(parsed.query, safe="%=&?/:@!$'()*+,;~-._"), quote(parsed.fragment, safe="%/?=&:@!$'()*+,;~-._")))
    except (ValueError, UnicodeError):
        return None


def metres(a, b):
    lat = math.radians((a[0] + b[0]) / 2)
    return math.hypot((a[0] - b[0]) * 111195, (a[1] - b[1]) * 111195 * math.cos(lat))


def osm_category(tags):
    if tags.get("leisure") == "pitch":
        sports = set(tags.get("sport", "").split(";"))
        for sport, category in SPORT_MAP.items():
            if sport in sports:
                return category
        return None
    if tags.get("amenity") == "coworking_space" or tags.get("office") == "coworking":
        return "coworking"
    if tags.get("leisure") == "swimming_area" or (tags.get("leisure") == "sports_centre" and tags.get("sport") == "swimming"):
        return "swimming"
    if tags.get("leisure") == "picnic_table" or tags.get("tourism") == "picnic_site":
        return "picnic"
    for key, mapping in OSM_DIRECT.items():
        if tags.get(key) in mapping:
            return mapping[tags[key]]
    return None


def tag_holds(tags):
    value = lambda key: (text(tags.get(key), 500) or "").lower()
    holds = []
    if value("access") in RESTRICTED or value("access:conditional"):
        holds.append("restricted_or_conditional_access")
    if any(value(k) for k in ("fee:conditional", "charge:conditional")):
        holds.append("conditional_fee_not_supported_by_deployed_resolver")
    if value("fee") == "no" and value("charge") not in {"", "0", "0 eur", "0 €"}:
        holds.append("conflicting_fee_and_charge")
    if value("fee") == "yes" and value("charge") in {"0", "0 eur", "0 €"}:
        holds.append("conflicting_fee_and_charge")
    if value("reservation") not in {"", "no", "yes", "recommended"} or value("membership") not in {"", "no"}:
        holds.append("booking_or_membership_requires_supported_constraints")
    if any(value(k) == "yes" for k in ("disused", "abandoned", "demolished", "construction")) or any(k.startswith(("disused:", "abandoned:", "demolished:", "construction:")) for k in tags):
        holds.append("lifecycle_requires_review")
    if any(value(k) for k in ("end_date", "closing_date")):
        holds.append("dated_lifecycle_requires_review")
    if value("opening_hours") in {"closed", "off"}:
        holds.append("closed_hours")
    return holds


def address(tags):
    street = " ".join(str(tags.get(k, "")).strip() for k in ("addr:street", "addr:housenumber")).strip()
    locality = " ".join(str(tags.get(k, "")).strip() for k in ("addr:postcode", "addr:city")).strip()
    return text(", ".join(x for x in (street, locality) if x), 500)


def payload(record, tags):
    aliases = []
    for key, value in tags.items():
        if key in {"alt_name", "short_name", "official_name", "local_name", "old_name"} or re.fullmatch(r"name:[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*", key):
            aliases.extend(v.strip() for v in value.split(";") if text(v) and v.strip() != record["source_name"])
    return {
        "name": record["source_name"], "aliases": sorted(set(aliases))[:50],
        "location": {"lat": record["lat"], "lng": record["lng"], "kind": record["point_kind"], "boundary_reference": record["source_id"] if record["point_kind"] == "source_center" else None},
        "access": {"raw": text(tags.get("access"), 500), "conditional": text(tags.get("access:conditional"), 500)},
        "fee": {"raw": text(tags.get("fee"))},
        "hours": {"raw": text(tags.get("opening_hours"), 500)},
        "contact": {"address": record["address"], "website": record["website"], "phone": record["phone"]},
        "description": text(tags.get("description") or tags.get("description:en"), 1000),
        "negative_facts": {k: tags[k] for k in ("covered", "drinking_water", "indoor", "lit", "wheelchair") if tags.get(k, "").lower() in {"no", "false", "0"}},
    }


def normalized(row):
    raw = json.loads(row["raw_json"])
    tags = raw.get("tags", {}) if row["source"] == "osm" else {}
    facts = json.loads(row["facts_json"])
    category = osm_category(tags) if row["source"] == "osm" else OVERTURE_MAP.get(row["category"])
    if not category:
        return None
    source_name = text(row["name"])
    record = {
        "key": row["id"], "source": row["source"], "source_id": row["source_key"],
        "source_url": row["source_url"], "observed_at": row["retrieved_at"],
        "licenses": json.loads(row["license_json"]), "category": category,
        "source_name": source_name, "name": source_name or LABELS.get(category),
        "name_kind": "source" if source_name else "descriptive",
        "role": "activity_facility" if category in FACILITIES else "destination",
        "lat": row["lat"], "lng": row["lon"],
        "point_kind": "source_node" if row["source"] == "osm" and raw["type"] == "node" else ("source_center" if row["source"] == "osm" else "legacy_unknown"),
        "address": address(tags), "website": http_url(tags.get("contact:website") or tags.get("website")),
        "phone": text(tags.get("contact:phone") or tags.get("phone"), 500),
        "tags": tags, "facts": facts, "raw": raw, "holds": tag_holds(tags),
    }
    if not record["name"] or (source_name and (len(norm(source_name)) < 2 or "http" in source_name.lower() or "<" in source_name)):
        record["holds"].append("missing_or_invalid_name")
    if not set(record["licenses"]).issubset(SOURCE_LICENSES[record["source"]]):
        record["holds"].append("unapproved_data_license")
    if record["source"] == "overture":
        p = raw["properties"]
        record["point_kind"] = "legacy_unknown"
        record["address"] = text(", ".join(str(p["addresses"][0].get(k, "") or "") for k in ("freeform", "postcode", "locality")).strip(", "), 500) if p.get("addresses") else None
        record["website"] = next((u for u in (http_url(v) for v in p.get("websites") or []) if u), None)
        record["phone"] = next((v for v in (text(v, 500) for v in p.get("phones") or []) if v), None)
        record["tags"] = {"overture:basic_category": row["category"], "overture:operating_status": row["operating_status"]}
        if row["operating_status"] != "open":
            record["holds"].append("not_reported_open")
        if p.get("confidence", 0) < 0.95:
            record["holds"].append("existence_confidence_below_0.95")
        if not record["address"] or not (record["website"] or record["phone"]):
            record["holds"].append("insufficient_contact_and_address")
    record["observation"] = payload(record, tags)
    record["record_sha256"] = sha(raw)
    return record


def comparable(record):
    return {
        "key": record["key"], "name": record.get("source_name") or "", "category": record["category"],
        "lat": record["lat"], "lng": record["lng"], "phone": re.sub(r"\D", "", record.get("phone") or ""),
        "website": (record.get("website") or "").lower().rstrip("/"),
        "address": norm(record.get("address")), "tags": record.get("tags") or {},
        "address_line": norm((record.get("address") or "").split(",", 1)[0]),
        "source": record.get("source"), "source_id": record.get("source_id"),
    }


def potential_duplicate(a, b):
    if a["key"] == b["key"] or (a["source"] and a["source"] == b["source"] and a["source_id"] == b["source_id"]):
        return None
    distance = metres((a["lat"], a["lng"]), (b["lat"], b["lng"]))
    if distance > 150:
        return None
    an, bn = core_name(a["name"]), core_name(b["name"])
    named = bool(an and bn)
    if named and (an == bn or (min(len(an), len(bn)) >= 5 and SequenceMatcher(None, an, bn).ratio() >= .88)):
        return "similar_name_nearby"
    generic = {"bistro", "ristorante", "pizzeria", "imbiss", "grill", "bakery", "backerei", "konditorei", "haus"}
    shared_name_tokens = {word for word in set(an.split()) & set(bn.split()) if len(word) >= 4 and word not in generic}
    if distance < 30 and a["category"] == b["category"] and a["address_line"] and a["address_line"] == b["address_line"] and shared_name_tokens:
        return "same_address_distinctive_name_overlap"
    if distance < 80 and a["category"] == b["category"] and (
        (a["phone"] and a["phone"] == b["phone"]) or
        (a["website"] and a["website"] == b["website"])
    ):
        return "same_contact_nearby"
    if distance < 2 and a["category"] == b["category"]:
        return "same_category_same_point"
    if distance < 12 and a["category"] in FACILITIES and a["category"] == b["category"] and not named and (a["source_id"] or "").split("/")[0] != (b["source_id"] or "").split("/")[0]:
        return "nearby_facility_node_and_area"
    return None


def write_lines(path, records):
    with path.open("w") as handle:
        for record in records:
            handle.write(canonical(record) + "\n")


def prepare(inventory, baseline, output):
    output.mkdir(parents=True, exist_ok=True)
    expected_inventory = json.loads((inventory.parent / "summary.json").read_text())["database_sha256"]
    if file_sha(inventory) != expected_inventory:
        raise ValueError("Inventory checksum changed after validated collection")
    baseline_doc = json.loads(baseline.read_text())
    spots = baseline_doc["tables"]["spots"]
    source_map = defaultdict(list)
    for spot in spots:
        if spot["source"] and spot["source_id"]:
            source_map[spot["source"] + ":" + spot["source_id"]].append(spot)
    db = sqlite3.connect(f"file:{inventory}?mode=ro", uri=True)
    db.row_factory = sqlite3.Row
    counts = Counter()
    records = []
    review_path = Path(__file__).with_name("review-holds.json")
    review_holds = json.loads(review_path.read_text()) if review_path.exists() else {}
    for row in db.execute("SELECT * FROM source_records WHERE source IN ('osm','overture') AND inside_city=1 ORDER BY source,id"):
        counts[row["source"] + ":inside_city"] += 1
        record = normalized(row)
        if record is None:
            counts[row["source"] + ":not_in_current_category_contract"] += 1
            continue
        if record["key"] in review_holds:
            record["holds"].append("manual_category_or_venue_identity_review")
            record["review_note"] = review_holds[record["key"]]
        existing = source_map.get(record["key"], [])
        if len(existing) > 1:
            record["holds"].append("multiple_existing_rows_for_source_identity")
        record["existing_id"] = existing[0]["id"] if len(existing) == 1 else None
        record["expected_existing_sha256"] = sha(existing[0]) if len(existing) == 1 else None
        if len(existing) == 1:
            old = existing[0]
            if old["canonical_spot_id"] is not None:
                record["holds"].append("existing_alias_requires_canonical_refresh_review")
            if not old["is_active"]:
                record["holds"].append("existing_inactive_identity")
            if old["category"] != record["category"]:
                record["holds"].append("existing_category_change")
            if metres((old["lat"], old["lng"]), (record["lat"], record["lng"])) > 100:
                record["holds"].append("existing_location_moved_over_100m")
        records.append(record)

    # Include all existing canonical catalogue rows in the ambiguity screen,
    # even inactive and untrusted legacy entries. They are not promoted.
    grid = defaultdict(list)
    def cell(item):
        return (math.floor(item["lat"] / .002), math.floor(item["lng"] / .003))
    for s in spots:
        if s["canonical_spot_id"] is not None or s["lat"] is None or s["lng"] is None:
            continue
        grid[cell(s)].append(comparable({**s, "key": f"existing:{s['id']}", "source_name": s["name"]}))
    records_by_key = {r["key"]: r for r in records}
    matches = []
    for r in records:
        if r["holds"]:
            continue
        a = comparable(r)
        x, y = cell(a)
        for i in range(x-1, x+2):
            for j in range(y-1, y+2):
                for b in grid.get((i, j), []):
                    why = potential_duplicate(a, b)
                    if not why:
                        continue
                    matches.append({"a": a["key"], "b": b["key"], "a_name": a["name"], "b_name": b["name"], "reason": why, "distance_m": round(metres((a["lat"], a["lng"]), (b["lat"], b["lng"])), 1)})
                    r["holds"].append("identity_ambiguity")
                    # Prefer OSM over an additional independent Overture row;
                    # do not hold a selected OSM row merely for cross-source overlap.
                    if b["key"] in records_by_key and b["source"] == r["source"]:
                        records_by_key[b["key"]]["holds"].append("identity_ambiguity")
        grid[cell(a)].append(a)

    # Preserve the earlier explicit identity/closure review holds.
    historical_hold_ids = {"node/11142034363", "node/2982804906", "way/1387586334", "node/2984033232", "node/823682613", "node/297870881", "node/2061501211", "node/2104817209", "node/14085362440", "node/1252516748", "way/151648492", "node/6063585731"}
    for r in records:
        if r["source"] == "osm" and r["source_id"] in historical_hold_ids:
            r["holds"].append("previous_identity_review_unresolved")
        r["holds"] = sorted(set(r["holds"]))
        r["is_recommendable"] = bool(r["source_name"] and not r["holds"])
        if r["facts"].get("fee_status") == "free_reported" and r["facts"].get("access_status") not in {"public_reported", "permissive_reported"} and r["role"] == "activity_facility":
            r["holds"].append("free_activity_without_public_access_evidence")
            r["is_recommendable"] = False
    ready = [r for r in records if not r["holds"]]
    held = [{**{k: r[k] for k in ("key", "name", "category", "role", "existing_id", "source_url", "holds")}, **({"review_note": r["review_note"]} if "review_note" in r else {})} for r in records if r["holds"]]
    write_lines(output / "records.jsonl", ready)
    write_lines(output / "holds.jsonl", held)
    write_lines(output / "identity-review.jsonl", matches)
    ready_keys = {r["key"] for r in ready}
    links = [dict(row) for row in db.execute("SELECT * FROM containment ORDER BY child_id,parent_id") if row["child_id"] in ready_keys]
    write_lines(output / "containment-evidence.jsonl", links)
    summary = {
        "schema_version": 1, "prepared_at": datetime.now(timezone.utc).isoformat(),
        "status": "prepared_pending_native_rehearsal", "live_changed": False,
        "input_counts": dict(counts), "baseline_exported_at": baseline_doc["exported_at"],
        "baseline_sha256": file_sha(baseline), "inventory_sha256": file_sha(inventory),
        "records_sha256": file_sha(output / "records.jsonl"),
        "records": len(ready), "named_records": sum(bool(r["source_name"]) for r in ready),
        "destinations": sum(r["role"] == "destination" for r in ready),
        "activity_facilities": sum(r["role"] == "activity_facility" for r in ready),
        "descriptively_named_facilities": sum(not r["source_name"] for r in ready),
        "creates": sum(r["existing_id"] is None for r in ready),
        "refreshes": sum(r["existing_id"] is not None for r in ready),
        "by_source": dict(Counter(r["source"] for r in ready)),
        "by_category": dict(Counter(r["category"] for r in ready)),
        "held": len(held), "hold_reasons": dict(Counter(reason for r in held for reason in r["holds"])),
        "containment_evidence": len(links), "containment_promoted_automatically": False,
        "photos_approved": 0,
        "licensing": {"osm": "https://www.openstreetmap.org/copyright", "overture": "https://docs.overturemaps.org/attribution/#places"},
    }
    (output / "manifest.json").write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n")
    print(json.dumps(summary, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--inventory", type=Path, required=True)
    parser.add_argument("--baseline", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    prepare(args.inventory, args.baseline, args.output)
