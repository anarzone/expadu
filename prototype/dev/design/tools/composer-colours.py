#!/usr/bin/env python3
"""Map Composer's remaining blue-grey colours onto the shared tokens.

Composer and the department Composer bar kept their own blue-grey palette after
every page moved to Travel's cream palette (6 October). This maps each
hard-coded colour by its job (see design-decisions.md, "Colour roles"):
greys and lines to the neutral tokens, selected/added states to cyan, fixed
appointments to lavender, warnings to amber, errors to coral. Photo overlays,
category illustration tiles, the loading spark and the teal Composer launcher
keep their colours.

Files shared with older previews get a fallback, so pages without tokens.css
keep their colours (shadows take the warm tint everywhere).

    python3 tools/composer-colours.py --dry-run
    python3 tools/composer-colours.py
"""
import re
import sys
from pathlib import Path

design = Path(__file__).resolve().parent.parent
dry_run = '--dry-run' in sys.argv

own = [
    'composer-studio/style.css',
    'composer-studio/conversation.css',
    'composer-studio/review-features.css',
    'composer-studio/place-ideas.css',
]
shared = [
    'today-c/composer-controls.css',
    'connected/composer-entry.css',
    'connected/location-popover.css',
    'connected/journey-session.css',
    'connected/time-control.css',
    'connected/date-control.css',
    'connected/loading.css',
    'bureaucracy-next/situation.css',
]

# Selectors whose colours are illustration, photography or brand: unchanged.
keep = re.compile(
    r'discovery-photo|photo-fallback|place-category|studio-bookmark:before|'
    r'companion-art|replacement-media|header-saved-count|spark|is-thinking|'
    r'composer-launcher|#composer-tray:not\(\.is-active\)'
)

T = lambda name: f'--color-{name}'
groups = {
    'text': '#294f59 #213b42 #33535e #243d46 #344f59 #234f5b #294c56 #253e47 '
    '#263c45 #243c45 #345c65 #345d6c #365f6a #396570 #356b78 #416d79 #416773 '
    '#326d7c #356774 #3f6a77 #4d7681 #286777 #245969 #386a78 #211d15 #486974 '
    '#456570',
    'text-soft': '#57717a #4d747e #51676e #5a727b #597985 #4c7380 #627f88 '
    '#667c84 #65808a #687f86 #60777d #75858a #506a74 #687e85 #819399 #52717b '
    '#5d6c70 #879ba1 #6b858c #60747b #607b83 #526b73 #657e87 #647b83 #6a8088 '
    '#526c76 #4d6873 #607a84 #58717b #647c85 #72838a #73858b #71858c #6b8088 '
    '#71838b #7a8c93 #536a71 #5d7278 #61747a #637980 #667a80 #687a80 #697e85 '
    '#70848a #718389 #75878d #5d767f #5d747b #61777e #58717a #5d767e #64808a '
    '#647a82 #68808a #71848a #426871',
    'select-text': '#25768b #277589 #2c7890 #327389 #3c7e8d #4d8290 #08778d '
    '#11b6d1 #477b89 #397360 #376e59 #397553 #35825d #347255 #31796e #417665 '
    '#235d6d #285e6d',
    'bg-surface': '#fff #ffffff #fbfdfd',
    'bg-canvas': '#f6fafb',
    'bg-quiet': '#f2f8f9 #f1f7f8 #f2f7f8 #f4f9fa #f1f6f7 #edf4f6 #e6f1f4 '
    '#eaf3f5 #e9f2f4 #e8f2f4 #f0f6f7 #edf5f7 #edf6f8 #326b7b0d #e9f3f5 '
    '#e4eef0 #e3ebee #e7f2f5 #def4fa #dceef2',
    'select-bg': '#e0f3f7 #e0f0f4 #e1eff2 #d4e9ed #e6f2f5 #edf9fc #e7f2ef '
    '#edf7f4 #e3f0e7 #edf6f4 #e5f1ed #e1f4f8 #e5f5f8 #dff4f8',
    'select-bg-hover': '#d7eaf0 #cee5eb #cbeaf1',
    'border': '#dce7e9 #d8e5e7 #d3e2e6 #c5d7d8 #cbdde1 #e4ecee #dbe6e8 '
    '#d6e3e7 #dbe7eb #e7eef0 #d5e5e9 #d8e5e8 #dce6e9 #c9dfe5 #dce8eb80 '
    '#d6e5e9 #e5edef #dce7ea #e0eaed #d6e4e8 #d8e6e9 #edf2f3 #d8e3e5 #bcd7df',
    'border-strong': '#cbdde2 #c9dade #89b4ba #9bbfc9 #aec8d0 #9aaeb5',
    'nav-violet': '#8767ad',
    'paper-bg-quiet': '#f1edf7 #f0ebf7',
    'paper-text': '#785da3 #7a6592 #655385',
    'attention-bg': '#fff2e1 #fff0d6',
    'attention-text': '#865515 #724513',
    'conflict-text': '#a13b24 #994521',
    'conflict-bg': '#fff3ea',
    'action': '#d9340a',
    'action-hover': '#bf2d08',
    'focus': '#397988 #49869a #50b4cc #64a5b5 #3997b0 #5a9cae #79bdcc #32697b '
    '#05badd',
}
table = {h: T(name) for name, hexes in groups.items() for h in hexes.split()}

# Specific jobs that the hex alone does not tell (selector regex, property
# regex, colour) → token. First match wins.
rules = [
    (r'#approval-mode:is\(:hover', r'^color$', None, T('text')),
    (r"today-approval \[aria-checked='true'\]", r'background', None, T('select-bg')),
    # Tools in the input row and toolbar stay quiet; menu items are content.
    (r'composer-minimize|#approval-mode|composer-actions > summary|'
     r'today-composer-tools summary|#included-ideas|composer-tools button|'
     r'conversation-tools button$', r'^color$', None, T('text-soft')),
    (r'composer-dismiss', r'^color$', '#627f88', T('text-soft')),
    # Answers are content, not supporting copy.
    (r'simple-answer > p', r'^color$', None, T('text')),
    # Selected states use cyan.
    (r"history-section\[aria-current", r'background', None, T('select-bg')),
    (r'studio-button\.light', r'background', '#e7f2f5', T('select-bg')),
    (r"days button\[aria-pressed='true'\]", r'background', None, T('select-bg')),
    (r"days button\[aria-pressed='true'\]", r'^color$', '#fff', T('select-text')),
    (r'replacement-check', r'background', None, T('select-text')),
    # Text on orange buttons.
    (r'send|compose-button', r'^color$', '#fff', T('text-on-action')),
    # Composer's toast matches Travel's: light surface, ink text.
    (r'^#studio-toast$', r'background', None, T('bg-surface')),
    (r'^#studio-toast$', r'^color$', None, T('text')),
    (r'^#studio-toast svg$', r'^color$', None, T('select-text')),
    (r'#studio-toast (button|a)', r'^color$', None, T('action-text')),
    # The Composer name is content; its spark keeps the Composer mark colour.
    (r'^\.workspace-name$', r'^color$', None, T('text')),
]
warm_shadow = '#3e352b'


def token(name, old, fallback):
    return f'var({name}, {old})' if fallback else f'var({name})'


def map_colour(sel, prop, hexval, fallback):
    low = hexval.lower()
    for sel_re, prop_re, colour, name in rules:
        if re.search(sel_re, sel) and re.search(prop_re, prop) and (
            colour is None or colour == low
        ):
            return token(name, hexval, fallback)
    if low in table:
        return token(table[low], hexval, fallback)
    return None


def map_shadow(sel, value, fallback):
    def one(m):
        hexval = m.group(0)
        before = value[: m.start()]
        if re.search(r'var\([^()]*$', before):
            return hexval
        part = before.split(',')[-1]
        if re.search(r'inset\s+0 0 0 1px\s*$', part):
            return token(T('select-text'), hexval, fallback)
        ring = re.search(r'(?<!inset )\b0 0 0 (\d+)px\s*$', part)
        if ring:
            name = T('focus') if int(ring.group(1)) >= 2 else T('border-strong')
            return token(name, hexval, fallback)
        alpha = hexval[7:9] if len(hexval) == 9 else ''
        return warm_shadow + alpha
    return re.sub(r'#[0-9a-fA-F]{6,8}\b', one, value)


report, unmapped = [], []
decl = re.compile(r'([a-z-]+)(\s*:\s*)([^;{}]*#[0-9a-fA-F]{3,8}\b[^;{}]*);')


def rewrite(css, file, fallback):
    def repl(m):
        prop, colon, value = m.group(1), m.group(2), m.group(3)
        if prop.startswith('--') or prop in ('mask', '-webkit-mask', 'stroke', 'fill'):
            return m.group(0)
        open_ = css.rfind('{', 0, m.start())
        start = max(css.rfind('}', 0, open_), css.rfind(';', 0, open_)) + 1
        sel = ' '.join(re.sub(r'/\*.*?\*/', '', css[start:open_], flags=re.S).split())
        sel = sel.replace(':is(.today-c, .department-composer) ', '')
        if keep.search(sel) or 'gradient' in value:
            return m.group(0)
        if prop == 'box-shadow':
            new = map_shadow(sel, value, fallback)
        else:
            # Leave fallbacks inside var(…) alone; map bare colours.
            def one(c):
                if re.search(r'var\([^()]*$', value[: c.start()]):
                    return c.group(0)
                if prop == 'scrollbar-color':
                    return token(T('border-strong'), c.group(0), fallback)
                if prop.startswith('outline'):
                    return token(T('focus'), c.group(0), fallback)
                if '::backdrop' in sel:
                    return '#211d15' + c.group(0)[7:9]
                mapped = map_colour(sel, prop, c.group(0), fallback)
                if mapped is None:
                    unmapped.append(f'{file}: {sel} {{ {prop}: {c.group(0)} }}')
                    return c.group(0)
                return mapped
            new = re.sub(r'#[0-9a-fA-F]{3,8}\b', one, value)
        if new != value:
            line = css.count('\n', 0, m.start()) + 1
            report.append(f'{file}:{line}  {sel[:48]}  {prop}: {value.strip()} → {new.strip()}')
        return f'{prop}{colon}{new};'
    return decl.sub(repl, css)


for file in own + shared:
    path = design / file
    css = path.read_text()
    new = rewrite(css, file, file in shared)
    if not dry_run and new != css:
        path.write_text(new)

print('\n'.join(report))
if unmapped:
    print('\nLeft as they are (no role match):\n  ' + '\n  '.join(unmapped))
print(f"\n{'dry run' if dry_run else 'applied'} · {len(report)} declarations")
