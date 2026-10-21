/* Plan a trip's way card and "We checked" card (plan-card.css), shared with
   the pages that plan a trip outside Travel (Events' Get there), as
   trip-card.js is for the Leave now card: trips outside Travel reuse
   Travel's own pieces unchanged. The page gives the routes (journeys.js's
   shape), the facts it checked, its reasons and which way is picked or
   open; the markup is Travel's. Needs icons.js (icon, safe) and labels.js. */
(function (root) {
    const L = root.TravelLabels;
    const minutes = (time) => {
        const [h, m] = time.split(':').map(Number);
        return h * 60 + m;
    };
    // A time before the planner's own clock is the next day.
    const overnight = (time, trip) =>
        trip?.time && time < trip.time
            ? '<sup aria-label="next day">+1</sup>'
            : '';
    function vehicleOf(line, leg) {
        // A captured journey knows its vehicle; otherwise the station capture.
        if (leg?.vehicle) return leg.vehicle === 'bus' ? 'Bus' : leg.vehicle === 'rail' ? 'Train' : 'Tram';
        const service = root.StationCapture?.services.find(
            (item) => item.line === String(line),
        );
        return service ? (service.type === 'bus' ? 'Bus' : 'Tram') : 'Line';
    }
    function optionTitle(route) {
        if (route.mode === 'bike') return 'By bike';
        if (route.mode === 'walk') return 'On foot';
        const lines = route.legs.filter((leg) => leg.mode === 'transit');
        const first = lines[0]?.line || route.line;
        return `${vehicleOf(first, lines[0])} ${first}${
            route.changes
                ? `, then ${
                      lines
                          .slice(1)
                          .map((leg) => leg.line || '')
                          .filter(Boolean)
                          .join(', ') ||
                      `${route.changes} change${route.changes > 1 ? 's' : ''}`
                  }`
                : ', no change'
        }`;
    }
    const spareLabel = (spare, margin) =>
        spare < margin
            ? L.chip('attention', 'timer', `Only ${spare} min spare`)
            : spare >= 90
              ? // Leaving now for the evening: hours early.
                L.chip(
                    'calm',
                    'clock',
                    `${Math.floor(spare / 60)} h${spare % 60 ? ` ${spare % 60} min` : ''} early`,
                )
              : L.chip('calm', 'check', `${spare} min spare`);
    // Why a way is good or not, in the trip's own facts: [words, ok or
    // warn, icon], each a label of its own. The weather is the page's.
    function reasons(route, trip, weather) {
        const list = [];
        if (route.mode === 'transit')
            list.push(
                route.changes
                    ? [
                          `${route.changes} change${route.changes > 1 ? 's' : ''}`,
                          'ok',
                          'route',
                      ]
                    : ['No change', 'ok', 'check'],
            );
        if (route.walking || route.mode !== 'transit')
            list.push([
                `${route.mode === 'transit' ? route.walking : route.duration} min ${route.mode === 'bike' ? 'cycling' : 'walking'}`,
                'ok',
                route.mode === 'bike' ? 'bike' : 'walk',
            ]);
        if (weather)
            list.push(
                weather.wet
                    ? [`${weather.sky} likely on the way (sample)`, 'warn', 'cloud']
                    : ['Dry on the way (sample)', 'ok', 'sun'],
            );
        if (route.inPlan) list.push(['In your plan', 'ok', 'calendar']);
        if (route.delayed)
            list.push(['Delay flagged in the capture', 'warn', 'clock']);
        if (trip.start) {
            const spare = minutes(trip.start) - minutes(route.arrive);
            if (spare < (trip.kind === 'official' ? 10 : 5))
                list.push([
                    `Only ${spare} min before your ${trip.start}`,
                    'warn',
                    'timer',
                ]);
        }
        return list;
    }
    // A step's words: a ride names its vehicle, line and where you get off.
    function stepWords(route, step, steps, index) {
        const plain = (text = '') => text.replace(/Köln\s+/g, '');
        if (step.mode !== 'transit')
            return { title: plain(step.title), detail: plain(step.detail) };
        const line = step.line || route.line;
        const off = steps.slice(index).find((item) => item.mode === 'alight');
        const kind =
            step.vehicle === 'bus' || L?.look(line, 'tram')?.bus
                ? 'Bus'
                : step.vehicle === 'rail'
                  ? 'Train'
                  : 'Tram';
        return {
            title: `${kind} ${line} to ${plain(off?.title.replace(/^Get off at /, '') || '')}`,
            detail: `From ${plain(step.placeName || step.from || '')} · ${plain(step.detail).replace(/^Towards /, 'towards ')}`,
        };
    }
    // The way, step by step. Travel adds its own links to a step (extra):
    // the platform, the timetable at a stop, places nearby.
    function steps(route, steps, trip, extra = () => '') {
        return `<ol class="route-steps">${steps
            .map((step, index) => ({
                step,
                words: stepWords(route, step, steps, index),
            }))
            .map(
                ({ step, words }) =>
                    `<li class="route-step ${step.mode}"><time>${step.time}${overnight(step.time, trip)}</time><span class="step-node">${icon(step.mode === 'arrive' ? 'pin' : step.mode === 'transit' ? 'train' : step.mode === 'alight' ? 'train' : step.mode === 'ride' ? 'route' : step.mode)}</span><div><strong>${safe(words.title)}</strong><small>${safe(words.detail)}</small>${extra(step)}</div></li>`,
            )
            .join('')}</ol>`;
    }
    // One way as a choice: its line badges and first tram's time, how much
    // room it leaves, when you leave and get there, why, and its steps.
    function option(route, { trip, on, expanded, best, why, way, note }) {
        const spare = trip.start
            ? minutes(trip.start) - minutes(route.arrive)
            : null;
        // The line badge (with its vehicle) is the option's name; how it
        // goes is in the reasons below.
        const transit = route.legs.filter((leg) => leg.mode === 'transit');
        const badges =
            route.mode === 'transit'
                ? transit
                      .map((leg, index) => {
                          const id = String(leg.line || route.line);
                          const vehicle = vehicleOf(id, leg);
                          const bus = vehicle === 'Bus';
                          const look = L.look(id, bus ? 'bus' : 'tram');
                          return L.line(id, look, {
                              word: index === 0,
                              kind: bus ? 'bus' : vehicle === 'Train' ? 'rail' : 'tram',
                          });
                      })
                      .join(`<span class="plan-then">${icon('arrow')}</span>`)
                : `<span class="tl-line is-mode">${icon(route.mode)}<span>${route.mode === 'bike' ? 'By bike' : 'On foot'}</span></span>`;
        // The first tram's time names the option, as the trip line does
        // ("your 13:24"; review, 16 Oct).
        const title = `<span class="plan-option-title">${badges}${transit[0]?.time ? `<span class="plan-option-at">at ${transit[0].time}</span>` : ''}</span>`;
        const after = trip.back
            ? minutes(route.depart) - minutes(trip.after) + (route.depart < '12:00' ? 1440 : 0)
            : null;
        const spareTag =
            spare !== null
                ? spareLabel(spare, trip.kind === 'official' ? 10 : 5)
                : after !== null
                  ? L.chip('calm', 'clock', after > 0 ? `${after} min after it ends` : 'As it ends')
                  : L.chip('calm', 'clock', `${route.duration} min`);
        return `<article class="plan-option${on ? ' is-on' : ''}" aria-label="${safe(optionTitle(route))}, leave ${route.depart}, there ${route.arrive}${overnight(route.arrive, trip) ? ' next day' : ''}">
        <button type="button" class="plan-option-pick" role="radio" aria-checked="${on}" tabindex="${on ? 0 : -1}" data-pick-route="${route.id}">
            <span class="plan-radio" aria-hidden="true"></span>
            <span class="plan-option-body"><span class="plan-option-head">${best ? L.chip('best', 'star', 'Best for you') : title}${spareTag}</span>${best ? title : ''}<span class="plan-option-times">Leave <strong>${route.depart}</strong> → there <strong>${route.arrive}</strong>${overnight(route.arrive, trip)}</span><span class="plan-option-reasons">${why
                .map(
                    ([text, kind, name]) =>
                        `<span class="is-${kind}"><span class="plan-reason-icon">${icon(name)}</span>${safe(text)}</span>`,
                )
                .join('')}</span></span>
        </button>
        <button class="details-toggle" data-route-details="${route.id}" aria-expanded="${expanded}" aria-controls="route-disclosure-${route.id}">Journey details ${icon('down')}</button>
        <div id="route-disclosure-${route.id}" class="route-disclosure" aria-hidden="${!expanded}" ${expanded ? '' : 'inert'}><div class="route-disclosure-inner"><div class="plan-option-way">${way}<div class="plan-option-side"><p class="route-note">${note}${route.mode === 'transit' ? '' : ' · Street directions weren’t captured.'}</p></div></div>${route.delayed ? '<p class="route-notice">Delay flagged in this capture. Current status is unknown.</p>' : ''}</div></div>
    </article>`;
    }
    // What the planner checked, as labels; Details opens each one's words.
    function checks(list, open) {
        return `<section class="plan-checks" aria-label="What we checked"><button type="button" class="plan-checks-toggle" data-action="checks" aria-expanded="${open}" aria-controls="plan-checks-detail"><strong>We checked today’s conditions</strong><span class="plan-check-chips tl-chips">${list.map((item) => L.chip(item.tone || (item.flag ? 'attention' : item.muted ? 'unsure' : 'calm'), item.icon || 'check', item.short)).join('')}</span><span class="plan-checks-more">${open ? 'Hide' : 'Details'}${icon('down')}</span></button><div id="plan-checks-detail" class="plan-checks-detail" ${open ? '' : 'hidden'}>${list.map((item) => `<p><strong>${safe(item.title)}</strong><span>${safe(item.text)}</span></p>`).join('')}</div></section>`;
    }
    // The shared sample weather at a sample-day clock, or nothing.
    const skies = {
        clear: 'Clear',
        partly: 'Partly cloudy',
        cloudy: 'Cloudy',
        showers: 'Showers',
        rain: 'Rain',
        mist: 'Mist',
    };
    function weather(date, time) {
        const hours = root.ExpaduSampleWeather?.days?.[date];
        const hour = hours?.[Number(time.slice(0, 2))];
        if (!hour) return null;
        const [sky, degrees, rain] = hour;
        return { sky: skies[sky] || sky, degrees, rain, wet: rain >= 50 };
    }
    // Where a trip's times come from, under its steps.
    const note = (trip) =>
        trip.evening
            ? 'Sample day · VRS timetable, scheduled times'
            : trip.id === 'day'
              ? 'Sample day · scheduled times'
              : '7 Sep 2026 capture';
    // What the planner checked for a trip, as the labels and words of the
    // "We checked" card. The page gives what it knows: whether it's the
    // sample day, its weather when you leave, the plans between now and
    // the start, any sample condition (scenario), step-free asked for, and
    // how it writes a date.
    function facts(trip, ctx) {
        const { day, weather, between } = ctx;
        return [
            {
                short: 'Departures',
                icon: day ? 'check' : 'offline',
                tone: day ? 'calm' : 'unsure',
                title: day ? 'Departures' : 'Departures, as captured',
                text: trip.evening
                    ? `Scheduled times from the VRS timetable for ${ctx.dateLabel(trip.date)}, captured 13 Oct`
                    : day
                    ? `Scheduled sample times for ${ctx.dateLabel(trip.date)}`
                    : `Captured ${ctx.dateLabel(trip.date)} at ${trip.time}: that night’s times`,
            },
            {
                short:
                    ctx.scenario === 'cancelled' && day
                        ? '1 notice'
                        : day
                          ? 'No notices'
                          : 'Notices',
                icon: ctx.scenario === 'cancelled' && day ? 'alert' : 'check',
                tone:
                    ctx.scenario === 'cancelled' && day
                        ? 'conflict'
                        : day
                          ? 'calm'
                          : 'unsure',
                title: 'KVB notices',
                text:
                    ctx.scenario === 'cancelled' && day
                        ? 'Tram 4 at 13:14 is cancelled (sample)'
                        : day
                          ? 'None in the sample day'
                          : 'Only historical examples were captured; current notices aren’t known',
                flag: ctx.scenario === 'cancelled' && day,
            },
            {
                short: 'Weather',
                icon: weather?.wet ? 'cloud' : 'sun',
                title: 'Weather',
                text: weather
                    ? `${weather.sky}, ${weather.degrees}° when you leave · sample weather`
                    : 'Not recorded for this trip',
                muted: !weather,
            },
            {
                short: 'My day',
                title: 'My day',
                text: trip.back
                    ? `After ${trip.event}, which ends at ${trip.after}`
                    : day
                      ? between.length
                          ? `${between.map((place) => `${place.title} at ${place.time}`).join(', ')} before this`
                          : `Nothing else before ${trip.start}`
                      : 'Not part of your sample day',
                muted: !day,
            },
            {
                short:
                    ctx.scenario === 'lift'
                        ? 'Lift out of order'
                        : 'Lifts unknown',
                icon: ctx.scenario === 'lift' ? 'lift' : 'liftok',
                tone:
                    ctx.scenario === 'lift'
                        ? 'conflict'
                        : ctx.stepfree
                          ? 'attention'
                          : 'unsure',
                title: 'Lifts on the way',
                text:
                    ctx.scenario === 'lift'
                        ? 'A lift at Neumarkt is out of order (sample)'
                        : ctx.stepfree
                          ? 'Lift status isn’t in this preview, so we can’t promise step-free'
                          : 'Not in this preview',
                muted: ctx.scenario !== 'lift',
                flag: ctx.stepfree || ctx.scenario === 'lift',
            },
        ];
    }
    root.TravelPlanCard = {
        vehicleOf,
        optionTitle,
        spareLabel,
        reasons,
        steps,
        option,
        checks,
        facts,
        weather,
        note,
    };
})(globalThis);
