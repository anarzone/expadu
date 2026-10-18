"""Prepare conservative identity proposals from already retained public evidence.

This does not release holds, modify the frozen package or write a catalogue.
Native reconciliation and consumer-eligibility checks remain mandatory.
"""
import collections
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
PACK = ROOT / 'docs/places/production-pack/2026-09-28'
BASELINE = ROOT / 'storage/app/private/places-research/production-pack-2026-09-28/places-only.json'
OUTPUT = Path(__file__).parent / '2026-09-28'


def prepare():
    manifest = json.loads((PACK / 'manifest.json').read_text())
    assert hashlib.sha256(BASELINE.read_bytes()).hexdigest() == manifest['baseline_sha256']
    rows = {r['id']: r for r in json.loads(BASELINE.read_text())['tables']['spots']}
    held = {
        r['key']: r for r in map(json.loads, (PACK / 'holds.jsonl').open())
        if r['holds'] == ['identity_ambiguity'] and r['existing_id'] is not None
    }
    links = collections.defaultdict(list)
    for link in map(json.loads, (PACK / 'identity-review.jsonl').open()):
        if link['a'] in held:
            links[link['a']].append(link)
    candidates = []
    rejected = collections.Counter()
    for key, hold in held.items():
        canonical = rows[hold['existing_id']]
        edges = links[key]
        reason = None
        if len(edges) != 1 or not edges[0]['b'].startswith('existing:'):
            reason = 'ambiguous_or_nonlegacy_counterpart'
        else:
            edge = edges[0]
            alias = rows[int(edge['b'].split(':')[1])]
            if alias['source'] is not None or alias['source_id'] is not None:
                reason = 'counterpart_has_source'
            elif canonical['source'] != 'osm' or canonical['canonical_spot_id'] is not None or alias['canonical_spot_id'] is not None:
                reason = 'unsupported_existing_identity'
            elif not canonical['is_active'] or not alias['is_active']:
                reason = 'inactive_identity'
            elif canonical['name'] != alias['name'] or canonical['category'] != alias['category']:
                reason = 'name_or_category_differs'
            elif edge.get('a_name') != canonical['name']:
                reason = 'current_source_name_or_label_differs'
            elif edge['distance_m'] > 1:
                reason = 'distance_over_one_metre'
            elif alias['destination_reviewed_at'] is not None or alias['parent_spot_id'] != canonical['parent_spot_id']:
                reason = 'reviewed_or_conflicting_relationship'
            elif (alias['tags'] or {}) != (canonical['tags'] or {}):
                reason = 'retained_source_tags_differ'
            else:
                candidates.append({
                    'alias_id': alias['id'], 'canonical_id': canonical['id'],
                    'name': canonical['name'], 'category': canonical['category'],
                    'current_source_name': edge['a_name'],
                    'source_id': canonical['source_id'], 'source_url': hold['source_url'],
                    'distance_m': edge['distance_m'],
                    'legacy_recommendable': alias['is_recommendable'],
                    'canonical_recommendable': canonical['is_recommendable'],
                    'evidence': 'Unique retained legacy/OSM pair with matching current-source and baseline names, category and practical tags; coordinates within one metre. Native identity, eligibility and live reconciliation checks are still required.',
                })
        if reason:
            rejected[reason] += 1
    alias_use = collections.Counter(r['alias_id'] for r in candidates)
    canonical_use = collections.Counter(r['canonical_id'] for r in candidates)
    accepted = [r for r in candidates if alias_use[r['alias_id']] == canonical_use[r['canonical_id']] == 1]
    rejected['non_unique_pair'] = len(candidates) - len(accepted)
    summary = {
        'scope': 'local retained public evidence; proposals only',
        'baseline_sha256': manifest['baseline_sha256'],
        'package_records_sha256': manifest['records_sha256'],
        'held_existing_identities_screened': len(held),
        'strict_proposals': len(accepted),
        'categories': dict(collections.Counter(r['category'] for r in accepted)),
        'rejected': dict(rejected),
        'staging_changed': False, 'production_changed': False,
    }
    OUTPUT.mkdir(parents=True, exist_ok=True)
    path = OUTPUT / 'identity-proposals.json'
    path.write_text(json.dumps({'summary': summary, 'operations': accepted}, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps(summary, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    prepare()
