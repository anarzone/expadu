/* Local interaction fixtures only. Production projection comes from the v2 plan API. */
(function (root) {
    const B = root.BureaucracyPreview;
    // Sample records for the other tasks. Their reports are the person's
    // own, in order, as the backend keeps them (bureaucracy-v2.md, process
    // events); the workflow is replayed from them.
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
            reports: [
                { event: 'preparation_started' },
                { event: 'blocked_reported', note: 'A document is not ready' },
            ],
        },
        {
            id: 'health',
            name: 'Health insurance',
            record: 3,
            icon: 'health',
            tone: 'green',
            initial: 'waiting',
            note: 'Response pending',
            docs: ['reported_available'],
            // "Waiting" follows a recorded submission (sample date).
            reports: [
                { event: 'preparation_started' },
                { event: 'submission_recorded', occurred_on: '2026-09-24' },
                { event: 'waiting_reported', note: 'Response pending' },
            ],
        },
        {
            id: 'bank',
            name: 'Bank account',
            record: 4,
            icon: 'bank',
            tone: 'blue',
            initial: 'not_started',
            note: '',
            docs: ['reported_available', 'unknown'],
            reports: [],
        },
    ];
    const clone = (value) => JSON.parse(JSON.stringify(value));
    // Number each fixture report, so a submission has an event id.
    const seeded = (id, reports) =>
        reports.map((r, i) => ({ id: `${id}-report-${i + 1}`, ...clone(r) }));
    function hydrate(s) {
        s.view = s.view || 'overview';
        s.actionFilter = s.actionFilter || 'all';
        s.paperFilter = s.paperFilter || 'all';
        s.workflow = s.workflow || 'preparing';
        s.processNote = s.processNote || '';
        // The sample appointment saved before it had a length and a point.
        const sample = B.sampleAppointment?.();
        if (
            sample &&
            s.appointment &&
            !s.appointment.id &&
            ['date', 'time', 'location'].every(
                (k) => s.appointment[k] === sample[k],
            )
        )
            s.appointment = { ...sample };
        // Older saved state has no report log: replay starts from where it is.
        if (!Array.isArray(s.reports)) {
            s.base = s.workflow;
            s.reports = [];
        }
        s.records = s.records || {};
        for (const d of definitions.filter(() => s.profileKind !== 'personal'))
            if (!s.records[d.id]) {
                const { reports, ...rest } = clone(d);
                s.records[d.id] = {
                    ...rest,
                    workflow: d.initial,
                    started: d.initial !== 'not_started',
                    appointment: null,
                    submission: null,
                    done: false,
                    closedOn: null,
                    base: 'not_started',
                    reports: seeded(d.id, reports),
                };
                settle(s, d.id);
            }
        for (const d of definitions)
            if (s.records[d.id]) {
                const r = s.records[d.id];
                r.docs = r.docs.slice(0, d.docs.length);
                if (!Array.isArray(r.reports)) {
                    r.base = r.workflow;
                    r.reports = [];
                }
            }
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
        // There are no free-text tasks (owner decision, 8 Oct 2026): a
        // person starts only what their plan suggests. Older saved
        // free-text requests are no longer read.
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
                    !!s.blueCardStarted ||
                    s.workflow !== 'not_started',
                note: s.processNote,
                docs: s.docs,
                appointment: s.appointment,
                submission: s.submission,
                records: [0, 1],
                closedOn: s.closedOn,
                reports: s.reports,
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
        if (d) {
            const { reports, ...rest } = clone(d);
            s.records[id] = {
                ...rest,
                docs: d.docs.map(() => 'unknown'),
                workflow: 'not_started',
                started: false,
                note: '',
                appointment: null,
                submission: null,
                done: false,
                closedOn: null,
                base: 'not_started',
                reports: [],
            };
        }
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
    // The backend's process events (bureaucracy-v2.md, "Process events and
    // payloads"): the workflow each report may follow and where it leads.
    // "waiting" here is the backend's waiting_authority.
    const open = [
        'not_started',
        'preparing',
        'blocked',
        'submitted',
        'waiting',
        'action_required',
    ];
    const accepts = {
        preparation_started: [
            'not_started',
            'preparing',
            'blocked',
            'action_required',
        ],
        blocked_reported: ['not_started', 'preparing', 'blocked'],
        submission_recorded: [
            'not_started',
            'preparing',
            'blocked',
            'submitted',
            'waiting',
            'action_required',
        ],
        waiting_reported: ['submitted', 'waiting'],
        action_required_reported: ['preparing', 'submitted', 'waiting'],
        completion_reported: [
            'preparing',
            'submitted',
            'waiting',
            'action_required',
        ],
        cancellation_reported: open,
        process_reopened: ['completed', 'cancelled'],
    };
    const leadsTo = {
        preparation_started: 'preparing',
        blocked_reported: 'blocked',
        submission_recorded: 'submitted',
        waiting_reported: 'waiting',
        action_required_reported: 'action_required',
        completion_reported: 'completed',
        cancellation_reported: 'cancelled',
        process_reopened: 'preparing',
    };
    // Which events take a note (1–500 characters) and a date.
    const takesNote = [
        'preparation_started',
        'blocked_reported',
        'waiting_reported',
        'action_required_reported',
        'completion_reported',
        'cancellation_reported',
        'process_reopened',
        'submission_retracted',
    ];
    const takesDate = [
        'blocked_reported',
        'submission_recorded',
        'action_required_reported',
        'completion_reported',
        'cancellation_reported',
    ];
    // Replays the person's reports from where the record started. A report
    // that is only valid after a withdrawn submission (such as "waiting")
    // drops out with it, as in the backend.
    function replay(base, reports) {
        let workflow = base,
            report = null;
        for (const r of reports) {
            if (r.withdrawn) continue;
            if (r.event === 'step') {
                if (workflow === 'not_started') {
                    workflow = 'preparing';
                    report = null;
                }
                continue;
            }
            if (!accepts[r.event]?.includes(workflow)) continue;
            workflow = leadsTo[r.event];
            report = r;
        }
        return { workflow, report };
    }
    // Where a process keeps its record. The Blue Card's fields sit on the
    // state itself, as in older saved state.
    const holder = (s, id) => (id === 'blue-card' ? s : s.records[id]);
    const activeSubmission = (r) =>
        (r.reports || []).findLast(
            (x) => x.event === 'submission_recorded' && !x.withdrawn,
        ) || null;
    // Writes the replayed workflow back onto the record, for the pages that
    // read it directly.
    function settle(s, id) {
        const r = holder(s, id);
        const { workflow, report } = replay(r.base, r.reports);
        const submission = activeSubmission(r);
        r.workflow = workflow;
        r.closedOn = ['completed', 'cancelled'].includes(workflow)
            ? report?.occurred_on || null
            : null;
        // Older records keep their submission date without a report.
        if (r.reports.some((x) => x.event === 'submission_recorded'))
            r.submission = submission?.occurred_on || null;
        if (id === 'blue-card') s.processNote = report?.note || '';
        else r.note = report?.note || '';
    }
    let reportCount = 0;
    const reportId = (id) =>
        `${id}-${Date.now().toString(36)}-${(reportCount += 1)}`;
    const today = () =>
        root.ExpaduSampleDay?.date || new Date().toLocaleDateString('en-CA');
    const workflowLabel = {
        not_started: 'Not started',
        preparing: 'Preparing',
        blocked: 'Blocked',
        submitted: 'Submitted',
        waiting: 'Waiting for the office',
        action_required: 'Needs your action',
        completed: 'Completed',
        cancelled: 'Cancelled',
    };
    // The plain-language refusals a 422 carries in errors.event[0]. The UI
    // shows them as they are.
    function refusal(p, event, payload) {
        if (!p) return 'This task isn’t available.';
        if (!p.started) return 'Start tracking this task first.';
        if (
            terminal(p) &&
            !['process_reopened', 'step_reopened'].includes(event)
        )
            return 'This task is closed. Reopen it to report progress.';
        if (
            event === 'submission_recorded' &&
            !B.validDate(payload.occurred_on)
        )
            return 'Choose the date you submitted it.';
        if (
            payload.occurred_on !== undefined &&
            payload.occurred_on !== null &&
            (!B.validDate(payload.occurred_on) || payload.occurred_on > today())
        )
            return 'Choose a date on or before today.';
        if (
            typeof payload.note === 'string' &&
            payload.note.trim().length > 500
        )
            return 'Keep the note to 500 characters.';
        if (
            event === 'waiting_reported' &&
            !accepts[event].includes(p.workflow)
        )
            return 'Record your submission before reporting that you’re waiting for the office.';
        if (
            event === 'completion_reported' &&
            accepts[event].includes(p.workflow) &&
            !p.allStepsDone
        )
            return 'Mark each step finished before recording completion.';
        if (accepts[event] && !accepts[event].includes(p.workflow))
            return `This change isn’t possible while the task is “${workflowLabel[p.workflow]}”.`;
        return null;
    }
    // POST /processes/{process}/events, on the local record. Returns
    // { ok: true } or { error } with the message to show.
    function event(s, id, name, payload = {}) {
        const p = process(s, id);
        if (!p) return { error: 'This task isn’t available.' };
        p.allStepsDone = stepsDone(s, p);
        const r = holder(s, id);
        if (name === 'process_untracked') {
            if (!untrackable(s, id))
                return {
                    error: 'This task already has progress. Cancel it instead.',
                };
            if (id === 'blue-card') s.blueCardStarted = false;
            else r.started = false;
            return { ok: true };
        }
        if (name === 'submission_retracted') {
            const target = r.reports?.find(
                (x) =>
                    x.id === payload.event_id &&
                    x.event === 'submission_recorded' &&
                    !x.withdrawn,
            );
            if (
                !['submitted', 'waiting', 'action_required'].includes(
                    p.workflow,
                )
            )
                return {
                    error: 'There’s no submission to withdraw from here.',
                };
            const note = refusal(p, name, payload);
            if (note && !note.startsWith('This change')) return { error: note };
            if (target) {
                target.withdrawn = true;
                if (payload.note?.trim())
                    r.reports.push({
                        id: reportId(id),
                        event: 'submission_retracted',
                        event_id: target.id,
                        note: payload.note.trim(),
                    });
                settle(s, id);
            } else {
                // A submission saved before reports existed: back to
                // preparing, as before.
                r.submission = null;
                r.workflow = 'preparing';
            }
            // Step states don't change with a withdrawn submission.
            return { ok: true };
        }
        const error = refusal(p, name, payload);
        if (error) return { error };
        const report = { id: reportId(id), event: name };
        if (takesDate.includes(name) && payload.occurred_on)
            report.occurred_on = payload.occurred_on;
        if (takesNote.includes(name) && payload.note?.trim())
            report.note = payload.note.trim();
        r.reports.push(report);
        settle(s, id);
        return { ok: true };
    }
    // "Track task" can be undone until there's progress: no report, no
    // finished step and no appointment.
    // The backend's rule (processes[].untrackable): started, not_started,
    // no finished step, no report or appointment, and no document
    // confirmed for the task.
    function untrackable(s, id) {
        const p = process(s, id);
        if (!p?.started || p.workflow !== 'not_started') return false;
        if (s.profileKind !== 'personal' && id === 'blue-card') return false;
        const r = holder(s, id);
        const stepDone =
            id === 'blue-card' ? s.prepared || s.submitDone : r.done;
        return (
            !stepDone &&
            !r.reports?.length &&
            !p.appointment &&
            !r.requirementConfirmed
        );
    }
    const eventFor = {
        preparing: 'preparation_started',
        blocked: 'blocked_reported',
        submitted: 'submission_recorded',
        waiting: 'waiting_reported',
        action_required: 'action_required_reported',
        completed: 'completion_reported',
        cancelled: 'cancellation_reported',
    };
    function stepsDone(s, p) {
        return p.id === 'blue-card'
            ? !!(s.prepared && s.submitDone)
            : !!s.records[p.id]?.done;
    }
    // What "Update progress" offers from where the task is now. "Blocked"
    // is blocked_reported before a submission and "the office needs
    // something" (action_required) after one; "waiting" needs a submission.
    function choices(s, id) {
        const p = process(s, id);
        if (!p?.started || terminal(p)) return [];
        const submitted = ['submitted', 'waiting', 'action_required'].includes(
            p.workflow,
        );
        return Object.keys(eventFor)
            .filter(
                (value) =>
                    value !== p.workflow &&
                    accepts[eventFor[value]].includes(p.workflow) &&
                    !(value === 'blocked' && submitted) &&
                    !(value === 'action_required' && !submitted) &&
                    // A recorded submission is edited from its own line.
                    !(value === 'submitted' && submitted),
            )
            .map((value) => ({
                value,
                event: eventFor[value],
                allowed: value !== 'completed' || stepsDone(s, p),
                note: takesNote.includes(eventFor[value]),
                date: takesDate.includes(eventFor[value])
                    ? value === 'submitted'
                        ? 'required'
                        : 'optional'
                    : null,
            }));
    }
    function updateWorkflow(s, id, value, note = '', date = null) {
        if (!eventFor[value]) return false;
        return !!event(s, id, eventFor[value], {
            ...(date ? { occurred_on: date } : {}),
            ...(note ? { note } : {}),
        }).ok;
    }
    // "I've submitted it" records the date and moves the task to submitted;
    // the step itself stays open. Changing the date corrects that record.
    function recordSubmission(s, id, date) {
        const p = process(s, id);
        const r = p && holder(s, id);
        const current = r && activeSubmission(r);
        if (current && p.started && !terminal(p)) {
            if (!B.validDate(date) || date > today()) return false;
            current.occurred_on = date;
            settle(s, id);
            return true;
        }
        return !!event(s, id, 'submission_recorded', { occurred_on: date }).ok;
    }
    function removeSubmission(s, id) {
        const p = process(s, id);
        if (!p?.started || terminal(p) || !p.submission) return false;
        const current = activeSubmission(holder(s, id));
        return !!event(s, id, 'submission_retracted', {
            event_id: current?.id,
        }).ok;
    }
    // Finish or reopen one step. Only an unfinished prerequisite blocks a
    // step (none here); a hold-up you reported doesn't, and the task stays
    // blocked.
    function toggleStep(s, actionId) {
        const a = actions(s).find((x) => x.id === actionId);
        const p = a && process(s, a.process);
        if (!p?.started || terminal(p)) return false;
        const finishing = a.status !== 'completed';
        if (p.id === 'blue-card') {
            if (actionId === 'blue-card:1') {
                if (finishing && !s.submission) return false;
                s.submitDone = finishing;
            } else s.prepared = finishing;
        } else s.records[p.id].done = finishing;
        // Like the backend, step work moves a task that hadn't started into
        // preparation; the office's states stay as they are.
        holder(s, p.id).reports.push({ id: reportId(p.id), event: 'step' });
        settle(s, p.id);
        return true;
    }
    // Tracking a task reports nothing: it is "Not started" until you do.
    function start(s, id) {
        const p = process(s, id);
        if (!p || p.started) return false;
        if (id === 'blue-card') {
            s.blueCardStarted = true;
            return true;
        }
        s.records[id].started = true;
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
        const done =
            id === 'blue-card'
                ? B.documentAction(s, index, action)
                : changeDocument(s.records[id].docs, index, action);
        // Confirming a requirement for the task is a process event: the
        // start can't be undone after it.
        if (done && action === 'confirm')
            holder(s, id).requirementConfirmed = true;
        return done;
    }
    function changeDocument(docs, index, action) {
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
    const durations = [15, 30, 45, 60, 90, 120];
    // appointment_recorded: the same appointment id again reschedules it.
    // The length may be unknown (null), never assumed. A place given as text
    // only can't be routed to; one from the sample day keeps its point.
    function recordAppointment(s, id, appointment) {
        const p = process(s, id);
        if (
            !p?.started ||
            terminal(p) ||
            !B.validDate(appointment.date) ||
            !/^([01]\d|2[0-3]):[0-5]\d$/.test(appointment.time)
        )
            return false;
        const minutes =
            appointment.duration === '' || appointment.duration == null
                ? null
                : Number(appointment.duration);
        if (
            minutes !== null &&
            !(Number.isInteger(minutes) && minutes >= 1 && minutes <= 1440)
        )
            return false;
        const before = p.appointment;
        const location = String(appointment.location || '').trim();
        const kept =
            before && location && location === before.location
                ? { lat: before.lat, lng: before.lng }
                : {};
        const record = {
            id: before?.id || reportId(id + '-appointment'),
            date: appointment.date,
            time: appointment.time,
            location,
            duration: minutes,
            ...(Number.isFinite(kept.lat) && Number.isFinite(kept.lng)
                ? kept
                : {}),
        };
        if (id === 'blue-card') s.appointment = record;
        else s.records[id].appointment = record;
        return true;
    }
    // appointment_cancelled.
    function cancelAppointment(s, id) {
        const p = process(s, id);
        if (!p?.appointment) return false;
        if (id === 'blue-card') s.appointment = null;
        else s.records[id].appointment = null;
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
    // The registration date is a target to aim for, not a legal deadline
    // (owner decision, 7 Oct 2026; backend f761736d); the reviewed card says
    // it "is not a confirmed personal legal deadline". The copy carries no
    // figure: any period a person reads must come from the card's own
    // source-checked text, never a string here. It needs the move-in date,
    // which this sample doesn't record, so it stays unknown; the client never
    // calculates it.
    const registrationDeadline = {
        rule: 'Aim to register soon after moving in',
        law: '§17 BMG',
        source: 'https://www.gesetze-im-internet.de/bmg/__17.html',
        verifiedAt: '2026-09-08',
        needs: 'your move-in date',
        fact: 'moved_in_at',
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
                              kind: 'Target date',
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
        event,
        untrackable,
        activeSubmission: (s, id) =>
            process(s, id) ? activeSubmission(holder(s, id)) : null,
        documents,
        guide,
        documentAction,
        durations,
        recordAppointment,
        cancelAppointment,
        appointmentForComposer,
        timeline,
        terminal,
        registrationDeadline,
        today,
    };
})(globalThis);
