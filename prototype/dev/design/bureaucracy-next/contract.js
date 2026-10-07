/* The v2 plan contract (docs/contracts/bureaucracy-v2.md, schema
   bureaucracy.plan.1) projected from the local sample records in plan.js.
   Paperwork reads only this shape, as it will read the API; the rules here
   follow the backend's read model (ProcessDetails, PlanReadModel,
   PlanAttention, BuildTimeline). Sample data only: nothing is live. */
(function (root) {
    const B = root.BureaucracyPreview,
        P = root.BureaucracyPlan;
    const R = () => root.BureaucracySource || [];
    const ZONE = 'Europe/Berlin',
        JURISDICTION = 'de-nrw-cologne';
    // process-map.yaml: each sample task's process definition and topic.
    const definitions = {
        'blue-card': { id: 'residence.blue_card.first', topic: 'residence' },
        address: { id: 'address.registration', topic: 'address' },
        health: { id: 'health.coverage', topic: 'health' },
        bank: { id: 'banking.account', topic: 'money' },
        'tax-history': { id: 'tax.identification', topic: 'tax' },
    };
    // Each reviewed unit's process definition (process-map.yaml).
    const unitDefinitions = {
        'case.bc.first_application.prepare': 'residence.blue_card.first',
        'case.bc.first_application.submit': 'residence.blue_card.first',
        'core.anmeldung': 'address.registration',
        'core.health_insurance': 'health.coverage',
        'core.bank_account': 'banking.account',
        'core.steuer_id': 'tax.identification',
    };
    // Catalogue step kinds (process-map.yaml): an "action" step is the
    // submission, so its completion event is submission_recorded.
    const actionSteps = ['case.bc.first_application.submit'];
    // config('bureaucracy_catalogue.topic_labels'): plain navigation copy,
    // not reviewed yet; the owner can rename them.
    const topicLabels = {
        address: 'Address',
        residence: 'Residence',
        tax: 'Tax',
        health: 'Health insurance',
        money: 'Money',
        family: 'Family',
        work: 'Work',
        education: 'Education',
        driving: 'Driving',
    };
    // Requirements whose reviewed label makes them conditional ("where
    // required", "where applicable", "if available"). Applicability is
    // data here, as in the backend, not read from the wording by the UI.
    const conditional = {
        'case.bc.first_application.prepare': [2, 4],
        'core.bank_account': [2],
    };
    // The registry's wording for the one sample question (facts.yaml).
    const visaQuestion = {
        fact_key: 'visa_expires_at',
        kind: 'answer',
        question: 'When does your entry visa expire?',
        why: 'We use this date to calculate your residence-application deadline.',
        answer_schema: { type: 'date', date_semantics: 'expiry' },
        can_skip: true,
        can_answer_unknown: true,
    };
    // "waiting" in the local records is the backend's waiting_authority.
    const wire = (workflow) =>
        workflow === 'waiting' ? 'waiting_authority' : workflow;
    function offset(date) {
        const part = new Intl.DateTimeFormat('en-GB', {
            timeZone: ZONE,
            timeZoneName: 'shortOffset',
        })
            .formatToParts(new Date(`${date}T12:00:00Z`))
            .find((p) => p.type === 'timeZoneName').value;
        const m = part.match(/GMT([+-])(\d{1,2})(?::(\d{2}))?/);
        return m ? `${m[1]}${m[2].padStart(2, '0')}:${m[3] || '00'}` : '+00:00';
    }
    const now = () => {
        const day = root.ExpaduSampleDay;
        return day
            ? `${day.date}T${day.time}:00${offset(day.date)}`
            : new Date().toISOString();
    };
    // Calendar days from today in the jurisdiction's time zone.
    const daysFrom = (date) =>
        Math.round(
            (Date.parse(`${date}T12:00:00Z`) -
                Date.parse(`${P.today()}T12:00:00Z`)) /
                864e5,
        );
    const readiness = (state) => (state === 'unknown' ? 'missing' : state);
    function requirementsOf(p) {
        const started = p.started;
        let index = 0;
        return p.records.flatMap((n) => {
            const r = R()[n];
            return (r?.documents || []).map((label, j) => {
                const row = {
                    id: `${r.key}.document.${j + 1}`,
                    process_id: started ? p.id : null,
                    occurrence_key: p.id,
                    source_rule_id: r.key,
                    step_id: `${r.key}.complete`,
                    label,
                    readiness: readiness(p.docs[index] || 'unknown'),
                    applicability: conditional[r.key]?.includes(j)
                        ? 'conditional'
                        : 'required',
                    // The local record's position, for this preview's
                    // document actions.
                    index,
                };
                index += 1;
                return row;
            });
        });
    }
    function timelineOf(s, ps) {
        const rows = [];
        if (B.validDate(s.expiry))
            rows.push({
                id: 'visa.expiry',
                kind: 'document_expiry',
                date: s.expiry,
                precision: 'calendar_date',
                timezone: ZONE,
                state: 'dated',
                overdue: daysFrom(s.expiry) < 0,
                provenance: 'confirmed_fact',
                legal_effect: 'not_assessed',
                fact_key: 'visa_expires_at',
                document: 'visa',
                process_id: null,
                occurrence_key: null,
                step_id: null,
                action_id: null,
            });
        for (const p of ps.filter((p) => p.started)) {
            const base = {
                process_id: p.id,
                occurrence_key: p.id,
                timezone: ZONE,
                workflow: wire(p.workflow),
            };
            // Every dated step row is a preparation_target: the backend has
            // no legal_due policy any more (process-map.yaml has no
            // temporal_policy since f761736d). Copy reads "Aim to … by".
            if (p.id === 'blue-card') {
                const known = B.validDate(s.expiry);
                // Both Blue Card steps take their date from the visa expiry
                // (deadline_type fact_date on visa_expires_at).
                [
                    ['case.bc.first_application.prepare', 0],
                    ['case.bc.first_application.submit', 1],
                ].forEach(([unit, i]) =>
                    rows.push({
                        ...base,
                        id: `${p.id}:${unit}.due`,
                        kind: 'preparation_target',
                        source_rule_id: unit,
                        date: known ? s.expiry : null,
                        precision: 'calendar_date',
                        state: known ? 'dated' : 'date_unknown',
                        needed_fact: known ? null : 'visa_expires_at',
                        overdue: known && daysFrom(s.expiry) < 0,
                        conditional: true,
                        anchor_fact: 'visa_expires_at',
                        anchor_event_id: known ? 'visa.expiry' : null,
                        step_id: `${unit}.complete`,
                        action_id: `${p.id}:${i}`,
                    }),
                );
            }
            if (p.id === 'address') {
                const d = P.registrationDeadline;
                rows.push({
                    ...base,
                    id: `${p.id}:core.anmeldung.due`,
                    kind: 'preparation_target',
                    source_rule_id: 'core.anmeldung',
                    date: null,
                    precision: 'calendar_date',
                    state: 'date_unknown',
                    needed_fact: d.fact,
                    overdue: false,
                    conditional: true,
                    step_id: 'core.anmeldung.complete',
                    action_id: `${p.id}:0`,
                    // Fixture only: in the real plan this wording is in the
                    // card's reviewed guidance ("a two-week period under
                    // §17 BMG"), not on the row.
                    target: d.rule,
                });
            }
            const a = p.appointment;
            if (a && B.validDate(a.date)) {
                const routable =
                    Number.isFinite(a.lat) && Number.isFinite(a.lng);
                const id = a.id || `${p.id}-appointment`;
                rows.push({
                    ...base,
                    id: `${p.id}:appointment:${id}`,
                    kind: 'appointment',
                    date: a.date,
                    starts_at: `${a.date}T${a.time}:00${offset(a.date)}`,
                    duration_minutes: Number.isInteger(a.duration)
                        ? a.duration
                        : null,
                    location: a.location
                        ? routable
                            ? { lat: a.lat, lng: a.lng, label: a.location }
                            : { label: a.location }
                        : null,
                    routable,
                    state: 'recorded',
                    legal_effect: 'not_assessed',
                    appointment_id: id,
                    event_id: id,
                    revision_event_id: id,
                    step_id: null,
                    action_id: null,
                });
            }
            const submitted = P.activeSubmission(s, p.id);
            if (p.submission) {
                const event = submitted?.id || `${p.id}-recorded-submission`;
                rows.push({
                    ...base,
                    id: `${p.id}:submission:${event}`,
                    kind: 'submission_recorded',
                    date: p.submission,
                    state: 'recorded',
                    event_id: event,
                    revision_event_id: event,
                    step_id: null,
                    action_id: null,
                });
            }
        }
        return rows;
    }
    const stepKinds = [
        'legal_due',
        'preparation_target',
        'authority_follow_up',
    ];
    function processOf(s, p, timeline, requirements, local, hidden) {
        const definition = definitions[p.id];
        const steps = p.records.flatMap((n, i) => {
            if (hidden?.has(R()[n]?.key)) return [];
            const r = R()[n];
            const id = `${p.id}:${i}`;
            const rows = requirements.filter((x) => x.source_rule_id === r.key);
            const open = rows.find((x) => x.readiness !== 'confirmed_for_use');
            const action = local.find((a) => a.id === id);
            return {
                id,
                step_id: `${r.key}.complete`,
                guidance_id: r.key,
                title: r.title,
                description: r.description,
                // The backend's step status: blocked only for an unfinished
                // prerequisite (none here), not for a reported "blocked".
                status:
                    p.workflow === 'cancelled'
                        ? 'cancelled'
                        : action?.status === 'completed'
                          ? 'completed'
                          : ['submitted', 'waiting'].includes(p.workflow)
                            ? 'waiting'
                            : 'todo',
                position: i + 1,
                depends_on: [],
                verified_at: r.verifiedAt,
                review_due_at: null,
                content_version: r.version,
                sources: {
                    official: [
                        {
                            id: `${r.key}.action.1`,
                            url: r.source,
                            purpose: 'information',
                        },
                    ],
                    legal:
                        r.law && r.law !== r.source
                            ? [{ kind: 'primary', label: null, url: r.law }]
                            : [],
                },
                requirements: {
                    ready: rows.filter(
                        (x) => x.readiness === 'confirmed_for_use',
                    ).length,
                    total: rows.length,
                },
                first_open_requirement: open
                    ? {
                          id: open.id,
                          label: open.label,
                          readiness: open.readiness,
                          applicability: open.applicability,
                          conditional: open.applicability !== 'required',
                          index: open.index,
                      }
                    : null,
                dates: timeline.filter((row) => row.action_id === id),
                completion_event: actionSteps.includes(r.key)
                    ? 'submission_recorded'
                    : 'step_completed',
            };
        });
        const workflow = wire(p.workflow);
        const closed = ['completed', 'cancelled'].includes(workflow);
        const report = (p.reports || []).findLast(
            (r) => !r.withdrawn && r.event !== 'step',
        );
        // From the person's own paperwork only, never a legal reason.
        let blocking = null;
        if (['blocked', 'action_required'].includes(workflow))
            for (const step of steps) {
                const row = requirements.find(
                    (x) =>
                        x.source_rule_id === step.guidance_id &&
                        ['missing', 'needs_reconfirmation'].includes(
                            x.readiness,
                        ),
                );
                if (row) {
                    blocking = {
                        basis: 'requirement_readiness',
                        step_id: step.step_id,
                        requirement_id: row.id,
                        label: row.label,
                        readiness: row.readiness,
                        applicability: row.applicability,
                    };
                    break;
                }
            }
        return {
            id: p.started ? p.id : null,
            occurrence_key: p.id,
            definition_id: definition.id,
            version: p.started ? (p.reports || []).length + 1 : 0,
            title: steps[0]?.title || null,
            topic: definition.topic,
            topic_label: topicLabels[definition.topic],
            state: {
                workflow,
                report: report
                    ? {
                          event: report.event,
                          occurred_on: report.occurred_on || null,
                          note: report.note || null,
                      }
                    : null,
            },
            is_closed: closed,
            closed_on: closed ? p.closedOn || null : null,
            closed_event_id: closed ? report?.id || null : null,
            blocking_reason: blocking,
            steps,
            progress: {
                completed: steps.filter((x) => x.status === 'completed').length,
                total: steps.length,
            },
        };
    }
    function attentionOf(timeline, processes) {
        const open = (row) => {
            const p = processes.find(
                (x) => x.occurrence_key === row.occurrence_key,
            );
            return (
                p &&
                !p.is_closed &&
                p.steps.find((x) => x.id === row.action_id)?.status !==
                    'completed'
            );
        };
        const rows = [];
        for (const row of timeline) {
            const appointment = row.kind === 'appointment';
            if (
                !appointment &&
                row.kind !== 'document_expiry' &&
                !stepKinds.includes(row.kind)
            )
                continue;
            if (stepKinds.includes(row.kind) && !open(row)) continue;
            if (
                stepKinds.includes(row.kind) &&
                row.state === 'date_unknown' &&
                row.needed_fact
            ) {
                rows.push({
                    ...row,
                    date: null,
                    days_remaining: null,
                    urgency: 'date_unknown',
                    label: null,
                });
                continue;
            }
            if (!row.date || !['dated', 'recorded'].includes(row.state))
                continue;
            if (appointment && row.starts_at <= now()) continue;
            const days = daysFrom(row.date);
            if (days > (appointment ? 7 : 14)) continue;
            rows.push({
                ...row,
                days_remaining: days,
                urgency: appointment
                    ? days === 0
                        ? 'appointment_today'
                        : days === 1
                          ? 'appointment_tomorrow'
                          : 'appointment_soon'
                    : days < 0
                      ? 'overdue'
                      : days <= 3
                        ? 'critical'
                        : days <= 7
                          ? 'urgent'
                          : 'upcoming',
            });
        }
        return rows.sort(
            (a, b) =>
                (a.days_remaining ?? Infinity) -
                    (b.days_remaining ?? Infinity) || a.id.localeCompare(b.id),
        );
    }
    function questionsOf(s) {
        const deferred = s.questionSkipped ? ['visa_expires_at'] : [];
        const asking =
            s.expiry === null && !s.expiryUnknown && !s.questionSkipped;
        return {
            status: asking ? 'preview' : 'no_more_questions',
            question: asking
                ? { ...visaQuestion, process_ids: ['blue-card'] }
                : null,
            remaining_information_count: asking ? 1 : 0,
            entry_state: deferred.length ? 'in_progress' : 'none',
            deferred,
            candidates_count: asking || deferred.length ? 1 : 0,
        };
    }
    function coverageOf() {
        return {
            state: 'partial',
            units: R()
                .map((r) =>
                    // A card the daily source check hasn't confirmed shows
                    // only its title and official pages (bureaucracy-v2.md,
                    // "Automatically checked cards").
                    r.state === 'unconfirmed'
                        ? {
                              definition_id: unitDefinitions[r.key] || null,
                              unit_id: r.key,
                              title: r.title,
                              content_version: null,
                              verified_at: null,
                              review_due_at: null,
                              source_urls: {
                                  official: [],
                                  legal: [r.law || r.source].filter(Boolean),
                              },
                              state: 'unconfirmed',
                          }
                        : {
                              definition_id: unitDefinitions[r.key] || null,
                              unit_id: r.key,
                              title: r.title,
                              content_version: r.version,
                              verified_at: r.verifiedAt,
                              review_due_at: null,
                              source_urls: {
                                  official: [r.source],
                                  legal:
                                      r.law && r.law !== r.source
                                          ? [r.law]
                                          : [],
                              },
                              // The sample catalogue is preparation guidance
                              // only.
                              state: 'partial',
                          },
                )
                .sort((a, b) => a.unit_id.localeCompare(b.unit_id)),
        };
    }
    const empty = (s) => ({
        schema_version: 'bureaucracy.plan.1',
        person_id: s.person,
        jurisdiction: JURISDICTION,
        evaluated_at: now(),
        overview: {
            next_actions: [],
            remaining_action_count: 0,
            question: null,
        },
        actions: [],
        processes: [],
        history: [],
        history_count: 0,
        progress: {
            total: 0,
            todo: { count: 0, ids: [] },
            blocked: { count: 0, ids: [] },
            waiting: { count: 0, ids: [] },
            completed: { count: 0, ids: [] },
        },
        timeline: [],
        attention: [],
        coming_up: [],
        questions: {
            status: 'permission_required',
            question: null,
            entry_state: null,
            deferred: [],
            candidates_count: null,
        },
        coverage: coverageOf(),
        paperwork: { available: false, reason: 'permission_required' },
    });
    // GET /people/{person}/plan, for the selected person.
    function plan(s) {
        if (!B.canEdit(s)) return empty(s);
        P.hydrate(s);
        const local = P.projection(s).actions;
        const all = P.processes(s);
        // Unconfirmed cards never show their steps, documents or dates.
        const hidden = new Set(
            R()
                .filter((r) => r.state === 'unconfirmed')
                .map((r) => r.key),
        );
        const shown = (row) => !hidden.has(row.source_rule_id);
        const requirements = all.flatMap(requirementsOf).filter(shown);
        const timeline = timelineOf(s, all).filter(shown);
        const processes = all
            .map((p) =>
                processOf(
                    s,
                    p,
                    timeline,
                    requirements.filter((r) => r.occurrence_key === p.id),
                    local,
                    hidden,
                ),
            )
            .filter((p) => p.steps.length);
        const steps = processes.flatMap((p) =>
            p.steps.map((step) => ({ ...step, process: p })),
        );
        const bucket = (status) => {
            const ids = steps
                .filter((x) => x.status === status)
                .map((x) => x.id);
            return { count: ids.length, ids };
        };
        const progress = {
            total: steps.filter((x) => x.status !== 'cancelled').length,
            todo: bucket('todo'),
            blocked: bucket('blocked'),
            waiting: bucket('waiting'),
            completed: bucket('completed'),
        };
        const actions = steps
            .filter((x) => x.status === 'todo' && !x.process.is_closed)
            .map((x) => ({
                id: x.id,
                type: 'open_process',
                person_id: s.person,
                process_id: x.process.id,
                occurrence_key: x.process.occurrence_key,
                step_id: x.step_id,
                title: x.title,
                process_title: x.process.title,
                source_rule_id: x.guidance_id,
                dates: x.dates.filter((d) => stepKinds.includes(d.kind)),
                requires_process_start: x.process.id === null,
            }))
            // Earliest step date first; undated keep their order.
            .map((a, i) => ({ a, i }))
            .sort(
                (x, y) =>
                    (x.a.dates[0]?.date || '9999-12-31').localeCompare(
                        y.a.dates[0]?.date || '9999-12-31',
                    ) || x.i - y.i,
            )
            .map(({ a }) => a);
        const history = s.history.map((h) => {
            const r = R()[h.record];
            return {
                id: h.id,
                occurrence_key: h.id,
                definition_id: definitions[h.id]?.id || null,
                title: r?.title || null,
                topic_label: topicLabels[definitions[h.id]?.topic] || null,
                state: { workflow: h.workflow, report: null },
                is_closed: true,
                closed_on: h.closedOn || null,
                closed_event_id: null,
                steps: [],
            };
        });
        const attention = attentionOf(timeline, processes);
        const questions = questionsOf(s);
        return {
            schema_version: 'bureaucracy.plan.1',
            person_id: s.person,
            jurisdiction: JURISDICTION,
            evaluated_at: now(),
            overview: {
                next_actions: actions.slice(0, 3),
                remaining_action_count: Math.max(0, actions.length - 3),
                question: questions.question,
            },
            actions,
            processes,
            history,
            history_count:
                history.length + processes.filter((p) => p.is_closed).length,
            progress,
            timeline,
            attention,
            coming_up: attention.filter(
                (row) =>
                    row.days_remaining !== null &&
                    row.days_remaining >= 0 &&
                    row.days_remaining <= 7,
            ),
            questions,
            coverage: coverageOf(),
            paperwork: {
                available: true,
                requirements,
                evidence: [],
                capabilities: {
                    upload: { available: false, reason: 'not_implemented' },
                    translation: {
                        available: false,
                        reason: 'not_implemented',
                    },
                },
            },
        };
    }
    // GET /people/{person}/facts, for the one sample fact this page asks.
    function facts(s) {
        const known = B.validDate(s.expiry);
        return {
            values: { visa_expires_at: known ? s.expiry : null },
            states: {
                visa_expires_at: known
                    ? 'value'
                    : s.expiryUnknown
                      ? 'unknown'
                      : null,
            },
        };
    }
    // The account holder's own records as Today and Composer show them: the
    // ready count, the visa date a deadline is anchored to and whether a
    // submission is recorded, all read from the plan Paperwork keeps (its
    // saved sample state). Each task's short copy comes from the shared
    // sample day. A closed or untracked task drops out.
    function records() {
        const fixtures = root.ExpaduSampleDay?.records || [];
        let stored = null;
        try {
            stored = JSON.parse(
                root.localStorage?.getItem('expadu-bureaucracy-design-v1'),
            );
        } catch {
            /* The sample state below still applies. */
        }
        const s = P.hydrate(
            stored && Array.isArray(stored.docs)
                ? { ...B.fresh(), ...stored, person: 'self' }
                : B.fresh(),
        );
        const p = plan(s);
        return fixtures
            .map((record) => {
                const c = p.processes.find(
                    (x) => x.occurrence_key === record.id,
                );
                if (!c || c.id === null || c.is_closed) return null;
                const rows = p.timeline.filter(
                    (t) => t.occurrence_key === record.id,
                );
                const due = rows.find(
                    (t) => stepKinds.includes(t.kind) && t.anchor_event_id,
                );
                const expiry =
                    due && p.timeline.find((t) => t.id === due.anchor_event_id);
                return {
                    ...record,
                    prepared: c.steps.reduce(
                        (n, x) => n + (x.requirements?.ready || 0),
                        0,
                    ),
                    total: c.steps.reduce(
                        (n, x) => n + (x.requirements?.total || 0),
                        0,
                    ),
                    deadline: expiry?.date || null,
                    submitted: rows.some(
                        (t) => t.kind === 'submission_recorded',
                    ),
                };
            })
            .filter(Boolean);
    }
    root.BureaucracyContract = { plan, facts, records, topicLabels };
})(globalThis);
