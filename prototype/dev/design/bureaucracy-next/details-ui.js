(function (root) {
    const D = root.BureaucracyDetails;
    const E = (v) =>
        String(v ?? '').replace(
            /[&<>"']/g,
            (c) =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                })[c],
        );
    const I = (name) => root.ExpaduIcon(name);
    const button = (label, action, primary = false, disabled = false) =>
        `<button type="button" class="${primary ? 'orientation-primary' : 'orientation-secondary'}" data-detail="${action}" ${disabled ? 'disabled' : ''}>${label}</button>`;
    const option = (key, value, label, selected, hint = '') =>
        `<button type="button" data-detail-value="${E(JSON.stringify(value))}" aria-pressed="${selected === value}"><span><strong>${E(label)}</strong>${hint ? `<small>${E(hint)}</small>` : ''}</span><span class="orientation-check">${I('check')}</span></button>`;
    function markup(r, name, options = {}) {
        const f = D.field(r.current),
            draft = r.draft;
        const value = (v) => E(D.label(r.current, v));
        let body = '';
        if (r.mode === 'summary') {
            const remaining = D.available(r).some(
                (f) => !r.facts[f.key] && !r.deferred.includes(f.key),
            );
            body = `<h2 tabindex="-1">Your details, at your pace.</h2><p class="orientation-intro">Answer what you know. Come back to anything else.${D.lastChecked(r) ? ` Last checked ${E(new Date(D.lastChecked(r)).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }))}.` : ''}</p>${r.notice ? `<p class="details-notice" role="status">${I('check')}${E(r.notice)}</p>` : ''}<div class="details-facts">${D.listed(
                r,
            )
                .map(
                    (f) =>
                        `<button type="button" data-detail-edit="${f.key}"><span class="details-fact-icon">${I(f.icon)}</span><span><small>${E(f.label)}</small><strong>${E(r.conflicts[f.key] ? 'Answers need reviewing' : r.facts[f.key] ? D.label(f.key, r.facts[f.key].value) : r.deferred.includes(f.key) ? 'Left for later' : 'Not answered')}</strong></span>${I(r.conflicts[f.key] ? 'info' : r.facts[f.key] ? 'pencil' : 'plus')}</button>`,
                )
                .join(
                    '',
                )}</div>${remaining ? `<div class="orientation-actions"><span class="details-soft">One question at a time</span>${button('Continue', 'next', true)}</div>` : '<p class="details-soft">You can keep using the app with unanswered questions.</p>'}${history(r)}<details class="details-demo"><summary>Preview states ${I('down')}</summary><p>Synthetic answers for reviewing this interaction.</p>${button('Try conflicting answers', 'example-conflict')}</details>`;
        } else if (r.mode === 'reason') {
            body = `<h2 tabindex="-1">What changed?</h2><p class="orientation-intro">${E(f.label)} · ${value(r.facts[f.key]?.value)}</p><div class="orientation-options details-reasons"><button type="button" data-detail-reason="correct">${I('pencil')}<span><strong>Correct an earlier answer</strong><small>It wasn’t right when I entered it.</small></span>${I('arrow')}</button><button type="button" data-detail-reason="change">${I('clock')}<span><strong>My situation changed</strong><small>Keep the previous answer in my history.</small></span>${I('arrow')}</button></div><div class="orientation-actions">${button('Cancel', 'cancel')}</div>`;
        } else if (r.mode === 'answer' || r.mode === 'conflict') {
            const conflict = r.mode === 'conflict';
            body = `<h2 tabindex="-1">${conflict ? 'Which answer is correct now?' : E(f.title)}</h2><p class="orientation-intro">${conflict ? 'These sample answers disagree. Neither has been chosen for you.' : E(f.why)}</p>${conflict ? `<div class="details-claims">${r.conflicts[r.current].map((c) => `<div><small>${E(c.source)}</small><strong>${value(c.value)}</strong></div>`).join('')}</div>` : ''}${f.type === 'date' ? `<label class="details-date">${E(f.label)}<input type="date" data-detail-date value="${/^\d{4}-/.test(draft.value || '') ? E(draft.value) : ''}"></label>` : f.type === 'integer' ? `<label class="details-date">${E(f.label)}<input type="number" inputmode="numeric" min="0" step="1" data-detail-number value="${Number.isInteger(draft.value) ? draft.value : ''}"></label>` : `<div class="orientation-options details-options">${f.options.map(([v, label]) => option(f.key, v, label, draft.value)).join('')}</div>`}<div class="details-uncertain">${option(f.key, 'unknown', 'I’m not sure', draft.value)}${option(f.key, 'declined', 'Prefer not to say', draft.value)}${f.allowsNotApplicable ? option(f.key, 'not_applicable', 'Doesn’t apply to me', draft.value) : ''}</div>${draft.operation === 'change' ? `<label class="details-date details-effective">When did this change? <small>Optional</small><input type="date" data-detail-effective value="${E(draft.effectiveFrom || '')}"></label>` : ''}<div class="orientation-actions">${button(r.facts[r.current] || conflict ? 'Cancel' : 'Skip for now', r.facts[r.current] || conflict ? 'cancel' : 'skip')}${button(options.initial ? 'Continue' : 'Review answer', 'review', true, draft.value === null)}</div>`;
        } else if (r.mode === 'confirm') {
            body = `<h2 tabindex="-1">Does this look right?</h2><p class="orientation-intro">${E(f.label)}</p><div class="details-comparison">${r.facts[r.current] ? `<div><small>Previously recorded</small><strong>${value(r.facts[r.current].value)}</strong></div>` : ''}<div class="details-proposed"><small>${draft.operation === 'resolve' ? 'Your answer now' : draft.operation === 'change' ? 'New situation' : draft.operation === 'correct' ? 'Corrected answer' : 'Your answer'}</small><strong>${value(draft.value)}</strong>${draft.effectiveFrom ? `<small>From ${E(D.label('visa_expires_at', draft.effectiveFrom))}</small>` : ''}</div></div><p class="details-soft">Saved only for ${name === 'You' ? 'you' : E(name)}. ${draft.operation !== 'assert' ? 'The earlier record stays in history.' : 'Nothing is sent to an authority.'}</p><div class="orientation-actions">${button('Edit', 'back')}${button('Confirm answer', 'confirm', true)}</div>`;
        } else {
            body = `<h2 tabindex="-1">This answer has changed.</h2><p class="orientation-intro">Review the latest saved answer before making another change. Your draft has not replaced it.</p>${button('Review latest answer', 'cancel', true)}`;
        }
        if (options.bodyOnly) return body;
        return `<div class="details-review"><button type="button" class="details-back" data-detail="close">${I('back')}Getting started</button><div class="details-person">${I('people')}For ${E(name)}<small>Sample</small></div>${body}</div>`;
    }
    function history(r) {
        if (!r.history.length) return '';
        return `<details class="details-history"><summary>${I('clock')}Answer history ${I('down')}</summary>${r.history.map((h) => `<div><strong>${E(D.field(h.key).label)}</strong><small>${E({ assert: 'Answer confirmed', correct: 'Earlier answer corrected', change: 'Situation changed', resolve: 'Conflicting answers reviewed' }[h.operation])}</small>${h.previous ? `<p>Before: ${E(D.label(h.key, h.previous.value))}</p>` : ''}<p>Confirmed: ${E(D.label(h.key, h.value))}</p>${h.effectiveFrom ? `<p>Effective from ${E(D.label('visa_expires_at', h.effectiveFrom))}</p>` : ''}${h.claims.length ? `<p>Both earlier sample claims are retained.</p>` : ''}</div>`).join('')}</details>`;
    }
    function action(r, b) {
        if (b.dataset.detailEdit) return D.edit(r, b.dataset.detailEdit);
        if (b.dataset.detailReason) return D.reason(r, b.dataset.detailReason);
        if (b.hasAttribute('data-detail-value'))
            return D.select(r, JSON.parse(b.dataset.detailValue));
        switch (b.dataset.detail) {
            case 'close':
                r.open = false;
                return true;
            case 'next':
                return D.begin(r);
            case 'cancel':
                return D.cancel(r);
            case 'skip':
                return D.skip(r);
            case 'review':
                return D.review(r);
            case 'confirm':
                return D.confirm(r) || r.mode === 'stale';
            case 'back':
                r.mode =
                    r.draft.operation === 'resolve' ? 'conflict' : 'answer';
                return true;
            case 'example-conflict':
                return D.exampleConflict(r);
        }
        return false;
    }
    root.BureaucracyDetailsUI = { markup, action, history };
})(globalThis);
