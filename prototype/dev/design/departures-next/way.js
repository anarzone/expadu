/* Your way as a line of stations (way.css): a row for each part of the
   trip, the one you're on marked. Travel's trip builds the rows from its
   route (navigation-ui.js); Composer from the same sample day, so a trip
   reads the same wherever it shows (owner, 18 Oct 2026, Composer review
   canvas board 11). A row: { kind: 'walk' | 'walk is-from-stop' | 'ride' |
   'end', time, title, sub, look (the line's, labels.js), badge (its line
   badge, already markup) }. */
(function (root) {
    const esc = (value) =>
        String(value ?? '').replace(
            /[&<>"']/g,
            (c) =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                })[c],
        );
    function list(rows, current = -1) {
        return `<ol class="nav-way">${rows
            .map(
                (row, index) =>
                    `<li class="is-${row.kind}${index < current ? ' is-done' : ''}${index === current ? ' is-now' : ''}"${row.look ? ` style="--line:${row.look.colour}"` : ''}${index === current ? ' aria-current="step"' : ''}><time>${esc(row.time)}</time><i aria-hidden="true"></i><div><strong>${row.badge || ''}<span>${esc(row.title)}</span>${index === current ? '<em>You’re here</em>' : ''}</strong><small>${esc(row.sub)}</small></div></li>`,
            )
            .join('')}</ol>`;
    }
    root.TravelWay = { list };
})(globalThis);
