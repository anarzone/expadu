#!/usr/bin/env python3
"""Pick a small set of real places around the sample day for the mocks.

Reads Expadu's local catalogue export (12,409 entries, 7 October 2026, with
cuisine, outdoor seating, diet and Wi-Fi tags and the woods, lakes and nature
areas; private, not committed) and writes `connected/sample-places.js`: the
nearest places of each kind around the sample day's anchors (Neumarkt, the
appointment at Wiener Platz in Mülheim, Rheinauhafen, Stadtgarten, Ehrenfeld)
plus the Events venues and a few named libraries and green destinations.

Place data © OpenStreetMap contributors (ODbL). Facts keep their status: a
value the source doesn't record stays null ("not listed"); nothing is guessed.

    python3 tools/sample-places.py <candidate-places.jsonl>
"""
import json
import math
import sys
from pathlib import Path

design = Path(__file__).resolve().parent.parent
source = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(
    design, '../../../storage/app/private/places-local/2026-10-07/'
    'refreshed-catalogue/candidate-places.jsonl').resolve()

# Points the sample day refers to. Neumarkt is Travel's captured stop; the
# others are venue points from the same local catalogue.
anchors = {
    'neumarkt': (50.93576, 6.94811),
    'wiener-platz': (50.96103, 7.0048),
    'rheinauhafen': (50.92262, 6.96838),
    'stadtgarten': (50.9445, 6.93694),
    'ehrenfeld': (50.95022, 6.91254),
}
kinds = {
    'pharmacy': {'Pharmacy'},
    'cafe': {'Café'},
    'bakery': {'Bakery'},
    'grocery': {'Supermarket', 'Convenience shop', 'Drugstore'},
    'playground': {'Playground'},
    # Lakes count as parks; woods and reserves are their own kind.
    'park': {'Park', 'Lake'},
    'nature': {'Woods & nature'},
    'library': {'Library'},
    'restaurant': {'Restaurant'},
    'fastfood': {'Fast food'},
}
# How many of each kind around each anchor.
wanted = {
    'neumarkt': {'pharmacy': 3, 'cafe': 3, 'bakery': 2, 'grocery': 3,
                 'playground': 1, 'fastfood': 1, 'park': 1},
    'wiener-platz': {'pharmacy': 3, 'cafe': 3, 'bakery': 2, 'grocery': 2,
                     'playground': 2, 'park': 2, 'library': 1, 'fastfood': 3,
                     'nature': 1},
    'rheinauhafen': {'restaurant': 3, 'cafe': 2, 'playground': 1, 'park': 1,
                     'nature': 1},
    'stadtgarten': {'restaurant': 3, 'cafe': 1, 'park': 1},
    'ehrenfeld': {'cafe': 2, 'library': 1, 'park': 1},
}
# Named places: the central library (by Neumarkt), the Events venues, the
# newly listed branch libraries and some green destinations.
venues = ['Interim der Zentralbibliothek', 'Flora', 'Museum Ludwig',
          'Stadtteilbibliothek Chorweiler', 'Waldlabor',
          'Stadtteilbibliothek Nippes',
          'Königsforst', 'Decksteiner Weiher', 'Poller Wiesen']
# Food, drink and library details the cards may show (connected/places.js).
practical_keys = ['cuisine', 'outdoor_seating', 'internet_access',
                  'diet:vegetarian', 'diet:vegan', 'diet:halal',
                  'diet:kosher', 'diet:gluten_free']


def km(a, b):
    rad = math.radians
    d_lat, d_lng = rad(b[0] - a[0]), rad(b[1] - a[1])
    h = (math.sin(d_lat / 2) ** 2 + math.cos(rad(a[0])) * math.cos(rad(b[0]))
         * math.sin(d_lng / 2) ** 2)
    return 6371 * 2 * math.asin(math.sqrt(h))


def kind_of(place):
    for kind, labels in kinds.items():
        if place['fine_label'] in labels:
            return kind
    return None


def same_name(name):
    # "Bau" and "Café BAU" are probably one café recorded twice; keep one.
    import re
    return re.sub(r'[^a-zäöüß]', '', re.sub(r'\bcaf[eé]\b', '', name.lower()))


def fact(entry):
    return entry.get('value') if entry and entry.get('status') == 'known' else None


def compact(record):
    p = record['place']
    f = p['place_facts']
    point = f['location']['map_point'].get('kind') or ''
    hours = f['hours']
    practical = f.get('practical', {})
    photo = None
    if p.get('photo_url') and p.get('photo_attribution') and p.get(
            'photo_license_url') and p.get('photo_source_url'):
        photo = {
            'url': p['photo_url'],
            'attribution': p['photo_attribution'],
            'sourceUrl': p['photo_source_url'],
            'licenseUrl': p['photo_license_url'],
        }
    return {
        'id': f"place-{p['id']}",
        'type': 'place',
        'name': p['name'],
        'kind': kind_of(p) or p['category'],
        'label': p['fine_label'],
        'veedel': p['veedel'],
        'lat': round(p['lat'], 6),
        'lng': round(p['lng'], 6),
        # A building or shop point, or the middle of a large area.
        'point': 'area' if point == 'source_center' else
                 'building' if point == 'source_node' else 'unknown',
        'hours': {
            'raw': hours.get('raw'),
            'week': hours.get('parsed') if hours.get('status') == 'known' else None,
        },
        'address': fact(f['contact'].get('address')),
        'website': fact(f['contact'].get('website')),
        'phone': fact(f['contact'].get('phone')),
        'wheelchair': fact(practical.get('wheelchair')),
        # Recorded or disputed only; a missing tag stays out ("not listed").
        'practical': {
            key: {'value': practical[key].get('value'),
                  'status': practical[key]['status']}
            for key in practical_keys
            if (practical.get(key) or {}).get('status') in ('known', 'conflicting')
        },
        'access': fact(f.get('access')) if fact(f.get('access')) != 'unknown' else None,
        'fee': fact(f.get('fee')) if fact(f.get('fee')) != 'unknown' else None,
        'source': {
            'provider': 'OpenStreetMap',
            'url': f['location']['map_point'].get('source_url'),
            'observed': (f['location']['map_point'].get('observed_at') or '')[:10],
        },
        'photo': photo,
    }


records = [json.loads(line) for line in source.open()]
chosen, seen, names = [], set(), set()
for anchor, counts in wanted.items():
    for kind, count in counts.items():
        pool = [r for r in records if kind_of(r['place']) == kind
                and r['place']['recommendation_status'] == 'available']
        # Nearest first; known hours win a tie within 150 m.
        pool.sort(key=lambda r: (
            round(km(anchors[anchor], (r['place']['lat'], r['place']['lng'])) / 0.15),
            r['place']['place_facts']['hours']['status'] != 'known'))
        taken = 0
        for r in pool:
            if taken == count:
                break
            if r['place']['id'] in seen:
                continue
            # Unnamed playgrounds share a name; other repeats are duplicates.
            key = same_name(r['place']['name'])
            if kind != 'playground' and key in names:
                continue
            seen.add(r['place']['id'])
            names.add(key)
            chosen.append(compact(r))
            taken += 1
for name in venues:
    match = next((r for r in records if r['place']['name'].startswith(name)
                  and r['place']['recommendation_status'] == 'available'
                  and r['place']['id'] not in seen), None)
    if match:
        seen.add(match['place']['id'])
        chosen.append(compact(match))

body = json.dumps(chosen, ensure_ascii=False, indent=1)
out = design / 'connected/sample-places.js'
out.write_text(f"""/* Real places around the sample day, picked by tools/sample-places.py from
   Expadu's local catalogue export of 7 October 2026 (not deployed). Place data
   © OpenStreetMap contributors (ODbL). A fact the source doesn't record is null
   and shows as "not listed"; nothing here is guessed. Generated: do not edit. */
(function (root) {{
    root.ExpaduSamplePlaces = {{
        anchors: {json.dumps({k: {'lat': v[0], 'lng': v[1]} for k, v in anchors.items()})},
        places: {body},
    }};
}})(globalThis);
""")
# Match the repo's formatting so regenerating doesn't churn the file.
import subprocess
subprocess.run(['npx', 'prettier', '--write', str(out)], cwd=design,
               capture_output=True)
print(f'{len(chosen)} places → {out.relative_to(design)}')
for kind in sorted({p['kind'] for p in chosen}):
    group = [p for p in chosen if p['kind'] == kind]
    print(f"  {kind}: {len(group)}, hours known {sum(1 for p in group if p['hours']['week'])}, "
          f"wheelchair {sum(1 for p in group if p['wheelchair'])}, photos {sum(1 for p in group if p['photo'])}, "
          f"details {sum(1 for p in group if p['practical'])}")
