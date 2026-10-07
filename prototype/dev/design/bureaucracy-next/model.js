(function (root) {
    const fresh = () => ({
        person: 'self',
        process: 'blue-card',
        tab: 'overview',
        expiry: '2026-10-01',
        candidate: null,
        questionSkipped: false,
        appointment: null,
        submission: null,
        prepared: false,
        docs: [
            'confirmed_for_use',
            'reported_available',
            'missing',
            'missing',
            'unknown',
        ],
        otherProcesses: [],
    });
    const canEdit = (s) => s.person === 'self';
    const blank = () => ({
        ...fresh(),
        profileKind: 'personal',
        profileId: 'fresh-design-v1',
        scenario: 'fresh',
        process: null,
        expiry: null,
        docs: Array(5).fill('unknown'),
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
        blank,
        canEdit,
        validDate,
        confirmExpiry,
        documentAction,
        appointmentForComposer,
    };
})(globalThis);
