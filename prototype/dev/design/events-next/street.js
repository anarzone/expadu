/* The Events street (owner, 12 Oct 2026): the Stories board from the
   "Events reimagined" canvas, as it is. street-art.js holds the board's own
   templates and logic; this file runs them on the Events page: a small
   renderer that patches the page in place (the street's animations keep
   running), routes the board's taps and drags, and fills its poster slots
   with the page's own posters (poster-art.js). Filters, the details sheet,
   saving and planning stay the page's own (app.js, wall.js). */
(function () {
    const ART = window.ExpaduStreetArt;
    if (!ART) return;
    const esc = (s) =>
        String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const escAttr = (s) => esc(s).replace(/"/g, '&quot;');
    const BOOLEAN = new Set(['hidden', 'checked', 'disabled', 'open', 'required', 'readonly', 'selected']);
    const attr = (name, value) => {
        if (value === undefined || value === null || value === false) return '';
        if (value === true && BOOLEAN.has(name)) return ` ${name}=""`;
        return ` ${name}="${escAttr(value)}"`;
    };

    // ---------- Patching ----------
    // The new markup is laid over the old one node by node, so elements,
    // and the animations running on them, stay. A drawn part that never
    // changes (data-s) is filled once and left alone; a poster (data-key)
    // keeps what the page did to it (turned, lifted, saved, its flags).
    const fill = (node) => {
        if (node.nodeType !== 1) return;
        const parts = node.matches('[data-s]') ? [node] : [];
        parts.push(...node.querySelectorAll('[data-s]'));
        for (const el of parts)
            if (!el.firstChild) el.innerHTML = ART.statics[el.dataset.s];
    };
    function syncAttrs(from, to) {
        for (const { name } of [...from.attributes])
            if (!to.hasAttribute(name)) from.removeAttribute(name);
        for (const { name, value } of [...to.attributes])
            if (from.getAttribute(name) !== value) from.setAttribute(name, value);
    }
    function morph(from, to) {
        if (from.nodeType !== to.nodeType || from.nodeName !== to.nodeName) {
            from.replaceWith(to);
            fill(to);
            return;
        }
        if (from.nodeType !== 1) {
            if (from.nodeValue !== to.nodeValue) from.nodeValue = to.nodeValue;
            return;
        }
        if (to.dataset.key && from.dataset.key === to.dataset.key) {
            // The page's own poster: only its place and size follow.
            const style = to.getAttribute('style') || '';
            if (from.dataset.style !== style) {
                for (const decl of style.split(';')) {
                    const at = decl.indexOf(':');
                    if (at > 0) from.style.setProperty(decl.slice(0, at).trim(), decl.slice(at + 1).trim());
                }
                from.dataset.style = style;
            }
            return;
        }
        if (to.dataset.s && from.dataset.s === to.dataset.s) return;
        syncAttrs(from, to);
        if (to.dataset.s) {
            from.innerHTML = '';
            fill(from);
            return;
        }
        const a = [...from.childNodes],
            b = [...to.childNodes];
        b.forEach((node, i) => {
            if (i < a.length) morph(a[i], node);
            else {
                from.appendChild(node);
                fill(node);
            }
        });
        for (let i = b.length; i < a.length; i++) a[i].remove();
    }
    function patch(host, html) {
        const t = document.createElement('template');
        t.innerHTML = html;
        if (!host.firstChild) {
            host.append(t.content);
            fill(host);
            return;
        }
        const a = [...host.childNodes],
            b = [...t.content.childNodes];
        b.forEach((node, i) => {
            if (i < a.length) morph(a[i], node);
            else {
                host.appendChild(node);
                fill(node);
            }
        });
        for (let i = b.length; i < a.length; i++) a[i].remove();
    }

    // ---------- Big moving groups as one picture ----------
    // A moving group of many shapes (the rain's 1,275 lines, the tree
    // crown's 1,500 leaves, the clouds) makes Chrome re-sort the street's
    // layers on every frame the page draws: about 100ms a frame on a
    // desktop, so taps, swipes and stories stutter. Drawn as one picture of
    // exactly the same shapes, with the styles they had on the page, it
    // costs next to nothing. The group itself and its motion stay as they
    // are; only fixed parts of the drawing (data-s) are pictured.
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const INHERITED = [
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity',
        'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset',
        'stroke-miterlimit', 'paint-order', 'visibility', 'color', 'shape-rendering',
    ];
    const OWN = {
        opacity: '1', filter: 'none', 'clip-path': 'none', mask: 'none', 'mix-blend-mode': 'normal',
        display: 'inline', 'stop-color': 'rgb(0, 0, 0)', 'stop-opacity': '1', 'vector-effect': 'none',
    };
    const PAINT_ATTRS = [...INHERITED, ...Object.keys(OWN)];
    const HANDLERS = '[data-on-click],[data-on-pointerdown],[data-on-pointerenter],[data-on-dblclick]';
    const pictures = new Map();
    let moving = null;
    // The classes the street animates, read from its own rules.
    function movingSelector() {
        if (moving !== null) return moving;
        const names = new Set();
        const walk = (rules) => {
            for (const rule of rules) {
                if (rule.cssRules && !rule.style) walk(rule.cssRules);
                else if (rule.style?.animationName && rule.style.animationName !== 'none')
                    for (const sel of rule.selectorText.split(',')) {
                        const m = sel.trim().match(/^\.st[dp] \.([\w-]+)$/);
                        if (m) names.add(m[1]);
                    }
            }
        };
        for (const sheet of document.styleSheets)
            if (/street-art\.css/.test(sheet.href || ''))
                try {
                    walk(sheet.cssRules);
                } catch {}
        moving = [...names].map((n) => '.' + CSS.escape(n)).join(',');
        return moving;
    }
    const localUrl = (value, ids) =>
        value.replace(/url\((["']?)[^#"')]*#([^"')]+)\1\)/g, (m, q, id) => (ids.add(id), `url(#${id})`));
    // Reading 1,500 shapes' styles takes a while on a phone: a slice at a
    // time, so taps and the page's own work go in between.
    const pause = () => new Promise((resolve) => setTimeout(resolve, 0));
    async function picture(g, key) {
        const ids = new Set();
        const src = [g, ...g.getElementsByTagName('*')];
        const values = new Map();
        let widest = 0,
            filtered = false;
        const styles = [];
        let slice = performance.now();
        for (const [i, el] of src.entries()) {
            if (performance.now() - slice > 50) {
                await pause();
                if (!g.isConnected) return null;
                slice = performance.now();
            }
            styles.push(styleOf(el, i));
        }
        function styleOf(el, i) {
            const cs = getComputedStyle(el);
            const own = {};
            for (const p of INHERITED) own[p] = localUrl(cs.getPropertyValue(p), ids);
            values.set(el, own);
            widest = Math.max(widest, parseFloat(own['stroke-width']) || 0);
            if (i === 0) return INHERITED.map((p) => `${p}:${own[p]}`).join(';');
            const parent = values.get(el.parentNode);
            let style = '';
            for (const p of INHERITED) if (own[p] !== parent[p]) style += `${p}:${own[p]};`;
            for (const [p, initial] of Object.entries(OWN)) {
                const v = localUrl(cs.getPropertyValue(p), ids);
                if (v && v !== initial) style += `${p}:${v};`;
                if (p === 'filter' && v !== initial) filtered = true;
            }
            if (!el.hasAttribute('transform') && cs.transform !== 'none')
                style += `transform:${cs.transform};transform-origin:${cs.transformOrigin};transform-box:${cs.transformBox};`;
            return style;
        }
        // The group changed while it was read: try again next time.
        if (g.getElementsByTagName('*').length !== src.length - 1 || !src.every((el) => g.contains(el))) return null;
        const copy = g.cloneNode(true);
        [copy, ...copy.getElementsByTagName('*')].forEach((el, i) => {
            for (const a of PAINT_ATTRS) el.removeAttribute(a);
            for (const a of ['class', 'style', 'aria-hidden']) el.removeAttribute(a);
            if (i === 0) el.removeAttribute('transform');
            if (styles[i]) el.setAttribute('style', styles[i]);
        });
        const defs = document.createElementNS(SVG_NS, 'defs');
        const seen = new Set();
        const define = (id) => {
            if (seen.has(id)) return;
            seen.add(id);
            const def = document.getElementById(id);
            if (!def) return;
            defs.append(def.cloneNode(true));
            for (const el of [def, ...def.getElementsByTagName('*')]) {
                const href = el.getAttribute('href') || el.getAttribute('xlink:href');
                if (href?.startsWith('#')) define(href.slice(1));
                for (const { value } of el.attributes) for (const m of value.matchAll(/url\(#([^)]+)\)/g)) define(m[1]);
            }
        };
        ids.forEach(define);
        const bb = g.getBBox();
        const pad = Math.ceil(widest) + (filtered ? 48 : 6);
        const box = [bb.x - pad, bb.y - pad, bb.width + pad * 2, bb.height + pad * 2].map((n) => +n.toFixed(2));
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('xmlns', SVG_NS);
        svg.setAttribute('viewBox', box.join(' '));
        svg.setAttribute('width', box[2]);
        svg.setAttribute('height', box[3]);
        svg.append(defs, copy);
        const url = URL.createObjectURL(
            new Blob([new XMLSerializer().serializeToString(svg)], { type: 'image/svg+xml' }),
        );
        const made = { url, box };
        pictures.set(key, made);
        return made;
    }
    // ---------- Touch targets ----------
    // The street is drawn at the board's size and scaled to the screen, and
    // the column's posters are scaled again, so its small buttons (a
    // poster's Save and Plan, the arrows, the dots) can end up 12–37px on a
    // phone. Each gets an invisible area of at least 44px on screen round it
    // (--hit-w/--hit-h, wall.css), no wider than the gap to its neighbour.
    function sizeHits(card) {
        const buttons = [...card.querySelectorAll('button:not(.pp-open)')].filter(
            (b) => b.offsetWidth && b.getClientRects().length && !b.closest('[inert], [aria-hidden="true"]'),
        );
        const rects = new Map(buttons.map((b) => [b, b.getBoundingClientRect()]));
        for (const b of buttons) {
            const r = rects.get(b);
            const kx = r.width / b.offsetWidth,
                ky = r.height / b.offsetHeight;
            // A column face turned to the side keeps its own size.
            if (!kx || !ky || Math.abs(kx / ky - 1) > 0.15 || (r.width >= 46 && r.height >= 46)) {
                for (const v of ['--hit-w', '--hit-h', '--hit-x', '--hit-y']) b.style.removeProperty(v);
                continue;
            }
            // 46: a hair over 44, for the posters' slight tilt.
            let w = 46,
                h = 46;
            for (const o of buttons) {
                if (o === b || o.parentNode !== b.parentNode) continue;
                const q = rects.get(o);
                const dx = Math.abs(q.left + q.width / 2 - (r.left + r.width / 2)),
                    dy = Math.abs(q.top + q.height / 2 - (r.top + r.height / 2));
                if (q.top < r.bottom && r.top < q.bottom) w = Math.min(w, dx);
                if (q.left < r.right && r.left < q.right) h = Math.min(h, dy);
            }
            w = Math.max(r.width, w);
            h = Math.max(r.height, h);
            // On a poster, the area stays on the poster (its edge clips it).
            let dx = 0,
                dy = 0;
            const pp = b.closest('.pp')?.getBoundingClientRect();
            if (pp && pp.width >= w && pp.height >= h) {
                const cx = r.left + r.width / 2,
                    cy = r.top + r.height / 2;
                dx = Math.max(pp.left - (cx - w / 2), Math.min(0, pp.right - (cx + w / 2)));
                dy = Math.max(pp.top - (cy - h / 2), Math.min(0, pp.bottom - (cy + h / 2)));
            }
            if (getComputedStyle(b).position === 'static') b.style.position = 'relative';
            b.style.setProperty('--hit-w', `${(w / kx).toFixed(1)}px`);
            b.style.setProperty('--hit-h', `${(h / ky).toFixed(1)}px`);
            b.style.setProperty('--hit-x', `${(dx / kx).toFixed(1)}px`);
            b.style.setProperty('--hit-y', `${(dy / ky).toFixed(1)}px`);
        }
    }

    function flattener(card) {
        let queued = false,
            running = false,
            again = false;
        const later = window.requestIdleCallback || ((fn) => setTimeout(fn, 200));
        // What the group's shapes inherit from around it; a new hour can change it.
        const keyOf = (g, i) => {
            const cs = getComputedStyle(g);
            return `${g.closest('[data-s]').dataset.s}:${i}:${INHERITED.map((p) => cs.getPropertyValue(p)).join('|')}:${g.getAttribute('class')}`;
        };
        async function run() {
            queued = false;
            running = true;
            try {
                await pass();
            } finally {
                running = false;
                if (again) {
                    again = false;
                    soon();
                }
            }
        }
        let first = true;
        function soon() {
            if (running) again = true;
            else if (!queued) {
                queued = true;
                // Straight after the first drawing (until then every frame
                // is slow), then whenever the page is idle.
                if (first) setTimeout(run, 0);
                else later(run, { timeout: 1500 });
                first = false;
            }
        }
        async function pass() {
            const sel = movingSelector();
            if (!card.isConnected) return;
            sizeHits(card);
            if (!sel) return;
            const groups = [...card.querySelectorAll('g[data-s][class], [data-s] g[class]')].filter((g) => g.matches(sel));
            for (const [i, g] of groups.entries()) {
                if (!g.isConnected) continue;
                const pictured = !!g.__shapes;
                if (!pictured && (g.getElementsByTagName('*').length < 20 || g.querySelector(sel) || g.querySelector('text') || g.querySelector(HANDLERS)))
                    continue;
                const key = keyOf(g, i);
                if (g.__key === key) continue;
                if (pictured) g.replaceChildren(...g.__shapes);
                const made = pictures.get(key) || (await picture(g, key));
                if (!made || !g.isConnected || g.__key === key) continue;
                const { url, box } = made;
                const pre = new Image();
                pre.src = url;
                try {
                    await pre.decode();
                } catch {
                    continue;
                }
                if (!g.isConnected) continue;
                const img = document.createElementNS(SVG_NS, 'image');
                ['x', 'y', 'width', 'height'].forEach((a, k) => img.setAttribute(a, box[k]));
                img.setAttribute('preserveAspectRatio', 'none');
                img.setAttribute('href', url);
                g.__shapes = [...g.childNodes];
                g.__key = key;
                g.replaceChildren(img);
                // One group per pause, so the page stays responsive.
                await new Promise((resolve) => setTimeout(resolve, 0));
            }
        }
        return { soon };
    }

    // ---------- The board's component ----------
    class Base {
        constructor(root, layout, opts) {
            this.root = root;
            this.layout = layout;
            this.opts = opts;
            this.__cfg = layout.cfg;
            this.props = {};
            this.state = undefined;
            this.handlers = [];
            this.zoom = 1;
        }
        setState(patchState) {
            const prev = this.state || {};
            this.state = Object.assign(
                {},
                prev,
                typeof patchState === 'function' ? patchState(prev) : patchState,
            );
            if (this.__alive && !this.__queued) {
                this.__queued = true;
                requestAnimationFrame(() => {
                    this.__queued = false;
                    if (this.__alive) this.draw();
                });
            }
        }
        draw() {
            // Nothing to draw until the page has given the day's events.
            if (!this.__catalogue) return;
            const v = this.renderVals();
            const handlers = [];
            const h = {
                t: (x) => (x === undefined || x === null || x === false ? '' : esc(x)),
                e: (x) => (x === undefined || x === null || x === false ? '' : escAttr(x)),
                a: attr,
                on: (fn) => (typeof fn === 'function' ? String(handlers.push(fn) - 1) : ''),
                s: () => '',
                poster: (p, where) => this.poster(p, where),
            };
            const { rings, card, story } = this.parts;
            patch(rings, this.layout.rings(v, h));
            patch(card, this.layout.card(v, h));
            patch(story, this.layout.story(v, h));
            this.handlers = handlers;
            const keys = [...this.root.querySelectorAll('.pp[data-key]')]
                .map((el) => el.dataset.key)
                .join('|');
            if (keys !== this.__keys) {
                this.__keys = keys;
                this.opts.onPosters?.();
            }
            this.flat?.soon();
        }
        poster(p, where) {
            if (!p || !p.id) return '';
            const item = (this.__catalogue || []).find((x) => x.id === p.id);
            const html = item ? this.opts.poster(item, where) : '';
            return html.replace(
                '<article ',
                `<article data-key="${escAttr(p.mxKey || where + ':' + p.id)}" data-style="${escAttr(p.mxStyle || '')}" style="${escAttr(p.mxStyle || '')}" `,
            );
        }
        // Handlers get the element they're on as currentTarget; inside the
        // zoomed card, pointer positions are in the card's own pixels.
        dispatch(type, event) {
            let stopped = false;
            for (let el = event.target; el && el !== this.root.parentNode; el = el.parentElement) {
                const id = el.getAttribute?.('data-on-' + type);
                const fn = id ? this.handlers[id] : null;
                if (fn) {
                    fn(this.wrap(event, el, () => (stopped = true)));
                    if (stopped) break;
                }
            }
        }
        enterLeave(type, event) {
            for (let el = event.target; el && el !== this.root.parentNode; el = el.parentElement) {
                const id = el.getAttribute?.('data-on-' + type);
                const fn = id ? this.handlers[id] : null;
                if (fn && !(event.relatedTarget && el.contains(event.relatedTarget)))
                    fn(this.wrap(event, el));
            }
        }
        wrap(event, el, stop) {
            const scale = this.parts.card.contains(el) ? this.zoom : 1;
            return new Proxy(event, {
                get(target, key) {
                    if (key === 'currentTarget') return el;
                    if (key === 'clientX' || key === 'clientY') return target[key] / scale;
                    if (key === 'stopPropagation')
                        return () => {
                            stop?.();
                            target.stopPropagation();
                        };
                    const value = Reflect.get(target, key);
                    return typeof value === 'function' ? value.bind(target) : value;
                },
            });
        }
    }

    function mount(host, opts) {
        let street = null;
        let data = null;
        let layoutName = '';
        // The page's own breakpoint, so the street and the posters' styles
        // agree on what a phone is.
        const pick = () => (matchMedia('(max-width: 700px)').matches ? 'phone' : 'desktop');
        function build() {
            street?.destroy();
            layoutName = pick();
            const layout = ART[layoutName];
            const Logic = ART.logic(Base);
            host.innerHTML = `<div class="st-rings"></div><div class="st-fit"><div class="st-card"></div></div>${layoutName === 'phone' ? '<p class="st-hint">Swipe the posters up for later, down for earlier</p>' : ''}<div class="st-story"></div>`;
            host.dataset.layout = layoutName;
            const s = new Logic(host, layout, opts);
            s.parts = {
                rings: host.querySelector('.st-rings'),
                card: host.querySelector('.st-card'),
                story: host.querySelector('.st-story'),
            };
            s.flat = flattener(s.parts.card);
            const types = ['click', 'dblclick', 'pointerdown', 'pointermove', 'pointerup', 'pointercancel', 'wheel', 'keydown'];
            const listeners = types.map((type) => [type, (event) => s.dispatch(type, event)]);
            listeners.push(['pointerover', (event) => s.enterLeave('pointerenter', event)]);
            listeners.push(['pointerout', (event) => s.enterLeave('pointerleave', event)]);
            for (const [type, fn] of listeners)
                host.addEventListener(type, fn, type === 'wheel' ? { passive: false } : undefined);
            const saved = () => {
                const S = window.ExpaduStore,
                    H = window.ExpaduHandoffs;
                if (!S || !H) return {};
                const keys = new Set((S.read().savedIdeas || []).map((x) => H.key(x)));
                const out = {};
                (s.__catalogue || []).forEach((x, i) => {
                    if (keys.has(H.key(x))) out[i + 1] = true;
                });
                return out;
            };
            const onChange = () => s.setState({ saved: saved() });
            window.addEventListener('expadu:change', onChange);
            window.addEventListener('storage', onChange);
            s.destroy = () => {
                s.__alive = false;
                s.componentWillUnmount?.();
                for (const [type, fn] of listeners) host.removeEventListener(type, fn);
                window.removeEventListener('expadu:change', onChange);
                window.removeEventListener('storage', onChange);
            };
            s.savedMap = saved;
            street = s;
            fit();
            if (data) feed(data, true);
            s.__alive = true;
            s.draw();
            s.componentDidMount?.();
        }
        // The card is drawn at the board's size and scaled to the page; a
        // phone's street grows only a little on a bigger phone, centred.
        // On a desktop or tablet the whole street fits the screen's height
        // and the hour rings float in its sky (owner, 13 Oct 2026); the
        // page's distance note lines up with its right edge (--street-w).
        // It also fills the screen's width (owner, 13 Oct 2026): on a wide,
        // short screen the street is wider (more wall, sky and pavement,
        // more hours of posters at once), up to how wide it's drawn.
        function fit() {
            if (!street) return;
            const art = ART[layoutName];
            const [w0, h] = art.size;
            const box = host.querySelector('.st-fit');
            let w = w0;
            let zoom = Math.max(0.3, Math.min(host.clientWidth / w0, layoutName === 'phone' ? 1.25 : Infinity));
            if (layoutName === 'desktop') {
                const top = box.getBoundingClientRect().top + scrollY;
                zoom = Math.max(0.45, Math.min(zoom, (innerHeight - top - 20) / h));
                w = Math.min(art.maxW || w0, Math.max(w0, Math.floor(host.clientWidth / zoom)));
            }
            if (street.__cfg.W !== w) {
                const base = art.cfg;
                street.__cfg = Object.assign({}, base, { W: w, zoneW: base.zoneW + w - w0 });
                if (street.__alive) street.setState({});
            }
            street.zoom = zoom;
            box.style.setProperty('--st-w', `${w}px`);
            box.style.width = `${w}px`;
            box.style.height = `${h}px`;
            box.style.zoom = zoom.toFixed(4);
            if (layoutName === 'desktop') {
                const left = (host.clientWidth - w * zoom) / 2;
                host.style.setProperty('--rings-x', `${Math.round(left + w0 * zoom * 0.3)}px`);
                host.style.setProperty('--rings-y', `${Math.round(box.offsetTop + 14)}px`);
                host.closest('main')?.style.setProperty('--street-w', `${Math.round(w * zoom)}px`);
            } else host.closest('main')?.style.removeProperty('--street-w');
        }
        // What sits above the street (the filters, the distance note) can
        // change its height, and with it the room the street has.
        const above = new ResizeObserver(() => fit());
        const board = host.closest('.st-board');
        for (const el of board?.parentElement?.children || []) if (el !== board) above.observe(el);
        function feed(next, fresh) {
            const s = street;
            const changed =
                fresh ||
                !data ||
                data.day !== next.day ||
                data.ids.join() !== next.ids.join();
            data = next;
            s.__catalogue = next.catalogue;
            s.__day = next.day;
            s.__ids = new Set(next.ids);
            s._evr = null;
            const reset = changed
                ? { page: undefined, offset: undefined, drag: 0, pos: 0, story: null }
                : {};
            if (s.__alive) s.setState(Object.assign(reset, { saved: s.savedMap() }));
            else s.state = Object.assign({}, s.state, reset, { saved: s.savedMap() });
        }
        build();
        let resizeFrame;
        window.addEventListener('resize', () => {
            cancelAnimationFrame(resizeFrame);
            resizeFrame = requestAnimationFrame(() => {
                if (pick() !== layoutName) build();
                else fit();
            });
        });
        return {
            update(items, catalogue) {
                const next = {
                    ids: items.map((x) => x.id),
                    day: items[0]?.date || '',
                    catalogue,
                };
                host.hidden = !items.length;
                if (!items.length) return;
                feed(next, false);
            },
            get street() {
                return street;
            },
        };
    }
    window.ExpaduStreet = { mount };
})();
