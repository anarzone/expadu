/* Station discovery over the captured catalogue only. Device location is used
   locally on request, never persisted and never treated as live timetable data. */
(function (root) {
    const storageKey = 'expadu.travel.stop-discovery.v1';
    const normal = (value) =>
        String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLocaleLowerCase()
            .trim();
    const escape = (value) =>
        String(value ?? '').replace(
            /[&<>"']/g,
            (char) =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                })[char],
        );
    function coordinates(point) {
        return (
            !!point &&
            Number.isFinite(point.lat) &&
            Number.isFinite(point.lng) &&
            Math.abs(point.lat) <= 90 &&
            Math.abs(point.lng) <= 180
        );
    }
    function catalogue(stops = [], entries = []) {
        return stops
            .filter((stop) => typeof stop.id === 'string' && stop.name)
            .map((stop) => ({
                ...stop,
                lines: [
                    ...new Set(
                        entries
                            .filter((entry) => entry.stopId === stop.id)
                            .map((entry) => String(entry.line)),
                    ),
                ].sort((a, b) =>
                    a.localeCompare(b, undefined, { numeric: true }),
                ),
            }));
    }
    function search(stops, query) {
        const terms = normal(query).split(/\s+/).filter(Boolean);
        return stops.filter((stop) => {
            const haystack = normal(
                `${stop.name} ${(stop.lines || []).join(' ')}`,
            );
            return terms.every((term) => haystack.includes(term));
        });
    }
    function nearby(stops, origin, radiusMeters = 3000) {
        if (
            !coordinates(origin) ||
            !Number.isFinite(radiusMeters) ||
            radiusMeters < 0
        )
            return [];
        const radians = (number) => (number * Math.PI) / 180;
        return stops
            .filter(coordinates)
            .map((stop) => {
                const a =
                    Math.sin(radians(stop.lat - origin.lat) / 2) ** 2 +
                    Math.cos(radians(origin.lat)) *
                        Math.cos(radians(stop.lat)) *
                        Math.sin(radians(stop.lng - origin.lng) / 2) ** 2;
                const distanceMeters =
                    6371000 *
                    2 *
                    Math.atan2(
                        Math.sqrt(Math.min(1, a)),
                        Math.sqrt(Math.max(0, 1 - a)),
                    );
                return { ...stop, distanceMeters };
            })
            .filter((stop) => stop.distanceMeters <= radiusMeters)
            .sort(
                (a, b) =>
                    a.distanceMeters - b.distanceMeters ||
                    a.name.localeCompare(b.name),
            );
    }
    function defaultStorage() {
        try {
            return root.localStorage;
        } catch {
            return null;
        }
    }
    function createStore(stops, storage = defaultStorage()) {
        const ids = new Set(stops.map((stop) => stop.id));
        const clean = (values, limit) =>
            [
                ...new Set(
                    Array.isArray(values)
                        ? values.filter(
                              (id) => typeof id === 'string' && ids.has(id),
                          )
                        : [],
                ),
            ].slice(0, limit);
        let state = { recent: [], saved: [] };
        try {
            const saved = JSON.parse(storage?.getItem(storageKey) || 'null');
            if (saved?.version === 1)
                state = {
                    recent: clean(saved.recent, 8),
                    saved: clean(saved.saved, 20),
                };
        } catch {
            /* Unavailable or old storage starts with an empty personal list. */
        }
        function persist() {
            try {
                storage?.setItem(
                    storageKey,
                    JSON.stringify({ version: 1, ...state }),
                );
            } catch {
                /* Choices still work in this session. */
            }
        }
        return {
            snapshot: () => ({
                recent: [...state.recent],
                saved: [...state.saved],
            }),
            recordSelection(id) {
                if (!ids.has(id)) return;
                state.recent = clean([id, ...state.recent], 8);
                persist();
            },
            toggleSaved(id) {
                if (!ids.has(id)) return;
                state.saved = state.saved.includes(id)
                    ? state.saved.filter((item) => item !== id)
                    : clean([id, ...state.saved], 20);
                persist();
            },
        };
    }
    function mount(container, options = {}) {
        const stops = catalogue(options.stops || [], options.entries || []);
        const store = createStore(stops, options.storage);
        const icon = options.icon || (() => '');
        const areas = stops.filter(coordinates);
        let context = 'search',
            query = '',
            origin = null,
            originName = '',
            locating = false,
            locationMessage = '',
            destroyed = false,
            locationRequest = 0;
        const id = `stop-discovery-${++mount.count}`;
        container.innerHTML = `<div class="stop-discovery"><label class="sd-search" for="${id}-query">${icon('search')}<input id="${id}-query" type="search" placeholder="Stop or line" aria-label="Search captured stops or lines" autocomplete="off" autocorrect="off" spellcheck="false"></label><div class="sd-contexts" role="group" aria-label="Find a stop">${[
            ['search', 'Search'],
            ['nearby', 'Nearby'],
            ['recent', 'Recent'],
            ['saved', 'Saved'],
        ]
            .map(
                ([key, label]) =>
                    `<button type="button" data-discovery-context="${key}" aria-pressed="${key === 'search'}">${label}</button>`,
            )
            .join(
                '',
            )}</div><div class="sd-location"></div><div class="sd-results" aria-label="Captured stops"></div><p class="sd-status" role="status" aria-live="polite"></p><p class="sd-coverage">${stops.length} captured stops · live stop search unavailable</p></div>`;
        const input = container.querySelector('.sd-search input');
        const resultBox = container.querySelector('.sd-results');
        const locationBox = container.querySelector('.sd-location');
        const status = container.querySelector('.sd-status');
        function results() {
            if (query) return search(stops, query);
            const personal = store.snapshot();
            if (context === 'recent' || context === 'saved')
                return personal[context]
                    .map((key) => stops.find((stop) => stop.id === key))
                    .filter(Boolean);
            if (context === 'nearby') return nearby(stops, origin);
            return stops;
        }
        function describe(stop) {
            const mode =
                stop.mode === 'mixed'
                    ? 'Tram & bus'
                    : stop.mode === 'bus'
                      ? 'Bus'
                      : 'Tram';
            const date = stop.capture?.date;
            const captured = /^\d{4}-\d{2}-\d{2}$/.test(date || '')
                ? new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
                      day: 'numeric',
                      month: 'short',
                      timeZone: 'UTC',
                  })
                : '';
            return `${mode}${stop.lines.length ? ` · ${stop.lines.join(', ')}` : ''}${captured ? ` · ${captured} sample` : ''}`;
        }
        function render() {
            if (destroyed) return;
            container
                .querySelectorAll('[data-discovery-context]')
                .forEach((button) =>
                    button.setAttribute(
                        'aria-pressed',
                        String(button.dataset.discoveryContext === context),
                    ),
                );
            locationBox.innerHTML =
                context === 'nearby'
                    ? `<div class="sd-area"><label for="${id}-area">Search area</label><select id="${id}-area" aria-label="Choose a sample search area"><option value="">Choose a sample area</option>${areas.map((stop) => `<option value="${escape(stop.id)}"${origin?.id === stop.id ? ' selected' : ''}>Near ${escape(stop.name)}</option>`).join('')}</select><button type="button" class="sd-locate" data-discovery-locate${locating ? ' disabled' : ''}>${icon('locate')}<span>${locating ? 'Locating…' : 'Use my location'}</span></button></div>`
                    : '';
            const found = results();
            const saved = store.snapshot().saved;
            resultBox.innerHTML = found
                .map(
                    (stop) =>
                        `<div class="sd-row"><button type="button" class="sd-choose" data-stop="${escape(stop.id)}" aria-pressed="${stop.id === options.selectedId}">${icon(stop.mode === 'mixed' ? 'pin' : stop.mode === 'bus' ? 'bus' : 'train')}<span><strong>${escape(stop.name)}</strong><small>${escape(describe(stop))}</small>${Number.isFinite(stop.distanceMeters) ? `<small>${stop.distanceMeters < 50 ? 'Under 50 m' : stop.distanceMeters < 1000 ? `${Math.round(stop.distanceMeters / 25) * 25} m` : `${(stop.distanceMeters / 1000).toFixed(1)} km`} straight line</small>` : ''}</span>${stop.id === options.selectedId ? icon('check') : ''}</button><button type="button" class="sd-save" data-discovery-save="${escape(stop.id)}" aria-label="${saved.includes(stop.id) ? 'Unsave' : 'Save'} ${escape(stop.name)}" aria-pressed="${saved.includes(stop.id)}">${icon('bookmark') || 'Save'}</button></div>`,
                )
                .join('');
            let message = '';
            if (context === 'nearby') {
                message =
                    locationMessage ||
                    (!origin
                        ? 'Choose an area or use your location.'
                        : !found.length
                          ? 'No captured stops within 3 km. This sample does not cover every stop.'
                          : `Near ${originName}. ${areas.length} of ${stops.length} captured stops have coordinates; distance is not a walking route.`);
            } else if (!found.length) {
                message = query
                    ? 'No captured stop matches. Try another name or line.'
                    : context === 'recent'
                      ? 'Stops you choose will appear here.'
                      : context === 'saved'
                        ? 'Save a stop with its bookmark to find it here.'
                        : 'No captured stops available.';
            }
            status.textContent = message;
            status.hidden = !message;
            options.onResize?.();
        }
        function locate() {
            const geolocation =
                options.geolocation || root.navigator?.geolocation;
            if (!geolocation) {
                locationMessage =
                    'Location is unavailable. Choose a sample area instead.';
                render();
                return;
            }
            const request = ++locationRequest;
            locating = true;
            origin = null;
            originName = '';
            locationMessage = 'Finding your location…';
            render();
            const unavailable = () => {
                if (destroyed || request !== locationRequest) return;
                locating = false;
                locationMessage =
                    'Location is unavailable. Choose a sample area instead.';
                render();
            };
            try {
                geolocation.getCurrentPosition(
                    (position) => {
                        if (destroyed || request !== locationRequest) return;
                        locating = false;
                        const point = {
                            lat: position.coords.latitude,
                            lng: position.coords.longitude,
                        };
                        if (!coordinates(point)) {
                            locationMessage =
                                'Location is unavailable. Choose a sample area instead.';
                        } else {
                            origin = point;
                            originName = 'your location';
                            locationMessage = '';
                        }
                        render();
                    },
                    unavailable,
                    {
                        enableHighAccuracy: false,
                        timeout: 8000,
                        maximumAge: 60000,
                    },
                );
            } catch {
                unavailable();
            }
        }
        function onClick(event) {
            const button = event.target.closest('button');
            if (!button || !container.contains(button)) return;
            if (button.dataset.stop) {
                store.recordSelection(button.dataset.stop);
                if (options.onSelect) {
                    event.stopPropagation();
                    options.onSelect(button.dataset.stop);
                }
                return;
            }
            if (button.dataset.discoveryContext) {
                context = button.dataset.discoveryContext;
                query = '';
                input.value = '';
                render();
            } else if (button.dataset.discoverySave) {
                const stopId = button.dataset.discoverySave;
                store.toggleSaved(stopId);
                render();
                const replacement = [
                    ...container.querySelectorAll('[data-discovery-save]'),
                ].find((item) => item.dataset.discoverySave === stopId);
                (
                    replacement ||
                    container.querySelector('[data-discovery-context="saved"]')
                ).focus({ preventScroll: true });
            } else if (button.hasAttribute('data-discovery-locate')) {
                locate();
            }
        }
        function onInput(event) {
            if (event.target !== input) return;
            query = input.value.trim();
            context = 'search';
            render();
        }
        function onChange(event) {
            if (event.target.id !== `${id}-area`) return;
            locationRequest++;
            locating = false;
            const area = areas.find((stop) => stop.id === event.target.value);
            origin = area || null;
            originName = area?.name || '';
            locationMessage = '';
            render();
            container
                .querySelector('.sd-area select')
                ?.focus({ preventScroll: true });
        }
        function onKey(event) {
            if (event.target === input && event.key === 'ArrowDown') {
                const first = resultBox.querySelector('[data-stop]');
                if (first) {
                    event.preventDefault();
                    first.focus();
                }
            }
        }
        container.addEventListener('click', onClick);
        container.addEventListener('input', onInput);
        container.addEventListener('change', onChange);
        container.addEventListener('keydown', onKey);
        render();
        return {
            focus: () => input.focus({ preventScroll: true }),
            recordSelection: store.recordSelection,
            destroy() {
                destroyed = true;
                origin = null;
                container.removeEventListener('click', onClick);
                container.removeEventListener('input', onInput);
                container.removeEventListener('change', onChange);
                container.removeEventListener('keydown', onKey);
            },
        };
    }
    mount.count = 0;
    const api = {
        catalogue,
        search,
        nearby,
        createStore,
        mount,
        recordSelection: (id, stops) => createStore(stops).recordSelection(id),
    };
    root.StopDiscovery = api;
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(globalThis);
