/* Read-only UI projections. Additional events are fictional design samples. */
(function (root) {
    const area = (value) =>
        String(value || '')
            .trim()
            .toLocaleLowerCase();
    const sampleEvents = [
        {
            id: 'language',
            title: 'Language café',
            start: '15:00',
            end: '16:00',
            area: 'Mülheim',
            venue: 'Mülheim · sample venue',
            category: 'Language',
            icon: 'chat',
            illustration: 'tandem',
        },
        {
            id: 'chess',
            title: 'Chess & coffee',
            start: '18:00',
            end: '19:30',
            area: 'Ehrenfeld',
            venue: 'Ehrenfeld · sample venue',
            category: 'Games',
            icon: 'ball',
            illustration: 'schach',
        },
        {
            id: 'riverside',
            title: 'After-work riverside walk',
            start: '17:00',
            end: '18:00',
            area: 'Deutz',
            venue: 'Deutz · sample meeting point',
            category: 'Outdoors',
            icon: 'leaf',
            illustration: 'forest',
        },
    ];
    const citySources = {
        Cologne: [
            {
                name: 'KVB transport',
                url: 'https://www.kvb.koeln/fahrtinfo/betriebslage/',
                host: 'www.kvb.koeln',
                icon: 'train',
            },
            {
                name: 'City news',
                url: 'https://www.stadt-koeln.de/politik-und-verwaltung/presse/',
                host: 'www.stadt-koeln.de',
                icon: 'document',
            },
        ],
    };
    function prepare(day) {
        const focus = root.TodayNext.paperworkFocus(day.paperwork);
        const firstVisit = day.name === 'first-visit';
        return {
            ...day,
            firstVisit,
            agenda:
                firstVisit || day.name === 'preparing'
                    ? []
                    : day.name === 'event'
                      ? day.agenda.map((item) => ({
                            ...item,
                            start: '16:00',
                            end: '17:00',
                        }))
                      : day.agenda,
            time: day.name === 'later' ? '16:45' : day.time,
            upcoming: firstVisit ? [] : day.upcoming,
            preparations:
                day.name === 'preparing'
                    ? [
                          {
                              id: 'tomorrow-review',
                              title: 'Review tomorrow’s plan',
                              dueDate: day.date,
                              targetDate: '2026-10-01',
                              source: 'user',
                              status: 'open',
                          },
                      ]
                    : [],
            paperwork: firstVisit ? [] : focus ? [focus] : [],
            events: [
                ...day.events,
                ...sampleEvents.map((item) => ({
                    ...item,
                    id: `today-review-${item.id}`,
                    occurrence: `today-review-${item.id}-${day.date}`,
                    type: 'event',
                    date: day.date,
                    compact: true,
                    sample: true,
                    attendancePolicy: 'fixed',
                    captured: 'Fictional sample programme',
                    color: 'blue',
                    description:
                        'A fictional event for this design preview. The date, time and venue are examples, not a published listing.',
                })),
            ],
        };
    }
    const minutes = (time) => root.TodayNext.minutes(time);
    const clock = (value) =>
        Number.isFinite(value) && value >= 0 && value < 1440
            ? `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`
            : null;
    // Fictional transit estimates between sample neighbourhoods, in minutes.
    const sampleTravel = {
        'ehrenfeld|mülheim': 30,
        'mülheim|stadtgarten': 25,
        'ehrenfeld|stadtgarten': 15,
        'ehrenfeld|rheinauhafen': 25,
        'mülheim|rheinauhafen': 30,
        'rheinauhafen|stadtgarten': 20,
        'deutz|mülheim': 15,
        'deutz|ehrenfeld': 25,
    };
    const travelEstimate = (from, to) => {
        const pair = [area(from), area(to)].sort().join('|');
        return sampleTravel[pair] ?? null;
    };
    // A personal deadline from the user's own records that is close enough to
    // lead the page. Wording comes from the record, never a guessed rule.
    function deadline(day) {
        const record = (day.paperwork || []).find(
            (item) =>
                item.deadline &&
                !item.submitted &&
                root.TodayContinuity.validDate(item.deadline),
        );
        if (!record) return null;
        const days = Math.round(
            (Date.parse(`${record.deadline}T12:00:00Z`) -
                Date.parse(`${day.date}T12:00:00Z`)) /
                86400000,
        );
        if (days < 0 || days > 3) return null;
        return {
            ...record,
            days,
            when:
                days === 0
                    ? 'today'
                    : days === 1
                      ? 'tomorrow'
                      : `in ${days} days`,
        };
    }
    // When to leave, and whether the arrival leaves enough room. Official
    // appointments get a larger margin for queues and security checks.
    function leaveBy(item, now) {
        const j = item.journey;
        const t = j && root.TodayNext.travel(item);
        if (!t || t.stale || !t.departure) return null;
        const leave = minutes(t.departure) - (j.accessWalkMinutes ?? 0);
        const arrival = minutes(t.destinationArrival);
        const spare = arrival === null ? null : minutes(item.start) - arrival;
        const margin = item.kind === 'official' ? 10 : 5;
        const tight = spare !== null && spare >= 0 && spare < margin;
        let earlier = null;
        if (tight && !j.delay && j.earlier) {
            const earlierLeave =
                minutes(j.earlier.depart) - (j.accessWalkMinutes ?? 0);
            const earlierArrival =
                minutes(j.earlier.arrive) + (j.walkMinutes ?? 0);
            if (earlierLeave >= now)
                earlier = {
                    depart: j.earlier.depart,
                    leave: clock(earlierLeave),
                    leaveIn: earlierLeave - now,
                    spare: minutes(item.start) - earlierArrival,
                };
        }
        return {
            leave: clock(leave),
            leaveIn: leave - now,
            spare,
            margin,
            tight,
            earlier,
        };
    }
    // Travel between consecutive plans in different places.
    function connections(active) {
        const timed = active
            .filter((item) => minutes(item.start) !== null)
            .slice()
            .sort((a, b) => minutes(a.start) - minutes(b.start));
        const links = {};
        for (let i = 1; i < timed.length; i++) {
            const from = timed[i - 1],
                to = timed[i];
            const end = minutes(from.end || from.start);
            if (!area(from.location) || !area(to.location)) continue;
            if (area(from.location) === area(to.location)) continue;
            const gap = minutes(to.start) - end;
            const estimate = travelEstimate(from.location, to.location);
            links[to.id] = {
                from,
                to,
                gap,
                estimate,
                status:
                    estimate === null
                        ? 'unknown'
                        : gap < estimate
                          ? 'conflict'
                          : gap < estimate + 10
                            ? 'tight'
                            : 'ok',
            };
        }
        return links;
    }
    const unfinished = (item, now) =>
        !item.completed &&
        (minutes(item.end || item.start) === null ||
            minutes(item.end || item.start) > now);
    function savedForToday(day, saved = []) {
        return saved.filter(
            (item) =>
                item.type !== 'event' ||
                item.date > day.date ||
                (item.date === day.date && unfinished(item, minutes(day.time))),
        );
    }
    function priority(day, content = {}) {
        const now = minutes(day.time);
        const active = day.agenda
            .filter((item) => unfinished(item, now))
            .slice()
            .sort(
                (a, b) =>
                    (minutes(a.start) ?? 1440) - (minutes(b.start) ?? 1440),
            );
        const finished = day.agenda.filter((item) => !unfinished(item, now));
        const imminent = active.find((item) => {
            const departure =
                item.journey?.activityId === item.id
                    ? minutes(
                          root.TodayNext.travel(item)?.departure ||
                              item.journey.depart,
                      )
                    : null;
            return (
                Math.min(minutes(item.start) ?? 1440, departure ?? 1440) -
                    now <=
                60
            );
        });
        const changed =
            imminent?.journey?.activityId === imminent?.id &&
            !!imminent?.journey &&
            (imminent.journey.delay > 0 ||
                root.TodayNext.travel(imminent).stale);
        const preparation = (day.preparations || []).find(
            (item) =>
                item.source === 'user' &&
                item.status === 'open' &&
                item.dueDate === day.date &&
                item.targetDate >= day.date,
        );
        const composer = content.composer;
        const todayDecision =
            ['proposal', 'selections'].includes(composer?.kind) &&
            composer.date === day.date &&
            (!composer.items?.length ||
                composer.items.some((item) => unfinished(item, now)));
        const events = day.events.filter((item) => {
            if (item.date !== day.date || !unfinished(item, now)) return false;
            const start = minutes(item.start),
                end = minutes(item.end);
            if (start === null) return false;
            // A fixed event that has already started is not a new plan suggestion.
            if (item.attendancePolicy === 'fixed' && start < now) return false;
            return !active.some((plan) => {
                const a = minutes(plan.start),
                    b = minutes(plan.end);
                return (
                    plan.id === item.id ||
                    (a !== null &&
                        b !== null &&
                        end !== null &&
                        start < b &&
                        end > a)
                );
            });
        });
        const highlight =
            day.highlight?.important &&
            events.find((item) => item.id === day.highlight.id);
        const lead = imminent
            ? 'agenda'
            : preparation
              ? 'preparation'
              : todayDecision
                ? 'composer'
                : highlight
                  ? 'event'
                  : active.length
                    ? 'agenda'
                    : day.firstVisit
                      ? 'essentials'
                      : 'discovery';
        const order = [
            ...(active.length
                ? ['agenda', 'essentials']
                : ['essentials', 'agenda']),
            'around',
            'discovery',
            'composer',
            'saved',
            'upcoming',
            'support',
        ];
        if (lead !== 'event') {
            const index = order.indexOf(lead);
            if (index >= 0) order.splice(index, 1);
            order.unshift(lead);
        }
        if (todayDecision && lead !== 'composer') {
            order.splice(order.indexOf('composer'), 1);
            order.splice(1, 0, 'composer');
        }
        if (preparation && lead !== 'preparation')
            order.splice(1, 0, 'preparation');
        if (lead === 'discovery') {
            // A free day opens with ideas, then the way to add a plan.
            order.splice(order.indexOf('agenda'), 1);
            order.splice(1, 0, 'agenda');
        }
        const urgent = deadline(day);
        if (urgent) order.unshift('deadline');
        return {
            lead,
            urgent,
            freeDay: !active.length && !day.firstVisit,
            reason: changed
                ? 'journey-change'
                : imminent
                  ? 'next-commitment'
                  : lead,
            active,
            finished,
            preparation,
            events,
            order,
            todayDecision,
            emptyLabel: 'No plans added for today',
        };
    }
    function board(timetable, id) {
        const stop = timetable.stops.find((item) => item.id === id);
        if (!stop) return null;
        const { rows, total } = timetable.departures(
            id,
            stop.capture.date,
            stop.capture.time,
            3,
        );
        return {
            stop,
            date: stop.capture.date,
            time: stop.capture.time,
            rows,
            total,
        };
    }
    function around(day, saved = []) {
        const plans = [
            ...day.agenda.map((item) => ({ ...item, date: day.date })),
            ...(day.upcoming || []),
        ];
        const anchors = plans
            .filter(
                (item) =>
                    item.date === day.date &&
                    !item.completed &&
                    area(item.location) &&
                    area(item.location) !== area(day.city),
            )
            .filter(
                (item) =>
                    item.date > day.date ||
                    root.TodayNext.minutes(item.end || item.start) >=
                        root.TodayNext.minutes(day.time),
            );
        for (const anchor of anchors) {
            const candidates = [
                ...saved.map((item) => ({ item, saved: true })),
                ...day.events.map((item) => ({ item, saved: false })),
            ];
            const found = candidates.find(({ item }) => {
                if (!item || area(item.area) !== area(anchor.location))
                    return false;
                if (day.agenda.some((plan) => plan.id === item.id))
                    return false;
                if (item.type === 'place') return true;
                const start = root.TodayNext.minutes(item.start);
                const end = root.TodayNext.minutes(item.end);
                const after = root.TodayNext.minutes(anchor.end);
                const conflict = plans.some((plan) => {
                    const planStart = root.TodayNext.minutes(plan.start);
                    const planEnd = root.TodayNext.minutes(plan.end);
                    return (
                        plan.date === item.date &&
                        start !== null &&
                        end !== null &&
                        planStart !== null &&
                        planEnd !== null &&
                        start < planEnd &&
                        end > planStart
                    );
                });
                return (
                    item.type === 'event' &&
                    !conflict &&
                    item.date === anchor.date &&
                    start !== null &&
                    after !== null &&
                    start >= after
                );
            });
            if (found) return { ...found, anchor };
        }
        return null;
    }
    function decision(composer, day) {
        if (!composer) return null;
        if (composer.kind === 'message')
            return {
                kind: 'message',
                title: 'Your message is still unsent',
                detail: 'Continue writing when you’re ready.',
            };
        if (composer.kind === 'conversation')
            return {
                kind: 'conversation',
                title: 'Continue the conversation',
                detail: 'Your previous choices stay with you.',
            };
        const items = composer.items || [];
        if (!items.length)
            return {
                kind: 'changes',
                title: 'Review the changes to your plan',
                detail: 'Your saved plan stays as it is until you confirm.',
            };
        if (composer.pending)
            return {
                kind: 'selections',
                count: composer.pending,
                title: `${composer.pending} more ${composer.pending === 1 ? 'selection' : 'selections'} to review`,
                detail: 'Bring them into your draft before confirming.',
            };
        const passed =
            day &&
            composer.date === day.date &&
            items.filter(
                (item) =>
                    minutes(item.start) !== null &&
                    minutes(item.start) < minutes(day.time),
            );
        if (passed?.length)
            return {
                kind: 'passed-time',
                count: passed.length,
                title: `Recheck ${passed.length === 1 ? 'the start time' : 'the start times'}`,
                detail: passed
                    .slice(0, 2)
                    .map((item) => `${item.title} · ${item.start} has passed`)
                    .join(' · '),
            };
        const missing = items.filter(
            (item) => root.TodayNext.minutes(item.start) === null,
        );
        if (missing.length)
            return {
                kind: 'time',
                count: missing.length,
                title: `Choose ${missing.length === 1 ? 'a time' : `${missing.length} times`}`,
                detail: missing
                    .slice(0, 2)
                    .map((item) => item.title)
                    .join(' · '),
            };
        return {
            kind: 'review',
            count: items.length,
            title: `${items.length} ${items.length === 1 ? 'activity' : 'activities'} ready to review`,
            detail: 'Your draft has not been added to My plans.',
        };
    }
    function updates(feed, city, date) {
        if (!feed?.connected || !root.TodayContinuity.validDate(date))
            return [];
        const sources = citySources[city] || [];
        const now = new Date(`${date}T12:00:00Z`).valueOf();
        return (Array.isArray(feed.items) ? feed.items : [])
            .filter((item) => {
                if (
                    !item?.verified ||
                    !item.title ||
                    item.city !== city ||
                    !root.TodayContinuity.validDate(item.date)
                )
                    return false;
                const age = now - new Date(`${item.date}T12:00:00Z`).valueOf();
                if (age < 0 || age > 2 * 86400000) return false;
                try {
                    const url = new URL(item.url);
                    return (
                        url.protocol === 'https:' &&
                        sources.some((source) => source.host === url.hostname)
                    );
                } catch {
                    return false;
                }
            })
            .slice(0, 3);
    }
    root.TodayContent = {
        prepare,
        priority,
        savedForToday,
        board,
        around,
        decision,
        updates,
        citySources,
        deadline,
        leaveBy,
        connections,
        travelEstimate,
    };
})(globalThis);
