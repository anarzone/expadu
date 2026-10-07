/* Read-only summaries of the connected prototype. Never schedule or confirm here. */
(function (root) {
    const list = (value) => (Array.isArray(value) ? value : []);
    const text = (value) => (typeof value === 'string' ? value.trim() : '');
    const validDate = (value) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return false;
        const date = new Date(`${value}T12:00:00Z`);
        return (
            !Number.isNaN(date.valueOf()) &&
            date.toISOString().slice(0, 10) === value
        );
    };
    const key = (item) =>
        `${item.type}:${item.type === 'event' ? item.occurrence || `${item.id}:${item.date}` : item.id}`;
    const unique = (items, identity) => {
        const seen = new Set();
        return items.filter((item) => {
            const id = identity(item);
            if (seen.has(id)) return false;
            seen.add(id);
            return true;
        });
    };
    function project({
        shared = {},
        workspace = {},
        journeyModel = {},
        savedJourneys = [],
        categories = [],
    } = {}) {
        shared ||= {};
        workspace ||= {};
        const saved = unique(
            list(shared.savedIdeas).filter(
                (item) =>
                    item &&
                    text(item.id) &&
                    text(item.title) &&
                    (item.type === 'place' ||
                        (item.type === 'event' && validDate(item.date))),
            ),
            key,
        );
        const proposal = workspace.proposal;
        const proposalItems = list(proposal?.items).filter(
            (item) => item && text(item.title),
        );
        const pending = unique(
            list(shared.draft).filter(
                (item) =>
                    item &&
                    text(item.title) &&
                    item.scheduled === true &&
                    !proposalItems.some(
                        (other) =>
                            key(other) === key(item) ||
                            other.id === item.id ||
                            other.id === `event:${item.id}`,
                    ),
            ),
            key,
        );
        let composer = null;
        if (
            proposal &&
            validDate(proposal.date) &&
            (proposalItems.length || proposal.baseId)
        ) {
            composer = {
                kind: 'proposal',
                date: proposal.date,
                title: proposal.baseId
                    ? 'Changes to your plan'
                    : 'Your next plan',
                status: 'Awaiting confirmation',
                items: proposalItems,
                pending: pending.length,
                action: 'Continue planning',
            };
        } else if (pending.length) {
            const dates = [
                ...new Set(pending.map((item) => item.date).filter(validDate)),
            ];
            composer = {
                kind: 'selections',
                date: dates.length === 1 ? dates[0] : '',
                title: 'Bring your plans together',
                status: 'Selections to review',
                items: pending,
                pending: 0,
                action: 'Review in Composer',
            };
        } else {
            const prompt = text(workspace.prompt) || text(shared.prompt);
            const chapter = list(workspace.conversation?.chapters).find(
                (item) => item?.id === workspace.conversation?.currentId,
            );
            const request = list(chapter?.entries).findLast(
                (entry) => entry?.role === 'user' && text(entry.text),
            );
            if (prompt || request)
                composer = {
                    kind: prompt ? 'message' : 'conversation',
                    date: '',
                    title: prompt
                        ? 'Your draft message'
                        : text(chapter.title) || request.text,
                    excerpt: prompt || '',
                    status: prompt ? 'Unsent message' : 'Your conversation',
                    items: [],
                    pending: 0,
                    action: prompt
                        ? 'Continue writing'
                        : 'Continue conversation',
                };
        }
        const requests = unique(
            list(shared.serviceRequests).filter(
                (item) =>
                    item?.type === 'service' &&
                    text(item.title) &&
                    categories.some(
                        (category) => category.id === item.category,
                    ) &&
                    item.requirements &&
                    typeof item.requirements === 'object',
            ),
            (item) => item.category,
        );
        const journeys = unique(list(savedJourneys), (id) => id)
            .map((id) =>
                list(journeyModel.routes).find((route) => route.id === id),
            )
            .filter(Boolean);
        return {
            saved,
            composer,
            requests,
            journeys,
            hasContinuation:
                !!composer || !!requests.length || !!journeys.length,
        };
    }
    root.TodayContinuity = { project, key, validDate };
})(globalThis);
