"""Generate resources/css/composer-page.css from the Composer mock (composer-studio).

Every rule is nested under .composer-page (the page's root element), so the port cannot
reach other pages. Selector weight: each mock selector gains one class from the scope;
the mock's body classes become the root's own classes plus that same scope class
(body.studio / .studio -> .composer-page.composer-page, body.has-x -> .composer-page.composer-page.has-x),
so the mock's relative weights hold. #main and the other ids stay ids.

usage: python3 tools/composer-css/port.py . && npx prettier --write resources/css/composer-page.css
"""
import re
import sys

repo = sys.argv[1]
mock = f'{repo}/prototype/dev/design'

# The mock's cascade (composer-studio/index.html), limited to what the page draws itself.
# The shell's header and menu (shell-review, today-header, section-bar, composer-entry,
# menu-colors) are the app shell's (session 1); Travel's line badges and ways are Travel's.
SOURCES = [
    'connected/style.css',
    'composer-studio/style.css',
    'composer-studio/review-features.css',
    'composer-studio/time-sheet.css',
    'composer-studio/conversation.css',
    'connected/date-control.css',
    'composer-studio/place-ideas.css',
    'connected/today-palette.css',
    'composer-studio/approved.css',
]

lifted = []  # @keyframes and @font-face, which cannot nest


def lift(css: str) -> str:
    def keep(match):
        block = match.group(0).strip()
        if block.startswith('@font-face'):
            if 'Bricolage' in block or 'Geist' in block:
                return ''  # resources/css/app.css defines it
            # The app's one Permanent Marker, woff2 first.
            block = block.replace(
                "url('../events-next/fonts/PermanentMarker-Regular.ttf') format('truetype')",
                "url('/fonts/permanent-marker-400.woff2') format('woff2'),\n"
                "        url('/fonts/permanent-marker-400.ttf') format('truetype')",
            )
        lifted.append(block)
        return ''

    css = re.sub(r'@keyframes [\w-]+ \{(?:[^{}]*\{[^{}]*\})*[^{}]*\}', keep, css)
    return re.sub(r'@font-face \{[^{}]*\}', keep, css)


def selectors(css: str) -> str:
    """Rewrite selector preludes only (text before a `{` that isn't an at-rule)."""
    out, i = [], 0
    for m in re.finditer(r'([^{};]*)\{', css):
        prelude = m.group(1)
        out.append(css[i:m.start()])
        stripped = prelude.lstrip()
        if stripped.startswith('@') or not stripped.strip():
            out.append(prelude + '{')
        else:
            out.append(rewrite(prelude) + '{')
        i = m.end()
    out.append(css[i:])
    return ''.join(out)


def rewrite(prelude: str) -> str:
    # Comments inside a prelude stay as they are.
    parts = re.split(r'(/\*.*?\*/)', prelude, flags=re.S)
    for k, part in enumerate(parts):
        if part.startswith('/*'):
            continue
        p = part
        p = p.replace('html:has(body.has-panel)', 'html:has(&.composer-page.has-panel)')
        p = re.sub(r':root\.keyboard-open\s+', ':root.keyboard-open & ', p)
        p = re.sub(r'(?<![\w.#-]):root(?![\w-])', '&', p)
        # The mock's body classes are the root's: body.studio, body.today-palette and
        # :where(.today-palette) all mean the page itself.
        p = re.sub(r'(?<![\w.#-])body\.(studio|today-palette|today-header|shell-review)(?![\w-])', '&.composer-page', p)
        p = re.sub(r':where\(\.today-palette\)', '&', p)
        # State the mock keeps on <body> but writes without it (.has-panel .x): the root's.
        p = re.sub(r'(?<![\w.#&(-])\.(has-panel|has-replacement|has-composer-day)(?![\w-])', r'&.composer-page.\1', p)
        p = re.sub(r'(?<![\w.#-])body(?=[.:\[])', '&.composer-page', p)
        p = re.sub(r'(?<![\w.#-])body(?![\w-])', '&.composer-page', p)
        # A body class after the root (&.studio, page.today-palette) is the root again;
        # on its own (.studio .x) it starts at the root.
        p = re.sub(r'(?<=[&e])\.(studio|today-palette|today-header|shell-review)(?![\w-])', '.composer-page', p)
        p = re.sub(r'(?<![\w-])\.(studio|today-palette|today-header|shell-review)(?![\w-])', '&.composer-page', p)
        p = p.replace('&&', '&').replace('&.composer-page&.composer-page', '&.composer-page')
        parts[k] = p
    return ''.join(parts)


def no_sample_bar(css: str) -> str:
    """The mock labels its sample conversation with a bar (.chat-bar, "Sample answers from the
    sample day"). A person's own conversation needs no such label, so the app has no bar and its
    feed always sits as the mock's does with the bar hidden: straight after the day row."""
    css = css.replace('.chat-bar[hidden] + .chat-feed', '.composer-day-row + .chat-feed')
    css = css.replace('.chat-bar button, ', '')
    return re.sub(r'(?m)^[ \t]*[^{};@\n]*\.chat-bar[^{};]*\{[^{}]*\}\n?', '', css)


def indent(css: str) -> str:
    return '\n'.join(('    ' + line) if line.strip() else '' for line in css.split('\n'))


parts = []
for name in SOURCES:
    css = open(f'{mock}/{name}').read()
    css = re.sub(r"@import url\('[^']*'\);\n?", '', css)
    if name == 'connected/style.css':
        # The base svg rule (0,0,1) must stay below Travel's own badge rules (.tl-line svg)
        # once scoped, as it is in the mock: keep it weightless.
        css = re.sub(r'(?m)^svg \{', ':where(svg) {', css)
    css = lift(css)
    css = no_sample_bar(css)
    css = selectors(css)
    parts.append(f'    /* ── {name} ── */\n{indent(css)}')

# connected/section-bar.css: Composer's text box sits on the phone menu (Composer review,
# board 1, 18 Oct 2026). The mock keys these on the header's menu existing
# (:root body:has(#shell .service-nav)); the app's shell always draws it, so they key on
# the page's root id, which keeps their weight above the ported rules.
menu = """
    /* ── connected/section-bar.css: the text box on the phone menu ── */
    @media (max-width: 700px) {
        &#composer-page .studio-input-wrap {
            padding-inline: 14px;
            padding-bottom: calc(var(--menu-gap) + var(--menu-height) + 8px);
            transition: translate var(--duration-base, 180ms) ease;
        }
        &#composer-page:has(.studio-input-wrap) #main {
            padding-bottom: calc(
                var(--studio-input-height, 190px) - var(--section-bar) + 36px
            );
        }
        body.menu-away &#composer-page .studio-input-wrap {
            translate: 0 calc(var(--menu-height) + 8px);
        }
        :root.keyboard-open &#composer-page .studio-input-wrap {
            padding-bottom: max(10px, env(safe-area-inset-bottom));
            translate: none;
        }
        /* The app's phone floor (16px !important, so iOS doesn't zoom in) would shrink
           the mock's 17px box; 17px is above that floor, so it stays. */
        &#composer-page #studio-request {
            font-size: var(--text-section) !important;
        }
    }
"""

# Travel's way (resources/css/travel.css) carries Travel's pages' type, 14px / 1.45 from
# their body. The Composer mock's way.css sets none, so in an answer the way reads the
# page's own, as the mock draws it.
travel = """
    /* ── Travel's way in an answer: the page's type, as the mock's way.css leaves it ── */
    & .answer-way .nav-way {
        font-size: inherit;
        line-height: inherit;
    }
"""

head = """/* Composer — ported from the approved prototype (prototype/dev/design/composer-studio)
   by tools/composer-css/port.py, in the mock's own order, scoped to .composer-page so it
   cannot reach other pages. Tokens come from resources/css/app.css (F1); the shell (header,
   menu) is the app shell's. Regenerate rather than edit the ported part by hand. */

/* The prototype leaves some things to the browser's defaults, which the app's base reset
   changes: a button's padding (1px 6px), an icon's flow (inline, on the baseline) and a
   border's style and colour (none, and the text's colour, until a rule sets them) and a
   heading's bold. Give them back in the base layer: every rule below and every utility
   class still wins. */
@layer base {
    .composer-page button {
        padding: 1px 6px;
    }
    .composer-page :is(svg, img) {
        display: inline;
        vertical-align: baseline;
    }
    .composer-page *,
    .composer-page ::before,
    .composer-page ::after {
        border-style: none;
        border-color: currentColor;
    }
    .composer-page :is(h1, h2, h3, h4, h5, h6) {
        font-weight: bold;
    }
}

.composer-page {
"""

out = head + '\n\n'.join(parts).rstrip() + '\n' + menu + travel + '}\n\n' + '\n\n'.join(dict.fromkeys(lifted)) + '\n'
open(f'{repo}/resources/css/composer-page.css', 'w').write(out)
print('lines', out.count('\n'), 'lifted', len(lifted))
