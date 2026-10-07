/* Explicit local plan edits. Saved ideas and external appointments are untouched. */
(function (root) {
    const clone = (value) => JSON.parse(JSON.stringify(value));
    const identity = (item) => String(item.id).replace(/^event:/, '');
    const onDay = (item, date) => (item.handoffDate || item.date) === date;
    function snapshot(shared, workspace, date) {
        return clone({
            reviewDay:
                shared.reviewDay?.date === date ? shared.reviewDay : null,
            plan: workspace?.plans?.find((plan) => plan.date === date) || null,
            proposal:
                workspace?.proposal?.date === date ? workspace.proposal : null,
            pending: (shared.draft || []).filter((item) => onDay(item, date)),
            handoffs: (workspace?.handoffs || []).filter((item) =>
                onDay(item, date),
            ),
            consumedReviewItems: workspace?.consumedReviewItems?.[date] || [],
        });
    }
    function remove(shared, workspace, date, expected) {
        const current = root.ExpaduHandoffs.dayItems(
            shared,
            workspace,
            date,
        ).find((item) => identity(item) === identity(expected));
        if (!current || JSON.stringify(current) !== JSON.stringify(expected))
            throw Error(
                'Your day changed. Review the latest activities before removing one.',
            );
        const before = snapshot(shared, workspace, date);
        if (shared.reviewDay?.date === date)
            shared.reviewDay.agenda = shared.reviewDay.agenda.filter(
                (item) => identity(item) !== identity(expected),
            );
        const plan =
            workspace?.proposal?.date === date
                ? workspace.proposal
                : workspace?.plans?.find((entry) => entry.date === date);
        if (plan?.items.some((item) => identity(item) === identity(expected))) {
            plan.items = plan.items.filter(
                (item) => identity(item) !== identity(expected),
            );
            if (plan !== workspace.proposal) {
                plan.revision = (plan.revision || 0) + 1;
                if (!plan.items.length) {
                    workspace.consumedReviewItems ||= {};
                    workspace.consumedReviewItems[date] = [
                        ...new Set([
                            ...(workspace.consumedReviewItems[date] || []),
                            identity(expected),
                            ...(shared.reviewDay?.date === date
                                ? shared.reviewDay.agenda || []
                                : []
                            ).map(identity),
                        ]),
                    ];
                    workspace.plans = workspace.plans.filter(
                        (entry) => entry !== plan,
                    );
                }
            }
        }
        const keep = (item) =>
            !onDay(item, date) ||
            (identity(item) !== identity(expected) &&
                !(item.type === 'journey' && item.activityId === expected.id));
        shared.draft = (shared.draft || []).filter(keep);
        shared.revision = (shared.revision || 0) + 1;
        if (workspace?.handoffs)
            workspace.handoffs = workspace.handoffs.filter(keep);
        if (workspace?.conversation?.lastApplied?.date === date)
            workspace.conversation.lastApplied = null;
        return {
            date,
            title: expected.title,
            before,
            after: snapshot(shared, workspace, date),
        };
    }
    function reschedule(shared, workspace, date, expected, times) {
        const H = root.ExpaduHandoffs;
        const current = H.dayItems(shared, workspace, date).find(
            (item) => identity(item) === identity(expected),
        );
        if (!current || JSON.stringify(current) !== JSON.stringify(expected))
            throw Error(
                'Your day changed. Reopen this visit to see its latest times.',
            );
        if (current.fixed || (current.type && current.type !== 'place'))
            throw Error(
                'This activity has fixed times. Keep its original schedule.',
            );
        const selected = H.schedule(current, { ...times, date });
        // Validate against the rest of the day, without treating this edit as a duplicate.
        const checkShared = clone(shared),
            checkWorkspace = workspace && clone(workspace);
        const other = (item) => identity(item) !== identity(expected);
        checkShared.draft = (checkShared.draft || []).filter(
            (item) => !onDay(item, date) || other(item),
        );
        for (const plan of [
            ...(checkWorkspace?.plans || []),
            checkWorkspace?.proposal,
        ]) {
            if (plan?.date === date) plan.items = plan.items.filter(other);
        }
        const error = H.conflict(checkShared, checkWorkspace, selected);
        if (error) throw Error(error);
        const before = snapshot(shared, workspace, date);
        const plan =
            workspace?.proposal?.date === date
                ? workspace.proposal
                : workspace?.plans?.find((entry) => entry.date === date);
        const update = (item) => {
            if (identity(item) === identity(expected)) {
                item.start = selected.start;
                item.end = selected.end;
            }
        };
        if (plan?.items.some((item) => !other(item))) {
            plan.items.forEach(update);
            if (plan !== workspace.proposal)
                plan.revision = (plan.revision || 0) + 1;
        }
        for (const list of [shared.draft, workspace?.handoffs])
            (list || []).filter((item) => onDay(item, date)).forEach(update);
        shared.revision = (shared.revision || 0) + 1;
        if (workspace?.conversation?.lastApplied?.date === date)
            workspace.conversation.lastApplied = null;
        return {
            date,
            title: current.title,
            before,
            after: snapshot(shared, workspace, date),
        };
    }
    function editPersonal(shared, workspace, date, expected, values) {
        const H = root.ExpaduHandoffs;
        const current = H.dayItems(shared, workspace, date).find(
            (item) => identity(item) === identity(expected),
        );
        if (!current || JSON.stringify(current) !== JSON.stringify(expected))
            throw Error('Your day changed. Reopen the plan before editing.');
        if (
            current.fixed ||
            current.type === 'event' ||
            (!current.personal && current.type !== 'appointment')
        )
            throw Error(
                'Only personal plans can be edited here. Keep fixed event times.',
            );
        const title = String(values.title || '')
            .trim()
            .slice(0, 160);
        const location = String(values.location || '')
            .trim()
            .slice(0, 160);
        const { start, end } = values;
        if (!title) throw Error('Give your plan a name.');
        if (
            !/^([01]\d|2[0-3]):[0-5]\d$/.test(start) ||
            !/^([01]\d|2[0-3]):[0-5]\d$/.test(end) ||
            end <= start
        )
            throw Error('Choose valid times with an end time after the start.');
        const overlap = H.dayItems(shared, workspace, date).find(
            (item) =>
                identity(item) !== identity(expected) &&
                start < item.end &&
                item.start < end,
        );
        if (overlap)
            throw Error(`This overlaps ${overlap.title}. Choose another time.`);
        const before = snapshot(shared, workspace, date);
        const update = (item) => {
            if (identity(item) !== identity(expected)) return;
            if (
                item.journey &&
                (item.start !== start ||
                    item.end !== end ||
                    item.location !== location)
            )
                item.journey.status = 'stale';
            if (item.location !== location) {
                delete item.lat;
                delete item.lng;
                delete item.destinationId;
            }
            Object.assign(item, { title, location, start, end });
        };
        if (shared.reviewDay?.date === date)
            shared.reviewDay.agenda.forEach(update);
        const plan =
            workspace?.proposal?.date === date
                ? workspace.proposal
                : workspace?.plans?.find((p) => p.date === date);
        if (plan) {
            plan.items.forEach(update);
            if (plan !== workspace.proposal)
                plan.revision = (plan.revision || 0) + 1;
        }
        for (const list of [shared.draft, workspace?.handoffs])
            (list || []).filter((item) => onDay(item, date)).forEach(update);
        shared.revision = (shared.revision || 0) + 1;
        return {
            date,
            title,
            before,
            after: snapshot(shared, workspace, date),
        };
    }
    function undo(shared, workspace, receipt) {
        const { date, before, after } = receipt;
        if (
            JSON.stringify(snapshot(shared, workspace, date)) !==
            JSON.stringify(after)
        )
            throw Error(
                'Your day changed since that change. Undo is no longer available.',
            );
        if (workspace) {
            if (
                JSON.stringify(before.consumedReviewItems) !==
                JSON.stringify(after.consumedReviewItems)
            ) {
                workspace.consumedReviewItems ||= {};
                if (before.consumedReviewItems?.length)
                    workspace.consumedReviewItems[date] = clone(
                        before.consumedReviewItems,
                    );
                else delete workspace.consumedReviewItems[date];
            }
            if (JSON.stringify(before.plan) !== JSON.stringify(after.plan)) {
                const restored = before.plan && clone(before.plan);
                if (restored)
                    restored.revision = (before.plan.revision || 0) + 2;
                workspace.plans = (workspace.plans || []).filter(
                    (plan) => plan.date !== date,
                );
                if (restored) workspace.plans.push(restored);
            }
            if (
                JSON.stringify(before.proposal) !==
                JSON.stringify(after.proposal)
            )
                workspace.proposal = clone(before.proposal);
            if (workspace.handoffs)
                workspace.handoffs = workspace.handoffs
                    .filter((item) => !onDay(item, date))
                    .concat(clone(before.handoffs));
        }
        shared.draft = (shared.draft || [])
            .filter((item) => !onDay(item, date))
            .concat(clone(before.pending));
        if (
            JSON.stringify(before.reviewDay) !== JSON.stringify(after.reviewDay)
        )
            shared.reviewDay = clone(before.reviewDay);
        shared.revision = (shared.revision || 0) + 1;
    }
    root.ExpaduDayActions = { remove, reschedule, editPersonal, undo };
})(globalThis);
