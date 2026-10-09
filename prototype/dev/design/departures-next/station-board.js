/* A station-wide board: every departure is equally selectable, including ties. */
(function (root) {
    const escape = (value) =>
        String(value).replace(
            /[&<>"']/g,
            (char) =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                })[char],
        );
    const day = (date) =>
        new Intl.DateTimeFormat('en-GB', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
        }).format(new Date(`${date}T12:00:00`));
    const panelId = (entry) =>
        `departure-${entry.id.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
    function lineChoices(entries, selected) {
        const lines = [...new Set(entries.map((entry) => entry.line))].sort(
            (a, b) => a.localeCompare(b, undefined, { numeric: true }),
        );
        return [
            { id: 'all', name: 'All lines' },
            ...lines.map((id) => ({ id, name: id })),
        ]
            .map(
                ({ id, name }) =>
                    `<button type="button" data-station-line="${escape(id)}" aria-label="${id === 'all' ? 'All lines' : `Line ${escape(id)}`}" aria-pressed="${selected === id}">${escape(name)}</button>`,
            )
            .join('');
    }
    function render({
        page,
        stop,
        clock,
        expanded,
        selected,
        desktop,
        detail,
    }) {
        let previousDate = clock.date;
        const rows = page.rows
            .map((entry) => {
                const dateBreak =
                    entry.date !== previousDate
                        ? `<div class="station-day">${day(entry.date)}</div>`
                        : '';
                previousDate = entry.date;
                const boarding = root.TimetableModel.stops.find(
                    (item) => item.id === entry.stopId,
                );
                const mode = entry.type || boarding.mode;
                const open = desktop && expanded === entry.id;
                const from = stop.id === 'all' ? `From ${boarding.name}` : '';
                // Staging derives via stops from a representative static trip,
                // not this departure. Don't present them as confirmed guidance.
                const caption = from;
                const delay = entry.delayed
                    ? '<span class="station-notice">Delay in capture</span>'
                    : '';
                const label = `Line ${entry.line} to ${entry.destination}, ${entry.time}, ${day(entry.date)}, from ${boarding.name}${entry.delayed ? ', delay in capture' : ''}`;
                return `${dateBreak}<div class="station-entry"><button class="station-row${selected === entry.id ? ' is-selected' : ''}" data-departure="${escape(entry.id)}" aria-label="${escape(label)}" aria-expanded="${open}" ${desktop ? `aria-controls="${panelId(entry)}"` : 'aria-haspopup="dialog"'}><span class="station-service ${mode === 'bus' ? 'bus' : 'tram'}">${root.ExpaduIcon(mode === 'bus' ? 'bus' : 'train')}<strong>${escape(entry.line)}</strong></span><span class="station-destination"><strong>${escape(entry.destination)}</strong>${caption ? `<small>${escape(caption)}</small>` : ''}${delay}</span><span class="station-time"><time datetime="${entry.date}T${entry.time}">${entry.time}</time></span>${root.ExpaduIcon('down')}</button><div class="departure-inline" id="${panelId(entry)}" ${open ? '' : 'hidden'}>${open ? detail(entry, true) : ''}</div></div>`;
            })
            .join('');
        // The board says once where its times come from (the header says
        // "Saved estimates" or "Sample day"), not under every row.
        const sample = stop.sampleDay && clock.date === stop.sampleDay.date;
        return `<section class="station-board" aria-labelledby="station-board-title"><div class="station-list-heading"><h2 id="station-board-title">Departures <span>${page.total}</span></h2></div><div class="station-column-labels" aria-hidden="true"><span>Line</span><span>Towards</span><span>Departs</span><span></span></div>${rows}${page.total > page.rows.length ? `<button class="quiet-button timetable-more" data-action="more-departures">Show more departures <span>${page.total - page.rows.length}</span></button>` : ''}<p class="station-endnote">${root.ExpaduIcon('info')}${sample ? 'Scheduled sample times' : 'Captured times'}. Check the platform and current service before travelling.</p></section>`;
    }
    root.StationBoard = { directionChoices: () => '', lineChoices, render };
})(globalThis);
