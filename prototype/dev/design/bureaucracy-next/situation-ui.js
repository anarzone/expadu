(function (root) {
    const J = root.BureaucracySituation,
        D = root.BureaucracyDetails,
        G = root.BureaucracyOrientation;
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
    const I = (n) => root.ExpaduIcon(n);
    const button = (label, action, icon = '', primary = false) =>
        `<button type="button" class="${primary ? 'orientation-primary' : 'orientation-secondary'}" data-situation="${action}">${icon ? I(icon) : ''}${label}</button>`;
    const shortDate = (value) =>
        value
            ? new Date(value).toLocaleDateString('en-GB', {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              })
            : 'Not checked yet';
    function facts(s, compact = false) {
        const r = G.hydrate(s).details,
            j = J.state(s);
        // What the backend can list (your answers, what you left for later,
        // the current question); the checkpoint shows what you've shared.
        // This run's answers, oldest first (the history is newest first).
        const run = D.given(r) - (s.interview?.mark || 0);
        const keys = compact
            ? [
                  ...new Set(
                      r.history
                          .filter((h) => !h.seed)
                          .slice(0, Math.max(0, run))
                          .map((h) => h.key)
                          .reverse(),
                  ),
              ]
            : [...new Set([...D.listed(r).map((f) => f.key), ...j.review])];
        return `<div class="situation-facts">${keys
            .map((key) => {
                const f = D.field(key),
                    fact = r.facts[key],
                    review = j.review.includes(key);
                return `<div class="situation-fact ${review ? 'needs-review' : ''}"><button type="button" data-situation-edit="${key}"><span class="situation-symbol">${I(f.icon)}</span><span><small>${E(f.label)}</small><strong>${E(D.label(key, fact?.value))}</strong></span>${I(fact ? 'pencil' : 'plus')}</button>${review ? `<div class="situation-fact-review"><span>Check after your change</span>${fact ? `<button type="button" data-situation-keep="${key}">Still correct</button>` : ''}</div>` : ''}</div>`;
            })
            .join('')}</div>`;
    }
    // The tasks your answers just opened, before you've looked at them.
    function arrived(s) {
        const names = (root.BureaucracyPlan?.openedBy(s) || [])
            .filter((id) => !s.seenTasks?.includes(id))
            .map((id) => root.BureaucracyContract?.titleOf(id))
            .filter(Boolean);
        if (!names.length) return '';
        const list =
            names.length > 1
                ? `${names.slice(0, -1).join(', ')} and ${names.at(-1)}`
                : names[0];
        return `<p class="situation-arrived">${I('arrow')}<span>${E(list)} ${names.length > 1 ? 'are' : 'is'} now in Your tasks, from your answers.</span></p>`;
    }
    function intro() {
        return `<section class="situation-welcome"><div class="situation-welcome-copy"><span class="situation-emblem">${I('document')}</span><h2>A little clarity.<br>One step at a time.</h2><p>Find your next steps, keep documents together and remember important dates.</p><div class="situation-benefits"><span>${I('arrow')}Next steps</span><span>${I('document')}Documents</span><span>${I('calendar')}Dates</span></div></div><div class="situation-start"><p>A few questions to find your starting point. Skip anything you’re unsure about.</p>${button('Get started', 'start', 'arrow', true)}${button('Browse first', 'browse', 'grid')}${button('Start in Composer', 'composer-start', 'chat')}</div></section>`;
    }
    // On the overview (compact), your situation is one line that opens in
    // place, so your plan comes first. It starts open when something needs
    // you (a check-in, answers to review, a just-saved change) or when the
    // plan has nothing else to show yet (expand).
    function summary(s, compact = false, expand = false) {
        const j = J.state(s),
            status = J.status(s),
            r = G.hydrate(s).details,
            checked = D.lastChecked(r) || j.lastChecked,
            badge = `<span class="situation-status ${status}">${I(status === 'review' ? 'info' : status === 'current' ? 'check' : 'document')}${{ review: 'Needs review', incomplete: 'Some details missing', check: 'Time to check', current: 'Answers checked' }[status]}</span>`;
        const body = `${j.notice ? `<p class="situation-notice" role="status">${I('check')}${E(j.notice)}</p>` : ''}${status === 'check' ? `<div class="situation-checkin"><strong>Anything changed since your last visit?</strong><p>Work, home, residence or family — a quick check keeps this useful.</p><div>${button('Nothing changed', 'unchanged', 'check')}${button('Something changed', 'changes', 'pencil')}</div></div>` : ''}${facts(s)}<div class="situation-summary-actions">${status !== 'check' ? button('Something changed', 'changes', 'pencil') : ''}${J.questions(s).some((k) => !G.hydrate(s).details.facts[k]) ? button('Continue questions', 'start', 'arrow') : ''}</div>${j.review.length ? `<p class="situation-note">${I('info')}Your previous answers are kept until you confirm an update. Your tracked tasks may also need a review.</p>` : ''}${j.changes.length ? `<details class="situation-history"><summary>${I('clock')}Reported changes ${I('down')}</summary>${j.changes.map((c) => `<div><strong>${I(J.changes[c.kind].icon)}${E(J.changes[c.kind].label)}</strong><small>${c.effectiveFrom ? 'From ' + shortDate(c.effectiveFrom) : 'Reported ' + shortDate(c.reportedAt)}</small></div>`).join('')}</details>` : ''}${root.BureaucracyDetailsUI.history(G.hydrate(s).details)}`;
        if (compact) {
            const count = Object.keys(r.facts).length;
            const open =
                expand ||
                ['check', 'review'].includes(status) ||
                j.notice ||
                j.review.length;
            const said =
                status === 'current' && checked
                    ? `Last checked ${shortDate(checked)}`
                    : {
                          review: 'Needs review',
                          incomplete: 'Some details missing',
                          check: 'Time to check',
                          current: 'Answers checked',
                      }[status];
            return `<details class="situation-summary situation-compact" ${open ? 'open' : ''}><summary><span class="situation-symbol">${I('sliders')}</span><span class="situation-compact-copy"><strong>Your situation</strong><small>${count} ${count === 1 ? 'answer' : 'answers'} · ${E(said)}</small></span>${I('down')}</summary><div class="situation-compact-body">${body}</div></details>`;
        }
        return `<section class="situation-summary"><div class="situation-section-head"><div><h2>Your situation</h2><p>${checked ? 'Last checked ' + shortDate(checked) : 'A starting point, at your pace.'}</p></div>${badge}</div>${body}</section>`;
    }
    // Topics you might explore: not the ones already in your plan or
    // waiting for your arrival, so nothing is listed twice.
    function topics(s) {
        const facts = G.hydrate(s).details?.facts || {};
        const listed = [
            ...(root.BureaucracyPlan?.openedBy(s) || []),
            ...(s.tracked || []),
            ...(root.BureaucracyRegistry?.afterArrival(facts) || []),
        ];
        const items = [
            ['address', 'pin', 2],
            ['health', 'health', 3],
            ['bank', 'bank', 4],
            ['blue-card', 'document', 0],
        ].filter(([id]) => !listed.includes(id));
        if (!items.length) return '';
        return `<section class="situation-topics"><div class="situation-section-head"><div><h2>Explore a topic</h2><p>Choose what you want to keep track of.</p></div></div><div class="situation-topic-grid">${items.map(([id, icon, index]) => `<article><span class="situation-symbol">${I(icon)}</span><strong>${E({ address: 'Address registration', health: 'Health insurance', bank: 'Bank account', 'blue-card': 'EU Blue Card' }[id])}</strong><div><a href="${E(root.BureaucracySource[index].source)}" target="_blank" rel="noopener">Official information ${I('arrow')}</a><button type="button" data-situation-topic="${id}" ${s.tracked?.includes(id) ? 'disabled' : ''}>${I(s.tracked?.includes(id) ? 'check' : 'plus')}${s.tracked?.includes(id) ? 'Tracking' : 'Track task'}</button></div></article>`).join('')}</div><p class="situation-note">Each card is checked against the official pages it quotes. Exploring a topic does not mean it applies to you.</p></section>`;
    }
    function page(s, expand = false) {
        return G.hydrate(s).started ? summary(s, true, expand) : intro();
    }
    function guide(s) {
        const j = J.state(s),
            r = G.hydrate(s).details;
        if (j.stage === 'question')
            return `<div class="situation-progress"><span>${j.origin === 'starter' ? 'Your starting point' : 'Review an answer'}</span>${j.origin === 'starter' ? `<span>${Math.min(j.batch + 1, 3)} of 3</span>` : ''}</div>${root.BureaucracyDetailsUI.markup(
                r,
                'You',
                { bodyOnly: true, initial: j.origin === 'starter' },
            )}`;
        if (j.stage === 'checkpoint')
            return `<span class="situation-emblem small">${I('check')}</span><h2>A starting point for you.</h2><p class="orientation-intro">You’ve answered a few questions. The rest can wait until you’re ready.</p>${facts(s, true)}${arrived(s)}<div class="orientation-actions">${J.questions(s).some((k) => !r.facts[k]) ? button('Keep going', 'start', '') : ''}${button('See my overview', 'overview', 'arrow', true)}</div><p class="situation-note">Your answers help choose the next questions and which tasks show. They do not establish eligibility.</p>`;
        if (j.stage === 'change-picker')
            return `<h2>What’s changed?</h2><p class="orientation-intro">Choose one to review. Your saved answers stay as they are until you confirm.</p><div class="situation-change-grid">${Object.entries(
                J.changes,
            )
                .map(
                    ([key, c]) =>
                        `<button type="button" data-situation-change="${key}"><span class="situation-symbol">${I(c.icon)}</span><span><small>${c.group}</small><strong>${c.label}</strong></span>${I('arrow')}</button>`,
                )
                .join(
                    '',
                )}</div><div class="orientation-actions">${button('Back to my situation', 'summary', 'back')}</div>`;
        if (j.stage === 'change-review') {
            const draft = j.changeDraft,
                c = J.changes[draft.kind];
            return `<h2>Record this change?</h2><p class="orientation-intro">Your existing answers stay available for review.</p><div class="situation-change-card">${I(c.icon)}<div><small>${E(c.group)}</small><strong>${E(c.label)}</strong></div></div><label class="details-date">When did this happen? <small>Optional</small><input type="date" data-situation-date value="${E(draft.effectiveFrom || '')}"></label><p class="situation-note">After confirmation, we’ll highlight related answers for you to check. No permit status or task will change automatically.</p><div class="orientation-actions">${button('Cancel', 'cancel-change')}${button('Confirm change', 'confirm-change', 'check', true)}</div>`;
        }
        return (
            summary(s) +
            `<div class="orientation-actions">${button('Back to overview', 'overview', 'back')}</div>`
        );
    }
    root.BureaucracySituationUI = { page, guide, topics };
})(globalThis);
