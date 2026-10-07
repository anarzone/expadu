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
                                    (i === 1 && s.submitDone))
                              ? 'completed'
                              : p.id !== 'blue-card' && s.records[p.id].done
                                ? 'completed'
                                : p.workflow === 'blocked'
                                  ? 'blocked'
                                  : // A submission waits for the office;
                                    // its step stays open until confirmed.
                                    ['waiting', 'submitted'].includes(
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
    // The backend's reported workflow (ProcessStateMachine): each target and
    // the states it may follow. "Waiting" needs a submission first, and
    // completion needs every step confirmed. Notes aren't stored.
    const transitions = {
        preparing: ['not_started', 'blocked', 'action_required'],
        submitted: [
            'not_started',
            'preparing',
            'submitted',
            'waiting',
            'action_required',
        ],
        waiting: ['submitted', 'waiting'],
        action_required: ['submitted', 'waiting', 'preparing'],
        completed: ['preparing', 'submitted', 'waiting', 'action_required'],
        cancelled: [
            'not_started',
            'preparing',
            'blocked',
            'submitted',
            'waiting',
            'action_required',
        ],
    };
    const dated = ['submitted', 'completed', 'cancelled'];
    function stepsDone(s, p) {
        return p.id === 'blue-card'
            ? !!(s.prepared && s.submitDone)
            : !!s.records[p.id]?.done;
    }
    // What "Update progress" may offer from where the task is now.
    function choices(s, id) {
        const p = process(s, id);
        if (!p?.started || terminal(p)) return [];
        return Object.keys(transitions)
            .filter(
                (value) =>
                    value !== p.workflow &&
                    transitions[value].includes(p.workflow),
            )
            .map((value) => ({
                value,
                allowed: value !== 'completed' || stepsDone(s, p),
            }));
    }
    function updateWorkflow(s, id, value, note = '', date = null) {
        const p = process(s, id);
        if (
            !p ||
            !p.started ||
            !transitions[value]?.includes(p.workflow) ||
            (value === 'completed' && !stepsDone(s, p)) ||
            (dated.includes(value) && !B.validDate(date))
        )
            return false;
        const closedOn = terminal({ workflow: value }) ? date : null;
        const submission = value === 'submitted' ? date : p.submission;
        if (id === 'blue-card') {
            s.workflow = value;
            s.processNote = '';
            s.closedOn = closedOn;
            s.submission = submission;
        } else
            Object.assign(s.records[id], {
                workflow: value,
                note: '',
                closedOn,
                submission,
            });
        return true;
    }
    // "I've submitted it" records the date and moves the task to submitted;
    // the step itself stays open. Removing it goes back to preparing.
    function recordSubmission(s, id, date) {
        return updateWorkflow(s, id, 'submitted', '', date);
    }
    function removeSubmission(s, id) {
        const p = process(s, id);
        if (!p?.started || terminal(p) || !p.submission) return false;
        const back = ['submitted', 'waiting'].includes(p.workflow)
            ? 'preparing'
            : p.workflow;
        if (id === 'blue-card') {
            s.submission = null;
            s.submitDone = false;
            s.workflow = back;
        } else
            Object.assign(s.records[id], { submission: null, workflow: back });
        return true;
    }
    // Finish or reopen one step; a blocked step can't be finished.
    function toggleStep(s, actionId) {
        const a = actions(s).find((x) => x.id === actionId);
        const p = a && process(s, a.process);
        if (!p?.started || terminal(p)) return false;
        if (a.status === 'blocked') return false;
        const finishing = a.status !== 'completed';
        if (p.id === 'blue-card') {
            if (actionId === 'blue-card:1') {
                if (finishing && !s.submission) return false;
                s.submitDone = finishing;
            } else s.prepared = finishing;
        } else s.records[p.id].done = finishing;
        // Like the backend, step work outside the office's hands is
        // preparation.
        if (!['submitted', 'waiting', 'action_required'].includes(p.workflow)) {
            if (p.id === 'blue-card') s.workflow = 'preparing';
            else s.records[p.id].workflow = 'preparing';
        }
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
    // The one legal deadline the backend declares (process-map.yaml,
    // legal_due): §17(1) BMG, register "innerhalb von zwei Wochen nach dem
    // Einzug". Its date needs the move-in date, which this sample doesn't
    // record, so it stays unknown; the client never calculates it.
    const registrationDeadline = {
        rule: 'Within two weeks of moving in',
        law: '§17(1) BMG',
        source: 'https://www.gesetze-im-internet.de/bmg/__17.html',
        verifiedAt: '2026-09-08',
        needs: 'your move-in date',
    };
    function timeline(s) {
        return processes(s)
            .filter((p) => !terminal(p))
            .flatMap((p) => [
                ...(p.id === 'address' && !stepsDone(s, p)
                    ? [
                          {
                              id: 'deadline-' + p.id,
                              process: p.id,
                              name: p.name,
                              kind: 'Legal deadline',
                              date: null,
                              icon: 'calendar',
                              legal: registrationDeadline,
                          },
                      ]
                    : []),
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
        choices,
        stepsDone,
        recordSubmission,
        removeSubmission,
        toggleStep,
        start,
        documents,
        guide,
        documentAction,
        recordAppointment,
        appointmentForComposer,
        timeline,
        terminal,
        registrationDeadline,
    };
})(globalThis);
