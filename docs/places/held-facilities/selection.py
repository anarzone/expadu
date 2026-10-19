"""Select existing public facilities without resolving or merging legacy identities."""
from collections import Counter, defaultdict
import copy
import re
import importlib.util
from pathlib import Path

_spec = importlib.util.spec_from_file_location('held_pack', Path(__file__).resolve().parents[1]/'production-pack/prepare.py')
pack = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(pack)


def select_candidates(holds, edges, spots, records, observations):
    by_id = {s['id']: s for s in spots}
    by_key = {r['key']: r for r in records}
    owners = defaultdict(list)
    links = defaultdict(list)
    history = defaultdict(list)
    for s in spots:
        if s.get('source') and s.get('source_id'):
            owners[s['source']+':'+s['source_id']].append(s['id'])
    for e in edges:
        links[e['a']].append(e)
    for o in observations:
        history[o['spot_id']].append(o)
    selected, decisions = [], []
    for hold in holds:
        if hold['holds'] != ['identity_ambiguity'] or hold['existing_id'] is None:
            continue
        record = by_key.get(hold['key'])
        spot = by_id.get(hold['existing_id'])
        reasons = []
        if not record or not spot:
            reasons.append('missing_source_or_target')
        else:
            tags = record['tags']
            value = lambda k: str(tags.get(k) or '').strip().casefold()
            if (record['source'] != 'osm' or not re.fullmatch(r'(node|way)/[0-9]+', record['source_id'])
                    or record['role'] != 'activity_facility'
                    or record['source_name'] or record['holds']):
                reasons.append('outside_unnamed_supported_facility_cohort')
            if owners[hold['key']] != [spot['id']]:
                reasons.append('source_identity_not_unique_or_changed')
            if (not spot['is_active'] or spot['is_recommendable'] or spot['canonical_spot_id'] is not None
                    or spot.get('destination_spot_id') is not None or spot['category'] != record['category']):
                reasons.append('target_not_independent_held_facility')
            if value('access') not in {'yes', 'public', 'permissive'} or value('access:conditional'):
                reasons.append('public_access_not_established')
            if value('foot') in {'no', 'private', 'customers', 'members', 'permit'} or value('foot:conditional'):
                reasons.append('pedestrian_access_restricted_or_conditional')
            reasons.extend(pack.tag_holds(tags))
            if any(value(k) for k in ['booking:conditional', 'reservation:conditional', 'opening_hours:conditional']):
                reasons.append('conditional_activity_requires_review')
            if value('fee') not in {'', 'no'} or (value('charge') and not (value('fee') == 'no' and value('charge') in {'0', '0 eur', '0 €'})):
                reasons.append('unsupported_or_conflicting_fee')
            if any(o.get('record_kind') != 'source' for o in history[spot['id']]):
                reasons.append('source_withdrawal_or_restore_requires_review')
        counterparts = []
        if not links[hold['key']]:
            reasons.append('missing_ambiguity_edges')
        for edge in links[hold['key']]:
            other_id = edge['b'].removeprefix('existing:')
            other = by_id.get(int(other_id)) if edge['b'].startswith('existing:') and other_id.isdigit() else None
            if not other or other.get('source') is not None or other.get('source_id') is not None:
                reasons.append('source_backed_or_unknown_counterpart')
            elif other['is_active'] or other['is_recommendable'] or other['canonical_spot_id'] is not None:
                reasons.append('counterpart_not_unavailable_legacy')
            else:
                counterparts.append(other['id'])
        reasons = sorted(set(reasons))
        decisions.append({'key': hold['key'], 'existing_id': hold['existing_id'], 'reasons': reasons})
        if not reasons:
            prepared = copy.deepcopy(record)
            prepared.update(existing_id=spot['id'], expected_existing_sha256=pack.sha(spot),
                            is_recommendable=False, holds=[], expected_fee='free' if value('fee') == 'no' else 'unknown',
                            original_holds=hold['holds'], ambiguity_evidence=copy.deepcopy(links[hold['key']]),
                            legacy_counterpart_ids=sorted(set(counterparts)))
            selected.append(prepared)
    return {'records': selected, 'decisions': decisions,
            'summary': {'existing_identity_holds_screened': len(decisions), 'selected': len(selected),
                        'by_category': dict(Counter(r['category'] for r in selected)),
                        'by_fee_evidence': dict(Counter(r['expected_fee'] for r in selected)),
                        'refusal_reasons': dict(Counter(reason for d in decisions for reason in d['reasons'])),
                        'legacy_records_changed': 0, 'identity_merges': 0}}
