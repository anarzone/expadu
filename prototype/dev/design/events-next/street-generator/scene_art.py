"""Hand-built SVG art for the Events Wall scene: sky, Cologne skyline, the
plane tree, the plastered wall, the advertising column, the gas lamp, bench,
bike, pigeons, leaves and weather. Every live value (sky colours, sun, moon,
clouds, light, rain) is a {{k.*}} hole filled from the board's logic."""
import math
import random


def H(path):
    return '{{' + path + '}}'


def f(v):
    return f'{v:.1f}'.rstrip('0').rstrip('.')


# ── Shared definitions ─────────────────────────────────────────────────────
def defs(p, W, SKY):
    return f'''<defs>
  <linearGradient id="{p}sky" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" style="stop-color:{H('k.s1')};transition:stop-color 1.2s"/>
    <stop offset="0.32" style="stop-color:{H('k.s2')};transition:stop-color 1.2s"/>
    <stop offset="0.62" style="stop-color:{H('k.s3')};transition:stop-color 1.2s"/>
    <stop offset="0.86" style="stop-color:{H('k.s4')};transition:stop-color 1.2s"/>
    <stop offset="1" style="stop-color:{H('k.s5')};transition:stop-color 1.2s"/>
  </linearGradient>
  <radialGradient id="{p}sunglow">
    <stop offset="0" style="stop-color:{H('k.sunC')};stop-opacity:0.75"/>
    <stop offset="0.35" style="stop-color:{H('k.sunC')};stop-opacity:0.28"/>
    <stop offset="1" style="stop-color:{H('k.sunC')};stop-opacity:0"/>
  </radialGradient>
  <radialGradient id="{p}moonglow">
    <stop offset="0" stop-color="#f4f1dc" stop-opacity="0.45"/>
    <stop offset="1" stop-color="#f4f1dc" stop-opacity="0"/>
  </radialGradient>
  <linearGradient id="{p}cloud" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" style="stop-color:{H('k.cloud')};transition:stop-color 1.2s"/>
    <stop offset="1" style="stop-color:{H('k.cloudShade')};transition:stop-color 1.2s"/>
  </linearGradient>
  <linearGradient id="{p}haze" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" style="stop-color:{H('k.s5')};stop-opacity:0"/>
    <stop offset="1" style="stop-color:{H('k.s5')};stop-opacity:0.55"/>
  </linearGradient>
  <filter id="{p}grain" x="0" y="0" width="100%" height="100%">
    <feTurbulence type="fractalNoise" baseFrequency="0.85" numOctaves="2" seed="7" stitchTiles="stitch"/>
    <feColorMatrix values="0 0 0 0 0.32  0 0 0 0 0.27  0 0 0 0 0.2  0 0 0 0.75 -0.18"/>
  </filter>
  <filter id="{p}mottle" x="0" y="0" width="100%" height="100%">
    <feTurbulence type="fractalNoise" baseFrequency="0.0045 0.009" numOctaves="4" seed="11"/>
    <feColorMatrix values="0 0 0 0 0.42  0 0 0 0 0.35  0 0 0 0 0.26  1.9 0 0 0 -0.92"/>
  </filter>
  <filter id="{p}bloom" x="0" y="0" width="100%" height="100%">
    <feTurbulence type="fractalNoise" baseFrequency="0.007 0.004" numOctaves="3" seed="23"/>
    <feColorMatrix values="0 0 0 0 1  0 0 0 0 0.98  0 0 0 0 0.94  0 1.8 0 0 -0.95"/>
  </filter>
  <filter id="{p}torn" x="-5%" y="-5%" width="110%" height="110%">
    <feTurbulence type="fractalNoise" baseFrequency="0.09" numOctaves="2" seed="5"/>
    <feDisplacementMap in="SourceGraphic" scale="7" xChannelSelector="R" yChannelSelector="G"/>
  </filter>
  <filter id="{p}soft"><feGaussianBlur stdDeviation="2.2"/></filter>
  <filter id="{p}softer"><feGaussianBlur stdDeviation="6"/></filter>
  <linearGradient id="{p}pipe" x1="0" y1="0" x2="1" y2="0">
    <stop offset="0" stop-color="#6c737a"/><stop offset="0.35" stop-color="#d9dee2"/><stop offset="0.55" stop-color="#aeb5bb"/><stop offset="1" stop-color="#5d646b"/>
  </linearGradient>
  <linearGradient id="{p}drip" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="#3a2a18" stop-opacity="0.3"/><stop offset="1" stop-color="#3a2a18" stop-opacity="0"/>
  </linearGradient>
  <linearGradient id="{p}foot" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="#4a3826" stop-opacity="0"/><stop offset="1" stop-color="#4a3826" stop-opacity="0.22"/>
  </linearGradient>
  <linearGradient id="{p}streak" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="#5a4630" stop-opacity="0.16"/><stop offset="0.6" stop-color="#5a4630" stop-opacity="0.07"/><stop offset="1" stop-color="#5a4630" stop-opacity="0"/>
  </linearGradient>
  <linearGradient id="{p}wet" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="#2c2a3a" stop-opacity="0.32"/><stop offset="0.5" stop-color="#2c2a3a" stop-opacity="0.12"/><stop offset="1" stop-color="#2c2a3a" stop-opacity="0.26"/>
  </linearGradient>
  <linearGradient id="{p}puddle" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" style="stop-color:{H('k.s4')}"/><stop offset="1" style="stop-color:{H('k.s2')}"/>
  </linearGradient>
  <radialGradient id="{p}lamp">
    <stop offset="0" stop-color="#ffe2a6" stop-opacity="0.95"/><stop offset="0.25" stop-color="#ffc56e" stop-opacity="0.45"/><stop offset="1" stop-color="#ffb04a" stop-opacity="0"/>
  </radialGradient>
  <linearGradient id="{p}leaf" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="#f2b33d"/><stop offset="1" stop-color="#c2561c"/>
  </linearGradient>
</defs>'''


# ── Sky: gradient, stars, moon, sun, clouds, birds, plane ──────────────────
def puffs(r, cx, cy, w, h):
    """A cumulus: overlapping ellipses, flat base, light top."""
    out = []
    n = max(4, int(w / 26))
    for i in range(n):
        t = i / (n - 1)
        x = cx - w / 2 + t * w
        rr = h * (0.45 + 0.55 * math.sin(math.pi * t)) * r.uniform(0.75, 1.05)
        out.append(f'<ellipse cx="{f(x)}" cy="{f(cy - rr * 0.35)}" rx="{f(rr * 1.15)}" ry="{f(rr * 0.85)}"/>')
    out.append(f'<rect x="{f(cx - w / 2 - h * 0.2)}" y="{f(cy - h * 0.25)}" width="{f(w + h * 0.4)}" height="{f(h * 0.4)}" rx="{f(h * 0.2)}"/>')
    return ''.join(out)


def sky(p, W, SKY, seed=3, ref=None):
    ref = ref or W
    r = random.Random(seed)
    stars = []
    for i in range(int(W / 18)):
        x, y = r.uniform(0, W), r.uniform(4, SKY * 0.7)
        rad = r.choice([0.6, 0.8, 0.8, 1.1, 1.4])
        cls = f' class="tw tw{i % 4}"' if i % 3 == 0 else ''
        stars.append(f'<circle cx="{f(x)}" cy="{f(y)}" r="{rad}"{cls}/>')
    layers = []
    # Three cloud layers drift at their own speeds; each is drawn twice so
    # the loop has no seam.
    for li, (yb, scale, cls, hole) in enumerate([(SKY * 0.28, 1.0, 'drift-a', 'k.c1'), (SKY * 0.52, 1.3, 'drift-b', 'k.c2'), (SKY * 0.74, 1.7, 'drift-c', 'k.c3')]):
        shapes = []
        x = r.uniform(0, W * 0.2)
        while x < W:
            w = r.uniform(70, 150) * scale * (ref / 1361) ** 0.5
            h = w * r.uniform(0.28, 0.4)
            shapes.append(puffs(r, x, yb + r.uniform(-SKY * 0.06, SKY * 0.06), w, h))
            x += w + r.uniform(60, 220) * (ref / 1361) ** 0.6
        body = ''.join(shapes)
        layers.append(f'<g style="opacity:{H(hole)};transition:opacity 1.2s"><g class="{cls}" fill="url(#{p}cloud)">{body}<g transform="translate({W} 0)">{body}</g></g></g>')
    birds = []
    for i, (bx, by, s) in enumerate([(0, 0, 1), (22, 9, 0.85), (40, -6, 0.9), (60, 12, 0.75), (78, 2, 0.8)]):
        birds.append(f'<g transform="translate({bx} {by}) scale({s})"><path class="wing w{i % 3}" d="M-9 0 Q-4.5 -6 0 0 Q4.5 -6 9 0" fill="none" stroke="#2a2a3a" stroke-width="1.8" stroke-linecap="round"/></g>')
    bird_y = SKY * 0.3
    return f'''<rect width="{W}" height="{SKY + 4}" fill="url(#{p}sky)"/>
  <g fill="#fffbe8" style="opacity:{H('k.stars')};transition:opacity 1.2s">{''.join(stars)}</g>
  <g style="transform:translate({H('k.moonX')}px,{H('k.moonY')}px);opacity:{H('k.moonO')};transition:transform 1.2s,opacity 1.2s">
    <circle r="46" fill="url(#{p}moonglow)"/><circle r="15" fill="#3a3b55" opacity="0.55"/><path d="{H('k.moonPath')}" fill="#f4f1dc"/>
  </g>
  <g style="transform:translate({H('k.sunX')}px,{H('k.sunY')}px);opacity:{H('k.sunO')};transition:transform 1.2s,opacity 1.2s">
    <circle class="pulse" r="{f(SKY * 0.42)}" fill="url(#{p}sunglow)"/><circle r="{f(max(11, SKY * 0.085))}" style="fill:{H('k.sunC')}"/>
  </g>
  {''.join(layers)}
  <g style="opacity:{H('k.birds')};transition:opacity 1.2s"><g class="flock" transform="translate(0 {f(bird_y)})">{''.join(birds)}</g></g>
  <g style="opacity:{H('k.plane')};transition:opacity 1.2s"><g class="plane" transform="translate(0 {f(SKY * 0.16)})"><circle r="1.6" fill="#ffffff"/><circle class="blink" cx="-5" r="1.8" fill="#ff5a4e"/></g></g>'''


# ── Cologne skyline ────────────────────────────────────────────────────────
def spire(x, base, top, w, step=6):
    """A gothic west tower: stages narrowing up to an openwork spire with
    crockets along its edges."""
    h = base - top
    s1 = base - h * 0.42   # top of the square stages
    s2 = base - h * 0.55   # octagon
    parts = [
        f'<rect x="{f(x - w / 2)}" y="{f(s1)}" width="{f(w)}" height="{f(base - s1)}"/>',
        f'<rect x="{f(x - w * 0.42)}" y="{f(s2)}" width="{f(w * 0.84)}" height="{f(s1 - s2 + 1)}"/>',
    ]
    # Pinnacles at the stage corners.
    for dx in (-w / 2, w / 2 - w * 0.12):
        parts.append(f'<path d="M{f(x + dx)} {f(s1 + 2)} l{f(w * 0.06)} {f(-h * 0.09)} l{f(w * 0.06)} {f(h * 0.09)} Z"/>')
    for dx in (-w * 0.42, w * 0.42 - w * 0.1):
        parts.append(f'<path d="M{f(x + dx)} {f(s2 + 2)} l{f(w * 0.05)} {f(-h * 0.07)} l{f(w * 0.05)} {f(h * 0.07)} Z"/>')
    # The spire with crockets.
    pts = [(x - w * 0.36, s2)]
    n = 7
    for i in range(1, n + 1):
        t = i / n
        yy = s2 - (s2 - top) * t
        hw = w * 0.36 * (1 - t)
        pts.append((x - hw - (2.2 if i < n else 0), yy + 2))
        pts.append((x - hw, yy))
    rpts = [(2 * x - px, py) for px, py in reversed(pts)]
    d = 'M' + ' L'.join(f'{f(px)} {f(py)}' for px, py in pts + rpts) + ' Z'
    parts.append(f'<path d="{d}"/>')
    parts.append(f'<path d="M{f(x - 1)} {f(top)} h2 v-8 h-2 Z"/><circle cx="{f(x)}" cy="{f(top - 9)}" r="2.2"/>')
    return ''.join(parts)


def skyline(p, W, SKY, seed=9, phone=False, ref=None):
    r = random.Random(seed)
    B = SKY + 2
    k = (ref or W) / 1361 if not phone else 1.0
    far = []
    sc = 0.62 if phone else 1.0
    def X(v):
        return v * k if not phone else v

    # Low distant roofs across the whole width, the Rhine's far bank.
    x = 0
    while x < W:
        w = r.uniform(18, 46) * sc
        h = r.uniform(10, 26) * sc
        far.append(f'<rect x="{f(x)}" y="{f(B - h)}" width="{f(w + 1)}" height="{f(h)}"/>')
        x += w
    if phone:
        cx_kran, cx_tv, cx_mart, cx_dom, cx_bridge = 30, 118, 168, 248, 312
    else:
        cx_kran, cx_tv, cx_mart, cx_dom, cx_bridge = 580, 875, 985, 1140, 1262
    # Kranhäuser: three inverted Ls on the Rheinauhafen.
    lancets_k = []
    for i in range(3):
        bx = cx_kran + i * 92 * sc
        far.append(f'<rect x="{f(bx)}" y="{f(B - 104 * sc)}" width="{f(17 * sc)}" height="{f(104 * sc)}"/>')
        far.append(f'<rect x="{f(bx)}" y="{f(B - 104 * sc)}" width="{f(72 * sc)}" height="{f(15 * sc)}"/>')
        far.append(f'<path d="M{f(bx + 17 * sc)} {f(B - 89 * sc)} l{f(14 * sc)} 0 l{f(-(14 * sc))} {f(16 * sc)} Z"/>')
        for gy in range(3):
            lancets_k.append(f'<rect x="{f(bx + 20 * sc)}" y="{f(B - 101 * sc + gy * 4.5 * sc)}" width="{f(50 * sc)}" height="{f(1.6 * sc)}"/>')
    # Colonius, the TV tower.
    far.append(f'<rect x="{f(cx_tv - 3.5 * sc)}" y="{f(B - 168 * sc)}" width="{f(7 * sc)}" height="{f(168 * sc)}"/>')
    far.append(f'<ellipse cx="{f(cx_tv)}" cy="{f(B - 128 * sc)}" rx="{f(17 * sc)}" ry="{f(6 * sc)}"/><ellipse cx="{f(cx_tv)}" cy="{f(B - 120 * sc)}" rx="{f(11 * sc)}" ry="{f(4 * sc)}"/>')
    far.append(f'<rect x="{f(cx_tv - 1)}" y="{f(B - 205 * sc)}" width="2" height="{f(40 * sc)}"/>')
    # Great St. Martin: the square crossing tower, its tall roof and the
    # four slender corner turrets.
    t0 = B - 120 * sc
    far.append(f'<rect x="{f(cx_mart - 30 * sc)}" y="{f(B - 58 * sc)}" width="{f(60 * sc)}" height="{f(58 * sc)}"/>')
    far.append(f'<rect x="{f(cx_mart - 16 * sc)}" y="{f(t0)}" width="{f(32 * sc)}" height="{f(B - t0)}"/>')
    far.append(f'<path d="M{f(cx_mart - 18 * sc)} {f(t0 + 2)} L{f(cx_mart)} {f(t0 - 44 * sc)} L{f(cx_mart + 18 * sc)} {f(t0 + 2)} Z"/>')
    for dx in (-22, 16):
        tx = cx_mart + dx * sc
        far.append(f'<rect x="{f(tx)}" y="{f(t0 + 4 * sc)}" width="{f(6 * sc)}" height="{f(48 * sc)}"/><path d="M{f(tx - 1)} {f(t0 + 5 * sc)} L{f(tx + 3 * sc)} {f(t0 - 22 * sc)} L{f(tx + 7 * sc)} {f(t0 + 5 * sc)} Z"/>')
    # The Dom: nave, choir and the two west spires.
    dw = 150 * sc
    far.append(f'<path d="M{f(cx_dom - 20 * sc)} {B} V{f(B - 70 * sc)} L{f(cx_dom + dw * 0.55)} {f(B - 92 * sc)} L{f(cx_dom + dw * 0.9)} {f(B - 70 * sc)} V{B} Z"/>')
    far.append(f'<path d="M{f(cx_dom + dw * 0.5)} {f(B - 92 * sc)} l{f(4 * sc)} {f(-(26 * sc))} l{f(4 * sc)} {f(26 * sc)} Z"/>')
    for i in range(5):
        far.append(f'<path d="M{f(cx_dom + dw * (0.25 + i * 0.14))} {f(B - 78 * sc)} l{f(3 * sc)} {f(-(14 * sc))} l{f(3 * sc)} {f(14 * sc)} Z"/>')
    far.append(spire(cx_dom - 26 * sc, B, B - 205 * sc, 34 * sc))
    far.append(spire(cx_dom + 18 * sc, B, B - 205 * sc, 34 * sc))
    # Lancet windows in the towers, in the sky's own colour.
    lancets = []
    for tx in (cx_dom - 26 * sc, cx_dom + 18 * sc):
        for row in range(2):
            yy = B - 40 * sc - row * 32 * sc
            lancets.append(f'<path d="M{f(tx - 5 * sc)} {f(yy)} v{f(-(14 * sc))} q{f(5 * sc)} {f(-(9 * sc))} {f(10 * sc)} 0 v{f(14 * sc)} Z"/>')
    # The Hohenzollern Bridge: three steel arches.
    bridge = []
    for i in range(3):
        ax = cx_bridge - 40 * sc + i * 46 * sc
        bridge.append(f'<path d="M{f(ax)} {f(B - 14 * sc)} Q{f(ax + 23 * sc)} {f(B - 52 * sc)} {f(ax + 46 * sc)} {f(B - 14 * sc)}" fill="none" stroke-width="{f(3 * sc)}"/>')
        for j in range(1, 6):
            t = j / 6
            xx = ax + 46 * sc * t
            yy = (1 - t) ** 2 * (B - 14 * sc) + 2 * (1 - t) * t * (B - 52 * sc) + t ** 2 * (B - 14 * sc)
            bridge.append(f'<line x1="{f(xx)}" y1="{f(yy)}" x2="{f(xx)}" y2="{f(B - 14 * sc)}" stroke-width="1"/>')
    bridge.append(f'<rect x="{f(cx_bridge - 44 * sc)}" y="{f(B - 15 * sc)}" width="{f(146 * sc)}" height="{f(4 * sc)}"/>')
    # Near: the Altstadt, house by house: stepped gables, pitched roofs with
    # dormers, a few flat roofs; lit windows and two smoking chimneys.
    near, wins, smoke = [], [], []
    x = -6
    i = 0
    while x < W:
        kind = r.choice(['step', 'step', 'pitch', 'pitch', 'flat', 'hip'])
        w = r.uniform(30, 58) * sc
        h = r.uniform(16, 34) * sc
        top = B - h
        if kind == 'step':
            steps = r.choice([2, 3, 3])
            sw = w / (steps * 2 + 1)
            d = f'M{f(x)} {B} V{f(top)}'
            cy = top
            for st in range(steps):
                cy -= 7 * sc
                d += f' H{f(x + sw * (st + 1))} V{f(cy)}'
            d += f' H{f(x + w - sw * steps)}'
            for st in range(steps):
                cy += 7 * sc
                d += f' V{f(cy)} H{f(x + w - sw * (steps - st - 1))}'
            d += f' V{B} Z'
            peak = top - steps * 7 * sc
        elif kind == 'pitch':
            ph = w * r.uniform(0.38, 0.55)
            d = f'M{f(x)} {B} V{f(top)} L{f(x + w / 2)} {f(top - ph)} L{f(x + w)} {f(top)} V{B} Z'
            peak = top - ph
            if r.random() < 0.6:
                dx = x + w * r.uniform(0.25, 0.55)
                dy = top - ph * 0.45
                d += f' M{f(dx)} {f(dy + 8 * sc)} V{f(dy)} L{f(dx + 4 * sc)} {f(dy - 4 * sc)} L{f(dx + 8 * sc)} {f(dy)} V{f(dy + 8 * sc)} Z'
        elif kind == 'hip':
            ph = w * 0.3
            d = f'M{f(x)} {B} V{f(top)} L{f(x + w * 0.22)} {f(top - ph)} H{f(x + w * 0.78)} L{f(x + w)} {f(top)} V{B} Z'
            peak = top - ph
        else:
            d = f'M{f(x)} {B} V{f(top - 4 * sc)} H{f(x + w)} V{B} Z M{f(x - 1)} {f(top - 4 * sc)} h{f(w + 2)} v{f(2.5 * sc)} h{f(-(w + 2))} Z'
            peak = top
        near.append(f'<path d="{d}"/>')
        rows = max(1, int(h / (12 * sc)))
        for wy in range(rows):
            for wx in range(max(1, int((w - 8 * sc) / (11 * sc)))):
                if r.random() < 0.5:
                    cls = ' class="flick f{0}"'.format(r.randrange(3)) if r.random() < 0.14 else ''
                    wins.append(f'<rect{cls} x="{f(x + 5 * sc + wx * 11 * sc)}" y="{f(top + 5 * sc + wy * 12 * sc)}" width="{f(4.5 * sc)}" height="{f(6.5 * sc)}" rx="0.6"/>')
        if r.random() < 0.35 or (i in (4, 11) and not phone) or (phone and i == 3):
            cx = x + w * r.uniform(0.2, 0.75)
            chy = min(peak + 6 * sc, top)
            near.append(f'<rect x="{f(cx)}" y="{f(chy - 12 * sc)}" width="{f(6 * sc)}" height="{f(14 * sc)}"/><rect x="{f(cx - 1)}" y="{f(chy - 13 * sc)}" width="{f(6 * sc + 2)}" height="{f(2 * sc)}"/>')
            if (i in (4, 11) and not phone) or (phone and i == 3):
                smoke.append(f'<g transform="translate({f(cx + 3 * sc)} {f(chy - 13 * sc)})">' + ''.join(
                    f'<circle class="smoke s{j}" r="{f((4 + j * 1.5) * (0.7 if phone else 1))}"/>' for j in range(3)) + '</g>')
        x += w + (r.uniform(0, 26) * sc if r.random() < 0.3 else r.uniform(-2, 2))
        i += 1
    return f'''<g style="fill:{H('k.far')};stroke:{H('k.far')};transition:fill 1.2s,stroke 1.2s" stroke-width="0">{''.join(far)}<g fill="none">{''.join(bridge)}</g></g>
  <g style="fill:{H('k.s4')};opacity:0.45;transition:fill 1.2s">{''.join(lancets)}{''.join(lancets_k)}</g>
  <rect y="{f(B - 60 * sc)}" width="{W}" height="{f(60 * sc)}" fill="url(#{p}haze)"/>
  <g style="fill:{H('k.mistC')};opacity:{H('k.mist')};transition:opacity 1.2s"><rect y="{f(B - 46 * sc)}" width="{W}" height="{f(46 * sc)}" filter="url(#{p}softer)"/></g>
  <g fill="#cfd3dc" opacity="0.5">{''.join(smoke)}</g>
  <g style="fill:{H('k.near')};transition:fill 1.2s">{''.join(near)}</g>
  <g fill="#ffd27a" style="opacity:{H('k.windows')};transition:opacity 1.2s">{''.join(wins)}</g>'''


# ── The plane tree over the wall ───────────────────────────────────────────
def leaf_path(s=1.0):
    """A plane-tree leaf: five rounded, pointed lobes round a short stalk."""
    d = ''
    for ang, L in ((-90, 11), (-38, 9.5), (-142, 9.5), (14, 7), (166, 7)):
        a = math.radians(ang)
        tip = (L * s * math.cos(a), L * s * math.sin(a))
        c1 = (L * s * 0.45 * math.cos(a - 0.95), L * s * 0.45 * math.sin(a - 0.95))
        c2 = (L * s * 0.9 * math.cos(a - 0.28), L * s * 0.9 * math.sin(a - 0.28))
        c3 = (L * s * 0.9 * math.cos(a + 0.28), L * s * 0.9 * math.sin(a + 0.28))
        c4 = (L * s * 0.45 * math.cos(a + 0.95), L * s * 0.45 * math.sin(a + 0.95))
        d += (f'M0 0 C{f(c1[0])} {f(c1[1])} {f(c2[0])} {f(c2[1])} {f(tip[0])} {f(tip[1])} '
              f'C{f(c3[0])} {f(c3[1])} {f(c4[0])} {f(c4[1])} 0 0 Z ')
    return d


def leaf(s, fill, rot, x, y, veins=True):
    v = ''
    if veins:
        v = ''.join(f'<path d="M0 0 L{f(L * s * 0.8 * math.cos(math.radians(a)))} {f(L * s * 0.8 * math.sin(math.radians(a)))}" stroke="#6b3a12" stroke-opacity="0.45" stroke-width="0.6"/>' for a, L in ((-90, 11), (-38, 9.5), (-142, 9.5)))
    return f'<g transform="translate({f(x)} {f(y)}) rotate({f(rot)})"><path d="{leaf_path(s)}" fill="{fill}"/>{v}<path d="M0 0 L0 {f(6 * s)}" stroke="#6b3a12" stroke-width="0.8"/></g>'


def tree(p, s=1.0, seed=4):
    """An autumn plane-tree branch hanging over the wall from the yard
    behind; its leaves sway together."""
    r = random.Random(seed)
    back = ['#a85a1e', '#8f4a1a', '#b9782a', '#7e6a2a']
    front = ['#e8a93c', '#d9772a', '#f2c45a', '#c8601f', '#e0952f', '#b8892e', '#9aa046', '#edb64a']
    clusters = [(26, 26, 40), (82, 50, 36), (136, 70, 34), (188, 90, 30), (236, 110, 24), (116, 22, 26), (58, 86, 28), (168, 126, 22), (212, 60, 22), (8, 70, 22)]
    layers = {0: [], 1: []}
    for cx, cy, rad in clusters:
        for li in (0, 1):
            for _ in range(int(rad / (1.5 if li else 2.2))):
                a = r.uniform(0, 2 * math.pi)
                d = r.uniform(0, rad) * (0.85 if li else 1.0)
                x, y = cx + d * math.cos(a), cy + d * math.sin(a) * 0.78
                layers[li].append(leaf(r.uniform(1.1, 1.55), r.choice(back if li == 0 else front), r.uniform(-60, 60) + (180 if r.random() < 0.15 else 0), x, y, veins=li == 1))
    branch = ('<path d="M-12 -8 C40 10 90 40 140 64 S220 104 262 118" fill="none" stroke="#3f2d1f" stroke-width="11" stroke-linecap="round"/>'
              '<path d="M-12 -8 C40 10 90 40 140 64 S220 104 262 118" fill="none" stroke="#6b5139" stroke-width="3" stroke-linecap="round" transform="translate(0 -3)"/>'
              '<path d="M90 42 C100 20 115 12 130 6" fill="none" stroke="#3f2d1f" stroke-width="4.5" stroke-linecap="round"/>'
              '<path d="M150 70 C165 90 168 110 172 130" fill="none" stroke="#3f2d1f" stroke-width="3.5" stroke-linecap="round"/>'
              '<path d="M45 22 C50 50 58 70 62 92" fill="none" stroke="#3f2d1f" stroke-width="3.5" stroke-linecap="round"/>'
              '<path d="M200 92 C206 76 212 66 222 58" fill="none" stroke="#3f2d1f" stroke-width="3" stroke-linecap="round"/>'
              '<path d="M10 20 C14 44 10 60 6 76" fill="none" stroke="#3f2d1f" stroke-width="3" stroke-linecap="round"/>')
    # Seed balls hang from the twigs, as on a London plane in autumn.
    balls = ''.join(f'<line x1="{x}" y1="{y}" x2="{x + 1}" y2="{y + 16}" stroke="#4a3626" stroke-width="0.8"/><circle cx="{x + 1}" cy="{y + 19}" r="3.6" fill="#7a5a32"/><circle cx="{x}" cy="{y + 18}" r="1.2" fill="#a07a48"/>' for x, y in ((150, 92), (226, 120), (64, 96)))
    return f'<g transform="scale({s})"><g class="sway">{branch}{"".join(layers[0])}{balls}{"".join(layers[1])}</g></g>'


def falling_leaves(n, x0, y0, spread, fall, seed=8):
    r = random.Random(seed)
    colours = ['#e2a23a', '#cf6a24', '#f0c454', '#b8521f', '#d88b2d']
    out = []
    for i in range(n):
        x = x0 + r.uniform(0, spread)
        out.append(f'<g transform="translate({f(x)} {f(y0)})"><g class="fall fall{i % 5}" style="--fall:{f(fall)}px">{leaf(1.2, r.choice(colours), 0, 0, 0)}</g></g>')
    return ''.join(out)


# ── The wall ───────────────────────────────────────────────────────────────
def jagged_poly(r, cx, cy, rx, ry, n=34, rough=0.18):
    pts = []
    for i in range(n):
        a = 2 * math.pi * i / n
        k = 1 + r.uniform(-rough, rough)
        pts.append((cx + rx * k * math.cos(a), cy + ry * k * math.sin(a)))
    return 'M' + ' L'.join(f'{f(x)} {f(y)}' for x, y in pts) + ' Z'


def brick_patch(p, r, cid, cx, cy, rx, ry, bw=22, bh=9):
    shape = jagged_poly(r, cx, cy, rx, ry)
    bricks = [f'<rect x="{f(cx - rx - 10)}" y="{f(cy - ry - 10)}" width="{f(rx * 2 + 20)}" height="{f(ry * 2 + 20)}" fill="#cbbca2"/>']
    cols = ['#a4553b', '#b0613f', '#9b4a35', '#b8714f', '#8f4632', '#c07a55', '#a85e44']
    y = cy - ry - 10
    row = 0
    while y < cy + ry + 10:
        x = cx - rx - 10 - (bw / 2 if row % 2 else 0)
        while x < cx + rx + 10:
            c = r.choice(cols)
            bricks.append(f'<rect x="{f(x + 1)}" y="{f(y + 1)}" width="{f(bw - 2)}" height="{f(bh - 2)}" rx="1" fill="{c}"/>')
            bricks.append(f'<rect x="{f(x + 1)}" y="{f(y + 1)}" width="{f(bw - 2)}" height="1.4" fill="#ffffff" opacity="0.14"/>')
            x += bw
        y += bh
        row += 1
    return f'''<clipPath id="{p}{cid}"><path d="{shape}"/></clipPath>
  <g clip-path="url(#{p}{cid})">{''.join(bricks)}<path d="{shape}" fill="none" stroke="#2a1a10" stroke-opacity="0.45" stroke-width="5" transform="translate(2 2)"/></g>
  <path d="{shape}" fill="none" stroke="#d9cbb3" stroke-width="3.2"/><path d="{shape}" fill="none" stroke="#fff6e4" stroke-opacity="0.6" stroke-width="1" transform="translate(-1 -1)"/>'''


def crack(r, x, y, length, ang=90):
    pts = [(x, y)]
    a = math.radians(ang)
    for _ in range(int(length / 7)):
        a += r.uniform(-0.6, 0.6)
        x += 7 * math.cos(a)
        y += 7 * math.sin(a)
        pts.append((x, y))
    d = 'M' + ' L'.join(f'{f(px)} {f(py)}' for px, py in pts)
    br = ''
    if len(pts) > 4:
        bx, by = pts[len(pts) // 2]
        b = [(bx, by)]
        a2 = a + r.choice([-1, 1]) * 0.9
        for _ in range(4):
            a2 += r.uniform(-0.5, 0.5)
            bx += 6 * math.cos(a2)
            by += 6 * math.sin(a2)
            b.append((bx, by))
        br = ' M' + ' L'.join(f'{f(px)} {f(py)}' for px, py in b)
    return (f'<path d="{d}{br}" fill="none" stroke="#fff6e4" stroke-opacity="0.55" stroke-width="1" transform="translate(0.8 0.8)"/>'
            f'<path d="{d}{br}" fill="none" stroke="#6f5d46" stroke-width="1.2" stroke-linejoin="round"/>')


def scrap(p, r, x, y, w, h, colour, rot):
    """An old poster torn off long ago: a faded paper with torn edges, ghost
    print and a strip of tape."""
    pts = []
    n = 14
    for i in range(n + 1):
        pts.append((x + w * i / n, y + r.uniform(0, 5)))
    for i in range(1, n + 1):
        pts.append((x + w - r.uniform(0, 8), y + h * i / n))
    for i in range(n, -1, -1):
        pts.append((x + w * i / n, y + h - r.uniform(0, 14)))
    for i in range(n, 0, -1):
        pts.append((x + r.uniform(0, 6), y + h * i / n))
    d = 'M' + ' L'.join(f'{f(px)} {f(py)}' for px, py in pts) + ' Z'
    ghost = ''.join(f'<rect x="{f(x + 10)}" y="{f(y + 14 + i * 11)}" width="{f(w * r.uniform(0.3, 0.75))}" height="5" rx="2" fill="#3a2f2a" opacity="0.10"/>' for i in range(int(h / 22)))
    tape = f'<rect x="{f(x + w * 0.35)}" y="{f(y - 6)}" width="34" height="12" fill="#fffbe9" opacity="0.55" transform="rotate({r.uniform(-12, 12):.0f} {f(x + w * 0.35 + 17)} {f(y)})"/>'
    return f'<g transform="rotate({rot} {f(x + w / 2)} {f(y + h / 2)})"><path d="{d}" fill="{colour}" opacity="0.78" filter="url(#{p}torn)"/>{ghost}<path d="{d}" fill="none" stroke="#fffaf0" stroke-opacity="0.35" stroke-width="1.2"/>{tape}</g>'


def wall(p, W, H, top, base, pave, seed=21, phone=False, holes=None, scraps=None, plate=None, pipe_x=None, stencil=True, ref=None):
    """Coping, plaster with grain and mottling, damp streaks, brick patches,
    cracks, old paper, a house number, the drainpipe, the basalt base and the
    cobbles."""
    r = random.Random(seed)
    s = 0.55 if phone else 1.0
    more = (W / ref) if ref else 1
    parts = []
    # Plaster.
    parts.append(f'<rect y="{top}" width="{W}" height="{base - top}" fill="#e7dbc4"/>')
    parts.append(f'<rect y="{top}" width="{W}" height="{base - top}" filter="url(#{p}mottle)" opacity="0.85"/>')
    parts.append(f'<rect y="{top}" width="{W}" height="{base - top}" filter="url(#{p}bloom)" opacity="0.8"/>')
    # Patched repairs in a slightly different plaster.
    for _ in range(round((4 if not phone else 2) * more)):
        px, py = r.uniform(0.05, 0.9) * W, r.uniform(top + 40, base - 120)
        pw, ph = r.uniform(70, 180) * s, r.uniform(40, 110) * s
        tone = r.choice(['#ece2cf', '#dccdb1', '#e3d6bd'])
        parts.append(f'<rect x="{f(px)}" y="{f(py)}" width="{f(pw)}" height="{f(ph)}" rx="6" fill="{tone}" opacity="0.75" filter="url(#{p}torn)"/>')
    # Old paste, where posters were scraped off.
    for _ in range(round((5 if not phone else 2) * more)):
        px, py = r.uniform(0.3, 0.95) * W, r.uniform(top + 20, base - 90)
        parts.append(f'<rect x="{f(px)}" y="{f(py)}" width="{f(r.uniform(40, 110) * s)}" height="{f(r.uniform(30, 90) * s)}" fill="#fbf6ea" opacity="0.38" filter="url(#{p}torn)"/>')
    parts.append(f'<rect y="{top}" width="{W}" height="{base - top}" filter="url(#{p}grain)" opacity="0.6"/>')
    # A little green where rain splashes the foot of the wall.
    x = 0
    while x < W:
        w = r.uniform(30, 110) * s
        parts.append(f'<ellipse cx="{f(x + w / 2)}" cy="{f(base - 4)}" rx="{f(w / 2)}" ry="{f(r.uniform(10, 26) * s)}" fill="#6f7d4a" opacity="{r.uniform(0.07, 0.16):.2f}" filter="url(#{p}soft)"/>')
        x += w + r.uniform(20, 120) * s
    # Damp streaks from the coping.
    x = r.uniform(0, 40)
    while x < W:
        w = r.uniform(6, 34) * s
        L = r.uniform(60, (base - top) * 0.6)
        parts.append(f'<path d="M{f(x)} {top} h{f(w)} q{f(r.uniform(-4, 4))} {f(L * 0.5)} {f(-w * 0.2)} {f(L)} h{f(-w * 0.6)} Z" fill="url(#{p}streak)"/>')
        x += r.uniform(40, 140) * s
    for hx, hy, hrx, hry in (holes or []):
        parts.append(brick_patch(p, r, f'bp{hx}', hx, hy, hrx, hry, 22 * s if not phone else 13, 9 * s if not phone else 5.4))
    for sx, sy, sw, sh, sc, rot in (scraps or []):
        parts.append(scrap(p, r, sx, sy, sw, sh, sc, rot))
    for cx, cy, L, a in [(r.uniform(0.1, 0.3) * W, top + 8, 70 * s, 95), (r.uniform(0.6, 0.8) * W, top + 6, 60 * s, 80), (r.uniform(0.35, 0.5) * W, base - 4, 50 * s, -100), (W * 0.93, base - 4, 44 * s, -80)]:
        parts.append(crack(r, cx, cy, L, a))
    # Shade under the coping and at the foot of the wall.
    parts.append(f'<rect y="{top}" width="{W}" height="{f(18 * s + 6)}" fill="url(#{p}drip)"/>')
    parts.append(f'<rect y="{f(base - 70 * s)}" width="{W}" height="{f(70 * s)}" fill="url(#{p}foot)"/>')
    # Coping stones.
    ch = 16 * s + 3
    parts.append(f'<rect y="{f(top - ch)}" width="{W}" height="{f(ch)}" fill="#cdc4b3"/><rect y="{f(top - ch)}" width="{W}" height="2" fill="#f2ede2"/><rect y="{f(top - 3)}" width="{W}" height="3" fill="#a59a86"/>')
    x = r.uniform(30, 90)
    while x < W:
        parts.append(f'<rect x="{f(x)}" y="{f(top - ch)}" width="1.4" height="{f(ch)}" fill="#9d9381"/>')
        x += r.uniform(110, 170) * s
    # House number: blue enamel, white border, a little gloss.
    if plate:
        px, py, pw = plate
        ph = pw * 0.72
        parts.append(f'''<g transform="translate({f(px)} {f(py)})"><rect width="{f(pw)}" height="{f(ph)}" rx="{f(pw * 0.14)}" fill="#1d4d8f"/><rect x="{f(pw * 0.07)}" y="{f(pw * 0.07)}" width="{f(pw * 0.86)}" height="{f(ph - pw * 0.14)}" rx="{f(pw * 0.1)}" fill="none" stroke="#fff" stroke-width="{f(max(1.2, pw * 0.05))}"/>
      <text x="{f(pw / 2)}" y="{f(ph * 0.74)}" text-anchor="middle" font-family="Geist, system-ui, sans-serif" font-weight="700" font-size="{f(ph * 0.62)}" fill="#fff">17</text>
      <rect x="2" y="2" width="{f(pw - 4)}" height="{f(ph * 0.42)}" rx="{f(pw * 0.12)}" fill="#ffffff" opacity="0.16"/><rect x="0" y="{f(ph + 1)}" width="{f(pw)}" height="2" fill="#000" opacity="0.12"/></g>''')
    if pipe_x is not None:
        parts.append(pipe(p, pipe_x, top, base, s))
    # The basalt base.
    parts.append(f'<rect y="{base}" width="{W}" height="{pave - base}" fill="#67635d"/>')
    row_h = (pave - base) / 2
    for row in range(2):
        x = -r.uniform(0, 60) if row else 0
        while x < W:
            w = r.uniform(80, 150) * (s if phone else 1)
            g = r.randint(0x5e, 0x76)
            parts.append(f'<rect x="{f(x + 1)}" y="{f(base + row * row_h + 1)}" width="{f(w - 2)}" height="{f(row_h - 2)}" rx="1.5" fill="#{g:02x}{g - 3:02x}{g - 8:02x}"/>')
            parts.append(f'<rect x="{f(x + 1)}" y="{f(base + row * row_h + 1)}" width="{f(w - 2)}" height="1.4" fill="#fff" opacity="0.10"/>')
            x += w
    parts.append(f'<rect y="{base}" width="{W}" height="{pave - base}" filter="url(#{p}grain)" opacity="0.8"/>')
    parts.append(f'<rect y="{base - 2}" width="{W}" height="3" fill="#8b857c"/>')
    if stencil:
        parts.append(stencil_text(W - (34 if not phone else 12), base, pave, phone))
    # Cobbles.
    parts.append(f'<rect y="{pave}" width="{W}" height="{H - pave}" fill="#5f5a53"/>')
    cs = 1.0 if not phone else 0.7
    y = pave + 1.5
    row = 0
    while y < H:
        ch2 = (7 + row * 0.8) * cs
        x = -(r.uniform(0, 8))
        while x < W:
            w = r.uniform(10, 15) * cs * (1 + row * 0.08)
            g = r.choice([0x8a, 0x94, 0x9c, 0xa3, 0x80, 0x8f])
            tint = r.choice([(0, 0, 0), (6, 2, -4), (-2, 0, 4)])
            col = '#%02x%02x%02x' % (g + tint[0], g - 4 + tint[1], g - 10 + tint[2])
            parts.append(f'<rect x="{f(x + 0.8)}" y="{f(y)}" width="{f(w - 1.6)}" height="{f(ch2)}" rx="{f(ch2 * 0.42)}" fill="{col}"/>')
            parts.append(f'<rect x="{f(x + 2)}" y="{f(y + 0.6)}" width="{f(w - 4)}" height="1.3" rx="0.6" fill="#fff" opacity="0.18"/>')
            x += w
        y += ch2 + 1.4
        row += 1
    return ''.join(parts)


# ── The street: gas lamp, bench, bike, pigeons, puddles ────────────────────
def gas_lamp():
    """A Cologne gas lantern; local origin is the foot of the post (0,0),
    the head's centre at (0,-610)."""
    return f'''<g>
  <ellipse cx="0" cy="2" rx="34" ry="5" fill="#000" opacity="0.18"/>
  <path d="M-22 0 h44 l-6 -14 h-32 Z M-14 -14 h28 l-4 -22 h-20 Z" fill="#2b3a34"/>
  <rect x="-12" y="-40" width="24" height="5" rx="2" fill="#3c4d46"/>
  <path d="M-5.5 -36 L-3.4 -560 h6.8 L5.5 -36 Z" fill="#2b3a34"/>
  <path d="M-2 -36 L-1.2 -560 h1.4 L1 -36 Z" fill="#ffffff" opacity="0.14"/>
  <rect x="-8" y="-300" width="16" height="7" rx="3" fill="#3c4d46"/><rect x="-7" y="-520" width="14" height="6" rx="3" fill="#3c4d46"/>
  <rect x="-24" y="-578" width="48" height="4" rx="2" fill="#2b3a34"/><circle cx="-24" cy="-576" r="3" fill="#2b3a34"/><circle cx="24" cy="-576" r="3" fill="#2b3a34"/>
  <path d="M-13 -568 h26 l-3 8 h-20 Z" fill="#2b3a34"/>
  <path d="M-14 -572 L-22 -640 h44 L14 -572 Z" fill="#c8dcd9" style="opacity:{H('k.glassDay')}"/>
  <path d="M-14 -572 L-22 -640 h44 L14 -572 Z" class="flame" fill="#ffd27a" style="opacity:{H('k.lamp')};transition:opacity 1.2s"/>
  <circle class="flame" cx="0" cy="-596" r="6" fill="#fff4cf" style="opacity:{H('k.lamp')}"/>
  <path d="M-14 -572 L-22 -640 M14 -572 L22 -640 M0 -572 V-640 M-18 -606 h36" stroke="#2b3a34" stroke-width="2.6" fill="none"/>
  <path d="M-26 -640 h52 L10 -664 h-20 Z" fill="#2b3a34"/><path d="M-10 -664 h20 l-4 -8 h-12 Z" fill="#3c4d46"/>
  <circle cx="0" cy="-678" r="5" fill="#2b3a34"/><path d="M-1.5 -683 h3 v-10 h-3 Z" fill="#2b3a34"/>
  <path d="M-26 -640 h52" stroke="#ffffff" stroke-opacity="0.18" stroke-width="1.5"/>
</g>'''


def lamp_glow():
    return f'<g style="opacity:{H("k.lamp")};transition:opacity 1.2s"><circle class="glow" cx="0" cy="-602" r="120" fill="url(#LAMP)"/></g>'


def bench():
    """A park bench, 150 wide; origin at the left foot."""
    slats = ''.join(f'<rect x="0" y="{y}" width="150" height="7" rx="2" fill="{c}"/><rect x="0" y="{y}" width="150" height="1.6" rx="1" fill="#fff" opacity="0.18"/>' for y, c in [(-66, '#8a5a3b'), (-54, '#7d5034'), (-34, '#8f603f'), (-26, '#7a4d31')])
    legs = ''.join(f'<path d="M{x} -64 C{x - 4} -50 {x - 4} -40 {x} -30 L{x - 6} 0 M{x} -30 L{x + 8} 0 M{x - 5} -30 h22" stroke="#24302c" stroke-width="4" fill="none" stroke-linecap="round"/>' for x in (16, 132))
    return f'<g><ellipse cx="75" cy="2" rx="80" ry="4" fill="#000" opacity="0.16"/>{legs}{slats}</g>'


def bike():
    """A city bike with a wicker basket, leaning; origin at the rear wheel's
    contact point."""
    def wheel(cx):
        spokes = ''.join(f'<line x1="{cx}" y1="-27" x2="{f(cx + 25 * math.cos(a))}" y2="{f(-27 + 25 * math.sin(a))}"/>' for a in [i * math.pi / 8 for i in range(16)])
        return f'<g><circle cx="{cx}" cy="-27" r="27" fill="none" stroke="#1d1f22" stroke-width="4.5"/><circle cx="{cx}" cy="-27" r="24" fill="none" stroke="#9aa3a8" stroke-width="1.4"/><g stroke="#b9c0c4" stroke-width="0.6">{spokes}</g><circle cx="{cx}" cy="-27" r="3" fill="#6b7378"/></g>'
    return f'''<g transform="rotate(-4)">
  <ellipse cx="46" cy="2" rx="70" ry="4" fill="#000" opacity="0.18"/>
  {wheel(0)}{wheel(92)}
  <path d="M0 -27 L30 -27 L58 -70 L18 -66 Z M30 -27 L18 -66 M58 -70 L92 -27 M52 -82 L58 -70" fill="none" stroke="#1f5a63" stroke-width="5" stroke-linejoin="round" stroke-linecap="round"/>
  <path d="M-12 -40 Q0 -58 14 -46" fill="none" stroke="#1f5a63" stroke-width="3"/><path d="M78 -40 Q92 -58 106 -46" fill="none" stroke="#1f5a63" stroke-width="3"/>
  <path d="M10 -70 q8 -6 20 -2 l-2 5 q-9 -2 -16 2 Z" fill="#2a1f1a"/><path d="M18 -66 L14 -70" stroke="#2a1f1a" stroke-width="3"/>
  <path d="M52 -82 q8 -6 18 -6 M52 -82 q-6 -2 -8 -8" fill="none" stroke="#2a2a2a" stroke-width="3" stroke-linecap="round"/>
  <g transform="translate(70 -96)"><rect width="30" height="20" rx="3" fill="#b0834f"/><path d="M0 5 h30 M0 10 h30 M0 15 h30 M6 0 v20 M12 0 v20 M18 0 v20 M24 0 v20" stroke="#8a6238" stroke-width="1"/><rect width="30" height="3" rx="1.5" fill="#c99a63"/></g>
  <circle cx="30" cy="-27" r="6" fill="none" stroke="#1d1f22" stroke-width="2"/>
</g>'''


def pigeon(i, flip=False):
    sx = -1 if flip else 1
    return f'''<g transform="scale({sx} 1)"><g class="pig-walk pw{i}">
  <ellipse cx="0" cy="1" rx="13" ry="2.2" fill="#000" opacity="0.18"/>
  <path d="M-11 -3 Q-6 -16 6 -14 Q14 -12 12 -5 Q6 0 -6 -1 L-16 -4 Z" fill="#8a909c"/>
  <path d="M-4 -12 Q2 -16 8 -12 Q4 -8 -2 -8 Z" fill="#a5abb6"/>
  <path d="M-5 -6 Q2 -9 8 -6" stroke="#5f6573" stroke-width="1.2" fill="none"/>
  <g class="pig-head ph{i}"><path d="M5 -13 Q8 -20 13 -19 Q17 -17 15 -13 Z" fill="#6e7c86"/><path d="M5 -13 Q9 -15 13 -12" stroke="#5aa58a" stroke-width="2.4" fill="none" opacity="0.8"/><path d="M5 -14 Q9 -16 13 -13" stroke="#8a5aa5" stroke-width="1.2" fill="none" opacity="0.7"/><circle cx="13" cy="-17" r="1" fill="#e8742c"/><path d="M16 -16 l3 1 l-3 1 Z" fill="#2b2b2b"/></g>
  <path d="M-1 -1 v4 M3 -1 v4" stroke="#d2645a" stroke-width="1.3"/>
</g></g>'''


def rain(W, H, density=1.0):
    lines = []
    cols = int(W / 18 * density)
    for c in range(cols):
        x = c * (W / cols) + (c * 37) % 11
        dy = (c * 29) % 64
        for row in range(int(H / 64) + 2):
            y = (row - 1) * 64 + dy
            lines.append(f'<line x1="{f(x)}" y1="{y}" x2="{f(x - 5)}" y2="{y + 17}"/>')
    return f'<g class="rainfall" stroke="#dde6ff" stroke-width="1.15" stroke-linecap="round" opacity="0.6">{"".join(lines)}</g>'


def splashes(W, y, n, seed=2):
    r = random.Random(seed)
    return ''.join(f'<ellipse class="splash sp{i % 4}" cx="{f(r.uniform(10, W - 10))}" cy="{f(y + r.uniform(0, 12))}" rx="5" ry="1.4" fill="none" stroke="#e6eefc" stroke-width="1"/>' for i in range(n))


def pipe(p, pipe_x, top, base, s):
    """The drainpipe with brackets, hopper and shoe."""
    parts = []
    pw = 16 * s + 3
    parts.append(f'<rect x="{f(pipe_x + 4)}" y="{top}" width="{f(pw)}" height="{base - top}" fill="#000" opacity="0.10" filter="url(#{p}soft)"/>')
    parts.append(f'<path d="M{f(pipe_x - 6 * s)} {f(top - 6)} h{f(pw + 12 * s)} l{f(-6 * s)} {f(18 * s)} h{f(-pw)} Z" fill="url(#{p}pipe)"/>')
    parts.append(f'<rect x="{f(pipe_x)}" y="{f(top + 10 * s)}" width="{f(pw)}" height="{f(base - top - 4)}" fill="url(#{p}pipe)"/>')
    y = top + 60 * s
    while y < base - 30:
        parts.append(f'<rect x="{f(pipe_x - 3)}" y="{f(y)}" width="{f(pw + 6)}" height="{f(6 * s + 2)}" rx="1.5" fill="#5f666d"/><circle cx="{f(pipe_x + pw / 2)}" cy="{f(y + 3 * s + 1)}" r="1.2" fill="#3a4045"/>')
        y += 170 * s
    parts.append(f'<path d="M{f(pipe_x)} {f(base - 8)} h{f(pw)} q0 {f(14 * s)} {f(14 * s)} {f(16 * s)} v{f(6 * s)} h{f(-pw - 14 * s)} Z" fill="url(#{p}pipe)"/>')
    parts.append(f'<circle cx="{f(pipe_x + pw / 2)}" cy="{f(top + (base - top) * 0.55)}" r="{f(7 * s + 2)}" fill="#e94f37"/><circle cx="{f(pipe_x + pw / 2)}" cy="{f(top + (base - top) * 0.55)}" r="{f(3 * s + 1)}" fill="#fff4dd"/>')
    return ''.join(parts)


def stencil_text(x, base, pave, phone=False):
    fs = 18 if not phone else 9.5
    return f'<text x="{f(x)}" y="{f(base + (pave - base) * 0.68)}" text-anchor="end" font-family="\'Courier New\', monospace" font-weight="700" font-size="{fs}" letter-spacing="{f(fs * 0.38)}" fill="#ece6da" opacity="0.62">PLAKATIEREN VERBOTEN</text>'
