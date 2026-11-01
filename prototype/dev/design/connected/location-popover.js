(function () {
    const cities = [
        {
            id: 'cologne',
            name: 'Cologne',
            country: 'Germany',
            aliases: ['Köln', 'Koeln'],
        },
        {
            id: 'dusseldorf',
            name: 'Düsseldorf',
            country: 'Germany',
            aliases: ['Duesseldorf'],
        },
        { id: 'bonn', name: 'Bonn', country: 'Germany', aliases: [] },
        { id: 'berlin', name: 'Berlin', country: 'Germany', aliases: [] },
        { id: 'hamburg', name: 'Hamburg', country: 'Germany', aliases: [] },
    ];
    const normalize = (value) =>
        value
            .toLocaleLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    function matches(city, query) {
        return [city.name, city.country, ...city.aliases].some((value) =>
            normalize(value).includes(normalize(query)),
        );
    }
    function suggestionsFor(query) {
        return normalize(query)
            ? cities.filter((city) => matches(city, query))
            : [];
    }
    function visibleCities(query, selectedId) {
        const selected = cities.find((city) => city.id === selectedId);
        return [
            selected,
            ...suggestionsFor(query).filter((city) => city.id !== selectedId),
        ].filter(Boolean);
    }
    if (typeof module !== 'undefined')
        module.exports = { cities, matches, suggestionsFor, visibleCities };
    if (typeof document === 'undefined') return;
    const trigger = document.querySelector(
        '.app-header .city, .app-header .location-button',
    );
    if (!trigger) return;
    const svg = (path) =>
        `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;
    const pin = svg(
        '<path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
    );
    const search = svg(
        '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
    );
    const check = svg('<path d="m5 12 4 4L19 6"/>');
    const panel = document.createElement('div');
    panel.id = 'location-popover';
    panel.className = 'location-popover';
    panel.hidden = true;
    panel.tabIndex = -1;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Choose your city');
    panel.innerHTML = `<label class="location-search">${search}<input type="search" placeholder="Search cities" aria-label="Search cities" autocomplete="off" spellcheck="false"></label><div class="location-results"></div><p class="location-empty" hidden>No matching cities</p><p class="location-note">Demo cities · content stays Cologne</p>`;
    document.body.append(panel);
    const input = panel.querySelector('input');
    const results = panel.querySelector('.location-results');
    const empty = panel.querySelector('.location-empty');
    let selectedId = 'cologne';
    const cityLabel =
        trigger.querySelector('[data-city-label]') ||
        [...trigger.childNodes].find(
            (node) =>
                node.nodeType === 3 && node.textContent.includes('Cologne'),
        );
    function renderResults() {
        const suggestions = suggestionsFor(input.value);
        results.innerHTML = visibleCities(input.value, selectedId)
            .map(
                (city) =>
                    `<button type="button" class="location-option" data-city="${city.id}" aria-label="${city.name}, ${city.country}${city.id === selectedId ? ', selected' : ''}" aria-pressed="${city.id === selectedId}"><span class="location-mark">${pin}</span><span class="location-copy"><strong>${city.name}</strong><small>${city.country}</small></span>${city.id === selectedId ? `<span class="location-selected">${check}</span>` : ''}</button>`,
            )
            .join('');
        results.hidden = false;
        empty.hidden = !normalize(input.value) || suggestions.length > 0;
    }
    trigger.removeAttribute('data-action');
    trigger.removeAttribute('data-ui');
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-controls', panel.id);
    trigger.setAttribute('aria-expanded', 'false');

    function position() {
        const rect = trigger.getBoundingClientRect();
        const viewport = window.visualViewport;
        const leftEdge = (viewport?.offsetLeft || 0) + 12;
        const topEdge = (viewport?.offsetTop || 0) + 12;
        const rightEdge =
            leftEdge + (viewport?.width || window.innerWidth) - 24;
        const bottomEdge =
            topEdge + (viewport?.height || window.innerHeight) - 24;
        panel.style.width = `${Math.min(232, rightEdge - leftEdge)}px`;
        panel.style.maxHeight = `${Math.max(100, bottomEdge - Math.max(topEdge, rect.bottom + 8))}px`;
        panel.style.left = `${Math.max(leftEdge, Math.min(rect.right - panel.offsetWidth, rightEdge - panel.offsetWidth))}px`;
        panel.style.top = `${Math.max(topEdge, rect.bottom + 8)}px`;
    }
    function close(restoreFocus = false) {
        if (panel.hidden) return;
        panel.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        if (restoreFocus) trigger.focus({ preventScroll: true });
    }
    function open() {
        if (!panel.hidden) {
            close(true);
            return;
        }
        input.value = '';
        renderResults();
        panel.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        position();
        // Avoid raising the mobile keyboard before the user chooses to search.
        panel.focus({ preventScroll: true });
    }
    trigger.addEventListener('click', open);
    results.addEventListener('click', (event) => {
        const option = event.target.closest('[data-city]');
        if (!option) return;
        const city = cities.find((city) => city.id === option.dataset.city);
        selectedId = city.id;
        if (cityLabel) cityLabel.textContent = city.name;
        trigger.setAttribute('aria-label', city.name);
        trigger.title = 'Demo selection · page content stays Cologne';
        close(true);
    });
    input.addEventListener('input', renderResults);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' && results.firstElementChild) {
            event.preventDefault();
            results.firstElementChild.focus();
        }
    });
    results.addEventListener('keydown', (event) => {
        const option = event.target.closest('[data-city]');
        if (!option || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
        event.preventDefault();
        const target =
            event.key === 'ArrowUp'
                ? option.previousElementSibling || input
                : option.nextElementSibling || option;
        target.focus();
    });
    document.addEventListener('keydown', (event) => {
        if (!panel.hidden && event.key === 'Escape') {
            event.preventDefault();
            close(true);
        }
    });
    document.addEventListener('click', (event) => {
        if (!panel.contains(event.target) && !trigger.contains(event.target))
            close();
    });
    document.addEventListener('focusin', (event) => {
        if (!panel.contains(event.target) && !trigger.contains(event.target))
            close();
    });
    // Keep the fixed dropdown open during page or results scrolling.
    // Outside clicks, Escape and selection still dismiss it.
    window.addEventListener(
        'resize',
        () => {
            if (!panel.hidden) position();
        },
        { passive: true },
    );
    window.visualViewport?.addEventListener('resize', () => {
        if (!panel.hidden) position();
    });
})();
