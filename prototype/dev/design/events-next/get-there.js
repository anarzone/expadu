/* Get there (owner, 13 Oct 2026): the way to an event, planned the way
   Travel plans a trip. The journeys are real scheduled ones
   (connected/sample-journeys.js: the VRS timetable through a local MOTIS
   planner, for the sample day, captured 13 Oct 2026): arriving by the start,
   leaving now (the sample day's clock) or the way back after it ends, from
   any start the preview recognises (Neumarkt and Cologne's districts; a
   district's journeys load when you start from it). The options use
   Travel's card, its line badges and labels (departures-next/labels.css);
   the chosen way is drawn on the map. Nothing here is live, and it says so.
   wall.js mounts it in the details' Get there page. */
(function (root) {
    // KVB line colours as Travel shows them (departures-next/nearby.js:
    // darkened where white text needs 4.5:1). Other lines are a dark slate
    // here rather than Travel's teal, which the Events details don't use;
    // buses are grey.
    const KVB = { 1: '#E2001A', 3: '#C8027E', 4: '#A01E5A', 7: '#008540', 9: '#E2001A', 12: '#95C11F', 16: '#0074AD', 18: '#0074AD' };
    const look = (leg) => ({
        colour: KVB[leg.line] || (leg.mode === 'bus' ? '#4A4A4A' : '#3B4A54'),
        ink: String(leg.line) === '12' ? '#1D2A05' : '#FFFFFF',
    });
    const vehicle = (leg) => (leg.mode === 'bus' ? 'Bus' : leg.mode === 'rail' ? 'Train' : 'Tram');
    const shape = (d) => `<svg viewBox="0 0 24 24" aria-hidden="true">${d}</svg>`;
    // Travel's label icons the Events set doesn't have.
    const extra = {
        star: shape('<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3Z"/>'),
        cloud: shape('<path d="M7 18a4 4 0 0 1-.5-8 6 6 0 0 1 11.6 1.5A3.3 3.3 0 0 1 17.5 18H7Z"/><path d="m9 21 .6-1.5m3.4 1.5.6-1.5"/>'),
        timer: shape('<circle cx="12" cy="13" r="7"/><path d="M12 13V9.5M10 3h4"/>'),
    };
    const mins = (t) => {
        const [h, m] = t.split(':').map(Number);
        return h * 60 + m;
    };
    // The way back can run past midnight: before noon counts as that night.
    const late = (t) => mins(t) + (mins(t) < 720 ? 1440 : 0);
    const clock = (n) => `${String(Math.floor(n / 60) % 24).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`;
    const span = (n) => (n < 60 ? `${n} min` : `${Math.floor(n / 60)} h${n % 60 ? ` ${n % 60} min` : ''}`);
    const data = () => root.ExpaduSampleJourneys;

    // Whether this event's ways were captured: the sample day's evening.
    const covers = (item) => !!data()?.trips.some((t) => t.event === item.id);
    // The captured trip for this event from the start named in From, once
    // that start's journeys are loaded.
    function tripFor(item, from) {
        const start = data()?.place(from);
        return (start && data().trips.find((t) => t.event === item.id && t.from.id === start.id)) || null;
    }

    function mount(box, ctx) {
        const { item, input, panel, I, E } = ctx;
        const J = data();
        const icon = (name) => extra[name] || I(name);
        const chip = (kind, name, text) => `<span class="tl-chip is-${kind}">${icon(name)}<span>${E(text)}</span></span>`;
        const badge = (leg, word) => {
            const l = look(leg);
            return `<span class="tl-line" style="--line:${l.colour};--ink:${l.ink}">${I(leg.mode === 'bus' ? 'bus' : 'train')}<span>${word ? `${vehicle(leg)} ${E(leg.line)}` : `<span class="sr-only">${vehicle(leg)} </span>${E(leg.line)}`}</span></span>`;
        };
        // The sample day's weather, hour by hour; none after midnight.
        const weatherAt = (time) => {
            const day = root.ExpaduSampleWeather?.days?.[item.date];
            const hour = late(time) < 1440 ? day?.[Number(time.slice(0, 2))] : null;
            return hour ? { wet: ['showers', 'rain'].includes(hour[0]), sky: hour[0] === 'rain' ? 'Rain' : 'Showers' } : null;
        };
        const weatherReason = (time) => {
            const w = weatherAt(time);
            return w ? [w.wet ? [`${w.sky} likely on the way (sample)`, 'warn', 'cloud'] : ['Dry on the way (sample)', 'ok', 'sun']] : [];
        };
        const reasonList = (reasons) =>
            `<span class="plan-option-reasons">${reasons.map(([text, kind, name]) => `<span class="is-${kind}"><span class="plan-reason-icon">${icon(name)}</span>${E(text)}</span>`).join('')}</span>`;
        const state = { pick: null, open: null, when: 'arrive' };
        const failed = new Set();

        // When and how, as small filters under the route (owner, 14 Oct
        // 2026, option A on the canvas): the time is a chip whose menu floats
        // over the page (by the start, now on the sample day's clock, or the
        // way back after it ends); the ways are chips with their minutes.
        // The form's own mode radios stay, so Open in Travel reads them.
        const route = panel.querySelector('.place-route');
        const whens = [
            ['arrive', `Arrive by ${item.start}`, 'When it starts', `By ${item.start}`],
            ['now', 'Leave now', `${J.now}, the sample day’s clock`, 'Now'],
            ...(item.end ? [['back', 'Way back', `After it ends at ${item.end}`, 'Way back']] : []),
        ];
        const menuId = `gt-when-${item.id}`;
        const date = new Date(item.date + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
        route?.insertAdjacentHTML(
            'afterend',
            `<div class="gt-bar"><div class="gt-bar-row"><button type="button" class="gt-time" aria-haspopup="menu" aria-expanded="false" aria-controls="${menuId}"><span class="gt-pill">${I('clock')}<span data-gt-when-label></span>${I('down')}</span></button><span class="gt-bar-rule" aria-hidden="true"></span></div><div class="gt-menu" id="${menuId}" role="menu" aria-label="When" hidden><small>${E(date)}</small>${whens
                .map(([value, title, sub]) => `<button type="button" role="menuitemradio" aria-checked="false" data-gt-when="${value}"><span><strong>${E(title)}</strong><small>${E(sub)}</small></span>${I('check')}</button>`)
                .join('')}</div></div>`,
        );
        const bar = panel.querySelector('.gt-bar');
        const timeButton = bar?.querySelector('.gt-time');
        const menu = bar?.querySelector('.gt-menu');
        const ways = panel.querySelector('fieldset');
        if (bar && ways) {
            ways.classList.add('gt-modes');
            bar.querySelector('.gt-bar-row').append(ways);
            ways.querySelectorAll('label > span').forEach((face) => face.insertAdjacentHTML('beforeend', '<span class="gt-mins"></span>'));
        }
        // A row that doesn't fit scrolls sideways and fades where it's cut.
        const row = bar?.querySelector('.gt-bar-row');
        const clip = () => row?.classList.toggle('is-clipped', row.scrollWidth - row.clientWidth - row.scrollLeft > 2);
        row?.addEventListener('scroll', clip, { passive: true });
        addEventListener('resize', clip);
        // Each way's chip shows how long it takes, for the time chosen: the
        // best tram or bus, the walk, the ride. Unknown, it shows its name.
        function minutes(trip, best) {
            const back = state.when === 'back';
            for (const radio of panel.querySelectorAll('input[name="mode"]')) {
                radio.dataset.name ||= radio.getAttribute('aria-label') || radio.value;
                const way = trip && (back ? trip.back : trip)[radio.value];
                const n = !trip ? null : radio.value === 'transit' ? best?.duration : way?.minutes;
                // Short, so the row fits a phone: "25 min", "1 h 29".
                const text = n ? (n < 60 ? `${n} min` : `${Math.floor(n / 60)} h${n % 60 ? ` ${n % 60}` : ''}`) : trip && radio.value !== 'transit' ? '2 h+' : '';
                const label = radio.closest('label');
                label?.classList.toggle('is-timed', !!text);
                const slot = label?.querySelector('.gt-mins');
                if (slot) slot.textContent = text;
                radio.setAttribute('aria-label', text ? `${radio.dataset.name}, ${n ? span(n) : 'over 2 hours'}` : radio.dataset.name);
            }
            clip();
        }
        // The way back runs the other way: from the venue, back to the place
        // in the field.
        const fieldLabel = [...(panel.querySelector('label.route-field')?.childNodes || [])].find((n) => n.nodeType === 3 && n.textContent.trim());
        const venueLabel = panel.querySelector('.place-journey-destination small');
        function direction() {
            const back = state.when === 'back';
            route?.classList.toggle('is-back', back);
            if (fieldLabel) fieldLabel.textContent = back ? 'Back to' : 'From';
            if (venueLabel) venueLabel.textContent = back ? 'From' : 'To';
            const chosen = whens.find(([value]) => value === state.when) || whens[0];
            const label = bar?.querySelector('[data-gt-when-label]');
            if (label) label.textContent = chosen[3];
            timeButton?.setAttribute('aria-label', `When: ${chosen[1]}, ${date}`);
            menu?.querySelectorAll('[data-gt-when]').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.gtWhen === state.when)));
        }

        // Steps of one option, as Travel's journey details list them.
        function steps(option, trip) {
            const back = state.when === 'back';
            const end = back ? trip.from.name : item.venue;
            const out = [];
            option.legs.forEach((leg, i) => {
                const last = i === option.legs.length - 1;
                if (leg.mode === 'walk') {
                    const change = i > 0 && !last;
                    out.push({
                        kind: 'walk',
                        time: leg.depart,
                        title: change ? (leg.from === leg.to ? `Change at ${leg.to}` : `Change: walk to ${leg.to}`) : last ? `Walk to ${end}` : `Walk to ${leg.to}`,
                        detail: `${leg.minutes} min on foot`,
                    });
                } else {
                    out.push({ kind: 'ride', time: leg.depart, title: `Take ${vehicle(leg)} ${leg.line}${i === 0 ? ` at ${leg.from}` : ''}`, detail: `Towards ${leg.towards} · ${leg.stops} stop${leg.stops > 1 ? 's' : ''}`, leg });
                    out.push({ kind: 'alight', time: leg.arrive, title: `Get off at ${leg.to}`, detail: '', leg });
                }
            });
            out.push(
                back
                    ? { kind: 'arrive', time: option.arrive, title: end, detail: `${span(option.duration)} from ${item.venue}` }
                    : { kind: 'arrive', time: option.arrive, title: item.venue, detail: `${item.title} starts at ${item.start}` },
            );
            return `<ol class="gt-steps">${out
                .map((s) => {
                    const l = s.leg ? look(s.leg) : null;
                    return `<li class="gt-step is-${s.kind}"${l ? ` style="--line:${l.colour}"` : ''}><time>${s.time}</time><span class="gt-node">${s.kind === 'ride' ? badge(s.leg) : I(s.kind === 'arrive' ? 'pin' : s.kind === 'alight' ? (s.leg.mode === 'bus' ? 'bus' : 'train') : 'walk')}</span><div><strong>${E(s.title)}</strong>${s.detail ? `<small>${E(s.detail)}</small>` : ''}</div></li>`;
                })
                .join('')}</ol>`;
        }
        // What an option's timing means: spare before the start, how early
        // leaving now gets you there, or how soon after the end you leave.
        function timing(depart, arrive) {
            if (state.when === 'back') {
                const after = late(depart) - late(item.end);
                return { tag: chip('calm', 'clock', after ? `${span(after)} after it ends` : 'As it ends'), tight: false };
            }
            const spare = mins(item.start) - mins(arrive);
            if (state.when === 'now') return { tag: chip('calm', 'clock', `${span(spare)} early`), tight: false };
            return spare < 5
                ? { tag: chip('attention', 'timer', `Only ${spare} min spare`), tight: true, spare }
                : { tag: chip('calm', 'check', `${spare} min spare`), tight: false, spare };
        }
        function option(o, best, trip) {
            const rides = o.legs.filter((l) => l.mode !== 'walk');
            const title = `<span class="plan-option-title">${rides.map((l, i) => badge(l, i === 0)).join(`<span class="plan-then">${I('arrow')}</span>`)}</span>`;
            const t = timing(o.depart, o.arrive);
            const reasons = [
                o.changes ? [`${o.changes} change${o.changes > 1 ? 's' : ''}`, 'ok', 'route'] : ['No change', 'ok', 'check'],
                [`${o.walking} min walking`, 'ok', 'walk'],
                ...weatherReason(o.depart),
                ...(t.tight ? [[`Only ${t.spare} min before ${item.start}`, 'warn', 'timer']] : []),
            ];
            const on = state.pick === o.id,
                open = state.open === o.id;
            return `<article class="plan-option${on ? ' is-on' : ''}" aria-label="${E(rides.map((l) => `${vehicle(l)} ${l.line}`).join(', then '))}, leave ${o.depart}, there ${o.arrive}">
                <button type="button" class="plan-option-pick" role="radio" aria-checked="${on}" tabindex="${on ? 0 : -1}" data-gt-pick="${o.id}">
                    <span class="plan-radio" aria-hidden="true"></span>
                    <span class="plan-option-body"><span class="plan-option-head">${best ? chip('best', 'star', 'Best for you') : title}${t.tag}</span>${best ? title : ''}<span class="plan-option-times">Leave <strong>${o.depart}</strong> → there <strong>${o.arrive}</strong></span>${reasonList(reasons)}</span>
                </button>
                <button type="button" class="details-toggle" data-gt-details="${o.id}" aria-expanded="${open}">Journey details ${I('down')}</button>
                <div class="gt-disclosure"${open ? '' : ' hidden'}>${steps(o, trip)}</div>
            </article>`;
        }
        // On foot or by bike: one way, whenever you go. By the start it's
        // there five minutes early (as Travel shows it: the margin it calls
        // tight); now it leaves at the sample clock; back, as it ends.
        function direct(trip, mode) {
            const back = state.when === 'back';
            const way = (back ? trip.back : trip)[mode];
            const doing = mode === 'bike' ? 'cycling' : 'walking';
            if (!way?.minutes)
                return `<p class="gt-none">${chip('unsure', 'info', 'Not in the capture')} The planner found no ${doing} way under 2 hours ${back ? `back to ${E(trip.from.name)}` : `from ${E(trip.from.name)}`}.</p>`;
            const leave = state.when === 'arrive' ? clock(mins(item.start) - 5 - way.minutes) : state.when === 'now' ? J.now : item.end;
            const there = state.when === 'arrive' ? clock(mins(item.start) - 5) : clock(mins(leave) + way.minutes);
            const tag = state.when === 'arrive' ? chip('calm', 'check', '5 min spare') : timing(leave, there).tag;
            const reasons = [[`${way.minutes} min ${doing} · ${way.km} km`, 'ok', mode], ...weatherReason(leave)];
            return `<div class="plan-options"><article class="plan-option is-on"><div class="plan-option-pick is-static"><span class="plan-radio" aria-hidden="true"></span><span class="plan-option-body"><span class="plan-option-head"><span class="tl-line is-mode">${I(mode)}<span>${mode === 'bike' ? 'By bike' : 'On foot'}</span></span>${tag}</span><span class="plan-option-times">Leave <strong>${leave}</strong> → there <strong>${there}</strong></span>${reasonList(reasons)}</span></div></article></div>`;
        }
        // Only what changed is redrawn: leaving the From field (a change)
        // as you tap a button below must not replace that button mid-tap.
        const put = (html) => {
            if (box.__html === html) return;
            box.__html = html;
            box.innerHTML = html;
        };
        // The starts loaded with the page, offered when a start isn't known.
        const offered = () => [...new Map(J.trips.filter((t) => t.event === item.id).map((t) => [t.from.id, t.from.name])).values()];
        function unknown(from) {
            const back = state.when === 'back';
            const named = from.trim();
            return `<div class="gt-empty">${chip('unsure', 'info', 'Not in this preview')}<p><strong>${named ? `No ${back ? `way back to ${E(named)}` : `routes from ${E(named)}`} yet` : back ? 'Where are you going back to?' : 'Where do you start?'}</strong><span>This preview has the timetable’s ways ${back ? 'to' : 'from'} Neumarkt and every Cologne district. Type a district, like Ehrenfeld, or pick one. Travel plans from anywhere.</span></p><div class="gt-starts">${offered()
                .map((c) => `<button type="button" class="gt-start" data-gt-start="${E(c)}">${I('pin')}${back ? 'Back to' : 'From'} ${E(c)}</button>`)
                .join('')}</div></div>`;
        }
        function render() {
            direction();
            const from = input?.value || '';
            const mode = panel.querySelector('input[name="mode"]:checked')?.value || 'transit';
            const back = state.when === 'back';
            const start = J?.place(from);
            if (!start) {
                minutes(null);
                put(unknown(from));
                ctx.onWay?.(null);
                return;
            }
            if (!J.has(start.id)) {
                minutes(null);
                ctx.onWay?.(null);
                if (failed.has(start.id)) {
                    put(`<div class="gt-empty">${chip('unsure', 'info', 'Not loaded')}<p><strong>The ways ${back ? 'to' : 'from'} ${E(start.name)} couldn’t load</strong><span>They’re part of this preview’s timetable capture.</span></p><div class="gt-starts"><button type="button" class="gt-start" data-gt-retry="${E(start.id)}">Try again</button></div></div>`);
                    return;
                }
                put(`<div class="gt-empty is-loading" role="status">${chip('unsure', 'clock', 'Loading')}<p><strong>Finding the ways ${back ? 'to' : 'from'} ${E(start.name)}</strong><span>From the VRS timetable capture.</span></p></div>`);
                J.load(start.id).then((ok) => {
                    if (!ok) failed.add(start.id);
                    if (J.place(input?.value || '')?.id === start.id) render();
                });
                return;
            }
            const trip = tripFor(item, from);
            if (!trip) {
                minutes(null);
                put(unknown(from));
                ctx.onWay?.(null);
                return;
            }
            const ways = back ? trip.back : trip;
            const list = state.when === 'now' ? trip.now : ways.options;
            // As Travel orders them: the earliest arrival first, and best.
            const options = [...list].sort((a, b) => late(a.arrive) - late(b.arrive));
            const best = options[0];
            if (!options.some((o) => o.id === state.pick)) state.pick = best?.id || null;
            minutes(trip, best);
            const sky = weatherAt(state.when === 'now' ? J.now : back ? item.end : item.start);
            // What the plan used, as one quiet line (owner, 14 Oct 2026); wet
            // weather keeps its warning colour.
            const checked = [
                'Timetable',
                'your day',
                ...(sky ? [sky.wet ? `<b>${sky.sky.toLowerCase()} (sample)</b>` : 'dry (sample)'] : []),
                'not live',
            ];
            const walk = ways.walk;
            const body =
                mode !== 'transit'
                    ? direct(trip, mode)
                    : options.length
                      ? `<div class="plan-options" role="radiogroup" aria-label="Ways ${back ? `back to ${E(trip.from.name)}` : `to ${E(item.venue)}`}">${options.map((o) => option(o, o === best, trip)).join('')}</div>`
                      : `<div class="gt-empty">${chip('calm', 'walk', 'Walk')}<p><strong>Walking is quickest</strong><span>No tram or bus beats the ${walk?.minutes ? `${walk.minutes}-minute ` : ''}walk ${back ? `back to ${E(trip.from.name)}` : `from ${E(trip.from.name)}`}.</span></p>${walk?.minutes ? '<div class="gt-starts"><button type="button" class="gt-start" data-gt-mode="walk">Show the walk</button></div>' : ''}</div>`;
            // A district whose centre the planner can't reach starts (or
            // ends) at its nearest stop, and says so.
            const known = J.starts.find((s) => s.id === trip.from.id);
            const snapped = trip.from.stop && known && (known.lat !== trip.from.lat || known.lng !== trip.from.lng);
            const day = new Date(item.date + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
            const captured = new Date(J.captured + 'T12:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
            put(
                `<p class="gt-checked">${I('check')}<span><span class="sr-only">We checked: </span>${checked.join(' · ')}</span></p>${body}<p class="gt-source">${I('info')}<span>Scheduled times from the VRS timetable for ${E(day)}, captured ${E(captured)}.${snapped ? ` The way ${back ? 'ends' : 'starts'} at ${E(trip.from.stop)}, the stop nearest ${E(trip.from.name)}’s centre.` : ''}${state.when === 'now' ? ` “Now” is the sample day’s ${E(J.now)}.` : ''} Not live: check departures before you go.</span></p>`,
            );
            const picked = mode === 'transit' ? options.find((o) => o.id === state.pick) : null;
            ctx.onWay?.({
                from: trip.from,
                legs: picked
                    ? picked.legs.map((l) => ({ points: l.points, walk: l.mode === 'walk', colour: l.mode === 'walk' ? '#6b6457' : look(l).colour }))
                    : mode !== 'transit' && ways[mode]?.points
                      ? [{ points: ways[mode].points, walk: mode === 'walk', colour: mode === 'bike' ? '#1d4d8f' : '#6b6457' }]
                      : [],
            });
        }
        box.addEventListener('click', (event) => {
            const pick = event.target.closest('[data-gt-pick]');
            const details = event.target.closest('[data-gt-details]');
            const start = event.target.closest('[data-gt-start]');
            const retry = event.target.closest('[data-gt-retry]');
            const walk = event.target.closest('[data-gt-mode]');
            if (pick) {
                state.pick = pick.dataset.gtPick;
                render();
                box.querySelector(`[data-gt-pick="${state.pick}"]`)?.focus({ preventScroll: true });
            } else if (details) {
                state.open = state.open === details.dataset.gtDetails ? null : details.dataset.gtDetails;
                state.pick = details.dataset.gtDetails;
                render();
                box.querySelector(`[data-gt-details="${details.dataset.gtDetails}"]`)?.focus({ preventScroll: true });
            } else if (start && input) {
                input.value = start.dataset.gtStart;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                render();
            } else if (retry) {
                failed.delete(retry.dataset.gtRetry);
                render();
            } else if (walk) {
                const radio = panel.querySelector(`input[name="mode"][value="${walk.dataset.gtMode}"]`);
                if (radio) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        });
        // Arrow keys move between the options, as in a radio group.
        box.addEventListener('keydown', (event) => {
            const pick = event.target.closest('[data-gt-pick]');
            if (!pick || !['ArrowDown', 'ArrowUp'].includes(event.key)) return;
            const all = [...box.querySelectorAll('[data-gt-pick]')];
            const next = all[(all.indexOf(pick) + (event.key === 'ArrowDown' ? 1 : -1) + all.length) % all.length];
            event.preventDefault();
            next.click();
        });
        panel.addEventListener('change', (event) => {
            if (event.target.matches('input[name="mode"], input[name="from"]')) render();
        });
        // The time's menu floats over the page; a choice, Escape or a tap
        // outside closes it.
        const showMenu = (open) => {
            if (!menu) return;
            menu.hidden = !open;
            timeButton.setAttribute('aria-expanded', String(open));
            if (open) menu.querySelector('[aria-checked="true"]')?.focus({ preventScroll: true });
        };
        timeButton?.addEventListener('click', () => showMenu(menu.hidden));
        menu?.addEventListener('click', (event) => {
            const choice = event.target.closest('[data-gt-when]');
            if (!choice) return;
            state.when = choice.dataset.gtWhen;
            state.open = null;
            showMenu(false);
            timeButton.focus({ preventScroll: true });
            render();
        });
        menu?.addEventListener('keydown', (event) => {
            const items = [...menu.querySelectorAll('[data-gt-when]')];
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                showMenu(false);
                timeButton.focus({ preventScroll: true });
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const at = items.indexOf(document.activeElement);
                items[(at + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length].focus();
            }
        });
        const outside = (event) => {
            if (!bar?.isConnected) return document.removeEventListener('pointerdown', outside, true);
            if (!menu.hidden && !bar.contains(event.target)) showMenu(false);
        };
        document.addEventListener('pointerdown', outside, true);
        input?.addEventListener('input', render);
        // Open in Travel takes the same request: by the start (the details'
        // own hand-off), leaving now, or the way back from the venue after
        // it ends.
        const form = panel.querySelector('form');
        const submit = form?.onsubmit;
        if (form)
            form.onsubmit = (event) => {
                if (state.when === 'arrive') return submit?.call(form, event);
                event.preventDefault();
                input.value = input.value.trim();
                if (!form.reportValidity()) return;
                const S = root.ExpaduStore;
                const travel = form.elements.mode.value;
                const place = input.value;
                S.update((s) => {
                    if (state.when === 'now') s.brief.from = place;
                    s.brief.mode = travel;
                });
                S.go(
                    'departures',
                    state.when === 'now'
                        ? { from: place, to: item.venue, context: item.id, mode: travel }
                        : { from: item.venue, to: place, context: item.id, date: item.date, time: item.end, mode: travel },
                );
            };
        render();
        return { render };
    }

    // The way on the map: each leg in its line's colour, walks dashed, the
    // start and the venue marked.
    function paint(map, item, way, I, marks) {
        const draw = () => {
            const legs = way?.legs || [];
            const data = {
                type: 'FeatureCollection',
                features: legs
                    .filter((l) => l.points?.length > 1)
                    .map((l) => ({ type: 'Feature', properties: { colour: l.colour, walk: l.walk ? 1 : 0 }, geometry: { type: 'LineString', coordinates: l.points.map(([lat, lng]) => [lng, lat]) } })),
            };
            if (map.getSource('gt-way')) map.getSource('gt-way').setData(data);
            else {
                map.addSource('gt-way', { type: 'geojson', data });
                map.addLayer({ id: 'gt-way-casing', type: 'line', source: 'gt-way', filter: ['==', ['get', 'walk'], 0], layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#ffffff', 'line-width': 7 } });
                map.addLayer({ id: 'gt-way-ride', type: 'line', source: 'gt-way', filter: ['==', ['get', 'walk'], 0], layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': ['get', 'colour'], 'line-width': 4.5 } });
                map.addLayer({ id: 'gt-way-walk', type: 'line', source: 'gt-way', filter: ['==', ['get', 'walk'], 1], layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': ['get', 'colour'], 'line-width': 3, 'line-dasharray': [0.1, 1.8] } });
            }
            marks.start?.remove();
            marks.start = null;
            const points = data.features.flatMap((f) => f.geometry.coordinates);
            if (way?.from) {
                const dot = document.createElement('span');
                dot.className = 'event-way-pin is-start';
                marks.start = new root.maplibregl.Marker({ element: dot }).setLngLat([way.from.lng, way.from.lat]).addTo(map);
                points.push([way.from.lng, way.from.lat]);
            }
            points.push([item.lng, item.lat]);
            if (points.length > 1) {
                const lngs = points.map((p) => p[0]),
                    lats = points.map((p) => p[1]);
                map.fitBounds([[Math.min(...lngs), Math.min(...lats)], [Math.max(...lngs), Math.max(...lats)]], { padding: 34, maxZoom: 15, duration: 0 });
            } else map.jumpTo({ center: [item.lng, item.lat], zoom: 14.5 });
        };
        if (map.isStyleLoaded()) draw();
        else map.once('load', draw);
    }

    root.ExpaduGetThere = { mount, paint, tripFor, covers };
})(globalThis);
