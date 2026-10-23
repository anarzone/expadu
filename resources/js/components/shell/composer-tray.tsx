import { Link, router } from '@inertiajs/react';
import {
    lazy,
    Suspense,
    useEffect,
    useId,
    useLayoutEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import type { KeyboardEvent } from 'react';
import { AiNoticeLine, useAiNotice } from '@/components/composer/ai-notice';
import { AskFirstChip } from '@/components/composer/ask-first-chip';
import type { TrayAsked, TrayView } from '@/components/composer/tray-body';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { composer } from '@/routes';

// The panel's start and conversation, and Composer's styles, load when it first
// opens, not with every page.
const TrayBody = lazy(() => import('@/components/composer/tray-body'));

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

const today = () =>
    new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Berlin' }).format(
        new Date(),
    );

const actions: { label: string; icon: IconName; href: () => string }[] = [
    {
        label: 'My Plans',
        icon: 'calendar',
        href: () => composer.url({ query: { panel: 'plans' } }),
    },
    {
        label: 'Saved',
        icon: 'bookmark',
        href: () => composer.url({ query: { panel: 'saved' } }),
    },
    {
        label: 'History',
        icon: 'clock',
        href: () => composer.url({ query: { panel: 'history' } }),
    },
    {
        label: 'Add appointment',
        icon: 'calendar',
        href: () =>
            composer.url({ query: { entry: 'appointment', date: today() } }),
    },
    { label: 'Try Composer', icon: 'spark', href: () => composer.url() },
    {
        label: 'Start fresh',
        icon: 'plus',
        href: () => composer.url({ query: { new: '1' } }),
    },
];

const still = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function ComposerTray({ onComposer }: { onComposer: boolean }) {
    const isOpen = useTrayOpen();
    const [text, setText] = useState('');
    const [menuOpen, setMenuOpen] = useState(false);
    // The request just sent, and whether there's a conversation to come back to.
    const [asked, setAsked] = useState<TrayAsked | null>(null);
    // The panel's start each time it opens; the conversation once asked, or
    // reopened from "Back to your conversation".
    const [view, setView] = useState<TrayView>('start');
    const backed = useRef(false);
    // The conversation's day in focus, once the answer has loaded it.
    const [focusDay, setFocusDay] = useState<string | null>(null);
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
    const menuId = useId();
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

    // Closed, it opens at its start next time.
    const shut = () => {
        setOpen(false);
        setView('start');
    };

    const hide = () => {
        if (closing.current) {
            return;
        }

        setMenuOpen(false);
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

    // The panel grows up from the box, at most to 12px under the page's header;
    // the keyboard counts.
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
            element.style.maxHeight = `${Math.max(
                160,
                box.getBoundingClientRect().top - Math.max(12, header + 12),
            )}px`;
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

    // The field grows with the request, up to five lines.
    useLayoutEffect(() => {
        const box = field.current;

        if (!box) {
            return;
        }

        box.style.height = 'auto';
        box.style.height = `${Math.min(box.scrollHeight, 120)}px`;
    }, [text, isOpen]);

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

    const send = (event?: { preventDefault(): void }) => {
        event?.preventDefault();
        const request = text.trim();

        if (!request) {
            return;
        }

        setText('');
        setMenuOpen(false);
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

        if (menuOpen) {
            setMenuOpen(false);
            document
                .querySelector<HTMLElement>(`[aria-controls="${menuId}"]`)
                ?.focus();

            return;
        }

        close();
    };

    // On Composer's own page there is no tray: asking Composer means its box.
    useEffect(() => {
        if (onComposer && isOpen) {
            document.getElementById('studio-request')?.focus();
            setOpen(false);
        }
    }, [onComposer, isOpen]);

    if (onComposer) {
        return null;
    }

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
                    >
                        <header className="composer-tray-head">
                            {view === 'talk' && (
                                <button
                                    type="button"
                                    className="composer-tray-head-button"
                                    aria-label="Back to recent conversations"
                                    onClick={() => {
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
                        <Suspense fallback={null}>
                            <TrayBody
                                view={view}
                                asked={asked}
                                notice={aiNotice}
                                onOpen={() => {
                                    setView('talk');
                                    setKept(true);
                                    field.current?.focus({
                                        preventScroll: true,
                                    });
                                }}
                                onFocus={setFocusDay}
                                onDraft={(words) => {
                                    setText(words);
                                    field.current?.focus({
                                        preventScroll: true,
                                    });
                                }}
                            />
                        </Suspense>
                    </section>
                    <form onSubmit={send}>
                        <textarea
                            ref={field}
                            rows={1}
                            maxLength={500}
                            value={text}
                            onChange={(event) => setText(event.target.value)}
                            onKeyDown={(event) => {
                                if (
                                    event.key === 'Enter' &&
                                    !event.shiftKey &&
                                    !event.nativeEvent.isComposing
                                ) {
                                    send(event);
                                }
                            }}
                            aria-label="Your request to Composer"
                            placeholder={
                                view === 'talk' && focusDay
                                    ? `Ask or change ${weekday(focusDay)}…`
                                    : 'Ask, discover, or plan…'
                            }
                        />
                        <div className="composer-tray-tools">
                            <div className="composer-tray-actions">
                                <button
                                    type="button"
                                    aria-label="Composer actions"
                                    aria-expanded={menuOpen}
                                    aria-controls={menuId}
                                    onClick={() => setMenuOpen((on) => !on)}
                                >
                                    <span className="composer-tray-icon">
                                        <Icon name="plus" variant="line" />
                                    </span>
                                </button>
                                {menuOpen && (
                                    <div
                                        id={menuId}
                                        className="composer-tray-menu"
                                        role="menu"
                                        aria-label="Composer actions"
                                    >
                                        {actions.map((action) => (
                                            <Link
                                                key={action.label}
                                                href={action.href()}
                                                role="menuitem"
                                                onClick={() => {
                                                    setMenuOpen(false);
                                                    shut();

                                                    // A fresh start leaves nothing to come back to.
                                                    if (
                                                        action.label ===
                                                        'Start fresh'
                                                    ) {
                                                        setKept(false);
                                                    }
                                                }}
                                            >
                                                <span
                                                    className="composer-tray-icon"
                                                    aria-hidden="true"
                                                >
                                                    <Icon
                                                        name={action.icon}
                                                        variant="line"
                                                    />
                                                </span>
                                                {action.label}
                                            </Link>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <button
                                className="composer-tray-send"
                                type="submit"
                                aria-label="Send request"
                                disabled={!text.trim()}
                            >
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth={1.8}
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    aria-hidden="true"
                                >
                                    <path d="M12 19V5m-7 7 7-7 7 7" />
                                </svg>
                            </button>
                        </div>
                    </form>
                    {aiNotice && <AiNoticeLine notice={aiNotice} />}
                </div>
            )}
        </>
    );
}
