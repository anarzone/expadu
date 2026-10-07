"""Read local research candidates with source evidence and explicit unknowns."""
import argparse
import json
import math
from pathlib import Path
import re
import sqlite3

from consolidate import distance_km

EXCLUDED={"outside_city","supporting_context","invalid_location","inactive","restricted",
          "requires_booking_review","requires_license_review","requires_currentness_review"}


def search(path,lat,lon,radius_km,*,activity=None,category=None,text=None,limit=20):
    if not all(isinstance(v,(int,float)) and math.isfinite(v) for v in [lat,lon,radius_km]):
        raise ValueError("Finite coordinates and distance are required.")
    if not -90<=lat<=90 or not -180<=lon<=180 or not 0<radius_km<=100 or not isinstance(limit,int) or not 1<=limit<=200:
        raise ValueError("Invalid coordinates, distance or display limit.")
    db=sqlite3.connect(Path(path).resolve().as_uri()+"?mode=ro",uri=True)
    db.row_factory=sqlite3.Row
    try:
        latitude_delta=radius_km/110.5
        longitude_delta=min(180,radius_km/(110.5*max(.01,abs(math.cos(math.radians(lat))))))
        sql="""SELECT s.*,e.display_name,e.name_kind,e.app_category,e.source_state,e.reasons_json,e.identity_review_count
            FROM source_records s JOIN registry_entries e ON e.record_id=s.id
            WHERE s.inside_city=1 AND s.lat BETWEEN ? AND ? AND s.lon BETWEEN ? AND ?"""
        parameters=[lat-latitude_delta,lat+latitude_delta,lon-longitude_delta,lon+longitude_delta]
        if activity:
            sql+=" AND EXISTS(SELECT 1 FROM capabilities c WHERE c.record_id=s.id AND c.activity=?)"
            parameters.append(activity)
        if category:
            sql+=" AND (s.category=? OR e.app_category=?)"
            parameters.extend([category,category])
        if text:
            terms=re.findall(r"\w+",text)
            if not terms:
                raise ValueError("A search term is required.")
            sql+=" AND s.id IN(SELECT record_id FROM registry_search WHERE registry_search MATCH ?)"
            parameters.append(" AND ".join('"'+term.replace('"','""')+'"' for term in terms))
        rows=db.execute(sql,parameters).fetchall()
        matches=[]
        for row in rows:
            if row["source_state"] in EXCLUDED:
                continue
            distance=distance_km(lat,lon,row["lat"],row["lon"])
            if distance>radius_km:
                continue
            links=[dict(x) for x in db.execute("""SELECT l.spot_id,l.canonical_spot_id,a.is_active,a.is_recommendable,
                c.is_active AS canonical_active,c.is_recommendable AS canonical_recommendable
                FROM app_source_links l JOIN app_records a ON a.spot_id=l.spot_id
                LEFT JOIN app_records c ON c.spot_id=l.canonical_spot_id WHERE l.source_record_id=? ORDER BY l.spot_id""",(row["id"],))]
            if links and not any((x["canonical_active"] and x["canonical_recommendable"]) if x["canonical_spot_id"] is not None
                                 else (x["is_active"] and x["is_recommendable"]) for x in links):
                continue
            facts=json.loads(row["facts_json"])
            reasons=json.loads(row["reasons_json"])
            free=(facts.get("fee_status")=="free_reported" and facts.get("access_status")=="public_reported"
                  and "conditional_or_conflicting_fee" not in reasons and row["identity_review_count"]==0)
            matches.append({
                "id":row["id"],"display_name":row["display_name"],"source_name":row["name"],
                "name_kind":row["name_kind"],"category":row["category"],"application_category":row["app_category"],
                "role":row["role"],"lat":row["lat"],"lon":row["lon"],"distance_km":round(distance,3),
                "distance_kind":"straight_line_to_source_point","location_status":"source_point_not_verified_entrance",
                "source":row["source"],"source_url":row["source_url"],"collected_at":row["retrieved_at"],
                "data_licenses":json.loads(row["license_json"]),"fee_status":facts.get("fee_status","unknown"),
                "access_status":facts.get("access_status","unknown"),"availability":"unknown",
                "confirmed_free_public_source_evidence":free,"facts":facts,"review_reasons":reasons,
                "identity_review_count":row["identity_review_count"],"application_links":links,
                "application_readiness":"requires_native_qualification",
                "media_rights":"not_established_by_source_link",
            })
        matches.sort(key=lambda x:(x["distance_km"],x["id"]))
        confirmed=[x for x in matches if x["confirmed_free_public_source_evidence"]]
        uncertain=[x for x in matches if not x["confirmed_free_public_source_evidence"]]
        return {"scope":"local_source_research_not_production_composer",
                "matched_source_records":len(matches),"display_limit":limit,"results":matches[:limit],
                "confirmed_free_public_count":len(confirmed),"confirmed_free_public":confirmed[:limit],
                "needs_checking_count":len(uncertain),"needs_checking":uncertain[:limit],
                "unique_destinations_established":False}
    finally:
        db.close()


if __name__=="__main__":
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("database",type=Path)
    parser.add_argument("--lat",type=float,required=True)
    parser.add_argument("--lon",type=float,required=True)
    parser.add_argument("--radius-km",type=float,default=3)
    parser.add_argument("--activity")
    parser.add_argument("--category")
    parser.add_argument("--text")
    parser.add_argument("--limit",type=int,default=20)
    args=parser.parse_args()
    print(json.dumps(search(args.database,args.lat,args.lon,args.radius_km,
        activity=args.activity,category=args.category,text=args.text,limit=args.limit),indent=2,ensure_ascii=False))
