/* Local interaction fixtures only. Production projection comes from the v2 plan API. */
(function (root) {
    const B = root.BureaucracyPreview;
    const definitions = [
        {
            id: 'address',
            name: 'Address registration',
            record: 2,
            icon: 'pin',
            tone: 'peach',
            initial: 'blocked',
            note: 'A document is not ready',
            docs: ['reported_available', 'missing'],
        },
        {
            id: 'health',
            name: 'Health insurance',
            record: 3,
            icon: 'health',
            tone: 'green',
            initial: 'waiting',
            note: 'Response pending',
            docs: ['reported_available', 'missing'],
        },
        {
            id: 'bank',
            name: 'Bank account',
            record: 4,
            icon: 'bank',
            tone: 'blue',
            initial: 'not_started',
            note: '',
            docs: ['reported_available', 'missing', 'unknown'],
        },
    ];
    function hydrate(s) {
        s.view = s.view || 'overview';
        s.actionFilter = s.actionFilter || 'all';
        s.paperFilter = s.paperFilter || 'all';
        s.workflow = s.workflow || 'preparing';
        s.processNote = s.processNote || '';
        s.records = s.records || {};
        for (const d of definitions.filter(() => s.profileKind !== 'personal'))
            if (!s.records[d.id])
                s.records[d.id] = {
                    ...JSON.parse(JSON.stringify(d)),
                    workflow: d.initial,
                    started: d.initial !== 'not_started',
                    appointment: null,
                    submission: null,
                    done: false,
                    closedOn: null,
                };
        for (const d of definitions)
            if (s.records[d.id])
                s.records[d.id].docs = s.records[d.id].docs.slice(
                    0,
                    d.docs.length,
                );
        s.history = s.history || [
            {
                id: 'tax-history',
                name: 'Tax identification number',
                record: 5,
                icon: 'document',
                workflow: 'completed',
                closedOn: '2026-08-20',
                note: 'Sample completion record',
            },
        ];
        s.custom = s.custom || [];
        if (s.otherProcesses?.length) {
            for (const name of s.otherProcesses)
                s.custom.push({ id: 'request-' + s.custom.length, name });
            s.otherProcesses = [];
        }
        return s;
    }
    const statuses = {
        todo: 'To do',
        blocked: 'Blocked',
        waiting: 'Waiting',
        completed: 'Done',
    };
    function processes(s) {
        if (!B.canEdit(s)) return [];
        hydrate(s);
        return [
            {
                id: 'blue-card',
                name: 'EU Blue Card',
                icon: 'document',
                tone: 'cyan',
                workflow: s.workflow,
                started:
                    s.profileKind !== 'personal' ||
                    s.workflow !== 'not_started',
                note: s.processNote,
                docs: s.docs,
                appointment: s.appointment,
                submission: s.submission,
                records: [0, 1],
                closedOn: s.closedOn,
            },
            ...definitions
                .filter((d) => s.records[d.id])
                .map((d) => ({
                    ...s.records[d.id],
                    records: [d.record],
                })),
        ].filter(
            (p) => s.profileKind !== 'personal' || s.tracked?.includes(p.id),
        );
    }
    function include(s, id) {
        if (
            !B.canEdit(s) ||
            s.profileKind !== 'personal' ||
            !['blue-card', ...definitions.map((d) => d.id)].includes(id)
        )
            return false;
        hydrate(s);
        s.tracked ||= [];
        if (s.tracked.includes(id)) return false;
        s.tracked.push(id);
        const d = definitions.find((d) => d.id === id);
        if (d)
            s.records[id] = {
                ...JSON.parse(JSON.stringify(d)),
                docs: d.docs.map(() => 'unknown'),
                workflow: 'not_started',
                started: false,
                note: '',
                appointment: null,
                submission: null,
                done: false,
                closedOn: null,
            };
        return true;
    }
    const terminal = (p) => ['completed', 'cancelled'].includes(p.workflow);
    function process(s, id) {
        return processes(s).find((p) => p.id === id);
    }
    function actions(s) {
        return processes(s)
            .filter((p) => p.workflow !== 'cancelled')
            .flatMap((p) =>
                p.records.map((record, i) => ({
                    id: p.id + ':' + i,
                    process: p.id,
                    name: p.name,
                    record,
                    icon: p.icon,
                    tone: p.tone,
                    proposal: !p.started,
                    status:
                        p.workflow === 'completed'
                            ? 'completed'
                            : p.id === 'blue-card' &&
                                ((i === 0 && s.prepared) ||
                                    (i === 1 && s.submission))
                              ? 'completed'
                              : p.id !== 'blue-card' && s.records[p.id].done
                                ? 'completed'
                                : p.workflow === 'blocked'
                                  ? 'blocked'
                                  : ['waiting', 'submitted'].includes(
                                          p.workflow,
                                      )
                                    ? 'waiting'
                                    : 'todo',
                })),
            );
    }
    function projection(s) {
        if (!B.canEdit(s))
            return {
                processes: [],
                actions: [],
                next: [],
                counts: { todo: 0, blocked: 0, waiting: 0, completed: 0 },
                remaining: 0,
                history: [],
                total: 0,
            };
        hydrate(s);
        const ps = processes(s),
            all = actions(s),
            counts = Object.fromEntries(
                Object.keys(statuses).map((k) => [
                    k,
                    all.filter((a) => a.status === k).length,
                ]),
            );
        const candidates = all.filter((a) => a.status === 'todo');
        const priorities = [];
        for (const a of candidates)
            if (!priorities.some((x) => x.process === a.process))
                priorities.push(a);
        for (const a of candidates)
            if (!priorities.some((x) => x.id === a.id)) priorities.push(a);
        return {
            processes: ps.filter((p) => !terminal(p)),
            actions: all,
            next: priorities.slice(0, 3),
            counts,
            remaining: Math.max(0, all.length - priorities.slice(0, 3).length),
            history: [...s.history, ...ps.filter(terminal)],
            total: all.length,
        };
    }
    function updateWorkflow(s, id, value, note = '', closedOn = null) {
        const p = process(s, id);
        if (
            !p ||
            !p.started ||
            ![
                'preparing',
                'blocked',
                'waiting',
                'completed',
                'cancelled',
            ].includes(value)
        )
            return false;
        if (
            ['completed', 'cancelled'].includes(value) &&
            !B.validDate(closedOn)
        )
            return false;
        if (id === 'blue-card') {
            s.workflow = value;
            s.processNote = note;
            s.closedOn = closedOn;
        } else
            Object.assign(s.records[id], { workflow: value, note, closedOn });
        return true;
    }
    function start(s, id) {
        const p = process(s, id);
        if (!p || p.started) return false;
        if (id === 'blue-card') {
            s.workflow = 'preparing';
            return true;
        }
        s.records[id].started = true;
        s.records[id].workflow = 'preparing';
        return true;
    }
    function documents(s, id) {
        const p = process(s, id);
        return p?.docs || [];
    }
    // Navigation through the existing checklist only; this is not an assessment.
    function guide(s, stepId, sources) {
        const action = actions(s).find((a) => a.id === stepId);
        if (!action) return null;
        const p = process(s, action.process);
        const labels = p.records.flatMap((n) => sources[n]?.documents || []);
        const rows = labels.map((label, index) => ({
            label,
            index,
            state: p.docs[index] || 'unknown',
        }));
        const document =
            [
                'missing',
                'unknown',
                'needs_reconfirmation',
                'reported_available',
            ].flatMap((status) => rows.filter((d) => d.state === status))[0] ||
            null;
        return {
            primary: (sources[action.record]?.documents || []).length
                ? 'documents'
                : 'source',
            process: p.id,
            status: action.status,
            document,
            ready: rows.filter((d) => d.state === 'confirmed_for_use').length,
            total: rows.length,
        };
    }
    function documentAction(s, id, index, action) {
        const p = process(s, id);
        if (!p?.started || terminal(p)) return false;
        if (id === 'blue-card') return B.documentAction(s, index, action);
        const docs = s.records[id].docs;
        if (!Number.isInteger(index) || index < 0 || index >= docs.length)
            return false;
        if (action === 'have') docs[index] = 'reported_available';
        else if (
            action === 'confirm' &&
            ['reported_available', 'needs_reconfirmation'].includes(docs[index])
        )
            docs[index] = 'confirmed_for_use';
        else if (action === 'missing') docs[index] = 'missing';
        else return false;
        return true;
    }
    function recordAppointment(s, id, appointment) {
        const p = process(s, id);
        if (
            !p?.started ||
            terminal(p) ||
            !B.validDate(appointment.date) ||
            !/^([01]\d|2[0-3]):[0-5]\d$/.test(appointment.time)
        )
            return false;
        if (id === 'blue-card') s.appointment = appointment;
        else s.records[id].appointment = appointment;
        return true;
    }
    function appointmentForComposer(s, id) {
        const p = process(s, id);
        if (!p?.started || terminal(p)) return null;
        const item = B.appointmentForComposer({
            ...s,
            appointment: p.appointment,
        });
        return item
            ? {
                  ...item,
                  id:
                      id === 'blue-card'
                          ? item.id
                          : 'bureaucracy-sample-appointment-' + id,
              }
            : null;
    }
    function timeline(s) {
        return processes(s)
            .filter((p) => !terminal(p))
            .flatMap((p) => [
                ...(p.id === 'blue-card'
                    ? [
                          {
                              id: 'expiry',
                              process: p.id,
                              name: p.name,
                              kind: 'Document expiry',
                              date: s.expiry,
                              icon: 'document',
                          },
                      ]
                    : []),
                ...(p.appointment
                    ? [
                          {
                              id: 'appointment-' + p.id,
                              process: p.id,
                              name: p.name,
                              kind: 'Appointment',
                              ...p.appointment,
                              icon: 'calendar',
                          },
                      ]
                    : []),
                ...(p.submission
                    ? [
                          {
                              id: 'submission-' + p.id,
                              process: p.id,
                              name: p.name,
                              kind: 'Submission recorded',
                              date: p.submission,
                              icon: 'check',
                          },
                      ]
                    : []),
            ])
            .sort((a, b) => (a.date || '9999').localeCompare(b.date || '9999'));
    }
    root.BureaucracyPlan = {
        hydrate,
        include,
        definitions,
        statuses,
        processes,
        process,
        projection,
        updateWorkflow,
        start,
        documents,
        guide,
        documentAction,
        recordAppointment,
        appointmentForComposer,
        timeline,
        terminal,
    };
})(globalThis);
