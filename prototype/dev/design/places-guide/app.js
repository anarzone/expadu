(function () {
    'use strict';
    const G = PlacesGuide,
        S = ExpaduStore,
        M = ExpaduModel,
        P = ExpaduPlanning,
        E = ExpaduUI.esc;
    const $ = (q) => document.querySelector(q);
    const symbols = {
        coffee: '<path d="M4 8h12v7a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5z"/><path d="M16 9h2a3 3 0 0 1 0 6h-2M7 3v2m4-2v2M3 22h15"/>',
        book: '<path d="M12 5C8 2 3 3 2 4v15c4-2 7-1 10 1 3-2 6-3 10-1V4c-3-2-7-1-10 1Zm0 0v15"/>',
        water: '<path d="M2 18c3-3 5 3 8 0s5 3 8 0 4 0 4 0M2 22c3-3 5 3 8 0s5 3 8 0 4 0 4 0M8 15V4a2 2 0 0 1 4 0m3 11V4a2 2 0 0 1 4 0M8 7h7M8 12h7"/>',
    };
    const I = (name) =>
        symbols[name]
            ? `<svg viewBox="0 0 24 24" aria-hidden="true">${symbols[name]}</svg>`
            : ExpaduIcon(name);
    document
        .querySelectorAll('[data-icon]')
        .forEach((el) => (el.innerHTML = I(el.dataset.icon)));
    const params = new URLSearchParams(location.search);
    const state = {
        query: params.get('q') || '',
        category: params.get('category') || '',
        collection: params.get('collection') || '',
        scope: params.get('scope') || '',
        sort: params.get('sort') || 'collection',
        saved: params.get('saved') === '1',
        limit: 24,
        picked: params.get('place'),
        photos: params.get('photos') !== 'none',
    };
    if (!G.categories.some((c) => c.id === state.category)) state.category = '';
    if (!G.collections.some((c) => c.id === state.collection))
        state.collection = '';
    if (!['collection', 'az'].includes(state.sort)) state.sort = 'collection';
    let places = [],
        byId = new Map(),
        ready = false,
        browseY = 0,
        lastFocus = null;
    const draftKey = 'expadu-places-guide-forms-v1';
    let formDrafts = {};
    try {
        formDrafts = JSON.parse(sessionStorage.getItem(draftKey)) || {};
    } catch {}
    function keepForms() {
        try {
            sessionStorage.setItem(draftKey, JSON.stringify(formDrafts));
        } catch {}
    }
    const savedIds = () =>
        new Set(
            (S.read().savedIdeas || [])
                .filter((x) => x.type === 'place')
                .map((x) => x.id),
        );
    const categoryIcon = (p) =>
        G.categories.find((c) => c.id === p.category)?.icon || 'pin';
    const locationText = (p) =>
        [
            p.label,
            p.address ||
                p.area ||
                (p.postcode ? `${p.postcode} Cologne` : 'Cologne'),
        ]
            .filter(Boolean)
            .join(' · ');
    const saveButton = (p) =>
        `<button type="button" class="save-item" data-save-item="${E(p.id)}" aria-label="Save ${E(p.title)}" aria-pressed="${savedIds().has(p.id)}">${I('bookmark')}</button>`;
    function syncURL() {
        const u = new URL(location.href);
        const values = {
            q: state.query,
            category: state.category,
            collection: state.collection,
            scope: state.scope,
            sort: state.sort === 'az' ? 'az' : '',
            saved: state.saved ? '1' : '',
            place: state.picked,
            photos: state.photos ? '' : 'none',
        };
        for (const [key, value] of Object.entries(values)) {
            if (value) u.searchParams.set(key, value);
            else u.searchParams.delete(key);
        }
        history.replaceState(null, '', u);
    }
    function miniPlace(p) {
        return `<li class="collection-place"><button class="place-link" data-place="${E(p.id)}" aria-controls="place-detail" aria-expanded="${state.picked === p.id}"><strong>${E(p.title)}</strong><small>${E(locationText(p))}</small></button>${saveButton(p)}</li>`;
    }
    function collectionCard(c, index) {
        const rows = G.select(
            places,
            { collection: c.id, scope: state.scope },
            savedIds(),
        );
        return `<article class="collection ${index === 0 ? 'featured' : ''}"><div class="collection-heading"><div><h2>${E(c.title)}</h2><p>${E(c.description)}</p></div><span class="collection-mark">${I(c.icon)}</span></div><ul class="collection-places">${rows
            .slice(0, index === 0 ? 5 : 2)
            .map(miniPlace)
            .join(
                '',
            )}</ul><button class="collection-cta" data-collection="${c.id}"><span>Explore ${E((c.short || c.title).toLowerCase())}</span><b aria-label="${rows.length} places">${rows.length}</b></button></article>`;
    }
    function renderCollections() {
        const visible = G.collections.filter(
            (c) =>
                (!state.category || c.category === state.category) &&
                G.select(
                    places,
                    { collection: c.id, scope: state.scope },
                    savedIds(),
                ).length,
        );
        const featured = visible.slice(0, 3),
            more = visible.slice(3);
        $('#collections').innerHTML = visible.length
            ? `<div class="collection-grid">${featured.map(collectionCard).join('')}</div>${
                  more.length
                      ? `<section class="collection-shelf" aria-labelledby="more-ways"><div class="shelf-heading"><h2 id="more-ways">More ways to explore</h2></div><div class="all-collections">${more
                            .map((c) => {
                                const count = G.select(
                                    places,
                                    { collection: c.id, scope: state.scope },
                                    savedIds(),
                                ).length;
                                return `<button class="small-collection" data-collection="${c.id}">${I(c.icon)}<span><strong>${E(c.title)}</strong><small>${E(c.description)} ${count} places</small></span></button>`;
                            })
                            .join('')}</div></section>`
                      : ''
              }`
            : '';
    }
    function placeCard(p) {
        return `<article class="place-card" data-record-id="${E(p.id)}"><span class="place-kind">${I(categoryIcon(p))}</span><button class="place-link" data-place="${E(p.id)}" aria-controls="place-detail" aria-expanded="${state.picked === p.id}"><strong>${E(p.title)}</strong><span class="place-location">${E(locationText(p))}</span>${p.facts.length ? `<span class="place-fact">${E(p.facts.slice(0, 2).join(' · '))}</span>` : ''}</button>${saveButton(p)}</article>`;
    }
    function renderResults() {
        const rows = G.select(places, state, savedIds());
        const c = G.collections.find((x) => x.id === state.collection);
        $('#collection-context').innerHTML = c
            ? `<div class="collection-intro"><button data-reset="collection">${I('back')}All collections</button><h2>${E(c.title)}</h2><p>${E(c.reason)}</p></div>`
            : '';
        $('#results-title').textContent = state.saved
            ? 'Your saved places'
            : state.query
              ? `Results for “${state.query}”`
              : c
                ? 'Places in this collection'
                : state.category
                  ? G.categories.find((c) => c.id === state.category).title
                  : 'Browse all places';
        $('#results-count').textContent =
            `${rows.length.toLocaleString('en')} ${rows.length === 1 ? 'place' : 'places'} in this ${state.saved ? 'selection' : 'snapshot'}${state.scope ? ' · matching recorded area' : ''}`;
        $('#place-results').innerHTML = rows.length
            ? rows.slice(0, state.limit).map(placeCard).join('')
            : `<div class="empty-state"><h3>${state.saved ? 'No saved places in this view' : 'No places found here'}</h3><p>${state.saved ? 'Save a place using its bookmark. Saved ideas stay separate from your plans.' : 'Try a place name, a street or a broader search area. Missing area information can limit these results.'}</p><button class="guide-button cyan" data-reset="all">Explore all places</button></div>`;
        $('#load-more').hidden = rows.length <= state.limit;
        $('#load-more').textContent =
            `Show ${Math.min(24, Math.max(0, rows.length - state.limit))} more places`;
        $('#sort').value = state.collection ? state.sort : 'az';
        $('#sort option[value="collection"]').hidden = !state.collection;
        P.refresh();
    }
    function refreshSaved() {
        const count = [...savedIds()].filter((id) => byId.has(id)).length;
        $('#saved-count').textContent = count;
        $('#saved-count').hidden = !count;
        $('#storage-warning').hidden = S.isPersistent();
        P.refresh();
    }
    function render() {
        if (!ready) return;
        $('#categories').innerHTML = G.categories
            .map(
                (c) =>
                    `<button data-category="${c.id}" aria-pressed="${state.category === c.id}">${I(c.icon)}${E(c.title)}</button>`,
            )
            .join('');
        $('#search').value = state.query;
        $('#clear-search').hidden = !state.query;
        $('#scope').value = state.scope;
        $('#saved-only').setAttribute('aria-pressed', String(state.saved));
        const discovery = !state.query && !state.collection && !state.saved;
        $('#collections').hidden = !discovery;
        if (discovery) renderCollections();
        renderResults();
        refreshSaved();
        syncURL();
    }
    function change(values, { focus = false } = {}) {
        Object.assign(state, values, { limit: 24 });
        render();
        if (focus) {
            $('#results-title').focus({ preventScroll: true });
            $('#results').scrollIntoView({ block: 'start', behavior: 'auto' });
        }
    }
    function rememberForm(id) {
        const form = $('#selection-form');
        if (!form) return;
        formDrafts[id] = {
            date: form.elements.date.value,
            start: form.elements.start.value,
            end: form.elements.end.value,
        };
        keepForms();
    }
    function mountPlan(p) {
        P.mount(p, $('#plan-mount'), (selection, undo) => {
            delete formDrafts[p.id];
            keepForms();
            $('#plan-mount').innerHTML =
                `<div class="plan-receipt" role="status"><strong>Added to your proposal</strong><p>${E(selection.date)} · ${E(selection.start)}–${E(selection.end)}. Your plan still needs review.</p><button class="guide-button primary" data-review-date="${E(selection.date)}">Review in Composer</button><button class="undo" id="undo-plan">Undo</button></div>`;
            $('#undo-plan').onclick = () => {
                undo();
                mountPlan(p);
                ExpaduUI.toast('Removed from proposal');
            };
        });
        const form = $('#selection-form');
        form.querySelectorAll('[data-time-input]').forEach((input) => {
            for (const attr of [
                'role',
                'aria-haspopup',
                'aria-expanded',
                'aria-controls',
            ])
                input.removeAttribute(attr);
        });
        const draft = formDrafts[p.id];
        if (draft) {
            for (const key of ['date', 'start', 'end'])
                if (draft[key]) form.elements[key].value = draft[key];
            form.elements.date.dispatchEvent(
                new Event('change', { bubbles: true }),
            );
        }
        form.addEventListener('input', () => rememberForm(p.id));
        form.addEventListener('change', () => rememberForm(p.id));
    }
    function showDetail(p, { plan = false, restore = false } = {}) {
        if (!p) return;
        if (state.picked && $('#selection-form')) rememberForm(state.picked);
        if (!$('#place-detail').hidden && state.picked === p.id) {
            if (plan) {
                $('#plan-section').open = true;
                $('#plan-section').scrollIntoView({ block: 'nearest' });
            }
            return;
        }
        if ($('#place-detail').hidden) {
            browseY = window.scrollY;
            lastFocus = document.activeElement;
        }
        state.picked = p.id;
        const details = $('#place-detail');
        const photo = state.photos && p.photo;
        const website = G.safeURL(p.website);
        const draft = formDrafts[p.id];
        details.innerHTML = `<div class="detail-top"><button class="detail-back" data-close-detail>${I('back')}Back to places</button>${saveButton(p)}</div><div class="detail-type">${I(categoryIcon(p))}${E(p.label)}${p.area ? ` · ${E(p.area)}` : ''}</div><h2 id="detail-title" tabindex="-1">${E(p.title)}</h2><p class="detail-address">${E(p.address || p.area || 'Cologne · street address not recorded')}${p.postcode ? `<br>${E(p.postcode)} Cologne` : ''}</p><div class="detail-actions"><button class="guide-button primary" data-open-plan>${I('calendar')}Add to plan</button><button class="guide-button" data-open-directions>${I('route')}Directions</button></div>${photo ? `<figure class="detail-photo"><img src="${E(photo.file)}" alt="${E(p.title)}" loading="lazy"><figcaption>${E(photo.attribution)} · <a href="${E(G.safeURL(photo.source) || p.sourceUrl)}" target="_blank" rel="noopener noreferrer">Image source</a> · <a href="${E(G.safeURL(photo.licenseUrl) || photo.source)}" target="_blank" rel="noopener noreferrer">${E(photo.license)}</a></figcaption></figure>` : ''}<section class="detail-block"><h3>A little about this place</h3><p>${E(p.summary)}</p>${p.description && p.description !== p.summary ? `<p class="hint">${p.descriptionLanguage === 'en' ? 'From the source' : 'From the source, in its original language'}:</p><p class="source-description">${E(p.description)}</p>` : ''}${p.facts.length ? `<ul class="fact-list">${p.facts.map((f) => `<li>${E(f)}</li>`).join('')}</ul>` : '<p class="hint">Additional amenities aren’t recorded.</p>'}</section><section class="detail-block"><h3>Before you go</h3><p>${E(G.hoursText(p.rawHours))}</p><p class="hint">${p.rawHours ? 'Recorded hours, not live opening status.' : 'Check opening and entry conditions with the venue.'}</p>${website ? `<a class="text-link" href="${E(website)}" target="_blank" rel="noopener noreferrer">Visit the venue’s website ${I('external')}</a>` : ''}</section><details class="detail-disclosure" id="plan-section" ${plan || draft ? 'open' : ''}><summary>Add a visit to your plan</summary><div id="plan-mount"></div></details><details class="detail-disclosure" id="directions-section"><summary>Getting there</summary><div><form class="travel-form" id="directions-form"><label>Starting point<input name="from" aria-label="Starting point" autocomplete="off" placeholder="Address, place or station" maxlength="160" value="${E(S.read().brief.from || '')}"></label><fieldset class="travel-modes"><legend>Travelling by</legend>${[
            ['walk', 'Walk'],
            ['bike', 'Cycle'],
            ['transit', 'Transit'],
        ]
            .map(
                ([mode, label]) =>
                    `<label><input type="radio" name="mode" value="${mode}" ${mode === (S.read().brief.mode || 'transit') ? 'checked' : ''}>${label}</label>`,
            )
            .join(
                '',
            )}</fieldset><button type="submit" class="guide-button cyan">Open Google Maps directions</button></form><a class="text-link" href="${E(G.mapURL(p))}" target="_blank" rel="noopener noreferrer">View location on OpenStreetMap ${I('external')}</a><p>Opens a map service using this place’s recorded coordinates. Route times aren’t available in this preview.</p></div></details><details class="detail-disclosure"><summary>Place information & sources</summary><div><p>Recorded on ${E(p.captured)}. This is a research snapshot; opening, access and the place’s current status haven’t been rechecked.</p><p><a href="${E(p.sourceUrl)}" target="_blank" rel="noopener noreferrer">View the OpenStreetMap record</a></p><p>Source identity: ${E(p.sourceId)}${p.memberSourceIds.length > 1 ? ` · ${p.memberSourceIds.length} linked source records` : ''}.</p></div></details>`;
        details.hidden = false;
        document
            .querySelectorAll('[data-place]')
            .forEach((button) =>
                button.setAttribute(
                    'aria-expanded',
                    String(button.dataset.place === p.id),
                ),
            );
        $('#guide-workspace').classList.add('detail-open');
        mountPlan(p);
        details
            .querySelector('img')
            ?.addEventListener(
                'error',
                (event) => event.target.closest('figure').remove(),
                { once: true },
            );
        const travel = $('#directions-form');
        travel.onsubmit = (event) => {
            event.preventDefault();
            const from = travel.elements.from.value,
                mode = travel.elements.mode.value;
            S.update((s) => {
                s.brief.from = from;
                s.brief.mode = mode;
            });
            window.open(
                G.directionsURL(p, from, mode),
                '_blank',
                'noopener,noreferrer',
            );
        };
        travel.addEventListener('change', () => {
            S.update((s) => {
                s.brief.from = travel.elements.from.value;
                s.brief.mode = travel.elements.mode.value;
            });
        });
        details.scrollTop = 0;
        P.refresh();
        syncURL();
        if (!restore) {
            $('#detail-title').focus({ preventScroll: true });
            if (matchMedia('(max-width:760px)').matches)
                details.scrollIntoView({ block: 'start', behavior: 'auto' });
        }
    }
    function closeDetail() {
        if (state.picked) rememberForm(state.picked);
        state.picked = null;
        document
            .querySelectorAll('[data-place]')
            .forEach((button) => button.setAttribute('aria-expanded', 'false'));
        $('#place-detail').hidden = true;
        $('#guide-workspace').classList.remove('detail-open');
        syncURL();
        window.scrollTo({ top: browseY, behavior: 'instant' });
        const target = lastFocus?.isConnected ? lastFocus : $('#results-title');
        target.focus({ preventScroll: true });
    }
    window.ExpaduPlaceSheet = {
        open: (p, tab) => showDetail(p, { plan: tab === 'plan' }),
    };
    document.addEventListener('click', (event) => {
        const button = event.target.closest('button,a');
        if (!button) return;
        if (button.hasAttribute('data-category')) {
            change({
                category: button.dataset.category,
                collection: '',
                query: '',
                saved: false,
            });
            return;
        }
        if (button.dataset.collection) {
            change(
                {
                    collection: button.dataset.collection,
                    query: '',
                    saved: false,
                },
                { focus: true },
            );
            return;
        }
        if (button.dataset.place) {
            showDetail(byId.get(button.dataset.place));
            return;
        }
        if (button.hasAttribute('data-close-detail')) {
            closeDetail();
            return;
        }
        if (button.hasAttribute('data-open-plan')) {
            $('#plan-section').open = !$('#plan-section').open;
            if ($('#plan-section').open) {
                $('#plan-section').scrollIntoView({
                    block: 'nearest',
                    behavior: 'auto',
                });
                $('#selection-form input')?.focus({ preventScroll: true });
            }
            return;
        }
        if (button.hasAttribute('data-open-directions')) {
            $('#directions-section').open = !$('#directions-section').open;
            if ($('#directions-section').open) {
                $('#directions-section').scrollIntoView({
                    block: 'nearest',
                    behavior: 'auto',
                });
                $('#directions-form input').focus({ preventScroll: true });
            }
            return;
        }
        if (button.dataset.reset) {
            change(
                button.dataset.reset === 'all'
                    ? {
                          category: '',
                          query: '',
                          scope: '',
                          saved: false,
                          collection: '',
                      }
                    : { collection: '' },
            );
            return;
        }
        if (button.dataset.reviewDate) {
            event.preventDefault();
            if (state.picked) rememberForm(state.picked);
            S.go(
                'composer',
                button.dataset.reviewDate
                    ? { date: button.dataset.reviewDate }
                    : {},
            );
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && state.picked && !e.defaultPrevented) {
            e.preventDefault();
            closeDetail();
        }
    });
    $('.search-form').onsubmit = (event) => {
        event.preventDefault();
        change(
            { query: $('#search').value.trim(), collection: '' },
            { focus: true },
        );
    };
    let searchTimer;
    $('#search').addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(
            () => change({ query: $('#search').value, collection: '' }),
            120,
        );
    });
    $('#clear-search').onclick = () => {
        change({ query: '' });
        $('#search').focus();
    };
    $('#saved-only').onclick = () =>
        change({ saved: !state.saved, collection: '' });
    $('#scope').onchange = (event) => change({ scope: event.target.value });
    $('#sort').onchange = (event) => change({ sort: event.target.value });
    $('#load-more').onclick = () => {
        const previous = state.limit;
        state.limit += 24;
        renderResults();
        const next =
            $('#place-results').children[previous]?.querySelector(
                '.place-link',
            );
        next?.focus({ preventScroll: true });
    };
    $('#show-photos').checked = state.photos;
    $('#show-photos').onchange = (event) => {
        state.photos = event.target.checked;
        if (state.picked) {
            const p = byId.get(state.picked);
            rememberForm(state.picked);
            $('#place-detail').hidden = true;
            showDetail(p, { restore: true });
        }
        syncURL();
    };
    window.addEventListener('expadu:change', () => {
        if (!ready) return;
        refreshSaved();
        if (state.saved) renderResults();
    });
    window.addEventListener('storage', () => {
        if (!ready) return;
        refreshSaved();
        if (state.saved) renderResults();
    });
    async function load() {
        try {
            $('#load-state').innerHTML = '<p>Loading places…</p>';
            const response = await fetch('./catalogue.json');
            if (!response.ok) throw Error('Catalogue unavailable');
            const data = await response.json();
            if (
                !Array.isArray(data.places) ||
                data.places.length !== data.meta.records
            )
                throw Error('Catalogue incomplete');
            places = data.places;
            byId = new Map(places.map((p) => [p.id, p]));
            const existing = new Set(M.places.map((p) => p.id));
            places.forEach((p) => {
                Object.defineProperty(p, 'searchText', {
                    value: G.normalize(
                        [
                            p.title,
                            p.label,
                            p.kind,
                            p.area,
                            p.postcode,
                            p.address,
                            ...p.aliases,
                            ...p.facts,
                        ].join(' '),
                    ),
                });
                if (!existing.has(p.id)) M.places.push(p);
            });
            const areas = [
                ...new Set(places.map((p) => p.area).filter(Boolean)),
            ].sort((a, b) => a.localeCompare(b));
            const postcodes = [
                ...new Set(places.map((p) => p.postcode).filter(Boolean)),
            ].sort();
            $('#scope').innerHTML =
                `<option value="">All of Cologne</option><optgroup label="Recorded neighbourhoods">${areas.map((a) => `<option value="area:${E(a)}">${E(a)}</option>`).join('')}</optgroup><optgroup label="Recorded postcodes">${postcodes.map((p) => `<option value="postcode:${E(p)}">${E(p)}</option>`).join('')}</optgroup>`;
            if (![...$('#scope').options].some((o) => o.value === state.scope))
                state.scope = '';
            $('#snapshot-summary').textContent =
                `${data.meta.records.toLocaleString('en')} source records prepared for this design, including cafés and restaurants. This is not a count of published places or a complete city inventory.`;
            $('#load-state').hidden = true;
            $('#results').hidden = false;
            ready = true;
            document.body.dataset.catalogueReady = 'true';
            render();
            if (state.picked) {
                const p = byId.get(state.picked);
                if (p) showDetail(p, { restore: true });
                else {
                    state.picked = null;
                    syncURL();
                }
            }
        } catch {
            $('#load-state').hidden = false;
            $('#load-state').innerHTML =
                '<div class="empty-state"><h2>Places couldn’t load</h2><p>Try loading the catalogue again. Your saved ideas and plans are kept.</p><button class="guide-button cyan" id="retry-load">Try again</button></div>';
            $('#retry-load').onclick = load;
        }
    }
    load();
})();
