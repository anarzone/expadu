import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import type { ReactNode, RefObject } from 'react';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';

/** What the box's + menu opens: a view in place, or a new conversation. */
export type MenuPick =
    | 'plans'
    | 'saved'
    | 'history'
    | 'appointment'
    | 'add'
    | 'origin'
    | 'fresh';

const ITEMS: {
    pick: MenuPick;
    label: string;
    icon: IconName;
    changes?: boolean;
}[][] = [
    [
        { pick: 'plans', label: 'My Plans', icon: 'calendar' },
        { pick: 'saved', label: 'Saved', icon: 'bookmark' },
        { pick: 'history', label: 'History', icon: 'clock' },
    ],
    [
        {
            pick: 'appointment',
            label: 'Add appointment',
            icon: 'calendar-plus',
            changes: true,
        },
        {
            pick: 'add',
            label: 'Add a place or event',
            icon: 'plus',
            changes: true,
        },
        { pick: 'origin', label: 'Starting point', icon: 'pin' },
    ],
    [{ pick: 'fresh', label: 'Start fresh', icon: 'chat', changes: true }],
];

/*
 * Composer's box, in the panel and in Full Composer alike (the Full Composer
 * canvas): the request, the + menu and Send, with who reads the request said
 * under it. The menu is one list in both sizes; what it opens opens in place.
 * While Composer works the menu still opens; only what changes the plan waits.
 */
export function ComposerBox({
    fieldRef,
    fieldId,
    value,
    placeholder,
    label,
    busy = false,
    sendBusy = false,
    enterSends = true,
    origin,
    notice,
    onChange,
    onSend,
    onPick,
}: {
    fieldRef: RefObject<HTMLTextAreaElement | null>;
    fieldId?: string;
    value: string;
    placeholder: string;
    label: string;
    /** Composer is working: what changes the plan waits. */
    busy?: boolean;
    /** Send waits too (Full Composer's; the panel queues nothing either way). */
    sendBusy?: boolean;
    /** Enter sends (not on a phone's keyboard, where it's a new line). */
    enterSends?: boolean;
    /** The starting point, said beside its item. */
    origin: string | null;
    notice?: ReactNode;
    onChange: (value: string) => void;
    onSend: () => void;
    onPick: (pick: MenuPick) => void;
}) {
    const [open, setOpen] = useState(false);
    const menuId = useId();
    const actions = useRef<HTMLDivElement>(null);

    // The field grows with the request, up to five lines.
    useLayoutEffect(() => {
        const box = fieldRef.current;

        if (!box) {
            return;
        }

        box.style.height = 'auto';
        box.style.height = `${Math.min(box.scrollHeight, 120)}px`;
    }, [value, fieldRef]);

    // Measured again when its width changes (a panel that opened hidden, a
    // rotated phone), so a waiting request never keeps a stale height.
    useEffect(() => {
        const box = fieldRef.current;

        if (!box) {
            return;
        }

        let width = box.clientWidth;
        const observer = new ResizeObserver(() => {
            if (box.clientWidth === width) {
                return;
            }

            width = box.clientWidth;
            box.style.height = 'auto';
            box.style.height = `${Math.min(box.scrollHeight, 120)}px`;
        });
        observer.observe(box);

        return () => observer.disconnect();
    }, [fieldRef]);

    // A tap or focus outside closes the menu.
    useEffect(() => {
        if (!open) {
            return;
        }

        const outside = (event: Event) => {
            if (!actions.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('pointerdown', outside);
        document.addEventListener('focusin', outside);

        return () => {
            document.removeEventListener('pointerdown', outside);
            document.removeEventListener('focusin', outside);
        };
    }, [open]);

    return (
        <div className="composer-box">
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    setOpen(false);
                    onSend();
                }}
            >
                <label className="sr-only" htmlFor={fieldId ?? menuId}>
                    {label}
                </label>
                <textarea
                    id={fieldId ?? menuId}
                    ref={fieldRef}
                    rows={1}
                    maxLength={500}
                    value={value}
                    placeholder={placeholder}
                    onChange={(event) => onChange(event.target.value)}
                    onKeyDown={(event) => {
                        if (
                            event.key === 'Enter' &&
                            !event.shiftKey &&
                            !event.nativeEvent.isComposing &&
                            enterSends
                        ) {
                            event.preventDefault();
                            setOpen(false);
                            onSend();
                        }
                    }}
                />
                <div className="composer-box-tools">
                    <div
                        ref={actions}
                        className="composer-box-actions"
                        onKeyDown={(event) => {
                            if (event.key === 'Escape' && open) {
                                event.preventDefault();
                                event.stopPropagation();
                                setOpen(false);
                                actions.current
                                    ?.querySelector<HTMLElement>(
                                        `[aria-controls="${menuId}-menu"]`,
                                    )
                                    ?.focus();
                            }
                        }}
                    >
                        <button
                            type="button"
                            aria-label="Composer menu"
                            aria-expanded={open}
                            aria-controls={`${menuId}-menu`}
                            onClick={() => setOpen((on) => !on)}
                        >
                            <Icon name="plus" variant="line" />
                        </button>
                        {open && (
                            <div
                                id={`${menuId}-menu`}
                                className="composer-box-menu"
                                role="menu"
                                aria-label="Composer menu"
                            >
                                {ITEMS.map((group, i) => (
                                    <div key={i} role="group">
                                        {group.map((item) => (
                                            <button
                                                key={item.pick}
                                                type="button"
                                                role="menuitem"
                                                disabled={
                                                    busy && !!item.changes
                                                }
                                                onClick={() => {
                                                    setOpen(false);
                                                    onPick(item.pick);
                                                }}
                                            >
                                                <Icon
                                                    name={item.icon}
                                                    variant="line"
                                                />
                                                {item.label}
                                                {item.pick === 'origin' &&
                                                    origin && (
                                                        <small>{`· ${origin}`}</small>
                                                    )}
                                            </button>
                                        ))}
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                    <button
                        className="composer-box-send"
                        type="submit"
                        aria-label="Send request"
                        disabled={!value.trim() || sendBusy}
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
            {notice}
        </div>
    );
}
