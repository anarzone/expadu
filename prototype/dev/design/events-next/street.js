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
        function fit() {
            if (!street) return;
            const [w, h] = ART[layoutName].size;
            const zoom = Math.max(0.3, Math.min(host.clientWidth / w, layoutName === 'phone' ? 1.25 : Infinity));
            street.zoom = zoom;
            const box = host.querySelector('.st-fit');
            box.style.width = `${w}px`;
            box.style.height = `${h}px`;
            box.style.zoom = zoom.toFixed(4);
        }
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
