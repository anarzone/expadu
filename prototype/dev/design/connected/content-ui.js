(function () {
    const M = ExpaduModel,
        S = ExpaduStore,
        U = ExpaduUI,
        I = U.icon,
        E = U.esc;
    function has(item) {
        return S.read().draft.some((x) => M.key(x) === M.key(item));
    }
    function toggle(item) {
        if (has(item)) {
            const undo = S.update((s) => M.remove(s, M.key(item)));
            U.toast('Removed from draft', () =>
                S.update((s) => M.restore(s, undo)),
            );
        } else {
            S.update((s) => M.add(s, item));
            U.toast('Added to your draft', () =>
                S.update((s) => M.remove(s, M.key(item))),
            );
        }
    }
    function itemButton(item) {
        return `<button class="button secondary" data-save-item="${E(item.id)}" aria-pressed="${(S.read().savedIdeas || []).some((x) => M.key(x) === M.key(item))}" aria-label="Save ${E(item.title)}">${I('bookmark')}Save</button><button class="button primary" data-plan-item="${E(item.id)}">Add to plan ${I('plus')}</button>`;
    }
    function placeDetail(item) {
        const introductions = {
            Park: 'Make a little room for the outdoors.',
            Pitch: 'Make space for a game in your day.',
            'Table tennis': 'Fancy a game of table tennis?',
            Playground: 'A possible stop for your next family outing.',
            Swimming: 'Thinking about a swim? Start here.',
        };
        const saved = (S.read().savedIdeas || []).some(
            (x) => M.key(x) === M.key(item),
        );
        return `<div class="place-preview">
            <div class="place-preview-content">
                ${item.image ? `<figure class="place-preview-media"><img src="${E(item.image)}" alt="${E(item.title)}"><figcaption><a href="${E(item.source)}" target="_blank" rel="noopener">${E(item.credit)}</a></figcaption></figure>` : `<div class="place-preview-art ${E(item.color)}" aria-hidden="true">${I(item.icon)}</div>`}
                <div class="place-preview-location"><span>${E(item.label)} · ${E(item.area)}</span><a href="https://www.openstreetmap.org/search?query=${encodeURIComponent(item.title + ' ' + item.area + ' Cologne')}" target="_blank" rel="noopener" aria-label="Find ${E(item.title)} on OpenStreetMap">Map ↗</a></div>
                <p class="place-preview-intro">${E(introductions[item.label] || 'A new possibility for your day.')}</p>
                ${(item.facts || []).length ? `<ul class="place-preview-facts">${item.facts.map((fact) => `<li>${I('info')}${E(fact)}</li>`).join('')}</ul>` : ''}
                <div class="place-preview-check">${I('info')}<div><strong>Before you go</strong><p>${E(item.unknown || 'Opening hours and access still need checking.')}</p></div></div>
                <details class="place-preview-source"><summary>About this listing ${I('down')}</summary><p>Captured from Expadu on ${E(item.captured)}. This preview is not a live availability check.</p></details>
            </div>
            <footer class="place-preview-actions">
                <button class="icon-button" data-save-item="${E(item.id)}" aria-pressed="${saved}" aria-label="${saved ? 'Unsave' : 'Save'} ${E(item.title)}" title="Save for later">${I('bookmark')}</button>
                <button class="button secondary" data-route="${E(item.id)}">${I('route')}Directions</button>
                <button class="button primary" data-plan-item="${E(item.id)}">${I('plus')}Add to plan</button>
            </footer>
        </div>`;
    }
    function detail(id) {
        const item = [
            ...M.places,
            ...(window.ExpaduEventCatalogue?.() || M.events),
        ].find((x) => x.id === id);
        if (!item) return;
        if (item.type === 'place' && window.ExpaduPlaceSheet) {
            ExpaduPlaceSheet.open(item);
            return;
        }
        if (item.type === 'event' && window.ExpaduEventSheet) {
            ExpaduEventSheet.open(item);
            return;
        }
        if (item.type === 'place') {
            U.open(item.title, placeDetail(item));
            document.querySelector('#shared-dialog').dataset.item = id;
            return;
        }
        const e = item.type === 'event';
        U.open(
            item.title,
            `${item.image ? `<img class="detail-photo" src="${E(item.image)}" alt="${E(item.title)}"><p class="photo-credit"><a href="${E(item.source)}" target="_blank" rel="noopener">${E(item.credit)}</a></p>` : `<div class="status-illustration ${item.color}">${I(item.icon)}</div>`}<p>${E(e ? item.venue : item.label)} · ${E(item.area)}</p><dl class="facts">${e ? `<div><dt>Date</dt><dd>8 September 2026</dd></div><div><dt>Time</dt><dd>${item.start}${item.end ? '–' + item.end : ''}</dd></div><div><dt>Price in listing</dt><dd>${E(item.price || 'Not recorded')}</dd></div><div><dt>Availability / booking</dt><dd>Check with organiser</dd></div>` : `<div><dt>Access / opening hours</dt><dd>Needs checking</dd></div>${(item.facts || []).map((f) => `<div><dt>In the listing</dt><dd>${E(f)}</dd></div>`).join('')}<div><dt>Travel time</dt><dd>Choose a starting point</dd></div>`}<div><dt>Captured from Expadu</dt><dd>${E(item.captured)}</dd></div></dl>${item.unknown ? `<p class="note-line">${I('info')}${E(item.unknown)}</p>` : ''}<div class="actions">${itemButton(item)}<button class="button secondary" data-route="${E(item.id)}">${I('route')}Directions</button>${!e ? `<a class="button secondary" href="https://www.openstreetmap.org/search?query=${encodeURIComponent(item.title + ' ' + item.area + ' Cologne')}" target="_blank" rel="noopener">Open map ↗</a>` : `<button class="button secondary" data-remind="${E(item.id)}">${I('bell')}${S.read().reminders.includes(item.occurrence) ? 'Reminder kept' : 'Keep reminder'}</button>`}</div>${e ? '<p class="muted">Reminder preview only. No notification will be sent.</p>' : ''}`,
        );
        document.querySelector('#shared-dialog').dataset.item = id;
    }
    function refresh() {
        document.querySelectorAll('[data-select]').forEach((b) => {
            const item = [...M.places, ...M.events].find(
                (x) => x.id === b.dataset.select,
            );
            if (!item) return;
            const yes = has(item);
            b.setAttribute('aria-pressed', String(yes));
            if (
                b.classList.contains('add-circle') ||
                b.classList.contains('icon-button')
            )
                b.innerHTML = I(yes ? 'check' : 'plus');
            else
                b.innerHTML =
                    I(yes ? 'check' : 'plus') +
                    (yes ? 'In your draft' : 'Add to draft');
        });
    }
    window.addEventListener('expadu:change', refresh);
    window.addEventListener('storage', refresh);
    document.addEventListener('click', (e) => {
        const t = e.target.closest(
            '[data-select],[data-detail],[data-route],[data-remind]',
        );
        if (!t) return;
        if (t.dataset.detail) detail(t.dataset.detail);
        if (t.dataset.select) {
            const item = [...M.places, ...M.events].find(
                (x) => x.id === t.dataset.select,
            );
            if (item) toggle(item);
        }
        if (t.dataset.route) {
            const item = [...M.places, ...M.events].find(
                (x) => x.id === t.dataset.route,
            );
            if (item) {
                const workspace = S.studio();
                const plan =
                    workspace?.proposal ||
                    workspace?.plans?.find((p) => p.date === item.date);
                const selected =
                    S.read().draft.find(
                        (x) => x.id === item.id && x.scheduled,
                    ) ||
                    plan?.items.find(
                        (x) => x.id === 'event:' + item.id || x.id === item.id,
                    );
                S.go('departures', {
                    to: item.venue || item.title,
                    context: item.id,
                    date: item.type === 'event' ? item.date : '',
                    arrive:
                        item.type === 'event'
                            ? selected?.start || item.start
                            : '',
                });
            }
        }
        if (t.dataset.remind) {
            const item = M.events.find((x) => x.id === t.dataset.remind);
            if (item) {
                const yes = S.read().reminders.includes(item.occurrence);
                S.update(
                    (s) =>
                        (s.reminders = yes
                            ? s.reminders.filter((x) => x !== item.occurrence)
                            : [...s.reminders, item.occurrence]),
                );
                t.innerHTML =
                    I('bell') + (yes ? 'Keep reminder' : 'Reminder kept');
                U.toast(
                    yes
                        ? 'Preview reminder removed'
                        : 'Reminder kept locally · no notification',
                );
            }
        }
    });
    let editingOrigin = false;
    let originDraft = '';
    function travelContext() {
        const root = document.querySelector('#travel-context');
        if (!root) return;
        const brief = S.read().brief;
        root.innerHTML = `${editingOrigin ? `<form id="shared-origin-form" class="inline-origin"><label for="travel-from">Where are you starting?</label><div class="inline-origin-field">${I('pin')}<input id="travel-from" name="from" maxlength="120" placeholder="Place, address or stop" value="${E(originDraft)}" aria-describedby="origin-hint"><button type="submit" class="icon-button" aria-label="Keep starting point">${I('check')}</button><button type="button" class="icon-button" data-cancel-origin aria-label="Cancel starting point edit">${I('close')}</button></div><p id="origin-hint">Used for directions. You can change it anytime.</p></form>` : `<button class="travel-origin" data-origin aria-expanded="false">${I('pin')}<span>${brief.from ? 'From ' + E(brief.from) : 'Choose a starting point'}</span>${I('down')}</button>`}<div class="travel-modes" aria-label="Getting around">${[
            ['transit', 'Transit'],
            ['walk', 'Walk'],
            ['bike', 'Cycle'],
        ]
            .map(
                ([id, label]) =>
                    `<button data-travel-mode="${id}" aria-pressed="${brief.mode === id}">${label}</button>`,
            )
            .join('')}</div>`;
    }
    document.addEventListener('click', (event) => {
        const t = event.target.closest(
            '[data-origin],[data-travel-mode],[data-cancel-origin]',
        );
        if (!t) return;
        if (t.hasAttribute('data-origin')) {
            editingOrigin = true;
            originDraft = S.read().brief.from || '';
            travelContext();
            document
                .querySelector('#travel-from')
                .focus({ preventScroll: true });
        }
        if (t.hasAttribute('data-cancel-origin')) cancelOrigin();
        if (t.dataset.travelMode)
            S.update((s) => (s.brief.mode = t.dataset.travelMode));
    });
    function cancelOrigin() {
        editingOrigin = false;
        travelContext();
        document.querySelector('[data-origin]')?.focus({ preventScroll: true });
    }
    document.addEventListener('input', (event) => {
        if (event.target.id === 'travel-from') originDraft = event.target.value;
    });
    document.addEventListener('keydown', (event) => {
        if (
            event.key === 'Escape' &&
            event.target.closest('#shared-origin-form')
        ) {
            event.preventDefault();
            cancelOrigin();
        }
    });
    document.addEventListener('submit', (event) => {
        if (event.target.id !== 'shared-origin-form') return;
        event.preventDefault();
        const value = new FormData(event.target).get('from').trim();
        editingOrigin = false;
        S.update((s) => (s.brief.from = value));
        travelContext();
        document.querySelector('[data-origin]')?.focus({ preventScroll: true });
    });
    window.addEventListener('expadu:change', travelContext);
    window.addEventListener('storage', travelContext);
    window.ExpaduContent = {
        has,
        toggle,
        detail,
        itemButton,
        refresh,
        travelContext,
    };
})();
