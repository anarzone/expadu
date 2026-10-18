/* Travel labels (owner-approved 10 Oct 2026, Travel directions canvas,
   row 10): every label is an icon and a word, never colour alone, and its
   strength follows how much it changes the trip.
   - conflict: it won't work (cancelled, lift out of order)
   - attention: watch out (late, tight, a long wait)
   - unsure: we don't know it live, or it's a sample
   - calm: good to go; best: the option we'd pick; select: where you are */
(function (root) {
    // icons.js keeps icon() as a top-level const in Travel, so reach it by
    // name; the line badge draws its own vehicle, so it looks the same on
    // every page (Today, Composer, Events), with or without Travel's icons.
    const ic = (name) => (typeof icon === 'function' ? icon(name) : '');
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
    const vehicles = {
        train: '<rect x="5" y="2" width="14" height="17" rx="4"/><path d="M5 11h14M12 2v9M8 22l2-3m6 3-2-3"/>',
        bus: '<rect x="3" y="4" width="18" height="15" rx="3"/><path d="M3 12h18M8 4v8m8-8v8M7 19v2m10-2v2M7 16h1m8 0h1"/>',
    };
    const vehicleIcon = (name) =>
        `<svg viewBox="0 0 24 24" aria-hidden="true">${vehicles[name]}</svg>`;
    // KVB line colours, darkened where white text needs 4.5:1 (owner-approved
    // on the Travel directions canvas, 9 Oct 2026). Unlisted lines fall back
    // to the travel colour; buses to grey. Every page takes a line's look
    // from here (owner, 18 Oct 2026: trips look like Travel everywhere).
    const colours = {
        1: '#E2001A',
        3: '#C8027E',
        4: '#A01E5A',
        7: '#008540',
        9: '#E2001A',
        12: '#95C11F',
        16: '#0074AD',
        18: '#0074AD',
    };
    const darkInk = new Set(['12']);
    const white = [255, 255, 255];
    const rgb = (hex) =>
        [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    // A pale wash of the line colour for its card (9% on white).
    const tintOf = (hex) =>
        '#' +
        rgb(hex)
            .map((c, i) =>
                Math.round(white[i] + (c - white[i]) * 0.09)
                    .toString(16)
                    .padStart(2, '0'),
            )
            .join('');
    function look(line, mode) {
        const bus = mode === 'bus';
        const colour = colours[line] || (bus ? '#4A4A4A' : '#00768B');
        return {
            colour,
            tint: tintOf(colour),
            ink: darkInk.has(String(line)) ? '#1D2A05' : '#FFFFFF',
            bus,
        };
    }
    const vehicle = (kind) =>
        kind === 'bus' ? 'Bus' : kind === 'rail' ? 'Train' : 'Tram';
    const style = (look = {}) =>
        `--line:${look.colour || 'var(--color-text)'};--ink:${look.ink || '#fff'}`;
    // The line badge with its vehicle inside: lists show the symbol and the
    // number, the trip says the word too.
    function line(id, look = {}, options = {}) {
        const kind = options.kind || (look.bus ? 'bus' : 'tram');
        const name = `${vehicle(kind)} ${id}`;
        const text = options.word
            ? esc(name)
            : `<span class="sr-only">${vehicle(kind)} </span>${esc(id)}`;
        return `<span class="tl-line${options.size ? ` is-${options.size}` : ''}" style="${style(look)}">${vehicleIcon(kind === 'bus' ? 'bus' : 'train')}<span>${text}</span></span>`;
    }
    // A label: kind, icon and words; `hidden` adds words only screen
    // readers hear (cards say "+2 min", people hear "+2 min late").
    function chip(kind, iconName, text, options = {}) {
        return `<span class="tl-chip is-${kind}${options.size ? ` is-${options.size}` : ''}">${iconName ? ic(iconName) : ''}<span>${esc(text)}${options.hidden ? `<span class="sr-only"> ${esc(options.hidden)}</span>` : ''}</span></span>`;
    }
    // "Now" is a filled pill in the line's colour with a slow blink.
    function now(look = {}) {
        return `<span class="tl-now" style="${style(look)}"><i aria-hidden="true"></i>Now</span>`;
    }
    // Something about the whole card, along its foot.
    function strip(kind, iconName, text) {
        return `<span class="tl-strip is-${kind}">${ic(iconName)}<span>${esc(text)}</span></span>`;
    }
    // A page-level situation: icon tile, title, one line.
    function banner(kind, iconName, title, text, extra = '') {
        return `<div class="tl-banner is-${kind}"><span class="tl-tile" aria-hidden="true">${ic(iconName)}</span><p><strong>${esc(title)}</strong>${text ? `<span>${esc(text)}</span>` : ''}</p>${extra}</div>`;
    }
    root.TravelLabels = { line, look, chip, now, strip, banner, vehicle };
})(globalThis);
