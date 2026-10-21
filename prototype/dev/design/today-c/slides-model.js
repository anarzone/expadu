/* Derived preview states only; catalogue guidance stays in its source records. */
(function (root) {
    function eventDecks(items) {
        // First show different kinds of events; never duplicate an occurrence.
        const unique = [
            ...new Map(
                items.map((item) => [item.occurrence || item.id, item]),
            ).values(),
        ];
        const categories = new Set();
        const first = [],
            rest = [];
        for (const item of unique) {
            if (categories.has(item.category)) rest.push(item);
            else {
                categories.add(item.category);
                first.push(item);
            }
        }
        const decks = [[], [], []];
        [...first, ...rest].forEach((item, index) =>
            decks[index % decks.length].push(item),
        );
        return decks;
    }
    function personal(state) {
        // Root records belong to the account holder; family data has separate storage below household.
        const own = JSON.parse(JSON.stringify(state));
        own.person = 'self';
        delete own.household;
        return own;
    }
    function paperwork(state) {
        const B = root.BureaucracyPreview,
            P = root.BureaucracyPlan;
        if (!B.canEdit(state)) return [];
        const ps = P.projection(state).processes.filter((p) => p.started);
        const slides = ps.map((p) => {
            const docs = P.documents(state, p.id);
            const missing = docs.filter((d) => d === 'missing').length;
            const ready = docs.filter((d) => d === 'confirmed_for_use').length;
            const kind = ['waiting', 'submitted'].includes(p.workflow)
                ? 'waiting'
                : p.workflow === 'blocked' && missing
                  ? 'missing'
                  : 'next';
            return {
                id: p.id,
                kind,
                name: p.name,
                missing,
                ready,
                total: docs.length,
                note: p.note || '',
                record: p.records[0],
                view: kind === 'missing' ? 'paperwork' : 'detail',
            };
        });
        for (const p of ps)
            if (p.appointment && B.validDate(p.appointment.date)) {
                slides.push({
                    id: p.id,
                    kind: 'appointment',
                    name: p.name,
                    ...p.appointment,
                    view: 'detail',
                });
            }
        return slides.sort(
            (a, b) =>
                ({ appointment: 0, next: 1, missing: 2, waiting: 3 })[a.kind] -
                { appointment: 0, next: 1, missing: 2, waiting: 3 }[b.kind],
        );
    }
    root.TodaySlides = { paperwork, personal, eventDecks };
})(globalThis);
