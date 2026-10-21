import { Link, router } from '@inertiajs/react';
import {
    useEffect,
    useId,
    useLayoutEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import type { KeyboardEvent } from 'react';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { useCurrentPageContext } from '@/lib/page-context';
import { composer } from '@/routes';
import { store as composerEntry } from '@/routes/composer/entry';

/*
 * Composer's tray (the mock's connected/composer-entry + today-c/composer-
 * controls): one box to ask in from any page. Phones open it from the round
 * button beside the floating sections; tablets and desktops from "Ask
 * Composer" in the header or ⌘K, and it sits just under the header with the
 * page dimmed. Sending opens Composer with the words. It lives in the
 * persistent shell, so an unsent request survives moving between pages.
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
    const [leftOut, setLeftOut] = useState<string | null>(null);
    const context = useCurrentPageContext();
    const about = context && context.label !== leftOut ? context : null;
    const tray = useRef<HTMLDivElement>(null);
    const field = useRef<HTMLTextAreaElement>(null);
    const launcher = useRef<HTMLButtonElement>(null);
    const menuId = useId();

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

    const hide = () => {
        setMenuOpen(false);
        const box = tray.current;

        if (!box || still()) {
            setOpen(false);

            return;
        }

        box.animate(
            [
                { opacity: 1, transform: 'translateY(0) scale(1)' },
                { opacity: 0, transform: 'translateY(10px) scale(0.96)' },
            ],
            { duration: 180, easing: 'cubic-bezier(.4,0,1,1)', fill: 'both' },
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
        setOpen(false);
        // By POST, so the words stay out of the URL, history and logs.
        router.post(
            composerEntry.url(),
            about
                ? { prompt: request, about: about.label }
                : { prompt: request },
        );
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
                    aria-label="Open Composer"
                    aria-expanded={false}
                    data-composer-opener
                    onClick={() => setOpen(true)}
                >
                    <span className="shell-composer-round-icon">
                        <Icon name="spark" variant="line" />
                    </span>
                </button>
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
                    {about && (
                        <div className="composer-tray-about">
                            <span>About: {about.label}</span>
                            <button
                                type="button"
                                aria-label={`Don’t include ${about.label}`}
                                onClick={() => {
                                    setLeftOut(about.label);
                                    field.current?.focus();
                                }}
                            >
                                <Icon name="close" variant="line" />
                            </button>
                        </div>
                    )}
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
                </div>
            )}
        </>
    );
}
