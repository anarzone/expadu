(function (root) {
    // The sample day's Blue Card appointment (connected/sample-day.js), so
    // Paperwork and Today show the same record. None without the sample day.
    function sampleAppointment() {
        const day = root.ExpaduSampleDay;
        const a = day?.appointment;
        if (a?.process !== 'blue-card') return null;
        const minutes = (t) => +t.slice(0, 2) * 60 + +t.slice(3, 5);
        return {
            id: 'sample-appointment',
            date: day.date,
            time: a.start,
            location: a.location,
            // Its recorded length and place, as the backend keeps them.
            duration: a.end ? minutes(a.end) - minutes(a.start) : null,
            ...(Number.isFinite(a.coordinates?.lat) &&
            Number.isFinite(a.coordinates?.lng)
                ? { lat: a.coordinates.lat, lng: a.coordinates.lng }
                : {}),
        };
    }
    const fresh = () => ({
        person: 'self',
        process: 'blue-card',
        tab: 'overview',
        expiry: '2026-10-01',
        candidate: null,
        questionSkipped: false,
        appointment: sampleAppointment(),
        submission: null,
        prepared: false,
        // The appointment step's nine documents (bc.blue_card), in order:
        // passport (ready), entry visa*, photo, contract (you have it),
        // qualification, BA approval*, rent proof, health insurance proof*,
        // professional licence* (*optional, "May be needed").
        docs: [
            'confirmed_for_use',
            'unknown',
            'missing',
            'reported_available',
            'missing',
            'unknown',
            'missing',
            'unknown',
            'unknown',
        ],
    });
    const canEdit = (s) => s.person === 'self';
    // What the sample day's person has told us: arrived from outside the EU
    // with a D visa to work, aiming for a Blue Card; address not registered
    // yet; health cover not confirmed; wants help with an account; has a
    // tax ID. Each of the plan's tasks follows from these (registry.js).
    // The move-in date isn't known yet: the plan asks for it.
    function sampleFacts(s) {
        if (
            !root.ExpaduSampleDay ||
            s?.person !== 'self' ||
            (s.scenario && s.scenario !== 'existing')
        )
            return {};
        return {
            arrival_planned: false,
            purpose: 'employment',
            citizenship_group: 'non_eu',
            current_residence_title: 'national_d_visa',
            entry_mode: 'd_visa',
            case_goal: 'blue_card',
            registration_status: 'not_registered',
            health_coverage_confirmed: false,
            bank_account_help_needed: true,
            tax_id_available: true,
        };
    }
    // The sample person's answer history, newest first: their first visit
    // on 2 Sept, and a correction the next day (they'd picked the wrong way
    // they entered). Kept permanently, as the backend keeps it.
    function sampleHistory(s) {
        if (!Object.keys(sampleFacts(s)).length) return [];
        const first = '2026-09-02T18:20:00+02:00',
            corrected = '2026-09-03T09:05:00+02:00';
        const asserted = (key, value, at = first) => ({
            key,
            previous: null,
            value,
            operation: 'assert',
            effectiveFrom: null,
            at,
            claims: [],
            // From before this visit: not part of a run of questions.
            seed: true,
        });
        return [
            {
                key: 'entry_mode',
                previous: { value: 'visa_free', state: 'value' },
                value: 'd_visa',
                operation: 'correct',
                effectiveFrom: null,
                at: corrected,
                claims: [],
                seed: true,
            },
            asserted('tax_id_available', true),
            asserted('bank_account_help_needed', true),
            asserted('health_coverage_confirmed', false),
            asserted('registration_status', 'not_registered'),
            asserted('case_goal', 'blue_card'),
            asserted('visa_expires_at', '2026-10-01'),
            asserted('purpose', 'employment'),
            asserted('current_residence_title', 'national_d_visa'),
            asserted('arrival_planned', false),
            asserted('citizenship_group', 'non_eu'),
            asserted('entry_mode', 'visa_free'),
        ];
    }
    const blank = () => ({
        ...fresh(),
        profileKind: 'personal',
        profileId: 'fresh-design-v1',
        scenario: 'fresh',
        process: null,
        expiry: null,
        appointment: null,
        docs: Array(9).fill('unknown'),
        workflow: 'not_started',
        tracked: [],
        records: {},
        history: [],
        household: {},
    });
    function validDate(value) {
        if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value))
            return false;
        const parsed = new Date(value + 'T12:00:00Z');
        return (
            Number.isFinite(parsed.getTime()) &&
            parsed.toISOString().slice(0, 10) === value
        );
    }
    function confirmExpiry(s, value) {
        if (!canEdit(s) || (value !== null && !validDate(value))) return false;
        const changed = s.expiry !== value;
        s.expiry = value;
        s.candidate = null;
        s.questionSkipped = false;
        s.expiryUnknown = false;
        if (changed && s.docs[0] === 'confirmed_for_use')
            s.docs[0] = 'needs_reconfirmation';
        return true;
    }
    function documentAction(s, index, action) {
        if (
            !canEdit(s) ||
            !Number.isInteger(index) ||
            index < 0 ||
            index >= s.docs.length
        )
            return false;
        if (action === 'have') {
            s.docs[index] = 'reported_available';
            return true;
        }
        if (
            action === 'confirm' &&
            ['reported_available', 'needs_reconfirmation'].includes(
                s.docs[index],
            )
        ) {
            s.docs[index] = 'confirmed_for_use';
            return true;
        }
        if (action === 'missing') {
            s.docs[index] = 'missing';
            return true;
        }
        return false;
    }
    function appointmentForComposer(s) {
        if (
            !canEdit(s) ||
            !validDate(s.appointment?.date) ||
            !/^([01]\d|2[0-3]):[0-5]\d$/.test(s.appointment?.time)
        )
            return null;
        return {
            id: 'bureaucracy-sample-appointment',
            type: 'appointment',
            title: 'Bureaucracy appointment',
            date: s.appointment.date,
            time: s.appointment.time,
            location: s.appointment.location || '',
            icon: 'calendar',
            color: 'peach',
            origin: 'User-recorded preview appointment',
        };
    }
    root.BureaucracyPreview = {
        fresh,
        sampleAppointment,
        sampleFacts,
        sampleHistory,
        blank,
        canEdit,
        validDate,
        confirmExpiry,
        documentAction,
        appointmentForComposer,
    };
})(globalThis);
