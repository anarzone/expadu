"""Behavior checks for whole-source retention and conservative local retrieval."""
import hashlib
import importlib.util
import json
from pathlib import Path
import sqlite3
import tempfile
import unittest

HERE = Path(__file__).resolve().parent


def module(name):
    path = HERE / (name + ".py")
    if not path.exists():
        return None
    spec = importlib.util.spec_from_file_location(name, path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def source(key, *, name=None, source="osm", category="pitch", role="activity_facility",
           lat=50.94, lon=6.95, inside=1, status="unknown", fee="unknown",
           access="unknown", sports=None, tags=None):
    facts = {"sports": sports or [], "fee_status": fee, "access_status": access,
             "availability": "unknown", "reservation": None, "membership": None}
    return (source+":"+key, source, key, name, role, category, lat, lon, inside,
            status, "2026-09-28T12:00:00Z", "https://example.test/source/"+key,
            json.dumps(["ODbL-1.0"] if source=="osm" else ["CDLA-Permissive-2.0"]),
            json.dumps(facts), json.dumps({"tags": tags or {}}))


class ConsolidationTest(unittest.TestCase):
    def setUp(self):
        self.builder = module("consolidate")
        self.assertIsNotNone(self.builder, "Whole-inventory consolidation has not been implemented")
        self.query = module("query")
        self.assertIsNotNone(self.query, "Consolidated catalogue query has not been implemented")
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.inventory = self.root/"inventory.sqlite"
        self.snapshot = self.root/"catalogue.json"
        self.output = self.root/"registry.sqlite"
        self.rows = [
            source("node/10", sports=["soccer"], access="public_reported"),
            source("node/11", name="Sports centre", category="sports_centre", role="destination_or_service"),
            source("node/12", name="Private pitch", sports=["soccer"], access="restricted_reported", fee="free_reported"),
            source("node/13", name="Old café", category="cafe", role="destination_or_service", status="inactive_reported"),
            source("node/14", name="River café", category="cafe", role="destination_or_service"),
            source("record-a", source="overture", name="River café", category="cafe", role="destination_or_service", status="open"),
            source("node/15", category="bench", role="supporting_feature"),
            source("node/16", sports=["soccer"], fee="free_reported", access="public_reported", lat=50.95),
            source("node/17", sports=["soccer"], fee="free_reported", access="public_reported",
                   tags={"access:conditional":"yes @ (Mo-Fr 10:00-12:00)"}),
            source("node/18", name="Outside café", category="cafe", role="destination_or_service", inside=0),
        ]
        self.spots = [
            {"id":"2","source":"osm","source_id":"node/11","name":"Sports centre","category":"sports_centre",
             "canonical_spot_id":None,"is_active":"t","is_recommendable":"t","lat":"50.94","lng":"6.95"},
            {"id":"4","source":"osm","source_id":"node/10","name":"Historical source alias","category":"pitch",
             "canonical_spot_id":"2","is_active":"f","is_recommendable":"f","lat":"50.94","lng":"6.95"},
            {"id":"9","source":None,"source_id":None,"name":"Held legacy place","category":"park",
             "canonical_spot_id":None,"is_active":"f","is_recommendable":"f","lat":"50.94","lng":"6.95"},
        ]

    def build(self):
        db=sqlite3.connect(self.inventory)
        db.executescript("""
            CREATE TABLE source_records(id TEXT PRIMARY KEY,source TEXT,source_key TEXT,name TEXT,role TEXT,category TEXT,
              lat REAL,lon REAL,inside_city INTEGER,operating_status TEXT,retrieved_at TEXT,source_url TEXT,
              license_json TEXT,facts_json TEXT,raw_json TEXT);
            CREATE TABLE capabilities(record_id TEXT,activity TEXT,fee_status TEXT,access_status TEXT,availability TEXT,
              free_public_evidence INTEGER,PRIMARY KEY(record_id,activity));
            CREATE TABLE containment(child_id TEXT,parent_id TEXT,basis TEXT,PRIMARY KEY(child_id,parent_id));
            CREATE TABLE source_links(from_id TEXT,to_id TEXT,basis TEXT,PRIMARY KEY(from_id,to_id,basis));
        """)
        db.executemany("INSERT INTO source_records VALUES("+",".join("?"*15)+")",self.rows)
        for row in self.rows:
            f=json.loads(row[13])
            for sport in f["sports"]:
                db.execute("INSERT INTO capabilities VALUES(?,?,?,?,?,?)",
                    (row[0],sport,f["fee_status"],f["access_status"],"unknown",0))
        db.execute("INSERT INTO containment VALUES('osm:node/10','osm:node/11','geometry only')")
        db.commit()
        db.close()
        self.snapshot.write_text(json.dumps({"schema_version":1,"row_format":"postgresql_column_text",
            "exported_at":"2026-10-01T09:18:34Z","tables":{"spots":self.spots}}))
        return self.builder.build(self.inventory,self.snapshot,self.output,
            inventory_sha256=digest(self.inventory),snapshot_sha256=digest(self.snapshot))

    def test_retains_every_source_and_app_record_without_relabeling_raw_count_as_places(self):
        result=self.build()
        self.assertEqual(result["source_records"],10)
        self.assertEqual(result["app_records"],3)
        db=sqlite3.connect(self.output)
        self.assertEqual(db.execute("SELECT count(*) FROM source_records").fetchone()[0],10)
        self.assertEqual(db.execute("SELECT count(*) FROM app_records").fetchone()[0],3)
        self.assertEqual(db.execute("SELECT count(*) FROM containment").fetchone()[0],1)
        db.close()

    def test_links_exact_source_identity_and_preserves_alias_target(self):
        self.build()
        db=sqlite3.connect(self.output)
        self.assertEqual(db.execute("SELECT source_record_id,spot_id,canonical_spot_id FROM app_source_links ORDER BY spot_id").fetchall(),
            [("osm:node/11",2,None),("osm:node/10",4,2)])
        self.assertEqual(db.execute("SELECT is_active,is_recommendable FROM app_records WHERE spot_id=9").fetchone(),(0,0))
        db.close()

    def test_cross_source_same_name_is_a_review_pair_and_not_a_merge(self):
        result=self.build()
        self.assertGreaterEqual(result["identity_review_pairs"],1)
        db=sqlite3.connect(self.output)
        self.assertEqual(db.execute("SELECT count(*) FROM source_records WHERE name='River café'").fetchone()[0],2)
        pairs=db.execute("SELECT left_id,right_id FROM identity_candidates").fetchall()
        self.assertIn(("osm:node/14","overture:record-a"),pairs)
        db.close()

    def test_unnamed_facility_gets_a_descriptive_label_without_inventing_an_official_name(self):
        self.spots=[]
        self.build()
        result=self.query.search(self.output,50.94,6.95,3,activity="soccer")
        item=next(x for x in result["needs_checking"] if x["id"]=="osm:node/10")
        self.assertEqual(item["display_name"],"Football pitch")
        self.assertEqual(item["name_kind"],"descriptive")
        self.assertIsNone(item["source_name"])
        self.assertEqual(item["fee_status"],"unknown")
        self.assertEqual(item["availability"],"unknown")

    def test_restricted_conditional_closed_and_outside_records_cannot_be_recommendations(self):
        self.spots=[]
        self.build()
        result=self.query.search(self.output,50.94,6.95,3,activity="soccer")
        ids={x["id"] for x in result["results"]}
        self.assertNotIn("osm:node/12",ids)
        self.assertNotIn("osm:node/17",ids)
        cafes=self.query.search(self.output,50.94,6.95,3,category="cafe")
        self.assertNotIn("osm:node/13",{x["id"] for x in cafes["results"]})
        self.assertNotIn("osm:node/18",{x["id"] for x in cafes["results"]})

    def test_whitespace_padded_closure_and_restriction_tags_are_excluded(self):
        self.spots=[]
        cases=[{"opening_hours":" closed "},{"opening_hours":" OFF "},
               {"disused":" YES "},{"access":" private "}]
        for number,tags in enumerate(cases):
            self.rows.append(source("padded-"+str(number), sports=["soccer"],
                fee="free_reported",access="public_reported",
                tags={"leisure":"pitch","sport":"soccer","access":"yes","fee":"no",**tags}))
        self.build()
        result=self.query.search(self.output,50.94,6.95,3,activity="soccer")
        ids={x["id"] for x in result["results"]}
        for number in range(len(cases)):
            with self.subTest(tags=cases[number]):
                self.assertNotIn("osm:padded-"+str(number),ids)
        self.assertEqual([x["id"] for x in result["confirmed_free_public"]],["osm:node/16"])

    def test_conflicting_exact_application_targets_require_identity_review(self):
        self.spots=[
            {"id":"20","source":"osm","source_id":"node/16","name":"Active pitch","category":"pitch",
             "canonical_spot_id":None,"is_active":"t","is_recommendable":"t","lat":"50.95","lng":"6.95"},
            {"id":"21","source":"osm","source_id":"node/16","name":"Held pitch","category":"pitch",
             "canonical_spot_id":None,"is_active":"f","is_recommendable":"f","lat":"50.95","lng":"6.95"},
        ]
        self.build()
        result=self.query.search(self.output,50.94,6.95,3,activity="soccer")
        self.assertEqual(result["confirmed_free_public"],[])
        item=next(x for x in result["needs_checking"] if x["id"]=="osm:node/16")
        self.assertEqual({x["spot_id"] for x in item["application_links"]},{20,21})
        self.assertGreater(item["identity_review_count"],0)
        self.assertIn("conflicting_exact_application_targets",item["review_reasons"])
        with sqlite3.connect(self.output) as db:
            self.assertEqual(db.execute("SELECT spot_id,canonical_spot_id,is_active,is_recommendable FROM app_records ORDER BY spot_id").fetchall(),
                [(20,None,1,1),(21,None,0,0)])
        db.close()

    def test_exact_links_to_one_canonical_target_do_not_create_false_conflict(self):
        self.spots=[
            {"id":"20","source":"osm","source_id":"node/16","name":"Active pitch","category":"pitch",
             "canonical_spot_id":None,"is_active":"t","is_recommendable":"t","lat":"50.95","lng":"6.95"},
            {"id":"21","source":"osm","source_id":"node/16","name":"Alias pitch","category":"pitch",
             "canonical_spot_id":"20","is_active":"f","is_recommendable":"f","lat":"50.95","lng":"6.95"},
        ]
        self.build()
        result=self.query.search(self.output,50.94,6.95,3,activity="soccer")
        self.assertEqual([x["id"] for x in result["confirmed_free_public"]],["osm:node/16"])
        self.assertEqual(result["confirmed_free_public"][0]["identity_review_count"],0)
        self.assertEqual(len(result["confirmed_free_public"][0]["application_links"]),2)

    def test_strict_free_selection_happens_before_display_limit(self):
        self.spots=[]
        self.build()
        result=self.query.search(self.output,50.94,6.95,3,activity="soccer",limit=1)
        self.assertEqual([x["id"] for x in result["confirmed_free_public"]],["osm:node/16"])
        self.assertEqual(result["confirmed_free_public_count"],1)
        self.assertEqual(result["needs_checking_count"],1)
        self.assertFalse(any(x["availability"]!="unknown" for x in result["results"]))

    def test_held_application_record_is_not_reactivated_by_older_source_inventory(self):
        self.spots[1]["canonical_spot_id"]=None
        self.build()
        result=self.query.search(self.output,50.94,6.95,3,activity="soccer")
        self.assertNotIn("osm:node/10",{x["id"] for x in result["results"]})

    def test_context_features_do_not_enter_destination_search(self):
        self.build()
        result=self.query.search(self.output,50.94,6.95,3)
        self.assertNotIn("osm:node/15",{x["id"] for x in result["results"]})

    def test_rebuild_is_semantically_stable_and_does_not_change_inputs(self):
        first=self.build()
        source_before=digest(self.inventory)
        snapshot_before=digest(self.snapshot)
        other=self.root/"second.sqlite"
        second=self.builder.build(self.inventory,self.snapshot,other,
            inventory_sha256=source_before,snapshot_sha256=snapshot_before)
        self.assertEqual(first["semantic_sha256"],second["semantic_sha256"])
        self.assertEqual(digest(self.inventory),source_before)
        self.assertEqual(digest(self.snapshot),snapshot_before)

    def test_raw_source_hash_detects_a_changed_registry_copy(self):
        self.build()
        with sqlite3.connect(self.inventory) as source, sqlite3.connect(self.output) as registry:
            self.assertEqual(self.builder.source_hash(source),self.builder.source_hash(registry))
            registry.execute("UPDATE source_records SET name='Changed source copy' WHERE id='osm:node/10'")
            self.assertNotEqual(self.builder.source_hash(source),self.builder.source_hash(registry))

    def test_refuses_changed_input_and_existing_output(self):
        self.build()
        with self.assertRaises(FileExistsError):
            self.builder.build(self.inventory,self.snapshot,self.output,
                inventory_sha256=digest(self.inventory),snapshot_sha256=digest(self.snapshot))
        with self.assertRaises(ValueError):
            self.builder.build(self.inventory,self.snapshot,self.root/"bad.sqlite",
                inventory_sha256="0"*64,snapshot_sha256=digest(self.snapshot))
        self.assertFalse((self.root/"bad.sqlite").exists())

    def test_member_only_reservation_cannot_enter_free_public_results(self):
        self.spots=[]
        row=list(self.rows[7])
        facts=json.loads(row[13]); facts["reservation"]="members_only"
        row[13]=json.dumps(facts); self.rows[7]=tuple(row)
        self.build()
        result=self.query.search(self.output,50.94,6.95,3,activity="soccer")
        self.assertEqual(result["confirmed_free_public"],[])
        self.assertNotIn("osm:node/16",{x["id"] for x in result["results"]})

    def test_rejects_nonfinite_query_coordinates_and_invalid_limits(self):
        self.build()
        for values in [(float("nan"),6.95,3,20),(50.94,float("inf"),3,20),(50.94,6.95,0,20),(50.94,6.95,3,0)]:
            with self.subTest(values=values),self.assertRaises(ValueError):
                self.query.search(self.output,*values[:3],limit=values[3])


if __name__=="__main__":
    unittest.main()
