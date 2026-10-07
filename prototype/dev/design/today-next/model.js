/* Presentation rules and clearly fictional review data; no live or legal claims. */
(function (root) {
    const clone = (x) => JSON.parse(JSON.stringify(x));
    const minutes = (time) =>
        /^([01]\d|2[0-3]):[0-5]\d$/.test(time || '')
            ? Number(time.slice(0, 2)) * 60 + Number(time.slice(3))
            : null;
    const clock = (value) =>
        value >= 0 && value < 1440
            ? `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`
            : null;
    const events = [
        {
            id: 'today-review-culture',
            type: 'event',
            title: 'Street culture night',
            date: '2026-09-30',
            start: '18:30',
            end: '21:00',
            venue: 'Rheinauhafen',
            area: 'Cologne',
            category: 'Culture',
            icon: 'calendar',
            color: 'blue',
            attendancePolicy: 'fixed',
            captured: 'Sample programme',
            illustration: 'garden',
            occurrence: 'today-review-culture-2026-09-30',
            description:
                'An illustrative evening of music by the Rhine. Sample event for this design preview.',
        },
        {
            id: 'today-review-cinema',
            type: 'event',
            title: 'Cinema under the stars',
            date: '2026-09-30',
            start: '20:00',
            end: '22:00',
            venue: 'Stadtgarten',
            area: 'Cologne',
            category: 'Film',
            icon: 'film',
            color: 'blue',
            attendancePolicy: 'fixed',
            captured: 'Sample programme',
            illustration: 'film',
            occurrence: 'today-review-cinema-2026-09-30',
            description:
                'An illustrative outdoor film night. Sample event for this design preview.',
        },
    ];
    const appointment = {
        id: 'today-review-appointment',
        title: 'Blue Card appointment',
        location: 'Mülheim',
        start: '14:00',
        end: '14:30',
        planStatus: 'Confirmed',
        // Recorded from Paperwork, so Today can show what to bring.
        process: 'blue-card',
        kind: 'official',
        journey: {
            activityId: 'today-review-appointment',
            from: 'Neumarkt',
            to: 'Mülheim',
            line: '4',
            depart: '13:24',
            arrive: '13:52',
            expectedDepart: '13:24',
            expectedArrive: '13:52',
            walkMinutes: 6,
            // Sample walk from the starting point to the boarding stop.
            accessWalkMinutes: 4,
            // Sample earlier service on the same line, offered when the
            // scheduled one leaves too little time before an appointment.
            earlier: { depart: '13:14', arrive: '13:42' },
            status: 'scheduled',
            delay: 0,
        },
    };
    // Fictional choices shown when the planned service is delayed.
    const delayAlternatives = [
        {
            id: 'sbahn',
            mode: 'train',
            label: 'Line 18 + S-Bahn S11',
            detail: 'Change at Köln Hbf',
            leave: '13:13',
            arrive: '13:50',
        },
        {
            id: 'bike',
            mode: 'bike',
            label: 'Cycle',
            detail: 'About 30 min',
            leave: '13:22',
            arrive: '13:52',
        },
        {
            id: 'wait',
            mode: 'train',
            label: 'Stay on Line 4',
            detail: 'Departs 13:36, delayed 12 min',
            leave: '13:32',
            arrive: '14:10',
        },
    ];
    function scenario(name) {
        const day = {
            name,
            date: '2026-09-30',
            time: '13:10',
            city: 'Cologne',
            person: 'Anar',
            agenda: [clone(appointment)],
            upcoming: [
                {
                    id: 'today-review-next-class',
                    title: 'Language class',
                    location: 'Ehrenfeld',
                    date: '2026-10-01',
                    start: '18:00',
                    end: '19:00',
                },
                {
                    id: 'today-review-next-walk',
                    title: 'Walk by the Rhine',
                    location: 'Rheinauhafen',
                    date: '2026-10-03',
                    start: '10:30',
                    end: '11:30',
                },
            ],
            removed: [],
            events: clone(events),
            highlight: null,
            paperwork: [
                {
                    id: 'blue-card',
                    title: 'EU Blue Card',
                    prepared: 1,
                    total: 5,
                    nextAction: 'Review your documents',
                    // Matches the Paperwork preview's sample record.
                    deadline: '2026-10-01',
                    deadlineLabel: 'D visa expires',
                    deadlineAction:
                        'Submit the Blue Card application before your D visa expires',
                    deadlineShort: 'Submit your Blue Card application',
                    submitted: false,
                },
                { id: 'address', title: 'City registration' },
            ],
        };
        if (name === 'quiet') day.agenda = [];
        if (name === 'busy')
            day.agenda.push(
                {
                    id: 'today-review-class',
                    title: 'Language class',
                    location: 'Ehrenfeld',
                    start: '15:30',
                    end: '16:30',
                    planStatus: 'Confirmed',
                },
                {
                    id: 'today-review-friend',
                    title: 'Meet Maya',
                    location: 'Stadtgarten',
                    start: '17:30',
                    end: '18:15',
                    planStatus: 'Confirmed',
                },
            );
        if (['delay', 'competing'].includes(name))
            Object.assign(day.agenda[0].journey, {
                expectedDepart: '13:36',
                expectedArrive: '14:04',
                delay: 12,
                status: 'simulated',
                alternatives: clone(delayAlternatives),
            });
        if (['event', 'competing'].includes(name))
            day.highlight = { ...day.events[0], important: true };
        if (name === 'event')
            day.agenda = [
                {
                    id: 'today-review-dinner',
                    title: 'Dinner with friends',
                    location: 'Ehrenfeld',
                    start: '19:00',
                    end: '20:30',
                    planStatus: 'Confirmed',
                },
            ];
        if (name === 'unavailable')
            Object.assign(day.agenda[0].journey, {
                status: 'stale',
                walkMinutes: null,
                expectedArrive: null,
                expectedDepart: null,
            });
        return day;
    }
    function travel(item) {
        const j = item.journey;
        if (!j) return null;
        const stale = j.status === 'stale' || j.activityId !== item.id;
        const arrival = minutes(j.expectedArrive);
        const hasWalk = Number.isFinite(j.walkMinutes) && j.walkMinutes >= 0;
        const destinationArrival =
            !stale && arrival !== null && hasWalk
                ? clock(arrival + j.walkMinutes)
                : null;
        const target = minutes(item.start);
        return {
            stationArrival: stale ? null : j.expectedArrive,
            departure: stale ? null : j.expectedDepart,
            destinationArrival,
            lateMinutes:
                destinationArrival && target !== null
                    ? Math.max(0, minutes(destinationArrival) - target)
                    : null,
            estimated: j.status === 'simulated',
            stale,
        };
    }
    function priority(day) {
        const now = minutes(day.time);
        const active = day.agenda.filter(
            (item) => minutes(item.end || item.start) >= now,
        );
        const changed = active.some(
            (item) =>
                item.journey?.activityId === item.id &&
                item.journey?.status === 'simulated' &&
                item.journey.delay > 0,
        );
        const imminent = active.some((item) => minutes(item.start) - now <= 60);
        const event = day.highlight;
        return !changed &&
            !imminent &&
            event?.important &&
            event.date === day.date &&
            minutes(event.end) > now
            ? 'event'
            : 'day';
    }
    function status(item) {
        return item.bookingConfirmed === true && item.bookingSource === 'user'
            ? 'Booking recorded'
            : 'In your plan';
    }
    function remove(state, id) {
        const index = state.agenda.findIndex((item) => item.id === id);
        if (index < 0) return false;
        state.removed.push({ item: state.agenda[index], index });
        state.agenda.splice(index, 1);
        return true;
    }
    function undo(state) {
        const entry = state.removed.pop();
        if (!entry) return false;
        state.agenda.splice(entry.index, 0, entry.item);
        return entry.item.id;
    }
    function withContext(shared, item, date) {
        const next = clone(shared),
            j = item.journey;
        const context = {
            id: `today-context-${item.id}`,
            type: 'journey',
            title: `Sample journey to ${item.title}`,
            from: j?.from || '',
            to: item.location,
            date,
            activityId: item.id,
            targetStart: item.start,
            targetLocation: item.location,
            handoffDate: date,
            scheduled: false,
            sample: true,
            requestedTime: item.start,
            preferences: { schedule: 'arrive' },
            finalWalkMinutes: j?.walkMinutes ?? null,
        };
        next.draft = (next.draft || []).filter((x) => x.id !== context.id);
        next.draft.push(context);
        if (!next.prompt?.trim())
            next.prompt = `How do I get to ${item.location} by ${item.start} on ${dayLabel(date)}? It’s for my ${item.title}.`;
        return next;
    }
    // Requests read like a person wrote them: "Thursday 1 October", not an ISO date.
    function dayLabel(date) {
        return new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            timeZone: 'UTC',
        });
    }
    function planList(items) {
        const parts = items.map(
            (item) => `${item.title} in ${item.location} at ${item.start}`,
        );
        return parts.length > 1
            ? `${parts.slice(0, -1).join(', ')} and ${parts.at(-1)}`
            : parts[0] || '';
    }
    function paperworkFocus(records) {
        return (
            records.find(
                (record) =>
                    Number.isInteger(record.prepared) &&
                    Number.isInteger(record.total) &&
                    record.prepared >= 0 &&
                    record.total > record.prepared,
            ) || null
        );
    }
    function dayBrief(day) {
        const active = day.agenda
            .filter(
                (item) =>
                    minutes(item.end || item.start) !== null &&
                    minutes(item.end || item.start) > minutes(day.time),
            )
            .sort((a, b) => a.start.localeCompare(b.start));
        const item =
            active.find(
                (item) => travel(item)?.estimated && item.journey.delay > 0,
            ) || active[0];
        if (!item)
            return {
                kind: 'open',
                activityId: null,
                label: 'Today',
                value: 'Room for a plan',
                detail: 'Choose what fits your day',
            };
        const journey = travel(item);
        const detail = `${item.title} · ${item.start}`;
        if (journey?.stale)
            return {
                kind: 'journey',
                activityId: item.id,
                label: 'Travel status unavailable',
                value: 'Check your route',
                detail,
            };
        if (journey?.estimated && item.journey.delay > 0)
            return {
                kind: 'delay',
                activityId: item.id,
                label: 'Journey update · Simulated',
                value: `${item.journey.delay} min delay`,
                detail,
            };
        if (
            journey?.departure &&
            minutes(journey.departure) >= minutes(day.time)
        )
            return {
                kind: 'journey',
                activityId: item.id,
                label: 'Next departure',
                value: journey.departure,
                detail,
            };
        return {
            kind: 'plan',
            activityId: item.id,
            label:
                minutes(item.start) <= minutes(day.time)
                    ? 'In progress'
                    : 'Next plan',
            value: item.start,
            detail: item.title,
        };
    }
    function suggestion(day, intent) {
        const already = (items) =>
            items.length ? ` I already have ${planList(items)}.` : '';
        if (intent === 'day')
            return day.agenda.length
                ? `Help me plan the rest of ${dayLabel(day.date)}.${already(day.agenda)}`
                : `Help me plan my day on ${dayLabel(day.date)}.`;
        if (intent === 'paperwork')
            return 'Help me settle in: which paperwork should I sort out first?';
        if (intent === 'tomorrow') {
            const next = new Date(`${day.date}T12:00:00Z`);
            next.setUTCDate(next.getUTCDate() + 1);
            const date = next.toISOString().slice(0, 10);
            const plans = (day.upcoming || []).filter(
                (item) => item.date === date,
            );
            return `Help me plan ${dayLabel(date)}.${already(plans)}`;
        }
        return '';
    }
    function withSuggestion(shared, suggestion) {
        const next = clone(shared);
        const current = next.prompt || '';
        if (suggestion && !current.includes(suggestion))
            next.prompt = current.trim()
                ? `${current}\n\n${suggestion}`
                : suggestion;
        return next;
    }
    root.TodayNext = {
        scenario,
        travel,
        priority,
        status,
        remove,
        undo,
        withContext,
        dayLabel,
        minutes,
        paperworkFocus,
        dayBrief,
        suggestion,
        withSuggestion,
    };
})(globalThis);
