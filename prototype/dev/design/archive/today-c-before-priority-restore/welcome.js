/* An optional invitation inside Today; never a pre-app questionnaire. */
(function () {
    const key = 'expadu-welcome-dismissed-v1';
    const host = document.querySelector('#optional-welcome');
    if (!host) return;
    let dismissed = false,
        started = false;
    try {
        dismissed = localStorage.getItem(key) === 'true';
        started =
            JSON.parse(localStorage.getItem('expadu-bureaucracy-design-v1'))
                ?.orientation?.started === true;
    } catch {}
    const preview =
        new URLSearchParams(location.search).get('welcome') === 'preview';
    if ((dismissed || started) && !preview) return;
    host.hidden = false;
    host.innerHTML = `<span class="welcome-symbol">${ExpaduIcon('spark')}</span><div><strong>Settling in?</strong><a href="../bureaucracy-next/?guide=continue&person=self">Get started ${ExpaduIcon('arrow')}</a></div><button type="button" aria-label="Dismiss welcome">${ExpaduIcon('close')}</button>`;
    host.querySelector('button').addEventListener('click', () => {
        try {
            localStorage.setItem(key, 'true');
        } catch {}
        if (matchMedia('(prefers-reduced-motion: reduce)').matches) {
            host.hidden = true;
            return;
        }
        const motion = host.animate([{ opacity: 1 }, { opacity: 0 }], {
            duration: 140,
            easing: 'ease-out',
        });
        motion.finished
            .then(() => {
                host.hidden = true;
            })
            .catch(() => {
                host.hidden = true;
            });
    });
})();
