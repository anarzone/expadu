/* Events as a poster wall (owner's choice, 8 October): every event is a
   poster set from its listing. The Wall is the Events page; the page
   offers no other view. The Skyline, which lights the day's events above
   Cologne and keeps the photo cards, stays in the preview as a reference
   only, at ?view=skyline. Filters, dates, details, saving and planning are
   the Events page's own (app.js); this file only swaps how events are
   drawn. Loaded after festival.js and before app.js. */
(function () {
    const U = ExpaduUI,
        E = U.esc,
        I = U.icon,
        F = ExpaduEvents,
        P = ExpaduPosterArt,
        Sky = ExpaduSky;
    const base = window.ExpaduEventFestival;
    const day = window.ExpaduSampleDay || {};
    const today = day.date || '';
    const view =
        new URLSearchParams(location.search).get('view') === 'skyline'
            ? 'skyline'
            : 'wall';

    // Pictures Events may show: the labelled AI illustrations, or approved
    // provider media. The sample film night's illustration shows mountains
    // and a lake, so it read as somewhere else (flagged 7 October); a poster
    // is more honest than that picture.
    const illustrations = new Set([
        'kunst-machen',
        'exhibition-ludwig',
        'schach',
        'forest',
        'garden',
        'tandem',
        'film',
    ]);
    const noUsablePicture = new Set(['today-review-cinema']);
    const hasPicture = (item) =>
        !noUsablePicture.has(item.id) &&
        (!!window.ExpaduEventPosters?.approvedMedia(item) ||
            illustrations.has(item.illustration || item.id));

    // A venue's own listing can say whether it is step-free.
    function access(item) {
        const place = window.ExpaduPlaces?.venue?.(item.venue);
        return place?.wheelchair
            ? `${window.ExpaduPlaces.wheelchairText[place.wheelchair]} (venue listing)`
            : '';
    }
    const unique = (items) => [
        ...new Map(
            items.map((item) => [item.occurrence || item.id, item]),
        ).values(),
    ];
    const posterOptions = (item, distance = '') => ({
        today,
        distance,
        access: access(item),
        appointment: day.appointment
            ? { ...day.appointment, title: day.appointment.title }
            : null,
    });

    /* ---------- The poster column: what's coming up, all the way round ----
       The featured picks, then the later listings in the same city series.
       It turns like a real Litfaßsäule: drag or swipe sideways, or the
       arrow keys. Only the poster facing you can be used. It stands on a
       street corner (mount() builds the street around it). */
    const columnItems = (items) => [
        ...base.featured(items),
        ...laterListings(items).map((item) => ({ ...item, listing: true })),
    ];
    function feature(items) {
        const posters = columnItems(items).map((item) =>
            item.listing
                ? P.poster(item, { size: 'column', today, listing: true })
                : P.poster(item, { size: 'column', ...posterOptions(item) }),
        );
        if (!posters.length) return '';
        const many = posters.length > 1;
        return `<div class="wall-column" role="region" aria-roledescription="carousel" aria-label="Coming up, independent of date filters">
            <span class="wall-column-cap" aria-hidden="true"><span></span></span>
            <span class="wall-column-frieze" aria-hidden="true"></span>
            <div class="wall-column-body"${many ? ' tabindex="0" aria-label="Poster column. Drag sideways or use the arrow keys to turn it."' : ''}>
                <span class="wall-column-old" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span></span>
                ${posters.map((poster, i) => `<div class="wall-column-face" role="group" aria-roledescription="poster" aria-label="${i + 1} of ${posters.length}">${poster}</div>`).join('')}
                <span class="wall-column-glow" aria-hidden="true"></span>
            </div>
            <span class="wall-column-base" aria-hidden="true"><span></span></span>
            <p class="wall-column-caption">Coming up${many ? ` · <span class="wall-column-count" aria-live="polite">1 of ${posters.length}</span> · ${window.matchMedia?.('(pointer: coarse)').matches ? 'swipe' : 'drag'} to turn` : ' · on the poster column'}</p>
        </div>`;
    }
    // The skyline is wider than its stage, so it pans: it opens on the
    // day's lights, follows a mouse drag (touch scrolls natively), and
    // fades at an edge while there's more beyond.
    function panSky() {
        document
            .querySelectorAll('.wall-view--skyline .sky-scroll')
            .forEach((scroller) => {
                if (!scroller.offsetParent) return;
                const width = scroller.firstElementChild.offsetWidth;
                const view = scroller.clientWidth;
                const at = (light) => (parseFloat(light.style.left) / 100) * width;
                // Frame the day's events, with room for their names; when
                // they don't all fit, open where most of them are.
                const xs = [...scroller.querySelectorAll('.sky-light')]
                    .map(at)
                    .sort((a, b) => a - b);
                if (!xs.length) return syncFades(scroller);
                let left;
                if (xs.at(-1) - xs[0] + 260 <= view)
                    left = (xs[0] + xs.at(-1)) / 2 - view / 2;
                else {
                    const inView = (x0) =>
                        xs.filter((x) => x >= x0 + 60 && x <= x0 + view - 60).length;
                    left = xs
                        .map((x) => x - 130)
                        .reduce((best, x0) => (inView(x0) > inView(best) ? x0 : best));
                }
                scroller.scrollTo({
                    left: Math.max(0, left),
                    behavior: reducedMotion() ? 'instant' : 'smooth',
                });
                syncFades(scroller);
            });
    }
    function syncFades(scroller) {
        const stage = scroller.closest('.sky-stage');
        if (!stage) return;
        const max = scroller.scrollWidth - scroller.clientWidth;
        stage.dataset.start = String(scroller.scrollLeft <= 2);
        stage.dataset.end = String(scroller.scrollLeft >= max - 2);
    }
    document.addEventListener(
        'scroll',
        (event) => {
            if (event.target.classList?.contains('sky-scroll'))
                syncFades(event.target);
        },
        true,
    );
    let pan = null;
    document.addEventListener('pointerdown', (event) => {
        const scroller = event.target.closest?.('.sky-scroll');
        if (!scroller || event.pointerType !== 'mouse' || event.button !== 0) return;
        pan = { scroller, x: event.clientX, left: scroller.scrollLeft, moved: false };
    });
    document.addEventListener('pointermove', (event) => {
        if (!pan) return;
        const dx = event.clientX - pan.x;
        if (!pan.moved && Math.abs(dx) < 6) return;
        pan.moved = true;
        pan.scroller.dataset.panning = 'true';
        pan.scroller.scrollLeft = pan.left - dx;
    });
    document.addEventListener('pointerup', () => {
        if (!pan) return;
        const moved = pan.moved;
        delete pan.scroller.dataset.panning;
        pan = null;
        // Letting go after a drag doesn't also open a light (only the click
        // that ends this drag is swallowed).
        if (!moved) return;
        const swallow = (event) => {
            event.preventDefault();
            event.stopImmediatePropagation();
        };
        document.addEventListener('click', swallow, true);
        setTimeout(() => document.removeEventListener('click', swallow, true), 0);
    });
    const reducedMotion = () =>
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    // Each poster sits a quarter turn from the next. Seen from the front, a
    // poster at angle θ is centred at R·sin θ and narrowed to cos θ, so
    // turning the column bends the posters round its sides.
    function bindColumn(root) {
        const column = root.querySelector('.wall-column');
        const body = column?.querySelector('.wall-column-body');
        const faces = [...(body?.querySelectorAll('.wall-column-face') || [])];
        const count = faces.length;
        if (!count) return;
        const step = Math.PI / 2;
        let pos = 0,
            current = 0,
            frame = null,
            drag = null,
            moved = false;
        const wrap = (d) => {
            let x = ((d % count) + count) % count;
            if (x > count / 2) x -= count;
            return x;
        };
        function paint() {
            const radius = body.clientWidth / 2;
            faces.forEach((face, i) => {
                const angle = wrap(i - pos) * step;
                const cos = Math.cos(angle);
                const visible = cos > 0.02;
                face.style.visibility = visible ? 'visible' : 'hidden';
                face.style.zIndex = String(Math.round(cos * 100));
                face.style.transform = visible
                    ? `translateX(${(radius * Math.sin(angle)).toFixed(1)}px) scaleX(${cos.toFixed(4)})`
                    : 'none';
                face.style.filter = `brightness(${(0.55 + 0.45 * cos).toFixed(3)})`;
            });
        }
        function settle(index) {
            current = ((index % count) + count) % count;
            faces.forEach((face, i) => {
                const front = i === current;
                face.inert = !front;
                face.setAttribute('aria-hidden', String(!front));
                // A poster turned over turns back when it leaves the front.
                const card = face.querySelector('.pp.is-turned');
                if (card && !front) {
                    card.classList.remove('is-turned');
                    card.querySelector('.pp-front').hidden = false;
                    card.querySelector('.pp-back').hidden = true;
                }
            });
            const counter = column.querySelector('.wall-column-count');
            if (counter) counter.textContent = `${current + 1} of ${count}`;
            const ink = faces[current]
                .querySelector('.pp')
                ?.className.match(/pp--(day|evening|night|paper)/)?.[1];
            column.dataset.ink = ink || 'paper';
        }
        function animateTo(target, duration = 320) {
            cancelAnimationFrame(frame);
            if (reducedMotion()) {
                pos = target;
                paint();
                settle(Math.round(target));
                return;
            }
            const from = pos,
                start = performance.now();
            const tick = (now) => {
                const t = Math.min(1, (now - start) / duration);
                // The shared ease-settle curve: quick start, soft landing.
                const eased = 1 - Math.pow(1 - t, 3);
                pos = from + (target - from) * eased;
                paint();
                if (t < 1) frame = requestAnimationFrame(tick);
                else settle(Math.round(target));
            };
            frame = requestAnimationFrame(tick);
        }
        const go = (by) => animateTo(Math.round(pos) + by);
        body.addEventListener('keydown', (event) => {
            if (event.target !== body || count < 2) return;
            const by = { ArrowLeft: -1, ArrowRight: 1 }[event.key];
            if (by) {
                event.preventDefault();
                go(by);
            }
        });
        if (count > 1) {
            body.addEventListener('pointerdown', (event) => {
                if (event.button !== 0 || event.isPrimary === false) return;
                cancelAnimationFrame(frame);
                moved = false;
                drag = {
                    id: event.pointerId,
                    x: event.clientX,
                    y: event.clientY,
                    pos,
                    samples: [[performance.now(), pos]],
                };
            });
            body.addEventListener('pointermove', (event) => {
                if (!drag || event.pointerId !== drag.id) return;
                const dx = event.clientX - drag.x,
                    dy = event.clientY - drag.y;
                if (!moved) {
                    if (Math.max(Math.abs(dx), Math.abs(dy)) < 8) return;
                    if (Math.abs(dy) > Math.abs(dx)) {
                        drag = null;
                        return;
                    }
                    moved = true;
                    body.dataset.turning = 'true';
                    body.setPointerCapture(event.pointerId);
                }
                event.preventDefault();
                // On-screen width: the corner may be scaled to the screen.
                pos =
                    drag.pos -
                    dx / ((body.getBoundingClientRect().width / 2) * step);
                drag.samples.push([performance.now(), pos]);
                if (drag.samples.length > 6) drag.samples.shift();
                paint();
            });
            const release = (event) => {
                if (!drag || event.pointerId !== drag.id) return;
                const { samples, pos: start } = drag;
                drag = null;
                delete body.dataset.turning;
                if (!moved) return;
                // A flick turns one poster; a long drag lands on the nearest.
                const [t0, p0] = samples[0];
                const [t1, p1] = samples.at(-1);
                const velocity = (p1 - p0) / Math.max(1, t1 - t0);
                let target = Math.round(pos + velocity * 160);
                target = Math.max(Math.round(start) - 1, Math.min(Math.round(start) + 1, target));
                animateTo(target);
            };
            body.addEventListener('pointerup', release);
            body.addEventListener('pointercancel', release);
            // The release of a turn must not also turn a poster over.
            body.addEventListener(
                'click',
                (event) => {
                    if (!moved) return;
                    moved = false;
                    event.preventDefault();
                    event.stopImmediatePropagation();
                },
                true,
            );
        }
        new ResizeObserver(paint).observe(body);
        paint();
        settle(0);
        // Once a session, the column turns a little and back, to show it can.
        let hinted = false;
        try {
            hinted = !!sessionStorage.getItem('expadu-column-hint');
            sessionStorage.setItem('expadu-column-hint', '1');
        } catch {}
        if (count > 1 && !hinted && !reducedMotion())
            setTimeout(() => {
                if (drag || Math.round(pos) !== 0) return;
                animateTo(0.22, 420);
                setTimeout(() => !drag && animateTo(0, 420), 460);
            }, 900);
    }
    const bind = (root) => {
        base.bind(root);
        bindColumn(root);
    };

    /* ---------- Skyline: the day's events lit above the city ----------
       The city has the board to itself (the column stays on the Wall).
       Each light is one of the chosen day's events and opens it. */
    // Where a district sits in the illustration (x and y in % of the
    // drawing). Districts that aren't drawn get no light; their events are
    // still in the list below.
    // The view is from the east bank: south on the left, north on the
    // right, and the west lies behind the city, on the horizon.
    const stage = {
        'Neustadt-Süd': [8.5, 41],
        'Altstadt-Süd': [24, 68],
        Junkersdorf: [45, 60],
        'Neustadt-Nord': [55.2, 31],
        'Altstadt-Nord': [72, 53],
        Deutz: [79, 78],
        Riehl: [86, 63],
        Mülheim: [93, 78],
        Chorweiler: [97.5, 52],
    };
    const far = new Set(['Junkersdorf', 'Chorweiler']);
    // Where the drawn city is, for its sunrise, sunset and moon.
    const skyPlace = {
        name: 'Cologne',
        lat: 50.9384,
        lng: 6.9599,
        timeZone: 'Europe/Berlin',
    };
    const weather = window.ExpaduSampleWeather || { days: {} };
    // The sample day's clock, or another time to preview (?at=19:40).
    const at = new URLSearchParams(location.search).get('at');
    const nowTime = /^([01]\d|2[0-3]):[0-5]\d$/.test(at || '')
        ? at
        : day.time || '12:00';
    // The App in colour concept's time switch (13:10, Morning … Night)
    // shows the sky at a time inside each of its parts of the day.
    const conceptClock = {
        morning: '08:30',
        midday: '12:30',
        afternoon: '16:00',
        evening: '19:30',
        night: '22:30',
    };
    const chosenTime = () => conceptClock[window.AppInColour?.get?.().time] || '';
    let skyEvents = [];
    const skyContext = (date) => ({
        date,
        city: skyPlace,
        weather: weather.days?.[date] || null,
        source: weather.source || 'Forecast',
        past: !!today && date < today,
    });
    // The listing itself says it's outdoors.
    const outdoors = (item) =>
        item.category === 'Outdoors' ||
        /\b(outdoors?|open[- ]air)\b/i.test(`${item.title} ${item.description || ''}`);
    // The Wall borrows the drawing as its backdrop: cut at the city's foot,
    // set to the right, with the sky and stars running on to the left.
    const drawing = (phase, backdrop = false) => {
        const wide = backdrop ? 'x="-3000" width="4376"' : 'width="1376"';
        return `<svg class="sky-art${backdrop ? ' wall-sky-art' : ''}" viewBox="0 40 1376 ${backdrop ? 364 : 450}"${backdrop ? ' preserveAspectRatio="xMaxYMax meet"' : ''} aria-hidden="true" focusable="false">
        <rect class="sky-1" ${wide} height="560"/><rect class="sky-2" y="130" ${wide} height="110"/><rect class="sky-3" y="230" ${wide} height="80"/><rect class="sky-4" y="300" ${wide} height="56"/><rect class="sky-5" y="346" ${wide} height="58"/>
        <g class="sky-stars">${backdrop ? '<circle cx="-120" cy="60" r="1.5"/><circle cx="-300" cy="110" r="1.2"/><circle cx="-480" cy="44" r="1.6"/><circle cx="-640" cy="96" r="1.1"/><circle cx="-60" cy="150" r="1"/>' : ''}<circle cx="90" cy="40" r="1.6"/><circle cx="240" cy="74" r="1.2"/><circle cx="420" cy="30" r="1.8"/><circle cx="560" cy="92" r="1.2"/><circle cx="700" cy="48" r="1.5"/><circle cx="980" cy="28" r="1.3"/><circle cx="1080" cy="82" r="1.7"/><circle cx="1310" cy="40" r="1.4"/><circle cx="350" cy="112" r="1"/><circle cx="820" cy="104" r="1.2"/><circle cx="160" cy="120" r="1"/><circle cx="620" cy="22" r="1.1"/></g>
        ${Sky.heavens(phase)}
        <g class="sky-far"><rect x="0" y="352" width="70" height="50"/><rect x="440" y="330" width="60" height="72"/><rect x="520" y="342" width="70" height="60"/><rect x="700" y="336" width="90" height="66"/><rect x="1040" y="344" width="80" height="58"/><rect x="1180" y="330" width="70" height="72"/><rect x="756" y="150" width="8" height="252"/><ellipse cx="760" cy="192" rx="24" ry="10"/><rect x="758.5" y="104" width="3" height="48"/><rect x="1302" y="296" width="22" height="106"/><rect x="1330" y="276" width="20" height="126"/><rect x="1354" y="306" width="22" height="96"/><circle cx="584" cy="338" r="16"/><circle cx="606" cy="332" r="20"/><circle cx="630" cy="340" r="15"/><circle cx="650" cy="336" r="13"/></g>
        <g class="sky-near"><rect x="0" y="398" width="1376" height="8"/>
        <rect x="50" y="232" width="34" height="168"/><rect x="50" y="232" width="132" height="42"/><polygon points="84,274 112,274 84,300"/>
        <rect x="210" y="232" width="34" height="168"/><rect x="210" y="232" width="132" height="42"/><polygon points="244,274 272,274 244,300"/>
        <rect x="370" y="232" width="34" height="168"/><rect x="370" y="232" width="132" height="42"/><polygon points="404,274 432,274 404,300"/>
        <rect x="420" y="360" width="40" height="40"/><rect x="462" y="348" width="36" height="52"/><rect x="500" y="366" width="50" height="34"/><rect x="552" y="352" width="40" height="48"/>
        <rect x="600" y="300" width="84" height="100"/><rect x="622" y="212" width="40" height="90"/><polygon points="622,212 662,212 642,162"/><polygon points="612,300 626,300 619,276"/><polygon points="658,300 672,300 665,276"/>
        <rect x="700" y="364" width="120" height="36"/><rect x="836" y="292" width="176" height="108"/><polygon points="836,292 1012,292 924,262"/>
        <rect x="858" y="170" width="34" height="230"/><polygon points="858,170 892,170 875,58"/><rect x="912" y="170" width="34" height="230"/><polygon points="912,170 946,170 929,58"/>
        <polygon points="846,292 856,292 851,272"/><polygon points="996,292 1006,292 1001,272"/></g>
        <g class="sky-windows">${[50, 210, 370]
            .flatMap((x) =>
                [
                    [44, 244],
                    [64, 256],
                    [84, 244],
                    [104, 256],
                    [12, 300],
                    [12, 340],
                ].map(
                    ([dx, y]) =>
                        `<rect x="${x + dx}" y="${y}" width="9" height="6"/>`,
                ),
            )
            .join(
                '',
            )}<rect x="616" y="320" width="8" height="6"/><rect x="650" y="336" width="8" height="6"/><rect x="870" y="210" width="6" height="10"/><rect x="924" y="210" width="6" height="10"/></g>
        ${backdrop ? '' : '<rect class="sky-river" y="406" width="1376" height="154"/>'}
        <path class="sky-bridge" d="M1012 402 Q1072 330 1134 402 Q1196 330 1258 402 Q1318 330 1376 402"/><rect class="sky-bridge-deck" x="1012" y="396" width="364" height="8"/>
        ${backdrop ? '' : Sky.mistLayer}${Sky.rainLayer}
    </svg>`;
    };
    function sky(events) {
        skyEvents = events;
        const lit = events.filter((item) => stage[item.area]);
        // A day that has already happened is drawn with its lights out.
        const past =
            events.length > 0 && events.every((item) => F.past(item, today));
        const first = [...events].sort((a, b) =>
            String(a.start).localeCompare(String(b.start)),
        )[0];
        const date = first?.date || today;
        // Today the sky shows now; another day, the hour its first event
        // starts. Today's lights follow the clock too: over, on now, or
        // still to come.
        const chosen = chosenTime();
        const now = date === today ? Sky.hours(chosen || nowTime) : null;
        const base =
            now ??
            (chosen ? Sky.hours(chosen) : first?.start ? Sky.hours(first.start) : 12);
        const ctx = skyContext(date);
        const st = Sky.at(ctx, base);
        const timing = (item) => {
            if (now === null || !item.start) return '';
            const start = Sky.hours(item.start),
                end = item.end ? Sky.hours(item.end) : null;
            if (end !== null && now >= end) return 'over';
            return now >= start && end !== null ? 'now' : '';
        };
        const forecast =
            ctx.source === 'Sample weather' ? 'sample forecast' : 'forecast';
        const appointment =
            day.appointment && date === today && stage[day.appointment.location]
                ? day.appointment
                : null;
        // Events in the same district fan out around it, to the first spot
        // that isn't already taken by another light.
        const taken = [];
        const spots = [
            [0, 0],
            [6, 12],
            [-6, 12],
            [0, 25],
            [9, -5],
        ];
        const place = (area) => {
            for (const [dx, dy] of [...spots, [-12, 0], [12, 6], [0, -14]]) {
                const x = Math.max(4, Math.min(96, stage[area][0] + dx));
                const y = stage[area][1] + dy;
                if (!taken.some(([tx, ty]) => Math.abs(tx - x) < 4.5 && Math.abs(ty - y) < 9)) {
                    taken.push([x, y]);
                    return [x, y];
                }
            }
            return stage[area];
        };
        const light = (item) => {
            const [x, y] = place(item.area);
            const state = timing(item);
            const out = past || state === 'over';
            // Rain where it matters: an outdoor event, at its hour.
            const wet =
                !out &&
                !!item.start &&
                outdoors(item) &&
                Sky.rainLikely(ctx.weather, item.start);
            const when = state === 'now' ? 'Now' : item.start || '';
            const said = [
                `${item.title}, ${state === 'now' ? `on now until ${item.end}` : item.start || 'time not listed'} at ${item.venue}`,
                past ? 'already happened' : state === 'over' ? 'already over' : '',
                wet ? `rain likely at ${item.start}, ${forecast}` : '',
            ]
                .filter(Boolean)
                .join(', ');
            return `<button type="button" class="sky-light${out ? ' is-out' : ''}${state === 'now' ? ' is-now' : ''}${wet ? ' is-wet' : ''}${far.has(item.area) ? ' is-far' : ''}${x > 78 ? ' is-end' : x < 16 ? ' is-start' : ''}" data-detail="${E(item.id)}"${item.start ? ` data-start="${E(item.start)}"` : ''} style="left:${x}%;top:${y}%" aria-label="${E(said)}"><span class="sky-light-label"><span class="sky-line">${when ? `<span class="sky-when">${E(when)}</span><span class="sky-what"> · ${E(item.title)}</span>` : E(item.title)}</span>${wet ? `<span class="sky-wet">Rain likely at ${E(item.start)} · ${forecast}</span>` : ''}</span><span class="sky-light-dot" aria-hidden="true">${wet ? '<span class="sky-light-drop"></span>' : ''}</span></button>`;
        };
        const title =
            date === today
                ? first && first.start >= '17:00'
                    ? 'Cologne tonight'
                    : 'Cologne today'
                : `Cologne on ${F.dateLabel(date, { weekday: 'short', month: 'short' })}`;
        const point = window.matchMedia?.('(hover: hover)').matches
            ? 'Point at'
            : 'Tap';
        const lead = past
            ? 'These have already happened, so their lights are out.'
            : !lit.length
              ? 'Nothing here is drawn on the skyline.'
              : now !== null
                ? `The sky follows the time and the weather. ${point} a light to see its hour.`
                : `${point} a light to see the sky at its hour.`;
        return `<section class="sky${past ? ' is-past' : ''}"${st.light ? ' data-light' : ''}${st.rain > 0.01 ? ' data-rain' : ''} data-date="${E(date)}" data-base="${base}" style="${Sky.style(st)}" aria-label="${E(title)}, illustrated">
            <div class="sky-head"><div><h2>${E(title)}</h2><p>${E(lead)}</p></div>
            <div class="sky-weather">${Sky.chip(st, ctx)}</div></div>
            <div class="sky-stage"><div class="sky-scroll" tabindex="0" aria-label="Skyline. Drag or scroll sideways along the Rhine."><div class="sky-inner">${drawing(st.moon.phase)}${lit.map(light).join('')}${appointment ? `<span class="sky-you" style="left:${stage[appointment.location][0]}%;top:${stage[appointment.location][1] + 10}%"><span aria-hidden="true"></span>Your ${E(appointment.start)} appointment</span>` : ''}</div></div><span class="sky-fade sky-fade--start" aria-hidden="true"></span><span class="sky-fade sky-fade--end" aria-hidden="true"></span><p class="sky-note">Illustration · places approximate</p></div>
        </section>`;
    }
    // Labels never pile up: the day's events are named in list order; a
    // label that would overlap one already placed shortens to its time, or
    // hides (the light stays, and names itself on hover, focus and to
    // screen readers).
    function layoutSky() {
        document.querySelectorAll('.wall-view--skyline .sky').forEach((sky) => {
            if (!sky.offsetParent) return;
            const lights = [...sky.querySelectorAll('.sky-light')];
            const placed = [
                ...sky.querySelectorAll('.sky-head h2, .sky-head p, .sky-weather'),
            ]
                .map((el) => el.getBoundingClientRect())
                .filter((r) => r.width);
            // A label mustn't cover another light either.
            const dots = lights.map((el) => [
                el,
                el.querySelector('.sky-light-dot').getBoundingClientRect(),
            ]);
            const overlap = (r, p) =>
                r.left < p.right + 6 &&
                p.left < r.right + 6 &&
                r.top < p.bottom + 4 &&
                p.top < r.bottom + 4;
            const hits = (r, own) =>
                placed.some((p) => overlap(r, p)) ||
                dots.some(([el, d]) => el !== own && overlap(r, d));
            for (const el of lights) {
                el.classList.remove('is-short', 'is-quiet');
                const label = el.querySelector('.sky-light-label');
                if (!label || getComputedStyle(label).display === 'none') continue;
                if (hits(label.getBoundingClientRect(), el)) {
                    el.classList.add('is-short');
                    if (hits(label.getBoundingClientRect(), el)) {
                        el.classList.remove('is-short');
                        el.classList.add('is-quiet');
                        continue;
                    }
                }
                placed.push(label.getBoundingClientRect());
            }
        });
    }
    /* ---------- The sky at a light's hour ----------
       Pointing at a light, or focusing it, turns the sky to its hour and
       that hour's weather; the sun and moon travel there. Leaving brings
       the sky back. On a touch screen the first tap does this and the
       second opens the event. */
    const skies = new WeakMap();
    function skyOf(section) {
        let sky = skies.get(section);
        if (!sky) {
            const base = Number(section.dataset.base);
            sky = { ctx: skyContext(section.dataset.date), base, shown: base, frame: null };
            skies.set(section, sky);
        }
        return sky;
    }
    function showHour(section, hour) {
        const sky = skyOf(section);
        sky.shown = hour;
        const st = Sky.at(sky.ctx, hour);
        for (const [key, value] of Object.entries(Sky.vars(st)))
            section.style.setProperty(key, value);
        section.toggleAttribute('data-light', st.light);
        section.toggleAttribute('data-rain', st.rain > 0.01);
        const chip = section.querySelector('.sky-weather');
        if (chip) chip.innerHTML = Sky.chip(st, sky.ctx);
    }
    function turnSky(section, hour) {
        const sky = skyOf(section);
        cancelAnimationFrame(sky.frame);
        const from = sky.shown;
        if (Math.abs(hour - from) < 0.01) return;
        if (reducedMotion()) return showHour(section, hour);
        const start = performance.now();
        const duration = Math.min(900, 320 + Math.abs(hour - from) * 70);
        const tick = (now) => {
            const t = Math.min(1, (now - start) / duration);
            showHour(section, from + (hour - from) * (1 - Math.pow(1 - t, 3)));
            if (t < 1) sky.frame = requestAnimationFrame(tick);
        };
        sky.frame = requestAnimationFrame(tick);
    }
    // A new time of day redraws the sky (today's lights may now be on or
    // over) and glides there from the hour it was showing.
    function redrawSky() {
        document
            .querySelectorAll('.wall-view--skyline .sky')
            .forEach((old) => {
                const from = skyOf(old).shown;
                old.insertAdjacentHTML('afterend', sky(skyEvents));
                const next = old.nextElementSibling;
                old.remove();
                showHour(next, from);
                turnSky(next, skyOf(next).base);
            });
        requestAnimationFrame(() => {
            layoutSky();
            panSky();
        });
    }
    let shownTime = chosenTime();
    new MutationObserver(() => {
        if (chosenTime() === shownTime) return;
        shownTime = chosenTime();
        redrawSky();
        paintStreet();
    }).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-ac-band', 'data-ac-sample'],
    });
    let settleTimer = null;
    function previewLight(light) {
        clearTimeout(settleTimer);
        const section = light.closest('.sky');
        if (section && light.dataset.start)
            turnSky(section, Sky.hours(light.dataset.start));
    }
    // Back to the sky's own hour once no light is pointed at, focused or
    // picked (a short wait, so moving between neighbours doesn't flicker).
    function settleSky(delay = 140) {
        clearTimeout(settleTimer);
        settleTimer = setTimeout(() => {
            document
                .querySelectorAll('.wall-view--skyline .sky')
                .forEach((section) => {
                    if (
                        section.querySelector(
                            '.sky-light:is(:hover, :focus-visible, .is-picked)',
                        )
                    )
                        return;
                    turnSky(section, skyOf(section).base);
                });
        }, delay);
    }
    document.addEventListener('pointerover', (event) => {
        if (event.pointerType !== 'mouse') return;
        const light = event.target.closest?.('.sky-light');
        if (light) previewLight(light);
    });
    document.addEventListener('pointerout', (event) => {
        if (event.pointerType !== 'mouse') return;
        if (
            event.target.closest?.('.sky-light') &&
            !event.relatedTarget?.closest?.('.sky-light')
        )
            settleSky();
    });
    document.addEventListener('focusin', (event) => {
        const light = event.target.closest?.('.sky-light');
        if (light?.matches(':focus-visible')) previewLight(light);
    });
    document.addEventListener('focusout', (event) => {
        if (event.target.closest?.('.sky-light')) settleSky();
    });
    let lastPointer = '';
    function unpick() {
        const picked = document.querySelectorAll('.sky-light.is-picked');
        if (!picked.length) return;
        picked.forEach((el) => el.classList.remove('is-picked'));
        settleSky(0);
    }
    document.addEventListener(
        'pointerdown',
        (event) => {
            lastPointer = event.pointerType;
            if (!event.target.closest?.('.sky-light')) unpick();
        },
        true,
    );
    document.addEventListener(
        'click',
        (event) => {
            const light = event.target.closest?.('.sky-light');
            if (
                !light ||
                !['touch', 'pen'].includes(lastPointer) ||
                light.classList.contains('is-picked')
            )
                return;
            event.preventDefault();
            event.stopImmediatePropagation();
            document
                .querySelectorAll('.sky-light.is-picked')
                .forEach((el) => el.classList.remove('is-picked'));
            light.classList.add('is-picked');
            previewLight(light);
        },
        true,
    );

    // A card keeps its picture when there is one; otherwise the poster's
    // art takes the picture's place, labelled as such.
    function card(item, index, distance) {
        const html = base.card(item, index, false, { distance });
        if (hasPicture(item)) return html;
        return html
            .replace(
                /<img class="event-poster-image"[^>]*>/,
                `${P.art(item, 'pp-art--slot')}<span class="sky-poster-label">Poster from the listing</span>`,
            )
            .replace('class="festival-card', 'class="festival-card is-poster');
    }

    /* ---------- Results ---------- */
    function render(
        items,
        { discover = false, featured: shown = [], distance = () => '' } = {},
    ) {
        const events = unique(items);
        if (!events.length) return '';
        const onWall = discover
            ? events.filter((item) => !shown.includes(item.id))
            : events;
        const posters = onWall.length
            ? `<div class="wall-day">${onWall.map((item, i) => P.poster(item, { size: 'wall', ...posterOptions(item, distance(item)) }).replace('<article ', `<article style="--i:${i}" `)).join('')}</div>`
            : `<p class="wall-day-note">${events.length === 1 ? 'This day’s event is' : 'These events are'} on the poster column.</p>`;
        // The skyline has no column beside it, so its cards are the whole day.
        const pictures = events.filter(hasPicture).length;
        const cards = `<div class="sky-cards">${events.map((item, i) => card(item, i, distance(item))).join('')}</div>`;
        return `<div class="wall-view wall-view--wall">${posters}<p class="festival-credit">Posters are set by Expadu from each listing: title, date, time, place, admission and category. No photo, nothing added. Sample programme.</p></div>
            <div class="wall-view wall-view--skyline">${sky(events)}${cards}<p class="festival-credit">Sample programme.${pictures ? ' AI illustrations depict the activity, not the listed event or venue.' : ''}${pictures < events.length ? ' Where there’s no usable picture, the listing’s poster stands in.' : ''}</p></div>`;
    }

    /* ---------- Next in the city's calendar, and the week's notes ---------- */
    // Public holidays shown beside the programme (Germany, for this preview).
    const holidays = [
        {
            date: '2026-10-03',
            name: 'German Unity Day',
            note: 'A public holiday across Germany: most shops are closed.',
        },
    ];
    const months = [
        'January',
        'February',
        'March',
        'April',
        'May',
        'June',
        'July',
        'August',
        'September',
        'October',
        'November',
        'December',
    ];
    // A captured listing names a later date in the same city series
    // ("City listing for 6 October 2026 in the same series"). That later
    // listing's time and price aren't captured, so they stay unknown.
    function laterListings(catalogue) {
        return unique(catalogue)
            .map((item) => {
                const m = /City listing for (\d{1,2}) (\w+) (\d{4})/.exec(
                    item.aboutSource || '',
                );
                const month = m ? months.indexOf(m[2]) + 1 : 0;
                if (!month) return null;
                const date = `${m[3]}-${String(month).padStart(2, '0')}-${m[1].padStart(2, '0')}`;
                return {
                    id: `${item.id}-listing-${date}`,
                    title: item.title,
                    titleEnglish: item.titleEnglish,
                    venue: item.venue,
                    area: item.area,
                    category: item.category,
                    ages: item.ages,
                    date,
                    aboutSource: item.aboutSource,
                    sourceUrl: item.sourceUrl,
                };
            })
            .filter((item) => item && (!today || item.date >= today))
            .sort((a, b) => a.date.localeCompare(b.date));
    }
    function notes() {
        if (!today) return '';
        const week = F.shift(today, 7);
        return holidays
            .filter((h) => h.date > today && h.date <= week)
            .map((h) => {
                const own = (day.upcoming || []).find((x) => x.date === h.date);
                return `<aside class="wall-note" aria-label="${E(F.dateLabel(h.date, { weekday: 'long' }))}"><span class="wall-note-pin" aria-hidden="true"></span><strong>${E(F.dateLabel(h.date, { weekday: 'short', month: 'short' }))} is ${E(h.name)}.</strong><p>${E(h.note)}${own ? ` Your ${E(own.title)} is at ${E(own.start)}.` : ''}</p></aside>`;
            })
            .join('');
    }
    // The later listings hang on the column; the week's notes stay pinned
    // to the wall.
    function next() {
        const extra = notes();
        return extra ? `<div class="wall-next">${extra}</div>` : '';
    }
    function pastStrip(catalogue) {
        const past = unique(catalogue).filter((item) => F.past(item, today));
        if (!past.length) return '';
        const dates = [...new Set(past.map((item) => item.date))].sort();
        const last = dates.at(-1);
        return `<section class="wall-past" aria-labelledby="wall-past-title"><div class="wall-past-head"><h2 id="wall-past-title">Already taken down</h2><p>${past.length} captured ${past.length === 1 ? 'listing' : 'listings'} from ${E(F.dateLabel(last, { month: 'short' }))}. You can read them, not plan them.</p><button type="button" class="wall-past-open" data-event-date="${E(last)}">See ${E(F.dateLabel(last, { weekday: 'short', month: 'short' }))}</button></div><div class="wall-past-row" aria-hidden="true">${past
            .filter((item) => item.date === last)
            .map(
                (item) =>
                    `<span class="wall-past-poster">${P.art(item)}<span class="wall-past-stamp">Past · ${E(item.start || '')}</span><span class="wall-past-title"${item.titleEnglish ? ' lang="de"' : ''}>${E(item.title)}</span></span>`,
            )
            .join('')}</div></section>`;
    }

    /* ---------- Phone tilt ----------
       Tilt the phone: the wall's posters lean with it, the column leans and
       the skyline's layers shift, the near city more than the far one. It
       follows how you hold the phone, which slowly becomes the new rest.
       Touch screens only, never with reduced motion. Phones that ask for
       motion access (iPhone) start it from the tilt button. */
    const phoneIcon =
        '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="7" y="3" width="10" height="18" rx="2.5"/><path d="M11 18h2M3.5 9a7 7 0 0 0 0 6M20.5 9a7 7 0 0 1 0 6"/></svg>';
    const canTilt = () =>
        typeof window.DeviceOrientationEvent !== 'undefined' &&
        !!window.matchMedia?.('(pointer: coarse)').matches &&
        !reducedMotion();
    const tilt = { on: false, rest: null, frame: null, x: 0, y: 0 };
    const clampUnit = (value) => Math.max(-1, Math.min(1, value));
    function orientation(event) {
        if (event.beta == null || event.gamma == null) return;
        const rest = (tilt.rest ??= { b: event.beta, g: event.gamma });
        rest.b += (event.beta - rest.b) * 0.02;
        rest.g += (event.gamma - rest.g) * 0.02;
        tilt.x = clampUnit((event.gamma - rest.g) / 25);
        tilt.y = clampUnit((event.beta - rest.b) / 25);
        if (tilt.frame) return;
        tilt.frame = requestAnimationFrame(() => {
            tilt.frame = null;
            const style = document.documentElement.style;
            style.setProperty('--phone-x', tilt.x.toFixed(3));
            style.setProperty('--phone-y', tilt.y.toFixed(3));
        });
    }
    function setTilt(on) {
        tilt.on = on;
        tilt.rest = null;
        document.documentElement.classList.toggle('wall-tilting', on);
        if (on) window.addEventListener('deviceorientation', orientation);
        else {
            window.removeEventListener('deviceorientation', orientation);
            document.documentElement.style.removeProperty('--phone-x');
            document.documentElement.style.removeProperty('--phone-y');
        }
        document
            .querySelectorAll('[data-wall-tilt]')
            .forEach((b) => b.setAttribute('aria-pressed', String(on)));
        try {
            localStorage.setItem('expadu-wall-tilt', on ? 'on' : 'off');
        } catch {}
    }
    async function toggleTilt() {
        if (tilt.on) return setTilt(false);
        const ask = window.DeviceOrientationEvent?.requestPermission;
        if (typeof ask === 'function') {
            let answer = 'denied';
            try {
                answer = await ask.call(window.DeviceOrientationEvent);
            } catch {}
            if (answer !== 'granted') {
                U.toast?.('Motion access wasn’t allowed, so the posters stay still.');
                return;
            }
        }
        setTilt(true);
    }

    /* ---------- The street around the column ----------
       The board is a plastered wall with scraps of old posters and a
       stencil; the column stands on a cobbled corner with a lantern, a
       bench, a bike and two pigeons. The street keeps today's time and
       weather, like the skyline: the lantern lights at dusk, the wall
       darkens at night, rain falls and leaves puddles, and the posters
       shine wet. Drawn flat, like the skyline; nothing here is a control. */
    const lantern = `<svg class="wall-lantern wall-scene-art" viewBox="0 0 128 700" aria-hidden="true" focusable="false"><path class="wall-lantern-glass" d="M41 50h46l-8 70H49Z"/><g class="wall-iron"><path d="M44 700l4-58q16-14 32 0l4 58Z"/><rect x="58" y="150" width="12" height="494"/><rect x="51" y="598" width="26" height="11" rx="3"/><rect x="52" y="188" width="24" height="9" rx="3"/><path d="M50 151h28l-5-16H55Z"/><rect x="45" y="120" width="38" height="9" rx="2"/><path d="M63 50h2v70h-2ZM36 44h56l-28-28Z"/><rect x="33" y="43" width="62" height="7" rx="2"/><circle cx="64" cy="12" r="5"/></g></svg>`;
    const bench = `<svg class="wall-bench wall-scene-art" viewBox="0 0 150 66" aria-hidden="true" focusable="false"><g class="wall-wood"><rect x="8" y="4" width="134" height="8" rx="2"/><rect x="8" y="16" width="134" height="8" rx="2"/><rect x="2" y="34" width="146" height="9" rx="2"/></g><g class="wall-iron"><path d="M16 2h7v64h-7ZM127 2h7v64h-7Z"/></g></svg>`;
    const bike = `<svg class="wall-bike wall-scene-art" viewBox="0 0 160 100" aria-hidden="true" focusable="false"><g class="wall-bike-wheels"><circle cx="34" cy="70" r="25"/><circle cx="124" cy="70" r="25"/></g><path class="wall-bike-frame" d="M34 70 72 72 60 34ZM72 72l36-36-48-2M108 36l16 34M104 24l4 12"/><path class="wall-bike-bits" d="M51 31h18M97 23h15"/><rect class="wall-bike-basket" x="113" y="28" width="27" height="17" rx="3"/></svg>`;
    const pigeon = (x, cls = '') =>
        `<g class="wall-pigeon${cls}" transform="translate(${x} 0)"><g class="wall-pigeon-body"><ellipse cx="16" cy="22" rx="12" ry="7.5"/><path d="M5 19 0 15l2 8Z"/><circle class="wall-pigeon-head" cx="27" cy="13" r="5"/><path class="wall-pigeon-beak" d="m31 12 5 2-5 1Z"/></g><path class="wall-pigeon-legs" d="M14 29v5M19 29v5"/></g>`;
    const pigeons = `<svg class="wall-pigeons wall-scene-art" viewBox="0 0 96 36" aria-hidden="true" focusable="false">${pigeon(0)}${pigeon(54, ' is-pecking')}</svg>`;
    // Rain in rows 64 apart that repeat exactly, so it loops without a seam.
    const rain = `<span class="wall-scene-rain" aria-hidden="true"><svg viewBox="0 0 640 1000" preserveAspectRatio="xMinYMin slice" focusable="false"><g class="wall-rain-fall">${Array.from({ length: 22 }, (_, c) => {
        const x = c * 30 + ((c * 37) % 19);
        const dy = (c * 29) % 64;
        return Array.from({ length: 17 }, (_, r) => {
            const y = (r - 1) * 64 + dy;
            return `<line x1="${x}" y1="${y}" x2="${x - 4}" y2="${y + 18}"/>`;
        }).join('');
    }).join('')}</g></svg></span>`;
    // The wall, as a real one: water streaks under the ledge, plaster
    // fallen away to the bricks in two places, a few cracks, torn older
    // posters (white paper showing at the tears, a fragment of their big
    // type), tape, staples and stickers, paste marks where others were, a
    // drainpipe, an enamel house number, a darker stone base and a stencil.
    // Left, top, width, height, turn, colour and a fragment of print.
    const scraps = [
        [57, 0.6, 180, 80, -3, '#e8cf73', 'FES'],
        [95.5, 16, 96, 170, 4, '#bcaed8', ''],
        [38, 47, 140, 70, 7, '#c8d8c0', 'NACH'],
        [97, 62, 120, 96, -7, '#f0c3a6', ''],
        [1.5, 92, 170, 64, 2, '#d9cfbb', 'OPE'],
        [70, 96, 150, 66, -2, '#c9b9e0', 'KON'],
    ];
    const bricks = [
        'left:1%;bottom:72px;width:230px;height:128px',
        'right:5%;top:44%;width:150px;height:100px',
    ];
    const cracks =
        '<svg class="wall-cracks" viewBox="0 0 100 100" preserveAspectRatio="none" focusable="false"><path vector-effect="non-scaling-stroke" d="M61 14l1 3-1 2 1.5 3-.5 2 1 4"/><path vector-effect="non-scaling-stroke" d="M62 19l2 1.5 1 2.5"/><path vector-effect="non-scaling-stroke" d="M88 80l-1 3 1.2 2-.6 3 .8 2.5"/><path vector-effect="non-scaling-stroke" d="M12 55l1.5 2-.5 2.5 1 2"/></svg>';
    const decor = `<div class="wall-decor" aria-hidden="true"><span class="wall-streaks"></span>${bricks
        .map((at) => `<span class="wall-bricks" style="${at}"></span>`)
        .join('')}${cracks}<span class="wall-paste" style="left:46%;top:22%"></span><span class="wall-paste" style="left:82%;top:40%"></span><span class="wall-paste" style="left:24%;top:78%"></span>${scraps
        .map(
            ([left, top, w, h, turn, colour, print], i) =>
                `<span class="wall-scrap" style="left:${left}%;top:${top}%;width:${w}px;height:${h}px;rotate:${turn}deg;--scrap:${colour};--n:${i}" data-print="${print}"><span class="wall-tape"></span></span>`,
        )
        .join('')}<span class="wall-sticker wall-sticker--round" style="left:34%;top:31%"></span><span class="wall-sticker wall-sticker--strip" style="left:91%;top:84%"></span><span class="wall-pipe"></span><span class="wall-number">17</span><span class="wall-sockel"></span><span class="wall-stencil" lang="de">Plakatieren verboten</span></div>`;
    const clampUnit01 = (value) => Math.max(0, Math.min(1, value));
    // Today's street at today's hour (the sample day's clock, ?at=, or the
    // App in colour concept's time switch) and today's weather.
    const streetNow = () => {
        const hour = Sky.hours(chosenTime() || nowTime);
        const ctx = skyContext(today);
        return { hour, ctx, st: Sky.at(ctx, hour) };
    };
    function paintStreet() {
        const board = document.querySelector('.wall-board');
        if (!board || !today) return;
        const { hour, ctx, st } = streetNow();
        // Puddles last a while after the rain.
        const recent = Math.max(
            ...[0, 0.5, 1, 1.5, 2].map(
                (back) => Sky.weatherAt(ctx.weather, Math.max(0, hour - back))?.rain || 0,
            ),
        );
        const dark = clampUnit01((3 - st.height) / 12);
        const lamp = clampUnit01((2 - st.height) / 3);
        const values = {
            '--street-dark': dark,
            '--lamp-on': lamp,
            '--wet': clampUnit01(recent * 1.6),
            '--rain': clampUnit01(st.rain * 1.4),
        };
        for (const [key, value] of Object.entries(values))
            board.style.setProperty(key, value.toFixed(3));
        board.toggleAttribute('data-dark', dark > 0.45);
        board.toggleAttribute('data-wet', recent > 0.05);
        board.toggleAttribute('data-raining', st.rain > 0.05);
        // The backdrop shows the same hour's sky, and says what it shows.
        const backdrop = board.querySelector('.wall-sky');
        if (backdrop) {
            for (const [key, value] of Object.entries(Sky.vars(st)))
                backdrop.style.setProperty(key, value);
            backdrop.toggleAttribute('data-light', st.light);
            backdrop.toggleAttribute('data-rain', st.rain > 0.01);
            backdrop.querySelector('.sky-weather').innerHTML = Sky.chip(st, ctx);
        }
        markPosters();
    }
    // The corner ends where the row of posters beside it ends, so no bare
    // wall is left under it: the column grows taller, its lower part
    // covered in older posters, as a real Litfaßsäule is top to bottom.
    // On a desktop the corner (the column and its street) and the posters
    // beside it scale to the screen's height, so the column shows from its
    // dome to its caption under the header on a laptop as on a large
    // screen; never larger than drawn, never so small the type suffers.
    function fitWall(scene) {
        const board = scene.closest('.wall-board');
        board?.style.removeProperty('--wall-fit');
        if (!board || getComputedStyle(scene).float === 'none') return;
        const header =
            document.querySelector('.app-header')?.offsetHeight || 0;
        const room = window.innerHeight - header - 16;
        // The whole picture: from the wall's top edge, with the sky, down
        // to the caption under the column's pavement.
        // Measured after each step, since the board's own padding and the
        // sky don't scale.
        const caption = scene.querySelector('.wall-column-caption') || scene;
        const span = () =>
            caption.getBoundingClientRect().bottom -
            board.getBoundingClientRect().top;
        let fit = 1;
        for (let step = 0; step < 3; step++) {
            const now = span();
            if (now <= room + 1 || fit <= 0.62) break;
            fit = Math.max(0.62, fit * (room / now));
            board.style.setProperty('--wall-fit', fit.toFixed(3));
        }
    }
    function fitCorner() {
        const scene = document.querySelector('.wall-scene');
        if (!scene) return;
        scene.style.removeProperty('--extra');
        if (!scene.offsetParent) return;
        fitWall(scene);
        if (getComputedStyle(scene).float === 'none') return;
        // Measured on screen; the column's length is set before scaling.
        const zoom = parseFloat(getComputedStyle(scene).zoom) || 1;
        const box = scene.getBoundingClientRect();
        const posters = [...document.querySelectorAll('.wall-view--wall .wall-day > .pp')];
        // The first poster that starts below the corner begins the next row.
        const next = posters.find((pp) => pp.getBoundingClientRect().left < box.right - 2);
        const beside = posters.filter((pp) => pp.getBoundingClientRect().left >= box.right - 2);
        if (!beside.length) return;
        // Offsets ignore the poster's tilt; each poster's own scale (the
        // screen fit) still applies to them.
        const scale = (el) => parseFloat(getComputedStyle(el).zoom) || 1;
        const top = (el) =>
            el.offsetParent.getBoundingClientRect().top + el.offsetTop * scale(el);
        const rowEnd = next
            ? top(next) - 2
            : Math.max(...beside.map((pp) => top(pp) + pp.offsetHeight * scale(pp)));
        // The corner's bottom margin keeps lines away too.
        const margin =
            (parseFloat(getComputedStyle(scene).marginBottom) || 0) * zoom;
        let extra = Math.floor((rowEnd - box.bottom - margin) / zoom);
        // Measured on screen: scaled posters fit more rows beside it.
        if (extra <= 0 || extra * zoom > 900) return;
        // Never let the corner reach into the next row: pull back if it does.
        for (let tries = 0; tries < 3 && extra > 0; tries++) {
            scene.style.setProperty('--extra', `${extra}px`);
            const over = scene.getBoundingClientRect().bottom + margin - rowEnd;
            if (over <= 0) return;
            extra -= Math.ceil(over / zoom) + 1;
        }
        if (extra <= 0) scene.style.removeProperty('--extra');
    }
    // Today's posters follow the clock: on now, or over (no planning then).
    // An outdoor one says when rain is likely at its hour, from the
    // forecast and labelled as such.
    const drop =
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5c-3.2 4.2-5.6 7.2-5.6 10.2a5.6 5.6 0 0 0 11.2 0c0-3-2.4-6-5.6-10.2Z"/></svg>';
    function markPosters() {
        if (!today) return;
        const catalogue = window.ExpaduEventCatalogue?.() || [];
        const { hour: now, ctx } = streetNow();
        const forecast =
            ctx.source === 'Sample weather' ? 'sample forecast' : 'forecast';
        // On a phone the day's posters hang under the street's sky, which is
        // today's: it shows only over today's posters.
        document.querySelector('.wall-board')?.toggleAttribute(
            'data-today-posters',
            [...document.querySelectorAll('.wall-view--wall .wall-day > .pp')].some(
                (card) => catalogue.find((x) => x.id === card.dataset.poster)?.date === today,
            ),
        );
        document.querySelectorAll('#main .pp[data-event-card]').forEach((card) => {
            const open = card.querySelector('.pp-open');
            if (!open) return;
            open.dataset.label ??= open.getAttribute('aria-label');
            card.querySelector('.pp-flags')?.remove();
            delete card.dataset.when;
            open.setAttribute('aria-label', open.dataset.label);
            const item = catalogue.find((x) => x.id === card.dataset.poster);
            if (!item || item.date !== today || !item.start) return;
            const start = Sky.hours(item.start),
                end = item.end ? Sky.hours(item.end) : null;
            const when =
                end === null ? '' : now >= end ? 'over' : now >= start ? 'now' : '';
            const wet =
                when !== 'over' &&
                outdoors(item) &&
                Sky.rainLikely(ctx.weather, item.start);
            if (!when && !wet) return;
            if (when) card.dataset.when = when;
            const flags = [
                when === 'now'
                    ? `<span class="pp-flag pp-flag--now"><span aria-hidden="true"></span>On now · until ${E(item.end)}</span>`
                    : when === 'over'
                      ? '<span class="pp-flag pp-flag--over">Over</span>'
                      : '',
                wet
                    ? `<span class="pp-flag pp-flag--rain">${drop}Rain likely · ${forecast}</span>`
                    : '',
            ].join('');
            // In the top row beside the category, so the poster's own text
            // keeps its room.
            card.querySelector('.pp-top')?.insertAdjacentHTML(
                'beforeend',
                `<span class="pp-flags">${flags}</span>`,
            );
            open.setAttribute(
                'aria-label',
                `${open.dataset.label}. ${when === 'now' ? `On now until ${item.end}. ` : when === 'over' ? 'Already over. ' : ''}${wet ? `Rain likely, ${forecast}.` : ''}`.trim(),
            );
        });
    }

    /* ---------- Page layout ---------- */
    // The shared count line: the Wall's column holds the featured events;
    // the Skyline shows them with the rest of the day.
    let noteSource = '',
        noteWritten = null;
    function syncNote(main) {
        const note = main.querySelector('#event-count-note');
        if (!note) return;
        if (note.textContent !== noteWritten) noteSource = note.textContent;
        const text =
            view === 'skyline'
                ? noteSource.replace(/^\d+ featured above( · )?/, '')
                : noteSource.replace('featured above', 'on the poster column');
        noteWritten = text;
        if (note.textContent !== text) note.textContent = text;
    }
    function mount() {
        const main = document.querySelector('#main');
        const catalogue = window.ExpaduEventCatalogue?.() || [];
        if (!main?.querySelector('.event-gallery')) return;
        main.dataset.view = view;
        const heading = main.querySelector('.events-page-heading');
        heading.classList.add('wall-heading');
        // No page title on screen (owner decision, 9 Oct 2026): the date
        // leads the search row; the posters' credit says how they're set.
        // Tilting the posters is set beside the events count, which stays
        // still while they lean.
        heading.innerHTML = `<h1 class="sr-only">On the wall</h1><div class="wall-heading-tools"></div>`;
        if (canTilt())
            main.querySelector('#reset-event-filters')?.insertAdjacentHTML(
                'beforebegin',
                `<button type="button" class="wall-tilt" data-wall-tilt aria-pressed="false" aria-label="Tilt the posters with your phone" title="Tilt with your phone">${phoneIcon}</button>`,
            );
        const calendar = main.querySelector('.event-calendar');
        heading.querySelector('.wall-heading-tools').prepend(calendar);
        main.querySelector('.event-search-row')?.prepend(heading);
        main.querySelector('.event-browse-heading')?.remove();
        const gallery = main.querySelector('.event-gallery');
        const board = document.createElement('section');
        board.className = 'wall-board';
        board.setAttribute('aria-label', 'Posters');
        // The wall, then the column on its street corner, the day's
        // posters flowing round it, and the week's notes.
        const { ctx: streetCtx, st: streetSky } = streetNow();
        board.innerHTML = `${decor}<div class="sky wall-sky"${streetSky.light ? ' data-light' : ''} style="${Sky.style(streetSky)}" aria-hidden="true">${drawing(streetSky.moon.phase, true)}<div class="sky-weather">${Sky.chip(streetSky, streetCtx)}</div></div>`;
        const scene = document.createElement('div');
        scene.className = 'wall-scene';
        scene.innerHTML = `<span class="wall-scene-glow" aria-hidden="true"></span>${lantern}${bench}`;
        scene.append(main.querySelector('#event-featured'));
        scene.insertAdjacentHTML(
            'beforeend',
            `<span class="wall-scene-ground" aria-hidden="true"><span class="wall-puddle"></span><span class="wall-puddle"></span><span class="wall-puddle"></span></span>${bike}${pigeons}${rain}`,
        );
        board.append(scene, main.querySelector('#event-list'));
        board.insertAdjacentHTML('beforeend', next());
        gallery.append(board);
        paintStreet();
        const sheetApi = window.ExpaduEventSheet;
        if (sheetApi && !sheetApi.wallDressed) {
            const open = sheetApi.open;
            sheetApi.open = (item, ...rest) => {
                const result = open(item, ...rest);
                dressSheet(item);
                return result;
            };
            sheetApi.wallDressed = true;
        }
        const fitSoon = () => requestAnimationFrame(fitCorner);
        fitSoon();
        window.addEventListener('resize', fitSoon);
        document.fonts?.ready.then(fitSoon);
        gallery.insertAdjacentHTML('afterend', pastStrip(catalogue));
        main.addEventListener('click', (event) => {
            const turn = event.target.closest('[data-poster-turn]');
            if (turn) {
                P.turn(turn);
                return;
            }
            if (event.target.closest('[data-wall-tilt]')) {
                toggleTilt();
                return;
            }
        });
        // A turned poster turns back with Escape.
        main.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            const turned = event.target.closest?.('.pp.is-turned');
            if (turned) {
                event.stopPropagation();
                P.turn(turned.querySelector('.pp-turn-back'));
            }
        });
        // Shown below the day only while it isn't the captured past day.
        const list = main.querySelector('#event-list');
        const syncPast = () => {
            const strip = main.querySelector('.wall-past');
            if (strip)
                strip.hidden = !!list.querySelector('.wall-view--wall .pp.is-past');
        };
        new MutationObserver(() => {
            syncPast();
            requestAnimationFrame(() => {
                layoutSky();
                panSky();
            });
        }).observe(list, { childList: true });
        window.addEventListener('resize', layoutSky);
        document.fonts?.ready.then(layoutSky);
        requestAnimationFrame(() => {
            layoutSky();
            panSky();
        });
        const note = main.querySelector('#event-count-note');
        if (note)
            new MutationObserver(() => syncNote(main)).observe(note, {
                childList: true,
                characterData: true,
                subtree: true,
            });
        syncPast();
        syncNote(main);
        let tiltChoice = null;
        try {
            tiltChoice = localStorage.getItem('expadu-wall-tilt');
        } catch {}
        if (
            canTilt() &&
            typeof window.DeviceOrientationEvent?.requestPermission !== 'function' &&
            tiltChoice !== 'off'
        )
            setTilt(true);
        // Your choices show on the posters: Save pins one (CSS), and once
        // it's in your plan for its day it gets a stamp. A sample plan you
        // didn't make isn't stamped.
        const stamps = () => {
            const H = window.ExpaduHandoffs,
                S = window.ExpaduStore;
            if (!H?.dayItems || !S) return;
            const byDate = {};
            main.querySelectorAll('.pp[data-event-card]').forEach((card) => {
                const item = catalogue.find((x) => x.id === card.dataset.poster);
                if (!item?.date) return;
                byDate[item.date] ??= H.dayItems(S.read(), S.studio(), item.date);
                const planned = byDate[item.date].find(
                    (x) =>
                        String(x.id).replace(/^event:/, '') === item.id &&
                        x.planStatus !== 'Sample plan',
                );
                const stamp = !planned
                    ? ''
                    : planned.planStatus === 'Confirmed'
                      ? 'In your plan'
                      : 'Added · not confirmed';
                if (stamp) card.dataset.stamp = stamp;
                else delete card.dataset.stamp;
            });
        };
        new MutationObserver(() => {
            stamps();
            markPosters();
            requestAnimationFrame(fitCorner);
        }).observe(list, { childList: true });
        const featuredBox = main.querySelector('#event-featured');
        if (featuredBox)
            new MutationObserver(markPosters).observe(featuredBox, { childList: true });
        window.addEventListener('expadu:change', stamps);
        window.addEventListener('storage', stamps);
        stamps();
        // With a mouse, a poster on the wall leans towards the pointer and
        // catches the light. Touch and reduced motion keep it still.
        const fine = window.matchMedia?.('(hover: hover) and (pointer: fine)');
        let tiltFrame = null;
        main.addEventListener('pointermove', (event) => {
            if (!fine?.matches || reducedMotion()) return;
            const card = event.target.closest('.wall-day > .pp');
            if (!card) return;
            const box = card.getBoundingClientRect();
            const x = (event.clientX - box.left) / box.width,
                y = (event.clientY - box.top) / box.height;
            cancelAnimationFrame(tiltFrame);
            tiltFrame = requestAnimationFrame(() => {
                card.classList.add('is-tilting');
                card.style.setProperty('--tilt-x', `${((x - 0.5) * 8).toFixed(2)}deg`);
                card.style.setProperty('--tilt-y', `${((0.5 - y) * 8).toFixed(2)}deg`);
                card.style.setProperty('--glare-x', `${(x * 100).toFixed(1)}%`);
                card.style.setProperty('--glare-y', `${(y * 100).toFixed(1)}%`);
            });
        });
        main.addEventListener('pointerout', (event) => {
            const card = event.target.closest('.wall-day > .pp');
            if (!card || card.contains(event.relatedTarget)) return;
            cancelAnimationFrame(tiltFrame);
            card.classList.remove('is-tilting');
            for (const name of ['--tilt-x', '--tilt-y', '--glare-x', '--glare-y'])
                card.style.removeProperty(name);
        });
    }

    /* ---------- "More about it": the sheet as the poster opened up ----------
       On the Events page the shared sheet takes the Wall's look: the
       poster's art with its date tape and big time on the cover, the same
       words as the posters (Add to plan), today's state and the rain
       warning, the fit with your day, unknown facts on one line, and no
       Plan tab for an event that can no longer be planned. Today's pages
       keep the shared sheet as it is. */
    const unknownWords = {
        Admission: 'admission',
        'When to arrive': 'when to arrive',
        Language: 'language',
        'Who it’s for': 'who it’s for',
        Accessibility: 'accessibility',
    };
    const listWords = (words) =>
        words.length < 2
            ? words.join('')
            : `${words.slice(0, -1).join(', ')} and ${words.at(-1)}`;
    function dressSheet(item) {
        const sheet = document.querySelector('.event-sheet');
        if (!sheet || !item || sheet.dataset.wall) return;
        sheet.dataset.wall = 'true';
        const past = F.past(item, today);
        // Today's clock and weather, as on the poster.
        const { hour: now, ctx } = streetNow();
        const forecast = ctx.source === 'Sample weather' ? 'sample forecast' : 'forecast';
        const start = item.start ? Sky.hours(item.start) : null,
            end = item.end ? Sky.hours(item.end) : null;
        const when =
            item.date !== today || start === null || end === null
                ? ''
                : now >= end
                  ? 'over'
                  : now >= start
                    ? 'now'
                    : '';
        const wet =
            item.date === today &&
            when !== 'over' &&
            !!item.start &&
            outdoors(item) &&
            Sky.rainLikely(ctx.weather, item.start);
        const closed = past || when === 'over';

        // The cover: the poster's ink, its date tape and its big time.
        const ticket = sheet.querySelector('.event-ticket');
        if (ticket) {
            ticket.classList.add('is-wall', `pp--${P.ink(item)}`);
            const date = ticket.querySelector(':scope > div:not(.event-ticket-time) strong');
            if (date) date.outerHTML = `<span class="pp-tape">${E(P.tape(item, today))}</span>`;
            // The start big, as on the poster; the end small beneath it.
            const time = ticket.querySelector('.event-ticket-time');
            if (time && item.start)
                time.innerHTML = `<strong>${E(item.start)}</strong><small>${item.end ? `until ${E(item.end)}` : 'End time to check'}${past ? ' · already happened' : ''}</small>`;
        }
        // The same words as the posters.
        sheet
            .querySelectorAll('.place-sheet-footer [data-event-section="plan"]')
            .forEach((button) => {
                const icon = button.querySelector('svg')?.outerHTML || '';
                button.innerHTML = `${icon}Add to plan`;
            });
        const planTab = sheet.querySelector('.place-sheet-tabs [data-event-section="plan"]');
        planTab?.setAttribute('aria-label', 'Add to plan');
        // Nothing to plan once it's over: no Plan tab, no Add to plan, no
        // reminder.
        if (closed) {
            planTab?.remove();
            sheet.querySelector('.event-reminder')?.remove();
            sheet
                .querySelector('.place-sheet-footer [data-event-section="plan"]')
                ?.remove();
        }
        // Today's state, the rain warning and how it fits your day.
        const about = sheet.querySelector('[data-panel="about"] .place-sheet-scroll');
        const fit = !closed && day.appointment ? P.fits(item, day.appointment, today) : '';
        const flags = [
            when === 'now'
                ? `<span class="pp-flag pp-flag--now"><span aria-hidden="true"></span>On now · until ${E(item.end)}</span>`
                : when === 'over'
                  ? '<span class="pp-flag pp-flag--over">Over</span>'
                  : '',
            wet
                ? `<span class="pp-flag pp-flag--rain">${drop}Rain likely at ${E(item.start)} · ${forecast}</span>`
                : '',
        ].join('');
        if (about && (flags || fit))
            about.insertAdjacentHTML(
                'afterbegin',
                `<div class="event-today">${flags ? `<div class="pp-flags">${flags}</div>` : ''}${fit ? `<p class="pp-fit"><span aria-hidden="true"></span>${E(fit)}</p>` : ''}</div>`,
            );
        // What the listing doesn't say goes on one line; what it says stays.
        const know = sheet.querySelector('.event-know');
        if (know) {
            const unknown = [...know.querySelectorAll('dl > div')].filter((row) =>
                row.querySelector('dd.is-unknown'),
            );
            const words = unknown.map(
                (row) => unknownWords[row.querySelector('dt').textContent] || row.querySelector('dt').textContent.toLowerCase(),
            );
            unknown.forEach((row) => row.remove());
            know.querySelector(':scope > p')?.remove();
            if (!know.querySelector('dl > div')) know.querySelector('dl')?.remove();
            if (words.length)
                know.insertAdjacentHTML(
                    'beforeend',
                    `<p class="event-unlisted"><strong>Not listed:</strong> ${E(listWords(words))}.${closed ? '' : ' Check with the organiser before you go.'}</p>`,
                );
        }
    }

    // The event sheet's cover follows the view: the poster's art on the
    // wall, the picture (or the poster in its place) on the skyline.
    const image = (item, eager) =>
        view === 'skyline' && hasPicture(item)
            ? base.image(item, eager)
            : P.art(item, 'pp-art--cover');
    // The sheet names its artwork; say what this one is.
    new MutationObserver(() => {
        const sheet = document.querySelector('.event-sheet');
        if (!sheet || sheet.dataset.wallCredit) return;
        sheet.dataset.wallCredit = 'true';
        const cover = sheet.querySelector('.event-ticket .pp-art');
        if (!cover) return;
        const row = [...sheet.querySelectorAll('.event-source dt')].find(
            (dt) => dt.textContent === 'Artwork',
        );
        if (row)
            row.nextElementSibling.textContent =
                'Poster set by Expadu from the listing, not an official poster';
    }).observe(document.documentElement, { childList: true, subtree: true });

    window.ExpaduEventFestival = { ...base, image, feature, render, bind };
    window.ExpaduEventWall = { mount, laterListings, hasPicture, stage, skyPlace };
    // After app.js has built the page, before its own DOMContentLoaded work.
    document.addEventListener('DOMContentLoaded', mount);
})();
