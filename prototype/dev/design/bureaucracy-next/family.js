/* Synthetic people and scope examples for interaction review only.
   These choices never establish real delegation or guardian authority. */
(function (root) {
    const roster = [
        { id: 'self', name: 'You', initial: 'Y', note: 'Your sample plan' },
        {
            id: 'shared-adult',
            name: 'Alex',
            initial: 'A',
            note: 'Shared adult · sample',
            kind: 'adult',
            record: 2,
            task: 'Address registration',
            documents: 2,
        },
        {
            id: 'shared-child',
            name: 'Sam',
            initial: 'S',
            note: 'Dependent · sample',
            kind: 'dependent',
            record: 3,
            task: 'Health insurance',
            // The health insurance card lists no documents.
            documents: 0,
        },
        {
            id: 'adult',
            name: 'Private adult',
            initial: 'P',
            note: 'Permission needed',
            denied: true,
        },
        {
            id: 'child',
            name: 'Pending dependent',
            initial: 'D',
            note: 'Guardian review pending',
            denied: true,
        },
    ];
    function hydrate(s) {
        s.household ||= {};
        if (s.profileKind === 'personal') return s.household;
        for (const p of roster.filter((p) => p.record !== undefined)) {
            s.household[p.id] ||= {
                access: 'edit',
                documents: Array(p.documents ?? 0).fill('missing'),
                workflow: p.kind === 'adult' ? 'preparing' : 'waiting',
                orientation: null,
                note: '',
                started: true,
            };
        }
        return s.household;
    }
    function info(s) {
        return roster.find((p) => p.id === s.person) || null;
    }
    function current(s) {
        const p = info(s);
        if (!p || p.denied || p.id === 'self') return null;
        hydrate(s);
        const record = s.household[p.id];
        return record && ['edit', 'read'].includes(record.access)
            ? record
            : null;
    }
    function canEdit(s) {
        return current(s)?.access === 'edit';
    }
    function document(s, index, action) {
        if (
            !canEdit(s) ||
            !Number.isInteger(index) ||
            index < 0 ||
            index >= current(s).documents.length
        )
            return false;
        const p = current(s),
            old = p.documents[index];
        if (
            action === 'confirm' &&
            !['reported_available', 'needs_reconfirmation'].includes(old)
        )
            return false;
        if (!['have', 'confirm', 'missing'].includes(action)) return false;
        p.documents[index] = {
            have: 'reported_available',
            confirm: 'confirmed_for_use',
            missing: 'missing',
        }[action];
        return true;
    }
    function setPreviewAccess(s, access) {
        const p = info(s);
        if (
            !p ||
            p.record === undefined ||
            !['read', 'edit', 'revoked', 'pending'].includes(access)
        )
            return false;
        hydrate(s)[p.id].access = access;
        return true;
    }
    root.BureaucracyFamily = {
        roster,
        hydrate,
        info,
        current,
        canEdit,
        document,
        setPreviewAccess,
    };
})(globalThis);
