/* Opt-in header alignment with the approved Today brief. Keep archived shells intact. */
(function () {
    if (!document.body.classList.contains('today-header')) return;
    const header = document.querySelector('.app-header');
    const menu = document.querySelector('nav[aria-label="Main navigation"]');
    if (!header || !menu) return;
    const I = window.TodayIcon;
    const S = window.ExpaduStore;
    const staticHeader = header.hasAttribute('data-static-header');
    if (!staticHeader) {
        const sections = {
            today: ['home', 'Today'],
            events: ['calendar', 'Events'],
            bureaucracy: ['document', 'Paperwork'],
            departures: ['train', 'Travel'],
        };

        menu.classList.add('service-nav');
        menu.querySelectorAll('a[data-page]').forEach((link) => {
            const section = sections[link.dataset.page];
            if (!section) return;
            link.innerHTML = `${I(section[0])}<span>${section[1]}</span>`;
            if (S?.routes[link.dataset.page])
                link.href = S.routes[link.dataset.page];
        });
        const brand = header.querySelector('.brand');
        if (brand && S?.routes.today) brand.href = S.routes.today;

        const city = header.querySelector('.city');
        const cityName = city.textContent.trim();
        city.innerHTML = `${I('pin')}<span data-city-label></span>${I('down')}`;
        city.querySelector('[data-city-label]').textContent = cityName;
        city.setAttribute('aria-label', cityName);
    }

    // Keep the Saved link itself: Composer intercepts it to open its existing drawer.
    const saved = header.querySelector('.my-day');
    const count = saved.querySelector('b') || document.createElement('b');
    count.dataset.count = '';
    if (!staticHeader) {
        saved.innerHTML = `${I('bookmark')}<span>Saved</span>`;
        saved.append(count);
    }
    saved.setAttribute('aria-label', 'Saved ideas');
    saved.title = 'Saved ideas';

    const account =
        header.querySelector('.account-button') ||
        header.querySelector('.avatar') ||
        document.createElement('button');
    account.className = 'account-button';
    account.type = 'button';
    account.removeAttribute('data-action');
    account.setAttribute('aria-label', 'Your account');
    account.setAttribute('popovertarget', 'header-account');
    if (!staticHeader) account.innerHTML = '<span>A</span>';
    const profile = document.createElement('div');
    profile.id = 'header-account';
    profile.className = 'header-account-popover';
    profile.setAttribute('popover', 'auto');
    profile.setAttribute('role', 'dialog');
    profile.setAttribute('aria-labelledby', 'header-account-title');
    profile.innerHTML =
        '<h2 id="header-account-title">Your account</h2><p>Anar · Preview profile</p><p>Account settings are available in the main app.</p>';
    document.body.append(profile);
    const positionProfile = () => {
        if (!profile.matches(':popover-open')) return;
        const rect = account.getBoundingClientRect();
        profile.style.top = `${rect.bottom + 8}px`;
        profile.style.left = `${Math.max(12, Math.min(rect.right - profile.offsetWidth, window.innerWidth - profile.offsetWidth - 12))}px`;
    };
    profile.addEventListener('toggle', positionProfile);
    window.addEventListener('resize', positionProfile, { passive: true });
    window.addEventListener('scroll', positionProfile, { passive: true });

    if (!staticHeader) {
        const utilities = document.createElement('div');
        utilities.className = 'header-utilities';
        utilities.append(
            header.querySelector('.header-context'),
            saved,
            account,
        );
        header.querySelector('.header-end')?.remove();
        header.append(menu, utilities);

        let shell = document.querySelector('#shell');
        if (!shell) {
            shell = document.createElement('div');
            shell.id = 'shell';
            header.before(shell);
        }
        // Composer measures this bar to position its workspace toolbar beneath it.
        const bar = document.createElement('div');
        bar.className = 'scroll-header';
        shell.append(bar);
        bar.append(header);
    }

    function syncSaved() {
        const total = S?.read().savedIdeas?.length || 0;
        count.textContent = total;
        count.hidden = total === 0;
    }
    window.addEventListener('expadu:change', syncSaved);
    window.addEventListener('storage', syncSaved);
    window.addEventListener('pageshow', syncSaved);
    syncSaved();
})();
