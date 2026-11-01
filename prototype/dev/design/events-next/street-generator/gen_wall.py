#!/usr/bin/env python3
"""Events Wall on a 100-event day: options A, B and C, desktop and phone.
Only the two sample events carry real facts; every other poster is a
labelled layout placeholder (no invented titles or times)."""
import json, os

ROOT = os.path.join(os.path.dirname(__file__), 'events-canvas')
PROJ = os.path.join(ROOT, 'project')
os.makedirs(PROJ, exist_ok=True)

CSS = '''
*{box-sizing:border-box}
.top{display:flex;align-items:center;gap:6px;height:52px;padding:0 10px 0 14px;background:#fffdf8;border-bottom:1px solid #e6ddcb}
.brand{display:flex;align-items:center;gap:5px;font-size:20px;font-weight:700;letter-spacing:-.4px;margin-right:auto}
.city{display:flex;align-items:center;gap:3px;height:44px;padding:0 6px;color:#6e6657;font-size:13.5px}
.av{display:grid;place-items:center;width:34px;height:34px;border-radius:50%;background:#e7e2f6;color:#4b3d8a;font-weight:600;font-size:14px}
.dtop{display:flex;align-items:center;gap:28px;height:72px;padding:0 32px;background:#fffdf8;border-bottom:1px solid #e6ddcb}
.dtop nav{display:flex;gap:6px;margin:0 auto}
.dtop nav span{display:flex;align-items:center;gap:7px;height:44px;padding:0 14px;border-radius:12px;font-size:15px;font-weight:500}
.dtop nav .on{background:#def5f8}
.filters{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.date{display:flex;align-items:center;gap:6px;height:44px;padding:0 14px;background:#ffe45c;transform:rotate(-1.5deg);font-family:'Permanent Marker',cursive;font-size:16px;box-shadow:0 2px 0 #00000014}
.pill{display:flex;align-items:center;gap:6px;height:44px;padding:0 14px;border:1px solid #e6ddcb;border-radius:14px;background:#fffdf8;font-size:14.5px;color:#211d15}
.pill.on{background:#def5f8;border-color:#bfe6ec;font-weight:600}
.pill.ghost{border-color:transparent;background:transparent}
.wall{position:relative;overflow:hidden;border-radius:28px;background:#e9e1d1;box-shadow:inset 0 0 0 1px #d8ccb4}
.sky{position:absolute;left:0;right:0;top:0}
.ledge{position:absolute;left:0;right:0;height:10px;background:#cdbf a3}
.street{position:absolute;left:0;right:0;bottom:0;background:#cfc3ac}
.stencil{position:absolute;right:28px;font-family:'Courier New',monospace;font-weight:700;letter-spacing:.3em;color:#00000033}
.poster{position:absolute;overflow:hidden;border-radius:3px;box-shadow:0 8px 18px -10px #0006,0 1px 0 #0000001a}
.poster .cat{position:absolute;left:10px;top:10px;display:flex;align-items:center;gap:5px;height:24px;padding:0 9px;border-radius:999px;background:#ffffff33;color:inherit;font-size:11.5px;font-weight:600}
.poster h4{position:absolute;left:12px;right:12px;margin:0;font-weight:900;letter-spacing:-.02em;line-height:.95;text-transform:uppercase}
.poster .meta{position:absolute;left:12px;right:12px;bottom:10px;font-size:11.5px;line-height:1.3}
.poster .big{font-size:34px;font-weight:300;letter-spacing:-.02em;-webkit-text-stroke:1.3px var(--ink,#fff);color:transparent;line-height:1}
.ph{display:grid;place-items:center;text-align:center;background:repeating-linear-gradient(135deg,#ece4d3 0 10px,#e4dac6 10px 20px);color:#6e6657;box-shadow:inset 0 0 0 1.5px #c9bba0}
.ph b{display:block;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#4d4639}
.ph small{display:block;font-size:11px;margin-top:3px}
.bay{position:absolute;top:0;bottom:0;border-left:1px dashed #00000022}
.hour{position:absolute;display:flex;align-items:center;gap:6px;height:26px;padding:0 10px;border-radius:6px;background:#211d15;color:#fffdf8;font-size:12.5px;font-weight:700;font-variant-numeric:tabular-nums}
.more{position:absolute;display:flex;align-items:center;gap:6px;padding:7px 10px;background:#fff6c9;color:#211d15;font-size:12.5px;font-weight:700;transform:rotate(-2deg);box-shadow:0 3px 8px -4px #0005;border:0}
.now{position:absolute;display:flex;align-items:center;gap:5px;height:26px;padding:0 9px;border-radius:999px;background:#c23b0e;color:#fff;font-size:12px;font-weight:700}
.arrow{position:absolute;display:grid;place-items:center;width:48px;height:48px;border-radius:50%;background:#fffdf8;border:1px solid #e6ddcb;box-shadow:0 4px 12px -6px #0005;color:#211d15}
.arrow svg{width:20px;height:20px}
.ruler{display:flex;align-items:flex-end;gap:3px}
.ruler span{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1;font-size:11px;color:#6e6657;font-variant-numeric:tabular-nums}
.ruler i{display:block;width:100%;border-radius:3px 3px 0 0;background:#d9cdb5}
.ruler .vis i{background:#00768b}
.ruler .vis{color:#00566a;font-weight:700}
.listh{display:flex;align-items:baseline;justify-content:space-between;gap:12px}
.listh h3{margin:0;font-size:24px;letter-spacing:-.5px}
.group{border-top:1px solid #e6ddcb}
.group h5{position:relative;margin:0;padding:10px 0 6px;font-size:13px;font-weight:700;color:#4d4639;font-variant-numeric:tabular-nums}
.row{display:grid;grid-template-columns:62px 1fr auto;gap:12px;align-items:center;min-height:52px;padding:6px 0;border-top:1px solid #efe7d6}
.row .t{font-weight:700;font-variant-numeric:tabular-nums}
.row .s{display:block;color:#6e6657;font-size:12.5px}
.row .sk{display:block;height:10px;border-radius:5px;background:#e6ddcb}
.chip{display:inline-flex;align-items:center;gap:5px;height:26px;padding:0 9px;border-radius:999px;background:#efe7d6;font-size:12px;font-weight:600;color:#4d4639}
.chip.warn{background:#fff1c7;color:#7a4b00}
.tabbar{position:absolute;left:0;right:0;bottom:0;display:grid;grid-template-columns:repeat(5,1fr);padding:6px 6px 26px;background:rgba(255,253,248,.96);border-top:1px solid #e6ddcb;z-index:5}
.tab{display:flex;flex-direction:column;align-items:center;gap:3px;min-height:48px;justify-content:center;color:#211d15;font-size:11.5px;font-weight:500}
.tab span.ic{display:grid;place-items:center;width:52px;height:30px;border-radius:15px}
.tab.on{color:#00566a;font-weight:700}
.tab.on span.ic{background:#def5f8}
.tab svg{width:22px;height:22px}
.fab{position:absolute;right:16px;display:grid;place-items:center;width:52px;height:52px;border-radius:50%;background:#1f6f7d;color:#fff;box-shadow:0 6px 18px rgba(0,0,0,.18);z-index:6}
.dots{display:flex;align-items:center;gap:6px}
.dots i{width:8px;height:8px;border-radius:50%;background:#cdbfa3}
.dots i.on{width:22px;border-radius:4px;background:#211d15}
.tag{display:inline-block;padding:3px 8px;border-radius:6px;background:#211d15;color:#fffdf8;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
'''.replace('#cdbf a3', '#cdbfa3')

P = {
    'arrowL': '<path d="m15 5.5-6.5 6.5 6.5 6.5"/>',
    'arrowR': '<path d="m9 5.5 6.5 6.5L9 18.5"/>',
    'search': '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4-4"/>',
    'down': '<path d="m7 10 5 5 5-5"/>',
    'bookmark': '<path d="M6.5 3.5h11v17l-5.5-3.8-5.5 3.8v-17Z"/>',
    'cal': '<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M8 3v4M16 3v4M3.5 10.5h17"/>',
    'home': '<path d="m3.5 10.5 8.5-7 8.5 7"/><path d="M5.5 9v11h13V9"/>',
    'doc': '<path d="M14 3H6.5v18h11V6.5L14 3Z"/><path d="M14 3v3.5h3.5M9 11h6M9 14.5h6M9 18h3.5"/>',
    'tram': '<rect x="5" y="3.5" width="14" height="14" rx="3"/><path d="M5 11h14M9 21l1.5-3.5M15 21l-1.5-3.5"/>',
    'grid': '<rect x="4" y="4" width="6.5" height="6.5" rx="1.8"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.8"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.8"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.8"/>',
    'spark': '<path d="m12 2.5 2.6 6.9 6.9 2.6-6.9 2.6-2.6 6.9-2.6-6.9L2.5 12l6.9-2.6L12 2.5Z"/>',
    'drop': '<path d="M12 3.5s6 6.4 6 10.5a6 6 0 0 1-12 0c0-4.1 6-10.5 6-10.5Z"/>',
    'sort': '<path d="M7 4v16M3.5 16.5 7 20l3.5-3.5M14 6h7M14 11h5M14 16h3"/>',
    'pin': '<path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11Z"/><circle cx="12" cy="10" r="2.3"/>',
    'list': '<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
}


def ic(name, size=20):
    return f'<svg width="{size}" height="{size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{P[name]}</svg>'


def page(title, w, h, body):
    return f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{title}</title>
<script src="./support.js"></script>
</head>
<body>
<x-dc>
<helmet>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800;900&amp;family=Permanent+Marker&amp;display=swap" rel="stylesheet">
<style>body{{margin:0}}{CSS}</style>
</helmet>
<div style="width: {w}px; height: {h}px; position: relative; overflow: hidden; background: #faf6ee; color: #211d15; font-family: Geist, system-ui, sans-serif; font-size: 15px; line-height: 1.4">
{body}
</div>
</x-dc>
<script type="text/x-dc" data-dc-script data-props='{{"$preview":{{"width":{w},"height":{h}}}}}'>
class Component extends DCLogic {{
  renderVals() {{
    return {{}};
  }}
}}
</script>
</body>
</html>
'''


# ── Scene pieces ───────────────────────────────────────────────────────────
def sky(w, h, stops):
    """A sky band whose colour follows the hours of the posters under it."""
    grad = ','.join(f'{c} {p}%' for c, p in stops)
    # One continuous silhouette: Kranhäuser, the cathedral, the TV tower.
    shapes = []
    x = 0
    unit = [
        ('rect', 0, 0.55, 0.06, 0.45), ('rect', 0, 0.55, 0.13, 0.1),
        ('rect', 0.16, 0.55, 0.06, 0.45), ('rect', 0.16, 0.55, 0.13, 0.1),
        ('rect', 0.32, 0.7, 0.12, 0.3), ('tower', 0.5, 0.15, 0.01, 0.85),
        ('rect', 0.47, 0.72, 0.08, 0.28), ('spire', 0.66, 0.05, 0.05, 0.95),
        ('spire', 0.73, 0.05, 0.05, 0.95), ('rect', 0.64, 0.45, 0.16, 0.55),
        ('rect', 0.85, 0.62, 0.05, 0.38), ('rect', 0.92, 0.5, 0.03, 0.5),
    ]
    span = 520
    while x < w:
        for kind, ux, uy, uw, uh in unit:
            X, Y, W, H = x + ux * span, uy * h, uw * span, uh * h
            if kind == 'rect':
                shapes.append(f'<rect x="{X:.0f}" y="{Y:.0f}" width="{W:.0f}" height="{H:.0f}"/>')
            elif kind == 'spire':
                shapes.append(f'<path d="M{X:.0f} {h} V{Y + H * .35:.0f} L{X + W / 2:.0f} {Y:.0f} L{X + W:.0f} {Y + H * .35:.0f} V{h} Z"/>')
            else:
                shapes.append(f'<rect x="{X:.0f}" y="{Y:.0f}" width="3" height="{H:.0f}"/><ellipse cx="{X + 1.5:.0f}" cy="{Y + H * .2:.0f}" rx="10" ry="3"/>')
        x += span
    return (f'<div class="sky" style="height:{h}px;background:linear-gradient(90deg,{grad})">'
            f'<svg width="{w}" height="{h}" viewBox="0 0 {w} {h}" style="position:absolute;inset:0" fill="#26314d" aria-hidden="true">{"".join(shapes)}</svg></div>')


DAY = [('#7cc3ea', 0), ('#8fcdea', 55), ('#f3c98b', 100)]
EVENING = [('#f3c98b', 0), ('#d97757', 30), ('#4b3d8a', 70), ('#1c2344', 100)]


def column(x, y, w, poster_html, caption='Coming up · 1 of 4 · drag to turn'):
    """The column: always one poster tall, plus its cap and base."""
    ph = round(w * 1.42)
    return f'''<div style="position:absolute;left:{x}px;top:{y}px;width:{w + 40}px">
  <div style="height:{round(w * .12)}px;margin:0 0 -2px;border-radius:50% 50% 8px 8px / 100% 100% 8px 8px;background:#2f4f40"></div>
  <div style="height:8px;margin:0 6px;background:#c9a94a"></div>
  <div style="position:relative;height:{ph}px;margin:0 20px;background:#3a2a9c;overflow:hidden">{poster_html}</div>
  <div style="height:14px;margin:0 12px;background:#2f4f40;border-radius:0 0 4px 4px"></div>
  <div style="margin-top:8px;text-align:center;color:#6e6657;font-size:11.5px">{caption}</div>
</div>'''


def street_poster(w, h):
    return f'''<div class="poster" style="position:absolute;inset:0;background:linear-gradient(160deg,#5b3bd6,#3a22a8);color:#ffe36e;--ink:#ffe36e;border-radius:0">
  <span class="cat">Culture</span>
  <span class="tag" style="position:absolute;left:12px;top:{round(h * .2)}px;background:#ffe36e;color:#211d15;transform:rotate(-3deg)">Tonight · 30 Sept</span>
  <h4 style="top:{round(h * .2) + 26}px;font-size:{round(w * .12)}px">Street culture night</h4>
  <div class="meta"><div class="big" style="font-size:{round(w * .16)}px">18:30</div>until 21:00 · Rheinauhafen<br>Price not listed</div>
</div>'''


def cinema_poster(x, y, w, h, rot=-1.5):
    return f'''<div class="poster" style="left:{x}px;top:{y}px;width:{w}px;height:{h}px;transform:rotate({rot}deg);background:radial-gradient(circle at 70% 30%,#f7efc8 0 14%,#1d1745 15%);color:#fffdf8">
  <span class="cat">Culture</span>
  <span class="chip warn" style="position:absolute;left:10px;top:40px;height:22px;font-size:10.5px">{ic('drop', 12)}Rain likely · sample forecast</span>
  <h4 style="top:{round(h * .44)}px;font-size:{round(w * .12)}px">Cinema under the stars</h4>
  <div class="meta"><div class="big" style="font-size:{round(w * .15)}px">20:00</div>until 22:00 · Stadtgarten<br>Price not listed</div>
</div>'''


def placeholder(x, y, w, h, n, rot=0, note=''):
    return (f'<div class="poster ph" style="left:{x}px;top:{y}px;width:{w}px;height:{h}px;transform:rotate({rot}deg)">'
            f'<span><b>Placeholder {n}</b><small>100-event day<br>layout only{note}</small></span></div>')


def tabbar():
    tabs = [('Today', 'home'), ('Events', 'cal'), ('Paperwork', 'doc'), ('Travel', 'tram'), ('Services', 'grid')]
    return '<nav class="tabbar" aria-label="Sections">' + ''.join(
        f'<span class="tab{" on" if n == "Events" else ""}"><span class="ic">{ic(i, 22)}</span>{n}</span>' for n, i in tabs) + '</nav>'


def phone_top():
    return (f'<div class="top"><span class="brand">Expadu</span><span class="city">Cologne {ic("down", 12)}</span>'
            f'<span style="display:grid;place-items:center;width:44px;height:44px">{ic("bookmark")}</span><span class="av">A</span></div>')


def phone_filters():
    return (f'<div class="filters" style="padding:10px 12px 8px;flex-wrap:nowrap;overflow:hidden">'
            f'<span class="date" style="height:40px;font-size:14px">Wed 30 Sept {ic("down", 12)}</span>'
            f'<span class="pill" style="height:40px;flex:1">{ic("search", 18)}Search</span>'
            f'<span class="pill" style="height:40px;width:44px;padding:0;justify-content:center">{ic("sort", 18)}</span></div>')


def desk_top():
    nav = ''.join(f'<span class="{"on" if n == "Events" else ""}">{ic(i)}{n}</span>' for n, i in [
        ('Today', 'home'), ('Events', 'cal'), ('Paperwork', 'doc'), ('Travel', 'tram'), ('Services', 'grid')])
    return (f'<div class="dtop"><span class="brand" style="margin:0;font-size:24px">Expadu</span><nav>{nav}</nav>'
            f'<span class="pill">{ic("pin", 18)}Cologne {ic("down", 14)}</span>{ic("bookmark")}<span class="av">A</span></div>')


def desk_filters():
    cats = ''.join(f'<span class="pill ghost">{c}</span>' for c in ['Culture', 'Games', 'Outdoors', 'Language'])
    return (f'<div class="filters" style="padding:18px 32px 14px">'
            f'<span class="date">Wed 30 Sept {ic("down", 14)}</span>'
            f'<span class="pill" style="width:180px">{ic("search", 18)}Search events</span>'
            f'<span class="pill on">All events</span>{cats}'
            f'<span style="margin-left:auto" class="pill">{ic("pin", 18)}Area {ic("down", 14)}</span></div>')


def ruler(hours, visible, height=34, counts=None):
    """The whole day under the wall: one bar per hour, the shown hours marked."""
    counts = counts or {}
    cells = []
    for hr in hours:
        n = counts.get(hr, 4)
        cells.append(f'<span class="{"vis" if hr in visible else ""}"><i style="height:{max(4, min(height, n * 3))}px"></i>{hr:02d}</span>')
    return f'<div class="ruler" aria-label="The day, one bar per hour">{"".join(cells)}</div>'


# Shape of the placeholder day: how many events start in each hour (layout
# only; the two real sample events are at 18 and 20).
COUNTS = {8: 2, 9: 3, 10: 6, 11: 5, 12: 4, 13: 6, 14: 5, 15: 7, 16: 8, 17: 9, 18: 11, 19: 10, 20: 9, 21: 7, 22: 5, 23: 3}
HOURS = list(range(8, 24))


def event_list(x, y, w, compact=False, title='All 100 events today', focus=None, hours=((13, 3), (14, 2), (18, 3), (19, 2))):
    groups = []
    for hr, label_rows in hours:
        rows = []
        if hr == 18:
            rows.append(f'<div class="row"><span class="t">18:30</span><span><strong>Street culture night</strong><span class="s">Rheinauhafen · until 21:00 · Price not listed</span></span><span class="chip">Culture</span></div>')
        for i in range(label_rows - (1 if hr == 18 else 0)):
            rows.append(f'<div class="row"><span class="t" style="color:#a99f8c">{hr}:··</span><span><span class="sk" style="width:{60 + (i * 37) % 30}%"></span><span class="s">Placeholder · layout only</span></span><span class="chip" style="opacity:.6">Category</span></div>')
        more = COUNTS[hr] - label_rows
        hi = ' style="background:#def5f8;margin:0 -10px;padding:10px 10px 6px;border-radius:10px"' if focus == hr else ''
        groups.append(f'<div class="group"><h5{hi}>{hr}:00 · {COUNTS[hr]} events</h5>{"".join(rows)}'
                      + (f'<div style="padding:8px 0 12px;color:#00566a;font-size:13px;font-weight:600">Show {more} more at {hr}:00</div>' if more > 0 else '') + '</div>')
    return (f'<div style="position:absolute;left:{x}px;top:{y}px;width:{w}px">'
            f'<div class="listh" style="margin-bottom:10px"><h3 style="{"font-size:20px" if compact else ""}">{title}</h3>'
            + ('' if compact else '<span style="color:#6e6657;font-size:13px">Grouped by hour · same filters as the wall</span>') + '</div>'
            + ''.join(groups) + '</div>')


# ── A: a fixed wall you walk along through the day ─────────────────────────
def a_desktop():
    W, H = 1440, 1560
    wall_w, wall_h = 1376, 700
    left0 = 330  # posters start right of the column
    bay = 210
    parts = [sky(wall_w, 170, DAY)]
    parts.append('<div style="position:absolute;left:0;right:0;top:170px;height:8px;background:#cdbfa3"></div>')
    # Hour bays: 13 to 17 visible, two poster spots each.
    n = 1
    for i, hr in enumerate(range(13, 18)):
        bx = left0 + i * bay
        parts.append(f'<div class="bay" style="left:{bx}px;top:178px;bottom:70px"></div>')
        parts.append(f'<span class="hour" style="left:{bx + 12}px;top:192px">{hr}:00</span>')
        parts.append(placeholder(bx + 18, 230, 170, 200, n, -1 + (i % 3)))
        parts.append(placeholder(bx + 24, 444, 160, 170, n + 1, 1 - (i % 2) * 2))
        n += 2
        if hr == 16:
            parts.append(f'<button type="button" class="more" style="left:{bx + 30}px;top:600px">+6 more at 16:00</button>')
        if hr == 17:
            parts.append(f'<button type="button" class="more" style="left:{bx + 30}px;top:600px">+7 more at 17:00</button>')
    parts.append(f'<span class="now" style="left:{left0 + 82}px;top:192px">Now 13:10</span>')
    parts.append(f'<div class="street" style="height:70px"></div>')
    parts.append(f'<span class="stencil" style="bottom:22px;font-size:20px">PLAKATIEREN VERBOTEN</span>')
    # The column stays put while the wall slides behind it.
    parts.append(f'<div style="position:absolute;left:0;top:178px;bottom:70px;width:330px;background:linear-gradient(90deg,#e9e1d1 88%,#e9e1d100)"></div>')
    parts.append(column(30, 196, 230, street_poster(230, 327)))
    parts.append(f'<button type="button" class="arrow" aria-label="Earlier" style="left:300px;top:400px">{ic("arrowL")}</button>')
    parts.append(f'<button type="button" class="arrow" aria-label="Later" style="right:20px;top:400px">{ic("arrowR")}</button>')
    parts.append(f'<div style="position:absolute;right:24px;top:20px;padding:8px 12px;border-radius:12px;background:#fffdf8d9;font-size:12.5px"><strong>21° Sunny</strong><br>13:10 · Sample weather</div>')
    wall = f'<div class="wall" style="position:absolute;left:32px;top:140px;width:{wall_w}px;height:{wall_h}px">{"".join(parts)}</div>'
    under = (f'<div style="position:absolute;left:32px;top:{140 + wall_h + 16}px;width:{wall_w}px">'
             f'{ruler(HOURS, range(13, 18), counts=COUNTS)}'
             f'<div style="margin-top:6px;color:#6e6657;font-size:12px">The whole day: one bar per hour, the hours on the wall in teal. Tap an hour to walk there. Bars show the placeholder day.</div></div>')
    body = desk_top() + desk_filters() + wall + under + event_list(32, 140 + wall_h + 110, 900)
    return page('Events · A · fixed wall · 1440', W, H, body)


def a_phone():
    W, H = 390, 844
    wall_h = 520
    parts = [sky(366, 92, DAY), '<div style="position:absolute;left:0;right:0;top:92px;height:6px;background:#cdbfa3"></div>']
    # Column first on the street, then one poster per hour; the next peeks in.
    parts.append(column(10, 104, 150, street_poster(150, 213), 'Coming up · 1 of 4'))
    parts.append(f'<div class="bay" style="left:200px;top:98px;bottom:50px"></div><span class="hour" style="left:208px;top:108px">13:00</span>')
    parts.append(placeholder(208, 144, 120, 180, 1, -1))
    parts.append(f'<button type="button" class="more" style="left:210px;top:306px">+5 more at 13:00</button>')
    parts.append(f'<div class="bay" style="left:338px;top:98px;bottom:50px"></div><span class="hour" style="left:346px;top:108px">14:00</span>')
    parts.append(placeholder(346, 144, 120, 180, 2, 1))
    parts.append(f'<span class="now" style="left:208px;top:356px">Now 13:10</span>')
    parts.append('<div class="street" style="height:50px"></div>')
    parts.append(f'<div style="position:absolute;left:0;right:0;bottom:58px;padding:0 12px">{ruler(HOURS, [13], 22, COUNTS)}</div>')
    wall = f'<div class="wall" style="position:absolute;left:12px;top:116px;width:366px;height:{wall_h}px">{"".join(parts)}</div>'
    hint = '<div style="position:absolute;left:12px;right:12px;top:642px;color:#6e6657;font-size:12px;text-align:center">Swipe the wall through the day · morning ← → night</div>'
    lst = (f'<div style="position:absolute;left:16px;right:16px;top:676px"><div class="listh"><h3 style="font-size:20px">All 100 events today</h3>'
           f'<span style="color:#00566a;font-size:13px;font-weight:600">{ic("list", 16)}</span></div>'
           f'<div class="row"><span class="t" style="color:#a99f8c">13:··</span><span><span class="sk" style="width:70%"></span><span class="s">Placeholder · layout only</span></span><span class="chip" style="opacity:.6">Category</span></div></div>')
    body = phone_top() + phone_filters() + wall + hint + lst + tabbar() + '<span class="fab" style="bottom:96px">' + ic('spark', 22) + '</span>'
    return page('Events · A · fixed wall · 390', W, H, body)


def a_phone_list():
    W, H = 390, 844
    head = (f'<div style="position:absolute;left:0;right:0;top:0;padding:10px 16px;background:#faf6eef2;border-bottom:1px solid #e6ddcb;z-index:3">'
            f'<div style="display:flex;align-items:center;justify-content:space-between"><strong style="font-size:17px">All 100 events today</strong><span class="chip">Back to the wall</span></div>'
            f'<div style="margin-top:8px;overflow:hidden">{ruler(HOURS, [18], 18, COUNTS)}</div></div>')
    note = '<div style="position:absolute;left:16px;right:16px;top:96px;padding:8px 10px;border-radius:10px;background:#fff6c9;font-size:12.5px">Opened from “+10 more at 18:00” on the wall.</div>'
    body = head + note + event_list(16, 140, 358, compact=True, title='18:00 and later', focus=18, hours=((18, 4), (19, 3))) + tabbar()
    return page('Events · A · the list at 18:00 · 390', W, H, body)


# ── B: a paged wall ────────────────────────────────────────────────────────
def b_desktop():
    W, H = 1440, 1240
    wall_w, wall_h = 1376, 700
    parts = [sky(wall_w, 170, DAY), '<div style="position:absolute;left:0;right:0;top:170px;height:8px;background:#cdbfa3"></div>']
    parts.append(column(30, 196, 230, street_poster(230, 327)))
    k = 1
    for r in range(2):
        for c in range(3):
            parts.append(placeholder(360 + c * 300, 210 + r * 230, 220, 210, k, (-1) ** (c + r)))
            k += 1
    parts.append('<div class="street" style="height:70px"></div>')
    parts.append(f'<div style="position:absolute;left:360px;bottom:20px;display:flex;align-items:center;gap:14px"><span class="dots"><i class="on"></i><i></i><i></i><i></i><i></i><i></i><i></i></span><strong style="font-size:13px">Page 1 of 17</strong><span style="color:#4d4639;font-size:12.5px">6 posters a page, in time order</span></div>')
    parts.append(f'<button type="button" class="arrow" aria-label="Next page" style="right:20px;top:400px">{ic("arrowR")}</button>')
    wall = f'<div class="wall" style="position:absolute;left:32px;top:140px;width:{wall_w}px;height:{wall_h}px">{"".join(parts)}</div>'
    body = desk_top() + desk_filters() + wall + event_list(32, 140 + wall_h + 40, 900)
    return page('Events · B · paged wall · 1440', W, H, body)


def b_phone():
    W, H = 390, 844
    parts = [sky(366, 92, DAY), '<div style="position:absolute;left:0;right:0;top:92px;height:6px;background:#cdbfa3"></div>']
    parts.append(column(10, 104, 150, street_poster(150, 213), 'Coming up · 1 of 4'))
    parts.append(placeholder(210, 120, 140, 160, 1, -1))
    parts.append(placeholder(214, 296, 136, 150, 2, 1))
    parts.append('<div class="street" style="height:50px"></div>')
    parts.append('<div style="position:absolute;left:0;right:0;bottom:14px;display:flex;justify-content:center;align-items:center;gap:10px"><span class="dots"><i class="on"></i><i></i><i></i><i></i><i></i></span><strong style="font-size:12.5px">1 of 50</strong></div>')
    wall = f'<div class="wall" style="position:absolute;left:12px;top:116px;width:366px;height:520px">{"".join(parts)}</div>'
    lst = '<div style="position:absolute;left:16px;right:16px;top:660px"><h3 style="margin:0;font-size:20px">All 100 events today</h3></div>'
    body = phone_top() + phone_filters() + wall + lst + tabbar()
    return page('Events · B · paged wall · 390', W, H, body)


# ── C: highlights on the wall, the rest in the list ────────────────────────
def c_desktop():
    W, H = 1440, 1240
    wall_w, wall_h = 1376, 700
    parts = [sky(wall_w, 170, DAY), '<div style="position:absolute;left:0;right:0;top:170px;height:8px;background:#cdbfa3"></div>']
    parts.append(column(30, 196, 230, street_poster(230, 327)))
    k = 1
    for r in range(2):
        for c in range(3):
            parts.append(placeholder(360 + c * 300, 210 + r * 230, 220, 210, k, (-1) ** (c + r), '<br>next from now'))
            k += 1
    parts.append('<div class="street" style="height:70px"></div>')
    parts.append('<div style="position:absolute;left:360px;bottom:22px;font-size:13px"><strong>Next 6 from 13:10</strong> <span style="color:#4d4639">· 94 more in the list below</span></div>')
    wall = f'<div class="wall" style="position:absolute;left:32px;top:140px;width:{wall_w}px;height:{wall_h}px">{"".join(parts)}</div>'
    body = desk_top() + desk_filters() + wall + event_list(32, 140 + wall_h + 40, 900, title='The rest of today · 94 events')
    return page('Events · C · highlights + list · 1440', W, H, body)


def c_phone():
    W, H = 390, 844
    parts = [sky(366, 92, DAY), '<div style="position:absolute;left:0;right:0;top:92px;height:6px;background:#cdbfa3"></div>']
    parts.append(column(10, 104, 150, street_poster(150, 213), 'Coming up · 1 of 4'))
    parts.append(placeholder(210, 120, 140, 160, 1, -1, '<br>next from now'))
    parts.append(placeholder(214, 296, 136, 150, 2, 1, '<br>next from now'))
    parts.append('<div class="street" style="height:50px"></div>')
    parts.append('<div style="position:absolute;left:0;right:0;bottom:16px;text-align:center;font-size:12.5px"><strong>Next 6 from 13:10</strong> · swipe for 4 more</div>')
    wall = f'<div class="wall" style="position:absolute;left:12px;top:116px;width:366px;height:520px">{"".join(parts)}</div>'
    lst = ('<div style="position:absolute;left:16px;right:16px;top:660px"><h3 style="margin:0;font-size:20px">The rest of today · 94</h3>'
           '<div class="row"><span class="t" style="color:#a99f8c">14:··</span><span><span class="sk" style="width:70%"></span><span class="s">Placeholder · layout only</span></span><span class="chip" style="opacity:.6">Category</span></div></div>')
    body = phone_top() + phone_filters() + wall + lst + tabbar()
    return page('Events · C · highlights + list · 390', W, H, body)


ROW_Y = 5860
BOARDS = [
    ('WallA-desktop.dc.html', a_desktop, 0, 1440, 1560, 'A · fixed wall you walk along · 1440'),
    ('WallA-phone.dc.html', a_phone, 1520, 390, 844, 'A · fixed wall · 390'),
    ('WallA-list.dc.html', a_phone_list, 1990, 390, 844, 'A · 390 · “+10 more at 18:00” opens the list'),
]
BOARDS_B = [
    ('WallB-desktop.dc.html', b_desktop, 0, 1440, 1240, 'B · paged wall · 1440'),
    ('WallB-phone.dc.html', b_phone, 1520, 390, 844, 'B · paged wall · 390'),
]
BOARDS_C = [
    ('WallC-desktop.dc.html', c_desktop, 0, 1440, 1240, 'C · highlights + list · 1440'),
    ('WallC-phone.dc.html', c_phone, 1520, 390, 844, 'C · highlights + list · 390'),
]

if __name__ == '__main__':
    index_path = os.path.join(PROJ, 'canvas.json')
    index = json.load(open(index_path))
    y = ROW_Y + 240
    rows = [('wallA', 'Wall on a 100-event day · A · a fixed wall you walk along through the day', BOARDS),
            ('wallB', 'Wall · B · a paged wall', BOARDS_B),
            ('wallC', 'Wall · C · the next few on the wall, the rest in a list', BOARDS_C)]
    for note_id, text, boards in rows:
        index['notes'][note_id] = {'x': 0, 'y': y - 240, 'text': text, 'kind': 'title1', 'maxW': 2380}
        tallest = 0
        for name, build, x, w, h, title in boards:
            open(os.path.join(PROJ, name), 'w').write(build())
            index['boards'][name] = {'x': x, 'y': y, 'w': w, 'h': h, 'title': title}
            if name not in index['order']:
                index['order'].append(name)
            tallest = max(tallest, h)
        y += tallest + 120 + 240
    json.dump(index, open(index_path, 'w'), indent=2, ensure_ascii=False)
    print('ok')
