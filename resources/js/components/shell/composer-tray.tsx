import { Link } from '@inertiajs/react';
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
import type { TrayAsked } from '@/components/composer/tray-answer';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { composer } from '@/routes';

// The answer and Composer's styles load with the first request, not every page.
const TrayAnswer = lazy(() => import('@/components/composer/tray-answer'));

/*
 * Composer's tray (the mock's connected/composer-entry + today-c/composer-
 * controls): one box to ask in from any page. Phones open it from the round
 * button beside the floating sections; tablets and desktops from "Ask
 * Composer" in the header or ⌘K, and it sits just under the header with the
 * page dimmed. Send asks Composer here (direction B, owner 22 Oct 2026): the
 * answer drops under the box (rises above it on a phone), under the AI line
 * shown here (sending is the consent, owner 21 Oct 2026); Full Composer
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

/** Opens the tray (the header button, ⌘K). */
export function openComposerTray(): void {
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
    const talked = useComposerKept();
    // Where the dim starts (under the header when Composer opens from it), and
    // where the way back sits (under the header's button, or in the corner).
    const [dimTop, setDimTop] = useState(0);
    const [chipAt, setChipAt] = useState<{ top: number; left: number } | null>(
        null,
    );
    const tray = useRef<HTMLDivElement>(null);
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

    const hide = () => {
        setMenuOpen(false);
        // What was asked is answered in the conversation; reopening asks nothing again.
        setAsked(null);
        const box = tray.current;

        if (!box || still()) {
            setOpen(false);

            return;
        }

        // Box and answer go together (B): fade and rise 8px.
        box.animate(
            [
                { opacity: 1, transform: 'translateY(0)' },
                { opacity: 0, transform: 'translateY(-8px)' },
            ],
            { duration: 240, easing: 'ease', fill: 'both' },
        ).finished.then(
            () => setOpen(false),
            () => {},
        );
    };

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
        // Asked here, once; the box stays for the next one.
        setAsked({ id: Date.now(), text: request });
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
            {!isOpen && talked && (
                <button
                    type="button"
                    className={
                        chipAt
                            ? 'composer-tray-resume'
                            : 'composer-tray-resume is-corner'
                    }
                    style={chipAt ?? undefined}
                    data-composer-opener
                    onClick={() => setOpen(true)}
                >
                    <Icon name="chat" variant="line" />
                    Back to your conversation
                    <Icon name="arrow" variant="line" />
                </button>
            )}
            {isOpen && (
                // The dim takes the click that closes, so nothing under it is pressed.
                <div
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
                    <button
                        type="button"
                        className="composer-tray-dismiss"
                        aria-label="Close Composer"
                        title="Close Composer"
                        onClick={close}
                    >
                        <span className="composer-tray-icon">
                            <Icon name="close" variant="line" />
                        </span>
                    </button>
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
                            placeholder="Ask, discover, or plan…"
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
                                                    setOpen(false);

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
                    {talked && (
                        <Suspense fallback={null}>
                            <TrayAnswer
                                asked={asked}
                                notice={aiNotice}
                                onClose={close}
                            />
                        </Suspense>
                    )}
                </div>
            )}
        </>
    );
}
