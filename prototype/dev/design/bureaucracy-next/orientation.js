/* Product-orientation sample only. No eligibility, legal facts or plan assessment. */
(function (root) {
    const key = 'expadu-bureaucracy-design-v1';
    const questions = [
        // The registry's own question (facts.yaml, arrival_planned), asked
        // in its words: the answer is kept in your details, so it is never
        // recorded from a different question.
        {
            key: 'move_stage',
            fact: 'arrival_planned',
            title: 'Are you still planning this move to Germany?',
            why: 'Planning a move is different from having already arrived. Your answer is kept in your details. You can skip any question.',
            label: 'Your move',
            options: [
                ['planning', 'Yes, still planning', 'calendar'],
                ['here', 'No, I have arrived', 'pin'],
                ['unknown', 'I’m not sure', 'info'],
            ],
        },
        {
            key: 'focus',
            title: 'What would you like help with?',
            why: 'Choose what feels useful today. Your other tasks stay available.',
            label: 'Your focus',
            options: [
                ['next', 'Find my next step', 'arrow'],
                ['documents', 'Check my documents', 'document'],
                ['appointment', 'Record an appointment', 'calendar'],
                ['unknown', 'I’m not sure', 'info'],
            ],
        },
    ];
    const allowed = (s) =>
        s?.person === 'self' || root.BureaucracyFamily?.canEdit(s) === true;
    const owner = (s) =>
        s.person === 'self' ? s : root.BureaucracyFamily.current(s);
    function hydrate(s) {
        if (!allowed(s)) return null;
        const record = owner(s);
        const o = record.orientation || {};
        record.orientation = {
            version: 1,
            started: false,
            mode: 'question',
            cursor: 'move_stage',
            answers: {},
            deferred: [],
            draft: null,
            history: [],
            ...o,
        };
        return record.orientation;
    }
    function unanswered(o) {
        return questions.filter((q) => !o.answers[q.key]);
    }
    function entry(s) {
        if (!allowed(s)) return null;
        const o = owner(s).orientation;
        if (!o?.started) return 'Help me get started';
        return o.draft || o.details?.draft || unanswered(o).length
            ? 'Continue where you stopped'
            : 'Update your situation';
    }
    function begin(s) {
        if (root.BureaucracySituation?.applies(s))
            return root.BureaucracySituation.begin(s);
        const o = hydrate(s);
        if (!o) return false;
        const resume =
            o.started &&
            (o.mode === 'question' ||
                (o.mode === 'paused' && o.returnMode === 'question'));
        o.started = true;
        if (o.mode === 'paused') o.mode = o.returnMode || 'question';
        if (resume && questions.some((q) => q.key === o.cursor)) return true;
        if (o.draft) {
            o.cursor = o.draft.key;
            o.mode = 'question';
            return true;
        }
        const missing = unanswered(o);
        if (!missing.length) o.mode = 'review';
        else {
            o.cursor =
                missing.find((q) => !o.deferred.includes(q.key))?.key ||
                missing[0].key;
            o.mode = 'question';
            o.deferred = o.deferred.filter((k) => k !== o.cursor);
        }
        return true;
    }
    function current(s) {
        if (!allowed(s)) return null;
        const o = hydrate(s),
            q = questions.find((q) => q.key === o.cursor);
        if (o.mode !== 'question' || !q) return null;
        return {
            ...q,
            selected:
                o.draft?.key === q.key
                    ? o.draft.value
                    : o.answers[q.key]?.value || null,
        };
    }
    function choose(s, value) {
        const q = current(s);
        if (!q || !q.options.some((x) => x[0] === value)) return false;
        owner(s).orientation.draft = { key: q.key, value };
        return true;
    }
    function advance(o) {
        const next = unanswered(o).find((q) => !o.deferred.includes(q.key));
        o.cursor = next?.key || o.cursor;
        o.mode = next ? 'question' : 'review';
    }
    function confirm(s) {
        const q = current(s),
            o = allowed(s) ? owner(s).orientation : null;
        if (!q || !q.selected || !q.options.some((x) => x[0] === q.selected))
            return false;
        const previous = o.answers[q.key],
            at = new Date().toISOString();
        if (previous && previous.value !== q.selected)
            o.history.push({ key: q.key, previous, at });
        o.answers[q.key] = {
            value: q.selected,
            state: q.selected === 'unknown' ? 'unknown' : 'value',
            source: 'sample orientation',
            at,
        };
        // Your answer to the arrival question itself, so it goes into your
        // details as you gave it.
        if (q.fact && o.details) {
            o.details.facts[q.fact] = arrivalFact(o.answers[q.key]);
            o.details.revision++;
        }
        o.deferred = o.deferred.filter((k) => k !== q.key);
        o.draft = null;
        advance(o);
        return true;
    }
    // The details fact for an answer to the arrival question.
    const arrivalFact = (answer) => ({
        value:
            answer.value === 'unknown'
                ? 'unknown'
                : answer.value === 'planning',
        state: answer.value === 'unknown' ? 'unknown' : 'value',
        source: 'Your answer',
        at: answer.at,
    });
    function defer(s) {
        const q = current(s);
        if (!q) return false;
        const o = owner(s).orientation;
        if (!o.answers[q.key] && !o.deferred.includes(q.key))
            o.deferred.push(q.key);
        o.draft = null;
        advance(o);
        return true;
    }
    function edit(s, key) {
        const o = hydrate(s);
        if (!o || !questions.some((q) => q.key === key)) return false;
        o.cursor = key;
        o.draft = null;
        o.mode = 'question';
        return true;
    }
    function pause(s) {
        const o = hydrate(s);
        if (!o) return false;
        if (o.mode !== 'paused') {
            o.returnMode = o.mode;
            o.mode = 'paused';
        }
        return true;
    }
    function review(s) {
        const o = hydrate(s);
        if (!o) return false;
        o.mode = 'review';
        return true;
    }
    function load(storage = root.localStorage) {
        let saved;
        try {
            saved = JSON.parse(storage.getItem(key));
        } catch {}
        const s = {
            ...(saved
                ? root.BureaucracyPreview?.fresh()
                : root.BureaucracyPreview?.blank?.() ||
                  root.BureaucracyPreview?.fresh() || { person: 'self' }),
            ...saved,
        };
        hydrate(s);
        return s;
    }
    function save(s, storage = root.localStorage) {
        if (!allowed(s)) return false;
        try {
            const latest =
                JSON.parse(storage.getItem(key)) ||
                JSON.parse(JSON.stringify(s));
            if (latest.person !== s.person || !allowed(latest)) return false;
            if ((latest.profileId || null) !== (s.profileId || null))
                return false;
            const previous = owner(latest).orientation;
            const next = owner(s).orientation;
            if ((previous?.writeRevision || 0) !== (next?.writeRevision || 0))
                return false;
            if (
                previous?.details &&
                next?.details &&
                previous.details.revision >= next.details.revision &&
                JSON.stringify(previous.details.facts) !==
                    JSON.stringify(next.details.facts)
            )
                return false;
            const expiry = next?.details?.facts.visa_expires_at;
            if (
                s.person === 'self' &&
                expiry?.source === 'Confirmed in sample' &&
                expiry.at !== previous?.details?.facts.visa_expires_at?.at
            ) {
                const value = expiry.state === 'value' ? expiry.value : null;
                if (root.BureaucracyPreview?.confirmExpiry(latest, value)) {
                    s.expiry = latest.expiry;
                    s.docs = [...latest.docs];
                }
            }
            next.writeRevision = (next.writeRevision || 0) + 1;
            owner(latest).orientation = JSON.parse(JSON.stringify(next));
            storage.setItem(key, JSON.stringify(latest));
            return true;
        } catch {
            return false;
        }
    }
    const api = {
        key,
        allowed,
        arrivalFact,
        questions,
        hydrate,
        entry,
        begin,
        current,
        choose,
        confirm,
        defer,
        edit,
        pause,
        review,
        load,
        save,
    };
    root.BureaucracyOrientation = api;
    if (typeof module !== 'undefined') module.exports = api;
})(globalThis);
