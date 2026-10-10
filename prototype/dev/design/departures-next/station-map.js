/* Recorded boarding locations, not vehicle positions or verified platforms. */
(function (root) {
    const scriptBase =
        typeof document !== 'undefined'
            ? new URL('.', document.currentScript.src)
            : null;
    const escape = (value) =>
        String(value).replace(
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
    const normalize = (value) =>
        value == null || value === '' ? 'all' : String(value);
    function groupPoints(points) {
        const groups = new Map();
        (points || []).forEach((point) => {
            if (!Number.isFinite(point.lat) || !Number.isFinite(point.lng))
                return;
            const key = `${point.lat.toFixed(6)},${point.lng.toFixed(6)}`;
            if (!groups.has(key))
                groups.set(key, {
                    key,
                    lat: point.lat,
                    lng: point.lng,
                    points: [],
                });
            groups.get(key).points.push(point);
        });
        return [...groups.values()];
    }
    function matches(group, line = 'all', type = 'all') {
        return group.points.some(
            (point) =>
                (normalize(type) === 'all' || point.mode === type) &&
                (normalize(line) === 'all' ||
                    (point.lines || []).map(String).includes(String(line))),
        );
    }
    const lines = (group, type) =>
        [
            ...new Set(
                group.points
                    .filter(
                        (point) =>
                            normalize(type) === 'all' || point.mode === type,
                    )
                    .flatMap((point) => point.lines || [])
                    .map(String),
            ),
        ].sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    const modeName = (group) => {
        const modes = new Set(group.points.map((point) => point.mode));
        return modes.size > 1
            ? 'Tram and bus'
            : modes.has('bus')
              ? 'Bus'
              : 'Tram';
    };
    const name = (group) =>
        [...new Set(group.points.map((point) => point.name))].join(' / ');
    let loading;
    function library() {
        if (root.maplibregl) return Promise.resolve();
        if (loading) return loading;
        loading = new Promise((resolve, reject) => {
            const cssHref = new URL(
                '../places-optional/vendor/maplibre-gl.css',
                scriptBase,
            ).href;
            if (
                ![...document.querySelectorAll('link[rel="stylesheet"]')].some(
                    (link) => link.href === cssHref,
                )
            ) {
                const css = document.createElement('link');
                css.rel = 'stylesheet';
                css.href = cssHref;
                document.head.append(css);
            }
            const src = new URL(
                '../places-optional/vendor/maplibre-gl.js',
                scriptBase,
            ).href;
            let script = [...document.scripts].find((item) => item.src === src);
            const exists = Boolean(script);
            if (!script) script = document.createElement('script');
            script.addEventListener(
                'load',
                () => (root.maplibregl ? resolve() : reject()),
                { once: true },
            );
            script.addEventListener('error', reject, { once: true });
            if (!exists) {
                script.src = src;
                document.head.append(script);
            }
        }).catch((error) => {
            loading = null;
            throw error;
        });
        return loading;
    }
    function mount(
        container,
        {
            points = [],
            line = 'all',
            type = 'all',
            selectedPointId = null,
            onLineSelect,
            onPointSelect,
        } = {},
    ) {
        const groups = groupPoints(points);
        const groupForId = (id) =>
            id == null
                ? null
                : groups.find((group) =>
                      group.points.some(
                          (point) => String(point.id) === String(id),
                      ),
                  ) || null;
        const label = (group) => `Map point ${groups.indexOf(group) + 1}`;
        let currentLine = normalize(line),
            currentType = normalize(type),
            alive = true,
            map,
            ready = false,
            selected = groupForId(selectedPointId),
            selectedId = selected ? selectedPointId : null;
        const markers = [];
        container.classList.add('station-stop-map');
        container.innerHTML = `<div class="station-map-context"><span class="station-map-legend"><span class="station-map-key tram"></span>Tram<span class="station-map-key bus"></span>Bus</span><span class="station-map-kind">Candidate stops</span></div><div class="station-map-stage"><div class="station-map-canvas" aria-label="Recorded candidate station stops on OpenStreetMap" hidden></div><div class="station-map-fallback"><p class="station-map-status" role="status">Loading map…</p><div class="station-map-stop-list"></div></div></div><div class="station-map-caption" aria-live="polite"></div><div class="station-map-detail" hidden></div>`;
        const canvas = container.querySelector('.station-map-canvas');
        const fallback = container.querySelector('.station-map-fallback');
        const status = container.querySelector('.station-map-status');
        const list = container.querySelector('.station-map-stop-list');
        const caption = container.querySelector('.station-map-caption');
        const detail = container.querySelector('.station-map-detail');
        const selectLine = (event) => {
            const button = event.target.closest('[data-station-map-line]');
            if (!button) return;
            const nextLine = button.dataset.stationMapLine;
            update({ line: nextLine, type: currentType });
            onLineSelect?.(nextLine);
        };
        function chipMarkup(group) {
            return lines(group, currentType)
                .map(
                    (item) =>
                        `<button type="button" data-station-map-line="${escape(item)}" aria-label="Show departures for line ${escape(item)}" aria-pressed="${item === currentLine}">${escape(item)}</button>`,
                )
                .join('');
        }
        function clearSelection(notify = false) {
            const hadSelection = Boolean(selected);
            selected = null;
            selectedId = null;
            detail.hidden = true;
            markers.forEach(({ button }) =>
                button.setAttribute('aria-expanded', 'false'),
            );
            if (notify && hadSelection) onPointSelect?.(null);
        }
        function showDetail(group, notify = false) {
            selected = group;
            if (
                !group.points.some(
                    (point) => String(point.id) === String(selectedId),
                )
            )
                selectedId = group.points[0].id;
            detail.hidden = false;
            detail.innerHTML = `<div class="station-map-detail-heading"><strong>${label(group)} · ${escape(name(group))}</strong><button type="button" class="station-map-detail-close" aria-label="Close stop details">×</button></div><span>${modeName(group)} · Boarding side unverified</span><div class="station-map-lines">${chipMarkup(group)}</div><p>This is a candidate location, not a confirmed platform. Check the direction on the station signs.</p>`;
            detail.querySelector('.station-map-detail-close').onclick = () => {
                clearSelection(true);
                markers
                    .find((marker) => marker.group === group)
                    ?.button.focus({ preventScroll: true });
            };
            markers.forEach(({ group: point, button }) =>
                button.setAttribute('aria-expanded', String(point === group)),
            );
            if (notify) onPointSelect?.(selectedId);
        }
        function update({
            line: nextLine = currentLine,
            type: nextType = currentType,
            selectedPointId: nextPointId = selectedId,
        } = {}) {
            if (!alive) return;
            currentLine = normalize(nextLine);
            currentType = normalize(nextType);
            selected = groupForId(nextPointId);
            selectedId = selected ? nextPointId : null;
            const matching = groups.filter((group) =>
                matches(group, currentLine, currentType),
            );
            caption.textContent =
                currentLine === 'all'
                    ? `${matching.length} candidate locations · Boarding sides unverified.`
                    : matching.length
                      ? `Candidates for line ${currentLine} · Boarding side unverified.`
                      : `No recorded stop location for line ${currentLine}. Check the station signs.`;
            markers.forEach(({ group, button }) => {
                button.classList.toggle(
                    'is-muted',
                    !matches(group, currentLine, currentType),
                );
                button.classList.toggle(
                    'is-matching',
                    currentLine !== 'all' &&
                        matches(group, currentLine, currentType),
                );
            });
            list.innerHTML = matching.length
                ? matching
                      .map(
                          (group) =>
                              `<div class="station-map-list-stop"><span class="station-map-key ${group.points.every((point) => point.mode === 'bus') ? 'bus' : 'tram'}"></span><div><strong>${label(group)} · ${escape(name(group))}</strong><small>${modeName(group)} candidate${group.points.length > 1 ? ' · Shared location' : ''}</small><div class="station-map-lines">${chipMarkup(group)}</div></div></div>`,
                      )
                      .join('')
                : '<p>No matching recorded stop locations.</p>';
            if (selected && matches(selected, currentLine, currentType))
                showDetail(selected);
            else clearSelection(true);
        }
        container.addEventListener('click', selectLine);
        update();
        function unavailable() {
            if (!alive) return;
            canvas.hidden = true;
            fallback.hidden = false;
            status.textContent =
                'Map unavailable. Candidate stops are listed below; confirm your boarding side on the station signs.';
        }
        const timeout = setTimeout(() => {
            if (!ready) unavailable();
        }, 10000);
        if (!groups.length) {
            clearTimeout(timeout);
            status.textContent =
                'Stop locations are not available for this station.';
        } else
            library()
                .then(() => {
                    if (!alive || !container.isConnected) return;
                    canvas.hidden = false;
                    map = new root.maplibregl.Map({
                        container: canvas,
                        style: 'https://tiles.openfreemap.org/styles/positron',
                        center: [groups[0].lng, groups[0].lat],
                        zoom: 16,
                        attributionControl: { compact: true },
                        dragRotate: false,
                        pitchWithRotate: false,
                        scrollZoom: false,
                    });
                    map.addControl(
                        new root.maplibregl.NavigationControl({
                            showCompass: false,
                        }),
                        'top-right',
                    );
                    map.on('error', () => {
                        if (!ready) unavailable();
                    });
                    map.on('webglcontextlost', unavailable);
                    map.on('load', () => {
                        if (!alive) return;
                        ready = true;
                        clearTimeout(timeout);
                        canvas.hidden = false;
                        fallback.hidden = true;
                        groups.forEach((group) => {
                            const button = document.createElement('button');
                            const allBus = group.points.every(
                                (point) => point.mode === 'bus',
                            );
                            button.type = 'button';
                            button.className = `station-map-marker ${allBus ? 'bus' : 'tram'}`;
                            button.setAttribute(
                                'aria-label',
                                `${label(group)}, ${name(group)}, ${modeName(group)} candidate, lines ${lines(group, 'all').join(', ')}`,
                            );
                            button.setAttribute('aria-expanded', 'false');
                            button.innerHTML = `<span>${root.ExpaduIcon ? root.ExpaduIcon(allBus ? 'bus' : 'train') : allBus ? 'B' : 'T'}</span>`;
                            button.onclick = () => showDetail(group, true);
                            new root.maplibregl.Marker({
                                element: button,
                                anchor: 'center',
                            })
                                .setLngLat([group.lng, group.lat])
                                .addTo(map);
                            markers.push({ button, group });
                        });
                        const bounds = new root.maplibregl.LngLatBounds();
                        groups.forEach((group) =>
                            bounds.extend([group.lng, group.lat]),
                        );
                        map.resize();
                        // Small phone maps keep most of their height for the
                        // markers; the zoom buttons stay clear on the right.
                        const small = container.clientHeight < 260;
                        map.fitBounds(bounds, {
                            padding: small
                                ? { top: 30, right: 54, bottom: 30, left: 24 }
                                : { top: 52, right: 54, bottom: 48, left: 40 },
                            maxZoom: 17,
                            duration: 0,
                        });
                        // The map credit starts collapsed to its "i", which opens it.
                        container
                            .querySelector('.maplibregl-ctrl-attrib')
                            ?.classList.remove('maplibregl-compact-show');
                        update();
                    });
                })
                .catch(unavailable);
        const resize =
            typeof ResizeObserver === 'function'
                ? new ResizeObserver(() => {
                      if (ready) map.resize();
                  })
                : null;
        resize?.observe(container);
        return {
            update,
            resize() {
                if (alive && ready) map.resize();
            },
            destroy() {
                alive = false;
                clearTimeout(timeout);
                resize?.disconnect();
                container.removeEventListener('click', selectLine);
                map?.remove();
                container.replaceChildren();
            },
        };
    }
    // `load` lets Nearby share the same vendored MapLibre.
    root.StationMap = { mount, groupPoints, matches, load: library };
})(globalThis);
