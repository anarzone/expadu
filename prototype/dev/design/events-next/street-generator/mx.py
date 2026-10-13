"""The Events mock's own posters and details sheet, embedded in a canvas
artboard: its markup (captured from the running mock for every event and
state), its CSS (kept to what that markup uses, scoped to `.mx`), and its
motion (the same keyframes, durations and easings as ExpaduMotion and
ExpaduPosterArt.turn). Behaviour lives in LOGIC, as DCLogic methods."""
import json, os, re
from html.parser import HTMLParser
import mxcss

HERE = os.path.dirname(os.path.abspath(__file__))
CAP = json.load(open(os.path.join(HERE, 'mx', 'capture.json')))
D = CAP['desktop']
IDS = [x['id'] for x in D['catalogue']]
H = lambda s: '{{' + s + '}}'
T = '{{ true }}'
F = '{{ false }}'

# ── A small DOM ────────────────────────────────────────────────────────────
VOID = {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'}
SVG_CASE = {'viewbox': 'viewBox', 'preserveaspectratio': 'preserveAspectRatio'}


class N:
    def __init__(self, tag, attrs=()):
        self.tag, self.attrs, self.kids = tag, [[k, v] for k, v in attrs], []

    def get(self, k):
        for a in self.attrs:
            if a[0] == k:
                return a[1]
        return None

    def set(self, k, v):
        for a in self.attrs:
            if a[0] == k:
                a[1] = v
                return self
        self.attrs.append([k, v])
        return self

    def rm(self, k):
        self.attrs = [a for a in self.attrs if a[0] != k]
        return self

    def has(self, c):
        return c in (self.get('class') or '').split()

    def all(self, pred):
        out = []
        for k in self.kids:
            if isinstance(k, N):
                if pred(k):
                    out.append(k)
                out += k.all(pred)
        return out

    def one(self, pred):
        r = self.all(pred)
        return r[0] if r else None

    def parent_of(self, node):
        for k in self.kids:
            if k is node:
                return self
            if isinstance(k, N):
                p = k.parent_of(node)
                if p:
                    return p
        return None


class _P(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.root = N('#root')
        self.stack = [self.root]

    def handle_starttag(self, tag, attrs):
        n = N(tag, [(SVG_CASE.get(k, k), '' if v is None else v) for k, v in attrs])
        self.stack[-1].kids.append(n)
        if tag not in VOID:
            self.stack.append(n)

    def handle_startendtag(self, tag, attrs):
        self.stack[-1].kids.append(N(tag, [(SVG_CASE.get(k, k), '' if v is None else v) for k, v in attrs]))

    def handle_endtag(self, tag):
        for i in range(len(self.stack) - 1, 0, -1):
            if self.stack[i].tag == tag:
                del self.stack[i:]
                return

    def handle_data(self, d):
        self.stack[-1].kids.append(d)


def parse(h):
    p = _P()
    p.feed(h)
    p.close()
    return p.root


class Raw(str):
    """Text that is already markup (holes, sc-if)."""


def ser(n):
    if isinstance(n, Raw):
        return str(n)
    if isinstance(n, str):
        return n.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
    if n.tag == '#root':
        return ''.join(ser(k) for k in n.kids)
    a = ''.join(f' {k}="{v.replace("&", "&amp;").replace(chr(34), "&quot;")}"' for k, v in n.attrs)
    if n.tag in VOID:
        return f'<{n.tag}{a}>'
    return f'<{n.tag}{a}>' + ''.join(ser(k) for k in n.kids) + f'</{n.tag}>'


def sc_if(cond, inner, hint=False):
    return f'<sc-if value="{H(cond)}" hint-placeholder-val="{T if hint else F}">{inner}</sc-if>'


def wrap_if(node, cond, hint=False):
    """Replace `node` in its parent by <sc-if cond>node</sc-if>."""
    w = N('sc-if', [('value', H(cond)), ('hint-placeholder-val', T if hint else F)])
    w.kids = [node]
    return w


def replace_in(parent, old, new):
    parent.kids = [new if k is old else k for k in parent.kids]


def cls(n, *names):
    return lambda x: isinstance(x, N) and all(x.has(c) for c in names)


def attr(k, v=None):
    return lambda x: isinstance(x, N) and x.get(k) is not None and (v is None or x.get(k) == v)


# ── Posters ────────────────────────────────────────────────────────────────
def _poster_node(eid, where):
    P = D['posters']
    h = P.get(f'{eid}|{where}') or P.get(f'{eid}|wall') or P.get(f'{eid}|column')
    root = parse(h)
    art = root.one(lambda x: isinstance(x, N) and x.tag == 'article')
    size = 'pp--size-column' if where == 'column' else 'pp--size-wall'
    c = re.sub(r'pp--size-\w+', size, art.get('class'))
    return root, art, c


def poster_markup(V, where):
    """Every event's poster as the mock sets it, one sc-if branch each; V is
    the loop item carrying the state and handlers (see mxPoster)."""
    out = []
    for k, eid in enumerate(IDS):
        root, art, c = _poster_node(eid, where)
        art.set('class', c + H(V + '.cls'))
        art.set('style', H(V + '.style'))
        art.set('data-stamp', H(V + '.stamp'))
        for ev, fn in (('onPointerMove', 'tilt'), ('onPointerEnter', 'tiltOn'), ('onPointerLeave', 'untilt'), ('onKeyDown', 'key')):
            art.set(ev, H(V + '.' + fn))
        front = art.one(lambda x: isinstance(x, N) and x.has('pp-front'))
        back = art.one(lambda x: isinstance(x, N) and x.has('pp-back'))
        back.rm('hidden')
        replace_in(art, front, wrap_if(front, V + '.front', True))
        replace_in(art, back, wrap_if(back, V + '.back'))
        o = art.one(lambda x: isinstance(x, N) and x.has('pp-open'))
        o.rm('data-label').set('aria-expanded', H(V + '.expanded')).set('onClick', H(V + '.turn'))
        for b in art.all(lambda x: isinstance(x, N) and x.has('pp-turn-back')):
            b.set('onClick', H(V + '.turn'))
        for b in art.all(attr('data-save-item')):
            b.set('aria-pressed', H(V + '.saved')).set('aria-label', H(V + '.saveLabel')).set('onClick', H(V + '.save'))
        for b in art.all(attr('data-plan-item')):
            b.set('onClick', H(V + '.plan'))
        for b in art.all(lambda x: isinstance(x, N) and x.tag == 'button' and x.has('pp-back-more')):
            b.set('onClick', H(V + '.more'))
        out.append(sc_if(f'{V}.e{k}', ser(art), k == 0))
    return ''.join(out)


def art_markup(hole):
    """The poster's picture alone (ink and shape), as the sheet's cover uses it."""
    return f'<span class="pp-art {H(hole)}" aria-hidden="true"><span></span></span>'


# ── The details sheet ──────────────────────────────────────────────────────
def _section(snap):
    root = parse(snap)
    dlg = root.one(lambda x: isinstance(x, N) and x.tag == 'dialog')
    return root, dlg


def _panel(root, name):
    return root.one(attr('data-panel', name))


def _plan_form(eid):
    S = D['sheets'][eid]
    root = parse(S['planReveal'])
    planning = _panel(root, 'plan').one(attr('data-place-planning'))
    form = planning.one(lambda x: isinstance(x, N) and x.tag == 'form')
    form.set('onSubmit', H('D.addPlan')).set('data-preview-removed', H('D.prAttr'))
    scroll = form.one(lambda x: isinstance(x, N) and x.has('place-sheet-scroll'))
    # "Removed from your day · Undo", after Remove selection.
    rroot = parse(S['removed'])
    notice = rroot.one(lambda x: isinstance(x, N) and x.has('event-removed-notice'))
    notice.one(lambda x: isinstance(x, N) and x.tag == 'button').set('onClick', H('D.undoRemove'))
    scroll.kids.insert(0, wrap_if(notice, 'D.removedNotice'))
    det = form.one(lambda x: isinstance(x, N) and x.tag == 'details')
    det.rm('open')
    det.one(lambda x: isinstance(x, N) and x.tag == 'summary').set('onClick', H('D.disc'))
    ol = det.one(lambda x: isinstance(x, N) and x.tag == 'ol')
    rail = ol.one(lambda x: isinstance(x, N) and x.has('visit-day-rail'))
    rail.set('class', 'visit-day-rail' + H('D.railCls'))
    card = rail.one(lambda x: isinstance(x, N) and x.has('visit-day-card'))
    card.set('aria-expanded', H('D.cardExp')).set('aria-label', H('D.cardLabel'))
    for ev, fn in (('onDoubleClick', 'reveal'), ('onKeyDown', 'cardKey'), ('onClick', 'cardTap'), ('onPointerDown', 'cardDown'),
                   ('onPointerMove', 'cardMove'), ('onPointerUp', 'cardUp'), ('onPointerCancel', 'cardCancel')):
        card.set(ev, H('D.' + fn))
    card.set('style', H('D.cardStyle'))
    dele = rail.one(lambda x: isinstance(x, N) and x.has('visit-day-delete'))
    dele.one(lambda x: isinstance(x, N) and x.tag == 'button').set('onClick', H('D.delPreview'))
    replace_in(rail, dele, wrap_if(dele, 'D.revealed'))
    proot = parse(S['planRemoved'])
    pnote = proot.one(lambda x: isinstance(x, N) and x.has('visit-day-notice'))
    pnote.one(lambda x: isinstance(x, N) and x.tag == 'button').set('onClick', H('D.restorePreview'))
    i = det.kids.index(ol)
    det.kids[i] = wrap_if(ol, 'D.previewShown', True)
    det.kids.insert(i + 1, wrap_if(pnote, 'D.previewGone'))
    small = form.one(lambda x: isinstance(x, N) and x.has('event-plan-summary')).one(lambda x: isinstance(x, N) and x.tag == 'small')
    small.kids = [Raw(H('D.sumSmall'))]
    return ''.join(ser(k) for k in planning.kids)


def _plan_added(eid):
    S = D['sheets'][eid]
    root = parse(S['planned'])
    planning = _panel(root, 'plan').one(attr('data-place-planning'))
    added = planning.one(lambda x: isinstance(x, N) and x.has('event-added'))
    added.set('class', 'place-sheet-scroll event-added' + H('D.addedCls'))
    foot = planning.one(lambda x: isinstance(x, N) and x.tag == 'footer')
    undo = foot.one(attr('data-event-remove')).set('onClick', H('D.undoAdd'))
    review = foot.one(attr('data-event-review')).set('onClick', H('D.review'))
    rroot = parse(S['plannedReopen'])
    remove = rroot.one(attr('data-event-remove')).set('onClick', H('D.askRemove'))
    croot = parse(S['removeConfirm'])
    conf = croot.one(lambda x: isinstance(x, N) and x.has('event-remove-confirm'))
    conf.set('class', 'event-remove-confirm' + H('D.confirmCls'))
    conf.one(attr('data-cancel-event-remove')).set('onClick', H('D.cancelRemove'))
    conf.one(attr('data-confirm-event-remove')).set('onClick', H('D.confirmRemove'))
    rv = ser(review)
    foot.kids = [Raw(sc_if('D.fJust', ser(undo) + rv, True) + sc_if('D.fReopen', ser(remove) + rv) + sc_if('D.fConfirm', ser(conf)))]
    return ''.join(ser(k) for k in planning.kids)


def _directions(eid):
    S = D['sheets'][eid]
    root = parse(S['dir_transit'])
    panel = _panel(root, 'directions')
    form = panel.one(lambda x: isinstance(x, N) and x.tag == 'form')
    form.set('onSubmit', H('D.openPlanner'))
    inp = form.one(attr('name', 'from'))
    inp.set('value', H('D.from')).set('onChange', H('D.fromInput'))
    note = form.one(lambda x: isinstance(x, N) and x.has('route-field-note'))
    if note:
        lab = form.parent_of(note)
        replace_in(lab, note, wrap_if(note, 'D.fromNote', True))
    for r in form.all(lambda x: isinstance(x, N) and x.tag == 'input' and x.get('type') == 'radio'):
        m = r.get('value')
        r.rm('checked').set('checked', H('D.m_' + m)).set('onChange', H('D.mode_' + m))
    dep = form.one(attr('data-mini-departures'))
    parts = []
    for m in ('transit', 'walk', 'bike'):
        mroot = parse(S['dir_' + m])
        mdep = mroot.one(attr('data-mini-departures'))
        sec = next(k for k in mdep.kids if isinstance(k, N))
        sec.set('class', (sec.get('class') or '') + H('D.depCls'))
        parts.append(sc_if('D.m_' + m, ser(sec), m == 'transit'))
    dep.kids = [Raw(''.join(parts))]
    return panel


def dialog_markup():
    out = []
    for k, eid in enumerate(IDS):
        S = D['sheets'][eid]
        root, dlg = _section(S['about'])
        dlg.set('class', 'detail-dialog' + H('D.dcls')).set('open', T).set('onClick', H('D.outside'))
        dlg.one(attr('data-ui', 'close')).set('onClick', H('D.close'))
        head = dlg.one(lambda x: isinstance(x, N) and x.has('dialog-head'))
        for ev, fn in (('onPointerDown', 'headDown'), ('onPointerMove', 'headMove'), ('onPointerUp', 'headUp'), ('onPointerCancel', 'headUp')):
            head.set(ev, H('D.' + fn))
        sec = dlg.one(lambda x: isinstance(x, N) and x.has('event-sheet'))
        sec.set('data-section', H('D.tab'))
        for b in sec.one(lambda x: isinstance(x, N) and x.has('place-sheet-tabs')).all(attr('data-event-section')):
            n = b.get('data-event-section')
            b.set('aria-pressed', H('D.p_' + n)).set('onClick', H('D.go_' + n))
        about = _panel(sec, 'about')
        about.rm('hidden').set('hidden', H('D.h_about')).set('class', 'place-sheet-panel' + H('D.r_about'))
        about.one(lambda x: isinstance(x, N) and x.has('event-venue')).set('onClick', H('D.go_directions'))
        rem = about.one(attr('data-event-reminder'))
        if rem:
            rem.set('checked', H('D.remind')).set('onChange', H('D.toggleRemind'))
        for sm in about.all(lambda x: isinstance(x, N) and x.tag == 'summary'):
            sm.set('onClick', H('D.disc'))
        save = about.one(attr('data-save-item'))
        if save:
            save.set('aria-pressed', H('D.saved')).set('aria-label', H('D.saveAria')).set('onClick', H('D.save'))
            save.one(attr('data-save-label')).kids = [Raw(H('D.saveText'))]
        for b in about.all(lambda x: isinstance(x, N) and x.get('data-event-section') == 'plan'):
            b.set('onClick', H('D.go_plan'))
        plan = _panel(sec, 'plan')
        if plan is not None and 'plan' in S:
            plan.rm('hidden').set('hidden', H('D.h_plan')).set('class', 'place-sheet-panel' + H('D.r_plan'))
            plan.kids = [Raw('<div data-place-planning="">' + sc_if('D.pForm', _plan_form(eid), True)
                             + sc_if('D.pAdded', _plan_added(eid)) + '</div>')]
        old = _panel(sec, 'directions')
        new = _directions(eid)
        new.rm('hidden').set('hidden', H('D.h_directions')).set('class', 'place-sheet-panel' + H('D.r_directions'))
        replace_in(sec, old, new)
        out.append(sc_if(f'D.e{k}', ser(dlg), k == 0))
    return ''.join(out)


# What the logic needs per event: the summary's length and the From field.
def facts():
    o = {}
    for eid in IDS:
        S = D['sheets'][eid]
        f = {}
        m = re.search(r'event-plan-summary[^>]*><strong>[^<]*</strong><small>([^<]*)</small>', S.get('plan', ''))
        f['len'] = m.group(1) if m else ''
        m = re.search(r'name="from"[^>]*value="([^"]*)"', S['dir_transit'])
        f['from'] = m.group(1) if m else ''
        f['note'] = 'route-field-note' in S['dir_transit']
        f['plannable'] = 'plan' in S
        f['stamp'] = (CAP['desktop'].get('posterPlanned') or {}).get(eid) or 'Added · not confirmed'
        o[eid] = f
    return o


# ── Wrappers that give the markup its context in the mock ──────────────────
def _chain(kind):
    ch = D['chains']['wall' if kind == 'wall' else 'column']
    return ch


def _open(e, extra_style='display:contents'):
    attrs = [(k, v) for k, v in e['attrs'] if k != 'style']
    tag = e['tag']
    if tag == 'html':
        return '<div class="mx-html" style="display:contents">'
    if tag == 'body':
        c = dict(attrs).get('class', '')
        rest = ''.join(f' {k}="{v}"' for k, v in attrs if k != 'class')
        return f'<div class="mx-body {c}"{rest} style="display:contents">'
    d = dict(attrs)
    c = (d.pop('class', '') or '').split()
    if 'id' in d:
        c.append('mx-id-' + d.pop('id'))
    if tag == 'main':
        c.append('mx-main')
    a = (f' class="{" ".join(c)}"' if c else '') + ''.join(f' {k}="{v}"' for k, v in d.items())
    return f'<div{a} style="{extra_style}">'


def chain_open(kind, stop):
    """The mock's ancestors of a poster, as boxless wrappers, down to (not
    including) the element whose class is `stop`."""
    out, closes = [], []
    for e in _chain(kind):
        c = dict(e['attrs']).get('class', '') or ''
        if stop in c.split():
            break
        out.append(_open(e))
        closes.append('</div>')
    return ''.join(out), ''.join(reversed(closes))


def body_open():
    b = next(e for e in D['chains']['wall'] if e['tag'] == 'body')
    return '<div class="mx-html" style="display:contents">' + _open(b), '</div></div>'


# ── CSS ────────────────────────────────────────────────────────────────────
FONT_FILES = {
    'geist-400.woff2': 'mx-geist-400.woff2', 'geist-500.woff2': 'mx-geist-500.woff2',
    'geist-600.woff2': 'mx-geist-600.woff2', 'geist-700.woff2': 'mx-geist-700.woff2',
    'PermanentMarker-Regular.ttf': 'mx-permanent-marker.ttf',
    'bricolage-grotesque-latin.woff2': 'mx-bricolage-latin.woff2',
    'bricolage-grotesque-latin-ext.woff2': 'mx-bricolage-latin-ext.woff2',
}
SAFE_INHERITED = ['font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'color', 'font-feature-settings',
                  'font-variant-numeric', 'font-optical-sizing', '-webkit-font-smoothing', 'text-rendering', 'overflow-wrap',
                  '-webkit-tap-highlight-color', 'font-kerning']


def _units(css):
    css = re.sub(r'(?<![\w.-])(\d*\.?\d+)(?:d|s|l)?vh\b', lambda m: f'calc({m.group(1)} * var(--mx-vh) / 100)', css)
    css = re.sub(r'(?<![\w.-])(\d*\.?\d+)(?:d|s|l)?vw\b', lambda m: f'calc({m.group(1)} * var(--mx-vw) / 100)', css)
    return css.replace('position: fixed', 'position: absolute')


def css(phone, W, Hh, asset):
    R = CAP['phone' if phone else 'desktop']
    htmls = [v for v in R['posters'].values()] + [h for s in R['sheets'].values() for h in s.values()] + [R['toast']['html']]
    TT = mxcss.tokens(htmls)
    TA = mxcss.tokens(htmls + mxcss.chain_html(R))
    body, fonts, unknown = mxcss.build(R['css'], TT, 390 if phone else 1440, 844 if phone else 1000, TA)
    assert not unknown, unknown
    body = _units(body)
    ff = []
    for f in fonts:
        t = f['text']
        m = re.search(r'url\("?([^")]+)"?\)', t)
        name = FONT_FILES.get(os.path.basename(m.group(1)))
        if not name:
            continue
        t = t.replace(m.group(0), f'url("{asset(name)}")')
        t = t.replace('"Festival Marker"', '"Festival Marker"')
        ff.append(t)
    ctx = R['context']
    bv, bi = ctx['body']['vars'], ctx['body']['inh']
    decl = lambda d: ';'.join(f'{k}:{v}' for k, v in d.items())
    root = dict(bv)

    def inherited(i):
        out = {k: i[k] for k in SAFE_INHERITED if i.get(k)}
        # Line height inherits as the page's ratio, not as pixels.
        lh, fs = out.get('line-height', ''), out.get('font-size', '')
        if lh.endswith('px') and fs.endswith('px'):
            out['line-height'] = str(round(float(lh[:-2]) / float(fs[:-2]), 4))
        return out
    inh = inherited(bi)
    wall = {k: v for k, v in ctx['wall']['vars'].items() if bv.get(k) != v}
    col = {k: v for k, v in ctx['column']['vars'].items() if bv.get(k) != v}
    wall.update({k: v for k, v in inherited(ctx['wall']['inh']).items() if inh.get(k) != v})
    col.update({k: v for k, v in inherited(ctx['column']['inh']).items() if inh.get(k) != v})
    own = f'''
.mx{{{decl(root)};{decl(inh)};--mx-vw:{W}px;--mx-vh:{Hh}px}}
.mxc-wall{{{decl(wall)}}}
.mxc-col{{{decl(col)}}}
.mx-art{{position:relative;overflow:hidden;container-type:inline-size}}
.is-lifted{{visibility:hidden}}
.mx-lift-card.is-pending{{visibility:hidden}}
.mx-lift-backdrop{{position:absolute;inset:0;background:rgba(20,16,30,.45)}}
.mx-lift-card{{filter:drop-shadow(0 24px 36px rgba(20,12,4,.45))}}
@media (prefers-reduced-motion: no-preference){{
.mx-lift-backdrop{{animation:mx-fade-in 240ms ease both}}
.mx-lift-backdrop.is-out{{animation:mx-fade-out 300ms ease both}}
}}
@keyframes mx-fade-in{{from{{opacity:0}}}}
@keyframes mx-fade-out{{to{{opacity:0}}}}
/* The modal's own box, as the browser gives a modal dialog. */
:where(.mx-overlay > dialog){{position:absolute;inset:0;margin:auto;max-width:calc(100% - 38px);max-height:calc(100% - 38px);overflow:auto;padding:0;border:0;color:inherit}}
.mx-backdrop{{position:absolute;inset:0;background:rgba(0,0,0,.333)}}
@media (prefers-reduced-motion: no-preference){{
.mx-dlg-in{{animation:mx-dlg-in 300ms cubic-bezier(.22,1,.36,1) both}}
.mx-dlg-out{{animation:mx-dlg-out 180ms cubic-bezier(.4,0,1,1) both}}
.mx-rev-0{{animation:mx-rev-0 260ms cubic-bezier(.22,1,.36,1) both}}
.mx-rev-l{{animation:mx-rev-l 260ms cubic-bezier(.22,1,.36,1) both}}
.mx-rev-r{{animation:mx-rev-r 260ms cubic-bezier(.22,1,.36,1) both}}
.mx-confirm-in{{animation:mx-confirm-in 260ms cubic-bezier(.22,1,.36,1) both}}
}}
@keyframes mx-dlg-in{{from{{opacity:0;transform:translateY(28px) scale(.99)}}to{{opacity:1;transform:translateY(0) scale(1)}}}}
@keyframes mx-dlg-out{{from{{opacity:1;transform:translateY(0) scale(1)}}to{{opacity:0;transform:translateY(20px) scale(.99)}}}}
@keyframes mx-rev-0{{from{{opacity:0;translate:0 8px}}to{{opacity:1;translate:0 0}}}}
@keyframes mx-rev-l{{from{{opacity:0;translate:-12px 0}}to{{opacity:1;translate:0 0}}}}
@keyframes mx-rev-r{{from{{opacity:0;translate:12px 0}}to{{opacity:1;translate:0 0}}}}
@keyframes mx-confirm-in{{from{{opacity:0;transform:translateX(30px)}}to{{opacity:1;transform:translateX(0)}}}}
'''
    if phone:
        # On a phone the sheet is the whole screen. It rises from the
        # bottom and goes back down, as the mock's phone sheets do
        # (ui.js phoneSheet: 280 ms in, 220 ms out), and follows a swipe
        # down on its header.
        own += '''
.mx-overlay > #shared-dialog.detail-dialog{width:100%;max-width:100%;height:100%;max-height:100%;margin:0;border-radius:0}
.mx-overlay > #shared-dialog .dialog-head{touch-action:none}
@media (prefers-reduced-motion: no-preference){
.mx-overlay > #shared-dialog.mx-dlg-in{animation:mx-sheet-in 280ms cubic-bezier(.22,1,.36,1) backwards}
.mx-overlay > #shared-dialog.mx-dlg-out{animation:mx-sheet-out 220ms cubic-bezier(.4,0,1,1) both}
}
@keyframes mx-sheet-in{from{transform:translateY(100%)}to{transform:translateY(0)}}
@keyframes mx-sheet-out{from{transform:var(--mx-drag, translateY(0))}to{transform:translateY(100%)}}
'''
    return '\n'.join(ff) + '\n' + body + own


# ── Toast ──────────────────────────────────────────────────────────────────
def toast_markup():
    o, c = body_open()
    return (o + f'<div class="mx" style="position:absolute;inset:0;pointer-events:none;z-index:95">'
            f'<div class="toast{H("TT.cls")}" id="shared-toast" role="status"><span>{H("TT.msg")}</span>'
            f'<button hidden="{H("TT.noUndo")}" onClick="{H("TT.undo")}">Undo</button></div></div>' + c)


def lift_markup(zoom):
    """The lifted poster: 300 x 470 at the mock's poster scale, zoomed to
    the screen, over a dimmed street."""
    o, c = body_open()
    w, h = round(300 * zoom), round(470 * zoom)
    return sc_if('liftOpen', o + '<div class="mx" style="position:absolute;inset:0;z-index:70">'
                 f'<div class="mx-lift-backdrop{H("liftCls")}" onClick="{H("liftDrop")}"></div>'
                 f'<div class="mx-lift-card{H("liftCardCls")}" style="position:absolute;left:calc(50% - {w / 2}px);top:calc(50% - {h / 2}px);width:{w}px;height:{h}px">'
                 + poster_markup('LP', 'lift') + '</div></div>' + c)


def overlay_markup():
    o, c = body_open()
    return (sc_if('mxOpen', o + '<div class="mx mx-overlay" style="position:absolute;inset:0;z-index:80">'
                  f'<div class="mx-backdrop" onClick="{H("D.close")}"></div>' + dialog_markup() + '</div>' + c))


LOGIC = r'''
  mxIds() { return __MXIDS__; }
  mxFacts() { return __MXFACTS__; }
  mxReduced() { try { return matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (err) { return false; } }
  mxFine() { try { return matchMedia('(hover: hover) and (pointer: fine)').matches && !this.mxReduced(); } catch (err) { return false; } }
  mxRun(el, frames, opt, done) {
    if (!el || !el.animate || this.mxReduced()) { if (done) done(); return; }
    const a = el.animate(frames, Object.assign({ duration: 260, easing: 'cubic-bezier(.22,1,.36,1)', fill: 'both' }, opt || {}));
    a.finished.then(() => { if (done) done(); a.cancel(); }, () => {});
  }
  mxInk(e) { const h = parseInt(e.start, 10); return isNaN(h) ? 'paper' : h < 17 ? 'day' : h < 20 ? 'evening' : 'night'; }
  mxShape(e) { return { Culture: 'rings', Games: 'checks', Outdoors: 'waves', Language: 'bubbles' }[e.category] || 'dots'; }
  mxArt(e) { return 'pp--' + this.mxInk(e) + ' pp--' + this.mxShape(e); }
  mxToast(msg, undo) {
    clearTimeout(this._mxT);
    this.setState({ tmsg: msg, tshow: true, tundo: undo || null });
    this._mxT = setTimeout(() => this.setState({ tshow: false }), 5000);
  }
  mxSave(e, ev) {
    const s = this.state || {};
    const sv = Object.assign({}, s.saved);
    const yes = !sv[e.n];
    if (yes) sv[e.n] = true; else delete sv[e.n];
    this.setState({ saved: sv });
    this.mxToast(yes ? 'Saved for later' : 'Removed from Saved');
    if (ev && ev.currentTarget) this.mxRun(ev.currentTarget, [{ transform: 'scale(.97)' }, { transform: 'scale(1.015)', offset: 0.6 }, { transform: 'scale(1)' }], { duration: 240 });
  }
  // A paper turn in depth: fold to the edge, swap faces, unfold.
  mxTurn(ev, key) {
    const card = ev.currentTarget && ev.currentTarget.closest ? ev.currentTarget.closest('.pp') : null;
    const s = this.state || {};
    const toBack = !(s.turned || {})[key];
    const swap = () => {
      const t = Object.assign({}, (this.state || {}).turned); if (toBack) t[key] = true; else delete t[key];
      this.setState({ turned: t });
      setTimeout(() => { const f = card && card.querySelector(toBack ? '.pp-turn-back' : '.pp-open'); if (f) f.focus({ preventScroll: true }); }, 0);
    };
    if (!card || !card.animate || this.mxReduced()) { swap(); return; }
    card.animate([{ transform: 'perspective(1100px) rotateY(0)' }, { transform: 'perspective(1100px) rotateY(90deg) scale(1.03)' }], { duration: 140, easing: 'cubic-bezier(.4,0,1,1)' }).onfinish = () => {
      swap();
      card.animate([{ transform: 'perspective(1100px) rotateY(-90deg) scale(1.03)' }, { transform: 'perspective(1100px) rotateY(0)' }], { duration: 180, easing: 'cubic-bezier(.22,1,.36,1)' });
    };
  }
  // A poster on the wall or the column is small, so turning it over lifts
  // it into a large card in the middle of the screen: it flies from its
  // place while it turns (the poster's own fold, 170 + 210 ms), and goes
  // back the same way. Everything on its back fits without scrolling.
  mxLiftGeom(root, wrap, from) {
    const R = root.getBoundingClientRect(), b = wrap.getBoundingClientRect();
    return {
      dx: (from.x + from.w / 2) - (b.left - R.left + b.width / 2),
      dy: (from.y + from.h / 2) - (b.top - R.top + b.height / 2),
      sc: from.w / b.width,
    };
  }
  mxLift(ev, key, e) {
    const card = ev.currentTarget && ev.currentTarget.closest ? ev.currentTarget.closest('.pp') : null;
    const root = card && card.closest('[data-mx-root]');
    if (!card || !root) return;
    const r = card.getBoundingClientRect(), R = root.getBoundingClientRect();
    const from = { x: r.left - R.left, y: r.top - R.top, w: r.width, h: r.height };
    const t = Object.assign({}, (this.state || {}).turned); delete t['lift:0'];
    this._liftRoot = root;
    this.setState({ lift: { key, n: e.n, from }, turned: t });
    requestAnimationFrame(() => requestAnimationFrame(() => this.mxLiftIn()));
  }
  mxLiftIn() {
    const s = this.state || {};
    const root = this._liftRoot;
    const wrap = root && root.querySelector('.mx-lift-card');
    if (!wrap || !s.lift) return;
    const art = wrap.querySelector('.pp');
    const show = () => { const t = Object.assign({}, (this.state || {}).turned); t['lift:0'] = true; this.setState({ turned: t }); setTimeout(() => { const f = wrap.querySelector('.pp-turn-back'); if (f) f.focus({ preventScroll: true }); }, 0); };
    if (this.mxReduced() || !art || !art.animate) { this.setState({ lift: Object.assign({}, s.lift, { started: true }) }); show(); return; }
    const g = this.mxLiftGeom(root, wrap, s.lift.from);
    wrap.animate([{ transform: 'translate(' + g.dx + 'px,' + g.dy + 'px) scale(' + g.sc + ')' }, { transform: 'none' }], { duration: 380, easing: 'cubic-bezier(.22,1,.36,1)', fill: 'backwards' });
    this.setState({ lift: Object.assign({}, s.lift, { started: true }) });
    art.animate([{ transform: 'perspective(1100px) rotateY(0)' }, { transform: 'perspective(1100px) rotateY(90deg) scale(1.03)' }], { duration: 170, easing: 'cubic-bezier(.4,0,1,1)' }).onfinish = () => {
      show();
      art.animate([{ transform: 'perspective(1100px) rotateY(-90deg) scale(1.03)' }, { transform: 'perspective(1100px) rotateY(0)' }], { duration: 210, easing: 'cubic-bezier(.22,1,.36,1)' });
    };
  }
  mxDrop() {
    const s = this.state || {};
    if (!s.lift || s.lift.out) return;
    const root = this._liftRoot;
    const wrap = root && root.querySelector('.mx-lift-card');
    const art = wrap && wrap.querySelector('.pp');
    const finish = () => { const t = Object.assign({}, (this.state || {}).turned); delete t['lift:0']; this.setState({ lift: null, turned: t }); };
    if (this.mxReduced() || !art || !art.animate) { finish(); return; }
    const g = this.mxLiftGeom(root, wrap, s.lift.from);
    this.setState({ lift: Object.assign({}, s.lift, { out: true }) });
    art.animate([{ transform: 'perspective(1100px) rotateY(0)' }, { transform: 'perspective(1100px) rotateY(-90deg) scale(1.03)' }], { duration: 150, easing: 'cubic-bezier(.4,0,1,1)' }).onfinish = () => {
      const t = Object.assign({}, (this.state || {}).turned); delete t['lift:0']; this.setState({ turned: t });
      art.animate([{ transform: 'perspective(1100px) rotateY(90deg) scale(1.03)' }, { transform: 'perspective(1100px) rotateY(0)' }], { duration: 190, easing: 'cubic-bezier(.22,1,.36,1)' });
    };
    wrap.animate([{ transform: 'none' }, { transform: 'translate(' + g.dx + 'px,' + g.dy + 'px) scale(' + g.sc + ')' }], { duration: 360, easing: 'cubic-bezier(.4,0,.2,1)', fill: 'forwards' }).onfinish = finish;
  }
  // With a mouse, a poster on the wall leans towards the pointer and catches the light.
  mxTilt(ev, key) {
    if (!this.mxFine()) return;
    const card = ev.currentTarget;
    const box = card.getBoundingClientRect();
    const x = (ev.clientX - box.left) / box.width, y = (ev.clientY - box.top) / box.height;
    cancelAnimationFrame(this._tiltF);
    this._tiltF = requestAnimationFrame(() => {
      card.style.setProperty('--tilt-x', ((x - 0.5) * 8).toFixed(2) + 'deg');
      card.style.setProperty('--tilt-y', ((0.5 - y) * 8).toFixed(2) + 'deg');
      card.style.setProperty('--glare-x', (x * 100).toFixed(1) + '%');
      card.style.setProperty('--glare-y', (y * 100).toFixed(1) + '%');
      if ((this.state || {}).tilt !== key) this.setState({ tilt: key });
    });
  }
  mxUntilt(ev) {
    cancelAnimationFrame(this._tiltF);
    const card = ev.currentTarget;
    ['--tilt-x', '--tilt-y', '--glare-x', '--glare-y'].forEach((n) => card.style.removeProperty(n));
    if ((this.state || {}).tilt) this.setState({ tilt: null });
  }
  mxPoster(e, where, i, style) {
    const s = this.state || {};
    const key = where + ':' + i;
    const turned = !!(s.turned || {})[key];
    const saved = !!(s.saved || {})[e.n];
    const planned = !!(s.planned || {})[e.n];
    const k = this.mxIds().indexOf(e.id);
    const o = {
      key, front: !turned, back: turned, expanded: turned ? 'true' : 'false',
      cls: (turned ? ' is-turned' : '') + (s.tilt === key && where === 'wall' ? ' is-tilting' : '') + (s.lift && s.lift.key === key ? ' is-lifted' : ''),
      saved: saved ? 'true' : 'false', saveLabel: (saved ? 'Unsave ' : 'Save ') + e.title,
      stamp: planned ? this.mxFacts()[e.id].stamp : undefined, style,
      turn: (ev) => (where === 'wall' || where === 'column' ? this.mxLift(ev, key, e) : where === 'lift' ? this.mxDrop() : this.mxTurn(ev, key)),
      save: (ev) => this.mxSave(e, ev),
      plan: () => this.mxOpen(e, 'plan'), more: () => this.mxOpen(e, 'about'),
      tilt: (ev) => { if (where === 'wall') this.mxTilt(ev, key); }, tiltOn: () => {}, untilt: (ev) => { if (where === 'wall') this.mxUntilt(ev); },
      key: (ev) => {
        if (ev.key !== 'Escape') return;
        if (where === 'lift') { ev.stopPropagation(); this.mxDrop(); return; }
        if (turned) { ev.stopPropagation(); const b = ev.currentTarget.querySelector('.pp-turn-back'); this.mxTurn({ currentTarget: b }, key); }
      },
    };
    for (let j = 0; j < this.mxIds().length; j++) o['e' + j] = j === k;
    return Object.assign({}, e, o);
  }
  mxOpen(e, tab) {
    const s = this.state || {};
    clearTimeout(this._dlgT);
    const planned = !!(s.planned || {})[e.n];
    this.setState({ sel: e.n, tab: tab || 'about', dlg: 'in', tabMoved: false, tabDir: 0, story: null, plan: false,
      ps: { stage: planned ? 'reopen' : 'form', revealed: false, gone: false, notice: false, confirm: false, anim: false },
      from: undefined, fromTyped: false, depMoved: false });
  }
  mxClose() {
    const s = this.state || {};
    if (!s.sel || s.dlg === 'out') return;
    clearTimeout(this._dlgT);
    this.setState({ dlg: 'out' });
    this._dlgT = setTimeout(() => this.setState({ sel: 0, dlg: '', tab: 'about' }), this.mxReduced() ? 0 : this.cfg().phone ? 220 : 180);
  }
  // On a phone, the sheet follows a swipe down on its header and closes
  // past a third of the way or on a quick flick; otherwise it settles back.
  mxHeadDown(ev) {
    if (!this.cfg().phone || ev.target.closest('button')) return;
    this._hd = { y: ev.clientY, ly: ev.clientY, lt: performance.now(), v: 0, dy: 0, on: false, dlg: ev.currentTarget.closest('dialog') };
    try { ev.currentTarget.setPointerCapture(ev.pointerId); } catch (err) { /* fine */ }
  }
  mxHeadMove(ev) {
    const g = this._hd;
    if (!g) return;
    const dy = Math.max(0, ev.clientY - g.y);
    if (!g.on) { if (dy < 6) return; g.on = true; }
    const t = performance.now();
    g.v = (ev.clientY - g.ly) / Math.max(1, t - g.lt); g.ly = ev.clientY; g.lt = t; g.dy = dy;
    g.dlg.style.transform = 'translateY(' + dy + 'px)';
  }
  mxHeadUp() {
    const g = this._hd;
    this._hd = null;
    if (!g || !g.on) return;
    if (g.dy > g.dlg.getBoundingClientRect().height / 3 || g.v > 0.5) {
      g.dlg.style.setProperty('--mx-drag', 'translateY(' + g.dy + 'px)');
      g.dlg.style.transform = '';
      this.mxClose();
    } else {
      const from = g.dy;
      g.dlg.style.transform = '';
      this.mxRun(g.dlg, [{ transform: 'translateY(' + from + 'px)' }, { transform: 'translateY(0)' }], { duration: 220 });
    }
  }
  // A disclosure opens and closes with its height (motion.js).
  mxDisc(ev) {
    ev.preventDefault();
    const summary = ev.currentTarget;
    const details = summary.parentElement;
    this._disc = this._disc || new WeakMap();
    const opening = !(this._disc.has(details) ? this._disc.get(details) : details.open);
    const from = details.getBoundingClientRect().height;
    this._disc.set(details, opening);
    details.getAnimations().forEach((a) => a.cancel());
    details.open = true;
    const to = opening ? details.getBoundingClientRect().height : summary.getBoundingClientRect().height;
    details.style.overflow = 'hidden';
    this.mxRun(details, [{ height: from + 'px' }, { height: to + 'px' }], {}, () => {
      details.open = opening;
      details.style.overflow = '';
      this._disc.delete(details);
      if (!opening || !details.closest('.selection-agenda')) return;
      const scroller = details.closest('.place-sheet-scroll');
      if (!scroller) return;
      const b = details.closest('.selection-agenda').getBoundingClientRect(), vp = scroller.getBoundingClientRect();
      const off = Math.min(b.top - vp.top - 12, b.bottom - vp.bottom + 12);
      if (off > 0) scroller.scrollTo({ top: scroller.scrollTop + off, behavior: this.mxReduced() ? 'instant' : 'smooth' });
    });
  }
  mxSheet(v, s) {
    const { ev } = this.events();
    const e = s.sel ? ev.find((x) => x.n === s.sel) : null;
    v.mxOpen = !!e;
    const ids = this.mxIds();
    v.TT = { cls: s.tshow ? ' show' : '', msg: s.tmsg || '', noUndo: !s.tundo, undo: () => { const u = s.tundo; this.setState({ tshow: false }); if (u) u(); } };
    if (!e) { v.D = {}; return; }
    const f = this.mxFacts()[e.id];
    const ps = Object.assign({ stage: 'form', revealed: false, gone: false, notice: false, confirm: false, anim: false }, s.ps);
    const setPs = (patch) => this.setState({ ps: Object.assign({}, ps, patch) });
    const tab = s.tab || 'about';
    const order = ['about', 'plan', 'directions'];
    const planned = Object.assign({}, s.planned);
    const D = { tab, dcls: s.dlg === 'out' ? ' is-leaving mx-dlg-out' : ' mx-dlg-in' };
    ids.forEach((id, j) => { D['e' + j] = id === e.id; });
    order.forEach((n) => {
      D['p_' + n] = tab === n ? 'true' : 'false';
      D['h_' + n] = tab !== n;
      D['r_' + n] = tab === n && s.tabMoved ? (s.tabDir > 0 ? ' mx-rev-r' : s.tabDir < 0 ? ' mx-rev-l' : ' mx-rev-0') : '';
      D['go_' + n] = () => { const t = (this.state || {}).tab || 'about'; if (t === n) return; this.setState({ tab: n, tabMoved: true, tabDir: Math.sign(order.indexOf(n) - order.indexOf(t)) }); };
    });
    D.close = () => this.mxClose();
    D.headDown = (ev2) => this.mxHeadDown(ev2);
    D.headMove = (ev2) => this.mxHeadMove(ev2);
    D.headUp = (ev2) => this.mxHeadUp(ev2);
    D.outside = (ev2) => { if (ev2.target === ev2.currentTarget) { const r = ev2.currentTarget.getBoundingClientRect(); if (ev2.clientX < r.left || ev2.clientX > r.right || ev2.clientY < r.top || ev2.clientY > r.bottom) this.mxClose(); } };
    D.disc = (ev2) => this.mxDisc(ev2);
    // About.
    const saved = !!(s.saved || {})[e.n];
    D.saved = saved ? 'true' : 'false'; D.saveAria = (saved ? 'Unsave ' : 'Save ') + e.title; D.saveText = saved ? 'Saved' : 'Save for later';
    D.save = (ev2) => this.mxSave(e, ev2);
    D.remind = !!(s.remind || {})[e.n];
    D.toggleRemind = (ev2) => {
      const r = Object.assign({}, (this.state || {}).remind); if (r[e.n]) delete r[e.n]; else r[e.n] = true;
      this.setState({ remind: r });
      const sw = ev2.currentTarget.nextElementSibling;
      this.mxRun(sw, [{ transform: 'scale(.97)' }, { transform: 'scale(1.015)', offset: 0.6 }, { transform: 'scale(1)' }], { duration: 240 });
    };
    // Plan.
    D.pForm = ps.stage === 'form'; D.pAdded = ps.stage !== 'form';
    D.fJust = ps.stage === 'added' && !ps.confirm; D.fReopen = ps.stage === 'reopen' && !ps.confirm; D.fConfirm = ps.confirm;
    D.confirmCls = ' mx-confirm-in';
    D.addedCls = ps.anim ? ' mx-rev-0' : '';
    D.removedNotice = ps.notice; D.previewShown = !ps.gone; D.previewGone = ps.gone;
    D.revealed = ps.revealed; D.railCls = ps.revealed ? ' is-revealed' : '';
    D.cardExp = ps.revealed ? 'true' : 'false';
    D.cardLabel = e.title + ', ' + e.start + (e.end ? '–' + e.end : '') + '. ' + (ps.revealed ? 'Hide' : 'Show') + ' delete action';
    D.cardStyle = ps.drag ? 'transform:translateX(' + ps.drag + 'px);transition:none' : '';
    D.sumSmall = ps.gone ? 'Preview removed' : f.len;
    D.prAttr = ps.gone ? 'true' : undefined;
    D.addPlan = (ev2) => { ev2.preventDefault(); planned[e.n] = true; this.setState({ planned, ps: { stage: 'added', anim: true } }); };
    D.undoAdd = () => { delete planned[e.n]; this.setState({ planned, ps: { stage: 'form' } }); };
    D.askRemove = () => setPs({ confirm: true });
    D.cancelRemove = () => setPs({ confirm: false, anim: false });
    D.confirmRemove = () => { delete planned[e.n]; this.setState({ planned, ps: { stage: 'form', notice: true } }); };
    D.undoRemove = () => { planned[e.n] = true; this.setState({ planned, ps: { stage: 'reopen' } }); };
    D.review = () => this.mxToast('Review plan continues in Composer, which isn’t part of this board.');
    // The preview in Your day: swipe left or double-tap shows Delete; neither deletes.
    D.reveal = () => setPs({ revealed: true });
    D.cardTap = () => { if (Date.now() - (this._cardSwiped || 0) < 300) return; if (ps.revealed) setPs({ revealed: false }); };
    D.cardKey = (ev2) => {
      if (ev2.key === 'Enter' || ev2.key === ' ') { ev2.preventDefault(); setPs({ revealed: !ps.revealed }); }
      else if (ev2.key === 'Escape' && ps.revealed) { ev2.stopPropagation(); setPs({ revealed: false }); }
    };
    D.cardDown = (ev2) => { this._cd = { x: ev2.clientX, y: ev2.clientY, open: ps.revealed, on: false }; };
    D.cardMove = (ev2) => {
      const g = this._cd; if (!g) return;
      const dx = ev2.clientX - g.x, dy = ev2.clientY - g.y;
      if (!g.on) { if (Math.max(Math.abs(dx), Math.abs(dy)) < 8) return; if ((g.open ? dx <= 0 : dx >= 0) || Math.abs(dy) >= Math.abs(dx)) { this._cd = null; return; } g.on = true; try { ev2.currentTarget.setPointerCapture(ev2.pointerId); } catch (err) { /* fine */ } }
      const w = 64, d = Math.min(w, Math.abs(dx));
      setPs({ drag: g.open ? -w + d : -d });
    };
    D.cardUp = () => {
      const g = this._cd; this._cd = null;
      if (!g || !g.on) return;
      this._cardSwiped = Date.now();
      const moved = Math.abs((ps.drag || 0) - (g.open ? -64 : 0));
      setPs({ drag: 0, revealed: moved >= 28 ? !g.open : g.open });
    };
    D.cardCancel = () => { this._cd = null; setPs({ drag: 0 }); };
    D.delPreview = () => setPs({ gone: true, revealed: false });
    D.restorePreview = () => setPs({ gone: false });
    // Get there.
    const mode = s.mode || 'transit';
    ['transit', 'walk', 'bike'].forEach((m) => { D['m_' + m] = mode === m; D['mode_' + m] = () => this.setState({ mode: m, depMoved: true }); });
    D.depCls = s.depMoved ? ' mx-rev-0' : '';
    D.from = s.from !== undefined ? s.from : (s.briefFrom || f.from);
    D.fromNote = f.note && !s.fromTyped && !s.briefFrom;
    D.fromInput = (ev2) => this.setState({ from: ev2.target.value, fromTyped: true });
    D.journeyMsg = '';
    D.openPlanner = (ev2) => {
      ev2.preventDefault();
      const from = String(D.from || '').trim();
      this.setState({ briefFrom: from, from: from });
      this.mxToast('Open planner continues in Travel to ' + e.venue + ', which isn’t part of this board.');
    };
    v.D = D;
  }
'''


def logic():
    return LOGIC.replace('__MXIDS__', json.dumps(IDS)).replace('__MXFACTS__', json.dumps(facts(), ensure_ascii=False))
