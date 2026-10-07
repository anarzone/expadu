/* One sample "today" for the connected previews: Wednesday 30 September 2026,
   13:10, Cologne. Today and Composer read it instead of the real clock, so a
   plan made on Today is "today" in Composer too. Everything here is fictional
   review data: no live listings, bookings or legal claims. Captured listings
   elsewhere (Events, Travel) keep their own capture dates. */
(function (root) {
    root.ExpaduSampleDay = {
        date: '2026-09-30',
        time: '13:10',
        city: 'Cologne',
        events: [
            {
                id: 'today-review-culture',
                type: 'event',
                title: 'Street culture night',
                date: '2026-09-30',
                start: '18:30',
                end: '21:00',
                venue: 'Rheinauhafen',
                area: 'Cologne',
                category: 'Culture',
                icon: 'calendar',
                color: 'blue',
                attendancePolicy: 'fixed',
                captured: 'Sample programme',
                illustration: 'garden',
                occurrence: 'today-review-culture-2026-09-30',
                description:
                    'An illustrative evening of music by the Rhine. Sample event for this design preview.',
            },
            {
                id: 'today-review-cinema',
                type: 'event',
                title: 'Cinema under the stars',
                date: '2026-09-30',
                start: '20:00',
                end: '22:00',
                venue: 'Stadtgarten',
                area: 'Cologne',
                category: 'Film',
                icon: 'film',
                color: 'blue',
                attendancePolicy: 'fixed',
                captured: 'Sample programme',
                illustration: 'film',
                occurrence: 'today-review-cinema-2026-09-30',
                description:
                    'An illustrative outdoor film night. Sample event for this design preview.',
            },
        ],
        appointment: {
            id: 'today-review-appointment',
            title: 'Blue Card appointment',
            location: 'Mülheim',
            start: '14:00',
            end: '14:30',
            planStatus: 'Confirmed',
            // Recorded from Paperwork, so Today can show what to bring.
            process: 'blue-card',
            kind: 'official',
            journey: {
                activityId: 'today-review-appointment',
                from: 'Neumarkt',
                to: 'Mülheim',
                line: '4',
                depart: '13:24',
                arrive: '13:52',
                expectedDepart: '13:24',
                expectedArrive: '13:52',
                walkMinutes: 6,
                // Sample walk from the starting point to the boarding stop.
                accessWalkMinutes: 4,
                // Sample earlier service on the same line, offered when the
                // scheduled one leaves too little time before an appointment.
                earlier: { depart: '13:14', arrive: '13:42' },
                status: 'scheduled',
                delay: 0,
            },
        },
        upcoming: [
            {
                id: 'today-review-next-class',
                title: 'Language class',
                location: 'Ehrenfeld',
                date: '2026-10-01',
                start: '18:00',
                end: '19:00',
            },
            {
                id: 'today-review-next-walk',
                title: 'Walk by the Rhine',
                location: 'Rheinauhafen',
                date: '2026-10-03',
                start: '10:30',
                end: '11:30',
            },
        ],
    };
})(globalThis);
