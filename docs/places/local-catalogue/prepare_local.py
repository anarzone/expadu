"""Add baseline freshness guards to the existing supported-source preparation."""
from collections import Counter
from datetime import datetime, timezone
import importlib.util
import json
from pathlib import Path
import contextlib
import io

spec=importlib.util.spec_from_file_location("supported_prepare",Path(__file__).resolve().parents[1]/"production-pack/prepare.py")
pack=importlib.util.module_from_spec(spec); spec.loader.exec_module(pack)

def timestamp(value, *, native_utc=False):
    parsed=datetime.fromisoformat(value.replace("Z","+00:00"))
    if parsed.tzinfo is None:
        if not native_utc: raise ValueError("Timezone required")
        parsed=parsed.replace(tzinfo=timezone.utc)
    return parsed

def freshness_hold(record,spots,observations):
    if record["existing_id"] is None: return None
    matching=[s for s in spots if s["id"]==record["existing_id"]]
    if len(matching)!=1: return "existing_identity_missing"
    histories=[o for o in observations if o["spot_id"]==record["existing_id"]]
    if any(o.get("record_kind") != "source" for o in histories):
        return "source_withdrawal_or_restore_requires_review"
    try:
        collected=timestamp(record["observed_at"]).replace(microsecond=0)
        known=[timestamp(o["observed_at"]).replace(microsecond=0) for o in histories]
        if matching[0].get("last_seen_at"): known.append(timestamp(matching[0]["last_seen_at"],native_utc=True).replace(microsecond=0))
        if known and max(known)>collected: return "newer_native_source_evidence"
        if known and max(known)==collected: return "source_already_seen_at_same_time"
    except (ValueError,TypeError,AttributeError):
        return "invalid_source_chronology"
    return None

def unchanged_source(record,spots,observations):
    matching=[s for s in spots if s["id"]==record["existing_id"]]
    history=[o for o in observations if o["spot_id"]==record["existing_id"] and o.get("record_kind")=="source"
        and o["provider"]==record["source"] and o["provider_record_id"]==record["source_id"]]
    if len(matching)!=1 or not history: return False
    spot=matching[0]
    latest=max(history,key=lambda o:(timestamp(o["observed_at"]),o["id"]))
    def normalized_payload(value):
        value=dict(value)
        # PHP encodes an empty associative value as []; no negative fact exists
        # in either representation. Nonempty arrays/objects remain distinct.
        if value.get("negative_facts")==[]: value["negative_facts"]={}
        return value
    return (normalized_payload(latest["payload"])==normalized_payload(record["observation"])
        and spot["category"]==record["category"] and spot["tags"]==(record["tags"] or None)
        and abs(float(spot["lat"])-record["lat"])<=0.00000011
        and abs(float(spot["lng"])-record["lng"])<=0.00000011)

def prepare(inventory,baseline,output):
    if output.exists(): raise FileExistsError("Preserve the dated package; choose a fresh output.")
    with contextlib.redirect_stdout(io.StringIO()):
        pack.prepare(inventory,baseline,output)
    document=json.loads(baseline.read_text())
    records=[json.loads(line) for line in (output/"records.jsonl").read_text().splitlines()]
    held=[json.loads(line) for line in (output/"holds.jsonl").read_text().splitlines()]
    selected=[]; unchanged=[]; freshness=Counter()
    for record in records:
        reason=freshness_hold(record,document["tables"]["spots"],document["tables"]["place_fact_observations"])
        if reason=="source_already_seen_at_same_time" and unchanged_source(record,document["tables"]["spots"],document["tables"]["place_fact_observations"]):
            unchanged.append({key:record[key] for key in ("key","existing_id","source_url")})
            continue
        if reason:
            freshness[reason]+=1
            held.append({key:record[key] for key in ("key","name","category","role","existing_id","source_url")} | {"holds":[reason]})
        else: selected.append(record)
    pack.write_lines(output/"records.jsonl",selected)
    pack.write_lines(output/"holds.jsonl",held)
    pack.write_lines(output/"unchanged.jsonl",unchanged)
    manifest=json.loads((output/"manifest.json").read_text())
    manifest.update(records=len(selected),creates=sum(r["existing_id"] is None for r in selected),
        refreshes=sum(r["existing_id"] is not None for r in selected),
        named_records=sum(bool(r["source_name"]) for r in selected),
        destinations=sum(r["role"]=="destination" for r in selected),
        activity_facilities=sum(r["role"]=="activity_facility" for r in selected),
        descriptively_named_facilities=sum(not r["source_name"] for r in selected),
        by_source=dict(Counter(r["source"] for r in selected)),
        by_category=dict(Counter(r["category"] for r in selected)),
        held=len(held),hold_reasons=dict(Counter(reason for r in held for reason in r["holds"])),
        unchanged_current_source_records=len(unchanged),unchanged_records_are_not_a_readiness_count=True,
        freshness_holds=dict(freshness),records_sha256=pack.file_sha(output/"records.jsonl"),
        status="prepared_against_current_local_catalogue_pending_native_rehearsal")
    links=[json.loads(line) for line in (output/"containment-evidence.jsonl").read_text().splitlines()]
    keys={r["key"] for r in selected}
    links=[r for r in links if r["child_id"] in keys]
    pack.write_lines(output/"containment-evidence.jsonl",links)
    manifest["containment_evidence"]=len(links)
    (output/"manifest.json").write_text(json.dumps(manifest,indent=2)+"\n")
    return manifest
