/* Separate Places concept using the existing local prototype store. */
(function () {
    'use strict';
    const P = PlacesSample.places,
        S = ExpaduStore,
        U = ExpaduUI;
    const E = U.esc,
        O = OptionalPlaces,
        F = PlaceFilters;
    const $ = (selector) => document.querySelector(selector);
    const list = $('#place-list'),
        detail = $('#place-detail'),
        layout = $('#results-layout');
    const filterKey = 'expadu-places-optional-filters-v1';
    const params = new URL(location.href).searchParams;
    const icons = {
        cup: '<path d="M4 4h12v10a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5V4Zm12 2h2a3 3 0 0 1 0 6h-2M3 22h15"/>',
        cutlery:
            '<path d="M5 2v7m4-7v7M3 2v6a4 4 0 0 0 8 0V2M7 12v10M20 22V2c-4 2-5 7-5 12h5"/>',
        sort: '<path d="M4 5h16M4 12h11M4 19h6"/>',
        list: '<path d="M8 5h13M8 12h13M8 19h13M3 5h.1M3 12h.1M3 19h.1"/>',
        map: '<path d="m3 5 6-3 6 3 6-3v17l-6 3-6-3-6 3V5Zm6-3v17M15 5v17"/>',
    };
    const I = (name) =>
        icons[name]
            ? `<svg viewBox="0 0 24 24" aria-hidden="true">${icons[name]}</svg>`
            : ExpaduIcon(name);
    document.querySelectorAll('[data-symbol]').forEach((node) => {
        node.innerHTML = I(node.dataset.symbol);
    });
    $('.composer-header').setAttribute('aria-label', 'Open Composer');
    document
        .querySelectorAll('[data-view]')
        .forEach((button) =>
            button.setAttribute(
                'aria-label',
                button.dataset.view === 'map'
                    ? 'Map and list view'
                    : 'List view',
            ),
        );
    let filters;
    try {
        filters = F.normalize(JSON.parse(sessionStorage.getItem(filterKey)), P);
    } catch {
        filters = F.empty();
    }
    let showPhotos = params.get('photos') !== 'none',
        selected = null,
        activeTab = 'about';
    let map,
        mapLoading,
        markers = [],
        matches = [],
        returnFocus;
    const failedPhotos = new Set();
    const compact = matchMedia('(max-width: 900px)');
    const categoryIcons = {
        All: 'grid',
        Cafés: 'cup',
        Restaurants: 'cutlery',
        Parks: 'leaf',
        Playgrounds: 'smile',
        Culture: 'museum',
        Activities: 'waves',
    };
    $('#categories').innerHTML = Object.entries(categoryIcons)
        .map(
            ([name, icon]) =>
                `<button type="button" data-category="${E(name)}" aria-pressed="false">${I(icon)}${E(name)}</button>`,
        )
        .join('');
    const areas = [...new Set(P.map((place) => place.area))].sort((a, b) =>
        a.localeCompare(b),
    );
    areas.forEach((area) => $('#area').add(new Option(area, area)));
    $('#hide-photos').checked = !showPhotos;

    function persistFilters() {
        try {
            sessionStorage.setItem(filterKey, JSON.stringify(filters));
        } catch {
            /* Filtering remains usable without storage. */
        }
    }
    function hasPhoto(place) {
        return showPhotos && place.photo && !failedPhotos.has(place.id);
    }
    function saved(place) {
        return (S.read().savedIdeas || []).some(
            (item) => item.id === place.id && item.type === 'place',
        );
    }
    function saveButton(place, full = false) {
        const yes = saved(place);
        return `<button type="button" class="${full ? 'button secondary' : 'save-place'}" data-save-item="${E(place.id)}" aria-pressed="${yes}" aria-label="${yes ? 'Unsave' : 'Save'} ${E(place.title)}">${I('bookmark')}${full ? `<span data-save-label>${yes ? 'Saved' : 'Save for later'}</span>` : ''}</button>`;
    }
    function row(place) {
        const location =
            place.area !== 'Area not recorded' ? place.area : place.address;
        return `<article class="place-row${selected?.id === place.id ? ' is-selected' : ''}" data-place-id="${E(place.id)}">
          <span class="place-symbol" data-category="${E(place.category)}" aria-hidden="true">${I(place.icon)}</span>
          <div class="row-copy"><h2><button type="button" class="place-title" data-open-place="${E(place.id)}" aria-controls="place-detail" aria-expanded="${selected?.id === place.id}">${E(place.title)}</button></h2>
          <p class="place-location">${E(place.label)}${location ? ` · ${E(location)}` : ''}</p>
          ${
              place.facts.length
                  ? `<div class="row-facts">${place.facts
                        .slice(0, 2)
                        .map((fact) => `<span>${E(fact)}</span>`)
                        .join('')}</div>`
                  : ''
          }
          <div class="row-actions"><button type="button" data-open-place="${E(place.id)}">Details ${I('arrow')}</button><button type="button" class="plan-action" data-plan-item="${E(place.id)}">${I('plus')}Plan a visit</button></div></div>
          <div class="row-side">${hasPhoto(place) ? `<div class="row-photo"><img src="${E(place.photo.file)}" data-photo-place="${E(place.id)}" alt="${E(place.title)} — reviewed place photo" loading="lazy" width="90" height="76"><button class="photo-caption" type="button" data-open-place="${E(place.id)}" aria-label="Photo credit for ${E(place.title)}">Photo credit</button></div>` : ''}${saveButton(place)}</div>
        </article>`;
    }
    function renderList() {
        // Keep the actual detail/form node: filtering and saving must not erase a visit being edited.
        const focused = document.activeElement;
        const focusKey = focused?.closest('.place-row')
            ? Object.entries(focused.dataset).find(([key]) =>
                  ['saveItem', 'openPlace', 'planItem'].includes(key),
              )
            : null;
        if (list.contains(detail)) layout.append(detail);
        matches = O.select(P, filters, S.read().savedIdeas || []);
        list.innerHTML = matches.length
            ? matches.map(row).join('')
            : '<div class="places-empty"><h2>No places in this sample</h2><p>Try another search, area or category.</p><button type="button" data-reset>Clear filters</button></div>';
        $('#result-count').textContent =
            `${matches.length} ${matches.length === 1 ? 'place' : 'places'}`;
        $('#place-search').value = filters.query;
        $('#clear-search').hidden = !filters.query;
        $('#area').value = filters.areas[0] || '';
        $('#sort').value = filters.sort;
        $('#saved-only').setAttribute('aria-pressed', String(filters.saved));
        $('#reset-filters').hidden = !(
            filters.query ||
            filters.saved ||
            filters.categories.length ||
            filters.areas.length ||
            filters.sort !== 'default'
        );
        document.querySelectorAll('[data-category]').forEach((button) => {
            if (button.tagName === 'BUTTON')
                button.setAttribute(
                    'aria-pressed',
                    String(
                        filters.categories[0] === button.dataset.category ||
                            (!filters.categories.length &&
                                button.dataset.category === 'All'),
                    ),
                );
        });
        placeDetail();
        if (focusKey) {
            const [key, value] = focusKey;
            const replacement = [...list.querySelectorAll('button')].find(
                (button) => button.dataset[key] === value,
            );
            (replacement || $('#saved-only')).focus({ preventScroll: true });
        }
        persistFilters();
        updateMarkers();
    }
    function placeDetail() {
        layout.classList.toggle('has-detail', !!selected);
        if (!selected) return;
        const matchingRow = [...list.querySelectorAll('.place-row')].find(
            (node) => node.dataset.placeId === selected.id,
        );
        if (compact.matches && matchingRow) matchingRow.after(detail);
        else if (compact.matches) list.append(detail);
        else layout.append(detail);
        const notice = detail.querySelector('[data-outside-filter]');
        if (notice) notice.hidden = !!matchingRow;
    }
    function photoFigure(place) {
        if (!hasPhoto(place)) return '';
        const photo = place.photo;
        return `<figure class="detail-photo"><img src="${E(photo.file)}" data-photo-place="${E(place.id)}" alt="${E(place.title)} — reviewed place photo"><figcaption>${E(photo.attribution)}. <a href="${E(photo.source)}" target="_blank" rel="noopener">Original & credit</a> · <a href="${E(photo.licenseUrl)}" target="_blank" rel="noopener">${E(photo.license)}</a>. Display cropped; original file unchanged.</figcaption></figure>`;
    }
    function about(place) {
        const site = O.safeURL(place.website);
        return `<div data-photo-slot>${photoFigure(place)}</div><dl class="detail-facts">
          <div><dt>Location</dt><dd>${E(place.address || 'Street address not recorded')}${place.area !== 'Area not recorded' ? `<br>${E(place.area)}, Cologne` : '<br>Cologne'}</dd></div>
          <div><dt>In the source record</dt><dd>${place.facts.length ? `<ul>${place.facts.map((fact) => `<li>${E(fact)}</li>`).join('')}</ul>` : 'Amenities not recorded'}</dd></div>
          <div><dt>Recorded hours · not live</dt><dd>${E(O.hoursText(place.rawHours))}</dd></div>
          <div><dt>Entry</dt><dd>${E(place.access ? `Source access tag: ${place.access}. Check current entry conditions.` : 'Entry conditions not confirmed.')}</dd></div></dl>
          <p class="detail-note">Recorded ${E(place.captured)}. Current hours, entry and travel still need checking. This map point is not a verified entrance.</p>
          <div class="detail-links">${site ? `<a href="${E(site)}" target="_blank" rel="noopener">Place website ${I('arrow')}</a>` : ''}<a href="${E(place.sourceUrl)}" target="_blank" rel="noopener">Source record ${I('arrow')}</a></div>
          <div class="detail-actions"><button class="button primary" type="button" data-detail-tab="plan">Plan a visit</button>${saveButton(place, true)}</div>
          <button class="detail-request" type="button" data-ask-composer>${I('spark')}Prepare a question for Composer</button>`;
    }
    function routePanel(place) {
        const brief = S.read().brief;
        const mode =
            { walk: 'Walking', bike: 'Cycling', transit: 'Public transport' }[
                brief.mode
            ] || 'Public transport';
        return `<div class="route-summary"><p><small>From</small>${E(brief.from || 'Choose a starting point')}</p><p><small>To</small>${E(place.title)}${place.address ? `<br>${E(place.address)}` : ''}</p><p><small>Travelling by</small>${mode}</p></div>
          <button type="button" class="button secondary" data-edit-travel>Change travel preferences</button>
          <p class="detail-note">Travel times are not available in this preview. The map point comes from the source record; check the entrance before travelling.</p>
          <div class="detail-links"><a href="${E(O.mapURL(place))}" target="_blank" rel="noopener">View recorded map point ${I('arrow')}</a></div>`;
    }
    function renderDetail() {
        if (!selected) return;
        detail.innerHTML = `<div class="detail-heading"><div><h2 id="detail-title" tabindex="-1">${E(selected.title)}</h2><p>${E(selected.label)} · Cologne</p></div><button type="button" class="close-detail" aria-label="Close place details">${I('close')}</button></div>
          <p class="detail-note" data-outside-filter hidden>This open place is outside your current filters.</p>
          <div class="detail-tabs" role="group" aria-label="Place detail sections">${[
              ['about', 'About'],
              ['plan', 'Plan a visit'],
              ['route', 'Get there'],
          ]
              .map(
                  ([key, label]) =>
                      `<button type="button" data-detail-tab="${key}" aria-pressed="false">${label}</button>`,
              )
              .join('')}</div>
          <section data-detail-section="about" aria-label="About this place">${about(selected)}</section>
          <section data-detail-section="plan" aria-label="Plan a visit" hidden><div data-planning-form></div></section>
          <section data-detail-section="route" aria-label="Getting there" hidden>${routePanel(selected)}</section>`;
        mountPlan(detail.querySelector('[data-planning-form]'));
        changeTab(activeTab);
        ExpaduPlanning.refresh();
    }
    function changeTab(tab) {
        activeTab = ['about', 'plan', 'route'].includes(tab) ? tab : 'about';
        detail
            .querySelectorAll('.detail-tabs button')
            .forEach((button) =>
                button.setAttribute(
                    'aria-pressed',
                    String(button.dataset.detailTab === activeTab),
                ),
            );
        detail.querySelectorAll('[data-detail-section]').forEach((section) => {
            section.hidden = section.dataset.detailSection !== activeTab;
        });
    }
    function mountPlan(container) {
        ExpaduPlanning.mount(selected, container, showReceipt);
        // This concept uses plain time entry, without the optional time-picker dialog.
        container.querySelectorAll('[data-time-input]').forEach((input) => {
            for (const attribute of [
                'role',
                'aria-haspopup',
                'aria-expanded',
                'aria-controls',
            ])
                input.removeAttribute(attribute);
        });
    }
    function showReceipt(choice, undo) {
        const form = detail.querySelector('[data-planning-form]');
        form.innerHTML = `<div class="detail-receipt" role="status" tabindex="-1"><strong>Added to your proposal</strong><p>${E(choice.title)} · ${E(choice.date)} · ${E(choice.start)}–${E(choice.end)}</p><button type="button" data-undo-add>Undo</button></div><p class="detail-note">Review it in Composer before confirming your plan. This does not book admission or check opening hours.</p><button type="button" class="button primary" data-review-proposal>Review in Composer</button>`;
        form.querySelector('[data-undo-add]').onclick = () => {
            undo();
            if (!S.isPersistent()) {
                U.toast('Could not keep this change. Keep the page open.');
                return;
            }
            mountPlan(form);
            form.querySelector('input').focus();
        };
        form.querySelector('[data-review-proposal]').onclick = () =>
            S.go('composer', { date: choice.date });
        form.querySelector('[role=status]').focus({ preventScroll: true });
    }
    function openDetail(place, tab = 'about', trigger) {
        if (!P.some((item) => item.id === place.id)) return;
        returnFocus = trigger || document.activeElement;
        if (selected?.id !== place.id) {
            selected = place;
            activeTab = tab;
            renderDetail();
        } else changeTab(tab);
        detail.hidden = false;
        renderList();
        const url = new URL(location.href);
        url.searchParams.set('place', place.id);
        history.replaceState(null, '', url);
        $('#detail-title').focus({ preventScroll: true });
        detail.scrollIntoView({
            block: 'nearest',
            behavior: matchMedia('(prefers-reduced-motion: reduce)').matches
                ? 'instant'
                : 'smooth',
        });
    }
    function closeDetail() {
        const id = selected?.id;
        selected = null;
        detail.hidden = true;
        layout.append(detail);
        detail.replaceChildren();
        renderList();
        const url = new URL(location.href);
        url.searchParams.delete('place');
        history.replaceState(null, '', url);
        const rowButton = [...list.querySelectorAll('.place-title')].find(
            (button) => button.dataset.openPlace === id,
        );
        (returnFocus?.isConnected
            ? returnFocus
            : rowButton || $('#place-search')
        ).focus({ preventScroll: true });
    }
    window.ExpaduPlaceSheet = { open: openDetail };
    function syncTravel() {
        const brief = S.read().brief;
        $('#travel-from').value = brief.from || '';
        $('#travel-form').elements.mode.value = [
            'walk',
            'bike',
            'transit',
        ].includes(brief.mode)
            ? brief.mode
            : 'transit';
        const route = detail.querySelector('[data-detail-section=route]');
        if (route && selected) route.innerHTML = routePanel(selected);
    }
    function resetFilters() {
        filters = F.empty();
        renderList();
    }
    $('#place-search').addEventListener('input', (event) => {
        filters.query = event.target.value;
        renderList();
    });
    $('#clear-search').onclick = () => {
        filters.query = '';
        renderList();
        $('#place-search').focus();
    };
    $('#saved-only').onclick = () => {
        filters.saved = !filters.saved;
        renderList();
    };
    $('#area').onchange = (event) => {
        filters.areas = event.target.value ? [event.target.value] : [];
        renderList();
    };
    $('#sort').onchange = (event) => {
        filters.sort = event.target.value;
        renderList();
    };
    $('#reset-filters').onclick = resetFilters;
    $('#categories').onclick = (event) => {
        const button = event.target.closest('button[data-category]');
        if (button) {
            filters = F.withCategory(filters, button.dataset.category);
            renderList();
        }
    };
    $('#travel-form').onsubmit = (event) => {
        event.preventDefault();
        const values = new FormData(event.currentTarget);
        S.update((state) => {
            state.brief.from = values.get('from').trim();
            state.brief.mode = values.get('mode');
        });
        if (!S.isPersistent()) {
            U.toast('Could not keep your preferences. Keep this page open.');
            return;
        }
        $('#travel-control').open = false;
        $('#travel-control summary').focus();
        U.toast('Travel preferences kept');
    };
    $('#hide-photos').onchange = (event) => {
        showPhotos = !event.target.checked;
        const url = new URL(location.href);
        if (showPhotos) url.searchParams.delete('photos');
        else url.searchParams.set('photos', 'none');
        history.replaceState(null, '', url);
        renderList();
        const slot = detail.querySelector('[data-photo-slot]');
        if (slot && selected) slot.innerHTML = photoFigure(selected);
    };
    document.addEventListener(
        'error',
        (event) => {
            const img = event.target;
            if (!(img instanceof HTMLImageElement) || !img.dataset.photoPlace)
                return;
            failedPhotos.add(img.dataset.photoPlace);
            document.querySelectorAll('[data-photo-place]').forEach((photo) => {
                if (photo.dataset.photoPlace === img.dataset.photoPlace)
                    photo.closest('.row-photo, .detail-photo')?.remove();
            });
        },
        true,
    );
    document.addEventListener('click', (event) => {
        const open = event.target.closest('[data-open-place]');
        if (open)
            openDetail(
                P.find((place) => place.id === open.dataset.openPlace),
                'about',
                open,
            );
        if (event.target.closest('[data-reset]')) resetFilters();
        if (event.target.closest('.close-detail')) closeDetail();
        const tab = event.target.closest('[data-detail-tab]');
        if (tab) changeTab(tab.dataset.detailTab);
        if (event.target.closest('[data-edit-travel]')) {
            $('#travel-control').open = true;
            $('#travel-from').focus();
            $('#travel-control').scrollIntoView({ block: 'center' });
        }
        if (event.target.closest('[data-ask-composer]') && selected) {
            S.update((state) => {
                state.prompt = O.composerRequest(selected);
            });
            S.go('composer');
        }
        if (
            !event.target.closest('#travel-control') &&
            !event.target.closest('[data-edit-travel]')
        )
            $('#travel-control').open = false;
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if ($('#travel-control').open) {
            $('#travel-control').open = false;
            $('#travel-control summary').focus();
        } else if (selected) closeDetail();
    });
    window.addEventListener('expadu:change', () => {
        renderList();
        syncTravel();
        ExpaduPlanning.refresh();
    });
    window.addEventListener('storage', () => {
        renderList();
        syncTravel();
        ExpaduPlanning.refresh();
    });
    compact.addEventListener('change', placeDetail);

    function updateMarkers() {
        if (!map) return;
        markers.forEach((marker) => marker.remove());
        markers = matches.map((place) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `map-marker${selected?.id === place.id ? ' is-selected' : ''}`;
            button.setAttribute('aria-label', `Show ${place.title}`);
            button.setAttribute(
                'aria-pressed',
                String(selected?.id === place.id),
            );
            button.innerHTML = I(place.icon);
            button.onclick = () => openDetail(place, 'about', button);
            return new maplibregl.Marker({ element: button })
                .setLngLat([place.lng, place.lat])
                .addTo(map);
        });
    }
    function fitMap() {
        if (!map || !matches.length) return;
        const bounds = new maplibregl.LngLatBounds();
        matches.forEach((place) => bounds.extend([place.lng, place.lat]));
        map.fitBounds(bounds, { padding: 48, maxZoom: 15, duration: 0 });
    }
    async function ensureMap() {
        if (map) {
            map.resize();
            return;
        }
        if (mapLoading) return mapLoading;
        mapLoading = (async () => {
            try {
                if (!window.maplibregl) {
                    const css = document.createElement('link');
                    css.rel = 'stylesheet';
                    css.href = './vendor/maplibre-gl.css';
                    document.head.append(css);
                    await new Promise((resolve, reject) => {
                        const script = document.createElement('script');
                        script.src = './vendor/maplibre-gl.js';
                        script.onload = resolve;
                        script.onerror = reject;
                        document.head.append(script);
                    });
                }
                map = new maplibregl.Map({
                    container: 'places-map',
                    style: 'https://tiles.openfreemap.org/styles/positron',
                    center: [6.96, 50.94],
                    zoom: 11,
                    attributionControl: { compact: false },
                    cooperativeGestures: true,
                });
                map.addControl(
                    new maplibregl.NavigationControl({ showCompass: false }),
                    'top-right',
                );
                map.on('load', () => {
                    $('#map-status').hidden = true;
                });
                map.on('error', () => {
                    $('#map-status').hidden = false;
                    $('#map-status').textContent =
                        'Some map detail could not load. Place records and the list remain available.';
                });
                updateMarkers();
                fitMap();
            } catch {
                $('#map-status').textContent =
                    'The map could not load. All matching places are available in the list below.';
            }
        })();
        return mapLoading;
    }
    document.querySelectorAll('[data-view]').forEach((button) => {
        button.onclick = () => {
            const show = button.dataset.view === 'map';
            $('#map-panel').hidden = !show;
            document
                .querySelectorAll('[data-view]')
                .forEach((other) =>
                    other.setAttribute(
                        'aria-pressed',
                        String(other === button),
                    ),
                );
            if (show) ensureMap();
        };
    });
    $('#fit-map').onclick = fitMap;
    syncTravel();
    renderList();
    const initialPlace = P.find((place) => place.id === params.get('place'));
    if (initialPlace) openDetail(initialPlace);
})();
