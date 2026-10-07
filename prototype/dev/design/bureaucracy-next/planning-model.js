/* UI prototype only: plan time with Composer's existing proposal model. */
(function (root) {
    const clone = (value) => JSON.parse(JSON.stringify(value));
    const M = root.StudioModel,
        C = root.StudioConversation;
    function sourceURL(process, step) {
        return (
            '../bureaucracy-next/?view=detail&process=' +
            encodeURIComponent(process) +
            (step ? '&step=' + encodeURIComponent(step) : '')
        );
    }
    function selection(state, processId, kind, stepId) {
        const P = root.BureaucracyPlan,
            p = P.process(state, processId);
        if (
            !root.BureaucracyPreview.canEdit(state) ||
            !p?.started ||
            P.terminal(p)
        )
            throw Error('Start this process before planning time for it.');
        if (kind === 'appointment') {
            const recorded = P.appointmentForComposer(state, processId);
            if (!recorded) throw Error('Record your appointment first.');
            return {
                id: 'recorded-' + recorded.id,
                sourceId: recorded.id,
                type: 'appointment',
                title: recorded.title,
                fixed: true,
                personal: true,
                date: recorded.date,
                start: recorded.time,
                location: recorded.location,
                sourceType: 'bureaucracy',
                sourceURL: sourceURL(processId),
                planningKind: 'appointment',
            };
        }
        const step = P.projection(state).actions.find(
            (a) => a.id === stepId && a.process === processId,
        );
        if (!step || kind !== 'preparation')
            throw Error('Open a current step before planning time.');
        return {
            id: 'bureaucracy-preparation-' + stepId,
            sourceId: 'bureaucracy-preparation-' + stepId,
            type: 'task',
            title: 'Paperwork time',
            fixed: false,
            sourceType: 'bureaucracy',
            sourceURL: sourceURL(processId, stepId),
            planningKind: 'preparation',
            location: '',
        };
    }
    function linked(workspace, sourceId) {
        const plans = [
            workspace?.proposal,
            ...(workspace?.plans || []).filter(
                (p) => p.date !== workspace?.proposal?.date,
            ),
        ].filter(Boolean);
        for (const plan of plans) {
            const item = plan.items.find((i) => i.sourceId === sourceId);
            if (item)
                return {
                    item: clone(item),
                    date: plan.date,
                    status:
                        plan === workspace.proposal
                            ? 'In your proposal'
                            : 'In your plan',
                };
        }
        return null;
    }
    function record(workspace, message) {
        C.ensure(workspace);
        C.recordPlan(workspace, message);
        C.append(workspace, 'assistant', message, { kind: 'text' });
    }
    function propose(shared, workspace, item, choice) {
        const existing = linked(workspace, item.sourceId);
        if (existing && existing.date !== choice.date)
            throw Error(
                'This is already in another day. Review that plan before moving it.',
            );
        if (
            item.planningKind === 'appointment' &&
            (choice.date !== item.date || choice.start !== item.start)
        )
            throw Error(
                'Keep the recorded appointment date and start. Edit the appointment record if it changed.',
            );
        const selected = root.ExpaduHandoffs.schedule(item, choice);
        const others = clone(shared);
        others.draft = (others.draft || []).filter(
            (i) => i.sourceId !== item.sourceId,
        );
        const check = clone(workspace);
        for (const p of [check.proposal, ...(check.plans || [])].filter(
            Boolean,
        ))
            p.items = p.items.filter((i) => i.sourceId !== item.sourceId);
        const conflict = root.ExpaduHandoffs.conflict(others, check, selected);
        if (conflict) throw Error(conflict);
        const candidate = clone(workspace);
        M.updateRecordedAppointment(candidate, selected, selected.date);
        const before = clone({
            proposal: workspace.proposal,
            date: workspace.date,
        });
        Object.assign(workspace, candidate);
        record(
            workspace,
            (existing ? 'Updated ' : 'Added ') +
                selected.title +
                ' in your proposal. Your process record is unchanged.',
        );
        return {
            before,
            after: JSON.stringify(workspace.proposal),
            date: selected.date,
        };
    }
    function remove(workspace, sourceId) {
        const current = linked(workspace, sourceId);
        if (!current)
            throw Error('This activity changed. Open the latest plan.');
        const candidate = clone(workspace);
        M.propose(candidate, [], current.date);
        candidate.proposal.items = candidate.proposal.items.filter(
            (i) => i.sourceId !== sourceId,
        );
        const before = clone({
            proposal: workspace.proposal,
            date: workspace.date,
        });
        Object.assign(workspace, candidate);
        record(
            workspace,
            'Proposed removing ' +
                current.item.title +
                ' from your day. Your process record is unchanged.',
        );
        return {
            before,
            after: JSON.stringify(workspace.proposal),
            date: current.date,
        };
    }
    function undo(workspace, receipt) {
        if (JSON.stringify(workspace.proposal) !== receipt.after)
            throw Error(
                'Your proposal changed. Review it in Composer before making another change.',
            );
        workspace.proposal = clone(receipt.before.proposal);
        workspace.date = receipt.before.date;
        if (workspace.proposal)
            record(workspace, 'Undid the last Bureaucracy planning change.');
        else
            C.append(
                workspace,
                'assistant',
                'Undid the last Bureaucracy planning change.',
            );
    }
    root.BureaucracyPlanning = {
        selection,
        linked,
        propose,
        remove,
        undo,
        sourceURL,
    };
})(globalThis);
