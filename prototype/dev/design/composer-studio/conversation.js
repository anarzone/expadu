(function (root) {
    const clone = (value) => JSON.parse(JSON.stringify(value));
    const now = () => new Date().toISOString();
    let sequence = 0;

    function id(conversation, kind) {
        const existing = new Set([
            ...conversation.chapters.flatMap((chapter) => [
                chapter.id,
                ...(chapter.entries || []).map((entry) => entry.id),
            ]),
            ...conversation.versions.map((version) => version.id),
        ]);
        let value;
        do {
            value = `${kind}-${Date.now().toString(36)}-${++sequence}`;
        } while (existing.has(value));
        return value;
    }

    function freeze(value) {
        if (value && typeof value === 'object' && !Object.isFrozen(value)) {
            Object.values(value).forEach(freeze);
            Object.freeze(value);
        }
        return value;
    }

    function chapter(conversation) {
        return {
            id: id(conversation, 'chapter'),
            startedAt: now(),
            title: 'New conversation',
            summary: '',
            entries: [],
        };
    }

    function ensure(s) {
        const migrating = !s.conversation;
        if (migrating) s.conversation = {};
        const conversation = s.conversation;
        if (!Array.isArray(conversation.chapters)) conversation.chapters = [];
        if (!Array.isArray(conversation.versions)) conversation.versions = [];
        if (!conversation.chapters.length)
            conversation.chapters.push(chapter(conversation));
        for (const section of conversation.chapters) {
            if (!Array.isArray(section.entries)) section.entries = [];
            if (!section.id) section.id = id(conversation, 'chapter');
            if (!section.startedAt) section.startedAt = now();
            if (typeof section.title !== 'string')
                section.title = 'New conversation';
            if (typeof section.summary !== 'string') section.summary = '';
        }
        if (
            !conversation.chapters.some(
                (section) => section.id === conversation.currentId,
            )
        )
            conversation.currentId = conversation.chapters.at(-1).id;
        if (!['ask', 'routine'].includes(conversation.approval))
            conversation.approval = 'ask';
        if (typeof conversation.activeDate !== 'string')
            conversation.activeDate = null;
        conversation.versions.forEach(freeze);
        if (migrating && Array.isArray(s.messages)) {
            for (const message of s.messages) {
                if (!message || typeof message !== 'object') continue;
                const {
                    id: oldId,
                    at,
                    role,
                    text,
                    content,
                    ...extra
                } = clone(message);
                const entry = append(
                    s,
                    role || 'assistant',
                    text || content || '',
                    extra,
                );
                if (at) entry.at = at;
            }
        }
        return conversation;
    }

    function current(s) {
        const conversation = ensure(s);
        return conversation.chapters.find(
            (section) => section.id === conversation.currentId,
        );
    }

    function append(s, role, text, extra = {}) {
        const section = current(s);
        const entry = {
            ...clone(extra),
            id: id(s.conversation, 'entry'),
            at: now(),
            role,
            text: String(text || ''),
        };
        section.entries.push(entry);
        if (
            role === 'user' &&
            !section.entries.slice(0, -1).some((item) => item.role === 'user')
        )
            section.title =
                entry.text.replace(/\s+/g, ' ').trim().slice(0, 64) ||
                'New conversation';
        return entry;
    }

    function updateEntry(s, entryId, patch) {
        const entry = ensure(s)
            .chapters.flatMap((section) => section.entries)
            .find((item) => item.id === entryId);
        if (!entry) return null;
        const { id: ignoredId, at: ignoredAt, ...changes } = clone(patch);
        Object.assign(entry, changes);
        return entry;
    }

    function startFresh(s) {
        const section = current(s);
        const requests = section.entries
            .filter((entry) => entry.role === 'user')
            .map((entry) => entry.text.replace(/\s+/g, ' ').trim())
            .filter(Boolean);
        const dates = [
            ...new Set(
                section.entries.map((entry) => entry.planDate).filter(Boolean),
            ),
        ];
        section.summary = `Deterministic topic summary (not AI memory). Requests: ${
            requests
                .slice(-3)
                .map((text) => text.slice(0, 140))
                .join(' · ') || 'none'
        }. Plan dates: ${dates.join(', ') || 'none'}.`;
        const next = chapter(s.conversation);
        s.conversation.chapters.push(next);
        s.conversation.currentId = next.id;
        s.conversation.activeDate = null;
        return next;
    }

    function activatePlan(s, date) {
        const section = current(s);
        s.conversation.activeDate = date;
        return (
            section.entries.find(
                (entry) => entry.kind === 'plan' && entry.planDate === date,
            ) || append(s, 'assistant', '', { kind: 'plan', planDate: date })
        );
    }

    // Explicitly opening a plan brings its live controls into the current exchange.
    // Its previous position keeps the version seen there, never another live editor.
    function presentPlan(s, date, replyId = null) {
        const section = current(s);
        if (
            s.proposal?.date !== date &&
            !(s.plans || []).some((p) => p.date === date)
        )
            return null;
        const reply = replyId
            ? section.entries.find((entry) => entry.id === replyId)
            : null;
        if (replyId && !reply) return null;
        if (
            reply &&
            !section.entries.some(
                (entry) => entry.kind === 'plan' && entry.planDate === date,
            )
        ) {
            Object.assign(reply, { kind: 'plan', text: '', planDate: date });
        }
        const version = recordPlan(s, 'Opened plan', date);
        if (!version) return null;
        const existing = section.entries.find(
            (entry) => entry.kind === 'plan' && entry.planDate === date,
        );
        if (!replyId && section.entries.at(-1) === existing) return existing;
        if (existing !== reply) existing.kind = 'plan-history';
        const target = reply || append(s, 'assistant', '');
        Object.assign(target, {
            kind: 'plan',
            text: '',
            planDate: date,
            versionId: version.id,
        });
        return target;
    }

    function recordPlan(s, label, targetDate = null) {
        const conversation = ensure(s);
        const plan = targetDate
            ? s.proposal?.date === targetDate
                ? s.proposal
                : (s.plans || []).find((p) => p.date === targetDate)
            : s.proposal ||
              (s.plans || []).find(
                  (item) => item.date === (conversation.activeDate || s.date),
              );
        if (!plan) return null;
        const status = plan === s.proposal ? 'proposed' : 'confirmed';
        const entry = activatePlan(s, plan.date);
        const previous = conversation.versions.find(
            (version) => version.id === entry.versionId,
        );
        if (
            previous &&
            previous.status === status &&
            JSON.stringify(previous.plan) === JSON.stringify(plan)
        )
            return previous;
        const version = freeze({
            id: id(conversation, 'version'),
            at: now(),
            label: String(label || 'Plan updated'),
            status,
            plan: clone(plan),
            entryId: entry.id,
            chapterId: conversation.currentId,
        });
        conversation.versions.push(version);
        entry.versionId = version.id;
        return version;
    }

    function setApproval(s, approval) {
        if (!['ask', 'routine'].includes(approval))
            throw Error('Choose a valid approval setting: ask or routine.');
        ensure(s).approval = approval;
        return approval;
    }

    // A bounded context projection for a future approved-source engine. Original
    // messages and authoritative plan/approval state remain separate and intact.
    function context(s, limit = 12) {
        const section = current(s);
        const count = Math.max(1, Math.min(30, Number(limit) || 12));
        const older = section.entries.slice(0, -count);
        return {
            summary: older.length
                ? 'Earlier topics: ' +
                  older
                      .filter((e) => e.role === 'user')
                      .slice(-8)
                      .map((e) => e.text.slice(0, 120))
                      .join(' · ')
                : '',
            summarizedEntries: older.length,
            recent: clone(section.entries.slice(-count)),
            activeDate: s.conversation.activeDate,
            summaryIsAuthoritative: false,
        };
    }

    const api = {
        ensure,
        current,
        append,
        updateEntry,
        startFresh,
        activatePlan,
        presentPlan,
        recordPlan,
        setApproval,
        context,
    };
    if (typeof module !== 'undefined') module.exports = api;
    root.StudioConversation = api;
})(globalThis);
