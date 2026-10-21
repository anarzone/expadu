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
    // Saved journeys left with Travel's Favourites (owner, 14 Oct 2026) and
    // Services' requests wait for Services, hidden at launch (owner, 18 Oct).
    function project({ shared = {}, workspace = {} } = {}) {
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
        // Composer: an unsent message, or the conversation to go back to.
        // A plan's changes go straight into it with Undo (owner, 18 Oct
        // 2026, Composer review board 3), so nothing waits to be confirmed
        // or reviewed, and things added on other pages are in Your day.
        let composer = null;
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
                action: prompt ? 'Continue writing' : 'Continue conversation',
            };
        return { saved, composer, hasContinuation: !!composer };
    }
    root.TodayContinuity = { project, key, validDate };
})(globalThis);
