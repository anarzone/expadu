"""Neumarkt up close, from OpenStreetMap: writes ../neumarkt-streets.svg (the
streets, buildings and track, no words) and ../station-closeup.js (the
platforms, the ways down, the street names, which direction stops where).

Source, read 15 Oct 2026 (ODbL, "© OpenStreetMap contributors"):
  curl -A "<your app>" -o neumarkt.osm \\
    "https://api.openstreetmap.org/api/0.6/map?bbox=6.9440,50.9334,6.9524,50.9382"
  python3 neumarkt.py neumarkt.osm
Which direction of each line uses which platform comes from the lines' route
relations (Overpass, the same day); they're written out below. The VRS
timetable has one point for all of Neumarkt, so it can't say this."""
import json, math, os, sys
import xml.etree.ElementTree as ET

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.dirname(HERE)

root = ET.parse(sys.argv[1]).getroot()
nodes = {n.get('id'): (float(n.get('lat')), float(n.get('lon')), {t.get('k'): t.get('v') for t in n.findall('tag')})
         for n in root.findall('node')}
ways = {}
for w in root.findall('way'):
    pts = [nodes[nd.get('ref')][:2] for nd in w.findall('nd') if nd.get('ref') in nodes]
    ways[w.get('id')] = {'tags': {t.get('k'): t.get('v') for t in w.findall('tag')}, 'pts': pts}

# Platforms: their sign, OSM way, underground?
PLATFORMS = {'1': ('119430155', True), '2': ('119430150', True), '3': ('33511470', False), '4': ('33511469', False),
             '5': ('32923610', False), '6': ('413024817', False), 'A': ('32923608', False), 'B': ('499014162', False)}
# Route relations: each direction's platform. "towards" lists the names the
# timetable uses for that way (the departures' destinations first).
DIRECTIONS = {
    '4': [('2', ['Schlebusch']), ('1', ['Rochusplatz', 'Bocklemünd'])],
    '3': [('2', ['Thielenbruch']), ('1', ['Görlinger-Zentrum', 'Mengenich', 'Bocklemünd'])],
    '16': [('2', ['Sürth', 'Bad Godesberg']), ('1', ['Niehl Sebastianstr.', 'Niehl'])],
    '18': [('2', ['Klettenberg', 'Bonn']), ('1', ['Buchheim'])],
    '1': [('3', ['Brück', 'Bensberg']), ('4', ['Weiden West', 'Weiden'])],
    '7': [('3', ['Zündorf']), ('4', ['Frechen'])],
    '9': [('3', ['Königsforst']), ('4', ['Sülz'])],
    '136': [('A', ['Lövenich', 'Daimlerstr.'])],
    '146': [('A', ['Deckstein'])],
}
# The station's own ways down (railway=subway_entrance) and its street lifts.
WAYS_DOWN = [('292345282', 'stairs'), ('382012849', 'stairs'), ('295805986', 'stairs'), ('1603562574', 'stairs'),
             ('4836687807', 'stairs'), ('292345279', 'stairs'), ('4836687809', 'stairs'), ('4836687808', 'stairs'),
             ('3311920493', 'stairs'), ('1342051693', 'lift'), ('2784177601', 'lift')]
# Street names, placed on the drawn streets (x, y, angle).
NAMES = [('Neumarkt', 78, 70, 0), ('Cäcilienstraße', 262, 133, 7), ('Schildergasse', 298, 46, 0),
         ('Richmodstraße', 122, 28, -2), ('Zeppelinstr.', 197, 36, -90)]
# Where each platform's name sits, from its centre (dx, dy, anchor).
LABEL_AT = {'1': (13, -4, 'start'), '2': (-13, -4, 'end'), '3': (0, 20, 'middle'), '4': (0, -17, 'middle'),
            '5': (0, -14, 'middle'), '6': (0, -14, 'middle'), 'A': (0, 22, 'middle'), 'B': (0, 22, 'middle')}

# The window: every platform and way down, north up.
LAT_N, LAT_S, LNG_W = 50.93705, 50.93405, 6.94510
VW, VH = 340, 260
MX = 111320.0 * math.cos(math.radians(50.9356))
PX = VH / ((LAT_N - LAT_S) * 111320.0)  # pixels per metre


def xy(lat, lng):
    return (lng - LNG_W) * MX * PX, (LAT_N - lat) * 111320.0 * PX


def path(pts, close=False, nd=1):
    return 'M' + ' L'.join(f'{x:.{nd}f} {y:.{nd}f}' for x, y in (xy(*p) for p in pts)) + ('Z' if close else '')


def near(pts, pad=40):
    return any(-pad <= x <= VW + pad and -pad <= y <= VH + pad for x, y in (xy(*p) for p in pts))


# ── The streets: one path per style, whole pixels ──
layers = {k: [] for k in ('plaza', 'green', 'blocks', 'paths', 'wide', 'narrow', 'track', 'tunnel')}
for w in ways.values():
    t, p = w['tags'], w['pts']
    if len(p) < 2 or not near(p):
        continue
    closed = p[0] == p[-1]
    d = path(p, closed, 0)
    if 'building' in t and closed and t.get('location') != 'underground' and t.get('building') != 'roof':
        layers['blocks'].append(d)
    elif closed and (t.get('landuse') == 'grass' or t.get('leisure') in ('garden', 'park', 'common')):
        layers['green'].append(d)
    elif closed and (t.get('area:highway') == 'pedestrian' or (t.get('highway') == 'pedestrian' and t.get('area') == 'yes') or t.get('place') == 'square'):
        layers['plaza'].append(d)
    elif t.get('railway') == 'light_rail':
        layers['tunnel' if t.get('tunnel') == 'yes' else 'track'].append(d)
    elif t.get('highway') in ('secondary', 'secondary_link', 'busway'):
        layers['wide'].append(d)
    elif t.get('highway') in ('residential', 'living_street', 'pedestrian') and t.get('area') != 'yes':
        layers['narrow'].append(d)
    elif (t.get('highway') in ('footway', 'path', 'cycleway') and t.get('area') != 'yes'
          and t.get('tunnel') != 'yes' and t.get('level', '0') in ('0', '')):
        layers['paths'].append(d)
J = lambda k: ''.join(layers[k])
road = 'fill="none" stroke-linecap="round" stroke-linejoin="round"'
faint = ''
for sign, (wid, under) in PLATFORMS.items():
    p = ways[wid]['pts']
    faint += (f'<path d="{path(p, True)}" fill="#d3c6ac" stroke="#d3c6ac" stroke-width="2" stroke-linejoin="round"{" opacity=\".6\"" if under else ""}/>'
              if p[0] == p[-1] else f'<path d="{path(p)}" fill="none" stroke="#d3c6ac" stroke-width="4" stroke-linecap="round"/>')
svg = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {VW} {VH}" width="{VW}" height="{VH}">'
       f'<!-- Neumarkt, Köln. © OpenStreetMap contributors (ODbL), read 15 Oct 2026. -->'
       f'<rect width="{VW}" height="{VH}" fill="#fbf6ec"/>'
       f'<path d="{J("plaza")}" fill="#f6f0e3"/><path d="{J("green")}" fill="#dfe5cc"/><path d="{J("blocks")}" fill="#e9e0cd"/>'
       f'<path d="{J("paths")}" {road} stroke="#fffdf8" stroke-width="1.6"/>'
       f'<path d="{J("wide")}" {road} stroke="#ddd2bc" stroke-width="11"/><path d="{J("narrow")}" {road} stroke="#ddd2bc" stroke-width="7.5"/>'
       f'<path d="{J("wide")}" {road} stroke="#fffdf8" stroke-width="8"/><path d="{J("narrow")}" {road} stroke="#fffdf8" stroke-width="5"/>'
       f'<path d="{J("track")}" fill="none" stroke="#cbbfa8" stroke-width="1.4"/>'
       f'<path d="{J("tunnel")}" fill="none" stroke="#cbbfa8" stroke-width="1.4" stroke-dasharray="3 4"/>'
       f'{faint}</svg>\n')
open(os.path.join(OUT, 'neumarkt-streets.svg'), 'w').write(svg)


# ── The data the page draws on top ──
def centre(pts):
    xs = [xy(*p)[0] for p in pts]; ys = [xy(*p)[1] for p in pts]
    return [round((min(xs) + max(xs)) / 2, 1), round((min(ys) + max(ys)) / 2, 1)]


data = {
    'id': 'neumarkt',
    'image': 'neumarkt-streets.svg',
    'width': VW, 'height': VH,
    'fifty': round(50 * PX, 1),
    'platforms': {sign: {'d': path(ways[wid]['pts'], ways[wid]['pts'][0] == ways[wid]['pts'][-1]),
                         'closed': ways[wid]['pts'][0] == ways[wid]['pts'][-1], 'under': under,
                         'at': centre(ways[wid]['pts']), 'label': LABEL_AT[sign]}
                  for sign, (wid, under) in PLATFORMS.items()},
    'down': [[round(xy(*nodes[n][:2])[0], 1), round(xy(*nodes[n][:2])[1], 1), kind] for n, kind in WAYS_DOWN],
    'names': NAMES,
    'directions': {line: [[sign, names] for sign, names in dirs] for line, dirs in DIRECTIONS.items()},
}
js = ('/* Neumarkt up close, from OpenStreetMap (© OpenStreetMap contributors, ODbL;\n'
      '   read 15 Oct 2026). Written by osm/neumarkt.py: the platforms, the station\'s\n'
      '   ways down and lifts, the street names, and which direction of each line\n'
      '   stops where (the lines\' route relations). Not confirmed by KVB. */\n'
      f'globalThis.StationCloseUp = {{ neumarkt: {json.dumps(data, ensure_ascii=False)} }};\n')
open(os.path.join(OUT, 'station-closeup.js'), 'w').write(js)
print('svg', len(svg), 'js', len(js))
