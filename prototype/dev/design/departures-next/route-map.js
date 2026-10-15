/* Map presentation only. No GPS, routing requests or simulated live vehicles. */
(function (root) {
    const N = root.JourneyNavigationModel;
    const scriptBase = new URL('.', document.currentScript.src);
    let loading;
    function library() {
        if (root.maplibregl) return Promise.resolve();
        if (!loading)
            loading = new Promise((resolve, reject) => {
                const css = document.createElement('link');
                css.rel = 'stylesheet';
                css.href = new URL(
                    '../places-optional/vendor/maplibre-gl.css',
                    scriptBase,
                ).href;
                document.head.append(css);
                const script = document.createElement('script');
                script.src = new URL(
                    '../places-optional/vendor/maplibre-gl.js',
                    scriptBase,
                ).href;
                script.onload = resolve;
                script.onerror = reject;
                document.head.append(script);
            }).catch((error) => {
                loading = null;
                throw error;
            });
        return loading;
    }
    function mount(container, route, { onSelect, padding, onReady } = {}) {
        let alive = true,
            map,
            ready = false,
            current,
            selected = 0,
            active = false;
        const markers = [];
        const steps = N.steps(route);
        const fallback =
            container.parentElement.querySelector('.nav-map-fallback');
        const duration = () =>
            matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 320;
        const pad = () =>
            padding?.() || { top: 80, right: 40, bottom: 80, left: 40 };
        function overview(animate = true) {
            if (!ready) return;
            const bounds = new maplibregl.LngLatBounds();
            steps.forEach((step) => bounds.extend(step.point));
            map.fitBounds(bounds, {
                padding: pad(),
                maxZoom: 15.5,
                duration: animate ? duration() : 0,
            });
        }
        function focus(index, animate = true) {
            selected = index;
            markers.forEach(({ button, place }) =>
                button.classList.toggle(
                    'is-selected',
                    place === steps[index].place,
                ),
            );
            if (!ready) return;
            const point = steps[index].point;
            map.fitBounds(
                [
                    [point[0] - 0.0006, point[1] - 0.00035],
                    [point[0] + 0.0006, point[1] + 0.00035],
                ],
                {
                    padding: pad(),
                    maxZoom: 16,
                    duration: animate ? duration() : 0,
                },
            );
        }
        function position(index, visible = true) {
            active = visible;
            selected = index;
            if (!current) return;
            current.setLngLat(steps[index].point);
            current.getElement().hidden = !visible;
        }
        const controller = {
            overview,
            focus,
            position,
            zoom: (delta) => {
                if (ready)
                    map.zoomTo(map.getZoom() + delta, { duration: duration() });
            },
            resize: () => {
                map?.resize();
            },
            destroy: () => {
                alive = false;
                map?.remove();
            },
        };
        library()
            .then(() => {
                if (!alive || !container.isConnected) return;
                map = new maplibregl.Map({
                    container,
                    style: 'https://tiles.openfreemap.org/styles/positron',
                    center: N.places.neumarkt.point,
                    zoom: 14,
                    attributionControl: { compact: true },
                    dragRotate: false,
                    pitchWithRotate: false,
                });
                map.on('error', () => {
                    if (!ready) {
                        fallback.hidden = false;
                        fallback.querySelector('strong').textContent =
                            'Map unavailable';
                    }
                });
                map.on('load', () => {
                    if (!alive) return;
                    ready = true;
                    fallback.hidden = true;
                    map.addSource('journey-overview', {
                        type: 'geojson',
                        data: N.geometry(route),
                    });
                    map.addLayer({
                        id: 'journey-case',
                        type: 'line',
                        source: 'journey-overview',
                        layout: { 'line-join': 'round', 'line-cap': 'round' },
                        paint: { 'line-color': '#ffffff', 'line-width': 8 },
                    });
                    map.addLayer({
                        id: 'journey-path',
                        type: 'line',
                        source: 'journey-overview',
                        layout: { 'line-join': 'round', 'line-cap': 'round' },
                        paint: {
                            'line-color': '#228da8',
                            'line-width': 4,
                            'line-dasharray': [2, 1.5],
                        },
                    });
                    const unique = new Set();
                    steps.forEach((step, index) => {
                        if (unique.has(step.place)) return;
                        unique.add(step.place);
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = `nav-map-stop ${step.destination ? 'destination' : ''} ${step.place === 'dom' ? 'label-above' : ''}`;
                        button.setAttribute(
                            'aria-label',
                            `Show ${step.placeName} in itinerary`,
                        );
                        button.innerHTML = `<span class="nav-stop-dot">${root.ExpaduIcon(step.destination ? 'pin' : 'train')}</span><span class="nav-stop-label"></span>`;
                        button.querySelector('.nav-stop-label').textContent =
                            step.placeName;
                        button.onclick = (event) => {
                            // Nearby 44px targets can overlap at an overview zoom. Resolve
                            // pointer taps to the nearest visible stop centre, not DOM order.
                            // Keyboard activation always selects its focused stop.
                            let chosen = index;
                            if (event.detail > 0) {
                                let nearest = Infinity;
                                markers.forEach(
                                    ({ button: target, index: candidate }) => {
                                        const rect =
                                            target.getBoundingClientRect();
                                        const distance = Math.hypot(
                                            event.clientX -
                                                rect.x -
                                                rect.width / 2,
                                            event.clientY -
                                                rect.y -
                                                rect.height / 2,
                                        );
                                        if (distance < nearest) {
                                            nearest = distance;
                                            chosen = candidate;
                                        }
                                    },
                                );
                            }
                            onSelect?.(chosen);
                        };
                        new maplibregl.Marker({
                            element: button,
                            anchor: 'center',
                        })
                            .setLngLat(step.point)
                            .addTo(map);
                        markers.push({ button, place: step.place, index });
                    });
                    const point = document.createElement('span');
                    point.className = 'nav-preview-position';
                    point.setAttribute('role', 'img');
                    point.setAttribute(
                        'aria-label',
                        'Sample position, not your live location',
                    );
                    current = new maplibregl.Marker({ element: point })
                        .setLngLat(steps[selected].point)
                        .addTo(map);
                    position(selected, active);
                    overview(false);
                    onReady?.();
                });
            })
            .catch(() => {
                if (!alive) return;
                fallback.hidden = false;
                fallback.querySelector('strong').textContent =
                    'Map unavailable';
            });
        return controller;
    }
    root.JourneyRouteMap = { mount };
})(globalThis);
