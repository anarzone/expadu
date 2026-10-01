import copy
import importlib.util
from pathlib import Path
import unittest

HERE = Path(__file__).resolve().parent


def module(name):
    path = HERE / (name + '.py')
    if not path.exists():
        return None
    spec = importlib.util.spec_from_file_location('held_' + name, path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


class SelectionTests(unittest.TestCase):
    def setUp(self):
        self.code = module('selection')
        self.assertIsNotNone(self.code, 'Held legacy-overlap selection is not implemented')
        self.record = {'key': 'osm:node/100', 'source': 'osm', 'source_id': 'node/100',
                       'source_name': None, 'name_kind': 'descriptive', 'category': 'pitch',
                       'role': 'activity_facility', 'tags': {'leisure': 'pitch', 'sport': 'soccer;basketball', 'access': 'yes'},
                       'lat': 50.94, 'lng': 6.95, 'holds': []}
        self.hold = {'key': self.record['key'], 'existing_id': 20, 'holds': ['identity_ambiguity']}
        self.spots = [
            {'id': 20, 'source': 'osm', 'source_id': 'node/100', 'is_active': True, 'is_recommendable': False,
             'canonical_spot_id': None, 'destination_spot_id': None, 'category': 'pitch'},
            {'id': 5, 'source': None, 'source_id': None, 'is_active': False, 'is_recommendable': False,
             'canonical_spot_id': None, 'category': 'pitch'},
        ]
        self.edges = [{'a': self.record['key'], 'b': 'existing:5', 'reason': 'same_category_same_point'}]
        self.observations = []

    def selected(self):
        return self.code.select_candidates([self.hold], self.edges, self.spots, [self.record], self.observations)['records']

    def test_exact_source_refresh_preserves_legacy_ambiguity_and_multiple_sports(self):
        before = copy.deepcopy([self.hold, self.edges, self.spots, self.record])
        result = self.selected()
        self.assertEqual(len(result), 1)
        self.assertEqual(result[0]['existing_id'], 20)
        self.assertEqual(result[0]['legacy_counterpart_ids'], [5])
        self.assertFalse(result[0]['is_recommendable'])
        self.assertEqual(result[0]['tags']['sport'], 'soccer;basketball')
        self.assertEqual([self.hold, self.edges, self.spots, self.record], before)

    def test_active_or_source_backed_or_aliased_counterpart_is_refused(self):
        for changes in [{'is_active': True}, {'is_recommendable': True}, {'source': 'osm'},
                        {'source_id': 'node/200'}, {'canonical_spot_id': 99}]:
            with self.subTest(changes=changes):
                old = self.spots[1].copy()
                self.spots[1].update(changes)
                self.assertEqual(self.selected(), [])
                self.spots[1] = old

    def test_missing_nonlegacy_or_unknown_edges_are_refused(self):
        for edges in [[], [{'a': self.record['key'], 'b': 'osm:node/200'}],
                      [{'a': self.record['key'], 'b': 'existing:999'}]]:
            self.edges = edges
            self.assertEqual(self.selected(), [])

    def test_source_identity_mismatch_and_duplicate_owner_are_refused(self):
        self.spots[0]['source_id'] = 'node/999'
        self.assertEqual(self.selected(), [])
        self.spots[0]['source_id'] = 'node/100'
        self.spots.append({**self.spots[0], 'id': 21})
        self.assertEqual(self.selected(), [])

    def test_existing_eligibility_alias_group_or_category_change_is_refused(self):
        for changes in [{'is_recommendable': True}, {'is_active': False}, {'canonical_spot_id': 5},
                        {'destination_spot_id': 7}, {'category': 'tennis'}]:
            with self.subTest(changes=changes):
                old = self.spots[0].copy(); self.spots[0].update(changes)
                self.assertEqual(self.selected(), [])
                self.spots[0] = old

    def test_named_facility_and_additional_holds_are_not_silently_approved(self):
        self.record['source_name'] = 'Sports club'
        self.assertEqual(self.selected(), [])
        self.record['source_name'] = None
        self.hold['holds'].append('previous_identity_review_unresolved')
        self.assertEqual(self.selected(), [])

    def test_access_booking_closure_and_fee_conflicts_are_refused(self):
        cases = [{'access': None}, {'access': 'private'}, {'access:conditional': 'yes @ (Mo)'},
                 {'foot': 'no'}, {'foot:conditional': 'no @ (Mo)'}, {'opening_hours': ' closed '},
                 {'reservation': 'required'}, {'booking:conditional': 'yes @ (Mo)'},
                 {'fee': 'no', 'charge': '5 EUR'}, {'fee:conditional': 'no @ (Mo)'}, {'fee': 'yes'}]
        for changes in cases:
            with self.subTest(changes=changes):
                old = self.record['tags'].copy(); self.record['tags'].update(changes)
                self.assertEqual(self.selected(), [])
                self.record['tags'] = old

    def test_explicit_free_is_separate_from_unknown_fee(self):
        self.assertEqual(self.selected()[0]['expected_fee'], 'unknown')
        self.record['tags']['fee'] = 'no'
        self.assertEqual(self.selected()[0]['expected_fee'], 'free')

    def test_relation_geometry_requires_separate_review(self):
        self.record['source_id'] = 'relation/100'
        self.record['key'] = 'osm:relation/100'
        self.spots[0]['source_id'] = 'relation/100'
        self.hold['key'] = self.record['key']
        self.edges[0]['a'] = self.record['key']
        self.assertEqual(self.selected(), [])

    def test_withdrawal_history_is_not_reintroduced(self):
        self.observations = [{'spot_id': 20, 'record_kind': 'withdrawal'}]
        self.assertEqual(self.selected(), [])


class FreshEvidenceTests(unittest.TestCase):
    def setUp(self):
        self.code = module('fetch')
        self.assertIsNotNone(self.code, 'Independent source proof is not implemented')
        self.record = {'source_id': 'way/20', 'tags': {'access': 'yes'}, 'lat': 50.94, 'lng': 6.95, 'raw': {'nodes': [1,2,3,1]}}
        self.way = {'type': 'way', 'id': 20, 'visible': True, 'version': '2', 'tags': {'access': 'yes'}, 'nodes': [1, 2, 3, 1]}
        self.nodes = {i: {'visible': True, 'lat': 50.94 + i*.00001, 'lon': 6.95} for i in [1,2,3]}

    def test_matching_proof_retains_geometry_without_claiming_an_entrance(self):
        result = self.code.fresh_evidence(self.record, self.way, self.way, self.nodes)
        self.assertEqual(result['reasons'], [])
        self.assertEqual(result['point_kind'], 'source_center')
        self.assertEqual(result['current']['nodes'], [1,2,3,1])

    def test_changed_tags_topology_missing_nodes_and_hidden_objects_are_refused(self):
        for change in ['tags', 'topology', 'nodes', 'hidden', 'missing']:
            with self.subTest(change=change):
                current=copy.deepcopy(self.way); after=copy.deepcopy(self.way); nodes=copy.deepcopy(self.nodes)
                if change=='tags':current['tags']['access']='private'
                if change=='topology':after['nodes']=[1,3,2,1]
                if change=='nodes':del nodes[2]
                if change=='hidden':current['visible']=False
                if change=='missing':current=None
                self.assertTrue(self.code.fresh_evidence(self.record,current,after,nodes)['reasons'])

    def test_two_matching_fresh_reads_do_not_replace_frozen_way_topology(self):
        for raw in [{'nodes': [1, 3, 2, 1]}, {}, {'nodes': []}, None]:
            with self.subTest(raw=raw):
                record = {**self.record, 'raw': raw}
                result = self.code.fresh_evidence(record, self.way, self.way, self.nodes)
                self.assertIn('frozen_way_topology_missing_or_changed', result['reasons'])

    def test_moved_node_is_refused(self):
        record={**self.record,'source_id':'node/1'}
        current={'type':'node','id':1,'visible':True,'version':'1','tags':record['tags'],'lat':51.1,'lon':6.95}
        self.assertIn('source_point_moved',self.code.fresh_evidence(record,current,None,{})['reasons'])


if __name__ == '__main__':
    unittest.main()
