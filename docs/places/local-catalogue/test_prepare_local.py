"""Prevent the older research collection from replacing newer native evidence."""
import importlib.util
from pathlib import Path
import unittest

class FreshnessTest(unittest.TestCase):
    def setUp(self):
        path=Path(__file__).with_name("prepare_local.py")
        self.assertTrue(path.exists(),"Native baseline freshness guard is missing")
        spec=importlib.util.spec_from_file_location("prepare_local",path)
        self.policy=importlib.util.module_from_spec(spec); spec.loader.exec_module(self.policy)
        self.record={"key":"osm:node/1","existing_id":7,"observed_at":"2026-09-28T12:00:00Z"}
        self.spots=[{"id":7,"source":"osm","source_id":"node/1","last_seen_at":"2026-09-27T12:00:00+00:00"}]

    def test_newer_observation_prevents_older_projection_refresh(self):
        observations=[{"spot_id":7,"provider":"osm","provider_record_id":"node/1","record_kind":"source","observed_at":"2026-09-30T12:00:00Z"}]
        self.assertEqual(self.policy.freshness_hold(self.record,self.spots,observations),"newer_native_source_evidence")

    def test_withdrawal_history_is_not_bypassed_by_reimport(self):
        observations=[{"spot_id":7,"provider":"osm","provider_record_id":"node/1","record_kind":"withdrawal","observed_at":"2026-09-27T11:00:00Z"}]
        self.assertEqual(self.policy.freshness_hold(self.record,self.spots,observations),"source_withdrawal_or_restore_requires_review")

    def test_equal_source_timestamp_does_not_rewrite_data(self):
        self.spots[0]["last_seen_at"]=self.record["observed_at"]
        self.assertEqual(self.policy.freshness_hold(self.record,self.spots,[]),"source_already_seen_at_same_time")

    def test_native_utc_timestamp_without_offset_is_compared_correctly(self):
        self.spots[0]["last_seen_at"]="2026-09-30T12:00:00"
        self.assertEqual(self.policy.freshness_hold(self.record,self.spots,[]),"newer_native_source_evidence")

    def test_native_second_precision_does_not_turn_the_same_capture_into_a_refresh(self):
        self.record["observed_at"]="2026-09-28T12:00:00.740185Z"
        self.spots[0]["last_seen_at"]="2026-09-28T12:00:00"
        self.assertEqual(self.policy.freshness_hold(self.record,self.spots,[]),"source_already_seen_at_same_time")

    def test_unchanged_source_accepts_only_empty_negative_fact_representation_difference(self):
        record={**self.record,"source":"osm","source_id":"node/1","category":"pitch","lat":50.94,"lng":6.95,
                "tags":{"leisure":"pitch","sport":"soccer"},"observation":{"name":None,"negative_facts":{}}}
        spot={**self.spots[0],"category":"pitch","lat":"50.9400000","lng":"6.9500000","tags":record["tags"]}
        observations=[{"id":1,"spot_id":7,"provider":"osm","provider_record_id":"node/1","record_kind":"source",
            "observed_at":"2026-09-28T12:00:00Z","payload":{"name":None,"negative_facts":[]}}]
        self.assertTrue(self.policy.unchanged_source(record,[spot],observations))
        observations[0]["payload"]["name"]="A different name"
        self.assertFalse(self.policy.unchanged_source(record,[spot],observations))

    def test_invalid_native_timestamp_fails_closed(self):
        self.spots[0]["last_seen_at"]="not-a-date"
        self.assertEqual(self.policy.freshness_hold(self.record,self.spots,[]),"invalid_source_chronology")

    def test_strictly_newer_source_and_new_identity_can_be_prepared(self):
        self.assertIsNone(self.policy.freshness_hold(self.record,self.spots,[{"spot_id":7,"record_kind":"source","observed_at":"2026-09-27T12:00:00Z"}]))
        self.record["existing_id"]=None
        self.assertIsNone(self.policy.freshness_hold(self.record,self.spots,[]))

if __name__=="__main__": unittest.main()
