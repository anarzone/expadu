/* Travel labels (owner-approved 10 Oct 2026, Travel directions canvas,
   row 10): every label is an icon and a word, never colour alone, and its
   strength follows how much it changes the trip.
   - conflict: it won't work (cancelled, lift out of order)
   - attention: watch out (late, tight, a long wait)
   - unsure: we don't know it live, or it's a sample
   - calm: good to go; best: the option we'd pick; select: where you are */
(function (root) {
    // icons.js keeps icon() and safe() as top-level consts, so reach them
    // by name.
    const ic = (name) => (typeof icon === 'function' ? icon(name) : '');
    const esc = (value) =>
        typeof safe === 'function' ? safe(value) : String(value);
    const vehicle = (kind) => (kind === 'bus' ? 'Bus' : 'Tram');
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
        return `<span class="tl-line${options.size ? ` is-${options.size}` : ''}" style="${style(look)}">${ic(kind === 'bus' ? 'bus' : 'train')}<span>${text}</span></span>`;
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
    root.TravelLabels = { line, chip, now, strip, banner, vehicle };
})(globalThis);
