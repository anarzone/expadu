/* Explicit cross-department choices. No live availability or inferred bookings. */
(function (root) {
    const clone = (x) => JSON.parse(JSON.stringify(x));
    const key = (x) => `${x.type}:${x.occurrence || x.id}`;
    const dateOK = (x) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(x || '')) return false;
        const date = new Date(x + 'T12:00:00Z');
        return (
            Number.isFinite(date.getTime()) &&
            date.toISOString().slice(0, 10) === x
        );
    };
    const timeOK = (x) => /^([01]\d|2[0-3]):[0-5]\d$/.test(x || '');
    function bookmark(state, item) {
        state.savedIdeas ||= [];
        const i = state.savedIdeas.findIndex((x) => key(x) === key(item));
        if (i >= 0) state.savedIdeas.splice(i, 1);
        else state.savedIdeas.push(clone(item));
    }
    function schedule(item, choice) {
        if (!String(item.title || '').trim())
            throw Error('Add an activity title.');
        if (!dateOK(choice.date)) throw Error('Choose a valid date.');
        let { start, end } = choice;
        if (item.type === 'event') {
            if (choice.date !== item.date)
                throw Error('Keep the event’s original date.');
            if (!['session', 'visit'].includes(choice.attendance))
                throw Error('Choose how you will attend.');
            if (
                choice.attendance === 'visit' &&
                item.attendancePolicy !== 'window'
            )
                throw Error(
                    item.attendancePolicy === 'fixed'
                        ? 'Keep this event’s fixed session time.'
                        : 'Keep the listed event time. Flexible entry has not been confirmed.',
                );
            if (choice.attendance === 'session') {
                start = item.start;
                end = item.end || choice.end;
            }
            if (
                choice.attendance === 'visit' &&
                (start < item.start || (item.end && end > item.end))
            )
                throw Error('Keep your visit within the listed time range.');
        }
        if (!timeOK(start) || !timeOK(end) || start >= end)
            throw Error('Enter valid times, with the end after the start.');
        return {
            ...clone(item),
            date: choice.date,
            handoffDate: choice.date,
            start,
            end,
            scheduled: true,
            ...(item.type === 'event'
                ? {
                      listedStart: item.start,
                      listedEnd: item.end,
                      attendance: choice.attendance,
                      entryUnconfirmed: !['window', 'fixed'].includes(
                          item.attendancePolicy,
                      ),
                      endEstimated: !item.end,
                      fixed: choice.attendance === 'session',
                  }
                : {}),
        };
    }
    function suggestTimes(items, earliest = 9 * 60, window = {}) {
        const minutes = (value) => {
            const [hours, mins] = value.split(':').map(Number);
            return hours * 60 + mins;
        };
        const format = (value) =>
            `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
        const occupied = items
            .filter((item) => timeOK(item.start))
            .map((item) => [
                minutes(item.start),
                timeOK(item.end) ? minutes(item.end) : 24 * 60,
            ]);
        const available = [];
        for (
            let start =
                Math.ceil(
                    Math.max(
                        timeOK(window.from) ? minutes(window.from) : 9 * 60,
                        earliest,
                    ) / 30,
                ) * 30;
            start + 60 <=
            (timeOK(window.until) ? minutes(window.until) : 21 * 60);
            start += 30
        ) {
            if (
                !occupied.some(
                    ([from, until]) => start < until && start + 60 > from,
                )
            )
                available.push(start);
        }
        const selected = [];
        for (const preferred of [10 * 60, 14 * 60, 18 * 60]) {
            const start = available
                .filter(
                    (value) =>
                        !selected.some((other) => Math.abs(value - other) < 60),
                )
                .sort(
                    (a, b) =>
                        Math.abs(a - preferred) - Math.abs(b - preferred) ||
                        a - b,
                )[0];
            if (start !== undefined) selected.push(start);
        }
        return selected
            .sort((a, b) => a - b)
            .map((start) => ({
                start: format(start),
                end: format(start + 60),
            }));
    }
    function reviewItems(shared, workspace, date) {
        if (shared.reviewDay?.date !== date) return [];
        const consumed = new Set(workspace?.consumedReviewItems?.[date] || []);
        return (shared.reviewDay.agenda || []).filter(
            (item) => !consumed.has(String(item.id).replace(/^event:/, '')),
        );
    }
    function dayItems(shared, workspace, date) {
        const proposal =
            workspace?.proposal?.date === date ? workspace.proposal : null;
        const plan = proposal || workspace?.plans?.find((p) => p.date === date);
        const entries = [
            ...(!plan ? reviewItems(shared, workspace, date) : [])
                .filter((item) => !item.completed)
                .map((item) => ({
                    ...item,
                    date,
                    planStatus: 'Sample plan',
                })),
            ...(plan?.items || []).map((item) => ({
                ...item,
                planStatus: proposal ? 'Proposal' : 'Confirmed',
            })),
            ...(shared.draft || [])
                .filter(
                    (item) =>
                        item.scheduled &&
                        (item.handoffDate || item.date) === date,
                )
                .map((item) => ({ ...item, planStatus: 'Pending' })),
        ];
        return [
            ...new Map(
                entries.map((item) => [
                    String(item.id).replace(/^event:/, ''),
                    item,
                ]),
            ).values(),
        ]
            .filter((item) => !item.completed)
            .sort((a, b) =>
                String(a.start || '').localeCompare(String(b.start || '')),
            );
    }
    function conflict(shared, workspace, item) {
        if (workspace?.proposal && workspace.proposal.date !== item.date)
            return 'Finish or discard your current proposal before adding to another day.';
        if (
            (shared.draft || []).some(
                (x) => x.scheduled && x.date !== item.date,
            )
        )
            return 'Review your pending selection before adding to another day.';
        const list = dayItems(shared, workspace, item.date);
        const same = list.find(
            (x) => x.id === item.id || x.id === `event:${item.id}`,
        );
        if (same)
            return 'Already selected for this day. Open Composer to change it.';
        const overlap = list.find(
            (x) =>
                timeOK(x.start) &&
                timeOK(x.end) &&
                item.start < x.end &&
                x.start < item.end,
        );
        return overlap
            ? `This overlaps ${overlap.title}. ${item.fixed ? 'Review your day before adding this event.' : 'Choose another time or review your plan.'}`
            : '';
    }
    function reviewURL(item) {
        const params = new URLSearchParams();
        if (item.type === 'journey') {
            for (const [name, value] of Object.entries({
                from: item.from,
                to: item.to,
                date: item.date,
                mode: ['transit', 'bike', 'walk'].includes(item.mode)
                    ? item.mode
                    : '',
                route: item.legs
                    ? item.id
                    : item.id?.startsWith('request:')
                      ? ''
                      : item.id,
                arrive:
                    item.activityId || item.preferences?.schedule === 'arrive'
                        ? item.targetStart || item.requestedTime
                        : '',
                time: item.depart || item.requestedTime,
                activity: item.activityId,
                planDate: item.planDate,
                previousId: item.previousId,
                previousEnd: item.previousEnd,
            }))
                if (value) params.set(name, value);
            const service = item.servicePreference;
            if (
                !item.legs &&
                service?.version === 1 &&
                service.kind === 'service-preference' &&
                service.isLive === false &&
                service.departureId &&
                service.capturedAt
            ) {
                // Restore against the local capture on Travel, never trust a
                // serialized itinerary or boarding assignment from the URL.
                params.set('service', service.departureId);
                params.set('serviceCapture', service.capturedAt);
                return '../departures-next/station.html?' + params;
            }
            return '../departures-next/?' + params;
        }
        params.set('service', item.category || 'doctors');
        return '../services-next/?' + params;
    }
    function journeyStatus(journey, plan, item, previous) {
        if (journey.date !== plan.date)
            return 'Different date · check a new journey';
        if (
            journey.activityId !== item.id ||
            journey.to !== (item.venue || item.location || item.title) ||
            journey.targetStart !== item.start ||
            journey.targetLocation !==
                (item.venue || item.location || item.title) ||
            (journey.previousId || null) !== (previous?.id || null) ||
            (journey.previousEnd || null) !== (previous?.end || null)
        )
            return 'Plan changed · check journey again';
        if (
            !timeOK(journey.depart) ||
            !timeOK(journey.arrive) ||
            journey.arrive > item.start ||
            (previous && journey.depart < previous.end)
        )
            return 'Timing does not fit · check another journey';
        return 'Captured journey · current service needs checking';
    }
    function nextUpcoming(plans, date, time) {
        return (
            (plans || [])
                .flatMap((plan) =>
                    (plan.items || []).map((item) => ({ plan, item })),
                )
                .filter(
                    ({ plan, item }) =>
                        plan.date > date ||
                        (plan.date === date && item.start > time),
                )
                .sort(
                    (a, b) =>
                        a.plan.date.localeCompare(b.plan.date) ||
                        a.item.start.localeCompare(b.item.start),
                )[0] || null
        );
    }
    const api = {
        reviewItems,
        dayItems,
        suggestTimes,
        bookmark,
        schedule,
        conflict,
        reviewURL,
        journeyStatus,
        nextUpcoming,
        key,
    };
    root.ExpaduHandoffs = api;
    if (typeof module !== 'undefined') module.exports = api;
})(globalThis);
