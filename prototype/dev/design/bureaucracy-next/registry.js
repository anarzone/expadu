/* The backend's fact registry and question order, as the mock uses them.
   Wording, types and options: database/seeders/data/bureaucracy/schema/
   facts.yaml (registry 2026-09-08.2). The registry has no display labels,
   so labels, icons and option names are UI copy, the same as the app's
   (resources/js/components/paperwork/facts.ts). Which task an answer opens:
   each catalogue card's applies_if. The order: question-protocol.yaml's
   orientation first, then the rest by the registry's priority. Sample only;
   the backend decides what is asked and when. */
(function (root) {
    const residenceTitles = [
        ['national_d_visa', 'National D visa'],
        ['standard_work_permit', 'Work permit'],
        ['blue_card_pending', 'Blue Card applied for'],
        ['blue_card', 'EU Blue Card'],
        ['family_reunification', 'Family reunification'],
        ['settlement_permit_9', 'Settlement permit · §9'],
        ['settlement_permit_18c', 'Settlement permit · §18c'],
        ['settlement_permit_unknown', 'Settlement permit · type unknown'],
        ['other', 'Another title'],
        // Entered visa-free or not here yet (facts.yaml, cf1b1caa); the
        // app's own label.
        ['none', 'No residence title yet'],
    ];
    const titles = (values) =>
        values.map((v) => residenceTitles.find(([x]) => x === v));
    const yesNoUnsure = [
        ['yes', 'Yes'],
        ['no', 'No'],
        ['unsure', 'I’m not sure yet'],
    ];
    // [key, label, icon, type, options, priority, question, why]
    const rows = [
        [
            'citizenship_group',
            'Citizenship group',
            'people',
            'enum',
            [
                ['eu', 'EU'],
                ['non_eu', 'Non-EU'],
            ],
            100,
            'Which citizenship group applies to you?',
            'EU and non-EU citizens follow different residence procedures.',
        ],
        [
            'arrival_planned',
            'Your move',
            'pin',
            'boolean',
            [
                [true, 'Yes, still planning'],
                [false, 'No, I have arrived'],
            ],
            100,
            'Are you still planning this move to Germany?',
            'Planning a move is different from having already arrived.',
        ],
        [
            'current_residence_title',
            'Current visa or permit',
            'document',
            'enum',
            titles([
                'national_d_visa',
                'standard_work_permit',
                'blue_card',
                'family_reunification',
                'settlement_permit_9',
                'settlement_permit_18c',
                'settlement_permit_unknown',
                'other',
                'none',
            ]),
            100,
            'Which German visa or residence title do you currently hold?',
            'Your current title changes the application route and which deadline applies.',
        ],
        [
            'visa_expires_at',
            'Entry visa expiry',
            'calendar',
            'date',
            null,
            100,
            'When does your entry visa expire?',
            'We use this date to calculate your residence-application deadline.',
        ],
        [
            'residence_title_expires_at',
            'Permit expiry',
            'calendar',
            'date',
            null,
            100,
            'When does your current visa or residence permit expire?',
            'We use this date to warn you before your current permission ends.',
        ],
        [
            'purpose',
            'Purpose of stay',
            'briefcase',
            'enum',
            [
                ['employment', 'Employment'],
                ['study', 'Study'],
                ['freelance', 'Freelance'],
                ['family', 'Family'],
                ['digital_nomad', 'Digital nomad'],
                ['other', 'Something else'],
            ],
            100,
            'What is the main purpose of your stay in Germany?',
            'Your purpose determines which residence route and checklist apply.',
        ],
        [
            'entry_mode',
            'How you entered',
            'document',
            'enum',
            [
                ['d_visa', 'With a national D visa'],
                ['visa_free', 'Without a visa'],
                ['has_permit', 'With a residence permit'],
            ],
            100,
            'How did you enter Germany for your current stay?',
            'Your entry mode determines which residence deadline applies.',
        ],
        [
            'arrival_date',
            'Arrival in Germany',
            'calendar',
            'date',
            null,
            100,
            'When did you arrive in Germany for this stay?',
            'An actual arrival date is different from a planned arrival.',
        ],
        [
            'moved_in_at',
            'Move-in date',
            'pin',
            'date',
            null,
            100,
            'When did you actually move into your current home?',
            'Moving in and having the housing provider’s confirmation are different facts.',
        ],
        [
            'case_goal',
            'What you want to do next',
            'arrow',
            'enum',
            [
                ['blue_card', 'Get an EU Blue Card'],
                ['family_reunification_permit', 'Join my family'],
                ['renew_current_title', 'Renew my current permit'],
                ['settlement_permit', 'Get a settlement permit'],
                ['understand_options', 'Understand my options'],
            ],
            90,
            'What do you want to do next with your residence status?',
            'This keeps the checklist focused on your next real decision.',
        ],
        [
            'permit_track',
            'Work permit route',
            'briefcase',
            'enum',
            [
                ['standard', 'Standard work permit'],
                ['blue_card', 'EU Blue Card'],
                ['chancenkarte', 'Opportunity Card (Chancenkarte)'],
            ],
            95,
            'Which employment residence track are you following?',
            'Standard work permits, Blue Cards, and Chancenkarte cases have different requirements.',
        ],
        [
            'registration_status',
            'Address registration',
            'pin',
            'enum',
            [
                ['registered', 'Yes, registered'],
                ['not_registered', 'Not yet'],
            ],
            90,
            'Have you completed address registration for this home?',
            'Having a home or a confirmation document does not mean registration is complete.',
        ],
        [
            'housing_provider_confirmation',
            'Housing provider’s confirmation',
            'document',
            'enum',
            [
                ['available', 'I have it'],
                ['requested', 'I asked for it'],
                ['not_available', 'I don’t have it'],
            ],
            80,
            'Do you have the housing provider’s confirmation for this home?',
            'We keep document availability separate from whether you already live there.',
        ],
        [
            'health_coverage_confirmed',
            'Health insurance',
            'health',
            'boolean',
            [
                [true, 'Confirmed with my insurer'],
                [false, 'Not yet'],
            ],
            85,
            'Have you confirmed health insurance cover for your stay in Germany with your insurer?',
            'This records your confirmation, not an insurance eligibility decision. Recheck it if your situation or cover changes.',
        ],
        [
            'residence_card_expires_at',
            'Residence card expiry',
            'calendar',
            'date',
            null,
            80,
            'What expiry date is printed on your physical residence card?',
            'The card’s validity is recorded separately from your legal residence status.',
        ],
        [
            'german_level',
            'German level',
            'people',
            'enum',
            [
                ['none', 'None yet'],
                ['a1', 'A1'],
                ['a2', 'A2'],
                ['b1', 'B1'],
                ['b2', 'B2'],
                ['c1', 'C1'],
                ['c2', 'C2'],
            ],
            70,
            'What is your current German level?',
            'Language requirements and faster settlement routes depend on your documented level.',
        ],
        [
            'weekly_work_hours',
            'Weekly working hours',
            'clock',
            'integer',
            null,
            80,
            'How many hours per week do you currently work?',
            'One spouse settlement route requires at least 20 hours of work per week.',
        ],
        [
            'blue_card_qualifying_months',
            'Months of Blue Card employment',
            'calendar',
            'integer',
            null,
            90,
            'How many complete months of qualifying Blue Card employment and pension or equivalent contributions can you document?',
            'The 21- and 27-month settlement routes count qualifying months, not simply time in Germany.',
        ],
        [
            'livelihood_secured',
            'Livelihood secured',
            'check',
            'enum',
            yesNoUnsure,
            75,
            'Is your household livelihood currently secured without Bürgergeld or Sozialhilfe?',
            'Residence and settlement routes can depend on secured livelihood.',
        ],
        [
            'housing_sufficient',
            'Enough living space',
            'pin',
            'enum',
            yesNoUnsure,
            60,
            'Do you believe your household has sufficient living space for everyone registered there?',
            'The authority checks sufficient housing for several settlement routes.',
        ],
        [
            'legal_social_knowledge_proved',
            'Knowledge of life in Germany',
            'document',
            'enum',
            yesNoUnsure,
            55,
            'Can you prove basic knowledge of Germany’s legal and social system, for example with Leben in Deutschland?',
            'This is one of the statutory settlement-permit checks.',
        ],
        [
            'tax_id_available',
            'Tax ID',
            'document',
            'boolean',
            [
                [true, 'I have it'],
                [false, 'Not yet'],
            ],
            55,
            'Do you already have access to your German tax identification number?',
            'We only need to know whether you have it, not the number itself. Moving home does not require a new number.',
        ],
        [
            'bank_account_help_needed',
            'Help with a bank account',
            'document',
            'boolean',
            [
                [true, 'Yes, please'],
                [false, 'No, thanks'],
            ],
            50,
            'Do you need help finding or opening an account for your everyday payments?',
            'If your existing account meets your needs, we should not tell you to open another one. Do not enter account numbers.',
        ],
        [
            'family_residence_permit_held_since',
            'Family permit held since',
            'calendar',
            'date',
            null,
            85,
            'Since when have you continuously held your family-reunification residence permit?',
            'Several renewal and settlement routes use the duration of this permit.',
        ],
        [
            'marital_household_continues',
            'Living together as a married household',
            'people',
            'boolean',
            [
                [true, 'Yes'],
                [false, 'No'],
            ],
            95,
            'Do you and your spouse still live together as a married household in Germany?',
            'Continuing family residence and an independent residence right follow different rules.',
        ],
        [
            'sponsor',
            'Family member you’re joining',
            'people',
            'enum',
            [
                ['german', 'German citizen'],
                ['eu_citizen', 'EU citizen'],
                ['non_eu', 'Non-EU citizen'],
            ],
            100,
            'Is the family member you are joining a German citizen, another EU citizen, or a non-EU citizen?',
            'We need to distinguish the family rules without assuming citizenship from a chosen path.',
        ],
        [
            'sponsor_current_title',
            'Your spouse’s residence status',
            'people',
            'enum',
            titles([
                'national_d_visa',
                'standard_work_permit',
                'blue_card_pending',
                'blue_card',
                'settlement_permit_9',
                'settlement_permit_18c',
                'settlement_permit_unknown',
                'other',
            ]),
            95,
            'Which German residence status does your spouse currently have?',
            'Family-reunification and settlement options depend on the sponsor’s current title.',
        ],
        [
            'sponsor_title_at_entry',
            'Your sponsor’s status when you joined',
            'people',
            'enum',
            titles([
                'national_d_visa',
                'standard_work_permit',
                'blue_card_pending',
                'blue_card',
                'settlement_permit_9',
                'settlement_permit_18c',
                'settlement_permit_unknown',
                'other',
            ]),
            80,
            'What residence status did your sponsor have when you joined them?',
            'Their status at that time may differ from the status they hold now.',
        ],
    ];
    // reconfirm_after_days (facts.yaml): once an answer is older, it drops
    // out of the confirmed view and its question is asked again.
    const reconfirm = {
        citizenship_group: 365,
        arrival_planned: 90,
        current_residence_title: 180,
        visa_expires_at: 180,
        residence_title_expires_at: 180,
        purpose: 180,
        entry_mode: 180,
        arrival_date: 365,
        moved_in_at: 180,
        case_goal: 180,
        permit_track: 180,
        registration_status: 180,
        housing_provider_confirmation: 90,
        health_coverage_confirmed: 90,
        residence_card_expires_at: 180,
        german_level: 180,
        weekly_work_hours: 90,
        blue_card_qualifying_months: 30,
        livelihood_secured: 90,
        housing_sufficient: 180,
        legal_social_knowledge_proved: 365,
        tax_id_available: 365,
        bank_account_help_needed: 180,
        family_residence_permit_held_since: 180,
        marital_household_continues: 180,
        sponsor: 180,
        sponsor_current_title: 180,
        sponsor_title_at_entry: 365,
    };
    const fields = rows.map(
        ([key, label, icon, type, options, priority, question, why]) => ({
            key,
            label,
            icon,
            type,
            // The registry's own question and why; options in its order.
            title: question,
            why,
            options: options || [],
            priority,
            // No fact in the registry allows "Doesn't apply to me" yet
            // (allows_not_applicable defaults to false).
            allowsNotApplicable: false,
            reconfirmAfterDays: reconfirm[key],
        }),
    );
    const field = (key) => fields.find((f) => f.key === key);
    // An answer that says something (not "I don't know", not skipped).
    const said = (facts, key) =>
        facts[key] && facts[key].state === 'value'
            ? facts[key].value
            : undefined;
    const answered = (facts, key) => !!facts[key];
    // question-protocol.yaml: the orientation questions and when each is asked.
    const orientation = [
        ['citizenship_group', () => true],
        ['arrival_planned', () => true],
        [
            'current_residence_title',
            (f) =>
                said(f, 'citizenship_group') === 'non_eu' &&
                said(f, 'arrival_planned') === false,
        ],
        [
            'visa_expires_at',
            (f) => said(f, 'current_residence_title') === 'national_d_visa',
        ],
        [
            'residence_title_expires_at',
            (f) =>
                [
                    'standard_work_permit',
                    'blue_card',
                    'family_reunification',
                ].includes(said(f, 'current_residence_title')),
        ],
    ];
    // The catalogue cards' applies_if for the sample's tasks, the facts a
    // task's dates wait for once it applies, and the catalogue phase of its
    // first step.
    const tasks = [
        {
            id: 'blue-card',
            definition: 'residence.blue_card.first',
            urgency: 'critical',
            when: {
                citizenship_group: 'non_eu',
                purpose: 'employment',
                current_residence_title: 'national_d_visa',
                case_goal: 'blue_card',
                entry_mode: 'd_visa',
            },
            // Both its targets aim for the visa expiry.
            dates: ['visa_expires_at'],
            phase: 'arrival',
        },
        {
            id: 'address',
            definition: 'address.registration',
            urgency: 'critical',
            when: {
                arrival_planned: false,
                registration_status: 'not_registered',
            },
            dates: ['moved_in_at'],
            phase: 'arrival',
        },
        {
            id: 'health',
            definition: 'health.coverage',
            urgency: 'critical',
            when: { health_coverage_confirmed: false },
            dates: [],
            phase: 'arrival',
        },
        {
            id: 'bank',
            definition: 'banking.account',
            urgency: 'high',
            when: { bank_account_help_needed: true },
            dates: [],
            phase: 'first_30_days',
        },
        // Joining a spouse: case.family.first_permit.prepare and the
        // appointment (family reunification, a sponsor of any listed title;
        // its situation needs purpose family), and the integration course.
        {
            id: 'family',
            definition: 'residence.family.first',
            urgency: 'critical',
            when: {
                purpose: 'family',
                current_residence_title: 'national_d_visa',
                case_goal: 'family_reunification_permit',
                entry_mode: 'd_visa',
                sponsor_current_title: [
                    'national_d_visa',
                    'standard_work_permit',
                    'blue_card_pending',
                    'blue_card',
                    'settlement_permit_9',
                    'settlement_permit_18c',
                    'settlement_permit_unknown',
                    'other',
                ],
            },
            dates: ['visa_expires_at'],
            phase: 'arrival',
        },
        {
            id: 'integration',
            definition: 'education.integration_course',
            urgency: 'low',
            when: { purpose: 'family', sponsor: ['non_eu', 'german'] },
            dates: [],
            phase: 'settling',
        },
    ];
    // question-protocol.yaml (2026-10-08.1) not_asked: questions your own
    // answers settle. Choosing the Blue Card as your goal says which
    // employment track you follow (owner decision, 8 Oct 2026).
    const notAsked = [
        ['permit_track', (f) => said(f, 'case_goal') === 'blue_card'],
    ];
    // The cards' urgency (QuestionProtocol ranks by it); a task's own date
    // question gets a little more (AnchorBonus).
    const urgency = { critical: 3, high: 2, normal: 1, low: 0 };
    const anchorBonus = 0.5;
    // A task applies when every condition is answered as it asks.
    // A condition is a value or a list of values (applies_if `in`).
    const meets = (value, wanted) =>
        Array.isArray(wanted) ? wanted.includes(value) : value === wanted;
    const applies = (facts, t) =>
        Object.entries(t.when).every(([k, v]) => meets(said(facts, k), v));
    // A task is still possible while no answer rules it out.
    // A task is still possible while no answer rules it out, and no
    // condition rests on a first question that won't be asked of you (an
    // EU citizen is never asked for a German permit, so a permit route
    // can't open for them).
    const unaskable = (facts, k) =>
        orientation.some(([key, when]) => key === k && !when(facts));
    const possible = (facts, t) =>
        Object.entries(t.when).every(([k, v]) =>
            said(facts, k) === undefined
                ? !unaskable(facts, k)
                : meets(said(facts, k), v),
        );
    // Still planning the move: a task whose catalogue phase is after
    // arrival waits. It isn't proposed and asks nothing until you arrive
    // (AssessPerson, cf1b1caa). The mock holds back whole tasks; each of
    // its after-arrival tasks starts after arrival.
    const afterArrivalPhases = ['first_30_days', 'settling', 'ongoing'];
    const waits = (facts, t) =>
        said(facts, 'arrival_planned') === true &&
        afterArrivalPhases.includes(t.phase);
    // Which sample tasks your answers open (processes are proposals until
    // you start tracking them).
    const opened = (facts) =>
        tasks
            .filter((t) => applies(facts, t) && !waits(facts, t))
            .map((t) => t.id);
    // The tasks that apply but wait for your arrival: listed under "After
    // you arrive", never as to-dos.
    const afterArrival = (facts) =>
        tasks
            .filter((t) => applies(facts, t) && waits(facts, t))
            .map((t) => t.id);
    // Every question that concerns this person now, in the order it would be
    // asked: orientation first, then what a still-possible task needs, in
    // QuestionProtocol's order (7ffc1179 on the backend branch).
    function questions(facts, deferred = []) {
        const keys = orientation
            .filter(([, when]) => when(facts))
            .map(([key]) => key);
        // Task questions come once orientation is through (a question left
        // for later doesn't hold the rest up).
        const oriented = keys.every(
            (k) => answered(facts, k) || deferred.includes(k),
        );
        if (!oriented) return keys;
        // Each question a still-possible task needs, as QuestionProtocol
        // ranks them: the most urgent work first; within one urgency, a
        // question for work that already applies (only that answer is
        // missing) before one that only decides whether other routes apply;
        // then the question that more tasks wait on, the registry's
        // priority, and the key. A first question is asked only under its
        // own condition (a non-EU person still planning the move isn't asked
        // for a German permit), even when a task would need it.
        const first = orientation.map(([key]) => key);
        const ranked = new Map();
        const add = (key, priority, relevant, t) => {
            if (first.includes(key)) return;
            const e = ranked.get(key) || {
                key,
                priority,
                relevant: false,
                tasks: new Set(),
            };
            e.priority = Math.max(e.priority, priority);
            e.relevant ||= relevant;
            e.tasks.add(t.id);
            ranked.set(key, e);
        };
        for (const t of tasks)
            if (possible(facts, t) && !waits(facts, t)) {
                for (const k of Object.keys(t.when))
                    add(
                        k,
                        urgency[t.urgency],
                        Object.entries(t.when).every(
                            ([other, v]) =>
                                other === k || meets(said(facts, other), v),
                        ),
                        t,
                    );
                if (applies(facts, t))
                    for (const k of t.dates)
                        add(k, urgency[t.urgency] + anchorBonus, true, t);
            }
        for (const [key, settled] of notAsked)
            if (settled(facts)) ranked.delete(key);
        const rest = [...ranked.values()].sort(
            (a, b) =>
                b.priority - a.priority ||
                b.relevant - a.relevant ||
                b.tasks.size - a.tasks.size ||
                field(b.key).priority - field(a.key).priority ||
                a.key.localeCompare(b.key),
        );
        return [...keys, ...rest.map((e) => e.key)];
    }
    // The tasks a question is for: those it can still open (process_ids
    // carry the backend's process definition ids).
    const processIds = (facts, key) =>
        tasks
            .filter(
                (t) =>
                    possible(facts, t) &&
                    !waits(facts, t) &&
                    (key in t.when ||
                        (applies(facts, t) && t.dates.includes(key))),
            )
            .map((t) => t.definition);
    const api = {
        fields,
        field,
        tasks,
        questions,
        opened,
        afterArrival,
        processIds,
        said,
    };
    root.BureaucracyRegistry = api;
    if (typeof module !== 'undefined') module.exports = api;
})(globalThis);
