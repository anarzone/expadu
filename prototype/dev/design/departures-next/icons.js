const shapes = {
    arrow: '<path d="M4 12h16m-6-6 6 6-6 6"/>',
    back: '<path d="M20 12H4m6-6-6 6 6 6"/>',
    down: '<path d="m7 10 5 5 5-5"/>',
    up: '<path d="m7 14 5-5 5 5"/>',
    close: '<path d="m6 6 12 12M6 18 18 6"/>',
    train: '<rect x="5" y="2" width="14" height="17" rx="4"/><path d="M5 11h14M12 2v9M8 22l2-3m6 3-2-3"/>',
    bus: '<rect x="3" y="4" width="18" height="15" rx="3"/><path d="M3 12h18M8 4v8m8-8v8M7 19v2m10-2v2M7 16h1m8 0h1"/>',
    pin: '<path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
    calendar:
        '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 2v6m10-6v6M3 11h18m-14 4h3m4 0h3"/>',
    document:
        '<path d="M14 2H5v20h14V7Z"/><path d="M14 2v5h5M8 11h8m-8 4h8m-8 3h5"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
    bookmark: '<path d="M6 3h12v18l-6-4-6 4V3Z"/>',
    spark: '<path d="m12 2 2.8 7.2L22 12l-7.2 2.8L12 22l-2.8-7.2L2 12l7.2-2.8L12 2Z"/>',
    route: '<circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M6 7v6a3 3 0 0 0 3 3h6a3 3 0 0 0 0-6H9"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    swap: '<path d="M8 3v18m-4-4 4 4 4-4M16 21V3m-4 4 4-4 4 4"/>',
    chevron: '<path d="m9 5 7 7-7 7"/>',
    sort: '<path d="M4 5h7M4 12h5M4 19h3M17 3v18m-4-4 4 4 4-4"/>',
    play: '<path d="m8 5 11 7-11 7Z"/>',
    edit: '<path d="m3 16 12-12 5 5L8 21H3v-5Zm9-9 5 5"/>',
    map: '<path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2V5Zm6-2v16m6-14v16"/>',
    cloud: '<path d="M7 18a4 4 0 1 1 1-7.9A5 5 0 0 1 17.5 12 3.5 3.5 0 0 1 17 18H7Z"/>',
    eye: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    list: '<path d="M9 6h12M9 12h12M9 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
    star: '<path d="m12 3 2.6 5.6 6.1.7-4.5 4.2 1.2 6.1L12 16.6 6.6 19.6l1.2-6.1-4.5-4.2 6.1-.7L12 3Z"/>',
    // The planner's size buttons (owner, 14 Oct 2026: it grows and shrinks).
    grow: '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
    shrink: '<path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/>',
    locate: '<circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4"/>',
    plus: '<path d="M5 12h14M12 5v14"/>',
    minus: '<path d="M5 12h14"/>',
    more: '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
    stop: '<rect x="6" y="6" width="12" height="12" rx="3"/>',
    // Travel labels (owner-approved 10 Oct 2026)
    cancel: '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>',
    alert: '<path d="M12 3.5 2.5 20h19L12 3.5Z"/><path d="M12 10v4.5m0 3v.1"/>',
    lift: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="m9.5 8.5 2.5-2.5 2.5 2.5m-5 7 2.5 2.5 2.5-2.5M3 3l18 18"/>',
    liftok: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="m9.5 8.5 2.5-2.5 2.5 2.5m-5 7 2.5 2.5 2.5-2.5"/>',
    timer: '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 1.5M9.5 2.5h5"/>',
    moon: '<path d="M19.5 14.5A7.5 7.5 0 1 1 9.5 4.5a6 6 0 0 0 10 10Z"/>',
    offline:
        '<path d="m3 3 18 18M2 8a16 16 0 0 1 3-2m4-2a16 16 0 0 1 13 4M5 12a11 11 0 0 1 4-2m5 0a11 11 0 0 1 5 2m-11 4a6 6 0 0 1 8 0"/><circle cx="12" cy="20" r=".8"/>',
};
const icon = (n) =>
    shapes[n]
        ? `<svg viewBox="0 0 24 24" aria-hidden="true">${shapes[n]}</svg>`
        : ExpaduIcon(n);
const safe = (s) =>
    String(s).replace(
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
