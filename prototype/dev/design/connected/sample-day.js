/* One sample "today" for the connected previews: Wednesday 30 September 2026,
   13:10, Cologne. Today and Composer read it instead of the real clock, so a
   plan made on Today is "today" in Composer too. Everything here is fictional
   review data: no live listings, bookings or legal claims. Events opens on this
   day too; its captured 8 September listings are shown as past. Travel opens
   on it with the appointment trip below; its older captures keep their own
   dates. */
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
                // Real venue points from Expadu's local catalogue (spots,
                // read 7 October 2026); the events themselves are fictional.
                area: 'Neustadt-Süd',
                lat: 50.92262,
                lng: 6.96838,
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
                area: 'Neustadt-Nord',
                lat: 50.9445,
                lng: 6.93694,
                // Like the captured film listing, films count as Culture.
                category: 'Culture',
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
            // Where it is: Wiener Platz, the appointment area (the place
            // layer's anchor in sample-places.js). Paperwork's record keeps
            // this point, so the appointment can be routed to.
            coordinates: { lat: 50.96103, lng: 7.0048 },
            // Recorded from Paperwork, so Today can show what to bring.
            process: 'blue-card',
            kind: 'official',
            journey: {
                activityId: 'today-review-appointment',
                from: 'Neumarkt',
                to: 'Mülheim',
                line: '4',
                // Line 4's Mülheim direction in the Neumarkt capture of
                // 4 October; Wiener Platz is the appointment area Composer
                // and the place layer use.
                towards: 'Schlebusch',
                alightStop: 'Wiener Platz',
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
        // The person's own Paperwork records, as the Paperwork preview's
        // sample shows them: the checklist's ready count and their own visa
        // date (never a computed legal deadline). Today and Composer read
        // them from here.
        records: [
            {
                id: 'blue-card',
                title: 'EU Blue Card',
                prepared: 1,
                total: 5,
                nextAction: 'Review your documents',
                deadline: '2026-10-01',
                deadlineLabel: 'D visa expires',
                deadlineAction:
                    'Submit the Blue Card application before your D visa expires',
                deadlineShort: 'Submit your Blue Card application',
                submitted: false,
            },
        ],
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
