(function (root) {
    function create(M, C) {
        function requestText(s, text, consumeDraft = false) {
            const request = String(text || '').trim();
            if (request && consumeDraft) s.prompt = '';
            return request;
        }
        function commit(s, label = 'Plan confirmed') {
            if (!s.proposal) throw Error('There is no proposal to apply.');
            const before = s.plans.find((p) => p.id === s.proposal.baseId);
            const draft = M.clone(s.proposal);
            C.recordPlan(s, 'Before confirmation');
            M.confirm(s);
            const after = s.plans.find((p) => p.date === draft.date);
            s.conversation.lastApplied = {
                date: draft.date,
                before: before ? M.clone(before) : null,
                after: after ? M.clone(after) : null,
                draft,
            };
            s.conversation.activeDate = draft.date;
            if (after) C.recordPlan(s, label);
            else
                s.conversation.lastApplied.cancellationEntryId = C.append(
                    s,
                    'assistant',
                    'This day has been cancelled. Your saved ideas are still available.',
                    {
                        kind: 'plan-cancelled',
                        planDate: draft.date,
                        plan: before ? M.clone(before) : null,
                    },
                ).id;
            return after;
        }
        function changed(s, label) {
            C.recordPlan(s, label);
            if (
                s.conversation.approval === 'routine' &&
                s.proposal?.items.length &&
                !M.conflicts(s.proposal).length
            ) {
                commit(s, label + ' · applied');
                return true;
            }
            return false;
        }
        function undo(s) {
            const change = s.conversation.lastApplied;
            if (!change) throw Error('There is no applied change to undo.');
            const current = s.plans.find((p) => p.date === change.date);
            if (
                JSON.stringify(current || null) !==
                    JSON.stringify(change.after) ||
                s.proposal ||
                (change.before &&
                    s.plans.some(
                        (p) =>
                            p.date === change.before.date &&
                            p.id !== change.after?.id,
                    ))
            )
                throw Error(
                    'The plan has changed since. Finish the current proposal before undoing.',
                );
            if (change.before) {
                const restored = M.clone(change.before);
                restored.revision =
                    (current?.revision || change.before.revision) + 1;
                s.plans = s.plans
                    .filter((p) => p.date !== change.date)
                    .concat(restored);
            } else {
                s.plans = s.plans.filter((p) => p.date !== change.date);
                s.proposal = M.clone(change.draft);
            }
            s.conversation.activeDate = change.before?.date || change.date;
            C.recordPlan(s, 'Undid last applied change');
            s.conversation.lastApplied = null;
        }
        function replacementPlan(s, request) {
            const date = request.planDate || s.conversation.activeDate;
            const plan =
                s.proposal?.date === date
                    ? s.proposal
                    : s.plans.find((p) => p.date === date);
            return s.conversation.activeDate === date &&
                (!s.proposal || s.proposal.date === date) &&
                plan &&
                JSON.stringify(plan) === request.proposalToken
                ? plan
                : null;
        }
        function replacementOptions(s, itemId, catalogue) {
            const date = s.conversation.activeDate;
            const plan =
                s.proposal?.date === date
                    ? s.proposal
                    : s.plans.find((p) => p.date === date);
            const item = plan?.items.find((item) => item.id === itemId);
            if (
                !item ||
                item.fixed ||
                item.type === 'appointment' ||
                (s.proposal && s.proposal.date !== date)
            )
                throw Error('Open the plan you want to change first.');
            return {
                kind: 'replacements',
                itemId,
                planDate: date,
                placeIds: M.filterReplacementPlaces(
                    catalogue,
                    plan,
                    s.saved,
                    {},
                ).map((p) => p.id),
                proposalToken: JSON.stringify(plan),
            };
        }
        function selectReplacement(s, request, selectedId) {
            const plan = replacementPlan(s, request);
            if (!plan)
                throw Error('Your plan has changed. Ask for new alternatives.');
            const previous = s.proposal ? M.clone(s.proposal) : null;
            try {
                if (!s.proposal) M.propose(s, [], plan.date, plan.id);
                M.replacePlace(s, request.itemId, selectedId);
                return s.proposal;
            } catch (error) {
                s.proposal = previous;
                throw error;
            }
        }
        function editTarget(plan, text) {
            const lower = text.toLowerCase();
            const named = plan.items.filter(
                (x) =>
                    lower.includes(x.title.toLowerCase()) ||
                    (x.fixed && /\bappointment\b/i.test(text)) ||
                    (/\bpark\b/i.test(text) &&
                        /park|grüngürtel/i.test(x.title)),
            );
            if (named.some((x) => x.fixed) && !/^(remove|delete)\b/i.test(text))
                throw Error(
                    'This activity has a fixed time. You can remove it, but cannot move or swap it.',
                );
            if (named.length === 1) return named[0];
            const flexible = plan.items.filter((x) => !x.fixed);
            const generic =
                /^(move|swap|replace|remove|delete|change|adjust|revise)\s+(it|this|the place|the activity|my plan|the plan)\b|^.*\blater[.!]?$/i.test(
                    text,
                );
            if (!named.length && flexible.length === 1 && generic)
                return flexible[0];
            throw Error(
                'Which activity do you mean? Use its name, or edit it directly in your plan.',
            );
        }
        return {
            requestText,
            commit,
            changed,
            undo,
            editTarget,
            replacementPlan,
            replacementOptions,
            selectReplacement,
        };
    }
    root.StudioConversationActions = { create };
    if (typeof module !== 'undefined') module.exports = { create };
})(globalThis);
