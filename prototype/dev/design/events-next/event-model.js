/* Discovery and attendance rules for captured event occurrences. */
(function (root) {
    const validDate = (value) =>
        /^\d{4}-\d{2}-\d{2}$/.test(value || '') &&
        new Date(value + 'T12:00:00Z').toISOString().slice(0, 10) === value;
    const dateLabel = (value, options = {}) =>
        new Date(value + 'T12:00:00').toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'long',
            ...options,
        });
    function normalize(value = {}, items = []) {
        const fallback = items[0]?.date || '2026-09-08';
        let date =
            value.date ||
            (value.day
                ? `2026-09-${String(value.day).padStart(2, '0')}`
                : fallback);
        try {
            if (!validDate(date)) date = fallback;
        } catch {
            date = fallback;
        }
        return {
            date,
            category: items.some((x) => x.category === value.category)
                ? value.category
                : 'All',
            area: items.some((x) => x.area === value.area) ? value.area : '',
            free: value.free === true,
            saved: value.saved === true,
            query: typeof value.query === 'string' ? value.query : '',
            sort: value.sort === 'Evening first' ? value.sort : 'Soonest',
        };
    }
    function select(items, filters, saved = []) {
        const query = filters.query.trim().toLocaleLowerCase();
        return items
            .filter(
                (x) =>
                    x.date === filters.date &&
                    (filters.category === 'All' ||
                        x.category === filters.category) &&
                    (!filters.area || x.area === filters.area) &&
                    (!filters.free || x.price?.startsWith('Free')) &&
                    (!filters.saved ||
                        saved.some((y) => y.occurrence === x.occurrence)) &&
                    (!query ||
                        `${x.title} ${x.venue} ${x.area} ${x.category}`
                            .toLocaleLowerCase()
                            .includes(query)),
            )
            .sort((a, b) =>
                filters.sort === 'Evening first'
                    ? b.start.localeCompare(a.start)
                    : a.start.localeCompare(b.start),
            );
    }
    function week(date) {
        const start = new Date(date + 'T12:00:00');
        start.setDate(start.getDate() - ((start.getDay() + 6) % 7));
        return Array.from({ length: 7 }, (_, i) => {
            const d = new Date(start);
            d.setDate(d.getDate() + i);
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        });
    }
    function shift(date, days) {
        const d = new Date(date + 'T12:00:00');
        d.setDate(d.getDate() + days);
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }
    function shiftMonth(date, months) {
        const d = new Date(date + 'T12:00:00');
        const day = d.getDate();
        d.setDate(1);
        d.setMonth(d.getMonth() + months);
        const last = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
        d.setDate(Math.min(day, last));
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }
    function month(date) {
        const first = date.slice(0, 7) + '-01';
        const start = week(first)[0];
        const last = shift(shiftMonth(first, 1), -1);
        const end = week(last)[6];
        const dates = [];
        for (let day = start; day <= end; day = shift(day, 1)) dates.push(day);
        return dates;
    }
    root.ExpaduEvents = {
        normalize,
        select,
        week,
        month,
        shift,
        shiftMonth,
        dateLabel,
    };
})(globalThis);
