(function () {
    const shapes = {
        people: '<circle cx="9" cy="7" r="3"/><path d="M3 21v-4a6 6 0 0 1 12 0v4m2-16a3 3 0 0 1 0 6m1 3a5 5 0 0 1 3 5v2"/>',
        home: '<path d="m3 10 9-7 9 7M5 9v12h14V9M9 21v-8h6v8"/>',
        arrow: '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        back: '<path d="M20 12H4m6-6-6 6 6 6"/>',
        down: '<path d="m7 10 5 5 5-5"/>',
        close: '<path d="m6 6 12 12M6 18 18 6"/>',
        walk: '<circle cx="13" cy="4" r="2"/><path d="m10 9 3-2 3 5 4 1M10 9l-3 4H4m9-5-2 8-4 6m4-6 5 1 2 5"/>',
        bike: '<circle cx="5" cy="17" r="4"/><circle cx="19" cy="17" r="4"/><path d="m5 17 5-9 5 9H5m4-9h8l2 9M7 8h4m5-4h3l-2 4"/>',
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
        briefcase:
            '<rect x="3" y="7" width="18" height="13" rx="3"/><path d="M8 7V4h8v3M3 12c5 4 13 4 18 0m-9 1v4"/>',
        route: '<circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M6 7v6a3 3 0 0 0 3 3h6a3 3 0 0 0 0-6H9"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1"/>',
        check: '<path d="m5 12 4 4L19 6"/>',
    };
    Object.assign(shapes, {
        plus: '<path d="M12 4v16M4 12h16"/>',
        // Add to plan on event posters, and step-free access (as on place
        // cards).
        'calendar-plus':
            '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 2v6m10-6v6M3 11h18m-9 3v5m-2.5-2.5h5"/>',
        wheelchair:
            '<circle cx="12" cy="4" r="2"/><path d="M12 7v6h5l2 6M12 10H8m3 3a5 5 0 1 0 4 8"/>',
        coffee: '<path d="M3 8h14v7a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V8Zm14 1h2a3 3 0 0 1 0 6h-2M6 2v3m5-3v3M2 22h18"/>',
        book: '<path d="M12 5C9 3 5 3 2 4v16c3-1 7-1 10 1 3-2 7-2 10-1V4c-3-1-7-1-10 1Zm0 0v16"/>',
        water: '<path d="M2 8c3-5 5 5 9 0s6 5 11 0M2 14c3-5 5 5 9 0s6 5 11 0M2 20c3-5 5 5 9 0s6 5 11 0"/>',
        search: '<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/>',
        leaf: '<path d="M20 3c1 12-5 18-12 16C0 16 4 4 20 3Z"/><path d="m4 21 11-11"/>',
        ball: '<circle cx="12" cy="12" r="9"/><path d="m12 8 4 3-2 5h-4l-2-5 4-3Zm0-5v5M3 10l5 1m2 5-3 4m7-4 3 4m-1-9 5-1"/>',
        'table-tennis':
            '<path d="M5 5c3-3 8-3 10 0s2 7-1 9c-2 2-4 1-5 2l-4 5-3-3 5-4c1-1 0-3-2-5-1-1-1-3 0-4Zm1 6 6 5"/><circle cx="19" cy="6" r="3"/>',
        sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1 1m12 12 1 1M5 19l1-1M18 6l1-1"/>',
        waves: '<path d="M2 8c3-5 5 5 9 0s6 5 11 0M2 14c3-5 5 5 9 0s6 5 11 0M2 20c3-5 5 5 9 0s6 5 11 0"/>',
        chat: '<path d="M3 3h18v14H9l-6 5V3Z"/><path d="M7 8h10M7 12h7"/>',
        museum: '<path d="m2 8 10-6 10 6H2Zm2 12h16M6 11v6m6-6v6m6-6v6M2 22h20"/>',
        pencil: '<path d="m3 16 12-12 5 5L8 21H3v-5Zm9-9 5 5"/>',
        film: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 3v18M17 3v18M3 8h4m-4 8h4m10-8h4m-4 8h4"/>',
        health: '<path d="M3 5h18v16H3zM8 5V2h8v3M12 9v8M8 13h8"/>',
        smile: '<circle cx="12" cy="12" r="9"/><path d="M8 14c1 4 7 4 8 0M8 8v1m8-1v1"/>',
        bank: '<path d="M3 7 12 2l9 5H3Zm2 3v8m7-8v8m7-8v8M2 22h20M3 19h18"/>',
        scales: '<path d="M12 2v20M5 22h14M3 6h18M5 6l-4 9h8L5 6Zm14 0-4 9h8l-4-9Z"/>',
        up: '<path d="m6 14 6-6 6 6"/>',
        trash: '<path d="M3 6h18M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7m4-7v7"/>',
        sliders: '<path d="M4 6h16M4 18h16M8 3v6m8 6v6"/>',
        bell: '<path d="M4 17h16l-2-4V8a6 6 0 0 0-12 0v5l-2 4Zm5 4h6"/>',
        ticket: '<path d="M3 5h18v5a2 2 0 0 0 0 4v5H3v-5a2 2 0 0 0 0-4V5Z"/><path d="M15 5v3m0 3v2m0 3v3"/>',
    });
    window.ExpaduIcon = (n) =>
        `<svg viewBox="0 0 24 24" aria-hidden="true">${shapes[n] || shapes.spark}</svg>`;
})();
