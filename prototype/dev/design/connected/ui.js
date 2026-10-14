(function () {
    const S = ExpaduStore,
        I = ExpaduIcon;
    const esc = (s) =>
        String(s ?? '').replace(
            /[&<>"']/g,
            (c) =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                })[c],
        );
    const logo = `<svg viewBox="13 22 74 60" aria-hidden="true"> <g transform="translate(2.45 2.05)"> <circle cx="20.5" cy="50" r="6" fill="#05badd" /> <rect x="30" y="24" width="13" height="52" rx="6" fill="currentColor" /> <rect x="46" y="24" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-7 46 30)" /> <rect x="46" y="43.5" width="25" height="13" rx="6" fill="#ff3902" transform="rotate(-4 46 50)" /> <rect x="46" y="63" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-2 46 69)" /> </g></svg>`;
    const page = document.body.dataset.page;
    const nav = [
        ['today', 'home', 'Today'],
        ['events', 'calendar', 'Events'],
        ['bureaucracy', 'document', 'Bureaucracy'],
        ['departures', 'train', 'Departures'],
    ];
    const top = document.querySelector('#shell');
    if (top && !top.querySelector('[data-static-header]'))
        top.innerHTML = `<header class="app-header"><a href="${S.routes.today}" class="brand" aria-label="Expadu home">${logo}Expadu</a><div class="header-context"><button class="city" data-ui="city">${I('pin')}Cologne${I('down')}</button><span class="header-weather" role="img" aria-label="Sample weather: 21 degrees, partly sunny" title="Sample weather · not live">${I('sun')}<strong>21°</strong></span></div><a href="${S.routes.composer}#saved" class="my-day" aria-label="Saved ideas">${I('bookmark')}<span>Saved</span><b data-count hidden>0</b></a></header><nav class="service-nav" aria-label="Main navigation">${nav.map(([id, icon, title]) => `<a data-page="${id}" href="${S.routes[id]}" ${page === id ? 'aria-current="page"' : ''}>${I(icon)}<span>${title}</span></a>`).join('')}</nav>`;
    const dock = document.querySelector('#dock');
    if (dock)
        dock.innerHTML = `<div class="dock-context" hidden><a href="${S.routes.composer}"><span data-draft-count></span> in your draft ${I('arrow')}</a></div><form id="shared-compose" class="composer-dock"><label for="dock-request">${I('spark')}<span>Composer</span></label><div class="dock-field"><input id="dock-request" placeholder="What do you need today?" maxlength="700" autocomplete="off" aria-label="Your request to Composer"><button type="submit">Compose ${I('arrow')}</button></div></form>`;
    document.body.insertAdjacentHTML(
        'beforeend',
        `<dialog id="shared-dialog" class="detail-dialog" aria-labelledby="dialog-title"><div class="dialog-head"><h2 id="dialog-title"></h2><button data-ui="close" class="icon-button" aria-label="Close details">${I('close')}</button></div><div id="dialog-body"></div></dialog><div class="toast" id="shared-toast" role="status"><span></span><button hidden>Undo</button></div>`,
    );
    const dialog = document.querySelector('#shared-dialog'),
        body = document.querySelector('#dialog-body');
    let toastTimer, undoAction, lastFocus, focusSelector;
    // Today's sheets, and the Events page's full-screen event sheet, slide
    // from the bottom on phones, without a fade over text.
    const phoneSheet = () =>
        !!body.querySelector('.today-sheet, .phone-sheet') &&
        matchMedia('(max-width: 700px)').matches;
    function open(title, html) {
        const entering =
            !dialog.open || dialog.classList.contains('is-leaving');
        window.ExpaduMotion?.cancel(dialog);
        dialog.classList.remove('is-leaving', 'is-entering');
        if (!dialog.open) {
            lastFocus = document.activeElement;
            focusSelector = null;
            for (const name of [
                'data-edit',
                'data-detail',
                'data-ui',
                'data-case',
                'data-document',
                'data-record',
            ]) {
                const value = lastFocus?.getAttribute(name);
                if (value) {
                    focusSelector = `[${name}="${CSS.escape(value)}"]`;
                    break;
                }
            }
        }
        document.querySelector('#dialog-title').textContent = title;
        body.innerHTML = html;
        if (!dialog.open) dialog.showModal();
        if (entering && window.ExpaduMotion) {
            dialog.classList.add('is-entering');
            ExpaduMotion.run(
                dialog,
                phoneSheet()
                    ? [
                          { transform: 'translateY(100%)' },
                          { transform: 'translateY(0)' },
                      ]
                    : [
                          {
                              opacity: 0,
                              transform: 'translateY(28px) scale(.99)',
                          },
                          { opacity: 1, transform: 'translateY(0) scale(1)' },
                      ],
                { duration: phoneSheet() ? 280 : 300 },
                () => dialog.classList.remove('is-entering'),
            );
        }
    }
    function close() {
        if (!dialog.open || dialog.classList.contains('is-leaving')) return;
        if (!window.ExpaduMotion) {
            if (dialog.style) dialog.style.transform = '';
            dialog.close();
            return;
        }
        dialog.classList.remove('is-entering');
        dialog.classList.add('is-leaving');
        // A sheet that was dragged down closes from where it is.
        const dragged = dialog.style?.transform;
        if (dialog.style) dialog.style.transform = '';
        ExpaduMotion.run(
            dialog,
            phoneSheet()
                ? [
                      { transform: dragged || 'translateY(0)' },
                      { transform: 'translateY(100%)' },
                  ]
                : [
                      { opacity: 1, transform: 'translateY(0) scale(1)' },
                      { opacity: 0, transform: 'translateY(20px) scale(.99)' },
                  ],
            {
                duration: phoneSheet() ? 220 : 180,
                easing: 'cubic-bezier(.4,0,1,1)',
            },
            () => {
                dialog.classList.remove('is-leaving');
                dialog.close();
            },
        );
    }
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        close();
    });
    dialog.addEventListener('close', () => {
        const target = lastFocus?.isConnected
            ? lastFocus
            : focusSelector
              ? document.querySelector(focusSelector)
              : null;
        if (target) target.focus();
        else {
            const title = document.querySelector('main h1');
            if (title) {
                title.tabIndex = -1;
                title.focus();
            }
        }
    });
    dialog.addEventListener('click', (e) => {
        if (e.target === dialog) {
            const r = dialog.getBoundingClientRect();
            if (
                e.clientX < r.left ||
                e.clientX > r.right ||
                e.clientY < r.top ||
                e.clientY > r.bottom
            )
                close();
        }
    });
    function toast(message, undo) {
        const t = document.querySelector('#shared-toast');
        clearTimeout(toastTimer);
        t.querySelector('span').textContent = message;
        t.querySelector('button').hidden = !undo;
        undoAction = undo;
        t.classList.add('show');
        toastTimer = setTimeout(() => t.classList.remove('show'), 5000);
    }
    document.querySelector('#shared-toast button').onclick = () => {
        undoAction?.();
        document.querySelector('#shared-toast').classList.remove('show');
    };
    function sync() {
        const s = S.read();
        document.querySelectorAll('[data-count]').forEach((e) => {
            e.textContent = (s.savedIdeas || []).length;
            e.hidden = !(s.savedIdeas || []).length;
        });
        document
            .querySelectorAll('[data-draft-count]')
            .forEach((e) => (e.textContent = s.draft.length));
        const c = document.querySelector('.dock-context');
        if (c) c.hidden = !s.draft.length;
        const input = document.querySelector('#dock-request');
        if (input && document.activeElement !== input) input.value = s.prompt;
    }
    window.addEventListener('expadu:change', (e) => {
        sync();
        if (e.detail?.persistent === false)
            toast('Storage unavailable. Keep this page open.');
    });
    window.addEventListener('storage', sync);
    window.addEventListener('pageshow', sync);
    sync();
    document
        .querySelector('#shared-compose')
        ?.addEventListener('submit', (e) => {
            e.preventDefault();
            const input = document.querySelector('#dock-request');
            if (!input.value.trim()) {
                input.focus();
                return;
            }
            S.update((s) => (s.prompt = input.value.trim()));
            S.go('composer', { compose: '1' });
        });
    document
        .querySelector('#dock-request')
        ?.addEventListener('input', (e) =>
            S.update((s) => (s.prompt = e.target.value)),
        );
    document.addEventListener('click', (e) => {
        const a = e.target.closest('[data-ui]');
        if (!a) return;
        if (a.dataset.ui === 'close') close();
        if (a.dataset.ui === 'reset')
            open(
                'Reset this preview?',
                '<p>This clears the connected draft, saved day and preview reminders on this device.</p><div class="actions"><button class="button secondary" data-ui="close">Keep my choices</button><button class="button primary" data-ui="confirm-reset">Reset preview</button></div>',
            );
        if (a.dataset.ui === 'confirm-reset') {
            S.write(ExpaduModel.fresh());
            for (const key of [
                'expadu-places-filters',
                'expadu-event-filters',
                'expadu-service-briefs',
            ])
                sessionStorage.removeItem(key);
            location.href = S.routes.places;
        }

        if (a.dataset.ui === 'city')
            open(
                'Your city',
                `<div class="city-choice">${I('pin')}<strong>Cologne</strong><span>Preview city</span>${I('check')}</div><p class="muted">More cities will use the same experience.</p>`,
            );
        if (a.dataset.ui === 'bureaucracy') S.go('bureaucracy');
        if (a.dataset.ui === 'about')
            open(
                'About this preview',
                `<p>These pages share a local draft. Composer responses are scripted; nothing is booked, submitted or sent.</p><dl class="facts"><div><dt>Places</dt><dd>Captured 26 June and 8 September 2026</dd></div><div><dt>Events</dt><dd>Selected occurrences from staging, 8 September 2026</dd></div><div><dt>Journeys</dt><dd>Captured 7 September 2026</dd></div><div><dt>Bureaucracy</dt><dd>Synthetic case; historical catalogue copy; v2 actions planned</dd></div><div><dt>Services</dt><dd>Request design; provider directory pending</dd></div></dl><p>Event artwork is decorative. Place photographs retain their open-licence attribution. Current availability and legal guidance are not supplied by this preview.</p><button class="button secondary" data-ui="reset">Reset this preview</button>`,
            );
    });
    window.ExpaduUI = { esc, open, close, toast, sync, icon: I };
    function revealCurrentPage() {
        const nav = document.querySelector('.service-nav, .services');
        const active = nav?.querySelector('[aria-current="page"]');
        if (!active || nav.scrollWidth <= nav.clientWidth) return;
        const item = active.getBoundingClientRect();
        const bounds = nav.getBoundingClientRect();
        if (item.right > bounds.right)
            nav.scrollLeft += item.right - bounds.right;
        else if (item.left < bounds.left)
            nav.scrollLeft -= bounds.left - item.left;
    }
    requestAnimationFrame(revealCurrentPage);
    // Font loading can widen the row after its first layout on a narrow phone.
    document.fonts?.ready.then(revealCurrentPage);
    window.addEventListener('load', revealCurrentPage, { once: true });
})();
