"""Read bounded public OSM evidence without contributor identities or database writes."""
from collections import Counter, defaultdict
from datetime import datetime, timezone
import hashlib
import json
import math
import time
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET


def metres(a, b):
    lat = math.radians((a[0]+b[0])/2)
    return math.hypot((a[0]-b[0])*111195, (a[1]-b[1])*111195*math.cos(lat))


def fresh_evidence(record, current, after, nodes):
    reasons = []
    kind, number = record['source_id'].split('/')
    point = None
    if not current or current.get('visible') is not True:
        reasons.append('source_missing_or_hidden')
    else:
        if current.get('type') != kind or current.get('id') != int(number) or not str(current.get('version', '')).isdigit():
            reasons.append('source_identity_or_version_invalid')
        if current['tags'] != record['tags']:
            reasons.append('source_tags_changed')
        if kind == 'node':
            point = [current.get('lat'), current.get('lon')]
            if not all(isinstance(x, (float, int)) and math.isfinite(x) for x in point):
                reasons.append('source_point_missing')
            elif metres(point, [record['lat'], record['lng']]) > 1:
                reasons.append('source_point_moved')
        elif kind == 'way':
            raw = record.get('raw')
            frozen_nodes = raw.get('nodes') if isinstance(raw, dict) else None
            if not isinstance(frozen_nodes, list) or len(frozen_nodes) < 2 or current.get('nodes') != frozen_nodes:
                reasons.append('frozen_way_topology_missing_or_changed')
            if after != current:
                reasons.append('way_changed_during_fetch')
            geometry = [nodes.get(i) for i in current.get('nodes', [])]
            if len(geometry) < 2 or any(not n or not n.get('visible') or 'lat' not in n or 'lon' not in n for n in geometry):
                reasons.append('incomplete_geometry')
        else:
            reasons.append('unsupported_geometry_type')
    return {'source_id': record['source_id'], 'current': current, 'after': after, 'reasons': sorted(set(reasons)),
            'point': point, 'point_kind': 'source_node' if kind == 'node' else 'source_center'}


def collect(records):
    requests = []
    def fetch(kind, ids):
        ids = sorted(set(ids)); found = {}
        for start in range(0, len(ids), 300):
            batch = ids[start:start+300]
            plural = kind+'s'
            url = 'https://api.openstreetmap.org/api/0.6/'+plural+'?'+urllib.parse.urlencode({plural: ','.join(map(str, batch))})
            request = urllib.request.Request(url, headers={'User-Agent': 'Expadu/1.0 (https://expadu.com; contact@expadu.com)'})
            with urllib.request.urlopen(request, timeout=45) as response:
                body = response.read(16*1024*1024)
                if response.status != 200 or len(body) >= 16*1024*1024:
                    raise RuntimeError('Public source response was incomplete')
            requests.append({'type': kind, 'requested': len(batch), 'sha256': hashlib.sha256(body).hexdigest(),
                             'checked_at': datetime.now(timezone.utc).isoformat()})
            for element in ET.fromstring(body):
                if element.tag != kind: continue
                attrs = element.attrib
                row = {'type': kind, 'id': int(attrs['id']), 'visible': attrs.get('visible', 'true') == 'true',
                       'version': attrs.get('version'), 'timestamp': attrs.get('timestamp'),
                       'tags': {tag.attrib['k']: tag.attrib['v'] for tag in element.findall('tag')}}
                if kind == 'node' and 'lat' in attrs and 'lon' in attrs:
                    row.update(lat=float(attrs['lat']), lon=float(attrs['lon']))
                if kind == 'way': row['nodes'] = [int(n.attrib['ref']) for n in element.findall('nd')]
                found[row['id']] = row
            time.sleep(1)
        return found
    groups = defaultdict(list)
    for record in records:
        kind, number = record['source_id'].split('/')
        if kind not in {'node', 'way'} or not number.isdigit(): raise ValueError('Unsupported source identity')
        groups[kind].append(int(number))
    ways = fetch('way', groups['way'])
    node_ids = set(groups['node']) | {i for w in ways.values() for i in w.get('nodes', [])}
    if len(node_ids) > 20000: raise ValueError('Geometry cohort exceeds review bound')
    nodes = fetch('node', node_ids)
    after = fetch('way', groups['way'])
    outcomes = []
    for record in records:
        kind, number = record['source_id'].split('/'); number = int(number)
        outcomes.append(fresh_evidence(record, (nodes if kind == 'node' else ways).get(number), after.get(number), nodes))
    return {'records': outcomes, 'geometry_nodes': nodes,
            'summary': {'status': 'fetched_pending_native_geometry', 'checked_at': datetime.now(timezone.utc).isoformat(),
                        'requested': len(records), 'matching_source_objects': sum(not r['reasons'] for r in outcomes),
                        'refusal_reasons': dict(Counter(x for r in outcomes for x in r['reasons'])),
                        'geometry_nodes': len(nodes), 'requests': requests, 'source': 'https://api.openstreetmap.org/api/0.6',
                        'contributor_identities_retained': False, 'database_changed': False}}
