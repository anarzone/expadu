/* Connect the approved workspace to the Today/department design, not the live app. */
(function (root) {
    const clone = (value) => JSON.parse(JSON.stringify(value));
    const savedId = (item) =>
        item.type === 'event' ? `event:${item.id}` : item.id;
    function clearCancelledHandoffs(state, shared) {
        const change = state.conversation?.lastApplied;
        if (!change?.cancellationEntryId) return false;
        let repairedReview = false;
        if (!change.reviewContextCleared) {
            state.consumedReviewItems ||= {};
            state.consumedReviewItems[change.date] = [
                ...new Set([
                    ...(state.consumedReviewItems[change.date] || []),
                    ...(change.before?.items || []).map((item) =>
                        String(item.id).replace(/^event:/, ''),
                    ),
                    ...(shared.reviewDay?.date === change.date
                        ? shared.reviewDay.agenda || []
                        : []
                    ).map((item) => String(item.id).replace(/^event:/, '')),
                ]),
            ];
            change.reviewContextCleared = true;
            repairedReview = true;
        }
        if (change.pendingCleared) return repairedReview;
        const pendingDate = (item) =>
            item.handoffDate || item.date || change.date;
        const removed = (state.handoffs || []).filter(
            (item) => pendingDate(item) === change.date,
        );
        state.handoffs = (state.handoffs || []).filter(
            (item) => pendingDate(item) !== change.date,
        );
        shared.draft = (shared.draft || []).filter(
            (item) =>
                !removed.some(
                    (old) =>
                        old.type === item.type &&
                        old.id === item.id &&
                        pendingDate(item) === change.date,
                ),
        );
        // Run once per cancellation, allowing deliberate new additions later.
        change.pendingCleared = true;
        return true;
    }
    function receive(state, shared, M, C) {
        C.ensure(state);
        state.reviewDay = shared.reviewDay ? clone(shared.reviewDay) : null;
        const remaining = [],
            imported = [];
        // Only explicit new handoffs are scheduled. Bookmarks never create a plan.
        for (const source of shared.draft || []) {
            const item = clone(source);
            const id = savedId(item);
            const date =
                item.handoffDate ||
                item.date ||
                state.proposal?.date ||
                shared.brief?.date ||
                state.date;
            const existing =
                state.proposal?.date === date
                    ? state.proposal
                    : state.plans.find((p) => p.date === date);
            if (existing?.items.some((x) => x.id === id)) continue;
            try {
                if (item.type === 'journey' && item.activityId && item.legs) {
                    const candidate = clone(state);
                    M.propose(candidate, [], item.planDate || date);
                    const target = candidate.proposal.items.find(
                        (x) => x.id === item.activityId,
                    );
                    if (!target)
                        throw Error(
                            'The activity has changed. Choose a journey from the current plan.',
                        );
                    target.journey = clone(item);
                    state.proposal = candidate.proposal;
                    C.recordPlan(state, 'Journey selected — review required');
                    C.append(
                        state,
                        'assistant',
                        'Attached the captured journey to ' +
                            target.title +
                            '. Review its date and times before applying. This is not live route verification.',
                    );
                    continue;
                }
                if (item.scheduled)
                    M.addScheduled(state, { ...item, id }, date);
                else if (item.type === 'place')
                    M.propose(state, [item.id], date);
                else if (item.type === 'event')
                    M.addScheduled(state, { ...item, id, fixed: true }, date);
                else {
                    // A route/service brief or appointment without an end time is context, not a fabricated booking.
                    state.contextItems ||= [];
                    const key = `${item.type}:${item.id}`;
                    const old = state.contextItems.findIndex(
                        (x) => `${x.type}:${x.id}` === key,
                    );
                    if (old < 0) state.contextItems.push(item);
                    else state.contextItems[old] = item;
                    C.append(
                        state,
                        'assistant',
                        item.type === 'appointment' &&
                            [state.proposal, ...state.plans]
                                .filter(Boolean)
                                .some((p) =>
                                    p.items.some((x) => x.sourceId === item.id),
                                )
                            ? 'Review the shared appointment details before updating your plan. Your confirmed plan has not changed.'
                            : `Brought into Composer: ${item.title}.`,
                        { kind: 'department-context', context: item },
                    );
                    continue;
                }
                state.date = date;
                state.conversation.activeDate = date;
                imported.push(item.title);
            } catch (error) {
                remaining.push({
                    ...item,
                    handoffDate: date,
                    handoffError: error.message,
                });
            }
        }
        if (imported.length) {
            C.recordPlan(state, 'Added from your selection');
            C.append(
                state,
                'assistant',
                `Added ${imported.join(', ')} to your proposal. Review the times before confirming.`,
            );
        }
        state.handoffs = remaining;
        state.sharedBrief = clone(shared.brief || {});
        return { remaining, imported };
    }
    const bureaucracyPaths = {
        'core.anmeldung': ['address', 'address:0'],
        'case.bc.first_application.prepare': ['blue-card', 'blue-card:0'],
        'case.bc.first_application.submit': ['blue-card', 'blue-card:1'],
        'core.health_insurance': ['health', 'health:0'],
        'core.bank_account': ['bank', 'bank:0'],
    };
    function receiveBureaucracyHelp(state, stepId, sources, C) {
        const match = Object.entries(bureaucracyPaths).find(
            ([, path]) => path[1] === stepId,
        );
        const source =
            match && sources.find((record) => record.key === match[0]);
        if (!source) return false;
        C.ensure(state);
        state.conversation.activeDate = null;
        C.append(state, 'user', 'Help with this step: ' + source.title);
        C.append(
            state,
            'assistant',
            'Here is the step you opened. You can return to its checklist or read the official information. Live guidance is not connected in this preview.',
            {
                kind: 'bureaucracy',
                sourceKey: source.key,
                stepHelp: true,
            },
        );
        return true;
    }
    function receiveBureaucracyGuide(state, person, C, profile = null) {
        if (!['self', 'shared-adult', 'shared-child'].includes(person))
            return false;
        C.ensure(state);
        state.conversation.activeDate = null;
        C.append(state, 'user', 'Continue getting started with Bureaucracy.');
        C.append(state, 'assistant', 'Let’s pick up where you left off.', {
            kind: 'bureaucracy',
            orientation: true,
            orientationPerson: person,
            orientationProfile: profile,
        });
        return true;
    }
    function bureaucracyLink(item) {
        const target = bureaucracyPaths[item.sourceKey];
        if (target)
            return `../bureaucracy-next/?view=detail&process=${target[0]}&step=${encodeURIComponent(target[1])}`;
        // Only locally created process links can leave the plan surface.
        if (
            item.sourceType === 'bureaucracy' &&
            /^\.\.\/bureaucracy-next\/\?view=detail&process=(blue-card|address|health|bank)(?:&step=(?:blue-card|address|health|bank)%3A\d+)?$/.test(
                item.sourceURL || '',
            )
        )
            return item.sourceURL;
        return '../bureaucracy-next/';
    }
    const api = {
        receive,
        savedId,
        clearCancelledHandoffs,
        bureaucracyLink,
        receiveBureaucracyHelp,
        receiveBureaucracyGuide,
    };
    if (typeof module !== 'undefined') module.exports = api;
    root.StudioIntegration = api;
})(globalThis);
