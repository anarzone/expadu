#!/usr/bin/env python3
"""Port the Stories board (canvas "Events reimagined", Wall2) into the
Events mock: the street card, the hour rings and the story viewer become JS
template functions, and the board's own logic becomes a class, in
events-next/street-art.js; its animations go to events-next/street-art.css.

The board's templates use x-dc syntax ({{holes}}, sc-if, sc-for, onX
handlers). Each becomes a template literal: holes are escaped values, sc-if
a condition, sc-for a map, handlers data-on-* ids that street.js routes.
Subtrees with no holes are stored once (data-s) so later renders leave them
alone. Poster slots (<mx-poster>) are filled with the mock's own posters.

Usage: port_street.py <events-next dir>"""
import json, os, re, sys
from html.parser import HTMLParser

OUT = sys.argv[1] if len(sys.argv) > 1 else '.'
WIDE = 2240  # the widest the desktop street is drawn (a 1920 x 800 screen needs about 2230)
sys.argv = sys.argv[:1]  # the generators read an asset map from argv
import gen_scene as GS
import gen_apps as GA
VOID = {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'}
EVENTS = {'click': 'click', 'doubleclick': 'dblclick', 'pointerdown': 'pointerdown', 'pointermove': 'pointermove',
          'pointerup': 'pointerup', 'pointercancel': 'pointercancel', 'pointerenter': 'pointerenter',
          'pointerleave': 'pointerleave', 'wheel': 'wheel', 'keydown': 'keydown', 'scroll': 'scroll',
          'change': 'change', 'input': 'input', 'submit': 'submit'}
HOLE = re.compile(r'\{\{\s*(.*?)\s*\}\}')


class Node:
    def __init__(self, tag, attrs):
        self.tag, self.attrs, self.kids = tag, attrs, []


class Parse(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.root = Node('#root', [])
        self.stack = [self.root]

    def handle_starttag(self, tag, attrs):
        n = Node(tag, [(k, '' if v is None else v) for k, v in attrs])
        self.stack[-1].kids.append(n)
        if tag not in VOID:
            self.stack.append(n)

    def handle_startendtag(self, tag, attrs):
        self.stack[-1].kids.append(Node(tag, [(k, '' if v is None else v) for k, v in attrs]))

    def handle_endtag(self, tag):
        for i in range(len(self.stack) - 1, 0, -1):
            if self.stack[i].tag == tag:
                del self.stack[i:]
                return

    def handle_data(self, d):
        self.stack[-1].kids.append(d)


PAINT = ('fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin')


def size_svgs(n):
    """The page styles every svg as a 23px outlined icon. A drawing here
    keeps what it says itself, inline: its size, and its paint (or the
    default where it says nothing)."""
    if isinstance(n, str):
        return
    if n.tag == 'svg':
        a = dict(n.attrs)
        unit = lambda x: x + 'px' if re.fullmatch(r'[\d.]+', x) else x
        style = ''
        w, h = a.get('width', ''), a.get('height', '')
        if w and h and '{{' not in w + h:
            style += f'width:{unit(w)};height:{unit(h)};'
        for prop in PAINT:
            v = a.get(prop, '')
            if '{{' not in v:
                style += f'{prop}:{v or "initial"};'
        style += 'flex-shrink:1;' + a.get('style', '')
        n.attrs = [(k, v) for k, v in n.attrs if k != 'style'] + [('style', style)]
    for k in n.kids:
        size_svgs(k)


def parse(markup):
    p = Parse()
    p.feed(markup)
    p.close()
    size_svgs(p.root)
    return p.root


def lit(s):
    """Text for a JS template literal."""
    return s.replace('\\', '\\\\').replace('`', '\\`').replace('${', '\\${')


def esc_text(s):
    return s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')


def esc_attr(s):
    return s.replace('&', '&amp;').replace('"', '&quot;')


def expr(path, scope):
    path = path.strip()
    if re.fullmatch(r'true|false|null|-?\d+(\.\d+)?', path):
        return path
    parts = path.split('.')
    head = parts[0]
    base = head if head in scope else 'v.' + head
    return base + ''.join(f'?.[{json.dumps(p)}]' for p in parts[1:])


def has_holes(n):
    if isinstance(n, str):
        return '{{' in n
    if n.tag in ('sc-if', 'sc-for', 'mx-poster'):
        return True
    if any('{{' in v or k.startswith('on') for k, v in n.attrs):
        return True
    return any(has_holes(k) for k in n.kids)


STATIC = []


def static_html(n):
    """A hole-free node as plain HTML."""
    if isinstance(n, str):
        return esc_text(n)
    a = ''.join(f' {k}="{esc_attr(v)}"' for k, v in n.attrs)
    if n.tag in VOID:
        return f'<{n.tag}{a}>'
    return f'<{n.tag}{a}>' + ''.join(static_html(k) for k in n.kids) + f'</{n.tag}>'


def interp(s, scope, fn):
    out, i = '', 0
    for m in HOLE.finditer(s):
        out += lit(s[i:m.start()])
        out += '${h.' + fn + '(' + expr(m.group(1), scope) + ')}'
        i = m.end()
    return out + lit(s[i:])


SVG_PARENTS = {'svg', 'g', 'defs', 'clippath', 'mask', 'pattern', 'symbol'}


def emit_kids(n, scope):
    """Children; in a drawing, a run of unchanging shapes is grouped and
    stored once (a plain <g> draws exactly the same)."""
    if n.tag not in SVG_PARENTS:
        return ''.join(emit(k, scope) for k in n.kids)
    out, run = '', []

    def flush():
        nonlocal out, run
        html = ''.join(static_html(k) for k in run)
        if len(run) > 1 and len(html) > 150:
            STATIC.append(html)
            out += f'<g data-s="{len(STATIC) - 1}">${{h.s({len(STATIC) - 1})}}</g>'
        else:
            out += ''.join(emit(k, scope) for k in run)
        run = []
    for k in n.kids:
        if not has_holes(k) and not (isinstance(k, str) and not k.strip() and not run):
            run.append(k)
        else:
            flush()
            out += emit(k, scope)
    flush()
    return out


def emit(n, scope):
    if isinstance(n, str):
        if '{{' not in n:
            return lit(esc_text(n))
        out, i = '', 0
        for m in HOLE.finditer(n):
            out += lit(esc_text(n[i:m.start()])) + '${h.t(' + expr(m.group(1), scope) + ')}'
            i = m.end()
        return out + lit(esc_text(n[i:]))
    kids = lambda sc: emit_kids(n, sc)
    if n.tag == '#root':
        return kids(scope)
    attrs = dict(n.attrs)
    if n.tag == 'sc-if':
        c = HOLE.fullmatch(attrs['value'].strip()).group(1)
        return '${(' + expr(c, scope) + ') ? `' + kids(scope) + '` : ``}'
    if n.tag == 'sc-for':
        lst = HOLE.fullmatch(attrs['list'].strip()).group(1)
        var = attrs['as']
        sc = scope | {var, '$index'}
        return '${(' + expr(lst, scope) + ' || []).map((' + var + ', $index) => `' + kids(sc) + '`).join(``)}'
    if n.tag == 'mx-poster':
        return '${h.poster(' + expr(attrs['v'], scope) + ', ' + json.dumps(attrs['where']) + ')}'
    # A large subtree with no holes is stored once.
    if not has_holes(n) and n.tag not in VOID and n.kids:
        inner = ''.join(static_html(k) for k in n.kids)
        if len(inner) > 60:
            STATIC.append(inner)
            a = ''.join(f' {k}="{lit(esc_attr(v))}"' for k, v in n.attrs)
            sid = len(STATIC) - 1
            return f'<{n.tag}{a} data-s="{sid}">${{h.s({sid})}}</{n.tag}>'
    out = '<' + n.tag
    for k, v in n.attrs:
        if k.startswith('hint-'):
            continue
        whole = HOLE.fullmatch(v.strip()) if v else None
        if k.startswith('on') and whole:
            ev = EVENTS[k[2:]]
            out += f' data-on-{ev}="${{h.on(' + expr(whole.group(1), scope) + ')}"'
        elif whole and v.strip() == v:
            out += '${h.a(' + json.dumps(k) + ', ' + expr(whole.group(1), scope) + ')}'
        elif '{{' in v:
            out += f' {k}="' + interp(esc_attr(v), scope, 'e') + '"'
        else:
            out += f' {k}="{lit(esc_attr(v))}"'
    out += '>'
    if n.tag in VOID:
        return out
    return out + kids(scope) + f'</{n.tag}>'


def template(markup):
    return 'function (v, h) { return `' + emit(parse(markup), set()) + '`; }'


# ── The board's pieces, as on the canvas ───────────────────────────────────
def parts(phone):
    base = dict(GS.PHONE if phone else GS.DESK)
    base['p'] = 'stp' if phone else 'std'
    base['model'] = 'stories'
    base['noPanel'] = True
    base['mx'] = 'mock'
    base['emptyHtml'] = ''
    if phone:
        base.update(vertical=True, pageH=372, zoneLeft=192, zoneW=154, cols=[6], rows=[213], per=1, pw=142, ph=270, pool='day')
    else:
        # On the page the street fills the screen (owner, 13 Oct 2026): it
        # is drawn up to WIDE across and shown from the board's 1361 up, as
        # wide as the screen's shape allows; the wider wall has its own
        # patches, scraps and puddles.
        base.update(
            oneWall=True,
            W0=base['W'], W=WIDE,
            holes=base['holes'] + [(1560, 704, 58, 34), (1904, 846, 66, 20), (2150, 640, 50, 38)],
            scraps=base['scraps'] + [(1500, 780, 112, 62, '#9db4c0', -2), (1760, 268, 96, 70, '#d9a8a8', 2), (2080, 300, 104, 58, '#e8c66a', -1)],
            front=dict(base['front'], puddles=base['front']['puddles'] + [(1520, 948, 60), (1880, 944, 78), (2150, 950, 66)]),
        )
    card = GS.card(base)
    # The page sets the card's place; the board's margin goes.
    card = card.replace(f'margin:{"0 19px" if phone else "0 0 0 32px"};', 'margin:0;', 1)
    rings = GA.rings(phone).replace('class="mx mx-art"', 'class="st-art"')
    rings = rings.replace("padding:14px 32px 10px", "padding:14px 0 10px").replace("padding:10px 14px 8px", "padding:10px 0 8px")
    real = GA.MX.poster_markup
    GA.MX.poster_markup = lambda V, where: f'<mx-poster v="{V}" where="{where}"></mx-poster>'
    try:
        story = GA.story_viewer(phone)
    finally:
        GA.MX.poster_markup = real
    story = story.replace('<div class="mx" style="position:relative;', '<div class="st-story-poster" style="position:relative;', 1)
    assert '<mx-poster v="st.p"' in story and 'st-story-poster' in story
    # Save is the mock's own (planning-ui.js); the viewer sits over the page.
    story = story.replace('onClick="{{st.save}}"', 'data-save-item="{{st.id}}"')
    if phone:
        story = story.replace('position:absolute;inset:0;z-index:60;', 'position:fixed;inset:0;z-index:58;', 1)
    else:
        story = story.replace('position:absolute;left:calc(50% - 230px);top:110px;width:460px;height:900px;z-index:40;',
                              'position:fixed;left:calc(50% - 230px);top:max(16px, calc(50vh - 450px));width:460px;height:min(900px, calc(100vh - 32px));z-index:58;', 1)
        story = story.replace('position:absolute;inset:0;z-index:39;', 'position:fixed;inset:0;z-index:57;', 1)
        assert story.count('; top:520px; z-index:41') == 2
        story = story.replace('; top:520px; z-index:41', '; position: fixed; top:calc(50vh - 24px); z-index:59')
    jc = {k: base[k] for k in ('phone', 'mode', 'W', 'SKY') if k in base}
    jc.update({k: base[k] for k in ('zoneW', 'bayW', 'px', 'pw', 'ph', 'rows', 'per', 'cols', 'pool', 'stepBays', 'spots', 'nowPill', 'vertical', 'pageH', 'oneWall') if k in base})
    jc['col'] = {'pitch': 264, 'pw': 264, 'ph': 515, 'inset': 0} if phone else {'pitch': 205, 'pw': 205, 'ph': 316, 'inset': 29.5}
    # The page's drum is round: its posters bend round it (gen_scene).
    jc['col'].update(drum=True, win=264, slices=3 if phone else 4, reach=1 if phone else 2, depth=1600 if phone else 1400)
    jc['mxSizes'] = ({'wallW': 142, 'wallH': 270, 'colW': 264, 'colH': 515} if phone
                     else {'wallZoom': round(190 / 300, 4), 'colZoom': round(316 / 560, 4), 'storyZoom': round(380 / 300, 4)})
    jc['model'] = 'stories'
    jc.update(storyW=300 if phone else 380, storyH=420 if phone else 532, feed=False, chrome=False)
    css = GS.css(base['W'], base['p']).replace('body{margin:0}\n', '')
    if not phone:
        jc['W'] = base['W0']
    return {'card': card, 'rings': rings, 'story': story, 'cfg': jc, 'css': css, 'W': base.get('W0', base['W']), 'H': base['H'], 'maxW': base['W']}


MOCK_LOGIC = r'''
  // ── In the Events mock ────────────────────────────────────────────────
  // The page's own catalogue, filters and day; the posters, sheet, saving
  // and planning are the page's (street.js).
  today() { return (window.ExpaduSampleDay && window.ExpaduSampleDay.date) || '2026-09-30'; }
  day() { return this.__day || this.today(); }
  isPast() { return this.day() < this.today(); }
  asset(f) { return 'assets/' + f; }
  events() {
    if (this._evr && this._evr.src === this.__catalogue) return this._evr;
    const two = (h) => (h < 10 ? '0' : '') + h;
    const ev = (this.__catalogue || []).map((x, i) => Object.assign({}, x, {
      n: i + 1, h: parseInt(x.start, 10), ph: false, time: x.start, until: x.end ? 'until ' + x.end : 'end not listed',
      place: x.venue, price: x.price || 'Price not listed', cat: x.category, src: '', tag: '',
      alt: x.title + ', ' + x.start + ', ' + x.venue,
    })).filter((x) => !isNaN(x.h));
    const COUNTS = {};
    ev.forEach((e) => { COUNTS[e.h] = (COUNTS[e.h] || 0) + 1; });
    for (let h = 0; h < 24; h++) COUNTS[h] = COUNTS[h] || 0;
    this._evr = { ev, COUNTS, two, src: this.__catalogue };
    return this._evr;
  }
  match(e) { return e.date === this.day() && (!this.__ids || this.__ids.has(e.id)); }
  mxInk(e) { const h = parseInt(e.start, 10); return isNaN(h) ? 'paper' : h < 17 ? 'day' : h < 20 ? 'evening' : 'night'; }
  mxShape(e) { return { Culture: 'rings', Games: 'checks', Outdoors: 'waves', Language: 'bubbles' }[e.category] || 'dots'; }
  mxArt(e) { return 'pp--' + this.mxInk(e) + ' pp--' + this.mxShape(e); }
  mxOpen(e, tab) {
    this.setState({ story: null });
    const item = (this.__catalogue || []).find((x) => x.id === e.id);
    if (item && window.ExpaduEventSheet) window.ExpaduEventSheet.open(item, tab || 'about');
  }
  mxSave() {}
  // The column holds while a sheet, a lifted poster or a story is open.
  mxBusy() {
    const s = this.state || {};
    return !!(s.story || document.querySelector('.pp-lift') || document.querySelector('#shared-dialog[open]'));
  }
  // The mock's posters, sized as on the board.
  mxDress(v) {
    const c = this.cfg(), z = c.mxSizes;
    const { ev } = this.events();
    const order = ev.filter((e) => this.match(e)).map((e) => e.n);
    const wall = (p, k) => {
      const i = Math.max(0, order.indexOf(p.n));
      return Object.assign({}, p, { mxKey: 'wall:' + p.id, mxStyle: '--i:' + (k % 6) + ';rotate:' + (i % 2 === 0 ? 1.4 : -1.1) + 'deg;' + (c.phone ? 'width:' + z.wallW + 'px;height:' + z.wallH + 'px' : 'zoom:' + z.wallZoom) });
    };
    let k = 0;
    if (v.grid) v.grid = v.grid.map((p) => wall(p, c.phone ? 0 : k++));
    if (v.bays) v.bays = v.bays.map((b) => Object.assign({}, b, { posters: (b.posters || []).map((p) => wall(p, k++)) }));
    if (v.strip) v.strip = v.strip.map((q, i) => Object.assign({}, q, { mxKey: 'column:' + i + ':' + q.id, mxStyle: c.phone ? 'width:' + z.colW + 'px;height:' + z.colH + 'px' : 'width:364px;height:560px;zoom:' + z.colZoom }));
    if (v.st && v.st.p && v.storyOpen) {
      v.st.id = v.st.p.id;
      v.st.p = Object.assign({}, v.st.p, { mxKey: 'story:' + v.st.p.id, mxStyle: c.phone ? 'width:300px;height:420px' : 'zoom:' + z.storyZoom });
    }
    // A past day has no weather on record.
    if (this.isPast() && v.k) { v.k = Object.assign({}, v.k, { temp: '', name: 'Weather not recorded', chipNote: 'Tue 8 Sept', when: '8 Sept' }); }
  }
'''


def logic():
    L = GA.logic('this.__cfg', real=True)
    L = L.replace("this.__cfg", "this.__cfg", 1)
    # The column holds while something is open over the street.
    L = L.replace("if (s.hold || s.sel || s.lift) return;", "if (s.hold || this.mxBusy()) return;")
    L = L.replace("if (s.hold || s.sel || s.story) return;", "if (s.hold || this.mxBusy()) return;")
    # A story waits while its poster is turned over or its details are open.
    old = "storyTurned() { return !!((this.state || {}).turned || {})['story:0']; }"
    assert old in L
    # On the page a story also waits while its poster's details are open over it.
    L = L.replace(old, "storyTurned() { return !!document.querySelector('.st-story-poster .pp.is-turned, #shared-dialog[open]'); }")
    L = L.replace('__AMAP__', '{}')
    head = L.index('class Component extends DCLogic {')
    body = L[head + len('class Component extends DCLogic {'):]
    end = body.rstrip().rfind('}')
    return 'class extends Base {' + body[:end] + MOCK_LOGIC + '}'


def main():
    out = {}
    css = []
    for name, phone in (('desktop', False), ('phone', True)):
        P = parts(phone)
        out[name] = P
        css.append(P['css'])
    js = ['/* GENERATED from the "Events reimagined" canvas board (2 · Stories) by',
          '   port_street.py: the street card, hour rings and story viewer as templates,',
          '   and the board\'s logic. Edit the board\'s generators, not this file.',
          '   street.js runs it. */',
          '(function (root) {',
          '    const art = {};',
          f'    art.statics = {json.dumps(None)};']
    STATIC.clear()
    for name in ('desktop', 'phone'):
        P = out[name]
        js.append(f'    art[{json.dumps(name)}] = {{')
        js.append(f'        cfg: {json.dumps(P["cfg"])},')
        js.append(f'        size: {json.dumps([P["W"], P["H"]])},')
        js.append(f'        maxW: {json.dumps(P["maxW"])},')
        js.append(f'        card: {template(P["card"])},')
        js.append(f'        rings: {template(P["rings"])},')
        js.append(f'        story: {template(P["story"])},')
        js.append('    };')
    js[6] = f'    art.statics = {json.dumps(STATIC, ensure_ascii=False)};'
    js.append(f'    art.logic = (Base) => {logic()};')
    js.append('    root.ExpaduStreetArt = art;')
    js.append('})(globalThis);')
    open(os.path.join(OUT, 'street-art.js'), 'w').write('\n'.join(js) + '\n')
    head = ('/* GENERATED from the "Events reimagined" canvas board (2 · Stories) by\n'
            '   port_street.py: the street\'s animations (clouds, birds, rain, smoke,\n'
            '   leaves, pigeons), scoped to the desktop (.std) and phone (.stp) street. */\n')
    open(os.path.join(OUT, 'street-art.css'), 'w').write(head + '\n'.join(css) + '\n')
    print('street-art.js', os.path.getsize(os.path.join(OUT, 'street-art.js')), 'statics', len(STATIC),
          'street-art.css', os.path.getsize(os.path.join(OUT, 'street-art.css')))


if __name__ == '__main__':
    main()
