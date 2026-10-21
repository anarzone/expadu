(function (root) {
    function create(M, C) {
        function requestText(s, text, consumeDraft = false) {
            const request = String(text || '').trim();
            if (request && consumeDraft) s.prompt = '';
            return request;
        }
        // A plan as it's compared for Undo: its revision number moves on
        // with every save, so it doesn't count.
        const same = (a, b) =>
            JSON.stringify(a ? { ...a, revision: 0 } : null) ===
            JSON.stringify(b ? { ...b, revision: 0 } : null);
        // What a change did to the day's items, for its reply and the marks
        // on the plan (owner, 18 Oct 2026, Composer review canvas board 9):
        // moved (old → new time), added, removed, or one in place of another.
        function diff(before, after) {
            if (!before || !after) return [];
            const span = (x) => `${x.start}–${x.end}`;
            const was = new Map(before.items.map((x) => [x.id, x]));
            const now = new Map(after.items.map((x) => [x.id, x]));
            const rows = [];
            for (const x of after.items) {
                const old = was.get(x.id);
                if (!old)
                    rows.push({ kind: 'added', id: x.id, title: x.title, type: x.type || '', to: span(x) });
                else if (old.start !== x.start || old.end !== x.end)
                    rows.push({ kind: 'moved', id: x.id, title: x.title, type: x.type || '', from: span(old), to: span(x) });
            }
            for (const x of before.items)
                if (!now.has(x.id)) {
                    const same = rows.find(
                        (r) => r.kind === 'added' && r.to === span(x),
                    );
                    if (same) Object.assign(same, { kind: 'replaced', was: x.title });
                    else rows.push({ kind: 'removed', id: x.id, title: x.title, type: x.type || '', from: span(x) });
                }
            return rows;
        }
        // A cancelled day is said in the reply asking for it (`entryId`), or
        // in its own message.
        function commit(s, label = 'Plan saved', entryId = null) {
            if (!s.proposal) throw Error('There is no plan change to save.');
            const before = s.plans.find((p) => p.id === s.proposal.baseId);
            const draft = M.clone(s.proposal);
            C.recordPlan(s, 'Before the change');
            M.confirm(s);
            const after = s.plans.find((p) => p.date === draft.date);
            const conversation = s.conversation;
            conversation.applied ||= [];
            const change = {
                id: `change-${conversation.applied.length + 1}-${Date.now()}`,
                date: draft.date,
                label,
                before: before ? M.clone(before) : null,
                after: after ? M.clone(after) : null,
                draft,
                rows: diff(before, after),
            };
            conversation.applied.push(change);
            // The latest twenty changes can be undone, newest first.
            conversation.applied = conversation.applied.slice(-20);
            conversation.lastApplied = change;
            conversation.activeDate = draft.date;
            if (after) C.recordPlan(s, label);
            else if (entryId) change.cancellationEntryId = entryId;
            else
                change.cancellationEntryId = C.append(
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
        // Every change goes straight into the plan, with its Undo (owner, 18
        // Oct 2026, Composer review canvas board 3). One that can't go in
        // (a clash, a time outside listed hours) changes nothing, and says
        // why; a new day emptied of everything isn't kept.
        function changed(s, label, entryId = null) {
            if (!s.proposal) return { applied: false, problem: null };
            const problem = M.conflicts(s.proposal)[0];
            if (problem) {
                s.proposal = null;
                return { applied: false, problem };
            }
            if (!s.proposal.items.length && !s.proposal.baseId) {
                s.proposal = null;
                return { applied: false, problem: null };
            }
            C.recordPlan(s, label);
            commit(s, label, entryId);
            return { applied: true, problem: null };
        }
        function undo(s) {
            const change = s.conversation.lastApplied;
            if (!change) throw Error('There is no change to undo.');
            const current = s.plans.find((p) => p.date === change.date);
            if (
                !same(current, change.after) ||
                s.proposal ||
                (change.before &&
                    s.plans.some(
                        (p) =>
                            p.date === change.before.date &&
                            p.id !== change.after?.id,
                    ))
            )
                throw Error('The plan has changed since.');
            if (change.before) {
                const restored = M.clone(change.before);
                restored.revision =
                    (current?.revision || change.before.revision) + 1;
                s.plans = s.plans
                    .filter((p) => p.date !== change.date)
                    .concat(restored);
            } else s.plans = s.plans.filter((p) => p.date !== change.date);
            s.conversation.activeDate = change.before?.date || change.date;
            C.recordPlan(s, 'Undid the change');
            // The change before it can be undone next.
            s.conversation.applied = (s.conversation.applied || []).filter(
                (x) => x.id !== change.id,
            );
            s.conversation.lastApplied = s.conversation.applied.at(-1) || null;
            return change;
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
