(function () {
    const M = ExpaduModel,
        S = ExpaduStore,
        U = ExpaduUI,
        I = U.icon,
        E = U.esc;
    let category = 'doctors',
        drafts = {};
    try {
        const x = JSON.parse(sessionStorage.getItem('expadu-service-briefs'));
        if (x) {
            category = x.category;
            drafts = x.drafts;
        }
    } catch {}
    const requested = new URLSearchParams(location.search).get('service');
    if (requested) category = requested;
    if (!M.categories.some((c) => c.id === category)) category = 'doctors';
    const kept = (S.read().serviceRequests || S.read().draft).find(
        (x) => x.type === 'service' && x.category === category,
    );
    if (kept) drafts[category] = M.clone(kept.requirements);
    const titles = {
        doctors: 'Find a doctor',
        dentists: 'Find a dentist',
        pharmacies: 'Find a pharmacy',
        banks: 'Find a bank',
        tax: 'Find a tax advisor',
        lawyers: 'Find a lawyer',
    };
    document.querySelector('#main').innerHTML =
        `<h1 class="sr-only">Services</h1><div class="services-layout"><nav class="service-choices" aria-label="Service categories">${M.categories.map((c) => `<button class="service-choice" data-service="${c.id}" aria-pressed="${c.id === category}">${I(c.icon)}<span>${c.title}</span></button>`).join('')}</nav><section class="service-content"><div class="service-workspace" id="service-workspace"></div><div id="brief-recap"></div></section></div>`;
    if (new URLSearchParams(location.search).get('process') === 'bank') {
        document
            .querySelector('#main')
            .insertAdjacentHTML(
                'afterbegin',
                '<a class="service-source" href="../bureaucracy-next/?view=detail&process=bank">← Back to your Bank account process</a>',
            );
    }
    function persist() {
        const form = document.querySelector('#service-form');
        if (form) drafts[category] = Object.fromEntries(new FormData(form));
        try {
            sessionStorage.setItem(
                'expadu-service-briefs',
                JSON.stringify({ category, drafts }),
            );
        } catch {}
    }
    function render() {
        const c = M.categories.find((c) => c.id === category),
            d = drafts[category] || {},
            medical = ['doctors', 'dentists'].includes(c.id);
        document.querySelector('#service-workspace').innerHTML =
            `<div class="service-work-head"><span class="service-intent-icon">${I(c.icon)}</span><div><h2>${titles[c.id]}</h2><p>Start with your preferences.</p></div></div>
            <p class="service-availability">${I('info')}<span>Provider search isn’t connected in this preview.</span></p>
            <form class="service-form" id="service-form"><div class="form-grid"><label class="form-field">${I('pin')}Area<input name="area" placeholder="Any area" value="${E(d.area || '')}" maxlength="120"></label><label class="form-field">${I('chat')}Language<select name="language">${['No preference', 'English', 'German', 'Turkish', 'Arabic', 'Other'].map((l) => `<option value="${l}" ${d.language === l ? 'selected' : ''}>${l === 'No preference' ? 'Any' : l}</option>`).join('')}</select></label></div>
            <details class="service-extra" ${d.notes || (d.insurance && d.insurance !== 'Not specified') ? 'open' : ''}><summary><span>${I('sliders')}More preferences</span>${I('down')}</summary><div>${medical ? `<label class="form-field">Insurance<select name="insurance">${['Not specified', 'Public', 'Private', 'Self-pay', 'Need to check'].map((l) => `<option ${d.insurance === l ? 'selected' : ''}>${l}</option>`).join('')}</select></label>` : ''}<label class="form-field">Anything else? <span class="muted">Optional</span><textarea name="notes" maxlength="500" placeholder="${medical ? 'For example, step-free access' : c.id === 'banks' ? 'For example, an English app and a local branch' : 'What would make this service right for you?'}">${E(d.notes || '')}</textarea></label></div></details>
            <div class="service-form-actions"><button class="button primary" type="button" id="discuss-service">${I('spark')}Ask Composer</button><button class="service-keep" type="submit" aria-label="Keep requirements on this device" title="Keep requirements">${I('bookmark')}</button></div><p class="service-footnote">Requirements only · nothing is booked</p></form>
            <button class="service-appointment-entry" id="record-service-appointment"><span class="service-intent-icon">${I('calendar')}</span><span><strong>Already have an appointment?</strong><small>Add its time to your day</small></span>${I('arrow')}</button>`;
        document
            .querySelectorAll('[data-service]')
            .forEach((b) =>
                b.setAttribute(
                    'aria-pressed',
                    String(b.dataset.service === category),
                ),
            );
        document.querySelector('#service-form').oninput = persist;
        document.querySelector('#service-form').onchange = persist;
        document.querySelector('#service-form').onsubmit = (e) => {
            e.preventDefault();
            persist();
            const requirements = drafts[category],
                id = 'service-' + category;
            S.update((s) => {
                const item = {
                    id,
                    type: 'service',
                    title: titles[category],
                    category,
                    icon: c.icon,
                    color: c.color,
                    requirements: M.clone(requirements),
                };
                s.serviceRequests ||= [];
                const old = s.serviceRequests.findIndex(
                    (x) => x.id === item.id,
                );
                if (old >= 0) s.serviceRequests[old] = item;
                else s.serviceRequests.push(item);
            });
            if (!S.isPersistent()) return;
            U.toast('Requirements kept. Nothing has been booked.');
            recap();
        };
        recap();
    }
    function recap() {
        const briefs = S.read().serviceRequests || [];
        document.querySelector('#brief-recap').innerHTML = briefs.length
            ? `<section class="service-requests"><h3>Your requests <span>${briefs.length}</span></h3>${briefs.map((x) => `<button data-service="${E(x.category)}"><span><strong>${E(x.title)}</strong><small>${E([x.requirements.area || 'Any neighbourhood', x.requirements.language].filter(Boolean).join(' · '))}</small></span><span class="request-state">Requirements kept</span>${I('arrow')}</button>`).join('')}</section>`
            : '';
    }
    document.addEventListener('click', (e) => {
        const action = e.target.closest(
            '#discuss-service,#record-service-appointment',
        );
        if (action?.id === 'discuss-service') {
            persist();
            const c = M.categories.find((x) => x.id === category);
            const item = {
                id: 'service-' + category,
                type: 'service',
                title: titles[category],
                category,
                requirements: M.clone(drafts[category] || {}),
                icon: c.icon,
                color: c.color,
            };
            S.update((s) => {
                const previous = s.draft.findIndex((x) => x.id === item.id);
                if (previous >= 0) s.draft[previous] = item;
                else M.add(s, item);
                s.serviceRequests ||= [];
                const index = s.serviceRequests.findIndex(
                    (x) => x.id === item.id,
                );
                if (index >= 0) s.serviceRequests[index] = item;
                else s.serviceRequests.push(item);
            });
            S.go('composer');
            return;
        }
        if (action?.id === 'record-service-appointment') {
            U.open(
                'Record your appointment',
                `<section class="appointment-details"><p>Keep an appointment you’ve already arranged in your day.</p><form id="service-appointment"><label class="form-field">Provider or appointment name<input name="title" maxlength="100" required placeholder="e.g. My dentist appointment"></label><label class="form-field">Location<input name="location" maxlength="140" placeholder="Address or meeting place"></label><label class="check-filter"><input type="checkbox" required> Already arranged with the provider</label><footer><small>No booking is made here</small><button class="button primary" type="submit">Choose time ${I('arrow')}</button></footer></form></section>`,
            );
            document.querySelector('#service-appointment').onsubmit = (
                event,
            ) => {
                event.preventDefault();
                event.target.elements.title.value =
                    event.target.elements.title.value.trim();
                if (!event.target.reportValidity()) return;
                const data = new FormData(event.target);
                ExpaduPlanning.open({
                    id: 'service-appointment-' + crypto.randomUUID(),
                    type: 'appointment',
                    title: data.get('title').trim(),
                    location: data.get('location').trim(),
                    category,
                    sourceType: 'service',
                    sourceId: 'service-' + category,
                    icon: 'calendar',
                    fixed: true,
                    personal: true,
                });
            };
            return;
        }
        const b = e.target.closest('[data-service]');
        if (b) {
            persist();
            category = b.dataset.service;
            render();
            persist();
            window.ExpaduMotion?.reveal(
                document.querySelector('#service-workspace'),
            );
        }
    });
    window.addEventListener('storage', recap);
    render();
})();
