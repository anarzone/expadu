"""Inspect specifically discovered public venue pages, without fetching media.

Produces review proposals, never catalogue writes. Existing OSM identities and
coordinates remain the place source; venue pages supply limited fee/audience
evidence. Descriptions, photographs and a provider's full database are not copied.
"""
import concurrent.futures
import hashlib
import html
import json
import math
import re
import sqlite3
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
OUTPUT = Path(__file__).parent / '2026-09-28'
SOURCES = {
    88419: 'https://www.guterstart.nrw.de/fhiangebot/details/id/88419',
    89938: 'https://www.guterstart.nrw.de/fhiangebot/details/id/89938',
    89802: 'https://www.guterstart.nrw.de/fhiangebot/details/id/89802',
    89929: 'https://www.guterstart.nrw.de/fhiangebot/details/id/89929',
    89944: 'https://www.guterstart.nrw.de/fhiangebot/details/id/89944',
    88404: 'https://www.guterstart.nrw.de/fhiangebot/details/id/88404',
    88441: 'https://www.fruehehilfen-online.nrw.de/fhiangebot/details/id/88441',
    88407: 'https://www.guterstart.nrw.de/fhiangebot/details/id/88407',
    88553: 'https://www.fruehehilfen-online.nrw.de/fhiangebot/details/id/88553',
    88509: 'https://www.fruehehilfen-online.nrw.de/fhiangebot/details/id/88509',
    89815: 'https://www.fruehehilfen-online.nrw.de/fhiangebot/details/id/89815',
    89861: 'https://www.guterstart.nrw.de/fhiangebot/details/id/89861',
    97683: 'https://www.guterstart.nrw.de/fhiangebot/details/id/97683',
    98062: 'https://www.fruehehilfen-online.nrw.de/fhiangebot/details/id/98062',
    97978: 'https://www.fruehehilfen-online.nrw.de/fhiangebot/details/id/97978',
    88547: 'https://www.guterstart.nrw.de/fhiangebot/details/id/88547',
    97157: 'https://www.guterstart.nrw.de/fhiangebot/details/id/97157',
}


def plain(value):
    return ' '.join(html.unescape(re.sub('<[^>]+>', ' ', value)).split())


def distance(a, b):
    p, q = map(math.radians, [a[0], b[0]])
    return 12742000 * math.asin(min(1, math.sqrt(math.sin((q-p)/2)**2 + math.cos(p)*math.cos(q)*math.sin(math.radians(b[1]-a[1])/2)**2)))


def inspect(item):
    identifier, url = item
    request = urllib.request.Request(url, headers={'User-Agent': 'Expadu public place fact verification (read-only; no media)'})
    with urllib.request.urlopen(request, timeout=25) as response:
        data = response.read()
    source = data.decode('utf-8')
    title_html = re.search(r'<h1>(.*?)</h1>', source, re.S).group(1)
    provider = re.search(r'<span[^>]*>(.*?)</span>', title_html, re.S)
    provider_name = plain(provider.group(1)) if provider else None
    title = plain(re.sub(r'<span.*?</span>', '', title_html, flags=re.S))
    description = plain(re.search(r'class="offer-kurzbeschreibung">(.*?)</div>', source, re.S).group(1))
    coordinate = re.search(r'if \(manuelle_geokoords\)\s*\{\s*lat = "([\d.]+)";\s*lng = "([\d.]+)";', source)
    ages = re.search(r'<th>Altersgruppe</th>(.*?)</tr>', source, re.S)
    return {
        'id': identifier, 'url': url, 'title': title,
        'provider_name': provider_name,
        'provider_policy': 'excluded_municipal_provider' if provider_name and 'Stadt Köln' in provider_name else 'unreviewed_provider',
        'provider_approved_for_catalogue': False,
        'retrieved_at': datetime.now(timezone.utc).isoformat(),
        'page_sha256': hashlib.sha256(data).hexdigest(),
        'provider_map_point': list(map(float, coordinate.groups())) if coordinate else None,
        'fee_reported_free': bool(re.search(r'class="for-free">Kostenloses Angebot</li>', source)),
        'audience_all_ages': ages is not None and 'altersunabhängig' in ages.group(1),
        'public_access_status': 'unknown',
        'public_access_evidence_url': None,
        'public_access_basis': None,
        'booking_status': 'unknown',
        'description_confirms_football': bool(re.search(r'Fußball|Bolzplatz|Bolzfläche', description, re.I)),
        'description_confirms_goals': bool(re.search(r'(Fußballtor|zwei (?:Metall)?Tor)', description, re.I)),
        'opening_hours': None, 'bookable_now': None,
        'media_requested': False, 'catalogue_applied': False,
    }


def run():
    db = sqlite3.connect(f'file:{ROOT}/docs/places/cologne-expansion/2026-09-28/inventory.sqlite?mode=ro', uri=True)
    db.row_factory = sqlite3.Row
    pitches = []
    for row in db.execute("SELECT * FROM source_records WHERE source='osm' AND inside_city=1 AND category='pitch'"):
        tags = json.loads(row['raw_json']).get('tags', {})
        if 'soccer' in tags.get('sport', '').split(';'):
            pitches.append({'source_id': row['source_key'], 'name': row['name'], 'point': [row['lat'], row['lon']], 'source_url': row['source_url'], 'tags': tags})
    baseline = json.loads((ROOT / 'storage/app/private/places-research/production-pack-2026-09-28/places-only.json').read_text())
    by_source = {r['source_id']: r for r in baseline['tables']['spots'] if r['source'] == 'osm'}
    records = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor:
        for result in executor.map(inspect, SOURCES.items()):
            if result['provider_map_point'] is None:
                result['hold'] = 'source_location_missing'
                records.append(result)
                continue
            point = result['provider_map_point']
            nearest = sorted(pitches, key=lambda p: distance(point, p['point']))[:2]
            result['nearest_osm'] = [{**p, 'distance_m': round(distance(point, p['point']), 1)} for p in nearest]
            closest = result['nearest_osm'][0]
            second = result['nearest_osm'][1]
            unique_point = closest['distance_m'] <= 10 and second['distance_m'] >= 75
            named_match = closest['name'] == 'Bolzplatz Lohmüllerstraße' and result['id'] == 89938 and closest['distance_m'] < 75 and second['distance_m'] > 300
            result['match_basis'] = 'unique_source_map_point' if unique_point else ('specific_name_and_locality_review' if named_match else None)
            if result['match_basis'] is not None:
                result['identity_candidate_source_id'] = closest['source_id']
                result['existing_id'] = by_source.get(closest['source_id'], {}).get('id')
            if not result['provider_approved_for_catalogue']:
                result['hold'] = result['provider_policy']
            elif not result['fee_reported_free'] or not result['audience_all_ages'] or not result['description_confirms_football'] or not result['description_confirms_goals']:
                result['hold'] = 'fee_audience_or_activity_not_established'
            elif result['public_access_status'] != 'known' or result['public_access_evidence_url'] is None:
                result['hold'] = 'explicit_public_access_evidence_missing'
            elif result['match_basis'] is None:
                result['hold'] = 'spatial_identity_requires_review'
            else:
                result['hold'] = 'native_fact_identity_and_reference_rehearsal_required'
                result['proposed_source_id'] = closest['source_id']
                result['existing_id'] = by_source.get(closest['source_id'], {}).get('id')
            records.append(result)
    # Guard against the misleading football title on a basketball-only description.
    assert next(r for r in records if r['id'] == 88547)['description_confirms_football'] is False
    assert all(r['media_requested'] is False and r['catalogue_applied'] is False for r in records)
    result = {'scope': 'research-only leads; municipal provider excluded under EXP-70 and no unrestricted public-access inference from fee/audience metadata', 'sources_checked': len(records), 'native_rehearsal_proposals': sum('proposed_source_id' in r for r in records), 'records': records}
    (OUTPUT / 'football-source-evidence.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({'sources_checked': len(records), 'proposals': result['native_rehearsal_proposals'], 'records': [{'id': r['id'], 'title': r['title'], 'source_id': r.get('proposed_source_id'), 'existing_id': r.get('existing_id'), 'hold': r['hold']} for r in records]}, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    run()
