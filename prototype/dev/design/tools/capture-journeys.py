#!/usr/bin/env python3
"""Capture real scheduled journeys for the sample day's evening events.

Asks a local MOTIS planner (VRS timetable, GTFS) for the ways to each event
on the sample day, from every start the previews recognise, plus the bike and
walking routes. Three kinds of trip per start and event:

- arriving by the event's start (the latest three that make it);
- leaving now, at the sample day's clock (the three that arrive first);
- the way back after the event's listed end (the three that arrive first).

Writes them, with each route's shape, to connected/sample-journeys.js (the
appointment area and Neumarkt, loaded with the page) and one file per
district to connected/journeys/ (loaded when someone starts from there).
Nothing is invented: every time, line and stop is the planner's answer from
the timetable; the files say when and how they were captured.

Run the planner first (docker; see infra/motis/README.md), then, from
prototype/dev/design:

    python3 tools/capture-journeys.py http://127.0.0.1:8091

"""
import hashlib
import json
import math
import os
import re
import sys
import unicodedata
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8091').rstrip('/')
OUT = 'connected/sample-journeys.js'
MORE = 'connected/journeys'
CAPTURED = '2026-10-13'
DAY = '2026-09-30'
NOW = '13:10'  # the sample day's clock (connected/sample-day.js)
OFFSET = timedelta(hours=2)  # Cologne on 30 September (CEST)

# Loaded with the page: the appointment area after the 14:30 appointment (the
# details' default) and Neumarkt (the Events page's distances, and Travel's
# own start).
ORIGINS = [
    {'id': 'wiener-platz', 'name': 'Mülheim', 'stop': 'Wiener Platz', 'lat': 50.96103, 'lng': 7.0048,
     'names': ['Mülheim', 'Wiener Platz']},
    {'id': 'neumarkt', 'name': 'Neumarkt', 'stop': 'Neumarkt', 'lat': 50.93576, 'lng': 6.94811,
     'names': ['Neumarkt']},
]
# The evening's events, with their sample programme's times
# (connected/sample-day.js).
EVENTS = [
    {'id': 'today-review-culture', 'venue': 'Rheinauhafen', 'lat': 50.92262, 'lng': 6.96838,
     'start': '18:30', 'end': '21:00'},
    {'id': 'today-review-cinema', 'venue': 'Stadtgarten', 'lat': 50.9445, 'lng': 6.93694,
     'start': '20:00', 'end': '22:00'},
]


def fold(text):
    """As events-next/distance.js folds a place name."""
    text = str(text or '').lower().replace('ß', 'ss')
    text = ''.join(c for c in unicodedata.normalize('NFD', text) if not unicodedata.combining(c))
    text = re.sub(r'\b(koln|cologne)\b', ' ', text)
    return re.sub(r'[^a-z0-9/]+', ' ', text).strip()


def districts():
    """Every other start the Events preview recognises: the district centres
    in events-next/distance.js (Mülheim is the appointment area above)."""
    src = open('events-next/distance.js', encoding='utf-8').read()
    out = []
    for name, lat, lng in re.findall(r"\['([^']+)', ([\d.]+), ([\d.]+)\]", src):
        if name in ('Neumarkt', 'Mülheim'):
            continue
        out.append({'id': fold(name).replace('/', '-').replace(' ', '-'), 'name': name, 'stop': None,
                    'lat': float(lat), 'lng': float(lng),
                    'names': [name, *name.split('/')] if '/' in name else [name]})
    return out


def get(params):
    url = f'{BASE}/api/v1/plan?' + urllib.parse.urlencode(params)
    with urllib.request.urlopen(url, timeout=120) as r:
        return json.load(r)


def at(clock):
    return f'{DAY}T{clock}:00+02:00'


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

    worst, where = 0, 0
    for i in range(1, len(points) - 1):
        d = dist(points[i], points[0], points[-1])
        if d > worst:
            worst, where = d, i
    if worst <= tolerance:
        return [points[0], points[-1]]
    return simplify(points[: where + 1], tolerance)[:-1] + simplify(points[where:], tolerance)


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


def minutes_between(a, b):
    return round((datetime.fromisoformat(b.replace('Z', '+00:00')) -
                  datetime.fromisoformat(a.replace('Z', '+00:00'))).total_seconds() / 60)


def legs_of(it, start, end):
    out = []
    for leg in it['legs']:
        mode = leg['mode']
        base = {'depart': local(leg['startTime']), 'arrive': local(leg['endTime']),
                'minutes': minutes_between(leg['startTime'], leg['endTime']),
                'from': place(leg['from'].get('name')), 'to': place(leg['to'].get('name')), 'points': shape(leg)}
        # The planner calls the two ends START and END.
        base['from'] = start if base['from'] == 'START' else base['from']
        base['to'] = end if base['to'] == 'END' else base['to']
        if mode == 'WALK':
            if base['minutes'] < 1 and base['from'] == base['to']:
                continue
            out.append({'mode': 'walk', 'metres': round(leg.get('distance') or 0), **base})
        else:
            out.append({'mode': VEHICLE.get(mode, 'tram'), 'line': leg.get('routeShortName') or '',
                        'towards': place(leg.get('headsign')), 'stops': len(leg.get('intermediateStops') or []) + 1,
                        **base})
    return out


def options_of(plan, start, end):
    options, seen = [], set()
    for it in plan.get('itineraries', []):
        legs = legs_of(it, start, end)
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
    return options


def late(clock):
    """Minutes into the evening: after midnight counts as the same night."""
    h, m = map(int, clock.split(':'))
    return h * 60 + m + (1440 if h < 12 else 0)


def first_to_arrive(options, after):
    """The three that arrive first, of those leaving at or after `after`."""
    seen, out = set(), []
    for o in sorted(options, key=lambda o: (late(o['arrive']), late(o['depart']))):
        key = (o['depart'], tuple(l.get('line') for l in o['legs']))
        if late(o['depart']) >= late(after) and key not in seen:
            seen.add(key)
            out.append(o)
    return out[:3]


def direct(common):
    """Cycling and walking: one way each, the same whenever you go. Longer
    than two hours isn't asked for, so it's marked as over that."""
    plan = get({**common, 'numItineraries': 1, 'directModes': 'WALK,BIKE', 'maxDirectTime': 7200})
    ways = {'bike': {'over': 120}, 'walk': {'over': 120}}
    for d in plan.get('direct', []):
        mode = d['legs'][0]['mode']
        if mode in ('WALK', 'BIKE'):
            ways['walk' if mode == 'WALK' else 'bike'] = {
                'minutes': round(d['duration'] / 60),
                'km': round(sum(l.get('distance') or 0 for l in d['legs']) / 1000, 1),
                'points': [p for l in d['legs'] for p in shape(l)],
            }
    return ways


def capture(origin, event):
    o, e = f'{origin["lat"]},{origin["lng"]}', f'{event["lat"]},{event["lng"]}'
    there, back = {'fromPlace': o, 'toPlace': e}, {'fromPlace': e, 'toPlace': o}
    name, venue = origin['name'], event['venue']
    # By the start: the latest ones that still make it.
    arriving = options_of(get({**there, 'time': at(event['start']), 'arriveBy': 'true', 'numItineraries': 8,
                               'directModes': 'WALK'}), name, venue)
    arriving = sorted([x for x in arriving if x['arrive'] <= event['start']], key=lambda x: x['depart'],
                      reverse=True)[:3]
    # Leaving now, at the sample day's clock.
    now = first_to_arrive(options_of(get({**there, 'time': at(NOW), 'numItineraries': 6, 'directModes': 'WALK'}),
                                     name, venue), NOW)
    # The way back once it ends.
    home_query = {**back, 'time': at(event['end']), 'numItineraries': 6, 'directModes': 'WALK'}
    home = first_to_arrive(options_of(get(home_query), venue, name), event['end'])
    if sum(late(x['depart']) <= late(event['end']) + 60 for x in home) < 3:
        # Late buses don't serve every street: up to 30 minutes' walk at
        # either end finds the ways that run then.
        longer = options_of(get({**home_query, 'maxPreTransitTime': 1800, 'maxPostTransitTime': 1800}),
                            venue, name)
        home = first_to_arrive(home + longer, event['end'])
    trip_id = f'{origin["id"]}-{event["id"]}'
    for kind, options in (('', arriving), ('now-', now), ('back-', home)):
        for x in options:
            x['id'] = f'{trip_id}-{kind}{x["depart"].replace(":", "")}'
    return {'id': trip_id, 'event': event['id'], 'venue': venue, 'date': DAY,
            'arriveBy': event['start'], 'end': event['end'],
            'from': {k: origin[k] for k in ('id', 'name', 'stop', 'lat', 'lng')},
            'to': {'lat': event['lat'], 'lng': event['lng']},
            'options': arriving, 'now': now, **direct({**there, 'time': at(event['start'])}),
            'back': {'after': event['end'], 'options': home, **direct({**back, 'time': at(event['end'])})}}


def pack(trips):
    """Each shape once per file; the legs that share it point to it."""
    shapes, index = [], {}

    def ref(points):
        key = json.dumps(points)
        if key not in index:
            index[key] = len(shapes)
            shapes.append(points)
        return index[key]

    def walk(x):
        if isinstance(x, list):
            for y in x:
                walk(y)
        elif isinstance(x, dict):
            for k, v in x.items():
                if k == 'points' and isinstance(v, list):
                    x[k] = ref(v)
                else:
                    walk(v)

    walk(trips)
    return shapes


def dump(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':'))


HEAD = ('/* GENERATED by tools/capture-journeys.py: real scheduled journeys for the\n'
        '   sample day\'s evening events, from the VRS timetable (GTFS) via a local\n'
        '   MOTIS planner, captured 13 October 2026. Scheduled times, not live. */\n')

# The page's part: the data, and finding and loading a start's journeys.
LOADER = r'''(function (root) {
    const data = %s;
    // Shapes are stored once per file; the legs that share one point to it.
    const unpack = (pack) => {
        const walk = (x) => {
            if (Array.isArray(x)) x.forEach(walk);
            else if (x && typeof x === 'object')
                for (const k in x) {
                    if (k === 'points' && typeof x[k] === 'number') x[k] = pack.shapes[x[k]];
                    else walk(x[k]);
                }
        };
        walk(pack.trips);
        return pack.trips;
    };
    data.trips = unpack(data);
    delete data.shapes;
    const fold = (text) =>
        String(text || '')
            .toLocaleLowerCase('de')
            .replace(/ß/g, 'ss')
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/\b(koln|cologne)\b/g, ' ')
            .replace(/[^a-z0-9/]+/g, ' ')
            .trim();
    // The captured start a typed place names: "Ehrenfeld", "Köln-Sülz" or
    // "Venloer Str., Ehrenfeld". The longest name wins, as in the Events
    // page's distances (events-next/distance.js).
    data.place = (text) => {
        const query = ` ${fold(text)} `;
        if (!query.trim()) return null;
        return (
            data.starts
                .flatMap((start) => start.names.map((name) => ({ start, key: fold(name) })))
                .filter(({ key }) => key && query.includes(` ${key} `))
                .sort((a, b) => b.key.length - a.key.length)[0]?.start || null
        );
    };
    // A district's journeys load when someone starts from there.
    const loaded = new Set(data.trips.map((trip) => trip.from.id));
    const waiting = {};
    const base = typeof document !== 'undefined' && document.currentScript ? document.currentScript.src : '';
    data.has = (id) => loaded.has(id);
    data.load = (id) => {
        if (loaded.has(id)) return Promise.resolve(true);
        const start = data.starts.find((item) => item.id === id);
        if (!base || !start?.v) return Promise.resolve(false);
        return (waiting[id] ||= new Promise((resolve) => {
            const script = document.createElement('script');
            script.src = new URL(`journeys/${id}.js?v=${start.v}`, base).href;
            script.onload = () => resolve(loaded.has(id));
            script.onerror = () => {
                delete waiting[id];
                resolve(false);
            };
            document.head.append(script);
        }));
    };
    data.add = (more) => {
        const trips = unpack(more);
        trips.forEach((trip) => loaded.add(trip.from.id));
        data.trips.push(...trips);
        root.dispatchEvent?.(new CustomEvent('expadu:journeys', { detail: { trips } }));
    };
    root.ExpaduSampleJourneys = data;
})(globalThis);
'''


def summary(t):
    def line(options):
        return [(o['depart'], o['arrive'], '+'.join(l['line'] for l in o['legs'] if l['mode'] != 'walk'))
                for o in options]
    return (f"{t['id']}: by {line(t['options'])} now {line(t['now'])} back {line(t['back']['options'])} "
            f"bike {t['bike'].get('minutes', '>120')} walk {t['walk'].get('minutes', '>120')}")


def reachable(lat, lng):
    """Whether the planner finds a way on foot and by tram or bus from here."""
    e = EVENTS[0]
    test = get({'fromPlace': f'{lat},{lng}', 'toPlace': f'{e["lat"]},{e["lng"]}', 'time': at(e['start']),
                'arriveBy': 'true', 'numItineraries': 1, 'directModes': 'WALK', 'maxDirectTime': 7200})
    return bool(test.get('itineraries') or test.get('direct'))


def snap(origin):
    """A district centre the planner can't reach on foot (a park, a hospital
    campus, industrial land) starts from its nearest stop instead, and says
    so: from.stop names it."""
    if reachable(origin['lat'], origin['lng']):
        return origin
    lat, lng = origin['lat'], origin['lng']
    url = f'{BASE}/api/v1/map/stops?' + urllib.parse.urlencode(
        {'min': f'{lat - 0.02},{lng - 0.03}', 'max': f'{lat + 0.02},{lng + 0.03}'})
    with urllib.request.urlopen(url, timeout=60) as r:
        stops = sorted(json.load(r), key=lambda x: math.dist((x['lat'], x['lon'] * 0.63), (lat, lng * 0.63)))
    for stop in stops[:12]:
        if reachable(stop['lat'], stop['lon']):
            print(f'  {origin["name"]}: the centre is off the street network, so from {stop["name"]}')
            return {**origin, 'stop': place(stop['name']), 'lat': round(stop['lat'], 5), 'lng': round(stop['lon'], 5)}
    raise SystemExit(f'No reachable stop near {origin["name"]}')


def run(origins):
    origins = [snap(o) for o in origins]
    jobs = [(o, e) for o in origins for e in EVENTS]
    with ThreadPoolExecutor(max_workers=4) as pool:
        return list(pool.map(lambda job: capture(*job), jobs))


def main():
    more = districts()
    os.makedirs(MORE, exist_ok=True)
    total, versions = 0, {}
    for start in more:
        trips = run([start])
        shapes = pack(trips)
        path = f'{MORE}/{start["id"]}.js'
        with open(path, 'w', encoding='utf-8') as f:
            f.write(HEAD + f'globalThis.ExpaduSampleJourneys?.add({dump({"shapes": shapes, "trips": trips})});\n')
        # Its cache version, as tools/asset-versions.mjs versions the pages'
        # own files: the start of its SHA-256.
        versions[start['id']] = hashlib.sha256(open(path, 'rb').read()).hexdigest()[:12]
        total += os.path.getsize(path)
        print(path, os.path.getsize(path), 'bytes')
    print(len(more), 'district files,', total, 'bytes')
    starts = [{**{k: s[k] for k in ('id', 'name', 'names', 'lat', 'lng')},
               **({'v': versions[s['id']]} if s['id'] in versions else {})} for s in ORIGINS + more]
    trips = run(ORIGINS)
    for t in trips:
        print(summary(t))
    shapes = pack(trips)
    data = {
        'source': 'VRS timetable (GTFS), planned with MOTIS',
        'captured': CAPTURED,
        'note': 'Scheduled times for the sample day, not live.',
        'now': NOW,
        'starts': starts,
        'shapes': shapes,
        'trips': trips,
    }
    with open(OUT, 'w', encoding='utf-8') as f:
        f.write(HEAD + LOADER % dump(data))
    print(OUT, os.path.getsize(OUT), 'bytes')


main()
