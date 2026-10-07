import copy
import json
import unittest
from selection import normalize, select_candidates


def row(source_id='node/1', name='Local Shop', kind='supermarket', lat=50.94, lng=6.95, extra=None):
    tags={'name':name,'shop':kind}|(extra or {})
    raw={'type':source_id.split('/')[0],'id':int(source_id.split('/')[1]),'tags':tags,'lat':lat,'lon':lng}
    return {'id':'osm:'+source_id,'source':'osm','source_key':source_id,'source_url':'https://www.openstreetmap.org/'+source_id,'retrieved_at':'2026-09-28T12:00:00+00:00','license_json':'["ODbL-1.0"]','facts_json':'{}','raw_json':json.dumps(raw),'inside_city':1,'name':name,'lat':lat,'lon':lng}


class SelectionTests(unittest.TestCase):
    def test_named_supported_destination_has_raw_type_and_unknown_facts(self):
        result=select_candidates([row()],[],[],{})
        self.assertEqual(len(result['records']),1)
        record=result['records'][0]
        self.assertEqual(record['category'],'supermarket')
        self.assertEqual(record['observation']['fee']['raw'],None)
        self.assertEqual(record['tags']['shop'],'supermarket')
        self.assertTrue(record['is_recommendable'])
        self.assertIsNone(record['existing_id'])

    def test_missing_name_private_closed_and_unknown_shop_are_not_ready(self):
        inputs=[row('node/1',name=''),row('node/2',extra={'access':'private'}),row('node/3',extra={'disused':'yes'}),row('node/4',kind='vacant'),row('node/5',kind='invented'),row('node/6',extra={'fee:conditional':'no @ (Su)'})]
        self.assertEqual(select_candidates(inputs,[],[],{})['records'],[])

    def test_duplicate_source_objects_hold_both_without_merging(self):
        a=row('node/1');b=row('node/2',lat=50.94003)
        result=select_candidates([a,b],[],[],{})
        self.assertEqual(result['records'],[])
        self.assertEqual(len(result['identity_pairs']),1)
        self.assertEqual(len(result['held']),2)

    def test_known_source_owner_and_manual_hold_remain_held(self):
        a=normalize(row())
        spot={'id':8,'source':'osm','source_id':'node/1','name':'Old Local Shop','category':'park','lat':50.941,'lng':6.95,'tags':{}}
        result=select_candidates([row(),row('node/2',name='Another')],[spot],[],{'osm:node/2':'Unresolved source identity'})
        self.assertEqual(result['records'],[])
        self.assertIn('existing_source_identity_requires_review',result['held'][0]['holds'])
        self.assertIn('manual_identity_review',result['held'][1]['holds'])

    def test_prior_addition_and_inactive_alias_are_in_identity_screen(self):
        addition=normalize(row('node/9'));addition['name']='Local Shop'
        result=select_candidates([row()],[{'id':11,'source':'osm','source_id':'node/100','name':'Unrelated alias','category':'supermarket','lat':50.93,'lng':6.96,'tags':{},'canonical_spot_id':7,'is_active':False}],[addition],{})
        self.assertEqual(result['records'],[])
        self.assertEqual(result['held'][0]['holds'],['identity_ambiguity'])

    def test_far_branches_are_independent_and_inputs_immutable(self):
        inputs=[row(),row('node/2',lat=50.945)];before=copy.deepcopy(inputs)
        result=select_candidates(inputs,[],[],{})
        self.assertEqual(len(result['records']),2)
        self.assertEqual(inputs,before)

    def test_wrong_licence_or_source_identity_is_held(self):
        a=row();a['license_json']='["proprietary"]'
        b=row('node/2');b['source_key']='node/99'
        result=select_candidates([a,b],[],[],{})
        self.assertEqual(result['records'],[])
        self.assertIn('unapproved_data_license',result['held'][0]['holds'])
        self.assertIn('source_identity_differs',result['held'][1]['holds'])

    def test_conflicting_destination_types_are_held(self):
        a=row(extra={'amenity':'doctors'})
        self.assertIn('conflicting_place_types',normalize(a)['holds'])

    def test_identity_chains_include_an_already_held_source(self):
        inputs=[row('node/1',lat=50.94),row('node/2',lat=50.9412),row('node/3',lat=50.9424)]
        self.assertEqual(select_candidates(inputs,[],[],{})['records'],[])

    def test_new_source_near_existing_name_is_held(self):
        s={'id':4,'name':'Local Shop','category':'supermarket','source':None,'source_id':None,'lat':50.9401,'lng':6.95,'tags':{},'is_active':False}
        self.assertEqual(select_candidates([row()],[s],[],{})['records'],[])


if __name__=='__main__':unittest.main()
