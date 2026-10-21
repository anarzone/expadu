/* Interaction fixtures. Questions and values are the backend registry's
   (registry.js). No eligibility or legal date is derived here. */
(function (root) {
    // Every registry question, in its own words (registry.js).
    const fields = root.BureaucracyRegistry.fields;
    const copy = (v) => JSON.parse(JSON.stringify(v));
    const field = (key) => fields.find((f) => f.key === key);
    const date = (v) =>
        typeof v === 'string' &&
        /^\d{4}-\d{2}-\d{2}$/.test(v) &&
        Number.isFinite(Date.parse(v)) &&
        new Date(v).toISOString().slice(0, 10) === v;
    const valid = (f, v) =>
        f &&
        (['unknown', 'declined'].includes(v) ||
            (v === 'not_applicable' && f.allowsNotApplicable) ||
            (f.type === 'date'
                ? date(v)
                : f.type === 'integer'
                  ? Number.isInteger(v) && v >= 0
                  : f.options.some(([value]) => value === v)));
    const state = (value) =>
        ['unknown', 'declined', 'not_applicable'].includes(value)
            ? value
            : 'value';
    // Known answers, with their history (newest first) when there is one:
    // each answer was confirmed at its latest history entry.
    function create(known = {}, history = []) {
        return {
            open: false,
            mode: 'summary',
            current: null,
            draft: null,
            revision: 0,
            deferred: [],
            history: copy(history),
            conflicts: {},
            facts: Object.fromEntries(
                Object.entries(known)
                    .filter(([k, v]) => valid(field(k), v))
                    .map(([k, value]) => [
                        k,
                        {
                            value,
                            state: state(value),
                            source: 'Recorded sample',
                            at: history.find((h) => h.key === k)?.at || null,
                        },
                    ]),
            ),
        };
    }
    // What the backend can list about your answers: the ones you've given
    // (in the registry's order), the ones you left for later and the
    // current question. Never answers it guesses might apply.
    function listed(r) {
        const current = available(r).find(
            (f) => !r.facts[f.key] && !r.deferred.includes(f.key),
        );
        return [
            ...new Set([
                ...fields.filter((f) => r.facts[f.key]).map((f) => f.key),
                ...r.deferred,
                ...(current ? [current.key] : []),
                ...Object.keys(r.conflicts),
            ]),
        ].map(field);
    }
    // Answers given in this preview (not the sample's earlier ones): a run
    // of questions counts these.
    const given = (r) => r.history.filter((h) => !h.seed).length;
    // When you last confirmed an answer (facts' checked_at).
    function lastChecked(r) {
        return (
            Object.values(r.facts)
                .map((f) => f.at)
                .filter(Boolean)
                .sort()
                .at(-1) || null
        );
    }
    // The questions that concern this person now, in the order they'd be
    // asked (registry.js: the backend's orientation, then its priorities).
    function available(r) {
        return root.BureaucracyRegistry.questions(r.facts, r.deferred).map(
            field,
        );
    }
    function begin(r) {
        r.open = true;
        if (
            r.draft &&
            ['answer', 'reason', 'confirm', 'conflict'].includes(r.mode)
        )
            return true;
        const next = available(r).find(
            (f) => !r.facts[f.key] && !r.deferred.includes(f.key),
        );
        if (next) return edit(r, next.key);
        r.mode = 'summary';
        return true;
    }
    function edit(r, key) {
        if (!field(key)) return false;
        r.notice = null;
        r.open = true;
        r.current = key;
        if (r.conflicts[key]) {
            r.mode = 'conflict';
            r.draft = {
                value: null,
                operation: 'resolve',
                revision: r.revision,
            };
        } else {
            r.mode = r.facts[key] ? 'reason' : 'answer';
            r.draft = {
                value: r.facts[key]?.value ?? null,
                operation: r.facts[key] ? null : 'assert',
                revision: r.revision,
            };
        }
        return true;
    }
    function reason(r, operation) {
        if (r.mode !== 'reason' || !['correct', 'change'].includes(operation))
            return false;
        r.draft.operation = operation;
        r.mode = 'answer';
        return true;
    }
    function select(r, value) {
        if (
            !['answer', 'conflict'].includes(r.mode) ||
            !valid(field(r.current), value)
        )
            return false;
        r.draft.value = value;
        return true;
    }
    function effective(r, value) {
        if (r.draft?.operation !== 'change' || (value && !date(value)))
            return false;
        r.draft.effectiveFrom = value || null;
        return true;
    }
    function review(r) {
        if (
            !['answer', 'conflict'].includes(r.mode) ||
            !r.draft?.operation ||
            !valid(field(r.current), r.draft.value)
        )
            return false;
        r.mode = 'confirm';
        return true;
    }
    function confirm(r) {
        if (
            r.mode !== 'confirm' ||
            !r.draft?.operation ||
            !valid(field(r.current), r.draft.value)
        )
            return false;
        if (r.draft.revision !== r.revision) {
            r.mode = 'stale';
            return false;
        }
        const { value, operation, effectiveFrom = null } = r.draft;
        // "Now" on the preview's sample day, so dates match Today.
        const at = root.ExpaduSampleDay
            ? `${root.ExpaduSampleDay.date}T${root.ExpaduSampleDay.time}:00`
            : new Date().toISOString();
        r.history.unshift({
            key: r.current,
            previous: copy(r.facts[r.current] || null),
            value,
            operation,
            effectiveFrom,
            at,
            claims: copy(r.conflicts[r.current] || []),
        });
        r.facts[r.current] = {
            value,
            state: state(value),
            source: 'Confirmed in sample',
            effectiveFrom,
            at,
        };
        delete r.conflicts[r.current];
        r.deferred = r.deferred.filter((key) => key !== r.current);
        r.revision++;
        r.draft = null;
        r.mode = 'summary';
        r.notice = 'Answer saved for this person.';
        return true;
    }
    function cancel(r) {
        r.draft = null;
        r.mode = 'summary';
        return true;
    }
    function skip(r) {
        if (!r.facts[r.current] && !r.deferred.includes(r.current))
            r.deferred.push(r.current);
        return cancel(r);
    }
    function exampleConflict(r) {
        r.conflicts.registration_status = [
            // Two confirmed answers, each with when it was given (the
            // backend's fact-conflicts choices carry recorded_at).
            {
                value: 'registered',
                source: 'Your answer on 2 Sept',
                recordedAt: '2026-09-02T18:20:00+02:00',
            },
            {
                value: 'not_registered',
                source: 'Your answer on 28 Sept',
                recordedAt: '2026-09-28T19:05:00+02:00',
            },
        ];
        return edit(r, 'registration_status');
    }
    // AI fact extraction, scripted for the preview: your own words for
    // this one question become a candidate answer, which is never saved
    // unless you confirm it (POST .../extract, then confirm or dismiss).
    // Dates only, the one example here.
    const months = [
        'january',
        'february',
        'march',
        'april',
        'may',
        'june',
        'july',
        'august',
        'september',
        'october',
        'november',
        'december',
    ];
    function suggest(r, text) {
        const f = field(r.current);
        if (!['answer', 'conflict'].includes(r.mode) || f?.type !== 'date')
            return false;
        const t = String(text).toLowerCase();
        let day, month, year;
        const iso = t.match(/(\d{4})-(\d{1,2})-(\d{1,2})/);
        const dotted = t.match(/\b(\d{1,2})[./](\d{1,2})[./](\d{4})\b/);
        const named =
            t.match(
                /\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?([a-z]+)(?:\s+(\d{4}))?/,
            ) || null;
        const namedFirst = t.match(
            /\b([a-z]+)\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?/,
        );
        if (iso) [, year, month, day] = iso.map(Number);
        else if (dotted) [, day, month, year] = dotted.map(Number);
        else if (named && months.includes(named[2]))
            [day, month, year] = [
                +named[1],
                months.indexOf(named[2]) + 1,
                named[3] && +named[3],
            ];
        else if (namedFirst && months.includes(namedFirst[1]))
            [day, month, year] = [
                +namedFirst[2],
                months.indexOf(namedFirst[1]) + 1,
                namedFirst[3] && +namedFirst[3],
            ];
        const today =
            root.ExpaduSampleDay?.date || new Date().toISOString().slice(0, 10);
        const pad = (n) => String(n).padStart(2, '0');
        if (day && month && !year) {
            year = +today.slice(0, 4);
            if (`${year}-${pad(month)}-${pad(day)}` < today) year += 1;
        }
        const value =
            day && month && year ? `${year}-${pad(month)}-${pad(day)}` : null;
        r.suggestion = date(value) ? { value, text } : { value: null, text };
        return true;
    }
    function useSuggestion(r) {
        if (!r.suggestion?.value || !select(r, r.suggestion.value))
            return false;
        r.suggestion = null;
        return true;
    }
    function dismissSuggestion(r) {
        r.suggestion = null;
        return true;
    }
    function label(key, value) {
        if (value === 'unknown') return 'I’m not sure';
        if (value === 'declined') return 'Prefer not to say';
        if (value === 'not_applicable') return 'Doesn’t apply to me';
        if (value === undefined || value === null) return 'Not answered';
        const f = field(key);
        if (f?.type === 'date' && date(value))
            return new Date(value + 'T12:00:00').toLocaleDateString('en-GB', {
                day: 'numeric',
                month: 'short',
                year: 'numeric',
            });
        if (f?.type === 'integer') return String(value);
        return f?.options.find(([v]) => v === value)?.[1] || String(value);
    }
    root.BureaucracyDetails = {
        fields,
        field,
        create,
        available,
        listed,
        lastChecked,
        given,
        begin,
        edit,
        reason,
        select,
        effective,
        review,
        confirm,
        cancel,
        skip,
        exampleConflict,
        suggest,
        useSuggestion,
        dismissSuggestion,
        label,
    };
})(globalThis);
