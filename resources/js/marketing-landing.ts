/**
 * Landing — "Cologne in motion".
 *
 * Every section renders complete server-side; this file only enhances:
 * - reveals: elements animate in once when they reach the viewport;
 * - the story: one pinned phone plays the sample day. Its CSS keyframes run on
 *   the section's scroll timeline on their own; where that isn't supported, or
 *   motion is reduced, this file steps through the same keyframes instead;
 * - Composer: a sample conversation plays once with a demo finger, and the card
 *   you pick becomes your question; tapping anything takes over;
 * - artwork: decorative SVGs (sun, map route, stars) follow the scroll through
 *   an eased --p value, so wheel steps glide, not jump;
 * - the day clock: a pill that names the time of day you're scrolling through;
 * - anchor glide: in-page links scroll slowly and stop when the visitor scrolls.
 *
 * Nothing here moves text with the scroll, re-renders on scroll, or changes the
 * page height (Composer's home and answers share one grid cell).
 */

type ScrubElement = HTMLElement | SVGElement;
type Box = { x: number; y: number; w: number; h: number };

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
    setupHeroTram(root, motion);
    const artwork = setupArtwork(root, motion);
    const clock = setupClock(root);
    const story = setupStory(root, motion);
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
            story.update();
        });
    };

    window.addEventListener('scroll', onScroll, {
        passive: true,
        capture: true,
    });
    window.addEventListener('resize', () => {
        story.measure();
        onScroll();
    });
    artwork.measure(true);
    clock.update();
    story.measure();
    void document.fonts?.ready.then(() => story.measure());
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

/* ── Hero: tram 4 sets off along the route once the phone has landed ── */
function setupHeroTram(root: HTMLElement, motion: boolean): void {
    const tram = root.querySelector<SVGAnimationElement>(
        '.xv-hero-tram animateMotion',
    );
    const phone = root.querySelector<HTMLElement>('.xv-hero-phone');

    if (!tram || !phone || !motion || !('IntersectionObserver' in window)) {
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            if (!entries.some((entry) => entry.isIntersecting)) {
                return;
            }

            observer.disconnect();
            window.setTimeout(() => tram.beginElement(), 1600);
        },
        { threshold: 0.3 },
    );

    observer.observe(phone);
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

/* ── The story: one pinned phone ───────────────────────────────── */
function setupStory(
    root: HTMLElement,
    motion: boolean,
): { update: () => void; measure: () => void } {
    const story = root.querySelector<HTMLElement>('.xv-story');
    const pin = story?.querySelector<HTMLElement>('.xv-pin');
    const portal = story?.querySelector<HTMLElement>('.xv-portal');
    const slot = story?.querySelector<HTMLElement>('.xv-devslot');
    const scene = story?.querySelector<HTMLElement>('.xv-scene');

    if (!story || !pin || !portal || !slot || !scene) {
        return { update: () => {}, measure: () => {} };
    }

    // Where one step hands over to the next (scroll progress through the
    // pinned range), and where each step rests (percent of the keyframes).
    const handover = [0.18, 0.4, 0.59, 0.75];
    const rest = [6, 30, 50, 66, 97];
    const glide =
        motion &&
        CSS.supports('animation-timeline: --xv-story') &&
        CSS.supports('view-timeline-name: --xv-story');
    let step = -1;
    let shown = 0;
    let tween = 0;

    story.dataset.mode = glide ? 'glide' : 'step';

    const animations = (): Animation[] =>
        story
            .getAnimations({ subtree: true })
            .filter((animation) =>
                (animation as CSSAnimation).animationName?.startsWith('xvk-'),
            );

    const show = (percent: number, list = animations()): void => {
        shown = percent;
        list.forEach((animation) => {
            animation.currentTime = percent * 10;
        });
    };

    const tweenTo = (percent: number): void => {
        cancelAnimationFrame(tween);

        if (!motion) {
            show(percent);

            return;
        }

        const from = shown;
        const startedAt = performance.now();
        const duration = Math.min(1700, 650 + Math.abs(percent - from) * 16);
        const list = animations();

        const frame = (now: number): void => {
            const progress = Math.min(1, (now - startedAt) / duration);
            show(from + (percent - from) * ease(progress), list);

            if (progress < 1) {
                tween = requestAnimationFrame(frame);
            }
        };

        tween = requestAnimationFrame(frame);
    };

    const update = (): void => {
        if (glide) {
            return;
        }

        const rect = story.getBoundingClientRect();
        const progress = clamp01(
            -rect.top / Math.max(rect.height - viewportHeight(), 1),
        );
        const next = handover.filter((edge) => progress >= edge).length;

        if (next === step) {
            return;
        }

        const first = step === -1;
        step = next;

        if (first) {
            show(rest[next]);
        } else {
            tweenTo(rest[next]);
        }
    };

    // The street opens out of the phone's own screen: measure where that
    // screen sits in the pinned frame, and start the street small inside it,
    // as the Events screen shows it.
    const measure = (): void => {
        const frame = pin.getBoundingClientRect();
        const box = slot.getBoundingClientRect();
        const bezel = box.width * 0.0244;
        const screen: Box = {
            x: box.left + bezel - frame.left,
            y: box.top + bezel - frame.top,
            w: box.width - 2 * bezel,
            h: box.height - 2 * bezel,
        };
        const set = (name: string, value: number, unit = 'px'): void => {
            portal.style.setProperty(name, `${Math.round(value)}${unit}`);
        };

        set('--pt', screen.y);
        set('--pl', screen.x);
        set('--pr', frame.width - screen.x - screen.w);
        set('--pb', frame.height - screen.y - screen.h);
        set('--prad', screen.w * 0.108);

        const [originX, originY] = getComputedStyle(scene)
            .transformOrigin.split(' ')
            .map((value) => Number.parseFloat(value));
        const focusX = scene.offsetLeft + (originX || scene.offsetWidth * 0.35);
        const focusY = scene.offsetTop + (originY || scene.offsetHeight * 0.58);

        set('--sx', screen.x + screen.w / 2 - focusX);
        set('--sy', screen.y + screen.h * 0.6 - focusY);
        portal.style.setProperty(
            '--ss',
            Math.max(
                0.2,
                Math.min(
                    1,
                    (screen.h * 0.62) / Math.max(scene.offsetHeight, 1),
                ),
            ).toFixed(3),
        );
        update();
    };

    return { update, measure };
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
    const win = root.querySelector<HTMLElement>('.xv-cwin');
    const home = win?.querySelector<HTMLElement>('.xv-cview--home');
    const finger = win?.querySelector<HTMLElement>('.xv-finger');
    const ghost = win?.querySelector<HTMLElement>('.xv-ghost');

    if (!win || !home || !finger || !ghost) {
        return;
    }

    const answers = Array.from(win.querySelectorAll<HTMLElement>('.xv-ans'));
    const order = answers.map((answer) => Number(answer.dataset.a));
    const timers: number[] = [];
    let played = 0;
    let run = 0;
    let visible = false;
    let userTookOver = false;
    let fingerAt: string | null = null;

    const later = (callback: () => void, delay: number): void => {
        timers.push(window.setTimeout(callback, delay));
    };

    const clearTimers = (): void => {
        timers.splice(0).forEach((timer) => window.clearTimeout(timer));
    };

    const boxOf = (element: Element): Box => {
        const frame = win.getBoundingClientRect();
        const rect = element.getBoundingClientRect();

        return {
            x: rect.left - frame.left,
            y: rect.top - frame.top,
            w: rect.width,
            h: rect.height,
        };
    };

    const answerFor = (index: number): HTMLElement | undefined =>
        answers.find((answer) => answer.dataset.a === String(index));

    // The card you picked becomes your question: a coloured shape flies from
    // the card to the question bubble while the answer fills in below it.
    const show = (
        index: number,
        source: HTMLElement | null,
        focus = false,
    ): void => {
        const answer = answerFor(index);
        const bubble = answer?.querySelector<HTMLElement>('.xv-you');

        if (!answer || !bubble) {
            return;
        }

        const from = source ? boxOf(source) : null;
        const fill = source ? getComputedStyle(source) : null;

        home.dataset.on = '0';
        answers.forEach((item) => {
            item.dataset.on = item === answer ? '1' : '0';
        });

        if (motion && from && fill && typeof ghost.animate === 'function') {
            const to = boxOf(bubble);
            const frame = (
                box: Box,
                radius: string,
                colour: string,
                opacity: number,
            ): Keyframe => ({
                left: `${box.x}px`,
                top: `${box.y}px`,
                width: `${box.w}px`,
                height: `${box.h}px`,
                borderRadius: radius,
                backgroundColor: colour,
                opacity,
            });

            ghost.style.display = 'block';
            bubble.style.opacity = '0';
            ghost.animate(
                [
                    frame(from, fill.borderRadius, fill.backgroundColor, 1),
                    { ...frame(to, '18px', '#def5f8', 1), offset: 0.92 },
                    frame(to, '18px', '#def5f8', 0),
                ],
                { duration: 620, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' },
            ).onfinish = () => {
                ghost.style.display = 'none';
                bubble.style.opacity = '';
            };
        }

        if (focus) {
            answer
                .querySelector<HTMLElement>('.xv-back')
                ?.focus({ preventScroll: true });
        }
    };

    const backHome = (focus = false): void => {
        answers.forEach((answer) => {
            answer.dataset.on = '0';
        });
        home.dataset.on = '1';

        if (focus) {
            home.querySelector<HTMLElement>('.xv-starter')?.focus({
                preventScroll: true,
            });
        }
    };

    // The demo finger enters from the text box, taps, then steps out of the way.
    const tap = (
        target: Element | null | undefined,
        then: () => void,
    ): void => {
        if (!target) {
            then();

            return;
        }

        const box = boxOf(target);
        const frame = win.getBoundingClientRect();
        const to = `translate(${Math.round(box.x + box.w * 0.62)}px, ${Math.round(box.y + Math.min(box.h * 0.58, box.h - 18))}px)`;
        const from =
            fingerAt ??
            `translate(${Math.round(frame.width * 0.78)}px, ${Math.round(frame.height - 40)}px)`;

        finger.dataset.on = '1';
        finger.animate([{ transform: from }, { transform: to }], {
            duration: 900,
            easing: 'cubic-bezier(0.45, 0, 0.2, 1)',
            fill: 'forwards',
        });
        fingerAt = to;
        later(() => {
            finger.dataset.press = '0';
            void finger.offsetWidth;
            finger.dataset.press = '1';
            later(then, 260);
            later(() => {
                finger.dataset.on = '0';
            }, 520);
        }, 950);
    };

    const play = (): void => {
        if (userTookOver || !visible || !motion || played >= order.length) {
            return;
        }

        const current = ++run;
        const index = order[played];
        const alive = (): boolean => current === run;

        later(
            () => {
                if (!alive()) {
                    return;
                }

                const source = win.querySelector<HTMLElement>(
                    `.xv-cview--home [data-q="${index}"]`,
                );

                tap(source, () => {
                    if (!alive()) {
                        return;
                    }

                    show(index, source);
                    played++;
                    later(() => {
                        if (!alive()) {
                            return;
                        }

                        tap(answerFor(index)?.querySelector('.xv-back'), () => {
                            if (!alive()) {
                                return;
                            }

                            backHome();

                            if (played >= order.length) {
                                fingerAt = null;

                                return;
                            }

                            later(play, 700);
                        });
                    }, 6200);
                });
            },
            played === 0 ? 1100 : 300,
        );
    };

    const takeOver = (): void => {
        if (userTookOver) {
            return;
        }

        userTookOver = true;
        run++;
        clearTimers();
        finger.dataset.on = '0';
    };

    win.addEventListener('pointerdown', (event) => {
        if (event.isTrusted) {
            takeOver();
        }
    });

    win.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const question = event.target.closest<HTMLElement>(
            '.xv-cview--home [data-q]',
        );

        if (question) {
            takeOver();
            show(Number(question.dataset.q), question, true);

            return;
        }

        if (event.target.closest('.xv-back')) {
            takeOver();
            backHome(true);
        }
    });

    if (!motion || !('IntersectionObserver' in window)) {
        return;
    }

    // Play only while the window is on screen; pick up where it left off.
    new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                const was = visible;
                visible = entry.isIntersecting;

                if (visible && !was && !userTookOver) {
                    backHome();
                    play();
                } else if (!visible && was) {
                    run++;
                    clearTimers();
                    finger.dataset.on = '0';
                }
            }
        },
        { threshold: 0.45 },
    ).observe(win);
}
