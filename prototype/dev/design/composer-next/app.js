(function () {
    const M = ExpaduModel,
        S = ExpaduStore,
        U = ExpaduUI,
        C = ExpaduContent,
        I = U.icon,
        E = U.esc;
    let response = null;
    const s = S.read();
    document.querySelector('#main').innerHTML =
        `<div class="page-title composer-title"><div><p class="eyebrow">Composer</p><h1>Your day, taking shape.</h1></div><div class="title-aside"><button id="view-saved" class="button secondary">${I('bookmark')}Saved day</button></div></div><div class="composer-layout"><section class="request-workspace" aria-label="Your request"><form id="request-form" class="request-surface"><label for="composer-request">${I('spark')}What do you need today?</label><textarea id="composer-request" maxlength="700" placeholder="A little fresh air, something to do, a hand with everyday life…">${E(s.prompt)}</textarea><div class="actions"><span class="muted">Interactive preview</span><button class="button primary" type="submit">Compose ${I('arrow')}</button></div></form><div class="starter-list" aria-label="Try a request"><button data-prompt="I'd like a little fresh air">A little fresh air</button><button data-prompt="Something to do this evening">Something this evening</button><button data-prompt="I want to play volleyball">Find a game</button></div><details class="brief-controls"><summary>Your preferences ${I('sliders')}</summary><form id="brief-form"><div class="form-grid"><label class="form-field">Date<input type="date" name="date" value="${E(s.brief.date)}"></label><label class="form-field">Getting around<select name="mode">${[
            ['transit', 'Public transport'],
            ['walk', 'Walking'],
            ['bike', 'Cycling'],
        ]
            .map(
                ([k, l]) =>
                    `<option value="${k}" ${s.brief.mode === k ? 'selected' : ''}>${l}</option>`,
            )
            .join(
                '',
            )}</select></label><label class="form-field">Starting point<input name="from" placeholder="Place, address or stop" maxlength="120" value="${E(s.brief.from)}"></label><label class="form-field">Budget<input name="budget" placeholder="No budget set" maxlength="80" value="${E(s.brief.budget)}"></label><label class="form-field form-wide">Must-haves and things to avoid<textarea name="notes" maxlength="500" placeholder="Your requirements stay with your request.">${E(s.brief.notes)}</textarea></label></div><button class="button secondary" type="submit">Keep preferences ${I('check')}</button></form></details><div class="response" id="response" aria-live="polite"></div><div class="bridge-links"><a href="../places-next/">Browse places</a><a href="../events-next/">Find an event</a><a href="../services-next/">Find a service</a><a href="../departures-next/">Plan a journey</a></div></section><section class="plan-workspace" aria-labelledby="plan-heading"><div class="plan-header"><div><h2 id="plan-heading">Your draft</h2><span id="plan-status"></span></div><span class="status-illustration cyan" style="width:47px;height:47px;border-radius:15px">${I('bookmark')}</span></div><div class="plan-content" id="plan-content"></div><div class="plan-footer"><span class="muted" id="save-note">Nothing saved yet</span><button class="button primary" id="save-day">Save my day ${I('check')}</button></div></section></div>`;
    document.body.insertAdjacentHTML(
        'beforeend',
        '<nav class="composer-jump" aria-label="Composer workspace"><a href="#request-form">' +
            I('spark') +
            'Your request</a><a href="#plan-heading">' +
            I('bookmark') +
            'Draft <b data-count></b></a></nav>',
    );
    U.sync();
    function metadata(x) {
        if (x.type === 'event')
            return `${new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(x.date + 'T12:00:00'))} · ${x.start}${x.end ? '–' + x.end : ''}`;
        if (x.type === 'journey')
            return x.date
                ? `${x.date} · ${x.depart || (x.requestedTime ? (x.preferences?.schedule === 'arrive' ? 'Arrive by ' : 'Depart at ') + x.requestedTime : 'Time to choose')}${x.arrive ? '–' + x.arrive : ''}`
                : 'Starting point and time to choose';
        if (x.type === 'appointment')
            return `${new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(x.date + 'T12:00:00'))} · ${x.time}`;
        if (x.time) return `Your time · ${x.time}`;
        return x.type === 'service' ? 'Requirements kept' : 'Time is up to you';
    }
    function subtitle(x) {
        if (x.type === 'event') return x.venue;
        if (x.type === 'appointment')
            return `${x.location || 'Location not set'} · User-recorded preview appointment`;
        if (x.type === 'journey')
            return `${x.from || 'Choose an origin'} → ${x.to || x.title}`;
        if (x.type === 'service')
            return (
                Object.entries(x.requirements || {})
                    .filter(
                        ([, v]) =>
                            v &&
                            !['No preference', 'Not specified'].includes(v),
                    )
                    .map(
                        ([k, v]) =>
                            `${{ area: 'Area', language: 'Language', insurance: 'Insurance' }[k] ? { area: 'Area', language: 'Language', insurance: 'Insurance' }[k] + ': ' : ''}${v}`,
                    )
                    .join(' · ') || 'No preferences set'
            );
        return `${x.label || 'Place'} · ${x.area || ''}`;
    }
    function renderPlan() {
        const st = S.read(),
            items = st.draft;
        document.querySelector('#plan-status').textContent =
            `${items.length} ${items.length === 1 ? 'item' : 'items'} · ${M.dirty(st) ? 'Unsaved changes' : 'Saved on this device'}`;
        document.querySelector('#save-note').textContent = st.saved
            ? M.dirty(st)
                ? 'Saved day stays unchanged'
                : 'Up to date'
            : 'Kept on this device';
        document.querySelector('#save-day').disabled =
            (!items.length && !st.saved) || !M.dirty(st);
        const warning = M.overlaps(items),
            dateMismatch =
                st.brief.date &&
                items.some((x) => x.date && x.date !== st.brief.date);
        document.querySelector('#plan-content').innerHTML = items.length
            ? items
                  .map(
                      (x, i) =>
                          `<article class="plan-item"><span class="plan-icon ${x.color || 'blue'}">${I(x.icon || (x.type === 'journey' ? 'train' : x.type === 'event' ? 'calendar' : 'pin'))}</span><div><span class="item-time">${E(metadata(x))}</span><h3>${E(x.title)}</h3><p>${E(subtitle(x))}</p><div class="item-controls">${['place', 'event'].includes(x.type) ? `<button class="text-button" data-detail="${E(x.id)}">Details</button>` : `<button class="text-button" data-inspect="${E(M.key(x))}">Details</button>`}<button class="text-button" data-edit="${E(M.key(x))}">Edit</button></div></div></article>`,
                  )
                  .join('') +
              `${warning.length ? `<div class="plan-warning"><strong>These events overlap</strong>${warning.map((p) => `<p>${E(p.join(' / '))}</p>`).join('')}</div>` : ''}${dateMismatch ? '<div class="plan-warning">Some captured items are on a different date from your preferences.</div>' : ''}<p class="saving-note">Travel gaps and current availability still need checking.</p>`
            : `<div class="plan-empty"><div class="path-art" aria-hidden="true"><span class="green">${I('pin')}</span><span class="cyan">${I('spark')}</span><span class="peach">${I('calendar')}</span></div><h3>A few good ideas. One day.</h3><p>Keep something you like. Bring it together here.</p><div class="actions"><a class="button secondary" href="../places-next/">Explore places</a><a class="button secondary" href="../events-next/">See events</a></div></div>`;
        C.refresh();
    }
    function renderResponse() {
        if (!response) return;
        const r = response,
            heads = {
                places: 'A little room to breathe.',
                events: 'A few possibilities for your evening.',
                quiet: 'Quietness needs a closer look.',
                sport: 'A place to play, or people to join?',
                service: 'Let’s start with what you need.',
                journey: 'Take the next step.',
                bureaucracy: 'Your paperwork needs a reviewed answer.',
                limits: 'Your requirements come first.',
                clarify: 'Which direction would help?',
                empty: 'What would you like to do?',
            };
        const paragraphs = {
            places: 'Captured places to consider; access and travel still need checking.',
            events: 'From the captured 8 September programme.',
            quiet: 'These listings do not confirm how quiet a place is.',
            sport: 'A sports venue does not tell us whether there is a game to join.',
            service:
                'Keep your language, area and other requirements together.',
            journey:
                'Choose your starting point and destination in Departures.',
            bureaucracy:
                'Choose the person and process in Bureaucracy. This request is not used as a confirmed fact.',
            limits: 'No verified match for these limits in this preview. Your request is kept exactly.',
            clarify: 'Your words are kept. Choose where to look next.',
            empty: 'Type a request or browse for an idea.',
        };
        let buttons = '';
        if (['quiet', 'limits', 'clarify'].includes(r.kind))
            buttons = `<div class="actions"><a class="button secondary" href="../places-next/">Browse places</a><a class="button secondary" href="../events-next/">Browse events</a></div>`;
        if (r.kind === 'sport')
            buttons = `<div class="actions"><button class="button secondary" data-sport="venue">Find a venue</button><button class="button secondary" data-sport="people">Find people to play with</button></div>`;
        if (r.kind === 'service')
            buttons =
                '<a class="button primary" href="../services-next/">Build a service request</a>';
        if (r.kind === 'journey')
            buttons =
                '<a class="button primary" href="../departures-next/">Open Departures</a>';
        if (r.kind === 'bureaucracy')
            buttons =
                '<a class="button secondary" href="../bureaucracy-next/">Open Bureaucracy</a>';
        const st = S.read(),
            candidates = r.items.filter(
                (x) => !st.brief.date || !x.date || x.date === st.brief.date,
            );
        document.querySelector('#response').innerHTML =
            `<p class="eyebrow">Preview response</p><h2>${heads[r.kind]}</h2><p>${paragraphs[r.kind]}</p>${buttons}${r.items.length && !candidates.length ? '<p>No captured occurrences on your selected date.</p>' : ''}${candidates.map((x) => `<div class="idea-row"><button class="idea-symbol ${x.color}" data-detail="${x.id}" aria-label="View ${E(x.title)}">${I(x.icon)}</button><div><h3>${E(x.title)}</h3><p>${E(x.type === 'event' ? x.start + ' · ' + x.venue : x.area)}</p></div><button class="icon-button" data-select="${x.id}" aria-label="Add or remove ${E(x.title)} from draft" aria-pressed="${C.has(x)}">${I(C.has(x) ? 'check' : 'plus')}</button></div>`).join('')}`;
    }
    function compose() {
        const value = document.querySelector('#composer-request').value.trim();
        if (!value) {
            document.querySelector('#composer-request').focus();
            return;
        }
        S.update((s) => (s.prompt = value));
        response = M.ideas(value);
        if (S.read().brief.notes || S.read().brief.budget)
            response = { kind: 'limits', items: [] };
        renderResponse();
    }
    document.querySelector('#request-form').onsubmit = (e) => {
        e.preventDefault();
        compose();
    };
    document.querySelector('#composer-request').oninput = (e) =>
        S.update((s) => (s.prompt = e.target.value));
    document.querySelector('#brief-form').onsubmit = (e) => {
        e.preventDefault();
        const brief = Object.fromEntries(new FormData(e.target));
        S.update((s) => (s.brief = brief));
        U.toast('Preferences kept');
        if (response) compose();
    };
    function saved() {
        const st = S.read();
        U.open(
            'Your saved day',
            st.saved
                ? `<p>${st.saved.items.length} items · Saved on this device</p>${st.saved.items.map((x) => `<div class="idea-row"><span class="idea-symbol ${x.color || 'blue'}">${I(x.icon || 'train')}</span><div><h3>${E(x.title)}</h3><p>${E(metadata(x))}</p></div></div>`).join('')}<div class="actions"><button class="button secondary" id="edit-saved">Restore this version</button><button class="button primary" data-ui="close">Keep editing draft</button></div>`
                : '<div class="status-illustration blue">' +
                      I('bookmark') +
                      '</div><p>Save your draft to keep a version of your day here.</p>',
        );
    }
    document.addEventListener('click', (e) => {
        const b = e.target.closest('button');
        if (!b) return;
        if (b.dataset.prompt) {
            document.querySelector('#composer-request').value =
                b.dataset.prompt;
            compose();
        }
        if (b.dataset.edit) editItem(b.dataset.edit);
        if (b.dataset.remove) {
            U.close();
            const undo = S.update((s) => M.remove(s, b.dataset.remove));
            U.toast('Removed from draft', () =>
                S.update((s) => M.restore(s, undo)),
            );
        }
        if (b.dataset.move) {
            const k = b.dataset.move,
                d = +b.dataset.direction;
            S.update((s) => M.move(s, k, d));
            editItem(k);
        }
        if (b.dataset.lock) {
            S.update((s) => {
                const x = s.draft.find((x) => M.key(x) === b.dataset.lock);
                if (x) x.locked = !x.locked;
            });
            U.toast('Choice updated');
        }
        if (b.id === 'save-day') {
            S.update(M.save);
            U.toast(
                S.isPersistent()
                    ? 'Your day is saved on this device'
                    : 'Could not save. Keep this page open.',
            );
        }
        if (b.id === 'view-saved') saved();
        if (b.id === 'edit-saved') {
            const before = S.read();
            if (M.dirty(before)) {
                U.open(
                    'Restore the saved version?',
                    `<p>Your current draft has unsaved changes.</p><div class="actions"><button class="button secondary" data-ui="close">Keep draft</button><button class="button primary" id="confirm-restore">Restore saved day</button></div>`,
                );
            } else restoreSaved();
        }
        if (b.id === 'confirm-restore') restoreSaved();
        if (b.dataset.time) {
            const x = S.read().draft.find((x) => M.key(x) === b.dataset.time);
            U.open(
                'When would you like to go?',
                `<form id="item-time-form" data-key="${E(b.dataset.time)}"><p>${E(x.title)}</p><label class="form-field">Your preferred time<input type="time" name="time" value="${E(x.time || '')}"></label><div class="actions"><button class="button primary" type="submit">Keep time</button></div><p class="muted">A preference, not a booking or verified opening time.</p></form>`,
            );
        }
        if (b.dataset.inspect) {
            const x = S.read().draft.find(
                (x) => M.key(x) === b.dataset.inspect,
            );
            U.open(
                x.title,
                `<p>${E(subtitle(x))}</p><p>${E(metadata(x))}</p><div class="actions"><a class="button secondary" href="${x.type === 'appointment' ? '../bureaucracy-next/' : x.type === 'service' ? '../services-next/?service=' + encodeURIComponent(x.category) : '../departures-next/?from=' + encodeURIComponent(x.from || '') + '&to=' + encodeURIComponent(x.to || '') + (x.legs ? '&route=' + encodeURIComponent(x.id) : '')}">${x.type === 'appointment' ? 'Open Bureaucracy' : x.type === 'service' ? 'Edit service request' : 'Open Departures'}</a></div>`,
            );
        }
        if (b.dataset.sport) {
            const text =
                b.dataset.sport === 'people'
                    ? 'Find people to play with'
                    : 'Find a sports venue';
            S.update(
                (s) =>
                    (s.brief.notes = [s.brief.notes, text]
                        .filter(Boolean)
                        .join('\n')),
            );
            document.querySelector('#brief-form [name=notes]').value =
                S.read().brief.notes;
            U.open(
                text,
                `<p>${b.dataset.sport === 'people' ? 'No verified games to join are included in this capture.' : 'Captured pitches do not confirm volleyball facilities or access.'}</p><a class="button secondary" href="${b.dataset.sport === 'people' ? '../events-next/' : '../places-next/'}">${b.dataset.sport === 'people' ? 'Explore events' : 'Explore venues'}</a>`,
            );
        }
    });
    function editItem(k) {
        const st = S.read(),
            i = st.draft.findIndex((x) => M.key(x) === k),
            x = st.draft[i];
        if (!x) return;
        U.open(
            'Edit your choice',
            `<h3>${E(x.title)}</h3><p>${E(metadata(x))}</p><div class="actions">${x.type === 'place' ? `<button class="button secondary" data-time="${E(k)}">${I('clock')}Set time</button>` : ''}<button class="button secondary" data-move="${E(k)}" data-direction="-1" ${i === 0 ? 'disabled' : ''}>${I('up')}Move earlier</button><button class="button secondary" data-move="${E(k)}" data-direction="1" ${i === st.draft.length - 1 ? 'disabled' : ''}>${I('down')}Move later</button><button class="button secondary" data-remove="${E(k)}">${I('trash')}Remove from draft</button></div>`,
        );
    }
    function restoreSaved() {
        S.update(M.editSaved);
        response = null;
        document.querySelector('#response').innerHTML = '';
        document.querySelector('#composer-request').value = S.read().prompt;
        const brief = S.read().brief;
        Object.entries(brief).forEach(([k, v]) => {
            const el = document.querySelector(`#brief-form [name="${k}"]`);
            if (el) el.value = v;
        });
        U.close();
        U.toast('Saved version restored');
    }
    document.addEventListener('submit', (e) => {
        if (e.target.id === 'item-time-form') {
            e.preventDefault();
            const value = new FormData(e.target).get('time'),
                k = e.target.dataset.key;
            S.update((s) => {
                const item = s.draft.find((x) => M.key(x) === k);
                if (item) item.time = value;
            });
            U.close();
        }
    });
    window.addEventListener('expadu:change', renderPlan);
    window.addEventListener('storage', () => {
        renderPlan();
        if (response) renderResponse();
    });
    renderPlan();
    if (new URLSearchParams(location.search).has('compose') && s.prompt)
        compose();
})();
