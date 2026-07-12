/**
 * Marketing site (expadu.com) — the only JavaScript the landing ships.
 * Vanilla + tiny by design: theme toggle, persona tabs, the hero typing
 * demo (canned data, no live LLM for anonymous visitors), waitlist form.
 * Every section renders complete server-side; this file only enhances.
 */

type DemoCard = { band: string; t: string; m: string; why: string };
type DemoScenario = { prompt: string; cards: DemoCard[] };

/* ── Theme ─────────────────────────────────────────────────────── */
(() => {
    const KEY = 'expadu-marketing-theme';
    let saved: string | null = null;

    try {
        saved = localStorage.getItem(KEY);
    } catch {
        // storage unavailable (private mode) — fall back to OS preference
    }

    const dark = saved
        ? saved === 'dark'
        : matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.classList.toggle('dark', dark);

    document.getElementById('themeToggle')?.addEventListener('click', () => {
        const isDark = document.documentElement.classList.toggle('dark');

        try {
            localStorage.setItem(KEY, isDark ? 'dark' : 'light');
        } catch {
            // non-persistent toggle is fine
        }
    });
})();

/* ── Persona tabs (cards are server-rendered; we only toggle) ───── */
(() => {
    const tabs = Array.from(
        document.querySelectorAll<HTMLButtonElement>('#personaTabs button'),
    );
    const cards = Array.from(
        document.querySelectorAll<HTMLElement>('[data-persona-card]'),
    );

    if (tabs.length === 0 || cards.length === 0) {
        return;
    }

    for (const tab of tabs) {
        tab.addEventListener('click', () => {
            for (const t of tabs) {
                t.setAttribute('aria-selected', String(t === tab));
            }

            for (const card of cards) {
                card.hidden = card.dataset.personaCard !== tab.dataset.persona;
            }
        });
    }
})();

/* ── Hero typing demo ───────────────────────────────────────────── */
(() => {
    const promptEl = document.getElementById('demoPrompt');
    const cardsEl = document.getElementById('demoCards');
    const dataEl = document.getElementById('demo-data');

    if (!promptEl || !cardsEl || !dataEl) {
        return;
    }

    // Scenario 1 is server-rendered for no-JS/SEO; with reduced motion we
    // leave that static state untouched.
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    let scenarios: DemoScenario[];

    try {
        scenarios = JSON.parse(dataEl.textContent ?? '[]') as DemoScenario[];
    } catch {
        return;
    }

    if (scenarios.length === 0) {
        return;
    }

    const renderCards = (cards: DemoCard[]): HTMLElement[] => {
        cardsEl.innerHTML = cards
            .map(
                (c) =>
                    `<div class="demo-card"><div class="band">${c.band}</div>` +
                    `<div class="t">${c.t}</div><div class="m">${c.m}</div>` +
                    `<div class="why">${c.why}</div></div>`,
            )
            .join('');

        return Array.from(cardsEl.children) as HTMLElement[];
    };

    let scenarioIndex = 0;
    const playScenario = (): void => {
        const scenario = scenarios[scenarioIndex % scenarios.length];
        scenarioIndex++;
        let i = 0;
        promptEl.textContent = '';
        cardsEl.innerHTML = '';

        const type = (): void => {
            if (i < scenario.prompt.length) {
                promptEl.textContent += scenario.prompt[i++];
                setTimeout(type, 26 + Math.random() * 34);

                return;
            }

            setTimeout(() => {
                const els = renderCards(scenario.cards);
                els.forEach((el, n) => {
                    setTimeout(() => el.classList.add('show'), 160 + n * 240);
                });
                setTimeout(() => {
                    els.forEach((el) => el.classList.remove('show'));
                    setTimeout(playScenario, 420);
                }, 4600);
            }, 420);
        };
        type();
    };
    playScenario();
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
    const csrf =
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';

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
                'X-CSRF-TOKEN': csrf,
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
