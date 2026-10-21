/* One place, one sheet. Tasks expand in context and keep their drafts. */
(function () {
    const S = ExpaduStore,
        M = ExpaduModel,
        U = ExpaduUI,
        E = U.esc,
        I = U.icon;
    const safeURL = (value) => {
        try {
            const url = new URL(value);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
        } catch {
            return '';
        }
    };
    function mapURL(item) {
        return Number.isFinite(item.lat) && Number.isFinite(item.lng)
            ? `https://www.openstreetmap.org/?mlat=${item.lat}&mlon=${item.lng}#map=17/${item.lat}/${item.lng}`
            : `https://www.openstreetmap.org/search?query=${encodeURIComponent([item.title, item.area, item.location].filter(Boolean).join(' '))}`;
    }
    function photoCredit(item) {
        if (!item.image) return '';
        const photo = item.photo;
        return photo
            ? `<p class="place-photo-credit"><a href="${E(safeURL(photo.source))}" target="_blank" rel="noopener">Photo: ${E(photo.attribution || photo.author)}</a> · <a href="${E(safeURL(photo.licenseUrl))}" target="_blank" rel="noopener">${E(photo.license)}</a></p>`
            : `<a class="place-photo-credit" href="${E(safeURL(item.source))}" target="_blank" rel="noopener">Photo: ${E(item.credit)}</a>`;
    }
    function about(item) {
        // A catalogue place: what the listing records, "Not listed" for the
        // rest, and where it comes from (connected/places.js).
        if (item.place && window.ExpaduPlaces) {
            const day = window.ExpaduSampleDay;
            return window.ExpaduPlaces.facts(item.place, {
                at: day ? { date: day.date, time: day.time } : null,
            });
        }
        if (!item.sourceId)
            return `<h3>A spot for your next ${item.label === 'Park' ? 'breather' : item.label === 'Playground' ? 'family outing' : 'outing'}.</h3><p>${E(item.label)} in ${E(item.area)}. Save it for later, or find a little room for it in your day.</p>${(item.facts || []).length ? `<ul>${item.facts.map((x) => `<li>${E(x)}</li>`).join('')}</ul>` : ''}<aside class="place-about-note"><div><h4>Before you go</h4><span>To check</span></div><p>${E(item.unknown || 'Opening hours and access still need checking.')}</p><small>Listing captured ${E(item.captured)}. Details may have changed.</small></aside>`;
        const website = safeURL(item.website),
            source = safeURL(item.sourceUrl);
        return `<p class="place-source-summary">${E(item.summary || `${item.label} in ${item.location || 'the city'}.`)}</p>${(item.facts || []).length ? `<ul class="place-recorded-facts">${item.facts.map((x) => `<li>${E(x)}</li>`).join('')}</ul>` : ''}<dl class="place-source-facts"><div><dt>Address</dt><dd>${E(item.address || 'Street address not recorded.')}</dd></div><div><dt>Opening hours <span>Recorded</span></dt><dd>${E(item.rawHours || 'Opening hours aren’t recorded.')}</dd></div></dl>${website ? `<a class="place-website inline-link" href="${E(website)}" target="_blank" rel="noopener">Visit website ↗</a>` : ''}<aside class="place-about-note"><div><h4>Before you go</h4><span>To check</span></div><p>${item.rawHours ? 'These are recorded hours, not a live opening check.' : 'Check opening hours before visiting.'} Access and availability may have changed.</p><small>Listing captured ${E(item.captured)}.</small></aside><details class="place-source-disclosure"><summary>About this listing</summary><p>Source snapshot for this design preview. Place identity still needs review before publication.</p>${source ? `<a href="${E(source)}" target="_blank" rel="noopener">View the OpenStreetMap listing ↗</a>` : ''}</details>`;
    }
    function open(item, initial = 'about') {
        const brief = S.read().brief;
        U.open(
            item.title,
            `<section class="place-preview place-sheet">
            <div class="place-sheet-hero ${item.image || item.place ? '' : 'no-media'}${item.place && !item.image ? ' is-map' : ''}">${item.image ? `<img src="${E(item.image)}" alt="${E(item.title)}">` : item.place ? `<img src="../connected/place-maps/${E(item.id)}.png" alt="Map around ${E(item.title)}">` : ''}<div><span>${E(item.label)}</span><strong>${E(item.area || item.postcode || item.location || '')}</strong><a href="${E(mapURL(item))}" target="_blank" rel="noopener">View on map ↗</a></div></div>
            <nav class="place-sheet-tabs" aria-label="Place actions"><button type="button" data-section="about" aria-pressed="true">About</button><button type="button" data-section="plan" aria-pressed="false">Plan a visit</button><button type="button" data-section="directions" aria-pressed="false">Get there</button></nav>
            <div class="place-sheet-panel" data-panel="about">${about(item)}${photoCredit(item)}<div class="place-sheet-footer"><button class="button secondary" data-save-item="${E(item.id)}" aria-pressed="false"><span data-save-label>Save for later</span></button><button class="button primary" data-section="plan">Plan a visit</button></div></div>
            <div class="place-sheet-panel" data-panel="plan" hidden><div class="place-section-heading"><div><h3>A little time here.</h3><p>Choose when it fits your day.</p></div></div><div data-place-planning></div></div>
            <div class="place-sheet-panel" data-panel="directions" hidden><form class="place-journey"><div class="place-route"><label class="place-route-origin"><span class="route-dot" aria-hidden="true"></span><span class="route-field">From<input name="from" placeholder="Address, place or stop" maxlength="120" required value="${E(brief.from || '')}" autocomplete="off"></span></label><div class="place-journey-destination">${I('pin')}<span><small>To</small><strong>${E(item.title)}</strong></span></div></div><fieldset><legend>How would you like to go?</legend>${[
                ['transit', 'Transit'],
                ['walk', 'Walk'],
                ['bike', 'Cycle'],
            ]
                .map(
                    ([value, title]) =>
                        `<label><input type="radio" name="mode" value="${value}" ${(brief.mode || 'transit') === value ? 'checked' : ''}><span>${title}</span></label>`,
                )
                .join(
                    '',
                )}</fieldset><div data-mini-departures></div><div class="place-sheet-footer"><button type="submit" class="button secondary">Save preferences</button><button type="button" class="button primary" data-full-journey>Open planner</button></div><p class="journey-feedback" role="status"></p></form></div>
        </section>`,
        );
        const root = document.querySelector('.place-sheet');
        root.querySelector('.place-sheet-hero > img')?.addEventListener(
            'error',
            (event) => {
                event.target.remove();
                root.querySelector('.place-sheet-hero').classList.add(
                    'no-media',
                );
                root.querySelector('.place-photo-credit')?.remove();
            },
            { once: true },
        );
        const planHeading = root.querySelector('.place-section-heading');
        function framePanel(section) {
            const panel = root.querySelector(`[data-panel="${section}"]`);
            const footer = panel.querySelector('.place-sheet-footer, .actions');
            if (!footer) return;
            const parent = footer.parentElement;
            if (parent.querySelector(':scope > .place-sheet-scroll')) return;
            const content = document.createElement('div');
            content.className = 'place-sheet-scroll';
            if (section === 'plan' && parent.tagName === 'FORM')
                content.append(planHeading);
            for (const child of [...parent.childNodes]) {
                if (child !== footer) content.append(child);
            }
            parent.insertBefore(content, footer);
        }
        let planningReady = false;
        function show(section) {
            const previous = root.dataset.section;
            if (previous === section && (section !== 'plan' || planningReady))
                return;
            window.ExpaduTime?.close();
            root.dataset.section = section;
            root.querySelectorAll('.place-sheet-scroll').forEach(
                (panel) => (panel.scrollTop = 0),
            );
            root.querySelectorAll('[data-panel]').forEach(
                (panel) => (panel.hidden = panel.dataset.panel !== section),
            );
            root.querySelectorAll('.place-sheet-tabs button').forEach(
                (button) =>
                    button.setAttribute(
                        'aria-pressed',
                        String(button.dataset.section === section),
                    ),
            );
            if (section === 'plan' && !planningReady) {
                planningReady = true;
                const container = root.querySelector('[data-place-planning]');
                ExpaduPlanning.mount(
                    item,
                    container,
                    (selected, undoAddition) => {
                        planningReady = false;
                        container.innerHTML = `<div class="place-added" role="status" tabindex="-1"><h3>Room made for ${E(item.title)}.</h3><p>${E(new Date(selected.date + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }))} · ${E(selected.start)}–${E(selected.end)}</p><p>Added to your proposal. It’s waiting for confirmation in Composer.</p></div><div class="place-sheet-footer"><button class="button secondary" data-undo-visit>Undo addition</button><button class="button primary" data-section="about">Back to place</button></div>`;
                        framePanel('plan');
                        container
                            .querySelector('.place-added')
                            .focus({ preventScroll: true });
                        window.ExpaduMotion?.reveal(container);
                        container.querySelector('[data-undo-visit]').onclick =
                            () => {
                                try {
                                    undoAddition();
                                    show('plan');
                                    root.querySelector(
                                        '.shared-date-toggle',
                                    ).focus({ preventScroll: true });
                                } catch (error) {
                                    let note =
                                        container.querySelector(
                                            '[data-undo-error]',
                                        );
                                    if (!note) {
                                        note = document.createElement('p');
                                        note.dataset.undoError = '';
                                        note.className = 'selection-error';
                                        note.setAttribute('role', 'alert');
                                        note.tabIndex = -1;
                                        container
                                            .querySelector('.place-added')
                                            .append(note);
                                    }
                                    note.textContent = error.message;
                                    note.focus();
                                }
                            };
                    },
                );
                const form = container.querySelector('#selection-form');
                const setup = document.createElement('div');
                setup.className = 'visit-setup';
                const day = form.querySelector('.selection-day');
                day.before(setup);
                setup.append(
                    day,
                    form.querySelector('[data-visit-edit-context]'),
                    form.querySelector('.visit-suggestions'),
                    form.querySelector('.selection-times'),
                    form.querySelector('[data-visit-edit-feedback]'),
                    form.querySelector('.selection-error'),
                );
            }
            framePanel(section);
            if (previous) {
                const order = ['about', 'plan', 'directions'];
                window.ExpaduMotion?.reveal(
                    root.querySelector(`[data-panel="${section}"]`),
                    Math.sign(order.indexOf(section) - order.indexOf(previous)),
                );
            }
        }
        root.addEventListener('click', (event) => {
            const b = event.target.closest('button[data-section]');
            if (b) {
                show(b.dataset.section);
                root.querySelector(
                    `.place-sheet-tabs [data-section="${b.dataset.section}"]`,
                ).focus({ preventScroll: true });
            }
        });
        const form = root.querySelector('.place-journey');
        function renderJourney() {
            const target = form.querySelector('[data-mini-departures]');
            target.innerHTML = window.ExpaduJourneyPreview.render(
                window.JourneyModel,
                form.elements.mode.value || 'transit',
                item.title,
                E,
                { compact: true },
            );
            window.ExpaduMotion?.reveal(target);
        }
        renderJourney();

        function keep() {
            form.elements.from.value = form.elements.from.value.trim();
            if (!form.reportValidity()) return false;
            S.update((s) => {
                s.brief.from = form.elements.from.value.trim();
                s.brief.mode = form.elements.mode.value || 'transit';
            });
            return true;
        }
        form.onsubmit = (event) => {
            event.preventDefault();
            if (keep()) {
                form.querySelector('.journey-feedback').textContent =
                    'Starting point and travel mode saved for your journeys.';
                window.ExpaduMotion?.feedback(
                    form.querySelector('[type="submit"]'),
                );
                window.ExpaduMotion?.reveal(
                    form.querySelector('.journey-feedback'),
                );
            }
        };
        form.querySelector('[data-full-journey]').onclick = () => {
            if (keep())
                S.go('departures', {
                    to: item.title,
                    context: item.id,
                    lat: item.lat ?? '',
                    lng: item.lng ?? '',
                    address: item.address || '',
                    source: item.sourceId || '',
                });
        };
        form.addEventListener('change', (event) => {
            form.querySelector('.journey-feedback').textContent = '';
            if (event.target.name === 'mode') renderJourney();
        });
        show(initial);
        ExpaduPlanning.refresh();
    }
    window.ExpaduPlaceSheet = { open };
})();
