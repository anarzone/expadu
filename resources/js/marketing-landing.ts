/**
 * Landing — "Cologne in colour".
 *
 * Every section renders complete server-side; this file only enhances:
 * - reveals: elements animate in once when they reach the viewport;
 * - artwork: decorative SVGs (sun, train, lights, map route, stars) follow
 *   the scroll through an eased --p value, so wheel steps glide, not jump;
 * - the day clock: a pill that names the time of day you're scrolling through;
 * - anchor glide: in-page links scroll slowly and stop when the visitor scrolls;
 * - the Composer sample conversation: plays once on its own, then by tap.
 *
 * Nothing here moves text with the scroll, re-renders on scroll, or changes
 * the page height (all Composer answers share one grid cell).
 */

type ScrubElement = HTMLElement | SVGElement;

const ease = (t: number): number =>
    t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;

const clamp01 = (value: number): number => Math.max(0, Math.min(1, value));

const viewportHeight = (): number =>
    window.innerHeight || document.documentElement.clientHeight || 800;

const viewportWidth = (): number =>
    window.innerWidth || document.documentElement.clientWidth || 1200;

const root = document.querySelector<HTMLElement>('[data-xv]');

if (root) {
    const motion = !matchMedia('(prefers-reduced-motion: reduce)').matches;

    setupReveals(root, motion);
    const artwork = setupArtwork(root, motion);
    const clock = setupClock(root);
    setupGlide(root, motion);
    setupComposer(root, motion);

    let frame = 0;
    const onScroll = (): void => {
        if (frame) {
            return;
        }

        frame = requestAnimationFrame(() => {
            frame = 0;
            artwork.measure();
            clock.update();
        });
    };

    window.addEventListener('scroll', onScroll, {
        passive: true,
        capture: true,
    });
    window.addEventListener('resize', onScroll);
    artwork.measure(true);
    clock.update();
}

/* ── Reveals ───────────────────────────────────────────────────── */
function setupReveals(root: HTMLElement, motion: boolean): void {
    const html = document.documentElement;

    // The head script in landing.blade.php set data-xv-anim before first
    // paint and removes it if this file hasn't started within 4 s. If it
    // already gave up, keep everything visible rather than hiding it again.
    html.dataset.xvReady = '1';

    if (
        !motion ||
        !('IntersectionObserver' in window) ||
        html.dataset.xvAnim !== 'on'
    ) {
        delete html.dataset.xvAnim;

        return;
    }

    // Which elements to reveal when a watched element comes into view.
    const waiting = new Map<Element, Element[]>();

    const observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (!entry.isIntersecting) {
                    continue;
                }

                waiting
                    .get(entry.target)
                    ?.forEach((element) => element.setAttribute('data-in', ''));
                waiting.delete(entry.target);
                observer.unobserve(entry.target);
            }
        },
        { threshold: 0.2, rootMargin: '0px 0px -12% 0px' },
    );

    root.querySelectorAll<HTMLElement>('[data-reveal]').forEach((element) => {
        // A handwriting wipe starts fully clipped, and Chrome never reports a
        // fully clipped element as intersecting — so watch its container.
        const watched =
            element.dataset.reveal === 'write'
                ? (element.parentElement ?? element)
                : element;

        waiting.set(watched, [...(waiting.get(watched) ?? []), element]);
        observer.observe(watched);
    });
}

/* ── Scroll-linked artwork ─────────────────────────────────────── */
function setupArtwork(
    root: HTMLElement,
    motion: boolean,
): { measure: (snap?: boolean) => void } {
    const items = Array.from(
        root.querySelectorAll<ScrubElement>('[data-scrub]'),
    ).map((element) => ({
        element,
        target: Number.NaN,
        current: Number.NaN,
    }));
    let easing = 0;

    const paint = (item: (typeof items)[number], value: number): void => {
        item.current = value;
        item.element.style.setProperty('--p', value.toFixed(4));
    };

    const step = (): void => {
        easing = 0;
        let moving = false;

        for (const item of items) {
            const delta = item.target - item.current;

            if (Math.abs(delta) < 0.0008) {
                if (item.current !== item.target) {
                    paint(item, item.target);
                }

                continue;
            }

            moving = true;
            paint(item, item.current + delta * 0.12);
        }

        if (moving) {
            easing = requestAnimationFrame(step);
        }
    };

    const measure = (snap = false): void => {
        if (!motion) {
            return;
        }

        const vh = viewportHeight();

        for (const item of items) {
            const reference =
                item.element.dataset.ref === 'parent'
                    ? (item.element.parentElement ?? item.element)
                    : item.element;
            const rect = reference.getBoundingClientRect();
            const mode = item.element.dataset.scrub;
            let progress: number;

            if (mode === 'out') {
                progress = -rect.top / Math.max(rect.height, 1);
            } else if (mode === 'through') {
                progress = (vh - rect.top) / (vh + rect.height);
            } else {
                const span = Number.parseFloat(
                    item.element.dataset.span ?? '0.75',
                );
                progress = (vh - rect.top) / (vh * span);
            }

            item.target = clamp01(progress);

            if (snap || Number.isNaN(item.current)) {
                paint(item, item.target);
            }
        }

        if (!easing) {
            easing = requestAnimationFrame(step);
        }
    };

    return { measure };
}

/* ── Day clock ─────────────────────────────────────────────────── */
function setupClock(root: HTMLElement): { update: () => void } {
    const pill = root.querySelector<HTMLElement>('.xv-clock');
    const time = root.querySelector<HTMLElement>('.xv-clock__time');
    const label = root.querySelector<HTMLElement>('.xv-clock__label');
    const footer = root.querySelector<HTMLElement>('.xv-footer');
    const stops = Array.from(
        root.querySelectorAll<HTMLElement>('[data-clock]'),
    );
    let lastKey: string | null = null;

    const update = (): void => {
        if (!pill || !time || !label) {
            return;
        }

        const vh = viewportHeight();
        const vw = viewportWidth();
        const middle = vh / 2;
        let hit: HTMLElement | null = null;

        for (const stop of stops) {
            const rect = stop.getBoundingClientRect();

            // The horizontal check picks the visible card in the phone swipe row.
            if (
                rect.top <= middle &&
                rect.bottom >= middle &&
                rect.left < vw * 0.6 &&
                rect.right > vw * 0.4
            ) {
                hit = stop;
            }
        }

        const footerVisible =
            !!footer && footer.getBoundingClientRect().top < vh - 24;
        const value = hit?.dataset.clock ?? '';
        const key = value + (footerVisible ? '|footer' : '');

        if (key === lastKey) {
            return;
        }

        lastKey = key;
        const [clockTime, clockLabel, colour, flag] = value.split('|');

        if (clockTime) {
            time.dataset.v = clockTime;
            label.dataset.v = clockLabel ?? '';
            pill.style.setProperty('--dot', colour ?? '#ffe363');
        }

        pill.dataset.show =
            clockTime && flag !== 'hide' && !footerVisible ? '1' : '0';
    };

    return { update };
}

/* ── Anchor glide ──────────────────────────────────────────────── */
function setupGlide(root: HTMLElement, motion: boolean): void {
    let frame = 0;

    // marketing.css sets html { scroll-behavior: smooth }; each step must be
    // instant or the browser re-smooths every frame.
    const jump = (y: number): void => {
        window.scrollTo({ top: y, behavior: 'instant' as ScrollBehavior });
    };

    const stop = (): void => {
        cancelAnimationFrame(frame);
        frame = 0;
        window.removeEventListener('wheel', stop, true);
        window.removeEventListener('touchstart', stop, true);
        window.removeEventListener('keydown', stop, true);
    };

    root.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const link = event.target.closest<HTMLAnchorElement>('a[href^="#"]');
        const id = link?.getAttribute('href')?.slice(1);

        if (!link || !id) {
            return;
        }

        const target = document.getElementById(id);

        if (!target) {
            return;
        }

        event.preventDefault();
        stop();
        history.replaceState(null, '', `#${id}`);

        const start = window.scrollY;
        const end = start + target.getBoundingClientRect().top;

        if (!motion) {
            jump(end);

            return;
        }

        const duration = Math.min(
            2600,
            Math.max(1100, Math.abs(end - start) * 0.45),
        );
        const startedAt = performance.now();

        window.addEventListener('wheel', stop, true);
        window.addEventListener('touchstart', stop, true);
        window.addEventListener('keydown', stop, true);

        const step = (now: number): void => {
            const progress = Math.min(1, (now - startedAt) / duration);
            jump(start + (end - start) * ease(progress));

            if (progress < 1) {
                frame = requestAnimationFrame(step);
            } else {
                stop();
            }
        };

        frame = requestAnimationFrame(step);
    });
}

/* ── Composer sample conversation ──────────────────────────────── */
function setupComposer(root: HTMLElement, motion: boolean): void {
    const section = root.querySelector<HTMLElement>('#composer');
    const prompts = Array.from(
        root.querySelectorAll<HTMLButtonElement>('.xv-prompt'),
    );
    const questions = Array.from(root.querySelectorAll<HTMLElement>('.xv-q'));
    const answers = Array.from(root.querySelectorAll<HTMLElement>('.xv-ans'));
    const typing = root.querySelector<HTMLElement>('.xv-typing');

    if (!section || prompts.length === 0 || answers.length === 0) {
        return;
    }

    const last = prompts.length - 1;
    let current = 0;
    let userTookOver = false;
    const timers: number[] = [];

    const later = (callback: () => void, delay: number): void => {
        timers.push(window.setTimeout(callback, delay));
    };

    const clearTimers = (): void => {
        timers.splice(0).forEach((timer) => window.clearTimeout(timer));
    };

    const show = (index: number, writing: boolean): void => {
        current = index;
        prompts.forEach((prompt, i) =>
            prompt.setAttribute('aria-pressed', String(i === index)),
        );
        questions.forEach((question, i) => {
            question.dataset.on = i === index ? '1' : '0';
        });
        answers.forEach((answer, i) => {
            answer.dataset.on = !writing && i === index ? '1' : '0';
        });

        if (typing) {
            typing.dataset.on = writing ? '1' : '0';
        }
    };

    const answer = (index: number, delay: number): void => {
        show(index, motion);

        if (motion) {
            later(() => show(index, false), delay);
        }
    };

    const playNext = (): void => {
        if (userTookOver || current >= last) {
            return;
        }

        later(() => {
            if (userTookOver) {
                return;
            }

            answer(current + 1, 1200);
            later(playNext, 1200);
        }, 7000);
    };

    prompts.forEach((prompt, index) => {
        prompt.addEventListener('click', () => {
            userTookOver = true;
            clearTimers();
            answer(index, 800);
        });
    });

    if (!motion || !('IntersectionObserver' in window)) {
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            if (!entries.some((entry) => entry.isIntersecting)) {
                return;
            }

            observer.disconnect();

            if (!userTookOver) {
                answer(current, 1200);
                later(playNext, 1200);
            }
        },
        { threshold: 0.35 },
    );

    observer.observe(section);
}
