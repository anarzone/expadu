#!/usr/bin/env python3
"""Capture real scheduled journeys for the sample day's evening events.

Asks a local MOTIS planner (VRS timetable, GTFS) for journeys that arrive by
each event's start on the sample day, from the places the previews start
from, plus the bike and walking routes. Writes them, with each route's shape,
to connected/sample-journeys.js. Nothing is invented: every time, line and
stop is the planner's answer from the timetable; the file says when and how
it was captured.

Run the planner first (docker; see infra/motis/README.md), then, from
prototype/dev/design:

    python3 tools/capture-journeys.py http://127.0.0.1:8091

"""
import json
import math
import sys
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8091').rstrip('/')
OUT = 'connected/sample-journeys.js'
DAY = '2026-09-30'
OFFSET = timedelta(hours=2)  # Cologne on 30 September (CEST)

# Where the previews start from: the appointment area after the 14:30
# appointment (the details' default) and Neumarkt (the Events page's
# distances, and Travel's own start).
ORIGINS = [
    {'id': 'wiener-platz', 'name': 'Mülheim', 'stop': 'Wiener Platz', 'lat': 50.96103, 'lng': 7.0048,
     'match': ['mülheim', 'wiener platz', 'mülheim · wiener platz']},
    {'id': 'neumarkt', 'name': 'Neumarkt', 'stop': 'Neumarkt', 'lat': 50.93576, 'lng': 6.94811,
     'match': ['neumarkt']},
]
EVENTS = [
    {'id': 'today-review-culture', 'venue': 'Rheinauhafen', 'lat': 50.92262, 'lng': 6.96838, 'start': '18:30'},
    {'id': 'today-review-cinema', 'venue': 'Stadtgarten', 'lat': 50.9445, 'lng': 6.93694, 'start': '20:00'},
]


def get(params):
    url = f'{BASE}/api/v1/plan?' + urllib.parse.urlencode(params)
    with urllib.request.urlopen(url, timeout=30) as r:
        return json.load(r)


def local(iso):
    t = datetime.fromisoformat(iso.replace('Z', '+00:00')).astimezone(timezone(OFFSET))
    return t.strftime('%H:%M')


def decode(points, precision):
    """Google polyline decoding at the given precision."""
    coords, index, lat, lng, factor = [], 0, 0, 0, 10 ** precision
    while index < len(points):
        for which in (0, 1):
            shift = result = 0
            while True:
                b = ord(points[index]) - 63
                index += 1
                result |= (b & 0x1F) << shift
                shift += 5
                if b < 0x20:
                    break
            delta = ~(result >> 1) if result & 1 else result >> 1
            if which == 0:
                lat += delta
            else:
                lng += delta
        coords.append((lat / factor, lng / factor))
    return coords


def simplify(points, tolerance=0.00004):
    """Douglas-Peucker, about 4 m: keeps the shape, drops most points."""
    if len(points) < 3:
        return points

    def dist(p, a, b):
        if a == b:
            return math.dist(p, a)
        (x, y), (x1, y1), (x2, y2) = p, a, b
        t = max(0, min(1, ((x - x1) * (x2 - x1) + (y - y1) * (y2 - y1)) / ((x2 - x1) ** 2 + (y2 - y1) ** 2)))
        return math.dist(p, (x1 + t * (x2 - x1), y1 + t * (y2 - y1)))

    worst, at = 0, 0
    for i in range(1, len(points) - 1):
        d = dist(points[i], points[0], points[-1])
        if d > worst:
            worst, at = d, i
    if worst <= tolerance:
        return [points[0], points[-1]]
    return simplify(points[: at + 1], tolerance)[:-1] + simplify(points[at:], tolerance)


def shape(leg):
    g = leg.get('legGeometry') or {}
    if not g.get('points'):
        return []
    return [[round(a, 5), round(b, 5)] for a, b in simplify(decode(g['points'], g.get('precision', 6)))]


def place(name):
    name = name or ''
    return name[5:] if name.startswith('Köln ') else name


VEHICLE = {'TRAM': 'tram', 'BUS': 'bus', 'SUBWAY': 'tram', 'RAIL': 'rail', 'REGIONAL_RAIL': 'rail',
           'REGIONAL_FAST_RAIL': 'rail', 'METRO': 'rail', 'HIGHSPEED_RAIL': 'rail', 'LONG_DISTANCE': 'rail'}


def legs_of(it):
    out = []
    for leg in it['legs']:
        mode = leg['mode']
        base = {'depart': local(leg['startTime']), 'arrive': local(leg['endTime']),
                'minutes': round((datetime.fromisoformat(leg['endTime'].replace('Z', '+00:00')) -
                                  datetime.fromisoformat(leg['startTime'].replace('Z', '+00:00'))).total_seconds() / 60),
                'from': place(leg['from'].get('name')), 'to': place(leg['to'].get('name')), 'points': shape(leg)}
        if mode == 'WALK':
            if base['minutes'] < 1 and base['from'] == base['to']:
                continue
            out.append({'mode': 'walk', 'metres': round(leg.get('distance') or 0), **base})
        else:
            out.append({'mode': VEHICLE.get(mode, 'tram'), 'line': leg.get('routeShortName') or '',
                        'towards': place(leg.get('headsign')), 'stops': len(leg.get('intermediateStops') or []) + 1,
                        **base})
    return out


def named(legs, origin, event):
    # The planner calls the two ends START and END.
    for leg in legs:
        if leg['from'] == 'START':
            leg['from'] = origin['name']
        if leg['to'] == 'END':
            leg['to'] = event['venue']
    return legs


def capture(origin, event):
    when = f'{DAY}T{event["start"]}:00+02:00'
    common = {'fromPlace': f'{origin["lat"]},{origin["lng"]}', 'toPlace': f'{event["lat"]},{event["lng"]}',
              'time': when, 'arriveBy': 'true'}
    plan = get({**common, 'numItineraries': 8, 'directModes': 'WALK'})
    options, seen = [], set()
    for it in plan.get('itineraries', []):
        legs = named(legs_of(it), origin, event)
        rides = [l for l in legs if l['mode'] != 'walk']
        if not rides:
            continue
        key = (legs[0]['depart'], tuple(l['line'] for l in rides))
        if key in seen:
            continue
        seen.add(key)
        options.append({
            'depart': legs[0]['depart'], 'arrive': legs[-1]['arrive'],
            'duration': round(it['duration'] / 60), 'changes': max(0, len(rides) - 1),
            'walking': sum(l['minutes'] for l in legs if l['mode'] == 'walk'),
            'legs': legs,
        })
    # The latest ones that still arrive by the start, latest first.
    options = sorted([o for o in options if o['arrive'] <= event['start']], key=lambda o: o['depart'], reverse=True)[:3]
    for o in options:
        o['id'] = f'{origin["id"]}-{event["id"]}-{o["depart"].replace(":", "")}'
    direct = get({**common, 'numItineraries': 1, 'directModes': 'WALK,BIKE', 'maxDirectTime': 7200,
                  }).get('direct', [])
    ways = {}
    for d in direct:
        mode = d['legs'][0]['mode']
        if mode in ('WALK', 'BIKE'):
            ways['walk' if mode == 'WALK' else 'bike'] = {
                'minutes': round(d['duration'] / 60),
                'km': round(sum(l.get('distance') or 0 for l in d['legs']) / 1000, 1),
                'points': [p for l in d['legs'] for p in shape(l)],
            }
    return {'id': f'{origin["id"]}-{event["id"]}', 'event': event['id'], 'venue': event['venue'],
            'date': DAY, 'arriveBy': event['start'],
            'from': {'id': origin['id'], 'name': origin['name'], 'stop': origin['stop'],
                     'lat': origin['lat'], 'lng': origin['lng'], 'match': origin['match']},
            'to': {'lat': event['lat'], 'lng': event['lng']},
            'options': options, **ways}


def main():
    trips = [capture(o, e) for o in ORIGINS for e in EVENTS]
    for t in trips:
        print(t['id'], [(o['depart'], o['arrive'], '+'.join(l['line'] for l in o['legs'] if l['mode'] != 'walk'))
                        for o in t['options']], 'bike', t.get('bike', {}).get('minutes'), 'walk', t.get('walk', {}).get('minutes'))
    data = {
        'source': 'VRS timetable (GTFS), planned with MOTIS',
        'captured': '2026-10-13',
        'note': 'Scheduled times for the sample day, not live.',
        'trips': trips,
    }
    with open(OUT, 'w') as f:
        f.write('/* GENERATED by tools/capture-journeys.py: real scheduled journeys for the\n'
                '   sample day\'s evening events, from the VRS timetable (GTFS) via a local\n'
                '   MOTIS planner, captured 13 October 2026. Scheduled times, not live. */\n'
                '(function (root) {\n    root.ExpaduSampleJourneys = ')
        f.write(json.dumps(data, ensure_ascii=False, separators=(',', ':')))
        f.write(';\n})(globalThis);\n')
    print(OUT, len(open(OUT).read()), 'bytes')


main()
