import { csrfHeaders } from '@/lib/csrf';

/**
 * Marketing site (expadu.com): the small script every marketing page ships.
 * The header's menu on narrow screens, the band the page slides over, and
 * the launch waitlist form. Every page renders complete server-side; this
 * file only enhances.
 */

/* ── The band the page slides over ─────────────────────────────── */
(() => {
    const band = document.querySelector<HTMLElement>('.mk-band');

    if (!band) {
        return;
    }

    // A band taller than the screen (a long title on a phone) scrolls until
    // its bottom is in view, then stays while the page covers it.
    const place = () =>
        band.style.setProperty(
            '--band-top',
            `${Math.min(0, window.innerHeight - band.offsetHeight)}px`,
        );

    place();
    window.addEventListener('resize', place);
})();

/* ── Header menu (narrow screens) ──────────────────────────────── */
(() => {
    const menus = Array.from(
        document.querySelectorAll<HTMLDetailsElement>('details.xv-menu'),
    );

    if (menus.length === 0) {
        return;
    }

    // Close the menu after picking a link, on a tap outside it, or on Escape.
    document.addEventListener('click', (event) => {
        for (const menu of menus) {
            const inside =
                event.target instanceof Node && menu.contains(event.target);
            const picked =
                event.target instanceof Element &&
                !!event.target.closest('.xv-menu__panel a');

            if (menu.open && (!inside || picked)) {
                menu.open = false;
            }
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        for (const menu of menus) {
            if (menu.open) {
                menu.open = false;
                menu.querySelector('summary')?.focus();
            }
        }
    });
})();

/* ── Waitlist (progressive enhancement over a real form POST) ───── */
(() => {
    const form = document.getElementById('waitlistForm');

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const button = form.querySelector<HTMLButtonElement>(
        'button[type="submit"]',
    );
    const message = form.querySelector<HTMLElement>('.waitlist-msg');

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        if (!button || !message) {
            return;
        }

        button.disabled = true;
        button.textContent = 'Saving…';
        message.textContent = '';
        message.className = 'waitlist-msg';

        void fetch(form.action, {
            method: 'POST',
            headers: {
                ...csrfHeaders(),
                Accept: 'application/json',
            },
            body: new FormData(form),
        })
            .then(async (response) => {
                const payload = (await response.json().catch(() => ({}))) as {
                    message?: string;
                    errors?: Record<string, string[]>;
                };

                if (response.ok) {
                    form.reset();
                    button.textContent = 'Notify me';
                    message.textContent =
                        payload.message ??
                        'Check your inbox to confirm — then you’re on the list.';
                    message.classList.add('ok');

                    return;
                }

                button.textContent = 'Notify me';
                message.textContent =
                    Object.values(payload.errors ?? {})[0]?.[0] ??
                    payload.message ??
                    'Something went wrong — please try again.';
                message.classList.add('err');
            })
            .catch(() => {
                button.textContent = 'Notify me';
                message.textContent = 'Network hiccup — please try again.';
                message.classList.add('err');
            })
            .finally(() => {
                button.disabled = false;
            });
    });
})();
