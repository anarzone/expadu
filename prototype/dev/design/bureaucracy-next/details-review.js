/* Interaction fixtures. Questions/values copied from the backend facts.yaml,
   8 September snapshot. No eligibility, legal dates or question ranking is derived. */
(function (root) {
    const fields = [
        {
            key: 'citizenship_group',
            label: 'Citizenship group',
            icon: 'people',
            title: 'Which citizenship group applies to you?',
            options: [
                ['eu', 'EU'],
                ['non_eu', 'Non-EU'],
            ],
        },
        {
            key: 'arrival_planned',
            label: 'Your move',
            icon: 'pin',
            title: 'Are you still planning this move to Germany?',
            options: [
                [true, 'Yes, still planning'],
                [false, 'No, I have arrived'],
            ],
        },
        {
            key: 'current_residence_title',
            label: 'Current visa or permit',
            icon: 'document',
            title: 'Which German visa or residence title do you currently hold?',
            options: [
                ['national_d_visa', 'National D visa'],
                ['standard_work_permit', 'Work permit'],
                ['blue_card', 'EU Blue Card'],
                ['family_reunification', 'Family reunification'],
                ['settlement_permit_9', 'Settlement permit · §9'],
                ['settlement_permit_18c', 'Settlement permit · §18c'],
                [
                    'settlement_permit_unknown',
                    'Settlement permit · type unknown',
                ],
                ['other', 'Another title'],
            ],
        },
        {
            key: 'visa_expires_at',
            label: 'Entry visa expiry',
            icon: 'calendar',
            title: 'When does your entry visa expire?',
            type: 'date',
        },
        {
            key: 'registration_status',
            label: 'Address registration',
            icon: 'pin',
            title: 'Have you completed address registration for this home?',
            options: [
                ['registered', 'Yes, registered'],
                ['not_registered', 'Not yet'],
            ],
        },
        {
            key: 'purpose',
            label: 'Purpose of stay',
            icon: 'briefcase',
            title: 'What is the main purpose of your stay in Germany?',
            options: [
                ['employment', 'Employment'],
                ['study', 'Study'],
                ['freelance', 'Freelance'],
                ['family', 'Family'],
                ['digital_nomad', 'Digital nomad'],
                ['other', 'Something else'],
            ],
        },
        {
            key: 'residence_title_expires_at',
            label: 'Permit expiry',
            icon: 'calendar',
            title: 'When does your current visa or residence permit expire?',
            type: 'date',
        },
    ];
    const copy = (v) => JSON.parse(JSON.stringify(v));
    const field = (key) => fields.find((f) => f.key === key);
    const date = (v) =>
        typeof v === 'string' &&
        /^\d{4}-\d{2}-\d{2}$/.test(v) &&
        Number.isFinite(Date.parse(v)) &&
        new Date(v).toISOString().slice(0, 10) === v;
    const valid = (f, v) =>
        f &&
        (['unknown', 'declined'].includes(v) ||
            (f.type === 'date'
                ? date(v)
                : f.options.some(([value]) => value === v)));
    const state = (value) =>
        ['unknown', 'declined'].includes(value) ? value : 'value';
    function create(known = {}) {
        return {
            open: false,
            mode: 'summary',
            current: null,
            draft: null,
            revision: 0,
            deferred: [],
            history: [],
            conflicts: {},
            facts: Object.fromEntries(
                Object.entries(known)
                    .filter(([k, v]) => valid(field(k), v))
                    .map(([k, value]) => [
                        k,
                        {
                            value,
                            state: state(value),
                            source: 'Recorded sample',
                            at: null,
                        },
                    ]),
            ),
        };
    }
    function available(r) {
        // An explicit fixture sequence, not a production assessment or dependency engine.
        return fields.filter(
            (f) =>
                (f.key !== 'visa_expires_at' ||
                    r.facts.current_residence_title?.value ===
                        'national_d_visa' ||
                    r.facts.visa_expires_at) &&
                (f.key !== 'residence_title_expires_at' ||
                    [
                        'standard_work_permit',
                        'blue_card',
                        'family_reunification',
                    ].includes(r.facts.current_residence_title?.value) ||
                    r.facts.residence_title_expires_at),
        );
    }
    function begin(r) {
        r.open = true;
        if (
            r.draft &&
            ['answer', 'reason', 'confirm', 'conflict'].includes(r.mode)
        )
            return true;
        const next = available(r).find(
            (f) => !r.facts[f.key] && !r.deferred.includes(f.key),
        );
        if (next) return edit(r, next.key);
        r.mode = 'summary';
        return true;
    }
    function edit(r, key) {
        if (!field(key)) return false;
        r.notice = null;
        r.open = true;
        r.current = key;
        if (r.conflicts[key]) {
            r.mode = 'conflict';
            r.draft = {
                value: null,
                operation: 'resolve',
                revision: r.revision,
            };
        } else {
            r.mode = r.facts[key] ? 'reason' : 'answer';
            r.draft = {
                value: r.facts[key]?.value ?? null,
                operation: r.facts[key] ? null : 'assert',
                revision: r.revision,
            };
        }
        return true;
    }
    function reason(r, operation) {
        if (r.mode !== 'reason' || !['correct', 'change'].includes(operation))
            return false;
        r.draft.operation = operation;
        r.mode = 'answer';
        return true;
    }
    function select(r, value) {
        if (
            !['answer', 'conflict'].includes(r.mode) ||
            !valid(field(r.current), value)
        )
            return false;
        r.draft.value = value;
        return true;
    }
    function effective(r, value) {
        if (r.draft?.operation !== 'change' || (value && !date(value)))
            return false;
        r.draft.effectiveFrom = value || null;
        return true;
    }
    function review(r) {
        if (
            !['answer', 'conflict'].includes(r.mode) ||
            !r.draft?.operation ||
            !valid(field(r.current), r.draft.value)
        )
            return false;
        r.mode = 'confirm';
        return true;
    }
    function confirm(r) {
        if (
            r.mode !== 'confirm' ||
            !r.draft?.operation ||
            !valid(field(r.current), r.draft.value)
        )
            return false;
        if (r.draft.revision !== r.revision) {
            r.mode = 'stale';
            return false;
        }
        const { value, operation, effectiveFrom = null } = r.draft;
        const at = new Date().toISOString();
        r.history.unshift({
            key: r.current,
            previous: copy(r.facts[r.current] || null),
            value,
            operation,
            effectiveFrom,
            at,
            claims: copy(r.conflicts[r.current] || []),
        });
        r.facts[r.current] = {
            value,
            state: state(value),
            source: 'Confirmed in sample',
            effectiveFrom,
            at,
        };
        delete r.conflicts[r.current];
        r.deferred = r.deferred.filter((key) => key !== r.current);
        r.revision++;
        r.draft = null;
        r.mode = 'summary';
        r.notice = 'Answer saved for this person.';
        return true;
    }
    function cancel(r) {
        r.draft = null;
        r.mode = 'summary';
        return true;
    }
    function skip(r) {
        if (!r.facts[r.current] && !r.deferred.includes(r.current))
            r.deferred.push(r.current);
        return cancel(r);
    }
    function exampleConflict(r) {
        r.conflicts.registration_status = [
            { value: 'registered', source: 'Earlier sample answer' },
            { value: 'not_registered', source: 'Later sample answer' },
        ];
        return edit(r, 'registration_status');
    }
    function label(key, value) {
        if (value === 'unknown') return 'I’m not sure';
        if (value === 'declined') return 'Prefer not to say';
        if (value === undefined || value === null) return 'Not answered';
        const f = field(key);
        if (f?.type === 'date' && date(value))
            return new Date(value + 'T12:00:00').toLocaleDateString('en-GB', {
                day: 'numeric',
                month: 'short',
                year: 'numeric',
            });
        return f?.options.find(([v]) => v === value)?.[1] || String(value);
    }
    root.BureaucracyDetails = {
        fields,
        field,
        create,
        available,
        begin,
        edit,
        reason,
        select,
        effective,
        review,
        confirm,
        cancel,
        skip,
        exampleConflict,
        label,
    };
})(globalThis);
