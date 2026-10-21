/* Expadu design guide behaviour. Reads colours from the shared palette so the
   guide cannot drift from the mocks. All mock content is sample data. */
(function () {
    const icon = (name) => (window.TodayIcon ? window.TodayIcon(name) : '');
    const root = document.body;
    const css = (name) => getComputedStyle(root).getPropertyValue(name).trim();

    // ── Icons ────────────────────────────────────────────────────────────
    function paintIcons(scope = document) {
        scope.querySelectorAll('i[data-icon]').forEach((slot) => {
            const holder = document.createElement('template');
            holder.innerHTML = icon(slot.dataset.icon);
            const svg = holder.content.firstElementChild;
            if (svg) slot.replaceWith(svg);
        });
    }

    // ── Toast ────────────────────────────────────────────────────────────
    const toastEl = document.querySelector('[data-toast]');
    let toastTimer;
    function toast(message) {
        toastEl.textContent = message;
        toastEl.classList.add('is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(
            () => toastEl.classList.remove('is-visible'),
            1800,
        );
    }

    // ── Colour ───────────────────────────────────────────────────────────
    const palette = {
        surfaces: [
            ['Canvas', '--color-bg-canvas', '--paper', 'Page background'],
            [
                'Surface',
                '--color-bg-surface',
                '--surface',
                'Cards, sections, menus',
            ],
            [
                'Surface quiet',
                '--color-bg-quiet',
                '--surface-quiet',
                'Rows, hover, inputs',
            ],
            [
                'Surface inset',
                '--color-bg-inset',
                '--surface-inset',
                'Neutral pills',
            ],
            [
                'Surface hover',
                '--color-bg-hover',
                '--surface-hover',
                'Pressed neutral',
            ],
            ['Line', '--color-border', '--line', 'Hairlines, borders'],
            [
                'Line strong',
                '--color-border-strong',
                null,
                'Form controls (3:1)',
            ],
        ],
        text: [
            ['Ink', '--color-text', '--ink', 'Titles, data, links'],
            ['Ink soft', '--color-text-soft', '--ink-soft', 'Body detail'],
            ['Muted', '--color-text-muted', '--muted', 'Captions, labels'],
        ],
        action: [
            [
                'Action',
                '--color-action',
                '--action',
                'Filled primary, icon actions',
            ],
            [
                'Action ink',
                '--color-action-text',
                '--action-ink',
                'Orange text actions',
            ],
            [
                'Action hover',
                '--color-action-hover',
                '--action-hover',
                'Pressed primary',
            ],
        ],
        travel: [
            [
                'Cyan surface',
                '--color-select-bg',
                '--blue',
                'Selected state, journey card',
            ],
            [
                'Cyan hover',
                '--color-select-bg-hover',
                '--cyan-hover',
                'Hover on cyan',
            ],
            [
                'Teal',
                '--color-select-text',
                '--teal',
                'Travel icons, line ink, focus',
            ],
        ],
        status: [
            ['Lavender', '--color-paper-bg', null, 'Paperwork surfaces'],
            ['Violet', '--color-paper-text', null, 'Paperwork icon, progress'],
            ['Amber surface', '--color-attention-bg', null, 'Deadline, advice'],
            ['Amber', '--color-attention', null, 'Warning pill, icon tile'],
            ['Amber ink', '--color-attention-text', null, 'Warning text'],
            ['Coral surface', '--color-conflict-bg', null, 'Conflict'],
            ['Coral ink', '--color-conflict-text', null, 'Conflict text'],
        ],
        sections: [
            ['Today · Travel', '--color-nav-teal', null, 'Navigation icon'],
            [
                'Events · Services',
                '--color-nav-violet',
                null,
                'Navigation icon',
            ],
            ['Paperwork', '--color-nav-rust', null, 'Navigation icon'],
            ['Brand orange', '--color-brand-orange', null, 'Logo only'],
            ['Brand cyan', '--color-brand-cyan', null, 'Logo only'],
        ],
    };
    const toRgb = (hex) => {
        const value = hex.replace('#', '');
        const full =
            value.length === 3
                ? value
                      .split('')
                      .map((c) => c + c)
                      .join('')
                : value.slice(0, 6);
        return [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16));
    };
    const luminance = (hex) => {
        const [r, g, b] = toRgb(hex).map((v) => {
            const c = v / 255;
            return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const contrast = (a, b) => {
        const [x, y] = [luminance(a), luminance(b)].sort((m, n) => n - m);
        return (x + 0.05) / (y + 0.05);
    };
    function renderSwatches() {
        const ink = css('--ink') || '#27241f';
        document.querySelectorAll('[data-swatches]').forEach((group) => {
            group.innerHTML = palette[group.dataset.swatches]
                .map(([name, token, alias, use, literal]) => {
                    const hex = (literal || css(token)).toLowerCase();
                    const light = luminance(hex) > 0.35;
                    const textColour = light ? ink : '#ffffff';
                    const ratio = contrast(hex, textColour);
                    const grade =
                        ratio >= 7
                            ? 'AAA'
                            : ratio >= 4.5
                              ? 'AA'
                              : ratio >= 3
                                ? 'AA large'
                                : 'Decor';
                    return `<button class="swatch" type="button" data-copy="${hex}" aria-label="${name}, ${hex}. Copy hex">
                        <span class="swatch-chip" style="background:${hex}"><span class="swatch-contrast" title="${light ? 'Ink' : 'White'} text on this colour">${light ? 'Ink' : 'White'} ${ratio.toFixed(1)} · ${grade}</span></span>
                        <span class="swatch-meta"><strong>${name}</strong><code>${token} · ${hex}</code>${alias ? `<code>alias ${alias}</code>` : ''}<small>${use}</small></span>
                    </button>`;
                })
                .join('');
        });
    }
    document.addEventListener('click', (event) => {
        const swatch = event.target.closest('[data-copy]');
        if (!swatch) return;
        const value = swatch.dataset.copy;
        const done = () => toast(`Copied ${value}`);
        if (navigator.clipboard?.writeText)
            navigator.clipboard.writeText(value).then(done, () => toast(value));
        else toast(value);
    });

    // ── Spacing ──────────────────────────────────────────────────────────
    function renderSpacing() {
        const slot = document.querySelector('[data-spacing]');
        const scale = [
            [2, 'Hairline offsets'],
            [4, 'Icon-to-text in pills'],
            [6, 'Tight stacks'],
            [8, 'Row padding, icon gaps'],
            [10, 'Control gaps'],
            [12, 'Card gaps, row padding'],
            [14, 'Card padding (phone)'],
            [16, 'Page gutter (phone)'],
            [22, 'Section padding (desktop)'],
            [32, 'Frame padding'],
        ];
        slot.innerHTML = scale
            .map(
                ([size, use]) =>
                    `<div class="space-row"><span class="mono">${size}px</span><span class="space-bar" style="width:${size * 3}px"></span><span>${use}</span></div>`,
            )
            .join('');
    }
    function renderRedlines() {
        const box = document.querySelector('[data-redline]');
        if (!box) return;
        const strip = box.querySelector('.deadline-strip');
        const draw = () => {
            box.querySelectorAll('.measure').forEach((m) => m.remove());
            const base = box.getBoundingClientRect();
            const s = strip.getBoundingClientRect();
            const iconBox = strip
                .querySelector('.deadline-symbol')
                .getBoundingClientRect();
            const text = strip.children[1].getBoundingClientRect();
            const add = (cls, style, label) => {
                const m = document.createElement('span');
                m.className = `measure ${cls}`;
                Object.assign(m.style, style);
                m.innerHTML = `<span>${label}</span>`;
                box.append(m);
            };
            const left = s.left - base.left;
            const top = s.top - base.top;
            add(
                'h',
                {
                    left: `${left}px`,
                    top: `${top - 12}px`,
                    width: `${iconBox.left - s.left}px`,
                },
                Math.round(iconBox.left - s.left),
            );
            add(
                'h',
                {
                    left: `${iconBox.left - base.left}px`,
                    top: `${top - 12}px`,
                    width: `${iconBox.width}px`,
                },
                Math.round(iconBox.width),
            );
            add(
                'h below',
                {
                    left: `${iconBox.right - base.left}px`,
                    top: `${s.bottom - base.top + 4}px`,
                    width: `${text.left - iconBox.right}px`,
                },
                Math.round(text.left - iconBox.right),
            );
            add(
                'v',
                {
                    left: `${s.right - base.left + 4}px`,
                    top: `${top}px`,
                    height: `${s.height}px`,
                },
                Math.round(s.height),
            );
        };
        draw();
        new ResizeObserver(draw).observe(box);
    }

    // ── Icons grid ───────────────────────────────────────────────────────
    function renderIcons() {
        const primary = [
            'home',
            'calendar',
            'document',
            'train',
            'grid',
            'pin',
            'bookmark',
            'plus',
            'calendar-plus',
            'sun',
            'down',
            'trash',
            'clock',
            'dots',
        ];
        const legacy = [
            'walk',
            'bike',
            'bus',
            'arrow',
            'back',
            'close',
            'check',
            'info',
            'spark',
            'route',
            'search',
            'bell',
            'ticket',
            'chat',
            'health',
            'film',
            'leaf',
            'pencil',
        ];
        const cell = (name) =>
            `<div class="icon-cell">${icon(name)}<span>${name}</span></div>`;
        document.querySelector('[data-icons="primary"]').innerHTML = primary
            .map(cell)
            .join('');
        document.querySelector('[data-icons="legacy"]').innerHTML = legacy
            .map(cell)
            .join('');
    }

    // ── Motion demos ─────────────────────────────────────────────────────
    document.addEventListener('click', (event) => {
        const play = event.target.closest('[data-play]');
        if (!play) return;
        const stage = play
            .closest('.motion-card')
            .querySelector('[data-motion]');
        stage.classList.remove('is-playing');
        void stage.offsetWidth;
        stage.classList.add('is-playing');
    });

    // ── Toggles ──────────────────────────────────────────────────────────
    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-toggle-pressed]');
        if (toggle) {
            const on = toggle.getAttribute('aria-pressed') !== 'true';
            toggle.setAttribute('aria-pressed', String(on));
            toggle.setAttribute('aria-label', on ? 'Saved' : 'Save');
        }
        const segment = event.target.closest('[data-segmented] button');
        if (segment)
            segment.parentElement
                .querySelectorAll('button')
                .forEach((b) =>
                    b.setAttribute('aria-pressed', String(b === segment)),
                );
    });

    // ── Select menu (mirrors the Today stop menu) ────────────────────────
    function bindPicker(picker) {
        const trigger = picker.querySelector('.stop-trigger');
        const list = picker.querySelector('.stop-list');
        const options = [...list.querySelectorAll('[role="option"]')];
        const set = (open, focus = false) => {
            list.hidden = !open;
            trigger.setAttribute('aria-expanded', String(open));
            picker.classList.toggle('is-open', open);
            if (open && focus)
                (
                    options.find(
                        (o) => o.getAttribute('aria-selected') === 'true',
                    ) || options[0]
                ).focus();
        };
        const choose = (option) => {
            options.forEach((o) =>
                o.setAttribute('aria-selected', String(o === option)),
            );
            picker.querySelector('[data-picker-label]').textContent =
                option.textContent.trim();
            set(false);
            trigger.focus();
        };
        trigger.addEventListener('click', () => set(list.hidden, true));
        trigger.addEventListener('keydown', (event) => {
            if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                set(true, true);
            }
        });
        options.forEach((option, index) => {
            option.addEventListener('click', () => choose(option));
            option.addEventListener('keydown', (event) => {
                const move = {
                    ArrowDown: index + 1,
                    ArrowUp: index - 1,
                    Home: 0,
                    End: options.length - 1,
                }[event.key];
                if (move !== undefined) {
                    event.preventDefault();
                    options[
                        Math.max(0, Math.min(options.length - 1, move))
                    ].focus();
                } else if (['Enter', ' '].includes(event.key)) {
                    event.preventDefault();
                    choose(option);
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    set(false);
                    trigger.focus();
                } else if (event.key === 'Tab') set(false);
            });
        });
        document.addEventListener('pointerdown', (event) => {
            if (!picker.contains(event.target)) set(false);
        });
    }

    // ── Scroll spy ───────────────────────────────────────────────────────
    function bindScrollSpy() {
        const links = [...document.querySelectorAll('.layers a')];
        const byId = new Map(links.map((a) => [a.hash.slice(1), a]));
        const observer = new IntersectionObserver(
            (entries) => {
                const visible = entries
                    .filter((e) => e.isIntersecting)
                    .sort(
                        (a, b) =>
                            a.boundingClientRect.top - b.boundingClientRect.top,
                    )[0];
                if (!visible) return;
                links.forEach((a) => a.removeAttribute('aria-current'));
                const active = byId.get(visible.target.id);
                if (active) {
                    active.setAttribute('aria-current', 'true');
                    if (matchMedia('(max-width: 999px)').matches)
                        active.scrollIntoView({
                            inline: 'center',
                            block: 'nearest',
                        });
                }
            },
            { rootMargin: '-15% 0px -70% 0px' },
        );
        document
            .querySelectorAll('.frame[id]')
            .forEach((frame) => observer.observe(frame));
    }

    // ── Mock gallery ─────────────────────────────────────────────────────
    const scenarios = [
        ['everyday', 'Everyday'],
        ['quiet', 'Quiet day'],
        ['first-visit', 'First visit'],
        ['preparing', 'Reminder due'],
        ['later', 'Later in the day'],
        ['busy', 'Busy day'],
        ['delay', 'Route delay'],
        ['event', 'Important event'],
        ['competing', 'Delay + event'],
        ['unavailable', 'Travel unavailable'],
    ];
    const todayURL = (view) =>
        `../today-next/brief/?view=${encodeURIComponent(view)}`;
    // Width a frame may use: its section's inner width, capped by the device size.
    const roomFor = (viewport) => {
        const body = viewport.closest('.frame-body');
        if (!body) return viewport.parentElement.clientWidth;
        const cs = getComputedStyle(body);
        return (
            body.clientWidth -
            parseFloat(cs.paddingLeft) -
            parseFloat(cs.paddingRight)
        );
    };
    function fit(viewport, width, height, maxWidth) {
        const frame = viewport.querySelector('iframe');
        const available = Math.min(
            maxWidth ?? Infinity,
            viewport.parentElement.clientWidth || Infinity,
            roomFor(viewport),
        );
        const scale = Math.min(1, available / width);
        frame.style.width = `${width}px`;
        frame.style.height = `${height}px`;
        frame.style.transform = `scale(${scale})`;
        viewport.style.width = `${width * scale}px`;
        viewport.style.height = `${height * scale}px`;
    }
    function bindMocks() {
        const chips = document.querySelector('[data-scenarios]');
        const phone = document.querySelector('[data-device="phone"]');
        const desktop = document.querySelector('[data-device="desktop"]');
        const open = document.querySelector('[data-mock-open]');
        let current = 'everyday';
        try {
            const kept = localStorage.getItem('expadu-guide-scenario');
            if (scenarios.some(([id]) => id === kept)) current = kept;
        } catch {
            /* Optional convenience only. */
        }
        chips.innerHTML = scenarios
            .map(
                ([id, label]) =>
                    `<button type="button" data-scenario="${id}" aria-pressed="${id === current}">${label}</button>`,
            )
            .join('');
        let loaded = false;
        const load = () => {
            loaded = true;
            phone.querySelector('iframe').src = todayURL(current);
            desktop.querySelector('iframe').src = todayURL(current);
            open.href = todayURL(current);
        };
        const layout = () => {
            fit(phone, 390, 844, 330);
            fit(desktop, 1440, 900);
        };
        layout();
        new ResizeObserver(layout).observe(desktop.parentElement);
        new IntersectionObserver(
            (entries, observer) => {
                if (entries.some((e) => e.isIntersecting)) {
                    load();
                    observer.disconnect();
                }
            },
            { rootMargin: '400px' },
        ).observe(document.querySelector('#mocks'));
        chips.addEventListener('click', (event) => {
            const button = event.target.closest('[data-scenario]');
            if (!button) return;
            current = button.dataset.scenario;
            chips
                .querySelectorAll('button')
                .forEach((b) =>
                    b.setAttribute('aria-pressed', String(b === button)),
                );
            try {
                localStorage.setItem('expadu-guide-scenario', current);
            } catch {
                /* Optional convenience only. */
            }
            if (loaded) load();
        });
    }
    const departments = [
        [
            'Events',
            'calendar',
            '--nav-violet',
            '../events-next/',
            'Discovery, festival cards, saved events',
        ],
        [
            'Paperwork',
            'document',
            '--nav-rust',
            '../bureaucracy-next/',
            'Steps, documents, verified sources',
        ],
        [
            'Travel · planner',
            'train',
            '--nav-teal',
            '../departures-next/',
            'Side-by-side planner, Map/Steps',
        ],
        [
            'Travel · station',
            'train',
            '--nav-teal',
            '../departures-next/station.html',
            'Station-wide timetable, filters',
        ],
        [
            'Services',
            'grid',
            '--nav-violet',
            '../services-next/',
            'Service requests and preferences',
        ],
        [
            'Composer',
            'spark',
            '--nav-teal',
            '../composer-studio/',
            'Conversation, plan review, history',
        ],
    ];
    function bindDepartments() {
        const grid = document.querySelector('[data-departments]');
        const viewer = document.querySelector('[data-department-viewer]');
        const viewport = viewer.querySelector('[data-device="department"]');
        grid.innerHTML = departments
            .map(
                ([name, glyph, colour, url, about], index) =>
                    `<button class="department" type="button" data-department="${index}" aria-pressed="false"><span style="color:var(${colour})">${icon(glyph)}</span><strong>${name}</strong><small>${about}</small></button>`,
            )
            .join('');
        const layout = () => fit(viewport, 1440, 900);
        new ResizeObserver(layout).observe(viewer);
        grid.addEventListener('click', (event) => {
            const button = event.target.closest('[data-department]');
            if (!button) return;
            const [name, , , url] =
                departments[Number(button.dataset.department)];
            grid.querySelectorAll('[data-department]').forEach((b) =>
                b.setAttribute('aria-pressed', String(b === button)),
            );
            viewer.hidden = false;
            viewer.querySelector('[data-department-name]').textContent =
                `${name} · 1440 × 900`;
            viewer.querySelector('[data-department-open]').href = url;
            viewport.querySelector('iframe').src = url;
            layout();
            viewer.scrollIntoView({
                behavior: matchMedia('(prefers-reduced-motion: reduce)').matches
                    ? 'auto'
                    : 'smooth',
                block: 'nearest',
            });
        });
    }

    // ── Scale proposal ───────────────────────────────────────────────────
    async function bindScaleProposal() {
        const statsSlot = document.querySelector('[data-scale-stats]');
        const tablesSlot = document.querySelector('[data-scale-tables]');
        if (!statsSlot) return;
        let report;
        try {
            report = await (
                await fetch('./scale-report.json', { cache: 'no-store' })
            ).json();
        } catch {
            statsSlot.innerHTML =
                '<p class="note">Run node tools/normalize-scales.mjs --preview to build the proposal.</p>';
            return;
        }
        const of = (property) =>
            report.changes.filter((c) => c.property === property);
        const distinct = (rows, key) => new Set(rows.map((r) => r[key])).size;
        const sizes = of('font-size'),
            weights = of('font-weight'),
            radii = of('border-radius');
        const stat = (label, before, after, detail) =>
            `<div class="scale-stat"><small>${label}</small><strong><s>${before}</s>${after}</strong><span>${detail}</span></div>`;
        statsSlot.innerHTML = [
            stat(
                'Font sizes',
                distinct(sizes, 'from'),
                distinct(sizes, 'shown'),
                '10 steps + 2 fluid ranges',
            ),
            stat(
                'Font weights',
                distinct(weights, 'from'),
                distinct(weights, 'shown'),
                'Only the loaded Geist weights',
            ),
            stat(
                'Corner radii',
                distinct(radii, 'from'),
                distinct(
                    radii.filter((r) => !r.shown.includes(' ')),
                    'shown',
                ),
                '8 · 12 · 16 · 24 + round',
            ),
            stat(
                'Form borders',
                '1.5:1',
                '3.6:1',
                'Meets WCAG non-text contrast',
            ),
        ].join('');
        const numeric = (value) => parseFloat(String(value)) || 0;
        // Pixel values in order; "20–28px fluid" reads as [20, 28], vw parts are ignored.
        const pxList = (value) =>
            String(value).includes('–')
                ? [...String(value).matchAll(/\d+/g)].map((m) => Number(m[0]))
                : [
                      ...String(value).matchAll(/(\d+)px|(?:^|\s)(0)(?=\s|$)/g),
                  ].map((m) => Number(m[1] ?? m[2]));
        const signed = (n) => (n > 0 ? `+${n}` : `${n}`).replace('-', '−');
        // How the change looks, not just whether the text differs.
        const difference = (row) => {
            if (row.property === 'font-weight') {
                const from = Number(row.from),
                    to = Number(row.shown);
                if (from === to) return null;
                return [550, 650].includes(from)
                    ? { label: 'looks the same', quiet: true }
                    : { label: signed(to - from) };
            }
            const from = pxList(row.from);
            const to = pxList(row.shown);
            if (row.from === '50%' || !from.length || from.length !== to.length)
                return null;
            const deltas = from.map((n, i) => to[i] - n);
            if (deltas.every((d) => d === 0)) return null;
            const unique = [...new Set(deltas.filter((d) => d !== 0))];
            return { label: unique.map((d) => `${signed(d)}px`).join(' / ') };
        };
        const table = (title, rows, sortKey) => {
            const sorted = rows
                .slice()
                .sort((a, b) => numeric(a[sortKey]) - numeric(b[sortKey]));
            const diffs = sorted.map(difference);
            const changed = diffs.filter((d) => d && !d.quiet).length;
            const quiet = diffs.filter((d) => d?.quiet).length;
            return `<table class="scale-table"><caption>${title} <small>${changed} of ${sorted.length} change${quiet ? ` · ${quiet} renamed, look the same` : ''}</small></caption><thead><tr><th>Now</th><th>Becomes</th><th>Uses</th></tr></thead><tbody>${sorted
                .map((row) => {
                    const diff = difference(row);
                    const token = row.to.replace(/var\((--[\w-]+)\)/g, '$1');
                    return `<tr class="${diff ? '' : 'is-same'}"><td>${row.from}</td><td>${row.shown}${diff ? `<span class="delta ${diff.quiet ? 'is-quiet' : ''}">${diff.label}</span>` : ''}<br><code class="mono">${token}</code></td><td>${row.count}</td></tr>`;
                })
                .join('')}</tbody></table>`;
        };
        tablesSlot.innerHTML =
            `<label class="scale-filter"><input type="checkbox" data-scale-all> Show values that stay the same</label>` +
            `<div class="scale-table-grid">${table('Font size', sizes, 'from') + table('Font weight', weights, 'from') + table('Corner radius', radii, 'from')}</div>`;
        tablesSlot.classList.add('is-changes-only');
        tablesSlot
            .querySelector('[data-scale-all]')
            .addEventListener('change', (event) =>
                tablesSlot.classList.toggle(
                    'is-changes-only',
                    !event.target.checked,
                ),
            );
    }
    function bindComparison() {
        const chips = document.querySelector('[data-compare-scenarios]');
        const stage = document.querySelector('[data-compare-stage]');
        if (!chips) return;
        const frames = {
            current: stage.querySelector('[data-compare="current"]'),
            proposed: stage.querySelector('[data-compare="proposed"]'),
        };
        const base = {
            current: '../today-next/brief/',
            proposed: '../today-next-tokens/brief/',
        };
        let scenario = 'everyday',
            size = 'phone',
            loaded = false;
        chips.innerHTML = scenarios
            .map(
                ([id, label]) =>
                    `<button type="button" data-compare-scenario="${id}" aria-pressed="${id === scenario}">${label}</button>`,
            )
            .join('');
        const layout = () => {
            stage.classList.toggle('is-desktop', size === 'desktop');
            for (const viewport of Object.values(frames)) {
                if (size === 'phone') fit(viewport, 390, 844, 320);
                else
                    fit(
                        viewport,
                        1440,
                        900,
                        viewport.parentElement.clientWidth,
                    );
            }
        };
        const load = () => {
            loaded = true;
            for (const [key, viewport] of Object.entries(frames))
                viewport.querySelector('iframe').src =
                    `${base[key]}?view=${encodeURIComponent(scenario)}`;
        };
        layout();
        new ResizeObserver(layout).observe(stage);
        new IntersectionObserver(
            (entries, observer) => {
                if (entries.some((e) => e.isIntersecting)) {
                    load();
                    observer.disconnect();
                }
            },
            { rootMargin: '400px' },
        ).observe(stage);
        chips.addEventListener('click', (event) => {
            const button = event.target.closest('[data-compare-scenario]');
            if (!button) return;
            scenario = button.dataset.compareScenario;
            chips
                .querySelectorAll('button')
                .forEach((b) =>
                    b.setAttribute('aria-pressed', String(b === button)),
                );
            if (loaded) load();
        });
        document
            .querySelector('[data-compare-size]')
            .addEventListener('click', (event) => {
                const button = event.target.closest('[data-size]');
                if (!button) return;
                size = button.dataset.size;
                button.parentElement
                    .querySelectorAll('[data-size]')
                    .forEach((b) =>
                        b.setAttribute('aria-pressed', String(b === button)),
                    );
                layout();
            });
    }

    // ── Live header crops ────────────────────────────────────────────────
    function bindHeaderCrops() {
        const crops = [...document.querySelectorAll('[data-crop]')];
        if (!crops.length) return;
        const spec = {
            phone: [390, 844, 124, 330],
            desktop: [1440, 900, 74, null],
        };
        const layout = () =>
            crops.forEach((viewport) => {
                const [width, height, visible, max] =
                    spec[viewport.dataset.crop];
                const frame = viewport.querySelector('iframe');
                const available = Math.min(
                    max ?? Infinity,
                    viewport.parentElement.clientWidth || Infinity,
                    roomFor(viewport),
                );
                const scale = Math.min(1, available / width);
                frame.style.width = `${width}px`;
                frame.style.height = `${height}px`;
                frame.style.transform = `scale(${scale})`;
                viewport.style.width = `${width * scale}px`;
                viewport.style.height = `${visible * scale}px`;
            });
        layout();
        new ResizeObserver(layout).observe(
            document.querySelector('#navigation'),
        );
        new IntersectionObserver(
            (entries, observer) => {
                if (!entries.some((e) => e.isIntersecting)) return;
                crops.forEach((viewport) => {
                    viewport.querySelector('iframe').src =
                        '../today-next/brief/?view=everyday';
                });
                observer.disconnect();
            },
            { rootMargin: '400px' },
        ).observe(document.querySelector('#navigation'));
    }

    paintIcons();
    renderSwatches();
    renderSpacing();
    renderIcons();
    document.querySelectorAll('[data-picker]').forEach(bindPicker);
    bindScrollSpy();
    bindMocks();
    bindDepartments();
    bindScaleProposal();
    bindComparison();
    bindHeaderCrops();
    // Fonts change text widths; measure redlines once they are ready.
    (document.fonts?.ready || Promise.resolve()).then(renderRedlines);
})();
