/* The trip page (owner, 15 Oct 2026; Travel directions canvas rows 19–22).
   One page before and after "I'm on my way": before, it is the trip's first
   moment with the button; tap it and the same page follows you by itself.
   Starting it never changes a proposal. */
(function (root) {
    const J = root.JourneyModel,
        N = root.JourneyNavigationModel,
        S = root.JourneySession;
    const dialog = document.createElement('dialog');
    dialog.id = 'journey-navigation';
    dialog.setAttribute('aria-labelledby', 'nav-title');
    document.body.append(dialog);
    let route,
        active = N.restore(S.read());
    let returnFocus,
        previousOverflow,
        closing = false,
        settings = false,
        confirmation = '';
    // The approved label set (labels.js), when the page has it.
    const L = root.TravelLabels;
    // Desktops keep the trip in Travel's column; smaller screens give it the
    // whole screen (owner-approved workspace layout).
    const wide = () => matchMedia('(min-width: 1000px)').matches;
    // The one map's drawings and the stop close-up (transit-map.js).
    const M = root.TravelMap;
    const reduced = () =>
        matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isActive = () =>
        active?.routeId === route?.id &&
        (active?.boardingStop || null) === (route?.boardingStop || null);
    const iconButton = (name, label, action, extra = '') =>
        `<button class="nav-icon-button" data-nav="${action}" aria-label="${label}" title="${label}" ${extra}>${icon(name)}</button>`;
    // Each route keeps its own sample: the capture or the sample day.
    const trip = () => J.tripOf?.(route) || J.capture;
    function persist() {
        const progress = N.progress(route, active.step);
        active = {
            ...active,
            step: progress.index,
            title: trip().place,
            instruction: progress.step.title,
            mode: route.mode,
        };
        const persisted = S.write(active);
        dialog
            .querySelector('.nav-session-note')
            ?.toggleAttribute('hidden', persisted);
    }
    function open(value, autoStart = false) {
        const found =
            typeof value === 'string'
                ? (J.all || J.routes).find((item) => item.id === value)
                : value;
        if (!found) return;
        if (document.querySelector('#sheet')?.open) {
            closeSheet(() => open(found, autoStart));
            return;
        }
        closePopover();
        hideSuggestions();
        active = N.restore(S.read());
        if (!dialog.open) {
            returnFocus = document.activeElement;
            previousOverflow = document.body.style.overflow;
        }
        route = found;
        settings = false;
        confirmation = '';
        dialog.innerHTML = `<div class="nav-workspace">
            <header class="nav-topbar">${iconButton('close', 'Close the trip', 'close')}<div><h1 id="nav-title">${safe(trip().place)}</h1><small></small></div>${iconButton('more', 'Preview options', 'options', 'aria-expanded="false" aria-controls="nav-options"')}</header>
            <section class="nav-panel" aria-label="Your trip"><div class="nav-panel-content"></div></section>
            <div id="nav-options" class="nav-options" hidden></div>
            <p class="nav-session-note" role="status" hidden>Progress is kept for this page only.</p>
            <p class="sr-only nav-announcement" aria-live="polite"></p>
        </div>`;
        if (!dialog.open) {
            // The phone's Back closes the trip (history.js).
            root.TravelHistory?.open('trip', () => close());
            if (wide()) {
                document.querySelector('.page-navigation')?.after?.(dialog);
                dialog.show();
                document.body.classList?.add('trip-open');
                window.scrollTo({ top: 0, behavior: 'instant' });
            } else {
                document.body.append?.(dialog);
                dialog.showModal();
            }
        }
        render();
        if (!wide()) document.body.style.overflow = 'hidden';
        closing = false;
        if (!reduced())
            dialog.animate(
                [
                    { opacity: 0, transform: 'translateY(16px)' },
                    { opacity: 1, transform: 'translateY(0)' },
                ],
                { duration: 240, easing: 'cubic-bezier(.22,.7,.2,1)' },
            );
        dialog
            .querySelector('[data-nav="close"]')
            .focus({ preventScroll: true });
        if (autoStart && !isActive()) startCurrent();
    }
    function close(after) {
        if (!dialog.open || closing) return;
        closing = true;
        root.TravelHistory?.closed('trip');
        const finish = () => {
            dialog.close();
            document.body.classList?.remove('trip-open');
            document.body.style.overflow = previousOverflow || '';
            closing = false;
            returnFocus?.isConnected &&
                returnFocus.focus({ preventScroll: true });
            after?.();
        };
        if (reduced()) finish();
        else
            dialog
                .animate(
                    [
                        { opacity: 1, transform: 'translateY(0)' },
                        { opacity: 0, transform: 'translateY(10px)' },
                    ],
                    { duration: 160, easing: 'ease-out' },
                )
                .finished.then(finish, finish);
    }
    function conditionMarkup() {
        if (!isActive() || active.condition === 'normal') return '';
        const conditions = {
            location: [
                'locate',
                'Location unavailable',
                'It follows the timetable until your location is back. Your place in the trip is kept.',
            ],
            offline: [
                'offline',
                'Connection lost',
                'Times won’t update until you’re back online.',
            ],
            changed: [
                'route',
                'Route change to review',
                'This way may not work any more. Plan again from where you are.',
            ],
        };
        const [symbol, title, text] = conditions[active.condition];
        return `<div class="nav-condition" role="status">${icon(symbol)}<div><strong>${title} <small>Sample</small></strong><p>${text}</p>${active.condition === 'changed' ? '<button data-nav="elsewhere">Plan again</button>' : ''}</div>${iconButton('close', 'Reset preview condition', 'normal')}</div>`;
    }
    /* ---------- Moments: the approved step-by-step trip ----------
       Each step says what to do now in the trip's own words, from its
       sample steps. No countdowns, platforms or live positions. */
    const stopName = (name = '') => name.replace(/^Köln\s+/, '');
    const minutesBetween = (a, b) =>
        J.timeline(b, trip()) - J.timeline(a, trip());
    function vehicle() {
        // A timetable journey knows its first vehicle; otherwise the
        // station capture.
        const leg = route.legs?.find(
            (item) => item.mode === 'transit' && item.vehicle,
        );
        if (leg)
            return leg.vehicle === 'bus'
                ? 'Bus'
                : leg.vehicle === 'rail'
                  ? 'Train'
                  : 'Tram';
        const service = root.StationCapture?.services.find(
            (item) => item.line === route.line,
        );
        return service?.type === 'bus' ? 'Bus' : service ? 'Tram' : 'Line';
    }
    // The boarding area Nearby knows for this line, if it's a station it has.
    function boardingArea(board) {
        if (!root.NearbyModel || !root.StationCapture || !board?.boardStop)
            return null;
        const model = root.NearbyModel.create({ capture: root.StationCapture });
        const stop = model.stops.find((item) => item.id === board.boardStop);
        const line = stop?.lines.find((item) => item.line === route.line);
        return stop?.areas.find((area) => area.id === line?.area) || null;
    }
    /* ---------- The trip follows you (owner, 15 Oct 2026; Travel
       directions canvas row 19) ----------
       One tap starts it (Nearby's "I'm on my way"); after that your
       location while the page is open, the timetable when it isn't, and a
       nudge one stop before you get off move it on. Nothing to confirm:
       "Not right?" corrects it. Here the sample clock stands in for time and
       location. The ring is Nearby's: minutes to the next thing, coloured by
       how much it matters, never by the line. */
    let nudge = false,
        menuOpen = false,
        missedFrom = '',
        notice = '';
    const at = (time, delta = 0) => {
        const total =
            (((Number(time.slice(0, 2)) * 60 +
                Number(time.slice(3, 5)) +
                delta) %
                1440) +
                1440) %
            1440;
        return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
    };
    // Each line says its own vehicle: the evening's tram 18, then bus 133.
    const word = (step) => {
        const legs = (route.legs || []).filter(
            (item) => item.mode === 'transit',
        );
        const leg =
            step &&
            (legs.find(
                (item) =>
                    String(item.line) === lineOf(step) &&
                    item.time === step.time,
            ) ||
                legs.find((item) => String(item.line) === lineOf(step)));
        const kind =
            step && leg?.vehicle
                ? leg.vehicle === 'bus'
                    ? 'Bus'
                    : leg.vehicle === 'rail'
                      ? 'Train'
                      : 'Tram'
                : vehicle();
        return kind === 'Bus' ? 'bus' : kind === 'Train' ? 'train' : 'tram';
    };
    const capital = (text) => text.charAt(0).toUpperCase() + text.slice(1);
    const lineOf = (step) => String(step?.line || route.line || '');
    const towardsOf = (step) =>
        stopName((step?.detail || '').replace(/^Towards /, '').split(' · ')[0]);
    const offAt = (step) =>
        stopName((step?.title || '').replace(/^Get off at /, ''));
    const place = (step) => stopName(step?.placeName || '');
    const scenario = () => {
        try {
            return new URL(location.href).searchParams.get('scenario') || '';
        } catch {
            return '';
        }
    };
    // The moments the trip passes through, each at its sample-clock time.
    function moments() {
        const list = N.steps(route);
        const out = [];
        list.forEach((step, index) => {
            const before = list[index - 1];
            if (step.mode === 'transit')
                out.push({
                    step: index,
                    nudge: false,
                    clock: at(step.time, -2),
                });
            else if (step.mode === 'alight') {
                if (before?.mode === 'transit')
                    out.push({
                        step: index,
                        nudge: false,
                        clock: at(
                            before.time,
                            Math.round(
                                minutesBetween(before.time, step.time) / 2,
                            ),
                        ),
                    });
                out.push({
                    step: index,
                    nudge: true,
                    clock: at(step.time, -1),
                });
            } else if (step.mode === 'walk' && index > 0)
                out.push({
                    step: index,
                    nudge: false,
                    // A change between lines is quick; the last walk less so.
                    clock: at(
                        step.time,
                        list
                            .slice(index)
                            .some((item) => item.mode === 'transit')
                            ? 1
                            : 2,
                    ),
                });
            else out.push({ step: index, nudge: false, clock: step.time });
        });
        // Never before the moment ahead of it (changes can be quick).
        out.forEach((item, index) => {
            if (index && minutesBetween(out[index - 1].clock, item.clock) < 0)
                item.clock = out[index - 1].clock;
        });
        return out;
    }
    const currentMoment = (p, list = moments()) =>
        list.find((item) => item.step === p.index && item.nudge === nudge) ||
        list.find((item) => item.step === p.index);
    // The other lines that stop where this one boards (Nearby's areas).
    function othersHere(step) {
        const line = lineOf(step);
        const others = (boardingArea(step)?.lines || [])
            .map(String)
            .filter((item) => item !== line);
        if (!others.length) return '';
        const names =
            others.length > 1
                ? `${others.slice(0, -1).join(', ')} and ${others.at(-1)}`
                : others[0];
        const many = { tram: 'Trams', bus: 'Buses', train: 'Trains' }[
            word(step)
        ];
        return `${many} ${names} stop here too: take the ${line}`;
    }
    const lookOf = (step) =>
        root.NearbyModel?.look(
            lineOf(step),
            word(step) === 'bus' ? 'bus' : 'tram',
        ) || {};
    // Where a line leaves from, when its stop has the OpenStreetMap close-up
    // (station-closeup.js): the platform for its direction, or both of the
    // line's when the timetable's name for the direction isn't one we know.
    function platformOf(board) {
        const stop = board?.boardStop || '';
        if (!M?.hasCloseUp(stop)) return null;
        const lit = M.platformFor(stop, lineOf(board), towardsOf(board));
        return lit.length ? { stop, lit, note: M.note(stop, lit) } : null;
    }
    // What to look for now: one row, the same size at every moment (owner,
    // 15 Oct 2026: "why three different uis when changing clocks?").
    function lookRow(look) {
        const tiles = {
            badge: () =>
                `<span class="nav-look-tile is-badge" style="--line:${look.colour || 'var(--color-text)'}">${safe(look.line)}</span>`,
            sign: () =>
                `<span class="nav-look-tile is-sign" title="${safe(look.line)} ${safe(look.towards)}"><span style="--line:${look.colour || 'var(--color-text)'}">${safe(look.line)}</span><b>${safe(look.towards)}</b></span>`,
            quote: () =>
                '<span class="nav-look-tile is-quote" aria-hidden="true">„“</span>',
            exit: () =>
                '<span class="nav-look-tile is-exit" lang="de">Ausgang</span>',
            walk: () => `<span class="nav-look-tile">${icon('walk')}</span>`,
            bike: () => `<span class="nav-look-tile">${icon('bike')}</span>`,
            doc: () => `<span class="nav-look-tile">${icon('document')}</span>`,
            pin: () => `<span class="nav-look-tile">${icon('pin')}</span>`,
        };
        const body = `${tiles[look.tile]()}<span class="nav-look-text"><strong>${safe(look.title)}</strong><small>${safe(look.text)}</small></span>`;
        return look.href
            ? `<a class="nav-look" href="${look.href}">${body}${icon('chevron')}</a>`
            : `<div class="nav-look">${body}</div>`;
    }
    // What the page says now: the ring, the words, how it knows.
    function momentNow(p, running = true) {
        const list = N.steps(route);
        const step = p.step;
        const cur = currentMoment(p);
        const left = (time) => Math.max(0, minutesBetween(cur.clock, time));
        const ring = (minutes, full = false) => ({
            text:
                minutes >= 60
                    ? `${Math.floor(minutes / 60)} h`
                    : String(minutes),
            label: minutes >= 60 ? '' : 'min',
            arc:
                full || minutes === 0
                    ? 100
                    : Math.max(
                          8,
                          Math.round((Math.min(minutes, 30) / 30) * 100),
                      ),
        });
        const firstTransit = list.findIndex((item) => item.mode === 'transit');
        const sc = scenario();
        // The sample day's earlier tram, when this trip is the later one.
        const gone =
            trip().id === 'day'
                ? J.dayRoutes?.find(
                      (item) =>
                          item.id !== route.id &&
                          minutesBetween(item.depart, route.depart) > 0,
                  )
                : null;
        const goneTime = gone?.legs.find((leg) => leg.mode === 'transit')?.time;
        const was =
            missedFrom ||
            (['cancelled', 'missed'].includes(sc) ? goneTime : '');
        const spare = trip().start
            ? minutesBetween(route.arrive, trip().start)
            : null;
        const spareText =
            spare == null
                ? ''
                : spare < (trip().kind === 'official' ? 10 : 5)
                  ? ` · only ${spare} min spare`
                  : ` · ${spare} min spare`;
        const base = {
            tone: 'calm',
            why: '',
            stand: false,
            done: false,
            canMiss: firstTransit >= 0 && p.index <= firstTransit && !was,
        };
        // A cancelled or missed earlier tram (sample scenarios): the later
        // trip says so from its first moment, as Nearby does.
        const coral = sc === 'cancelled' && !missedFrom;
        const warn = was
            ? {
                  tone: coral ? 'conflict' : 'attention',
                  why: coral ? `${was} cancelled · ` : `Missed the ${was} · `,
              }
            : {};
        const board = list.find((item) => item.mode === 'transit');
        // A lift out of order (sample): an underground platform has no
        // step-free way down, said from the first moment as Nearby does.
        const liftOut =
            !was &&
            sc === 'lift' &&
            !!board &&
            !!platformOf(board)?.note.includes('underground');
        const warning = was
            ? warn
            : liftOut
              ? { tone: 'conflict', why: 'Lift out of order · ' }
              : {};
        const extra = was
            ? spareText
            : liftOut
              ? ' · no step-free way down (sample)'
              : '';
        const nextLook = (at) => ({
            tile: 'badge',
            line: lineOf(board),
            colour: lookOf(board).colour,
            title: `Next: ${word(board)} ${lineOf(board)} at ${board.time}`,
            text: at
                ? `From ${place(board)} · ${at.note.replace(' · ', ', ')}`
                : `From ${place(board)}`,
        });
        // A way with no tram or bus: nothing to look for but where it ends.
        const ownWay = (item) => ({
            tile: item.mode === 'bike' ? 'bike' : 'walk',
            title: 'Street directions weren’t captured',
            text: `${trip().place} · there ${route.arrive}`,
        });
        // Before you start: when to leave, counted from now (the sample's
        // clock), and what you do first (canvas row 22, review 15 Oct).
        if (!running) {
            const wait = minutesBetween(trip().time, route.depart);
            const first = list[0];
            const at = platformOf(board);
            return {
                ...base,
                ...warning,
                tone: warning.tone || (wait <= 0 ? 'attention' : 'calm'),
                ring:
                    wait <= 0
                        ? { text: 'now', label: '', arc: 100 }
                        : ring(wait),
                title: wait <= 0 ? 'Leave now' : `Leave at ${route.depart}`,
                sub: `${first.title.replace(/Köln\s+/g, '')} · ${first.detail}${extra}`,
                map: at ? at.note : `Your way · ${route.duration} min`,
                stand: !!at,
                look: board ? nextLook(at) : ownWay(first),
            };
        }
        if (step.mode === 'walk' && p.index === 0) {
            const to = stopName(step.title.replace(/^Walk to /, ''));
            const at = platformOf(board);
            const wait = left(board ? board.time : route.arrive);
            // The ring already says the minutes when they're the walk's own
            // (review, 16 Oct 2026); Your way keeps the walk's length.
            const walk = Number(step.detail.match(/^(\d+) min/)?.[1]);
            return {
                ...base,
                ...warning,
                ring: ring(wait),
                title: `Walk to ${to}`,
                sub: `${walk === wait ? 'On foot' : step.detail}${extra}`,
                how: board
                    ? 'We’ll see when you reach the stop'
                    : 'We’ll see when you arrive',
                map: at ? at.note : `The walk to ${to}`,
                stand: !!board,
                look: board ? nextLook(at) : ownWay(step),
            };
        }
        if (step.mode === 'transit') {
            const from = place(step);
            const standNote = platformOf(step)?.note || `Board at ${from}`;
            const look = {
                tile: 'sign',
                line: lineOf(step),
                colour: lookOf(step).colour,
                towards: towardsOf(step),
                title: `On the front of the ${word(step)}`,
                text:
                    othersHere(step) ||
                    `Look for ${lineOf(step)} ${towardsOf(step)}`,
            };
            if (was) {
                return {
                    ...base,
                    ...warn,
                    ring: { text: '!', label: '', arc: 100 },
                    title: coral
                        ? `Take the ${step.time} instead`
                        : `Take the ${step.time}`,
                    sub: `${capital(word(step))} ${lineOf(step)} to ${towardsOf(step)} · there ${route.arrive}${spareText} (sample)`,
                    how: coral
                        ? 'We’ll move on when it leaves with you'
                        : `We noticed you were still at the stop after ${was} (sample)`,
                    map: standNote,
                    stand: true,
                    look,
                };
            }
            const minutes = left(step.time);
            return {
                ...base,
                tone: minutes === 0 ? 'attention' : 'calm',
                ring: ring(minutes),
                why: minutes === 0 ? 'It’s leaving · ' : '',
                title: `Take ${word(step)} ${lineOf(step)} to ${towardsOf(step)}`,
                sub: `Leaves ${from} at ${step.time}`,
                how: `We’ll move on when the ${word(step)} leaves with you`,
                map: standNote,
                stand: true,
                look,
            };
        }
        if (step.mode === 'ride' || step.mode === 'alight') {
            const board = list
                .slice(0, p.index + 1)
                .reverse()
                .find((item) => item.mode === 'transit');
            const alight =
                step.mode === 'alight'
                    ? step
                    : list
                          .slice(p.index)
                          .find((item) => item.mode === 'alight');
            const ride = `${capital(word(board))} ${lineOf(board)} to ${offAt(alight)}`;
            if (step.mode === 'alight' && cur.nudge)
                return {
                    ...base,
                    tone: 'attention',
                    ring: ring(Math.max(1, left(step.time)), true),
                    why: 'Next stop · ',
                    title: 'Get off at the next stop',
                    sub: `${offAt(step)} at ${step.time}`,
                    how: 'Your phone buzzes here, even with the screen off (with notifications on)',
                    map: ride,
                    look: {
                        tile: 'quote',
                        title: `„Nächster Halt: ${offAt(step)}“`,
                        text: 'That’s you: get off here',
                    },
                };
            return {
                ...base,
                ring: ring(left(alight.time)),
                title:
                    step.mode === 'ride'
                        ? `Stay on to ${offAt(alight)}`
                        : `Ride to ${offAt(alight)}`,
                sub:
                    step.mode === 'ride'
                        ? `Next: ${stopName(step.title)} at ${step.time}`
                        : `Get off at ${alight.time} · ${word(board)} ${lineOf(board)} towards ${towardsOf(board)}`,
                how: 'We’ll tell you one stop before',
                map: ride,
                look: {
                    tile: 'quote',
                    title: `You’ll hear „Nächster Halt: ${offAt(alight)}“`,
                    text: 'It means “next stop”: that’s where you get off',
                },
            };
        }
        if (step.mode === 'walk') {
            const after = list
                .slice(p.index + 1)
                .find((item) => item.mode === 'transit');
            const off = list
                .slice(0, p.index)
                .reverse()
                .find((item) => item.mode === 'alight');
            const title = step.title.replace(/Köln\s+/g, '');
            if (after)
                return {
                    ...base,
                    ring: ring(left(after.time)),
                    title,
                    sub: step.detail,
                    how: 'We’ll see when you reach the stop',
                    map: title,
                    look: {
                        tile: 'badge',
                        line: lineOf(after),
                        colour: lookOf(after).colour,
                        title: `Next: ${word(after)} ${lineOf(after)} at ${after.time}`,
                        text: `From ${place(after)}`,
                    },
                };
            return {
                ...base,
                ring: ring(left(route.arrive)),
                title,
                sub: `${off ? `From ${offAt(off)} · ` : ''}there ${route.arrive}`,
                how: 'We’ll see when you arrive',
                map: title,
                look:
                    off &&
                    word(
                        list
                            .slice(0, p.index)
                            .reverse()
                            .find((item) => item.mode === 'transit'),
                    ) !== 'bus'
                        ? {
                              tile: 'exit',
                              title: 'Follow „Ausgang“',
                              text: 'It means “exit” · street directions weren’t captured',
                          }
                        : {
                              tile: 'walk',
                              title: step.detail,
                              text: 'Street directions weren’t captured',
                          },
            };
        }
        if (step.mode !== 'arrive')
            return {
                ...base,
                ring: ring(left(list[p.index + 1]?.time || route.arrive)),
                title: step.title,
                sub: step.detail,
                how: 'We’ll see when you arrive',
                map: step.title,
                look: ownWay(step),
            };
        const early = trip().start
            ? minutesBetween(route.arrive, trip().start)
            : null;
        return {
            ...base,
            done: true,
            canMiss: false,
            title:
                early != null && early >= 0
                    ? `You’re ${early} min early`
                    : 'You’ve arrived',
            sub: `${trip().place}${trip().start ? ` · ${trip().start}` : ''}${trip().to ? ` · ${stopName(trip().to)}` : ''}`,
            how: 'The trip ends by itself',
            map: trip().place,
            look:
                trip().kind === 'official'
                    ? {
                          tile: 'doc',
                          title: 'What to bring',
                          text: 'Your Blue Card checklist in Paperwork',
                          href: '../bureaucracy-next/',
                      }
                    : {
                          tile: 'pin',
                          title: trip().place,
                          text: trip().start
                              ? `Starts at ${trip().start}`
                              : 'You’re there',
                      },
        };
    }
    function stepCard(now, running) {
        const circles = (arc) =>
            `<svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="16" pathLength="100"></circle><circle cx="18" cy="18" r="16" pathLength="100" stroke-dasharray="${arc} 100"></circle></svg>`;
        const ring = now.done
            ? `<span class="nav-ring is-done" aria-hidden="true">${circles(100)}${icon('check')}</span>`
            : `<span class="nav-ring" aria-hidden="true">${circles(now.ring.arc)}<span><strong>${now.ring.text}</strong>${now.ring.label ? `<small>${now.ring.label}</small>` : ''}</span></span>`;
        const how =
            active?.condition === 'location' && !now.done
                ? 'Location is off: following the timetable'
                : now.how;
        const menu = menuOpen
            ? `<div class="nav-not-right" role="menu" aria-label="Not right?">${now.canMiss ? `<button role="menuitem" data-nav="missed">${icon('clock')}I missed the ${word()}</button>` : ''}<button role="menuitem" data-nav="elsewhere">${icon('pin')}I’m somewhere else</button><button role="menuitem" data-nav="end">${icon('stop')}Stop the trip</button></div>`
            : '';
        const follow = running
            ? `<div class="nav-follow"><span>${icon('locate')}${safe(how)}</span>${now.done ? '' : `<button data-nav="not-right" aria-expanded="${menuOpen}">Not right?${icon('down')}</button>`}</div>${menu}`
            : `<div class="nav-start"><button class="nav-primary" data-nav="start">${icon('play')}I’m on my way</button><div><span>It moves on by itself as you go</span>${route.inPlan ? `<span class="nav-in-plan">${icon('calendar')}In your plan</span>` : `<button class="nav-text-button" data-save="${route.id}">${icon('bookmark')}<span data-save-label>Add to plan</span></button>`}</div></div>`;
        return `<section class="nav-step-card is-${now.tone}" aria-label="${running ? 'Now' : 'First'}"><div class="nav-step-lead">${ring}<div><h2>${safe(now.title)}</h2><p class="moment-sub">${now.why ? `<b class="nav-why">${safe(now.why)}</b>` : ''}${safe(now.sub)}</p></div></div>${lookRow(now.look)}${follow}${notice ? `<p class="nav-notice" role="status">${safe(notice)}</p>` : ''}</section>`;
    }
    // Your way as a line of stations: a row for each part, the one you're on
    // marked. Waiting at the stop and riding are both the tram's row.
    function wayRows(p, running) {
        const list = N.steps(route);
        const rows = [];
        let current = running ? 0 : -1;
        let lastOff = null;
        list.forEach((step, index) => {
            if (step.mode === 'transit') {
                const alight = list
                    .slice(index)
                    .find((item) => item.mode === 'alight');
                const look = root.NearbyModel?.look(
                    lineOf(step),
                    word(step) === 'bus' ? 'bus' : 'tram',
                );
                rows.push({
                    kind: 'ride',
                    look,
                    time: step.time,
                    badge:
                        L && lineOf(step)
                            ? L.line(lineOf(step), look || {})
                            : '',
                    title: `${capital(word(step))} ${lineOf(step)} to ${offAt(alight)}`,
                    sub: `From ${place(step)} · towards ${towardsOf(step)}${alight ? ` · get off at ${alight.time}` : ''}`,
                });
            } else if (step.mode === 'walk')
                rows.push({
                    kind: index && lastOff ? 'walk is-from-stop' : 'walk',
                    look: rows.at(-1)?.look,
                    time: step.time,
                    title: step.title.replace(/Köln\s+/g, ''),
                    sub: lastOff
                        ? `From ${offAt(lastOff)} · ${step.detail}`
                        : step.detail,
                });
            else if (step.mode === 'arrive')
                rows.push({
                    kind: 'end',
                    time: step.time,
                    title: trip().place,
                    sub: `${trip().start ? `Starts at ${trip().start}` : 'There'}${trip().to ? ` · ${stopName(trip().to)}` : ''}`,
                });
            else if (!['ride', 'alight'].includes(step.mode))
                rows.push({
                    kind: 'walk',
                    time: step.time,
                    title: step.title,
                    sub: step.detail,
                });
            if (step.mode === 'alight') lastOff = step;
            if (running && index <= p.index) current = rows.length - 1;
        });
        return `<ol class="nav-way">${rows
            .map(
                (row, index) =>
                    `<li class="is-${row.kind}${index < current ? ' is-done' : ''}${index === current ? ' is-now' : ''}"${row.look ? ` style="--line:${row.look.colour}"` : ''}${index === current ? ' aria-current="step"' : ''}><time>${row.time}</time><i aria-hidden="true"></i><div><strong>${row.badge || ''}<span>${safe(row.title)}</span>${index === current ? '<em>You’re here</em>' : ''}</strong><small>${safe(row.sub)}</small></div></li>`,
            )
            .join('')}</ol>`;
    }
    // A moment's name in the ⋯ menu, where reviewing picks it (the page
    // itself moves on by itself; owner, 15 Oct 2026).
    function momentName(item) {
        const list = N.steps(route);
        const step = list[item.step];
        if (step.mode === 'walk' && item.step === 0)
            return `Walking to ${stopName(step.title.replace(/^Walk to /, ''))}`;
        if (step.mode === 'transit') return `At ${place(step)}`;
        if (step.mode === 'alight' && item.nudge)
            return `Next stop: ${offAt(step)}`;
        if (step.mode === 'alight' || step.mode === 'ride') {
            const board = list
                .slice(0, item.step + 1)
                .reverse()
                .find((entry) => entry.mode === 'transit');
            return `On ${word(board)} ${lineOf(board)}`;
        }
        if (step.mode === 'arrive') return 'Arrived';
        return step.title.replace(/Köln\s+/g, '');
    }
    /* ---------- The map card (owner, 15 Oct 2026; canvas row 20) ----------
       One map while the trip runs: where to stand while you walk to the
       stop and wait (the stop's street close-up, when it has one), then your
       way drawn as a line of stations with you on it. */
    function wayDrawing(p, running) {
        const list = N.steps(route);
        const nodes = [{ name: 'Start' }];
        const legs = [];
        const atStep = [];
        let ride = null;
        list.forEach((step, index) => {
            const next = list[index + 1];
            if (step.mode === 'walk' || step.mode === 'bike') {
                legs.push({ mode: step.mode });
                nodes.push({
                    name: next?.mode === 'transit' ? place(next) : trip().place,
                });
                atStep[index] = { leg: legs.length - 1, t: 0.5 };
            } else if (step.mode === 'transit') {
                const alight = list
                    .slice(index)
                    .find((item) => item.mode === 'alight');
                const between = list
                    .slice(index + 1, list.indexOf(alight))
                    .filter((item) => item.mode === 'ride').length;
                const counted =
                    Number((step.detail.match(/· (\d+) stops?/) || [])[1]) || 0;
                const minutes = alight
                    ? minutesBetween(step.time, alight.time)
                    : 0;
                ride = {
                    mode: 'ride',
                    line: lineOf(step),
                    tint: lookOf(step).colour || '#211d15',
                    stops: between || Math.max(0, counted - 1),
                    rides: between,
                    seen: 0,
                };
                ride.note = [
                    `${minutes} min`,
                    counted
                        ? `${counted} stops`
                        : between
                          ? `${between} stop${between > 1 ? 's' : ''} between`
                          : 'stops between not recorded',
                ];
                legs.push(ride);
                nodes.push({ name: alight ? offAt(alight) : '' });
                atStep[index] = { leg: legs.length - 1, t: 0 };
            } else if (step.mode === 'ride') {
                ride.seen++;
                atStep[index] = {
                    leg: legs.length - 1,
                    t: ride.seen / (ride.rides + 1),
                };
            } else if (step.mode === 'alight')
                atStep[index] = {
                    leg: legs.length - 1,
                    t: ride.rides ? 0.8 : 0.2,
                };
            else if (step.mode === 'arrive') atStep[index] = { done: true };
        });
        // Before you start the whole way is drawn, with nobody on it yet.
        let at = running ? atStep[p.index] || { done: true } : null;
        if (running && p.step.mode === 'alight' && nudge)
            at = { ...at, t: 0.88 };
        const label = `Your way: ${legs
            .map((leg, index) =>
                leg.mode === 'ride'
                    ? `${word(list.find((item) => item.mode === 'transit' && lineOf(item) === leg.line))} ${leg.line} to ${nodes[index + 1].name}`
                    : `${leg.mode === 'bike' ? 'cycle' : 'walk'} to ${nodes[index + 1].name}`,
            )
            .join(', ')}`;
        return M.trip({ nodes, legs, at, label });
    }
    function mapCard(p, now, running) {
        if (!M) return '';
        const board =
            p.step.mode === 'transit'
                ? p.step
                : N.steps(route)
                      .slice(p.index)
                      .find((item) => item.mode === 'transit');
        const stand = now.stand && platformOf(board);
        const colour = lookOf(board).colour || '#211d15';
        const body = stand
            ? `${M.closeUp(stand.stop, stand.lit, colour)}${M.legend(stand.stop, stand.lit)}`
            : wayDrawing(p, running);
        return `<section class="nav-mapcard" aria-label="On the map"><h3 class="nav-way-title">On the map</h3><div class="nav-mapcard-box"><span class="nav-map-note">${safe(now.map)}</span>${body}</div></section>`;
    }
    let youWas = '';
    function render() {
        const running = isActive();
        const p = N.progress(route, running ? active.step : 0);
        const content = dialog.querySelector('.nav-panel-content');
        const focus = content.contains(document.activeElement)
            ? document.activeElement.dataset.nav
            : null;
        const confirm = confirmation
            ? `<div class="nav-confirm" role="group" aria-label="${confirmation === 'end' ? 'Stop this trip?' : 'Replace the trip you’re on?'}"><span>${confirmation === 'end' ? 'Stop this trip?' : 'Replace the trip you’re on?'}</span><button data-nav="cancel-confirm">Keep</button><button class="nav-primary" data-nav="confirm">${confirmation === 'end' ? 'Stop' : 'Replace'}</button></div>`
            : '';
        const now = momentNow(p, running);
        content.innerHTML = `${conditionMarkup()}<div class="nav-summary">${stepCard(now, running)}</div>${mapCard(p, now, running)}<div id="nav-expanded" class="nav-expanded"><div class="nav-detail-scroll"><h3 class="nav-way-title">Your way</h3>${wayRows(p, running)}</div></div>${confirm ? `<footer class="nav-footer">${confirm}</footer>` : ''}`;
        // The top bar: the day, when to leave before you start, where you are
        // in the trip once it runs; always that it's a sample (desktop: the
        // dashed label; phones: the line under the title).
        dialog.querySelector('.nav-trip-progress')?.remove();
        dialog.querySelector('.nav-topbar > .nav-sample')?.remove();
        if (wide())
            dialog
                .querySelector('.nav-topbar [data-nav="options"]')
                .insertAdjacentHTML(
                    'beforebegin',
                    '<span class="nav-sample">Sample · not live</span>',
                );
        if (running) {
            const parts = N.steps(route).filter(
                (step) => step.mode !== 'arrive',
            ).length;
            const arrived = p.step.mode === 'arrive';
            dialog
                .querySelector('.nav-topbar > div')
                .insertAdjacentHTML(
                    'afterend',
                    `<div class="nav-trip-progress"><span class="nav-bars" role="progressbar" aria-label="Trip progress" aria-valuemin="0" aria-valuemax="${parts}" aria-valuenow="${arrived ? parts : p.index}">${Array.from({ length: parts }, (_, index) => `<i class="${arrived || index < p.index ? 'is-done' : index === p.index ? 'is-now' : ''}"></i>`).join('')}</span><span class="nav-count">${arrived ? 'Arrived' : `${p.index + 1} of ${parts}`}</span></div>`,
                );
        }
        if (focus)
            (
                content.querySelector(`[data-nav="${focus}"]`) ||
                content.querySelector(
                    '[data-nav="start"], [data-nav="not-right"], [data-nav="cancel-confirm"], [data-nav="confirm"]',
                )
            )?.focus({ preventScroll: true });
        const spare = trip().start
            ? ` · ${minutesBetween(route.arrive, trip().start)} min spare`
            : '';
        dialog.querySelector('#nav-title').textContent = `To ${trip().place}`;
        const day = new Date(`${trip().date}T12:00:00`).toLocaleDateString(
            'en-GB',
            { weekday: 'short', day: 'numeric', month: 'short' },
        );
        dialog.querySelector('.nav-topbar small').textContent =
            `${wide() ? '' : 'Sample · '}${day} · ${running ? `there ${route.arrive}` : `leave ${route.depart} · there ${route.arrive}`}${spare}`;
        if (typeof savedCount === 'function') savedCount();
        // You move along your way rather than jump.
        const you = content.querySelector('.tmap-you');
        if (you && youWas && youWas !== you.style.transform && !reduced())
            you.animate(
                [{ transform: youWas }, { transform: you.style.transform }],
                {
                    duration: 420,
                    easing: 'cubic-bezier(.22,.7,.2,1)',
                },
            );
        youWas = you?.style.transform || '';
    }
    function startCurrent(replace = false) {
        if (active && !isActive() && !replace) {
            confirmation = 'switch';
            render();
            return;
        }
        active = {
            routeId: route.id,
            ...(route.boardingStop ? { boardingStop: route.boardingStop } : {}),
            step: 0,
            condition: 'normal',
        };
        confirmation = '';
        nudge = false;
        menuOpen = false;
        missedFrom = '';
        notice = '';
        persist();
        // The same page now runs: the button gives way to how it knows.
        render();
        dialog
            .querySelector('[data-nav="not-right"]')
            ?.focus({ preventScroll: true });
        announce('Trip started. It moves on by itself.');
    }
    function announce(text) {
        dialog.querySelector('.nav-announcement').textContent = text;
    }
    function options() {
        settings = !settings;
        const box = dialog.querySelector('#nav-options');
        box.hidden = !settings;
        dialog
            .querySelector('[data-nav="options"]')
            .setAttribute('aria-expanded', String(settings));
        if (settings) fillOptions();
    }
    // Review-only switches: which moment of the trip it is (it stands in for
    // time and your location), and how guidance copes when things go wrong.
    function fillOptions() {
        const running = isActive();
        const list = moments();
        const cur = running
            ? currentMoment(N.progress(route, active.step), list)
            : null;
        const condition = active?.condition || 'normal';
        dialog.querySelector('#nav-options').innerHTML =
            `<strong>Trip moment</strong><p>Stands in for the time and your location.</p>${list
                .map(
                    (item, index) =>
                        `<button data-nav="moment" data-moment="${index}" ${running ? '' : 'disabled'} aria-pressed="${item === cur}"><time>${item.clock}</time>${safe(momentName(item))}${item === cur ? icon('check') : ''}</button>`,
                )
                .join(
                    '',
                )}<strong>Preview conditions</strong><p>Try how guidance responds.</p>${[
                ['normal', 'route', 'Normal journey'],
                ['location', 'locate', 'Location unavailable'],
                ['offline', 'offline', 'Connection lost'],
                ['changed', 'info', 'Route changed'],
            ]
                .map(
                    ([key, symbol, label]) =>
                        `<button data-nav-condition="${key}" ${running ? '' : 'disabled'} aria-pressed="${condition === key}">${icon(symbol)}${label}${condition === key ? icon('check') : ''}</button>`,
                )
                .join(
                    '',
                )}${running ? '' : '<small>Start the trip to try these.</small>'}`;
    }
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            close();
            return;
        }
        const button = event.target.closest('button');
        if (
            settings &&
            !event.target.closest('#nav-options, [data-nav="options"]')
        )
            options();
        if (!button || button.disabled) return;
        if (button.dataset.nav || button.dataset.navCondition)
            event.stopPropagation();
        if (button.dataset.navCondition) {
            if (!isActive()) return;
            active.condition = button.dataset.navCondition;
            persist();
            options();
            render();
            dialog
                .querySelector('[data-nav="options"]')
                .focus({ preventScroll: true });
            return;
        }
        const action = button.dataset.nav;
        if (action === 'moment') {
            const item = moments()[Number(button.dataset.moment)];
            active.step = item.step;
            nudge = item.nudge;
            menuOpen = false;
            notice = '';
            persist();
            // The menu closes, as it does for a condition, so the moment
            // you picked is in view.
            options();
            render();
            dialog
                .querySelector('[data-nav="options"]')
                ?.focus({ preventScroll: true });
            announce(
                `${item.clock}. ${momentNow(N.progress(route, active.step)).title}`,
            );
            return;
        }
        if (action === 'not-right') {
            menuOpen = !menuOpen;
            notice = '';
            render();
            dialog
                .querySelector(
                    menuOpen
                        ? '.nav-not-right button'
                        : '[data-nav="not-right"]',
                )
                ?.focus({ preventScroll: true });
            return;
        }
        if (action === 'missed') {
            menuOpen = false;
            // The sample day has a later tram 4; other trips plan again.
            const later =
                trip().id === 'day'
                    ? J.dayRoutes?.find(
                          (item) =>
                              item.id !== route.id &&
                              minutesBetween(route.depart, item.depart) > 0,
                      )
                    : null;
            if (!later) {
                notice = `No later ${word()} is in the sample. Plan a trip can look again.`;
                render();
                return;
            }
            missedFrom =
                route.legs.find((leg) => leg.mode === 'transit')?.time || '';
            nudge = false;
            S.write({
                routeId: later.id,
                step: Math.max(
                    0,
                    N.steps(later).findIndex((step) => step.mode === 'transit'),
                ),
                condition: active.condition || 'normal',
            });
            open(later);
            announce(
                `Switched to the ${later.legs.find((leg) => leg.mode === 'transit')?.time}.`,
            );
            return;
        }
        if (action === 'elsewhere') {
            menuOpen = false;
            const to = trip().to;
            close(() => root.TravelPlanTo?.(to));
            return;
        }
        if (action === 'close') close();
        else if (action === 'options') options();
        else if (action === 'start') startCurrent();
        else if (action === 'end') {
            menuOpen = false;
            confirmation = 'end';
            render();
        } else if (action === 'cancel-confirm') {
            confirmation = '';
            render();
        } else if (action === 'confirm' && confirmation === 'switch')
            startCurrent(true);
        else if (action === 'confirm') {
            active = null;
            S.write(null);
            close();
        } else if (action === 'normal') {
            active.condition = 'normal';
            persist();
            render();
        }
    });
    // On desktop the trip is part of the page, so a tap outside the ⋯ menu
    // can land beyond the trip; that closes it too.
    document.addEventListener('click', (event) => {
        if (settings && dialog.open && !dialog.contains(event.target))
            options();
    });
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        if (settings) {
            options();
            dialog.querySelector('[data-nav="options"]').focus();
        } else close();
    });
    // Crossing the desktop width moves the trip between page and screen.
    matchMedia('(min-width: 1000px)').addEventListener('change', () => {
        if (!dialog.open) return;
        const current = route;
        close(() => open(current));
    });
    root.JourneyNavigation = {
        open,
        start: (id) => open(id, true),
        resume: () => {
            const record = N.restore(S.read());
            if (record) open(N.routeFor(record));
        },
        currentRoute: (id) => (dialog.open && route?.id === id ? route : null),
        close,
    };
    if (new URL(location.href).searchParams.get('journey') === 'resume') {
        const url = new URL(location.href);
        url.searchParams.delete('journey');
        history.replaceState(history.state, '', url);
        root.JourneyNavigation.resume();
    }
})(globalThis);
