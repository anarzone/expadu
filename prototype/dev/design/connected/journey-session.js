/* A local, explicitly simulated journey. Separate from proposals and saved plans. */
(function (root) {
    const key = 'expadu.journey-preview.v1';
    let memory = null;
    let memoryOnly = false;
    function normalize(record) {
        if (
            !record ||
            record.version !== 1 ||
            typeof record.routeId !== 'string' ||
            !/^[\w-]{1,40}$/.test(record.routeId)
        )
            return null;
        if (
            !Number.isInteger(record.step) ||
            record.step < 0 ||
            record.step > 31
        )
            return null;
        if (
            record.boardingStop != null &&
            (typeof record.boardingStop !== 'string' ||
                !/^[\w-]{1,40}$/.test(record.boardingStop))
        )
            return null;
        return {
            version: 1,
            routeId: record.routeId,
            step: record.step,
            ...(record.boardingStop != null
                ? { boardingStop: record.boardingStop }
                : {}),
            title: String(record.title || 'Your journey').slice(0, 120),
            instruction: String(record.instruction || '').slice(0, 160),
            mode: ['transit', 'walk', 'bike'].includes(record.mode)
                ? record.mode
                : 'transit',
            condition: ['normal', 'location', 'offline', 'changed'].includes(
                record.condition,
            )
                ? record.condition
                : 'normal',
        };
    }
    function read() {
        if (memoryOnly) return memory && { ...memory };
        try {
            memory = normalize(JSON.parse(localStorage.getItem(key)));
        } catch {
            /* Keep the current session if storage is unavailable. */
        }
        return memory && { ...memory };
    }
    function write(value) {
        memory = value === null ? null : normalize({ ...value, version: 1 });
        let persisted = true;
        try {
            if (memory) localStorage.setItem(key, JSON.stringify(memory));
            else localStorage.removeItem(key);
        } catch {
            persisted = false;
        }
        memoryOnly = !persisted;
        root.dispatchEvent?.(
            new CustomEvent('expadu:journey-preview', {
                detail: { persisted },
            }),
        );
        return persisted;
    }
    const api = { read, write, normalize };
    root.JourneySession = api;
    if (typeof module !== 'undefined') module.exports = api;
    if (typeof document === 'undefined') return;
    const base = new URL('../departures-next/', document.currentScript.src);
    const bar = document.createElement('a');
    bar.className = 'journey-resume';
    bar.hidden = true;
    const host =
        document.querySelector('.page-navigation') ||
        document.querySelector('#shell') ||
        document.querySelector('.service-nav, .services');
    if (!host) return;
    host.insertAdjacentElement('afterend', bar);
    const escape = (value) =>
        String(value).replace(
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
    function sync() {
        const record = read();
        bar.hidden = !record;
        if (!record) return;
        const url = new URL(base);
        url.searchParams.set('journey', 'resume');
        bar.href = url.href;
        bar.setAttribute(
            'aria-label',
            `Back to your trip to ${record.title}, step ${record.step + 1}`,
        );
        bar.innerHTML = `${root.ExpaduIcon?.(record.mode === 'transit' ? 'train' : record.mode) || ''}<span><strong>${escape(record.title)}</strong><small>On your way · ${escape(record.instruction)}</small></span>${root.ExpaduIcon?.('arrow') || '›'}`;
    }
    bar.addEventListener('click', (event) => {
        if (root.JourneyNavigation) {
            event.preventDefault();
            root.JourneyNavigation.resume();
        }
    });
    root.addEventListener('expadu:journey-preview', sync);
    root.addEventListener('storage', sync);
    root.addEventListener('pageshow', sync);
    sync();
})(globalThis);
