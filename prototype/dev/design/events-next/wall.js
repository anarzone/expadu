/* Events as a poster wall (owner's choice, 8 October): every event is a
   poster set from its listing. A second view, Skyline, lights the day's
   events above Cologne and keeps the photo cards, with a poster wherever
   there's no usable picture. Filters, dates, details, saving and planning
   are the Events page's own (app.js); this file only swaps
   how events are drawn. Loaded after festival.js and before app.js. */
(function () {
    const U = ExpaduUI,
        E = U.esc,
        I = U.icon,
        F = ExpaduEvents,
        P = ExpaduPosterArt;
    const base = window.ExpaduEventFestival;
    const day = window.ExpaduSampleDay || {};
    const today = day.date || '';
    const views = ['wall', 'skyline'];
    let view = new URLSearchParams(location.search).get('view');
    try {
        if (!views.includes(view))
            view = sessionStorage.getItem('expadu-event-view');
    } catch {}
    if (!views.includes(view)) view = 'wall';

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
       It turns like a real Litfaßsäule: drag or swipe sideways, the arrows
       or the arrow keys. Only the poster facing you can be used. */
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
            <div class="wall-column-body"${many ? ' tabindex="0" aria-label="Poster column. Drag sideways or use the arrow keys to turn it."' : ''}>
                ${posters.map((poster, i) => `<div class="wall-column-face" role="group" aria-roledescription="poster" aria-label="${i + 1} of ${posters.length}">${poster}</div>`).join('')}
                <span class="wall-column-glow" aria-hidden="true"></span>
            </div>
            <span class="wall-column-base" aria-hidden="true"></span>
            ${many ? `<div class="wall-column-controls"><button type="button" data-column-step="-1" aria-label="Turn to the previous poster">${I('back')}</button><span class="wall-column-count" aria-live="polite">1 / ${posters.length}</span><button type="button" data-column-step="1" aria-label="Turn to the next poster">${I('arrow')}</button></div>` : ''}
            <p class="wall-column-caption">Coming up${many ? ' · turn the column' : ' · on the poster column'}</p>
        </div>`;
    }
    // How much each column poster faces you (0–1), for the skyline's lights.
    const facing = new Map();
    function applyFacing() {
        document.querySelectorAll('[data-poster-id]').forEach((el) => {
            const value = facing.get(el.dataset.posterId) || 0;
            el.style.setProperty('--facing', value.toFixed(3));
            el.classList.toggle('is-facing', value > 0.5);
        });
    }
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
                facing.set(face.querySelector('.pp')?.dataset.poster, Math.max(0, cos));
            });
            applyFacing();
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
            if (counter) counter.textContent = `${current + 1} / ${count}`;
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
        // A light on the skyline turns the column to its poster, the short
        // way round, and brings the column into view first if it's away.
        document.addEventListener('click', (event) => {
            const light = event.target.closest('[data-column-to]');
            if (!light) return;
            const box = column.getBoundingClientRect();
            if (box.bottom < 80 || box.top > window.innerHeight - 80)
                column.scrollIntoView({
                    block: 'center',
                    behavior: reducedMotion() ? 'instant' : 'smooth',
                });
            const from = Math.round(pos);
            animateTo(from + wrap(Number(light.dataset.columnTo) - from), 420);
        });
        column.addEventListener('click', (event) => {
            const button = event.target.closest('[data-column-step]');
            if (button) go(Number(button.dataset.columnStep));
        });
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
                pos = drag.pos - dx / ((body.clientWidth / 2) * step);
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

    /* ---------- Skyline: the day's events lit above the city ---------- */
    // Where a district sits in the illustration (x and y in % of the
    // drawing). Districts that aren't drawn are named at the edge.
    const stage = {
        'Neustadt-Süd': [8.5, 40],
        'Altstadt-Süd': [24, 62],
        'Altstadt-Nord': [72, 50],
        'Neustadt-Nord': [55.2, 32],
        Deutz: [79, 70],
        Riehl: [86, 58],
        Mülheim: [95, 70],
    };
    const offstage = {
        Chorweiler: 'Further north',
        Junkersdorf: 'Further west',
    };
    const band = (item) =>
        !item.start
            ? 'evening'
            : item.start < '17:00'
              ? 'afternoon'
              : item.start < '21:00'
                ? 'evening'
                : 'late';
    const skyline = `<svg class="sky-art" viewBox="0 0 1376 560" aria-hidden="true" focusable="false">
        <rect class="sky-1" width="1376" height="560"/><rect class="sky-2" y="130" width="1376" height="110"/><rect class="sky-3" y="230" width="1376" height="80"/><rect class="sky-4" y="300" width="1376" height="56"/><rect class="sky-5" y="346" width="1376" height="58"/>
        <g class="sky-stars"><circle cx="90" cy="40" r="1.6"/><circle cx="240" cy="74" r="1.2"/><circle cx="420" cy="30" r="1.8"/><circle cx="560" cy="92" r="1.2"/><circle cx="700" cy="48" r="1.5"/><circle cx="980" cy="28" r="1.3"/><circle cx="1080" cy="82" r="1.7"/><circle cx="1310" cy="40" r="1.4"/><circle cx="350" cy="112" r="1"/><circle cx="820" cy="104" r="1.2"/><circle cx="160" cy="120" r="1"/><circle cx="620" cy="22" r="1.1"/></g>
        <circle class="sky-orb-halo" cx="1062" cy="176" r="48"/><circle class="sky-orb" cx="1062" cy="176" r="30"/>
        <g class="sky-far"><rect x="0" y="352" width="70" height="50"/><rect x="440" y="330" width="60" height="72"/><rect x="520" y="342" width="70" height="60"/><rect x="700" y="336" width="90" height="66"/><rect x="1040" y="344" width="80" height="58"/><rect x="1180" y="330" width="70" height="72"/><rect x="756" y="150" width="8" height="252"/><ellipse cx="760" cy="192" rx="24" ry="10"/><rect x="758.5" y="104" width="3" height="48"/></g>
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
        <rect class="sky-river" y="406" width="1376" height="154"/>
        <path class="sky-bridge" d="M1012 402 Q1072 330 1134 402 Q1196 330 1258 402 Q1318 330 1376 402"/><rect class="sky-bridge-deck" x="1012" y="396" width="364" height="8"/>
    </svg>`;
    function sky(events) {
        const column = columnItems(window.ExpaduEventCatalogue?.() || []);
        const onColumn = new Map(column.map((item, i) => [item.id, i]));
        const isToday = new Set(events.map((item) => item.id));
        const all = unique([...events, ...column]);
        const lit = all.filter((item) => stage[item.area]);
        const away = all.filter(
            (item) => !stage[item.area] && offstage[item.area],
        );
        // A light for a column poster turns the column; any other opens
        // the event.
        const target = (item) =>
            onColumn.has(item.id)
                ? `data-column-to="${onColumn.get(item.id)}" data-poster-id="${E(item.id)}"`
                : `data-detail="${E(item.id)}"`;
        const when = (item) =>
            isToday.has(item.id)
                ? item.start || ''
                : F.dateLabel(item.date, { weekday: 'short', month: 'short' });
        const first = [...events].sort((a, b) =>
            String(a.start).localeCompare(String(b.start)),
        )[0];
        const now = first ? band(first) : 'evening';
        const date = first?.date || today;
        const appointment =
            day.appointment && date === today && stage[day.appointment.location]
                ? day.appointment
                : null;
        // A second event in the same district sits a step lower.
        const used = {};
        const light = (item) => {
            const step = (used[item.area] = (used[item.area] ?? -1) + 1);
            const [x, y] = [
                stage[item.area][0] - step * 3,
                stage[item.area][1] + step * 12,
            ];
            const coming = !isToday.has(item.id);
            return `<button type="button" class="sky-light${coming ? ' is-coming' : ''}${x > 78 ? ' is-end' : x < 16 ? ' is-start' : ''}" ${target(item)} data-band="${band(item)}" style="left:${x}%;top:${y}%" aria-label="${onColumn.has(item.id) ? 'Turn the column to ' : ''}${E(item.title)}, ${E(when(item) || 'time not listed')} at ${E(item.venue)}"><span class="sky-light-label">${when(item) ? `<span class="sky-when">${E(when(item))}</span><span class="sky-what"> · ${E(item.title)}</span>` : E(item.title)}</span><span class="sky-light-dot" aria-hidden="true"></span></button>`;
        };
        const title =
            date === today
                ? first && first.start >= '17:00'
                    ? 'Cologne tonight'
                    : 'Cologne today'
                : `Cologne on ${F.dateLabel(date, { weekday: 'short', month: 'short' })}`;
        return `<section class="sky" data-sky="${now}" aria-label="${E(title)}, illustrated">
            <div class="sky-head"><div><h2>${E(title)}</h2><p>${onColumn.size > 1 ? 'Turn the column and its event lights up.' : lit.length ? 'Tap a light to see what’s on there.' : 'Nothing here is drawn on the skyline.'}</p></div>
            <div class="sky-bands" role="group" aria-label="Light the city for">${[
                ['afternoon', 'Afternoon'],
                ['evening', 'Evening'],
                ['late', 'Late'],
            ]
                .map(
                    ([id, label]) =>
                        `<button type="button" data-sky-band="${id}" aria-pressed="${id === now}">${label}</button>`,
                )
                .join('')}</div></div>
            <div class="sky-scroll" tabindex="0" aria-label="Skyline. Scroll sideways along the Rhine."><div class="sky-inner">${skyline}${lit.map(light).join('')}${appointment ? `<span class="sky-you" style="left:${stage[appointment.location][0]}%;top:${stage[appointment.location][1] + 8}%"><span aria-hidden="true"></span>Your ${E(appointment.start)} appointment</span>` : ''}</div></div>
            ${away.length ? `<div class="sky-away">${away.map((item) => `<button type="button" ${target(item)}>${E(offstage[item.area])}: ${E(item.title)} · ${E(when(item))}${I('arrow')}</button>`).join('')}</div>` : ''}
            <p class="sky-note">Illustration · places approximate</p>
        </section>`;
    }
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
        const pictures = onWall.filter(hasPicture).length;
        const cards = onWall.length
            ? `<div class="sky-cards">${onWall.map((item, i) => card(item, i, distance(item))).join('')}</div>`
            : '';
        return `<div class="wall-view wall-view--wall">${posters}<p class="festival-credit">Posters are set by Expadu from each listing: title, date, time, place, admission and category. No photo, nothing added. Sample programme.</p></div>
            <div class="wall-view wall-view--skyline">${sky(events)}${cards}<p class="festival-credit">Sample programme.${pictures ? ' AI illustrations depict the activity, not the listed event or venue.' : ''}${pictures < onWall.length ? ' Where there’s no usable picture, the listing’s poster stands in.' : ''}</p></div>`;
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

    /* ---------- Page layout and the view switch ---------- */
    function setView(next, main) {
        view = next;
        main.dataset.view = view;
        main.querySelectorAll('[data-wall-view]').forEach((b) =>
            b.setAttribute('aria-pressed', String(b.dataset.wallView === view)),
        );
        try {
            sessionStorage.setItem('expadu-event-view', view);
        } catch {}
        const url = new URL(location.href);
        if (view === 'wall') url.searchParams.delete('view');
        else url.searchParams.set('view', view);
        history.replaceState(history.state, '', url);
        syncNote(main);
    }
    // The shared count line says what the column holds in each view.
    let noteSource = '',
        noteWritten = null;
    function syncNote(main) {
        const note = main.querySelector('#event-count-note');
        if (!note) return;
        if (note.textContent !== noteWritten) noteSource = note.textContent;
        const text = noteSource.replace('featured above', 'on the poster column');
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
        heading.innerHTML = `<div class="wall-heading-text"><h1>On the wall</h1><p>Every poster is set from its listing. Nothing added.</p></div><div class="wall-heading-tools"><div class="wall-views" role="group" aria-label="Show events as">${[
            ['wall', 'Wall', 'grid'],
            ['skyline', 'Skyline', 'pin'],
        ]
            .map(
                ([id, label, icon]) =>
                    `<button type="button" data-wall-view="${id}" aria-pressed="${id === view}">${I(icon)}<span>${label}</span></button>`,
            )
            .join('')}</div>${canTilt() ? `<button type="button" class="wall-tilt" data-wall-tilt aria-pressed="false" aria-label="Tilt the posters with your phone" title="Tilt with your phone">${phoneIcon}</button>` : ''}</div>`;
        const calendar = main.querySelector('.event-calendar');
        heading.querySelector('.wall-heading-tools').prepend(calendar);
        main.querySelector('.event-browse-heading')?.remove();
        const gallery = main.querySelector('.event-gallery');
        const board = document.createElement('section');
        board.className = 'wall-board';
        board.setAttribute('aria-label', 'Posters');
        board.append(
            main.querySelector('#event-featured'),
            main.querySelector('#event-list'),
        );
        board.insertAdjacentHTML('beforeend', next());
        gallery.append(board);
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
            const viewButton = event.target.closest('[data-wall-view]');
            if (viewButton) {
                setView(viewButton.dataset.wallView, main);
                return;
            }
            const bandButton = event.target.closest('[data-sky-band]');
            if (bandButton) {
                const sky = bandButton.closest('.sky');
                sky.dataset.sky = bandButton.dataset.skyBand;
                sky.querySelectorAll('[data-sky-band]').forEach((b) =>
                    b.setAttribute('aria-pressed', String(b === bandButton)),
                );
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
            applyFacing();
        }).observe(list, { childList: true });
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
        new MutationObserver(stamps).observe(list, { childList: true });
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
    window.ExpaduEventWall = { mount, laterListings, hasPicture, band, stage };
    // After app.js has built the page, before its own DOMContentLoaded work.
    document.addEventListener('DOMContentLoaded', mount);
})();
