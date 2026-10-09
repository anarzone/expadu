/* A station-wide board, drawn as the stop's departure display: every
   departure is equally selectable, including ties. */
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
    const minutesTo = (entry, clock) =>
        Math.round(
            (new Date(`${entry.date}T${entry.time}:00`) -
                new Date(`${clock.date}T${clock.time}:00`)) /
                60000,
        );
    // The board is the stop's display: its sign and clock, then each
    // departure with the minutes until it leaves (from the board's clock,
    // which is the sample's or a capture's, never now). `tag` adds what a
    // departure means for the traveller, such as their appointment tram.
    function render({
        page,
        stop,
        clock,
        expanded,
        selected,
        desktop,
        detail,
        tag = () => '',
    }) {
        let previousDate = clock.date;
        const rows = page.rows
            .map((entry) => {
                const dateBreak =
                    entry.date !== previousDate
                        ? `<div class="display-day">${day(entry.date)}</div>`
                        : '';
                previousDate = entry.date;
                const boarding = root.TimetableModel.stops.find(
                    (item) => item.id === entry.stopId,
                );
                const mode = entry.type || boarding.mode;
                const open = desktop && expanded === entry.id;
                const from = stop.id === 'all' ? `From ${boarding.name}` : '';
                const mins = minutesTo(entry, clock);
                const when =
                    mins <= 0 ? 'now' : mins < 60 ? `${mins} min` : entry.time;
                const label = `Line ${entry.line} to ${entry.destination}, ${entry.time}, ${day(entry.date)}${mins > 0 && mins < 60 ? `, in ${mins} minutes` : ''}, from ${boarding.name}${entry.delayed ? ', delay in capture' : ''}`;
                return `${dateBreak}<div class="display-entry"><button class="display-row${selected === entry.id ? ' is-selected' : ''}" data-departure="${escape(entry.id)}" aria-label="${escape(label)}" aria-expanded="${open}" ${desktop ? `aria-controls="${panelId(entry)}"` : 'aria-haspopup="dialog"'}><span class="display-line">${root.ExpaduIcon(mode === 'bus' ? 'bus' : 'train')}<strong>${escape(entry.line)}</strong></span><span class="display-dest"><strong>${escape(entry.destination)}</strong>${from ? `<small>${escape(from)}</small>` : ''}${tag(entry, mins)}${entry.delayed ? '<small class="display-delay">Delay in capture</small>' : ''}</span><span class="display-when"><strong>${when}</strong>${when !== entry.time ? `<small><time datetime="${entry.date}T${entry.time}">${entry.time}</time></small>` : ''}</span>${root.ExpaduIcon('down')}</button><div class="departure-inline" id="${panelId(entry)}" ${open ? '' : 'hidden'}>${open ? detail(entry, true) : ''}</div></div>`;
            })
            .join('');
        const sample = stop.sampleDay && clock.date === stop.sampleDay.date;
        return `<section class="stop-display" aria-labelledby="station-board-title"><header class="display-head"><span class="display-sign" aria-hidden="true">H</span><div><h2 id="station-board-title">${escape(stop.name.replace(/^Köln\s+/, ''))}<span class="sr-only"> departures</span></h2><small>${sample ? 'Sample clock · scheduled times' : `Saved estimates · ${day(clock.date)}`}</small></div><time class="display-clock" datetime="${clock.date}T${clock.time}">${escape(clock.time)}</time></header><div class="display-rows">${rows}</div>${page.total > page.rows.length ? `<button class="quiet-button timetable-more" data-action="more-departures">Show more departures <span>${page.total - page.rows.length}</span></button>` : ''}<p class="display-note">${root.ExpaduIcon('info')}<span>${sample ? 'Scheduled sample times' : 'Captured times'}. Minutes count from the ${sample ? 'sample' : 'capture’s'} clock, not now. Check the platform and current service before travelling.</span></p></section>`;
    }
    root.StationBoard = { directionChoices: () => '', lineChoices, render };
})(globalThis);
