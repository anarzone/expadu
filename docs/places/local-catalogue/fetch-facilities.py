"""Read current public OSM evidence; never connect to an application database."""
from collections import Counter, defaultdict
from datetime import datetime, timezone
import hashlib
import json
import math
import os
from pathlib import Path
import time
import urllib.request
import urllib.parse
import xml.etree.ElementTree as ET

os.umask(0o077)
root = Path(os.environ['PLACES_LOCAL_PRIVATE'])
assert root.name == '2026-10-01' and root.parent.name == 'places-local'
rows = json.loads((root / 'held-facilities-input-public.json').read_text())
assert len(rows) == 559 and len({r['source_id'] for r in rows}) == 559
calls = []

def fetch(kind, ids):
    result = {}
    ids = sorted(set(ids))
    for offset in range(0, len(ids), 400):
        batch = ids[offset:offset + 400]
        plural = kind + 's'
        url = 'https://api.openstreetmap.org/api/0.6/' + plural + '?' + urllib.parse.urlencode({plural: ','.join(map(str, batch))})
        req = urllib.request.Request(url, headers={'User-Agent': 'Expadu/1.0 (https://expadu.com; contact@expadu.com)'})
        with urllib.request.urlopen(req, timeout=45) as res:
            body = res.read(16 * 1024 * 1024)
            status = res.status
        assert status == 200 and len(body) < 16 * 1024 * 1024
        calls.append({'type': kind, 'requested': len(batch), 'status': status, 'sha256': hashlib.sha256(body).hexdigest(), 'checked_at': datetime.now(timezone.utc).isoformat()})
        for element in ET.fromstring(body):
            if element.tag != kind:
                continue
            a = element.attrib
            row = {'type': kind, 'id': int(a['id']), 'visible': a.get('visible', 'true') == 'true', 'version': a.get('version'), 'timestamp': a.get('timestamp'), 'tags': {t.attrib['k']: t.attrib['v'] for t in element.findall('tag')}}
            if kind == 'node' and 'lat' in a and 'lon' in a:
                row.update(lat=float(a['lat']), lon=float(a['lon']))
            if kind == 'way':
                row['nodes'] = [int(n.attrib['ref']) for n in element.findall('nd')]
            result[row['id']] = row
        time.sleep(1)
    return result

def distance(a, b):
    x, y = math.radians(a[0]), math.radians(b[0])
    return 6371000 * 2 * math.asin(min(1, math.sqrt(math.sin((y - x) / 2) ** 2 + math.cos(x) * math.cos(y) * math.sin(math.radians(b[1] - a[1]) / 2) ** 2)))

groups = defaultdict(list)
for row in rows:
    kind, number = row['source_id'].split('/')
    assert kind in {'node', 'way'} and number.isdigit() and row['source'] == 'osm'
    groups[kind].append(int(number))
ways = fetch('way', groups['way'])
node_ids = set(groups['node']) | {node for way in ways.values() for node in way.get('nodes', [])}
assert len(node_ids) < 10000
nodes = fetch('node', node_ids)
# Refetch way topology after fetching its member nodes. Changed topology is held.
ways_after = fetch('way', groups['way'])
outcomes = []
counts = Counter()
for record in rows:
    kind, number = record['source_id'].split('/')
    element = (nodes if kind == 'node' else ways).get(int(number))
    reasons = []
    point = None
    if element is None or not element['visible']:
        reasons.append('not_currently_visible')
    else:
        if element['tags'] != record['tags']:
            reasons.append('tags_changed')
        if kind == 'node':
            point = [element.get('lat'), element.get('lon')]
        else:
            if ways_after.get(int(number)) != element:
                reasons.append('way_changed_during_fetch')
            geometry = [nodes.get(n) for n in element['nodes']]
            if not geometry or any(n is None or not n['visible'] or 'lat' not in n for n in geometry):
                reasons.append('incomplete_geometry')
            else:
                point = [(min(n['lat'] for n in geometry) + max(n['lat'] for n in geometry)) / 2,
                         (min(n['lon'] for n in geometry) + max(n['lon'] for n in geometry)) / 2]
        if point is None or None in point:
            reasons.append('missing_point')
        elif distance(point, [record['lat'], record['lng']]) > 1:
            reasons.append('point_changed_over_one_metre')
    outcome = {'key': record['key'], 'source_id': record['source_id'], 'category': record['category'],
               'status': 'unchanged' if not reasons else 'review', 'reasons': reasons, 'current': element,
               'fresh_point': point, 'point_kind': 'source_node' if kind == 'node' else 'source_center',
               'distance_metres': None if point is None or None in point else round(distance(point, [record['lat'], record['lng']]), 4),
               'way_after': ways_after.get(int(number)) if kind == 'way' else None}
    outcome['sha256'] = hashlib.sha256(json.dumps(outcome, sort_keys=True).encode()).hexdigest()
    outcomes.append(outcome)
    counts[outcome['status']] += 1
summary = {'status': 'completed', 'checked_at': datetime.now(timezone.utc).isoformat(), 'requested': len(rows),
           'outcomes': dict(counts), 'reason_counts': dict(Counter(reason for r in outcomes for reason in r['reasons'])),
           'current_access_raw': dict(Counter((r['current'] or {}).get('tags', {}).get('access', 'unknown') for r in outcomes)),
           'current_fee_raw': dict(Counter((r['current'] or {}).get('tags', {}).get('fee', 'unknown') for r in outcomes)),
           'geometry_nodes_checked': len(nodes), 'requests': calls, 'source': 'https://api.openstreetmap.org/api/0.6',
           'geometry_meaning': 'source node or recomputed way bounding-box centre; no entrance verification',
           'contributor_identity_retained': False, 'database_changed': False, 'raw_rows_exported': False}
(root / 'facility-source-private.json').write_text(json.dumps({'summary': summary, 'records': outcomes, 'geometry_nodes': nodes}, indent=2))
(root / 'facility-source-summary.json').write_text(json.dumps(summary, indent=2))
print(json.dumps(summary))
