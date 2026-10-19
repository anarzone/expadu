/* Optional content layer for the restored Today review. Shared actions stay shared. */
(function (root) {
    root.TodayEnrichment = {
        create({ S, I, E, main, getDay, shortDate, saved }) {
            const C = root.TodayContent,
                T = root.TimetableModel;
            let stopId = root.JourneyModel.capture.boardingStop.id;
            try {
                const stored = localStorage.getItem('expadu-today-stop');
                if (T.stops.some((stop) => stop.id === stored)) stopId = stored;
            } catch {
                /* The stop can still be changed in this visit. */
            }
            // Each line as Travel's Nearby draws it, a strip through the stop
            // (departures-next/strip.js): the next departure each way, in
            // minutes from the board's time, and a tap opens the stop in
            // Travel (owner, 18 Oct 2026, Composer review canvas board 12).
            // Today's column shows three lines at most; the next twelve
            // departures find them and their two ways.
            function boardRows() {
                const board = C.board(T, stopId, 12);
                const L = root.TravelLabels,
                    St = root.TravelStrip;
                const at = (date, time) => new Date(`${date}T${time}:00`).getTime();
                const minutes = (row) =>
                    Math.round(
                        (at(row.date, row.time) - at(board.date, board.time)) /
                            60000,
                    );
                const href = `${S.routes.departures}?view=timetable&stop=${encodeURIComponent(stopId)}`;
                const lines = [];
                for (const row of board.rows) {
                    let line = lines.find((item) => item.line === row.line);
                    if (!line) {
                        if (lines.length === 3) continue;
                        const mode =
                            (row.type || board.stop.mode) === 'bus'
                                ? 'bus'
                                : 'tram';
                        line = { line: row.line, mode, look: L.look(row.line, mode), ways: [] };
                        lines.push(line);
                    }
                    if (
                        line.ways.length < 2 &&
                        !line.ways.some((way) => way.destination === row.destination)
                    )
                        line.ways.push(row);
                }
                const way = (row) =>
                    row && {
                        dest: plain(row.destination),
                        when: `${St.time(St.when(minutes(row), row.time))}${row.delayed ? L.chip('attention', 'clock', 'Delay recorded', { size: 'sm' }) : ''}`,
                    };
                const name = (row) => {
                    const t = St.when(minutes(row), row.time);
                    return `${plain(row.destination)} ${t.big}${t.unit ? ` ${t.unit}` : ''}`;
                };
                return `<p class="board-capture">${board.sample ? 'Sample day' : `Captured ${E(shortDate(board.date))}`} · ${board.time}<span>Not live</span></p>
                    <div class="departure-strips">${lines
                        .map((line) =>
                            St.line({
                                tag: 'a',
                                attrs: `href="${href}" aria-label="${E(`${line.mode === 'bus' ? 'Bus' : 'Tram'} ${line.line}: ${line.ways.map(name).join(', ')}. Open in Travel`)}"`,
                                look: line.look,
                                badge: L.line(line.line, line.look, { kind: line.mode }),
                                first: way(line.ways[0]),
                                second: way(line.ways[1]),
                                empty: 'Other way: see Travel',
                            }),
                        )
                        .join('')}</div>
                    <a class="board-full" href="${href}">Full timetable</a>`;
            }
            // Stops as Travel shows them: "Neumarkt", not "Köln Neumarkt".
            const plain = (name = '') => name.replace(/^Köln\s+/, '');
            const stopName = (id) =>
                plain(T.stops.find((stop) => stop.id === id)?.name);
            // Custom stop menu in the app's style; the native select opened an
            // operating-system menu that did not match the page.
            function stopPicker() {
                return `<div class="stop-picker" data-stop-picker>
                    <button type="button" class="stop-trigger" data-stop-trigger aria-haspopup="listbox" aria-expanded="false" aria-controls="today-stop-list" aria-label="Departure stop: ${E(stopName(stopId))}">${I('train')}<span data-stop-label>${E(stopName(stopId))}</span>${I('down')}</button>
                    <ul class="stop-list" id="today-stop-list" role="listbox" aria-label="Departure stop" hidden>${T.stops
                        .map(
                            (stop) =>
                                `<li role="option" id="today-stop-${E(stop.id)}" data-stop-option="${E(stop.id)}" aria-selected="${stop.id === stopId}" tabindex="-1"><span>${E(plain(stop.name))}</span>${I('check')}</li>`,
                        )
                        .join('')}</ul>
                </div>`;
            }
            function cityHelp() {
                return `<section class="city-help" aria-labelledby="city-help-title"><div class="section-head"><h2 id="city-help-title">${E(getDay().city)} today</h2></div>
                    <button class="forecast-strip" data-today-weather aria-label="Open the sample ${E(getDay().city)} forecast">${I('sun')}<b>21°</b><span>17:00 <strong>20°</strong></span><small>Sample forecast</small></button>
                    <details class="departure-board" ${matchMedia('(min-width: 701px)').matches && C.priority(getDay()).active.length ? 'open' : ''}><summary id="departure-board-title">${I('train')}<span>Check departures</span>${I('down')}</summary><div class="board-content">
                        ${stopPicker()}
                        <div id="departure-board-rows">${boardRows()}</div><span id="stop-feedback" class="sr-only" role="status"></span>
                    </div></details></section>`;
            }
            // Everyday places listed as open near your next plan, when it
            // ends: pharmacies, bakeries, cafés and groceries with known hours
            // (connected/places.js). Nothing shows without listed hours.
            const anchorFor = {
                mülheim: 'wiener-platz',
                neumarkt: 'neumarkt',
                ehrenfeld: 'ehrenfeld',
                rheinauhafen: 'rheinauhafen',
                stadtgarten: 'stadtgarten',
            };
            function nearbyPlaces() {
                const P = root.ExpaduPlaces,
                    day = getDay();
                if (!P || !day) return null;
                const minutes = root.TodayNext.minutes;
                const now = minutes(day.time);
                const plan = day.agenda.find(
                    (item) =>
                        !item.completed &&
                        anchorFor[String(item.location || '').toLowerCase()] &&
                        minutes(item.end || item.start) >= now,
                );
                if (!plan) return null;
                const from = P.anchors[anchorFor[plan.location.toLowerCase()]];
                const time =
                    plan.end && minutes(plan.end) > now ? plan.end : day.time;
                const at = { date: day.date, time };
                const picks = ['bakery', 'cafe', 'pharmacy', 'grocery']
                    .map(
                        (kind) =>
                            P.near(from, {
                                kinds: [kind],
                                limit: 1,
                                at,
                                open: true,
                            })[0],
                    )
                    .filter((pick) => pick && pick.km <= 1)
                    .slice(0, 3);
                if (!picks.length) return null;
                return {
                    plan,
                    html: `<div class="around-places"><p class="around-places-title">Open near ${E(from.name.split(',')[0])} at ${E(time)}<small>Listed hours</small></p>${picks.map(({ place }) => P.card(place, { from, at, variant: 'line' })).join('')}<a class="around-places-map" href="${E(`${P.mapLink('', from)}&time=${time}`)}">${I('pin')}More nearby on the map</a><p class="around-places-source">${E(P.credit || '')}</p></div>`,
                };
            }
            function around(content) {
                const suggestion = C.around(getDay(), content.saved);
                const places = nearbyPlaces();
                if (!suggestion)
                    return places
                        ? `<section class="around-plan" aria-labelledby="around-plan-title"><div class="around-heading"><h3 id="around-plan-title">Near ${E(places.plan.title)}</h3><small>Sample places</small></div><p class="around-context">Today · ${E(places.plan.start)}–${E(places.plan.end)}</p>${places.html}</section>`
                        : '';
                const { item, anchor, saved: kept } = suggestion;
                const key = E(root.TodayContinuity.key(item));
                const open = kept
                    ? `data-today-kept="${key}"`
                    : `data-today-event="${E(item.id)}"`;
                const plan = kept
                    ? `data-today-kept-plan="${key}"`
                    : `data-today-plan="${E(item.id)}"`;
                const dayLabel =
                    anchor.date === getDay().date
                        ? 'Today'
                        : shortDate(anchor.date);
                return `<section class="around-plan" aria-labelledby="around-plan-title"><div class="around-heading"><h3 id="around-plan-title">Also in ${E(anchor.location)}</h3><small>${kept ? 'From your saved ideas' : 'Sample idea'}</small></div>
                    <p class="around-context">${E(dayLabel)} · ${E(anchor.title)}</p><div class="around-item"><button class="around-open" ${open}>${item.type === 'event' ? `<span class="event-thumb pp--${ExpaduPosterArt.ink(item)}">${ExpaduPosterArt.art(item, 'pp-art--slot')}${I(ExpaduPosterArt.icon(item.category))}</span>` : `<span class="around-symbol">${I('pin')}</span>`}<span><strong>${E(item.title)}</strong><small>${item.type === 'event' ? `${E(item.start)}–${E(item.end || 'end TBC')}` : 'No time set'} · ${E(item.area)}</small></span></button><button class="icon-button" ${plan} aria-label="Plan ${E(item.title)}">${I('plus')}</button></div>${places ? places.html : ''}</section>`;
            }
            function compactEvents(items) {
                if (!items.length) return '';
                return `<div class="compact-events" aria-label="More sample events">${items
                    .map(
                        (item) => `<article class="compact-event">
                    <button class="compact-event-open" data-today-event="${E(item.id)}" aria-label="Explore ${E(item.title)}"><span class="event-thumb pp--${ExpaduPosterArt.ink(item)}">${ExpaduPosterArt.art(item, 'pp-art--slot')}${I(ExpaduPosterArt.icon(item.category))}</span><span><strong>${E(item.title)}</strong><small><time datetime="${item.date}T${item.start}">${item.start}</time> · ${E(item.area)}</small></span><span class="compact-category">${E(item.category)}</span></button>
                    <button class="icon-button compact-save" data-today-save="${E(item.id)}" aria-pressed="${saved(item)}" aria-label="${saved(item) ? 'Unsave' : 'Save'} ${E(item.title)}">${I('bookmark')}</button><button class="icon-button compact-plan" data-today-plan="${E(item.id)}" aria-label="Plan to go to ${E(item.title)}">${I('plus')}</button>
                </article>`,
                    )
                    .join(
                        '',
                    )}</div><p class="discovery-sample">30 Sept · Sample programme</p>`;
            }
            function excerpt(composer) {
                if (!composer.excerpt) return '';
                return `<details class="draft-preview"><summary><span>${E(composer.excerpt)}</span><small>Read draft</small></summary><p>${E(composer.excerpt)}</p></details>`;
            }
            function cityUpdates() {
                const day = getDay(),
                    sources = C.citySources[day.city] || [];
                if (!sources.length) return '';
                const updates = C.updates(
                    root.ExpaduCityUpdates,
                    day.city,
                    day.date,
                );
                return `<section class="city-source-section" aria-labelledby="city-updates-title"><div class="continue-head"><h3 id="city-updates-title">${updates.length ? 'City updates' : 'Useful city links'}</h3><span class="record-label">${'Official websites'}</span></div>
                    ${updates.length ? updates.map((item) => `<a class="city-update" href="${E(item.url)}" target="_blank" rel="noopener noreferrer"><strong>${E(item.title)}</strong><small>${E(item.source)} · ${E(shortDate(item.date))}</small></a>`).join('') : `<div class="city-source-links">${sources.map((source) => `<a href="${E(source.url)}" target="_blank" rel="noopener noreferrer">${I(source.icon)}${E(source.name)}<span class="sr-only"> (official website, opens in a new tab)</span></a>`).join('')}</div>`}</section>`;
            }
            function selectStop(id) {
                if (!C.board(T, id)) return;
                stopId = id;
                try {
                    localStorage.setItem('expadu-today-stop', stopId);
                } catch {
                    /* In-memory selection remains usable. */
                }
                const picker = main.querySelector('[data-stop-picker]');
                picker.querySelector('[data-stop-label]').textContent =
                    stopName(id);
                picker
                    .querySelector('[data-stop-trigger]')
                    .setAttribute(
                        'aria-label',
                        `Departure stop: ${stopName(id)}`,
                    );
                picker
                    .querySelectorAll('[data-stop-option]')
                    .forEach((option) =>
                        option.setAttribute(
                            'aria-selected',
                            String(option.dataset.stopOption === id),
                        ),
                    );
                main.querySelector('#departure-board-rows').innerHTML =
                    boardRows();
                main.querySelector('#stop-feedback').textContent =
                    `Showing ${C.board(T, stopId).sample ? 'sample-day' : 'captured'} departures from ${plain(C.board(T, stopId).stop.name)}.`;
            }
            function setMenu(open, focusSelected = false) {
                const picker = main.querySelector('[data-stop-picker]');
                if (!picker) return;
                const trigger = picker.querySelector('[data-stop-trigger]');
                const list = picker.querySelector('.stop-list');
                if (list.hidden === !open) return;
                list.hidden = !open;
                trigger.setAttribute('aria-expanded', String(open));
                picker.classList.toggle('is-open', open);
                if (open && focusSelected)
                    (
                        list.querySelector('[aria-selected="true"]') ||
                        list.firstElementChild
                    ).focus({ preventScroll: true });
            }
            main.addEventListener('click', (event) => {
                const place = event.target.closest('[data-place-open]');
                if (place && root.ExpaduPlaces && root.ExpaduPlaceSheet) {
                    const found = root.ExpaduPlaces.byId(
                        place.dataset.placeOpen,
                    );
                    if (found)
                        root.ExpaduPlaceSheet.open(
                            root.ExpaduPlaces.item(found),
                        );
                    return;
                }
                const trigger = event.target.closest('[data-stop-trigger]');
                if (trigger) {
                    setMenu(
                        trigger.getAttribute('aria-expanded') !== 'true',
                        true,
                    );
                    return;
                }
                const option = event.target.closest('[data-stop-option]');
                if (option) {
                    selectStop(option.dataset.stopOption);
                    setMenu(false);
                    main.querySelector('[data-stop-trigger]').focus({
                        preventScroll: true,
                    });
                }
            });
            main.addEventListener('keydown', (event) => {
                const trigger = event.target.closest('[data-stop-trigger]');
                if (trigger && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
                    event.preventDefault();
                    setMenu(true, true);
                    return;
                }
                const option = event.target.closest('[data-stop-option]');
                if (!option) return;
                const options = [
                    ...option.parentElement.querySelectorAll(
                        '[data-stop-option]',
                    ),
                ];
                const index = options.indexOf(option);
                const move = {
                    ArrowDown: Math.min(options.length - 1, index + 1),
                    ArrowUp: Math.max(0, index - 1),
                    Home: 0,
                    End: options.length - 1,
                }[event.key];
                if (move !== undefined) {
                    event.preventDefault();
                    options[move].focus({ preventScroll: true });
                } else if (['Enter', ' '].includes(event.key)) {
                    event.preventDefault();
                    option.click();
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    setMenu(false);
                    main.querySelector('[data-stop-trigger]').focus({
                        preventScroll: true,
                    });
                } else if (event.key === 'Tab') setMenu(false);
            });
            document.addEventListener('pointerdown', (event) => {
                if (!event.target.closest('[data-stop-picker]')) setMenu(false);
            });
            function refresh(content) {
                const slot = main.querySelector('#around-plan-slot');
                if (slot) {
                    const markup = around(content);
                    if (slot.innerHTML !== markup) slot.innerHTML = markup;
                }
            }
            const mobile = matchMedia('(max-width: 700px)');
            let layoutPriority = null;
            function arrange(priority = layoutPriority) {
                if (!priority) return;
                layoutPriority = priority;
                const canvas = main.querySelector('.daily-canvas');
                if (!canvas) return;
                const left = canvas.querySelector('.day-area'),
                    right = canvas.querySelector('.next-area');
                const selectors = {
                    preparation: '.preparation-panel',
                    agenda: '.day-panel',
                    essentials: '.practical-surface',
                    around: '#around-plan-slot',
                    discovery: '.discovery',
                    saved: '.saved-ideas',
                    upcoming: '.coming-up',
                    composer: '#personal-continuity-slot',
                    support: '#support-continuity-slot',
                };
                const blocks = Object.fromEntries(
                    Object.entries(selectors).map(([key, selector]) => [
                        key,
                        main.querySelector(selector),
                    ]),
                );
                // Move the original elements so reading, keyboard and visual order agree.
                // This runs on explicit page actions / breakpoint changes, never on a clock or save sync.
                if (mobile.matches) {
                    priority.order.forEach((key) => {
                        if (blocks[key]) canvas.append(blocks[key]);
                    });
                    blocks.essentials?.prepend(
                        main.querySelector('.city-help'),
                    );
                } else {
                    [
                        'preparation',
                        'agenda',
                        'around',
                        'saved',
                        'upcoming',
                        'composer',
                    ].forEach((key) => {
                        if (blocks[key]) left.append(blocks[key]);
                    });
                    // A free day leads with ideas; otherwise practical help first.
                    (priority.lead === 'discovery'
                        ? ['discovery', 'essentials', 'support']
                        : ['essentials', 'discovery', 'support']
                    ).forEach((key) => {
                        if (blocks[key]) right.append(blocks[key]);
                    });
                    blocks.essentials?.append(main.querySelector('.city-help'));
                    if (priority.lead === 'essentials') {
                        left.prepend(blocks.essentials);
                        blocks.essentials.prepend(
                            main.querySelector('.city-help'),
                        );
                    }
                }
            }
            mobile.addEventListener('change', () => {
                const focus = document.activeElement;
                arrange();
                if (focus && main.contains(focus))
                    focus.focus({ preventScroll: true });
            });
            return {
                cityHelp,
                around,
                compactEvents,
                excerpt,
                cityUpdates,
                refresh,
                arrange,
            };
        },
    };
})(globalThis);
