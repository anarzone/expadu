import { Link, router } from '@inertiajs/react';
import {
    lazy,
    Suspense,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import type { KeyboardEvent } from 'react';
import { AiNoticeLine, useAiNotice } from '@/components/composer/ai-notice';
import { AskFirstChip } from '@/components/composer/ask-first-chip';
import { ComposerBox } from '@/components/composer/composer-box';
import type { MenuPick } from '@/components/composer/composer-box';
import { useComposerStart } from '@/components/composer/origin-store';
import type {
    default as TrayBodyComponent,
    TrayAsked,
    TrayPick,
    TrayView,
} from '@/components/composer/tray-body';
import type { ComposerView } from '@/components/composer/views';
import { Icon } from '@/components/ui/icon';
import { composer } from '@/routes';

// The panel's start and conversation, and Composer's styles, load apart from
// every page: ahead, once the page is idle (below), or when it first opens.
// Loaded ahead (below), it's drawn directly: a lazy component would still
// show its fallback for a moment, which is the empty panel this avoids.
let Loaded: typeof TrayBodyComponent | null = null;
const loadTrayBody = () =>
    import('@/components/composer/tray-body').then((module) => {
        Loaded = module.default;

        return module;
    });
const LazyTrayBody = lazy(loadTrayBody);

// Once per page: the panel's code, then its conversations and your day.
let warmed = false;
const warmTray = () => {
    if (warmed) {
        return;
    }

    warmed = true;
    void loadTrayBody()
        .then((module) => module.prefetchTray())
        .catch(() => {
            warmed = false;
        });
};

/*
 * Composer's tray (the mock's connected/composer-entry + today-c/composer-
 * controls): one panel to ask in from any page, with the box at its foot.
 * Phones open it from the round button beside the floating sections; tablets
 * and desktops from "Ask Composer" in the header or ⌘K, and it rises at the
 * foot of the screen with the page dimmed (owner, 22 Oct 2026: "anchor at the
 * bottom"). Before you ask, the panel offers the conversation to go back to
 * and recent ones; asked, the conversation grows above the box, under the AI
 * line shown here (sending is the consent, owner 21 Oct 2026). Full Composer
 * carries on with the same conversation. Closing keeps it for coming back. It
 * lives in the persistent shell, so an unsent request survives moving between
 * pages.
 */

let open = false;
const listeners = new Set<() => void>();
const setOpen = (next: boolean) => {
    open = next;
    listeners.forEach((notify) => notify());
};

// While the tray is going away: opening again keeps it instead.
let stopClosing: (() => void) | null = null;

/** Opens the tray (the header button, ⌘K). */
export function openComposerTray(): void {
    stopClosing?.();
    setOpen(true);
}

/**
 * Opens Composer at one of its views (the header's bookmark: Saved), every
 * time, whatever it showed last: in place on Composer's own page, else in
 * the panel, opening it if it's closed.
 */
export function openComposerView(view: MenuPick): void {
    if (document.getElementById('composer-page')) {
        window.dispatchEvent(
            new CustomEvent('composer:view', { detail: view }),
        );

        return;
    }

    openComposerTray();
    window.dispatchEvent(
        new CustomEvent('composer:tray-view', { detail: view }),
    );
}

// A conversation kept after closing (B): the dot on what opens Composer, and the way back.
let kept = false;
const setKept = (next: boolean) => {
    kept = next;
    listeners.forEach((notify) => notify());
};

const subscribe = (notify: () => void) => {
    listeners.add(notify);

    return () => listeners.delete(notify);
};

/** Whether there's a conversation from the tray, open or closed. */
function useComposerKept(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => kept,
        () => false,
    );
}

/** A conversation kept with Composer closed: the dot on what opens it. */
export function useComposerWaiting(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => kept && !open,
        () => false,
    );
}

function useTrayOpen(): boolean {
    return useSyncExternalStore(
        (notify) => {
            listeners.add(notify);

            return () => listeners.delete(notify);
        },
        () => open,
        () => false,
    );
}

// "Saturday": the day a plan in focus is on, as Composer's own box says it.
const weekday = (date: string) =>
    new Intl.DateTimeFormat('en-GB', {
        weekday: 'long',
        timeZone: 'Europe/Berlin',
    }).format(new Date(`${date}T12:00:00`));

const still = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function ComposerTray({ onComposer }: { onComposer: boolean }) {
    const isOpen = useTrayOpen();
    const [text, setText] = useState('');
    // The request just sent, and whether there's a conversation to come back to.
    const [asked, setAsked] = useState<TrayAsked | null>(null);
    // The + menu's last choice: its view opens in the panel.
    const [pick, setPick] = useState<TrayPick | null>(null);
    const origin = useComposerStart();
    // The panel's start each time it opens; the conversation once asked, or
    // reopened from "Back to your conversation".
    const [view, setView] = useState<TrayView>('start');
    const backed = useRef(false);
    // The conversation's day in focus, once the answer has loaded it.
    const [focusDay, setFocusDay] = useState<string | null>(null);
    // What the conversation's place shows: a view's ‹ goes back to the conversation.
    const [inner, setInner] = useState<ComposerView>('chat');
    const talked = useComposerKept();
    // Where the dim starts (under the header when Composer opens from it), and
    // where the way back sits (under the header's button, or in the corner).
    const [dimTop, setDimTop] = useState(0);
    const [chipAt, setChipAt] = useState<{ top: number; left: number } | null>(
        null,
    );
    // The way back shows for a moment after closing (owner, 22 Oct 2026); the dot
    // on what opens Composer is the lasting one.
    const [resume, setResume] = useState(false);
    const chip = useRef<HTMLButtonElement>(null);
    const tray = useRef<HTMLDivElement>(null);
    const panel = useRef<HTMLElement>(null);
    const dim = useRef<HTMLDivElement>(null);
    // One close at a time: a tap outside closes on pointerdown, then its click
    // lands on the dim; a second fade from full would flash the box back.
    const closing = useRef(false);
    const field = useRef<HTMLTextAreaElement>(null);
    const launcher = useRef<HTMLButtonElement>(null);
    // Read once the tray first opens: who reads the request, said before Send.
    const aiNotice = useAiNotice(isOpen);

    // Opening settles the box in; closing (hide) tucks it away before it
    // goes. Reduced motion: no movement.
    useEffect(() => {
        if (!isOpen) {
            return;
        }

        closing.current = false;
        field.current?.focus({ preventScroll: true });

        if (!still()) {
            tray.current?.animate(
                [
                    { opacity: 0, transform: 'translateY(10px) scale(0.96)' },
                    { opacity: 1, transform: 'translateY(0) scale(1)' },
                ],
                { duration: 260, easing: 'cubic-bezier(.16,1,.3,1)' },
            );
        }
    }, [isOpen]);

    // Open, the page under the dim is inert: no focus and no clicks but the one that
    // closes. From the header, the dim starts under it; otherwise it covers everything.
    useLayoutEffect(() => {
        if (!isOpen) {
            return;
        }

        const button = document.querySelector<HTMLElement>('.shell-composer');
        const header = button?.offsetParent
            ? document.querySelector<HTMLElement>('.shell-header')
            : null;
        // eslint-disable-next-line react-hooks/set-state-in-effect -- Measured from the page's own header, which is outside React's view.
        setDimTop(
            header ? Math.round(header.getBoundingClientRect().bottom) : 0,
        );
        const under = [
            document.getElementById('main'),
            header ? null : document.querySelector('.shell-header'),
        ].filter((element): element is HTMLElement => !!element);
        under.forEach((element) => element.setAttribute('inert', ''));

        return () =>
            under.forEach((element) => element.removeAttribute('inert'));
    }, [isOpen]);

    // Closed with a conversation kept: the way back, 8px under the header's button.
    useLayoutEffect(() => {
        if (isOpen || !talked) {
            return;
        }

        const place = () => {
            const button =
                document.querySelector<HTMLElement>('.shell-composer');
            const at = button?.offsetParent
                ? button.getBoundingClientRect()
                : null;
            setChipAt(
                at
                    ? {
                          top: Math.round(at.bottom + 8),
                          left: Math.round(at.left),
                      }
                    : null,
            );
        };

        place();
        window.addEventListener('resize', place);

        return () => window.removeEventListener('resize', place);
    }, [isOpen, talked]);

    // It goes about 4 s after closing, or at the first tap or scroll elsewhere,
    // whichever comes first. Reduced motion: it just goes.
    useEffect(() => {
        if (isOpen || !talked || !resume) {
            return;
        }

        let gone = false;
        const leave = (event?: Event) => {
            if (
                gone ||
                (event?.target instanceof Node &&
                    chip.current?.contains(event.target))
            ) {
                return;
            }

            gone = true;
            const fade =
                chip.current && !still()
                    ? chip.current.animate(
                          [
                              { opacity: 1 },
                              { opacity: 0, transform: 'translateY(-4px)' },
                          ],
                          { duration: 200, easing: 'ease', fill: 'forwards' },
                      )
                    : null;

            if (fade) {
                fade.finished.then(
                    () => setResume(false),
                    () => {},
                );
            } else {
                setResume(false);
            }
        };
        const timer = window.setTimeout(leave, 4000);
        const events = ['pointerdown', 'wheel', 'touchmove'] as const;
        events.forEach((name) =>
            document.addEventListener(name, leave, {
                capture: true,
                passive: true,
            }),
        );

        return () => {
            window.clearTimeout(timer);
            events.forEach((name) =>
                document.removeEventListener(name, leave, { capture: true }),
            );
        };
    }, [isOpen, talked, resume]);

    // Closed, it opens at its start next time, and a menu choice made before
    // isn't made again (a Start fresh would otherwise run again on opening).
    const shut = () => {
        setOpen(false);
        setView('start');
        setPick(null);
    };

    const hide = () => {
        if (closing.current) {
            return;
        }

        // What was asked is answered in the conversation; reopening asks nothing again.
        setAsked(null);
        const box = tray.current;

        if (!box || still()) {
            shut();
            setResume(true);

            return;
        }

        // Panel and box go together: fade and sink 8px, the dim fading with them.
        closing.current = true;
        const timing = { duration: 240, easing: 'ease', fill: 'both' } as const;
        const fade = box.animate(
            [
                { opacity: 1, transform: 'translateY(0)' },
                { opacity: 0, transform: 'translateY(8px)' },
            ],
            timing,
        );
        const dimFade = dim.current?.animate(
            [{ opacity: 1 }, { opacity: 0 }],
            timing,
        );
        stopClosing = () => {
            stopClosing = null;
            closing.current = false;
            fade.cancel();
            dimFade?.cancel();
        };
        fade.finished.then(
            () => {
                stopClosing = null;
                closing.current = false;
                shut();
                setResume(true);
            },
            () => {},
        );
    };

    // A link in it that leaves for another page (Open in Travel, a place or an
    // event, Full Composer) closes it with its fade; the conversation stays for
    // later (owner, 22 Oct 2026). A partial reload (turning personalisation on)
    // leaves it open.
    useEffect(() => {
        if (!isOpen) {
            return;
        }

        return router.on('start', (event) => {
            const { only, except } = event.detail.visit;

            if (only.length === 0 && except.length === 0) {
                hide();
            }
        });
    }, [isOpen]);

    // An outside tap closes it; the request stays for next time.
    useEffect(() => {
        if (!isOpen) {
            return;
        }

        const onPointer = (event: PointerEvent) => {
            const target = event.target as Node;

            if (tray.current?.contains(target)) {
                return;
            }

            if ((target as Element).closest?.('[data-composer-opener]')) {
                return;
            }

            hide();
        };

        document.addEventListener('pointerdown', onPointer);

        return () => document.removeEventListener('pointerdown', onPointer);
    }, [isOpen]);

    // The panel grows up from the box, at most to 12px under the page's header
    // (on a phone, all the way); the keyboard counts.
    useLayoutEffect(() => {
        const box = tray.current;
        const element = panel.current;

        if (!isOpen || !box || !element) {
            return;
        }

        const fit = () => {
            const header =
                document.querySelector('.shell-header')?.getBoundingClientRect()
                    .bottom ?? 0;
            const room = `${Math.max(
                160,
                box.getBoundingClientRect().top - Math.max(12, header + 12),
            )}px`;
            element.style.maxHeight = room;
            // On a phone the panel is Full Composer: it takes all of that room.
            element.style.height = window.matchMedia('(max-width: 700px)')
                .matches
                ? room
                : '';
        };

        fit();
        const observer = new ResizeObserver(fit);
        observer.observe(box);
        window.addEventListener('resize', fit);
        window.visualViewport?.addEventListener('resize', fit);

        return () => {
            observer.disconnect();
            window.removeEventListener('resize', fit);
            window.visualViewport?.removeEventListener('resize', fit);
        };
    }, [isOpen]);

    // ‹ goes back to the start, with the focus on its first button.
    useEffect(() => {
        if (view !== 'start' || !backed.current) {
            return;
        }

        backed.current = false;
        (
            tray.current?.querySelector<HTMLElement>(
                '.composer-tray-start :is(button, a)',
            ) ?? field.current
        )?.focus({ preventScroll: true });
    }, [view]);

    const close = () => {
        hide();
        // Back to whichever button opened it: the header's from 701px.
        window.setTimeout(() => {
            const header =
                document.querySelector<HTMLElement>('.shell-composer');
            (header?.offsetParent ? header : launcher.current)?.focus({
                preventScroll: true,
            });
        }, 200);
    };

    const send = () => {
        const request = text.trim();

        if (!request) {
            return;
        }

        setText('');
        // Asked here, once; the box stays for the next one. From the start,
        // it's a new conversation.
        setAsked({ id: Date.now(), text: request, fresh: view === 'start' });
        setView('talk');
        setKept(true);
        field.current?.focus({ preventScroll: true });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key !== 'Escape') {
            return;
        }

        event.preventDefault();
        close();
    };

    // On Composer's own page there is no tray: asking Composer means its box,
    // and a view opens there in place.
    useEffect(() => {
        if (onComposer && isOpen) {
            document.getElementById('studio-request')?.focus();
            setOpen(false);
        }
    }, [onComposer, isOpen]);

    // The panel's code and conversations load ahead, so opening it never
    // shows an empty panel, but only once the page itself is done: loaded,
    // its own deferred requests back, then a quiet second and an idle moment
    // (speed plan, 26 Oct 2026). Pointing at, focusing or touching what opens
    // Composer loads them at once.
    useEffect(() => {
        if (onComposer) {
            return;
        }

        let busy = 0;
        let quiet = 0;
        let idle = 0;
        const stop = () => {
            window.clearTimeout(quiet);

            if (typeof window.cancelIdleCallback === 'function') {
                window.cancelIdleCallback(idle);
            } else {
                window.clearTimeout(idle);
            }
        };
        const settle = () => {
            stop();

            if (warmed || busy > 0 || document.readyState !== 'complete') {
                return;
            }

            quiet = window.setTimeout(() => {
                idle =
                    typeof window.requestIdleCallback === 'function'
                        ? window.requestIdleCallback(warmTray, {
                              timeout: 3000,
                          })
                        : window.setTimeout(warmTray, 0);
            }, 1000);
        };
        const offStart = router.on('start', () => {
            busy += 1;
            stop();
        });
        const offFinish = router.on('finish', () => {
            busy = Math.max(0, busy - 1);
            settle();
        });
        const intent = (event: Event) => {
            if (
                event.target instanceof Element &&
                event.target.closest('[data-composer-opener]')
            ) {
                warmTray();
            }
        };
        window.addEventListener('load', settle);
        document.addEventListener('pointerover', intent);
        document.addEventListener('focusin', intent);
        document.addEventListener('touchstart', intent, { passive: true });
        settle();

        return () => {
            stop();
            offStart();
            offFinish();
            window.removeEventListener('load', settle);
            document.removeEventListener('pointerover', intent);
            document.removeEventListener('focusin', intent);
            document.removeEventListener('touchstart', intent);
        };
    }, [onComposer]);

    // Opened at a view: it opens in the panel, as the + menu opens it.
    useEffect(() => {
        if (onComposer) {
            return;
        }

        const open = (event: Event) => {
            setPick({
                id: Date.now(),
                pick: (event as CustomEvent<MenuPick>).detail,
            });
            setView('talk');
            setKept(true);
        };
        window.addEventListener('composer:tray-view', open);

        return () => window.removeEventListener('composer:tray-view', open);
    }, [onComposer]);

    if (onComposer) {
        return null;
    }

    const TrayBody = Loaded ?? LazyTrayBody;

    return (
        <>
            {!isOpen && (
                <button
                    ref={launcher}
                    type="button"
                    className="shell-composer-round"
                    aria-label={
                        talked
                            ? 'Open Composer, with your conversation'
                            : 'Open Composer'
                    }
                    aria-expanded={false}
                    data-composer-opener
                    onClick={() => setOpen(true)}
                >
                    <span className="shell-composer-round-icon">
                        <Icon name="spark" variant="line" />
                    </span>
                    {talked && (
                        <span
                            className="shell-composer-dot"
                            aria-hidden="true"
                        />
                    )}
                </button>
            )}
            {!isOpen && talked && resume && (
                <button
                    ref={chip}
                    type="button"
                    className={
                        chipAt
                            ? 'composer-tray-resume'
                            : 'composer-tray-resume is-corner'
                    }
                    style={chipAt ?? undefined}
                    data-composer-opener
                    onClick={() => {
                        setView('talk');
                        setOpen(true);
                    }}
                >
                    <Icon name="chat" variant="line" />
                    Back to your conversation
                    <Icon name="arrow" variant="line" />
                </button>
            )}
            {isOpen && (
                // The dim takes the tap, so nothing under it is pressed. The tap has
                // already closed it on pointerdown; its click only hands focus back.
                <div
                    ref={dim}
                    className="composer-backdrop"
                    style={{ top: dimTop }}
                    aria-hidden="true"
                    onClick={close}
                />
            )}
            {isOpen && (
                <div
                    ref={tray}
                    className="composer-tray"
                    role="dialog"
                    aria-label="Ask Composer"
                    onKeyDown={onKeyDown}
                >
                    <section
                        ref={panel}
                        className="composer-tray-panel"
                        aria-label="Composer"
                        data-layer-host
                    >
                        <header className="composer-tray-head">
                            {view === 'talk' && (
                                <button
                                    type="button"
                                    className="composer-tray-head-button"
                                    aria-label={
                                        inner === 'chat'
                                            ? 'Back to recent conversations'
                                            : 'Back to the conversation'
                                    }
                                    onClick={() => {
                                        if (inner !== 'chat') {
                                            setPick({
                                                id: Date.now(),
                                                pick: 'chat',
                                            });

                                            return;
                                        }

                                        backed.current = true;
                                        setView('start');
                                    }}
                                >
                                    <Icon name="back" variant="line" />
                                </button>
                            )}
                            <span
                                className="composer-tray-mark"
                                aria-hidden="true"
                            >
                                <Icon name="spark" variant="line" />
                            </span>
                            <strong>Composer</strong>
                            <AskFirstChip />
                            <Link
                                className="composer-tray-full"
                                href={composer.url()}
                                aria-label="Open full Composer"
                            >
                                <Icon name="grow" variant="line" />
                                <span>Full Composer</span>
                            </Link>
                            <button
                                type="button"
                                className="composer-tray-head-button"
                                aria-label="Close Composer"
                                onClick={close}
                            >
                                <Icon name="close" variant="line" />
                            </button>
                        </header>
                        <Suspense
                            fallback={
                                <div
                                    className="composer-tray-start is-loading"
                                    aria-busy="true"
                                />
                            }
                        >
                            <TrayBody
                                view={view}
                                asked={asked}
                                pick={pick}
                                notice={aiNotice}
                                onOpen={() => {
                                    setView('talk');
                                    setKept(true);
                                    field.current?.focus({
                                        preventScroll: true,
                                    });
                                }}
                                onFocus={setFocusDay}
                                onView={setInner}
                                onDraft={(words) => {
                                    setText(words);
                                    field.current?.focus({
                                        preventScroll: true,
                                    });
                                }}
                            />
                        </Suspense>
                    </section>
                    <ComposerBox
                        fieldRef={field}
                        value={text}
                        label="Your request to Composer"
                        placeholder={
                            view === 'talk' && focusDay
                                ? `Ask or change ${weekday(focusDay)}…`
                                : 'Ask, discover, or plan…'
                        }
                        origin={origin?.label ?? null}
                        notice={aiNotice && <AiNoticeLine notice={aiNotice} />}
                        onChange={setText}
                        onSend={send}
                        onPick={(choice) => {
                            // A fresh start leaves the last one to come back to in History.
                            setPick({ id: Date.now(), pick: choice });
                            setView('talk');
                            setKept(true);
                        }}
                    />
                </div>
            )}
        </>
    );
}
