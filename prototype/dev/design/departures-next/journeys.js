'use strict';
// Public route results and steps read from staging's rendered UI on 7 Sep 2026.
// Clock frozen at 23:37. No personal addresses, ticket ownership or GPS copied.
// When the shared sample day loads first (connected/sample-day.js), its
// appointment trip is a second sample with its own date: Wed 30 Sep, 13:10.
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
        trip: 'capture',
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
            trip: 'capture',
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
            trip: 'capture',
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
    const clock = (value) =>
        `${String(Math.floor(value / 60) % 24).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
    const nextDate = (date) =>
        new Date(Date.parse(`${date}T12:00:00Z`) + 86400000)
            .toISOString()
            .slice(0, 10);
    // The appointment trip Today shows: the planned tram and the earlier one
    // it suggests. Scheduled sample times, no intermediate stops recorded.
    const sample = globalThis.ExpaduSampleDay;
    const plan = sample?.appointment;
    const planned = plan?.journey;
    const trips = {
        capture: {
            id: 'capture',
            date: capture.date,
            time: capture.time,
            from: capture.from,
            to: capture.to,
            place: capture.to,
            boardingStop: capture.boardingStop,
        },
        day: planned
            ? {
                  id: 'day',
                  date: sample.date,
                  time: sample.time,
                  from: planned.from,
                  to: planned.to,
                  place: plan.title,
                  start: plan.start,
                  kind: plan.kind,
                  activityId: plan.id,
                  alightStop: planned.alightStop || planned.to,
                  boardingStop: capture.boardingStop,
                  // Where the sample day puts you: a short walk from the
                  // trip's first stop. "Your location" in the planner.
                  here: {
                      name: 'Your location',
                      near: capture.boardingStop.name,
                      walk: planned.accessWalkMinutes ?? 0,
                  },
              }
            : null,
    };
    function dayRoute(depart, arrive, inPlan) {
        const walkIn = planned.accessWalkMinutes ?? 0,
            walkOut = planned.walkMinutes ?? 0;
        const leave = clock(minutes(depart) - walkIn),
            reach = clock(minutes(arrive) + walkOut);
        return {
            id: `day-${depart.replace(':', '')}`,
            trip: 'day',
            line: planned.line,
            depart: leave,
            arrive: reach,
            duration: minutes(reach) - minutes(leave),
            walking: walkIn + walkOut,
            changes: 0,
            mode: 'transit',
            delayed: false,
            inPlan,
            legs: [
                {
                    mode: 'walk',
                    title: `Walk to ${capture.boardingStop.name}`,
                    detail: `${walkIn} min on foot`,
                    time: leave,
                    duration: walkIn,
                },
                {
                    mode: 'transit',
                    boardStop: capture.boardingStop.id,
                    title: `Take line ${planned.line}`,
                    detail: planned.towards
                        ? `Towards ${planned.towards}`
                        : `Line ${planned.line}`,
                    time: depart,
                    duration: minutes(arrive) - minutes(depart),
                    stop: trips.day.alightStop,
                    alight: arrive,
                    intermediate: [],
                },
                {
                    mode: 'walk',
                    title: 'Walk to your appointment',
                    detail: `${walkOut} min on foot`,
                    time: arrive,
                    duration: walkOut,
                },
            ].filter((leg) => leg.mode === 'transit' || leg.duration > 0),
        };
    }
    const dayRoutes = planned
        ? [
              ...(planned.earlier
                  ? [
                        dayRoute(
                            planned.earlier.depart,
                            planned.earlier.arrive,
                            false,
                        ),
                    ]
                  : []),
              dayRoute(planned.depart, planned.arrive, true),
          ]
        : [];
    const all = [...routes, ...dayRoutes];
    const route = (id) => all.find((item) => item.id === id);
    const tripOf = (value) =>
        trips[(typeof value === 'string' ? route(value) : value)?.trip] ||
        trips.capture;
    const timeline = (time, trip = trips.capture) =>
        minutes(time) + (time < trip.time ? 1440 : 0);
    const normal = (s) =>
        s
            .trim()
            .toLocaleLowerCase()
            .replace(/^köln\s+/, '');
    // "Your location" starts where the sample day puts you.
    const origin = (from) =>
        trips.day && normal(from) === normal(trips.day.here.name)
            ? trips.day.from
            : from;
    const matches = (from, to) =>
        normal(origin(from)) === 'neumarkt' && normal(to) === 'museum ludwig';
    // Which sample a typed request asks for, if any.
    function tripFor(start, to) {
        if (matches(start, to)) return 'capture';
        const from = origin(start);
        const day = trips.day;
        return day &&
            normal(from) === normal(day.from) &&
            [day.to, day.alightStop].some((name) => normal(to) === normal(name))
            ? 'day'
            : null;
    }
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
        const trip = trips[options.trip] || trips.capture;
        const {
            mode = 'transit',
            sort = 'arrival',
            schedule = 'now',
            date = trip.date,
            time = trip.time,
            maxWalk = 60,
            avoidDelays = false,
            direct = false,
            boardingStop,
        } = options;
        const day =
            date === trip.date ? 0 : date === nextDate(trip.date) ? 1440 : null;
        if (day === null || (schedule !== 'now' && !/^\d{2}:\d{2}$/.test(time)))
            return [];
        const target = day + minutes(time);
        const at = (value) => timeline(value, trip);
        return (trip.id === 'day' ? dayRoutes : routes)
            .map((route) => fromStop(route, boardingStop))
            .filter(
                (r) =>
                    (mode === 'all' || r.mode === mode) &&
                    r.walking <= Number(maxWalk) &&
                    (!avoidDelays || !r.delayed) &&
                    (!direct || r.changes === 0) &&
                    (schedule === 'now' ||
                        (schedule === 'arrive'
                            ? at(r.arrive) <= target
                            : at(r.depart) >= target)),
            )
            .sort(
                (a, b) =>
                    (sort === 'duration'
                        ? a.duration - b.duration
                        : sort === 'walking'
                          ? a.walking - b.walking
                          : sort === 'changes'
                            ? a.changes - b.changes
                            : 0) || at(a.arrive) - at(b.arrive),
            );
    }
    function tripSteps(route) {
        const trip = tripOf(route);
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
                title: trip.place,
                detail: trip.start
                    ? `Starts at ${trip.start}`
                    : 'You’ve arrived',
                time: route.arrive,
                mode: 'arrive',
            },
        ];
    }
    return {
        capture,
        routes,
        day: trips.day,
        dayRoutes,
        all,
        trips,
        route,
        tripOf,
        tripFor,
        filtered,
        matches,
        timeline,
        tripSteps,
        fromStop,
    };
})();
