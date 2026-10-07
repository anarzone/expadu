/* Around here: places of one kind near a point, as a list beside a map.
   Opened from Composer, Today and Travel ("Show on map"); not a navigation
   destination. Sample places only (connected/sample-places.js). */
(function () {
    const P = ExpaduPlaces,
        U = ExpaduUI,
        S = ExpaduStore,
        E = U.esc,
        I = U.icon;
    const params = new URLSearchParams(location.search);
    const day = window.ExpaduSampleDay || {};
    const at = {
        date: day.date || new Date().toISOString().slice(0, 10),
        time: /^\d{2}:\d{2}$/.test(params.get('time') || '')
            ? params.get('time')
            : day.time || '12:00',
    };
    // The point: a known anchor, a place, or coordinates with a name.
    const fromPlace = P.byId(params.get('near') || '');
    const custom = Number.isFinite(+params.get('lat')) &&
        Number.isFinite(+params.get('lng')) &&
        params.get('lat') && {
            name: params.get('name') || 'this point',
            lat: +params.get('lat'),
            lng: +params.get('lng'),
        };
    const from =
        P.anchors[params.get('near')] ||
        (fromPlace && {
            id: fromPlace.id,
            name: fromPlace.name,
            lat: fromPlace.lat,
            lng: fromPlace.lng,
        }) ||
        custom ||
        P.anchors.neumarkt;
    let kind = P.kinds[params.get('kind')] ? params.get('kind') : '';
    let openOnly = params.get('open') === '1';
    let selected = null;
    const kindList = Object.keys(P.kinds).filter((key) =>
        P.all.some((place) => place.kind === key),
    );
    const time = at.time;
    const dayLabel = new Date(`${at.date}T12:00:00`).toLocaleDateString(
        'en-GB',
        { weekday: 'short', day: 'numeric', month: 'short' },
    );
    const main = document.querySelector('#main');
    const back =
        document.referrer &&
        new URL(document.referrer).origin === location.origin
            ? `<button type="button" class="around-back" data-around-back>${I('back')}<span>Back</span></button>`
            : `<a class="around-back" href="${E(S.routes.composer)}">${I('back')}<span>Composer</span></a>`;
    main.innerHTML = `<div class="around">
        <header class="around-head">${back}<div><h1>Around ${E(from.name)}</h1><p>Sample places · listed hours for ${E(dayLabel)}, ${E(time)}</p></div></header>
        <div class="around-filters" role="group" aria-label="Kind of place">
            <button type="button" data-around-kind="" aria-pressed="${!kind}">All</button>
            ${kindList.map((key) => `<button type="button" data-around-kind="${key}" aria-pressed="${kind === key}">${P.icon(key)}<span>${E(P.kinds[key].many)}</span></button>`).join('')}
            <button type="button" class="around-open-toggle" data-around-open aria-pressed="${openOnly}" title="Only places whose listed hours include ${E(time)}">${P.icon('clock')}<span>Open at ${E(time)}</span></button>
        </div>
        <div class="around-body">
            <section class="around-list" aria-label="Places"><p class="around-count" role="status"></p><div class="around-results"></div><p class="place-map-credit">Place data and maps © OpenStreetMap contributors · OpenFreeMap. Straight-line distances from ${E(from.name)}.</p></section>
            <div class="around-map" aria-label="Map of these places"><div class="around-map-canvas"></div><p class="around-map-fallback" hidden>${I('pin')}<span>Map unavailable. The list still works.</span></p></div>
        </div>
    </div>`;
    const results = main.querySelector('.around-results');
    const count = main.querySelector('.around-count');
    let map,
        markers = [];
    function current() {
        const list = P.near(from, {
            kinds: kind ? [kind] : null,
            limit: 12,
            at,
            open: openOnly,
        });
        // Within 2 km, plus up to three further away when that's thin.
        const close = list.filter(({ km }) => km <= 2);
        return close.length >= 3
            ? close
            : [
                  ...close,
                  ...list.filter(({ km }) => km > 2).slice(0, 3 - close.length),
              ];
    }
    function render() {
        const list = current();
        const label = kind ? P.kinds[kind].many.toLowerCase() : 'places';
        const nearby = list.filter(({ km }) => km <= 2).length;
        count.textContent = list.length
            ? `${nearby || 'No'} ${nearby === 1 ? label.replace(/ies$/, 'y').replace(/s$/, '') : label} within 2 km${openOnly ? `, listed as open at ${time}` : ''}`
            : `No ${label} in the sample${openOnly ? ` listed as open at ${time}` : ''} near ${from.name}.`;
        // Close by first; a few further away when the area has little.
        const row = ({ place }) =>
            P.card(place, { from, at, variant: 'row', plan: false });
        const close = list.filter(({ km }) => km <= 2);
        const further = list.filter(({ km }) => km > 2);
        results.innerHTML =
            close.map(row).join('') +
            (further.length
                ? `<p class="around-further">Further away</p>${further.map(row).join('')}`
                : '');
        if (!list.some(({ place }) => place.id === selected)) selected = null;
        syncSelection();
        drawMarkers(list);
        window.ExpaduPlanning?.refresh();
        const url = new URL(location.href);
        url.searchParams.set('kind', kind);
        if (!kind) url.searchParams.delete('kind');
        url.searchParams.set('open', openOnly ? '1' : '0');
        if (!openOnly) url.searchParams.delete('open');
        history.replaceState(history.state, '', url);
    }
    function syncSelection() {
        results
            .querySelectorAll('[data-place-card]')
            .forEach((card) =>
                card.classList.toggle(
                    'is-selected',
                    card.dataset.placeCard === selected,
                ),
            );
        markers.forEach(({ id, element }) =>
            element.classList.toggle('is-selected', id === selected),
        );
    }
    function select(id, { fly = false, scroll = false } = {}) {
        selected = id;
        syncSelection();
        const place = P.byId(id);
        if (fly && map && place)
            map.easeTo({
                center: [place.lng, place.lat],
                duration: matchMedia('(prefers-reduced-motion: reduce)').matches
                    ? 0
                    : 400,
            });
        if (scroll)
            results
                .querySelector(`[data-place-card="${CSS.escape(id)}"]`)
                ?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
    function drawMarkers(list) {
        if (!map) return;
        markers.forEach(({ marker }) => marker.remove());
        markers = list.map(({ place }) => {
            const element = document.createElement('button');
            element.type = 'button';
            element.className = 'around-marker';
            element.dataset.kind = place.kind;
            element.setAttribute('aria-label', place.name);
            element.innerHTML = P.icon(place.kind);
            element.addEventListener('click', (event) => {
                event.stopPropagation();
                select(place.id, { scroll: true });
            });
            const marker = new maplibregl.Marker({ element })
                .setLngLat([place.lng, place.lat])
                .addTo(map);
            return { id: place.id, element, marker };
        });
        const bounds = new maplibregl.LngLatBounds();
        bounds.extend([from.lng, from.lat]);
        list.forEach(({ place }) => bounds.extend([place.lng, place.lat]));
        map.fitBounds(bounds, {
            padding: 48,
            maxZoom: 16,
            duration: 0,
        });
        syncSelection();
    }
    function loadMap() {
        const canvas = main.querySelector('.around-map-canvas');
        const fail = () =>
            (main.querySelector('.around-map-fallback').hidden = false);
        const css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = '../places-optional/vendor/maplibre-gl.css';
        document.head.append(css);
        const script = document.createElement('script');
        script.src = '../places-optional/vendor/maplibre-gl.js';
        script.onerror = fail;
        script.onload = () => {
            map = new maplibregl.Map({
                container: canvas,
                preserveDrawingBuffer: true,
                style: 'https://tiles.openfreemap.org/styles/positron',
                center: [from.lng, from.lat],
                zoom: 15,
                attributionControl: { compact: true },
                dragRotate: false,
                pitchWithRotate: false,
            });
            map.on('error', () => {
                if (!map.loaded()) fail();
            });
            map.on('load', () => {
                // Keep the map credit collapsed; it's also under the list.
                canvas
                    .querySelector('.maplibregl-ctrl-attrib')
                    ?.classList.remove('maplibregl-compact-show');
                const here = document.createElement('span');
                here.className = 'around-here-marker';
                here.title = from.name;
                new maplibregl.Marker({ element: here })
                    .setLngLat([from.lng, from.lat])
                    .addTo(map);
                drawMarkers(current());
            });
        };
        document.head.append(script);
    }
    main.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.matches('[data-around-back]')) history.back();
        if (button.hasAttribute('data-around-kind')) {
            kind = button.dataset.aroundKind;
            main.querySelectorAll('[data-around-kind]').forEach((b) =>
                b.setAttribute('aria-pressed', String(b === button)),
            );
            render();
        }
        if (button.matches('[data-around-open]')) {
            openOnly = !openOnly;
            button.setAttribute('aria-pressed', String(openOnly));
            render();
        }
        if (button.dataset.placeOpen) {
            select(button.dataset.placeOpen, { fly: true });
            ExpaduPlaceSheet.open(P.item(P.byId(button.dataset.placeOpen)));
        }
    });
    // Hovering a row points it out on the map, without opening it.
    results.addEventListener('pointerover', (event) => {
        const card = event.target.closest('[data-place-card]');
        if (card && event.pointerType === 'mouse') {
            markers.forEach(({ id, element }) =>
                element.classList.toggle(
                    'is-hovered',
                    id === card.dataset.placeCard,
                ),
            );
        }
    });
    window.addEventListener('expadu:change', () =>
        window.ExpaduPlanning?.refresh(),
    );
    render();
    loadMap();
})();
