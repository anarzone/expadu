(function () {
    const M = ExpaduModel,
        storageKey = 'expadu-connected-design-v1';
    let memory = M.fresh(),
        persistent = true;
    function read() {
        if (!persistent) return M.clone(memory);
        try {
            const x = JSON.parse(localStorage.getItem(storageKey));
            if (x?.version === 1 && Array.isArray(x.draft) && x.brief)
                return withExistingBookmarks(x);
        } catch {}
        return withExistingBookmarks(M.clone(memory));
    }
    function withExistingBookmarks(s) {
        const workspace = studio();
        if (workspace && !workspace.bookmarksConnected) {
            const known = [...M.places, ...M.events];
            const saved = (workspace.saved || [])
                .map((id) =>
                    known.find(
                        (item) =>
                            (item.type === 'event'
                                ? 'event:' + item.id
                                : item.id) === id,
                    ),
                )
                .filter(Boolean);
            s.savedIdeas = [...(s.savedIdeas || [])];
            for (const item of saved)
                if (!s.savedIdeas.some((x) => M.key(x) === M.key(item)))
                    s.savedIdeas.push(item);
        }
        return s;
    }
    function studio() {
        try {
            return JSON.parse(
                localStorage.getItem('expadu-composer-studio-v1'),
            );
        } catch {
            return null;
        }
    }
    function write(s) {
        let workspaceSaved = true;
        // Sync bookmarks alone; never replace the conversation or confirmed plans.
        const workspace = studio();
        if (workspace && Array.isArray(s.savedIdeas)) {
            workspace.saved = s.savedIdeas.map((item) =>
                item.type === 'event' ? `event:${item.id}` : item.id,
            );
            try {
                localStorage.setItem(
                    'expadu-composer-studio-v1',
                    JSON.stringify(workspace),
                );
            } catch {
                workspaceSaved = false;
            }
        }
        memory = M.clone(s);
        persistent = workspaceSaved;
        try {
            localStorage.setItem(storageKey, JSON.stringify(s));
        } catch {
            persistent = false;
        }
        window.dispatchEvent(
            new CustomEvent('expadu:change', { detail: { persistent } }),
        );
        return persistent;
    }
    function update(fn) {
        const s = read(),
            result = fn(s);
        write(s);
        return result;
    }
    // A day can contain both Studio activities and department selections.
    // Persist both together, restoring the previous values if either write fails.
    function updateDay(fn, { createWorkspace } = {}) {
        if (!persistent)
            throw Error(
                'Changes could not be saved. Reload before editing your day.',
            );
        const workspaceKey = 'expadu-composer-studio-v1';
        const previousShared = localStorage.getItem(storageKey);
        const previousWorkspace = localStorage.getItem(workspaceKey);
        const shared = read(),
            workspace = studio() || createWorkspace?.() || null;
        const result = fn(shared, workspace);
        try {
            if (workspace)
                localStorage.setItem(workspaceKey, JSON.stringify(workspace));
            localStorage.setItem(storageKey, JSON.stringify(shared));
        } catch {
            try {
                for (const [key, value] of [
                    [workspaceKey, previousWorkspace],
                    [storageKey, previousShared],
                ]) {
                    if (value === null) localStorage.removeItem(key);
                    else localStorage.setItem(key, value);
                }
            } catch {
                persistent = false;
            }
            throw Error(
                'Could not save this change. Your day has not been updated here.',
            );
        }
        memory = M.clone(shared);
        window.dispatchEvent(
            new CustomEvent('expadu:change', { detail: { persistent } }),
        );
        return result;
    }
    const routes = {
        today: '../today-next/brief/',
        todayPreview: '../today-next/',
        places: '../places-next/',
        placesGuide: '../places-guide/',
        events: '../events-next/',
        bureaucracy: '../bureaucracy-next/',
        services: '../services-next/',
        departures: '../departures-next/',
        composer: '../composer-studio/',
    };
    const sessionKey = 'expadu-composer-navigation-v1';
    function composerSession() {
        try {
            return JSON.parse(sessionStorage.getItem(sessionKey)) || {};
        } catch {
            return {};
        }
    }
    function saveComposerSession(session) {
        try {
            sessionStorage.setItem(sessionKey, JSON.stringify(session));
        } catch {
            /* Navigation still works when session storage is unavailable. */
        }
    }
    function localTarget(href, fallback) {
        const base = new URL('../', document.baseURI || location.href);
        try {
            const url = new URL(href);
            const allowed = Object.values(routes).some((route) => {
                const path = new URL(route, document.baseURI || location.href)
                    .pathname;
                return (
                    url.pathname === path ||
                    url.pathname === path + 'index.html'
                );
            });
            if (
                url.origin === base.origin &&
                url.protocol === base.protocol &&
                allowed
            )
                return url.href;
        } catch {}
        const url = new URL(fallback, document.baseURI || location.href);
        if (url.protocol === 'file:' && url.pathname.endsWith('/'))
            url.pathname += 'index.html';
        return url.href;
    }
    // Where leaving Composer goes, and what that page is called: the page
    // it was opened from, otherwise Today.
    const pageNames = {
        today: 'Today',
        todayPreview: 'Today',
        events: 'Events',
        bureaucracy: 'Paperwork',
        services: 'Services',
        departures: 'Travel',
        places: 'Places',
        placesGuide: 'Places',
    };
    function composerOrigin() {
        const url = localTarget(composerSession().origin?.url, routes.today);
        const path = new URL(url).pathname.replace(/index\.html$/, '');
        const key = Object.keys(pageNames).find(
            (k) =>
                new URL(routes[k], document.baseURI || location.href)
                    .pathname === path,
        );
        return { url, name: pageNames[key] || 'Today' };
    }
    function minimizeComposer() {
        const session = composerSession();
        const url = localTarget(session.origin?.url, routes.today);
        session.workspace = {
            url: location.href.split('#')[0],
            y: window.scrollY,
        };
        session.origin = {
            url,
            y: session.origin?.url === url ? session.origin.y : 0,
        };
        session.minimized = true;
        session.restore = 'origin';
        saveComposerSession(session);
        location.href = url;
    }
    function restoreComposerPosition() {
        // An async catalogue may not have enough height to restore the origin yet.
        if (document.querySelector?.('main[aria-busy="true"]')) return;
        const session = composerSession(),
            target = session[session.restore];
        if (!target || target.url !== location.href) return;
        window.scrollTo({
            top: Math.max(0, Number(target.y) || 0),
            behavior: 'instant',
        });
        session.restore = null;
        saveComposerSession(session);
    }
    function isComposerMinimized() {
        return composerSession().minimized === true;
    }
    function rememberWorkspace() {
        const session = composerSession();
        session.workspace = {
            url: location.href.split('#')[0],
            y: window.scrollY,
        };
        session.returning = true;
        saveComposerSession(session);
    }
    function canReturnToComposer() {
        return composerSession().returning === true;
    }
    function go(page, params = {}) {
        if (!persistent) {
            window.dispatchEvent(
                new CustomEvent('expadu:change', {
                    detail: { persistent: false },
                }),
            );
            return false;
        }
        const url = new URL(routes[page], document.baseURI || location.href);
        if (page === 'composer') {
            const session = composerSession();
            if (!new URL(location.href).pathname.includes('/composer-studio/'))
                session.origin = { url: location.href, y: window.scrollY };
            if (
                params.resume === '1' &&
                (session.minimized || session.returning) &&
                session.workspace
            ) {
                session.minimized = false;
                session.returning = false;
                session.restore = 'workspace';
                saveComposerSession(session);
                location.href = localTarget(
                    session.workspace.url,
                    routes.composer,
                );
                return;
            }
            session.minimized = false;
            session.returning = false;
            session.restore = null;
            saveComposerSession(session);
            const s = read();
            s.composerHandoff = {
                id: `${Date.now()}-${Math.random()}`,
                submit: params.compose === '1',
                date: params.date || null,
                action: ['new', 'appointment', 'examples'].includes(
                    params.action,
                )
                    ? params.action
                    : null,
            };
            write(s);
            if (!persistent) return false;
        }
        Object.entries(params).forEach(([k, v]) => {
            if (k === 'panel') {
                if (v) url.hash = v;
                return;
            }
            if (v) url.searchParams.set(k, v);
        });
        location.href = url.href;
    }
    document.addEventListener(
        'click',
        (event) => {
            const target = event.target.closest('a[href]');
            if (
                persistent &&
                target &&
                !event.ctrlKey &&
                !event.metaKey &&
                new URL(location.href).pathname.includes('/composer-studio/') &&
                !new URL(target.href, location.href).pathname.includes(
                    '/composer-studio/',
                ) &&
                localTarget(target.href, routes.today) === target.href
            ) {
                rememberWorkspace();
            }
            if (
                persistent &&
                target &&
                !event.ctrlKey &&
                !event.metaKey &&
                new URL(target.href, location.href).pathname.includes(
                    '/composer-studio/',
                ) &&
                !location.pathname.includes('/composer-studio/')
            ) {
                event.preventDefault();
                go('composer', {
                    panel: new URL(target.href, location.href).hash.slice(1),
                });
                return;
            }
            if (persistent) return;
            const link = event.target.closest('a[href]');
            if (
                link &&
                new URL(link.href, location.href).origin ===
                    new URL(location.href).origin
            ) {
                event.preventDefault();
                window.dispatchEvent(
                    new CustomEvent('expadu:change', {
                        detail: { persistent: false },
                    }),
                );
            }
        },
        true,
    );
    window.ExpaduStore = {
        read,
        studio,
        write,
        update,
        updateDay,
        routes,
        go,
        minimizeComposer,
        composerOrigin,
        restoreComposerPosition,
        isComposerMinimized,
        rememberWorkspace,
        canReturnToComposer,
        isPersistent: () => persistent,
    };
    window.addEventListener('pageshow', () => {
        requestAnimationFrame(() =>
            requestAnimationFrame(restoreComposerPosition),
        );
    });
})();
