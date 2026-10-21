/* Presentation of recorded plans only; no inferred deadlines, bookings or live travel. */
(function (root) {
    const validDate = (value) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return false;
        const date = new Date(value + 'T12:00:00Z');
        return (
            Number.isFinite(date.getTime()) &&
            date.toISOString().slice(0, 10) === value
        );
    };
    const validTime = (value) => /^([01]\d|2[0-3]):[0-5]\d$/.test(value || '');
    function project({
        date,
        time,
        workspace,
        papers = [],
        journey = null,
        draft = [],
    }) {
        const remaining = (day, start, end) =>
            validDate(day) &&
            validTime(start) &&
            (day > date ||
                (day === date &&
                    (start >= time || (validTime(end) && end > time))));
        const plans = (workspace?.plans || []).filter(
            (plan) => plan.status !== 'cancelled',
        );
        const activities = plans.flatMap((plan) => {
            const items = (plan.items || []).filter(
                (item) =>
                    item.status !== 'cancelled' &&
                    remaining(plan.date, item.start, item.end),
            );
            return items.map((item) => ({
                kind: 'plan',
                item,
                date: plan.date,
                start: item.start,
                count: items.length,
                ongoing: plan.date === date && item.start < time,
            }));
        });
        const appointments = papers
            .filter(
                (paper) =>
                    paper.kind === 'appointment' &&
                    remaining(paper.date, paper.time),
            )
            .map((item) => ({
                kind: 'appointment',
                item,
                date: item.date,
                start: item.time,
                ongoing: false,
            }));
        const upcoming = [...activities, ...appointments].sort(
            (a, b) =>
                a.date.localeCompare(b.date) || a.start.localeCompare(b.start),
        );
        const next = upcoming[0] || null;
        const primary = journey
            ? { kind: 'journey', item: journey }
            : next?.date === date
              ? next
              : null;
        const priority = { next: 0, missing: 1, waiting: 2 };
        const paperwork =
            papers
                .filter(
                    (paper) =>
                        paper.kind !== 'appointment' &&
                        !(
                            next?.kind === 'appointment' &&
                            next.item.id === paper.id
                        ),
                )
                .sort((a, b) => priority[a.kind] - priority[b.kind])[0] || null;
        const proposal = workspace?.proposal;
        const pending = proposal?.items?.length
            ? {
                  kind: 'proposal',
                  date: proposal.date,
                  count: proposal.items.length,
              }
            : draft.length
              ? {
                    kind: 'selection',
                    date: draft[0].handoffDate || draft[0].date,
                    count: draft.length,
                }
              : null;
        return {
            primary,
            later: next && next !== primary ? next : null,
            paperwork,
            pending,
        };
    }
    root.TodayBrief = { project };
    if (typeof module !== 'undefined') module.exports = root.TodayBrief;
})(globalThis);
