"""The Events mock's own CSS, kept to what its posters and sheet use and
scoped so it styles only what sits inside `.mx`."""
import json, re
from html.parser import HTMLParser

DYN_PSEUDO = {'hover', 'active', 'focus', 'focus-visible', 'focus-within', 'checked', 'disabled', 'enabled',
              'placeholder-shown', 'target', 'visited', 'link', 'any-link', 'invalid', 'valid', 'required', 'optional',
              'read-only', 'read-write', 'empty', 'first-child', 'last-child', 'only-child', 'first-of-type',
              'last-of-type', 'nth-child', 'nth-of-type', 'nth-last-child', 'not', 'is', 'where', 'has', 'modal',
              'open', 'indeterminate', 'default', 'user-invalid', 'popover-open', 'autofill', '-webkit-autofill',
              'lang', 'dir', 'scope', 'defined', 'fullscreen'}
STATE_ATTRS = {'aria-pressed', 'aria-expanded', 'hidden', 'open', 'data-stamp', 'data-when', 'data-wear', 'checked',
               'data-preview-removed', 'aria-current', 'aria-selected', 'disabled', 'data-section'}
STATE_CLASSES = {'is-turned', 'is-tilting', 'is-entering', 'is-leaving', 'show', 'is-revealed', 'is-past'}


class Tokens(HTMLParser):
    def __init__(self):
        super().__init__()
        self.tags, self.classes, self.ids, self.attrs = set(), set(STATE_CLASSES), set(), set(STATE_ATTRS)

    def handle_starttag(self, tag, attrs):
        self.tags.add(tag)
        for k, v in attrs:
            self.attrs.add(k)
            if k == 'class' and v:
                self.classes.update(v.split())
            if k == 'id' and v:
                self.ids.add(v)
    handle_startendtag = handle_starttag


def tokens(htmls):
    t = Tokens()
    for h in htmls:
        t.feed(h)
    return t


def split_top(s, sep=','):
    parts, depth, q, cur, i = [], 0, None, '', 0
    while i < len(s):
        ch = s[i]
        if q:
            cur += ch
            if ch == '\\' and i + 1 < len(s):
                cur += s[i + 1]; i += 2; continue
            if ch == q:
                q = None
        elif ch in '"\'':
            q = ch; cur += ch
        elif ch in '([':
            depth += 1; cur += ch
        elif ch in ')]':
            depth -= 1; cur += ch
        elif ch == sep and depth == 0:
            parts.append(cur.strip()); cur = ''
        else:
            cur += ch
        i += 1
    if cur.strip():
        parts.append(cur.strip())
    return parts


def subject(sel):
    """(prefix, compound, pseudo-element) for a complex selector."""
    depth, q, last = 0, None, 0
    i = 0
    while i < len(sel):
        ch = sel[i]
        if q:
            if ch == q: q = None
        elif ch in '"\'': q = ch
        elif ch in '([': depth += 1
        elif ch in ')]': depth -= 1
        elif depth == 0 and (ch in ' >+~'):
            last = i + 1
        i += 1
    prefix, comp = sel[:last], sel[last:]
    # A pseudo-element at the top level of the compound.
    depth, pe = 0, ''
    for j in range(len(comp)):
        ch = comp[j]
        if ch in '([': depth += 1
        elif ch in ')]': depth -= 1
        elif depth == 0 and comp[j:j + 2] == '::':
            pe = comp[j:]; comp = comp[:j]; break
    return prefix, comp, pe


def strip_parens(c):
    out, depth = '', 0
    for ch in c:
        if ch == '(':
            depth += 1
        elif ch == ')':
            depth -= 1
        elif depth == 0:
            out += ch
    return out


def compound_ok(comp, T):
    c = strip_parens(comp)
    c = re.sub(r'\[[^\]]*\]', lambda m: ' ATTR' + m.group(0) + ' ', c)
    for a in re.findall(r'\[\s*([\w-]+)', comp):
        if a not in T.attrs:
            return False
    c = re.sub(r'\[[^\]]*\]', '', c)
    m = re.match(r'^\s*([a-zA-Z][\w-]*|\*)', c.replace(' ATTR', ''))
    if m and m.group(1) != '*' and m.group(1).lower() not in T.tags:
        return False
    for cl in re.findall(r'\.((?:\\.|[\w-])+)', c):
        if cl.replace('\\', '') not in T.classes:
            return False
    for i in re.findall(r'#((?:\\.|[\w-])+)', c):
        if i not in T.ids:
            return False
    return True


ROOTISH = re.compile(r'^(?:html|body|:root)(?=$|[.#\[:])')


def rewrite_tokens(sel):
    sel = re.sub(r'(?<![\w\-#.\[:])html(?![\w-])', '.mx-html', sel)
    sel = re.sub(r'(?<![\w\-#.\[:])body(?![\w-])', '.mx-body', sel)
    sel = sel.replace(':root', '.mx-html').replace(':modal', '[open]')
    # The mock's page landmarks become plain wrappers in the board.
    sel = re.sub(r'(?<![\w\-#.\[:])main(?![\w-])', '.mx-main', sel)
    sel = re.sub(r'#(main|event-list|event-featured)(?![\w-])', r'.mx-id-\1', sel)
    return sel


def compounds(prefix):
    out, depth, q, cur = [], 0, None, ''
    for ch in prefix:
        if q:
            cur += ch
            if ch == q: q = None
            continue
        if ch in '"\'':
            q = ch; cur += ch; continue
        if ch in '([': depth += 1
        elif ch in ')]': depth -= 1
        if depth == 0 and ch in ' >+~':
            if cur.strip(): out.append(cur.strip())
            cur = ''
            continue
        cur += ch
    if cur.strip(): out.append(cur.strip())
    return out


def keep_selector(sel, T, TA=None):
    if '::backdrop' in sel or '::-webkit-scrollbar' in sel and False:
        return None
    prefix, comp, pe = subject(sel)
    if not comp.strip() and not pe:
        return None
    if ROOTISH.match(comp.strip()):
        return None
    if '::backdrop' in pe:
        return None
    if not compound_ok(comp or '*', T):
        return None
    if TA is not None:
        for c in compounds(prefix):
            if not ROOTISH.match(c) and not compound_ok(c, TA):
                return None
            if ROOTISH.match(c) and not compound_ok(re.sub(r'^(html|body|:root)', '', c) or '*', TA):
                return None
    return rewrite_tokens(prefix) + rewrite_tokens(comp or '*') + ':where(.mx *)' + pe


FEAT = re.compile(r'\(\s*(min|max)-(width|height)\s*:\s*([\d.]+)px\s*\)')
RANGE = re.compile(r'\(\s*(width|height)\s*(<=|>=|<|>)\s*([\d.]+)px\s*\)')


def eval_media(text, W, H, unknown):
    out = []
    for q in split_top(text):
        ok, rest = True, []
        for part in re.split(r'\s+and\s+', q.strip()):
            p = part.strip()
            m, r = FEAT.fullmatch(p), RANGE.fullmatch(p)
            if m:
                v = float(m.group(3)); dim = W if m.group(2) == 'width' else H
                ok &= dim >= v if m.group(1) == 'min' else dim <= v
            elif r:
                v = float(r.group(3)); dim = W if r.group(1) == 'width' else H
                ok &= {'<=': dim <= v, '>=': dim >= v, '<': dim < v, '>': dim > v}[r.group(2)]
            elif p in ('screen', 'all', 'only screen'):
                pass
            elif p == 'print':
                ok = False
            else:
                if re.search(r'width|height', p):
                    unknown.add(p)
                rest.append(p)
        if ok:
            if not rest:
                return ''          # always true
            out.append(' and '.join(rest))
    return None if not out else ', '.join(out)


def build(rule_sheets, T, W, H, TA=None):
    unknown, kept_frames, fonts, kf = set(), [], [], {}

    def walk(rules, depth=0):
        out = []
        for r in rules:
            t = r['t']
            if t == 'import':
                if r.get('media'):
                    c = eval_media(r['media'], W, H, unknown)
                    if c is None: continue
                out += walk(r['rules'], depth)
            elif t == 'media':
                c = eval_media(r['cond'], W, H, unknown)
                if c is None:
                    continue
                inner = walk(r['rules'], depth + 1)
                if not inner:
                    continue
                out += inner if c == '' else [f'@media {c}{{' + ''.join(inner) + '}']
            elif t in ('supports',):
                inner = walk(r['rules'], depth + 1)
                if inner:
                    out.append(f'@supports {r["cond"]}{{' + ''.join(inner) + '}')
            elif t == 'container':
                inner = walk(r['rules'], depth + 1)
                if inner:
                    out.append(r['head'] + '{' + ''.join(inner) + '}')
            elif t == 'layer':
                inner = walk(r['rules'], depth + 1)
                if inner:
                    out.append(f'@layer {r["name"]}{{' + ''.join(inner) + '}')
            elif t == 'keyframes':
                kf[r['name']] = r['text']
            elif t == 'font':
                fonts.append(r)
            elif t == 'style':
                if r.get('nested'):
                    unknown.add('NESTED ' + r['sel'])
                sels = [keep_selector(s, T, TA) for s in split_top(r['sel'])]
                sels = [s for s in sels if s]
                if sels and r['decl'].strip():
                    out.append(', '.join(sels) + '{' + r['decl'] + '}')
        return out

    body = []
    for sh in rule_sheets:
        body += walk(sh['rules'])
    css = '\n'.join(body)
    # Keyframes the kept rules use, renamed so they can't meet the board's own.
    used = {}
    for name, text in kf.items():
        if re.search(r'animation(?:-name)?\s*:[^;{}]*(?<![\w-])' + re.escape(name) + r'(?![\w-])', css):
            used[name] = text
    for name in sorted(used, key=len, reverse=True):
        css = re.sub(r'(animation(?:-name)?\s*:[^;{}]*?)(?<![\w-])' + re.escape(name) + r'(?![\w-])',
                     lambda m: m.group(1) + 'mx-' + name, css)
    frames = '\n'.join(re.sub(r'^@keyframes\s+' + re.escape(n), '@keyframes mx-' + n, t) for n, t in used.items())
    return css + '\n' + frames, fonts, unknown


def chain_html(R):
    out = []
    for ch in (R['chains']['wall'], R['chains']['column']):
        for e in ch:
            attrs = ' '.join(f'{k}="{v}"' for k, v in e['attrs'] if k != 'style')
            out.append(f'<{e["tag"]} {attrs}>')
    return out


if __name__ == '__main__':
    import sys
    d = json.load(open(sys.argv[1]))
    for vp, (W, H) in (('desktop', (1440, 960)), ('phone', (390, 844))):
        R = d[vp]
        htmls = [v for v in R['posters'].values()] + [h for s in R['sheets'].values() for h in s.values()] + [R['toast']['html']]
        T = tokens(htmls)
        TA = tokens(htmls + chain_html(R))
        css, fonts, unknown = build(R['css'], T, W, H, TA)
        urls = sorted(set(u for u in re.findall(r'url\(\s*["\']?([^"\')]+)', css) if not u.startswith('data:')))
        print(vp, len(css), 'bytes; fonts', [re.search(r'font-family:\s*([^;]+)', f['text']).group(1) for f in fonts])
        print(' urls', urls)
        print(' unknown media', unknown)
        open(f'mx-{vp}.css', 'w').write(css)
