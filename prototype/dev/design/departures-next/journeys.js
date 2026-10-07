'use strict';
// Public route results and steps read from staging's rendered UI on 7 Sep 2026.
// Clock frozen at 23:37. No personal addresses, ticket ownership or GPS copied.
globalThis.JourneyModel = (() => {
    const capture = {
        date: '2026-09-07',
        time: '23:37',
        from: 'Köln Neumarkt',
        to: 'Museum Ludwig',
        boardingStop: {
            id: 'neumarkt',
            name: 'Köln Neumarkt',
            mode: 'tram',
            operator: 'KVB Stadtbahn',
        },
    };
    const transit = (
        id,
        line,
        depart,
        board,
        alight,
        arrive,
        duration,
        walkIn,
        walkOut,
        stop,
        headsign,
        intermediate = [],
        delayed = false,
    ) => ({
        id,
        line,
        depart,
        arrive,
        duration,
        walking: walkIn + walkOut,
        changes: 0,
        mode: 'transit',
        delayed,
        legs: [
            {
                mode: 'walk',
                title: 'Walk to Köln Neumarkt',
                detail: `${walkIn} min on foot`,
                time: depart,
                duration: walkIn,
            },
            {
                mode: 'transit',
                boardStop: capture.boardingStop.id,
                title: `Take line ${line}`,
                detail: `Towards ${headsign}`,
                time: board,
                duration: duration - walkIn - walkOut,
                stop,
                alight,
                intermediate,
            },
            {
                mode: 'walk',
                title: 'Walk to Museum Ludwig',
                detail: `${walkOut} min on foot`,
                time: alight,
                duration: walkOut,
            },
        ],
    });
    const routes = [
        transit(
            'r9',
            '9',
            '23:37',
            '23:38',
            '23:40',
            '23:53',
            16,
            1,
            13,
            'Köln Heumarkt',
            'Köln Rath/Heumar Königsforst',
        ),
        transit(
            'r18',
            '18',
            '23:44',
            '23:47',
            '23:50',
            '23:59',
            15,
            3,
            9,
            'Köln Dom/Hbf',
            'Köln Buchheim Herler Str.',
            [{ name: 'Köln Appellhofplatz', time: '23:49' }],
        ),
        transit(
            'r1',
            '1',
            '23:45',
            '23:46',
            '23:48',
            '00:01',
            16,
            1,
            13,
            'Köln Heumarkt',
            'Bergisch Gladbach Bensberg',
        ),
        transit(
            'r7',
            '7',
            '23:48',
            '23:49',
            '23:51',
            '00:04',
            16,
            1,
            13,
            'Köln Heumarkt',
            'Köln Zündorf',
            [],
            true,
        ),
        transit(
            'r16',
            '16',
            '23:51',
            '23:54',
            '23:57',
            '00:06',
            15,
            3,
            9,
            'Köln Dom/Hbf',
            'Köln Niehl Sebastianstr.',
            [{ name: 'Köln Appellhofplatz', time: '23:56' }],
            true,
        ),
        {
            id: 'bike',
            mode: 'bike',
            depart: '23:37',
            arrive: '23:42',
            duration: 6,
            walking: 0,
            changes: 0,
            delayed: false,
            legs: [
                {
                    mode: 'bike',
                    title: 'Cycle to Museum Ludwig',
                    detail: '6 min by bike',
                    time: '23:37',
                    duration: 6,
                },
            ],
        },
        {
            id: 'walk',
            mode: 'walk',
            depart: '23:37',
            arrive: '23:59',
            duration: 23,
            walking: 23,
            changes: 0,
            delayed: false,
            legs: [
                {
                    mode: 'walk',
                    title: 'Walk to Museum Ludwig',
                    detail: '23 min on foot',
                    time: '23:37',
                    duration: 23,
                },
            ],
        },
    ];
    const minutes = (time) => {
        const [h, m] = time.split(':').map(Number);
        return h * 60 + m;
    };
    const timeline = (time) => minutes(time) + (time < capture.time ? 1440 : 0);
    const normal = (s) =>
        s
            .trim()
            .toLocaleLowerCase()
            .replace(/^köln\s+/, '');
    const matches = (from, to) =>
        normal(from) === 'neumarkt' && normal(to) === 'museum ludwig';
    function fromStop(route, stopId) {
        if (!route || !stopId) return route;
        const boarding = route.legs.findIndex(
            (leg) => leg.mode === 'transit' && leg.boardStop === stopId,
        );
        if (boarding < 1) return route;
        const approach = route.legs.slice(0, boarding);
        if (approach.some((leg) => leg.mode !== 'walk')) return route;
        const approachMinutes = approach.reduce(
            (total, leg) => total + leg.duration,
            0,
        );
        // A timetable handoff starts at the stop, not at the captured walk's origin.
        return {
            ...route,
            boardingStop: stopId,
            depart: route.legs[boarding].time,
            duration: route.duration - approachMinutes,
            walking: route.walking - approachMinutes,
            legs: route.legs.slice(boarding),
        };
    }
    function filtered(options = {}) {
        const {
            mode = 'transit',
            sort = 'arrival',
            schedule = 'now',
            date = capture.date,
            time = capture.time,
            maxWalk = 60,
            avoidDelays = false,
            direct = false,
            boardingStop,
        } = options;
        const day =
            date === capture.date ? 0 : date === '2026-09-08' ? 1440 : null;
        if (day === null || (schedule !== 'now' && !/^\d{2}:\d{2}$/.test(time)))
            return [];
        const target = day + minutes(time);
        return routes
            .map((route) => fromStop(route, boardingStop))
            .filter(
                (r) =>
                    (mode === 'all' || r.mode === mode) &&
                    r.walking <= Number(maxWalk) &&
                    (!avoidDelays || !r.delayed) &&
                    (!direct || r.changes === 0) &&
                    (schedule === 'now' ||
                        (schedule === 'arrive'
                            ? timeline(r.arrive) <= target
                            : timeline(r.depart) >= target)),
            )
            .sort(
                (a, b) =>
                    (sort === 'duration'
                        ? a.duration - b.duration
                        : sort === 'walking'
                          ? a.walking - b.walking
                          : sort === 'changes'
                            ? a.changes - b.changes
                            : 0) || timeline(a.arrive) - timeline(b.arrive),
            );
    }
    function tripSteps(route) {
        const steps = route.legs.flatMap((l) =>
            l.mode === 'transit'
                ? [
                      { ...l, title: `Board line ${route.line}` },
                      ...l.intermediate.map((s) => ({
                          title: s.name,
                          time: s.time,
                          detail: 'Stay on board',
                          mode: 'ride',
                      })),
                      {
                          title: `Get off at ${l.stop}`,
                          detail: 'Your stop',
                          time: l.alight,
                          mode: 'alight',
                      },
                  ]
                : [l],
        );
        return [
            ...steps,
            {
                title: 'Museum Ludwig',
                detail: 'You’ve arrived',
                time: route.arrive,
                mode: 'arrive',
            },
        ];
    }
    return {
        capture,
        routes,
        filtered,
        matches,
        timeline,
        tripSteps,
        fromStop,
    };
})();
