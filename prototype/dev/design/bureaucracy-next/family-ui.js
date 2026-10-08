(function (root) {
    const H = root.BureaucracyFamily;
    const E = (v) => root.ExpaduUI.esc(v);
    const I = (v) => root.ExpaduUI.icon(v);
    const statuses = {
        missing: 'Not ready yet',
        reported_available: 'Available · check for this task',
        confirmed_for_use: 'Ready for this task',
        needs_reconfirmation: 'Check again',
    };
    function access(s) {
        const p = H.info(s),
            record = s.household?.[s.person];
        return `<details class="family-access"><summary>${I('people')}Access & sharing ${I('down')}</summary><p>${p.kind === 'adult' ? 'An adult chooses who can see their plan and who can make changes.' : 'A dependent’s plan requires confirmed guardian access.'}</p><ul><li>Plan ${record?.access === 'read' ? 'viewing only' : 'and progress'}</li><li>${record?.access === 'read' ? 'Personal answers stay private' : 'Answers saved only for ' + E(p.name)}</li><li>Documents stay separate for each person and task</li></ul><div class="family-preview"><label>Sample access state<select data-family-access aria-label="Sample access state"><option value="edit" ${record?.access === 'edit' ? 'selected' : ''}>${p.kind === 'adult' ? 'Shared with edit permission' : 'Guardian access confirmed'}</option><option value="read" ${record?.access === 'read' ? 'selected' : ''}>View plan only</option><option value="pending" ${record?.access === 'pending' ? 'selected' : ''}>Review pending</option><option value="revoked" ${record?.access === 'revoked' ? 'selected' : ''}>Access removed</option></select></label><small>Preview only. This does not grant or change anyone’s access.</small></div></details>`;
    }
    function render(s, sources) {
        const p = H.info(s),
            record = H.current(s);
        if (!p || p.record === undefined) return '';
        if (!record)
            return `<section class="family-locked"><span class="family-portrait">${I('people')}</span><h2>${s.household[p.id].access === 'revoked' ? 'Access is no longer available.' : 'Access is being reviewed.'}</h2><p>${E(p.name)}’s plan and personal details stay hidden.</p><button class="button secondary" data-person="self">Back to your plan</button></section>${access(s)}`;
        const editable = H.canEdit(s),
            src = sources[p.record],
            ready = record.documents.filter(
                (x) => x === 'confirmed_for_use',
            ).length;
        const identity = `<section class="family-identity"><span class="family-portrait">${E(p.initial)}</span><div><h2>${E(p.name)}’s plan</h2><p>${record.access === 'read' ? 'Shared with you · view only' : p.kind === 'adult' ? 'Shared with you · editing allowed' : 'Guardian access · sample'}</p></div></section>`;
        const papers = `<section class="family-paperwork"><div class="case-section-head"><h3>Documents for ${E(p.task.toLowerCase())}</h3>${record.documents.length ? `<span>${ready}/${record.documents.length}</span>` : ''}</div>${src.documents.length ? src.documents.map((label, i) => `<details class="family-document" data-family-paper="${i}"><summary>${I('document')}<span><strong>${E(label)}</strong><small>${statuses[record.documents[i]]}</small></span>${I('down')}</summary><div><p>For ${E(p.name)} · ${E(p.task)}</p>${editable ? `<div class="family-doc-actions">${['missing', 'unknown'].includes(record.documents[i]) ? `<button data-family-doc="${i}|have">${I('plus')}I have this</button>` : record.documents[i] !== 'confirmed_for_use' ? `<button data-family-doc="${i}|confirm">${I('check')}Ready for this task</button>` : `<span>${I('check')}Confirmed for this task</span>`}${record.documents[i] !== 'missing' ? `<button data-family-doc="${i}|missing">Not ready yet</button>` : ''}</div>` : '<p>Viewing only. Editing permission is not included.</p>'}</div></details>`).join('') : '<p class="muted">This task lists no documents.</p>'}</section>`;
        const overview = `<div class="family-overview"><section class="family-next"><div class="case-section-head"><h3>${record.workflow === 'waiting' ? 'Waiting' : 'Next step'}</h3>${I(record.workflow === 'waiting' ? 'clock' : 'document')}</div><h2>${E(p.task)}</h2><p>${record.workflow === 'waiting' ? 'A response is pending in this sample. You can still review the documents.' : 'Review the documents already recorded for this task.'}</p>${editable ? `<button class="button primary" data-view="paperwork">${I('document')}Review documents</button>` : '<span class="state-badge">Documents aren’t shared</span>'}<a class="family-source" href="${E(src.source)}" target="_blank" rel="noopener">Official information ${I('arrow')}</a><small>Sample progress · current guidance needs checking</small></section><section class="family-personal"><span class="family-small-icon">${I('people')}</span><h3>A separate space for ${E(p.name)}.</h3><p>Answers and documents here belong to ${E(p.name)}. Your own plan stays as you left it.</p>${editable ? `<button class="button secondary" data-case="get-started">Review ${E(p.name)}’s answers ${I('arrow')}</button>` : '<span class="state-badge">Personal answers are private</span>'}</section></div>`;
        return `${identity}${s.view === 'paperwork' ? (editable ? papers : '<section class="family-locked"><h2>Documents aren’t shared.</h2><p>You can view this plan. Document and answer access are separate.</p><button class="button secondary" data-view="overview">Back to overview</button></section>') : overview}${access(s)}`;
    }
    root.BureaucracyFamilyUI = { render };
})(globalThis);
