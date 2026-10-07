/* Public Neumarkt records read from running staging on 4 October 2026.
   A frozen design capture, never live travel advice. Only station.html loads it.
   The timetable's `all` board supplies rounded countdowns, not exact scheduled
   timestamps or boarding-point references. KVB point records are kept separate:
   sharing a line does not prove which point a departure boards from. */
(function (root) {
    const capturedAt = '2026-10-04T22:19:08+02:00';
    const capture = { date: '2026-10-04', time: '22:19', estimated: true };
    const stop = {
        id: 'neumarkt-station',
        name: 'Neumarkt',
        mode: 'mixed',
        operator: 'KVB',
        capture,
        lat: 50.93576,
        lng: 6.94811,
    };
    // [KVB ASS ID, mode, lines, latitude, longitude]. Coincident point records
    // stay distinct; neither coordinates nor names establish platform identity.
    const boardingPoints = [
        [211, 'tram', ['1', '7', '9'], 50.93576, 6.94811],
        [212, 'tram', ['1', '7', '9'], 50.93583, 6.94731],
        [213, 'tram', ['7'], 50.93633, 6.94688],
        [214, 'tram', ['3', '4', '16', '18'], 50.93464, 6.94947],
        [215, 'tram', ['3', '4', '16', '18'], 50.93542, 6.9491],
        [216, 'tram', ['7'], 50.93633, 6.94688],
        [217, 'tram', ['7'], 50.93636, 6.94689],
        [218, 'tram', ['7'], 50.93636, 6.94689],
        [221, 'bus', ['136', '146'], 50.93566, 6.94817],
        [222, 'bus', ['136', '146'], 50.9363, 6.94807],
    ].map(([id, mode, lines, lat, lng]) => ({
        id: `kvb:${id}`,
        sourceId: id,
        name: 'Neumarkt',
        mode,
        lines,
        lat,
        lng,
        platform: null,
    }));
    // Every line/destination group from TimetableService::boards(...).all.
    // [line, destination, mode, minutes, delay, disrupted, via, colour].
    const services = [
        [
            '7',
            'Zündorf',
            'tram',
            [0],
            0,
            true,
            ['Heumarkt', 'Deutzer Freiheit'],
            '#009A45',
        ],
        [
            '18',
            'Buchheim',
            'tram',
            [0],
            2,
            true,
            ['Appellhofplatz', 'Dom/Hbf'],
            '#0089CB',
        ],
        [
            '3',
            'Görlinger-Zentrum',
            'tram',
            [2],
            0,
            true,
            ['Appellhofplatz', 'Friesenplatz'],
            '#C8027E',
        ],
        [
            '4',
            'Schlebusch',
            'tram',
            [3],
            0,
            true,
            ['Poststr.', 'Severinstr.'],
            '#A01E5A',
        ],
        [
            '136',
            'Daimlerstr.',
            'bus',
            [3],
            0,
            false,
            ['Rudolfplatz', 'Moltkestr.'],
            '#4A4A4A',
        ],
        [
            '9',
            'Königsforst',
            'tram',
            [4],
            1,
            false,
            ['Heumarkt', 'Deutzer Freiheit'],
            '#E2001A',
        ],
        [
            '16',
            'Niehl Sebastianstr.',
            'tram',
            [7],
            2,
            true,
            ['Appellhofplatz', 'Dom/Hbf'],
            '#0089CB',
        ],
        [
            '1',
            'Weiden West',
            'tram',
            [7],
            1,
            false,
            ['Rudolfplatz', 'Moltkestr.'],
            '#E2001A',
        ],
        [
            '3',
            'Thielenbruch',
            'tram',
            [11],
            0,
            true,
            ['Poststr.', 'Severinstr.'],
            '#C8027E',
        ],
        ['1', 'Brück', 'tram', [11], 0, false, ['Heumarkt'], '#E2001A'],
        [
            '7',
            'Moltkestr.',
            'tram',
            [11],
            1,
            true,
            ['Rudolfplatz', 'Moltkestr.'],
            '#009A45',
        ],
        [
            '146',
            'Deckstein',
            'bus',
            [11],
            0,
            true,
            ['Rudolfplatz', 'Moltkestr.'],
            '#4A4A4A',
        ],
        [
            '4',
            'Rochusplatz',
            'tram',
            [12],
            2,
            true,
            ['Appellhofplatz', 'Friesenplatz'],
            '#A01E5A',
        ],
        [
            '18',
            'Klettenberg',
            'tram',
            [13],
            0,
            true,
            ['Poststr.', 'Barbarossaplatz'],
            '#0089CB',
        ],
        [
            '9',
            'Sülz',
            'tram',
            [14],
            0,
            false,
            ['Mauritiuskirche', 'Zülpicher Platz'],
            '#E2001A',
        ],
        [
            '16',
            'Sürth',
            'tram',
            [19],
            0,
            true,
            ['Poststr.', 'Barbarossaplatz'],
            '#0089CB',
        ],
    ].map(
        (
            [line, destination, type, minutes, delay, disrupted, via, color],
            index,
        ) => ({
            id: `neumarkt-capture-${index + 1}`,
            line,
            destination,
            type,
            minutes,
            delay,
            cancelled: false,
            disrupted,
            direction: null,
            via,
            color,
        }),
    );
    // UTC is only a wall-clock arithmetic carrier here. These clock labels are
    // Europe/Berlin capture time plus rounded minutes, explicitly approximate.
    const wallClock = Date.parse(`${capture.date}T${capture.time}:00Z`);
    const entries = services.flatMap((service) =>
        service.minutes.map((minutesAtCapture, index) => {
            const clock = new Date(
                wallClock + minutesAtCapture * 60000,
            ).toISOString();
            return {
                ...service,
                id: `${service.id}:${index}`,
                serviceId: service.id,
                stopId: stop.id,
                date: clock.slice(0, 10),
                time: clock.slice(11, 16),
                delayed: service.delay > 0,
                minutesAtCapture,
                timeBasis: 'rounded-countdown',
                boardingPointId: null,
                platform: null,
            };
        }),
    );
    root.StationCapture = {
        stop,
        entries,
        services,
        boardingPoints,
        capturedAt,
        timezone: 'Europe/Berlin',
        source: 'trias_rt',
        board: 'all',
        isLive: false,
        timeBasis: 'rounded-countdown',
        boardingPointSource: 'KVB Open Data',
        boardingPointAssignmentAvailable: false,
    };
})(globalThis);
