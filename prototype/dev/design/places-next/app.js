(async function () {
    const M = ExpaduModel,
        S = ExpaduStore,
        U = ExpaduUI,
        C = ExpaduContent,
        I = U.icon,
        E = U.esc;
    const controlIcon = (name) => {
        const paths = {
            map: '<path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2V5Zm6-2v16m6-14v16"/>',
            world: '<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18M5 7h14M5 17h14"/>',
            sort: '<path d="M4 6h8M4 12h6M4 18h4M17 4v16m-4-4 4 4 4-4"/>',
            reset: '<path d="M4 10a8 8 0 1 1 1 7M4 4v6h6"/>',
        };
        return paths[name]
            ? `<svg viewBox="0 0 24 24" aria-hidden="true">${paths[name]}</svg>`
            : I(name);
    };
    const G = PlacesGuide,
        F = PlaceFilters;
    const main = document.querySelector('#main');
    main.setAttribute('aria-busy', 'true');
    const gate = ExpaduLoading.createGate({
        show: () => {
            main.innerHTML = ExpaduLoading.status('Loading places');
        },
        hide: () => {
            main.innerHTML = '';
        },
    });
    const request = gate.start();
    let catalogue;
    try {
        const response = await fetch('../places-guide/catalogue.json');
        if (!response.ok) throw new Error('Catalogue unavailable');
        const snapshot = await response.json();
        if (!Array.isArray(snapshot.places) || !snapshot.places.length)
            throw new Error('Empty catalogue');
        catalogue = snapshot.places;
    } catch {
        gate.finish(request);
        main.setAttribute('aria-busy', 'false');
        main.innerHTML = `<div class="empty"><h1>Places couldn’t load.</h1><p>Try loading this page again.</p><button class="button secondary" id="retry-places">Try again</button></div>`;
        document.querySelector('#retry-places').onclick = () =>
            location.reload();
        return;
    }
    gate.finish(request);
    const known = new Set(M.places.map((p) => p.id));
    M.places.push(...catalogue.filter((p) => !known.has(p.id)));
    const categoryIcons = Object.fromEntries(
        G.categories.map((c) => [c.id || 'All', c.icon]),
    );
    const categoryLabels = Object.fromEntries(
        G.categories.map((c) => [c.id || 'All', c.id ? c.title : 'All places']),
    );
    let filters = F.empty(),
        collection = '',
        visible = 24;
    try {
        const stored = JSON.parse(
            sessionStorage.getItem('expadu-places-filters') || '{}',
        );
        filters = F.normalize(stored, catalogue);
        collection = G.collections.some((c) => c.id === stored.collection)
            ? stored.collection
            : '';
        visible = Math.min(
            catalogue.length,
            Math.max(24, Number(stored.visible) || 24),
        );
    } catch {}
    const categories = [
        'All',
        ...G.categories.filter((c) => c.id).map((c) => c.id),
    ];
    const areas = [
        ...new Set(catalogue.map((p) => p.area).filter(Boolean)),
    ].sort((a, b) => a.localeCompare(b));
    const savedIds = () =>
        new Set(
            (S.read().savedIdeas || [])
                .filter((p) => p.type === 'place')
                .map((p) => p.id),
        );
    const select = (selectedCollection = collection) =>
        G.select(
            catalogue,
            {
                query: filters.query,
                category: filters.categories[0],
                collection: selectedCollection,
                saved: filters.saved,
                sort: filters.sort === 'name' ? 'az' : '',
            },
            savedIds(),
        ).filter(
            (p) => !filters.areas.length || filters.areas.includes(p.area),
        );
    main.innerHTML = `<div class="page-title"><div><h1>Places to make your own.</h1></div><div class="title-aside"><a class="inline-link" href="../composer-studio/">${I('spark')}Make it part of your day ${I('arrow')}</a></div></div>
        <section class="places-discovery" aria-label="Find places"><div class="places-tools"><label class="search-bar">${I('search')}<input id="place-search" aria-label="Search places" placeholder="Search places, streets, cafés…" value="${E(filters.query)}"><button type="button" id="clear-search" aria-label="Clear search" hidden>${I('close')}</button></label><button type="button" class="saved-control" id="saved-filter" aria-label="Saved places only" title="Saved places only" aria-pressed="false">${I('bookmark')}<span>Saved</span></button></div><div class="control-row"><div class="filters" aria-label="Place categories" style="--category-count:${categories.length}">${categories.map((c) => `<button class="filter" data-category="${c}" aria-pressed="false" title="${E(categoryLabels[c])}">${I(categoryIcons[c])}<span>${E(c === 'All' ? 'All' : categoryLabels[c])}</span></button>`).join('')}</div></div><div class="discovery-controls" id="discovery-controls"></div></section>
        <p class="guide-preview-note">Sample listings · 18 Sep 2026</p><div id="place-collections"></div>
        <section class="places-catalogue" aria-label="Place results"><div class="guide-results-heading"><div><h2 id="results-title">Browse all places</h2><p id="collection-reason" hidden></p></div><button type="button" class="inline-link" id="leave-collection" hidden>All places ${I('arrow')}</button></div><div class="section-caption"><span class="count-label" id="places-count" role="status"></span><span class="catalogue-note">Sample listings · 18 Sep 2026</span></div><div class="guide-results" id="place-grid"></div><button class="button secondary guide-more" id="more-places" hidden>Show more places</button></section>
        <details class="guide-source-note"><summary>About these listings</summary><p>Design preview using a captured Cologne catalogue. These source records still need identity review before publication. Details may have changed; hours aren’t live.</p><p>© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors · ODbL</a>. Photos appear only where reviewed media is available.</p></details>`;
    function placeRow(p, compact = false) {
        const location =
            p.area ||
            p.address ||
            (p.postcode ? p.postcode + ' Cologne' : p.location || 'Cologne');
        const facts = (p.facts || []).slice(0, 2);
        const saved = savedIds().has(p.id);
        return `<article class="guide-place ${compact ? 'compact' : ''}"><button class="guide-place-open" data-detail="${E(p.id)}"><h3>${E(p.title)}</h3><span>${E(p.label)} · ${E(location)}</span>${!compact && facts.length ? `<p>${facts.map(E).join(' · ')}</p>` : ''}</button><button class="guide-save" data-save-item="${E(p.id)}" aria-label="${saved ? 'Unsave' : 'Save'} ${E(p.title)}" aria-pressed="${saved}" title="Save for later">${I('bookmark')}</button>${!compact ? `<button class="guide-plan" data-plan-item="${E(p.id)}">Plan a visit ${I('plus')}</button>` : ''}</article>`;
    }
    function renderCollections() {
        const host = document.querySelector('#place-collections');
        const show = !filters.query.trim() && !filters.saved && !collection;
        host.hidden = !show;
        if (!show) {
            host.innerHTML = '';
            return;
        }
        const groups = G.collections
            .filter(
                (c) =>
                    !filters.categories.length ||
                    filters.categories.includes(c.category),
            )
            .map((c) => ({ ...c, items: select(c.id) }))
            .filter((c) => c.items.length);
        const featured = groups.slice(0, 3),
            more = groups.slice(3);
        host.innerHTML = `<section class="guide-collections" aria-label="Ways to explore">${featured
            .map(
                (c, i) =>
                    `<article class="guide-collection ${i === 0 ? 'lead' : ''}"><header><span class="guide-collection-icon">${I(c.icon)}</span><div><h2>${E(c.title)}</h2><p>${E(c.description)}</p></div></header><div class="guide-choices">${c.items
                        .slice(0, i === 0 ? 4 : 2)
                        .map((p) => placeRow(p, true))
                        .join(
                            '',
                        )}</div><button class="inline-link guide-explore" data-collection="${E(c.id)}">Explore ${c.items.length} places ${I('arrow')}</button></article>`,
            )
            .join(
                '',
            )}</section>${more.length ? `<div class="guide-more-ways" aria-label="More ways to explore">${more.map((c) => `<button data-collection="${E(c.id)}">${I(c.icon)}<span>${E(c.title)}</span>${I('arrow')}</button>`).join('')}</div>` : ''}`;
    }
    function render() {
        const items = select();
        const selected = G.collections.find((c) => c.id === collection);
        renderCollections();
        document.querySelector('#results-title').textContent =
            selected?.title ||
            (filters.saved
                ? 'Saved places'
                : filters.query.trim()
                  ? 'Search results'
                  : 'Browse all places');
        document.querySelector('#collection-reason').textContent =
            selected?.reason || '';
        document.querySelector('#collection-reason').hidden = !selected;
        document.querySelector('#leave-collection').hidden = !selected;
        document.querySelector('#places-count').textContent =
            `${items.length.toLocaleString('en')} ${items.length === 1 ? 'place' : 'places'}`;
        document.querySelector('#clear-search').hidden = !filters.query;
        document.querySelector('#place-grid').innerHTML = items.length
            ? items
                  .slice(0, visible)
                  .map((p) => placeRow(p))
                  .join('')
            : `<div class="empty"><h2>No places in this selection.</h2><p>Try another name or area. Your travel preferences are kept.</p><button class="button secondary" id="reset-places">Reset filters</button></div>`;
        document.querySelector('#more-places').hidden = items.length <= visible;
        try {
            sessionStorage.setItem(
                'expadu-places-filters',
                JSON.stringify({ ...filters, collection, visible }),
            );
        } catch {}
        document
            .querySelectorAll('[data-category]')
            .forEach((e) =>
                e.setAttribute(
                    'aria-pressed',
                    String(
                        e.dataset.category === 'All'
                            ? !filters.categories.length
                            : filters.categories.includes(e.dataset.category),
                    ),
                ),
            );
        document
            .querySelector('#saved-filter')
            .setAttribute('aria-pressed', String(filters.saved));
        document
            .querySelector('.filters')
            .style.setProperty(
                '--category-index',
                Math.max(0, categories.indexOf(filters.categories[0] || 'All')),
            );
        syncDiscoveryControls();
        window.ExpaduPlanning?.refresh();
    }
    document.querySelector('#place-search').addEventListener('input', (e) => {
        filters.query = e.target.value;
        visible = 24;
        render();
    });
    const controls = document.querySelector('#discovery-controls');
    const pickerTrigger = (id, icon, label) =>
        `<button type="button" class="discovery-control ${['area', 'category'].includes(id) ? '' : 'icon-control'}" data-picker="${id}" aria-label="${label}" title="${label}" aria-controls="${id}-picker" aria-expanded="false">${id === 'category' ? `<span data-category-icon>${controlIcon(icon)}</span>` : controlIcon(icon)}<span data-picker-value${['area', 'category'].includes(id) ? '' : ' class="visually-hidden"'}>${label}</span></button>`;
    const pickerHead = (title) =>
        `<div class="picker-head"><strong>${title}</strong><button type="button" class="icon-button" data-close-picker aria-label="Close ${title.toLowerCase()}">${I('close')}</button></div>`;
    controls.innerHTML = `<div class="discovery-slot category-slot">${pickerTrigger('category', 'grid', 'All places')}
        <section id="category-picker" class="discovery-picker category-picker" aria-label="Place category" hidden>
            ${pickerHead('Category')}
            <div class="picker-category-options">${categories.map((category) => `<button type="button" data-category="${category}" aria-pressed="false">${controlIcon(categoryIcons[category] || 'grid')}<span>${E(categoryLabels[category])}</span></button>`).join('')}</div>
            <button type="button" id="reset-category-filters" hidden>${controlIcon('reset')}<span>Reset all filters</span></button>
        </section></div><div class="discovery-slot area-slot">${pickerTrigger('area', 'map', 'All areas')}
        <section id="area-picker" class="discovery-picker area-picker" aria-label="Search area" hidden>
            ${pickerHead('Search area')}
            <button type="button" class="all-areas" data-all-areas>${controlIcon('world')}All areas ${I('check')}</button>
            <p class="picker-label">Neighbourhoods</p>
            <div class="picker-area-options">${areas.map((area) => `<label class="picker-area"><input type="checkbox" name="areas" value="${E(area)}"><span>${E(area)}</span>${I('check')}</label>`).join('')}</div>
            <p class="picker-hint">Only recorded neighbourhoods are shown. You can also search by street or postcode.</p>
        </section></div>
        <div class="discovery-slot origin-slot">${pickerTrigger('origin', 'route', 'Travel')}
        <section id="origin-picker" class="discovery-picker origin-picker" aria-label="Starting point" hidden>
            ${pickerHead('Your starting point')}
            <form id="discovery-origin-form"><label class="picker-field" for="discovery-from"><span class="visually-hidden">Address, place or stop</span><span class="origin-input">${I('search')}<input id="discovery-from" name="from" maxlength="120" placeholder="Address, place or stop" autocomplete="off"></span></label>
            <fieldset><legend>Travelling by</legend><div class="picker-modes">${[
                ['transit', 'Transit'],
                ['walk', 'Walk'],
                ['bike', 'Cycle'],
            ]
                .map(
                    ([value, label]) =>
                        `<label title="${label}"><input type="radio" name="mode" value="${value}" aria-label="${label}"><span>${controlIcon(value === 'transit' ? 'train' : value)}</span></label>`,
                )
                .join('')}</div></fieldset>
            <div class="picker-origin-footer"><p class="picker-hint">Used for directions.</p><button class="picker-done" type="submit">Done</button></div></form>
        </section></div>
        <div class="discovery-slot sort-slot">${pickerTrigger('sort', 'sort', 'Sort')}
        <section id="sort-picker" class="discovery-picker sort-picker" aria-label="Sort places" hidden>${pickerHead('Sort by')}${[
            ['default', 'As listed'],
            ['name', 'Name A–Z'],
        ]
            .map(
                ([value, label]) =>
                    `<button type="button" data-sort="${value}" aria-pressed="false">${label}${I('check')}</button>`,
            )
            .join(
                '',
            )}</section></div><button type="button" id="clear-filters" aria-label="Reset place filters" title="Reset filters" hidden>${controlIcon('reset')}</button>`;
    let activePicker = null;
    function closePicker(restoreFocus = false) {
        if (!activePicker) return;
        const trigger = controls.querySelector(
            `[data-picker="${activePicker}"]`,
        );
        controls.querySelector(`#${activePicker}-picker`).hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        activePicker = null;
        if (restoreFocus) trigger.focus({ preventScroll: true });
    }
    function openPicker(id) {
        const wasOpen = activePicker === id;
        closePicker();
        if (wasOpen) return;
        if (id === 'origin') {
            const brief = S.read().brief;
            document.querySelector('#discovery-from').value = brief.from || '';
            controls
                .querySelectorAll('[name="mode"]')
                .forEach(
                    (input) =>
                        (input.checked =
                            input.value === (brief.mode || 'transit')),
                );
        }
        activePicker = id;
        const panel = controls.querySelector(`#${id}-picker`);
        panel.hidden = false;
        window.ExpaduMotion?.reveal(panel);
        controls
            .querySelector(`[data-picker="${id}"]`)
            .setAttribute('aria-expanded', 'true');
        panel
            .querySelector(
                '[data-category][aria-pressed="true"], input, [data-sort], .all-areas',
            )
            ?.focus({ preventScroll: true });
    }
    function syncDiscoveryControls() {
        const category = filters.categories[0] || 'All';
        const categoryLabel = categoryLabels[category] || category;
        const categoryTrigger = controls.querySelector(
            '[data-picker="category"]',
        );
        categoryTrigger.querySelector('[data-picker-value]').textContent =
            categoryLabel;
        categoryTrigger.querySelector('[data-category-icon]').innerHTML =
            controlIcon(categoryIcons[category] || 'grid');
        categoryTrigger.setAttribute(
            'aria-label',
            `Place category: ${categoryLabel}`,
        );
        categoryTrigger.setAttribute(
            'data-selected',
            String(category !== 'All'),
        );
        categoryTrigger.title = categoryTrigger.getAttribute('aria-label');
        const areaLabel = !filters.areas.length
            ? 'All areas'
            : filters.areas.length === 1
              ? filters.areas[0]
              : `${filters.areas.length} areas`;
        const area = controls.querySelector('[data-picker="area"]');
        area.querySelector('[data-picker-value]').textContent = areaLabel;
        area.setAttribute('data-selected', String(!!filters.areas.length));
        area.setAttribute(
            'aria-label',
            `Search area: ${filters.areas.join(', ') || 'All areas'}`,
        );
        controls
            .querySelectorAll('[name="areas"]')
            .forEach(
                (input) =>
                    (input.checked = filters.areas.includes(input.value)),
            );
        controls
            .querySelector('[data-all-areas]')
            .setAttribute('aria-pressed', String(!filters.areas.length));
        controls.querySelector(
            '[data-picker="sort"] [data-picker-value]',
        ).textContent = filters.sort === 'name' ? 'A–Z' : 'Sort';
        controls
            .querySelectorAll('[data-sort]')
            .forEach((button) =>
                button.setAttribute(
                    'aria-pressed',
                    String(button.dataset.sort === filters.sort),
                ),
            );
        const sort = controls.querySelector('[data-picker="sort"]');
        sort.setAttribute(
            'aria-label',
            `Sort places: ${filters.sort === 'name' ? 'Name A–Z' : 'As listed'}`,
        );
        sort.setAttribute('data-selected', String(filters.sort !== 'default'));
        sort.title = sort.getAttribute('aria-label');
        area.title = area.getAttribute('aria-label');
        const brief = S.read().brief,
            origin = controls.querySelector('[data-picker="origin"]');
        origin.querySelector('[data-picker-value]').textContent = 'Travel';
        origin.classList.toggle('has-origin', Boolean(brief.from));
        const hasFilters = Boolean(
            filters.query ||
            filters.areas.length ||
            filters.categories.length ||
            filters.saved ||
            filters.sort !== 'default' ||
            collection,
        );
        document.querySelector('#clear-filters').hidden = !hasFilters;
        document.querySelector('#reset-category-filters').hidden = !hasFilters;
        origin.setAttribute(
            'aria-label',
            `Starting point: ${brief.from || 'Not set'}, ${brief.mode === 'bike' ? 'Cycle' : brief.mode === 'walk' ? 'Walk' : 'Transit'}`,
        );
        origin.title = brief.from || 'Set a starting point for directions';
    }
    controls.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.dataset.picker) openPicker(button.dataset.picker);
        if (button.hasAttribute('data-close-picker')) closePicker(true);
        if (button.hasAttribute('data-all-areas')) {
            visible = 24;
            filters.areas = [];
            render();
        }
        if (button.dataset.sort) {
            filters.sort = button.dataset.sort;
            visible = 24;
            render();
            closePicker(true);
        }
    });
    controls.addEventListener('change', (event) => {
        if (event.target.name === 'areas') {
            filters.areas = [
                ...controls.querySelectorAll('[name="areas"]:checked'),
            ].map((input) => input.value);
            render();
        }
    });
    document
        .querySelector('#discovery-origin-form')
        .addEventListener('submit', (event) => {
            event.preventDefault();
            const data = new FormData(event.target);
            S.update((state) => {
                state.brief.from = data.get('from').trim();
                state.brief.mode = data.get('mode') || 'transit';
            });
            syncDiscoveryControls();
            closePicker(true);
        });
    function dismissOutside(event) {
        if (!activePicker) return;
        const panel = controls.querySelector(`#${activePicker}-picker`);
        const trigger = controls.querySelector(
            `[data-picker="${activePicker}"]`,
        );
        if (!panel.contains(event.target) && !trigger.contains(event.target))
            closePicker();
    }
    document.addEventListener('pointerdown', dismissOutside);
    document.addEventListener('click', dismissOutside, true);
    document.addEventListener('focusin', dismissOutside);
    controls.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closePicker(true);
        }
    });
    const desktopFilters = window.matchMedia('(min-width: 1000px)');
    desktopFilters.addEventListener('change', () => closePicker());
    window.addEventListener('expadu:change', syncDiscoveryControls);
    window.addEventListener('storage', syncDiscoveryControls);
    document.addEventListener('click', (e) => {
        const b = e.target.closest('button');
        if (!b) return;
        if (b.dataset.collection) {
            collection = b.dataset.collection;
            visible = 24;
            render();
            document
                .querySelector('#results-title')
                .setAttribute('tabindex', '-1');
            document
                .querySelector('#results-title')
                .focus({ preventScroll: true });
        }
        if (b.id === 'leave-collection') {
            collection = '';
            visible = 24;
            render();
        }
        if (b.id === 'more-places') {
            const previous = visible;
            visible += 24;
            render();
            document
                .querySelectorAll('#place-grid .guide-place-open')
                [previous]?.focus({ preventScroll: true });
        }
        if (b.dataset.category) {
            collection = '';
            visible = 24;
            const value = b.dataset.category;
            filters = F.withCategory(filters, value);
            render();
            if (activePicker === 'category') closePicker(true);
        }
        if (b.id === 'saved-filter') {
            filters.saved = !filters.saved;
            visible = 24;
            render();
        }
        if (b.id === 'clear-search') {
            filters.query = '';
            document.querySelector('#place-search').value = '';
            render();
            document.querySelector('#place-search').focus();
        }
        if (
            [
                'reset-places',
                'clear-filters',
                'reset-category-filters',
            ].includes(b.id)
        ) {
            filters = F.empty();
            collection = '';
            visible = 24;
            closePicker();
            document.querySelector('#place-search').value = '';
            render();
            document
                .querySelector(
                    desktopFilters.matches
                        ? '.filters [data-category="All"]'
                        : '[data-picker="category"]',
                )
                .focus({ preventScroll: true });
        }
        if (b.id === 'ask-places') {
            S.update(
                (s) =>
                    (s.prompt = [
                        s.prompt,
                        `Find ${filters.query || (filters.categories.join(' or ').toLowerCase() || 'local') + ' places'}${filters.areas.length ? ' in ' + filters.areas.join(' or ') : ''}`,
                    ]
                        .filter(Boolean)
                        .join('\n')),
            );
            S.go('composer', { compose: '1' });
        }
    });
    window.addEventListener('expadu:change', () =>
        filters.saved ? render() : window.ExpaduPlanning?.refresh(),
    );
    window.addEventListener('storage', () =>
        filters.saved ? render() : window.ExpaduPlanning?.refresh(),
    );
    render();
    main.dataset.catalogueReady = 'true';
    main.setAttribute('aria-busy', 'false');
    requestAnimationFrame(() => S.restoreComposerPosition());
    const id = new URLSearchParams(location.search).get('place');
    if (id) C.detail(id);
})();
