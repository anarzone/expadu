import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useId,
    useLayoutEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import type {
    CSSProperties,
    KeyboardEvent,
    PointerEvent as ReactPointerEvent,
    ReactNode,
    Ref,
} from 'react';
import { createPortal } from 'react-dom';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

/*
 * Pickers and filters (the mock's anchored menus: Events' Category, Area
 * and Sort, Places' pickers): a panel that floats over the page under its
 * button, one open at a time; a tap outside or Escape closes it and focus
 * returns to the button. Where a page asks, it becomes a bottom sheet on
 * smaller screens (`sheet="phone"` up to 700px, `"tablet"` up to 999px),
 * closed by dragging its top edge down.
 */

const SHEET_UP_TO = { phone: 700, tablet: 999 } as const;

// One open at a time: the open menu's id lives here, so opening one closes
// the one before it.
let openId: string | null = null;
const openListeners = new Set<() => void>();
const setOpenId = (next: string | null) => {
    openId = next;
    openListeners.forEach((notify) => notify());
};
const useOpenId = () =>
    useSyncExternalStore(
        (notify) => {
            openListeners.add(notify);

            return () => openListeners.delete(notify);
        },
        () => openId,
        () => null,
    );

type TriggerProps = {
    ref: Ref<HTMLButtonElement>;
    type: 'button';
    'aria-expanded': boolean;
    'aria-controls': string;
    'aria-haspopup': 'menu' | 'dialog';
    onClick: () => void;
};

type MenuApi = { close: () => void };

const MenuContext = createContext<MenuApi>({ close: () => {} });

type AnchoredMenuProps = {
    /** Names the menu; shown in its head and as the sheet's title. */
    label: string;
    /** The button that opens it; spread the props onto a <button>. */
    trigger: (props: TriggerProps) => ReactNode;
    children: ReactNode | ((api: MenuApi) => ReactNode);
    align?: 'start' | 'end';
    /** The element whose left or right edge the panel lines up with
     *  (default: its button), e.g. a filter row's edge. It still opens
     *  under the button. */
    anchor?: () => Element | null;
    /** Panel width in px (default 320). */
    width?: number;
    /** Becomes a bottom sheet up to this screen size. */
    sheet?: keyof typeof SHEET_UP_TO;
    /** Show the head with the label and a close button (default true). */
    head?: boolean;
    /** 'menu' for a list of choices; 'dialog' for richer content (a
     *  calendar, a search with results). */
    kind?: 'menu' | 'dialog';
};

function useMatches(query: string | null): boolean {
    return useSyncExternalStore(
        (notify) => {
            if (!query) {
                return () => {};
            }

            const list = window.matchMedia(query);
            list.addEventListener('change', notify);

            return () => list.removeEventListener('change', notify);
        },
        () => (query ? window.matchMedia(query).matches : false),
        () => false,
    );
}

const focusables = (root: HTMLElement) =>
    [
        ...root.querySelectorAll<HTMLElement>(
            '[role="menuitemradio"], [role="menuitem"], [role="option"]',
        ),
    ].filter((el) => !el.hasAttribute('disabled'));

export function AnchoredMenu({
    label,
    trigger,
    children,
    align = 'start',
    anchor,
    width = 320,
    sheet,
    head = true,
    kind = 'menu',
}: AnchoredMenuProps) {
    const id = useId();
    // The latest edge to line up with, read when the panel is placed.
    const edgeOf = useRef(anchor);
    useLayoutEffect(() => {
        edgeOf.current = anchor;
    });
    const open = useOpenId() === id;
    const button = useRef<HTMLButtonElement>(null);
    const panel = useRef<HTMLDivElement>(null);
    const drag = useRef<{ y: number; id: number } | null>(null);
    const asSheet = useMatches(
        sheet ? `(max-width: ${SHEET_UP_TO[sheet]}px)` : null,
    );

    const close = useCallback(
        (refocus = true) => {
            if (openId === id) {
                setOpenId(null);
            }

            if (refocus) {
                button.current?.focus({ preventScroll: true });
            }
        },
        [id],
    );

    // Where the panel goes: inside the dialog the button sits in (a modal
    // Sheet makes everything outside it inert), else the page.
    const [host, setHost] = useState<Element | null>(null);

    const toggle = () => {
        if (open) {
            close();

            return;
        }

        setHost(button.current?.closest('dialog') ?? document.body);
        setOpenId(id);
    };

    // Float under the button (above it when there's no room below), kept
    // inside the screen.
    useLayoutEffect(() => {
        if (!open || asSheet) {
            return;
        }

        const position = () => {
            const anchor = button.current?.getBoundingClientRect();
            const edge = (
                edgeOf.current?.() ?? button.current
            )?.getBoundingClientRect();
            const box = panel.current;

            if (!anchor || !edge || !box) {
                return;
            }

            const w = Math.min(width, window.innerWidth - 16);
            const h = box.offsetHeight;
            const below = anchor.bottom + 4;
            const top =
                below + h <= window.innerHeight - 8 || anchor.top - h - 4 < 8
                    ? below
                    : anchor.top - h - 4;
            const left =
                align === 'end' ? edge.right - w : Math.max(8, edge.left);
            const x = Math.max(8, Math.min(left, window.innerWidth - w - 8));
            box.style.top = `${top}px`;
            box.style.left = `${x}px`;
            // Inside a dialog that's still sliding in (a transform makes it the
            // fixed panel's frame), it lands off by the dialog's own offset:
            // take that back off, so it sits under its button either way.
            const placed = box.getBoundingClientRect();
            if (Math.round(placed.left) !== Math.round(x) || Math.round(placed.top) !== Math.round(top)) {
                box.style.left = `${x - (placed.left - x)}px`;
                box.style.top = `${top - (placed.top - top)}px`;
            }
        };

        position();
        window.addEventListener('resize', position);
        window.addEventListener('scroll', position, true);
        // Placed again once the dialog it's in has finished sliding in.
        let live = true;
        const sliding = panel.current?.closest('dialog')?.getAnimations() ?? [];
        if (sliding.length > 0) {
            void Promise.all(sliding.map((a) => a.finished))
                .then(() => live && position())
                .catch(() => undefined);
        }

        return () => {
            live = false;
            window.removeEventListener('resize', position);
            window.removeEventListener('scroll', position, true);
        };
    }, [open, asSheet, align, width]);

    // Focus the chosen option, or the first, as it opens.
    useEffect(() => {
        if (!open || !panel.current) {
            return;
        }

        const options = focusables(panel.current);
        const chosen =
            options.find(
                (el) =>
                    el.getAttribute('aria-checked') === 'true' ||
                    el.getAttribute('aria-selected') === 'true',
            ) ??
            options.find((el) => el.tabIndex === 0) ??
            options[0];
        (chosen ?? panel.current).focus({ preventScroll: true });
    }, [open]);

    // A tap outside closes it, without stealing the tap.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointer = (event: PointerEvent) => {
            const target = event.target as Node;

            if (
                panel.current?.contains(target) ||
                button.current?.contains(target)
            ) {
                return;
            }

            close(false);
        };

        document.addEventListener('pointerdown', onPointer);

        return () => document.removeEventListener('pointerdown', onPointer);
    }, [open, close]);

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close();

            return;
        }

        const keys = ['ArrowDown', 'ArrowUp', 'Home', 'End'];

        // Content with its own arrow keys (a calendar) handles them.
        if (
            event.defaultPrevented ||
            !keys.includes(event.key) ||
            !panel.current
        ) {
            return;
        }

        const options = focusables(panel.current);
        const at = options.indexOf(document.activeElement as HTMLElement);

        if (!options.length) {
            return;
        }

        event.preventDefault();
        const next =
            event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? options.length - 1
                  : (at +
                        (event.key === 'ArrowDown' ? 1 : -1) +
                        options.length) %
                    options.length;
        options[next].focus();
    };

    // As a sheet, its head is the handle: drag it down to close.
    const onPointerDown = (event: ReactPointerEvent<HTMLElement>) => {
        if (!asSheet || (event.target as Element).closest('button')) {
            return;
        }

        drag.current = { y: event.clientY, id: event.pointerId };
        event.currentTarget.setPointerCapture(event.pointerId);
    };
    const onPointerMove = (event: ReactPointerEvent<HTMLElement>) => {
        if (drag.current?.id !== event.pointerId || !panel.current) {
            return;
        }

        panel.current.style.transform = `translateY(${Math.max(0, event.clientY - drag.current.y)}px)`;
    };
    const onPointerUp = (event: ReactPointerEvent<HTMLElement>) => {
        if (drag.current?.id !== event.pointerId || !panel.current) {
            return;
        }

        const far = event.clientY - drag.current.y > 80;
        drag.current = null;
        panel.current.style.transform = '';

        if (far) {
            close();
        }
    };

    const api: MenuApi = { close: () => close() };

    return (
        <>
            {trigger({
                ref: button,
                type: 'button',
                'aria-expanded': open,
                'aria-controls': id,
                'aria-haspopup': kind,
                onClick: toggle,
            })}
            {open &&
                createPortal(
                    <MenuContext.Provider value={api}>
                        {asSheet && (
                            <div className="ui-menu-scrim" aria-hidden="true" />
                        )}
                        <div
                            ref={panel}
                            id={id}
                            role={asSheet ? 'dialog' : kind}
                            aria-modal={asSheet || undefined}
                            aria-label={label}
                            tabIndex={-1}
                            className={cn('ui-menu', asSheet && 'is-sheet')}
                            style={
                                {
                                    '--ui-menu-width': `${width}px`,
                                } as CSSProperties
                            }
                            onKeyDown={onKeyDown}
                        >
                            {head && (
                                <div
                                    className="ui-menu-head"
                                    onPointerDown={onPointerDown}
                                    onPointerMove={onPointerMove}
                                    onPointerUp={onPointerUp}
                                    onPointerCancel={onPointerUp}
                                >
                                    <strong>{label}</strong>
                                    <button
                                        type="button"
                                        aria-label={`Close ${label}`}
                                        onClick={() => close()}
                                    >
                                        <Icon name="close" variant="line" />
                                    </button>
                                </div>
                            )}
                            {typeof children === 'function' ? (
                                <WithMenu render={children} />
                            ) : (
                                children
                            )}
                        </div>
                    </MenuContext.Provider>,
                    host ?? document.body,
                )}
        </>
    );
}

/** Hands the menu's controls to a render function among its children. */
function WithMenu({ render }: { render: (api: MenuApi) => ReactNode }) {
    return render(useContext(MenuContext));
}

/** A grid of options inside a menu; `columns` lays short ones in two. */
export function MenuOptions({
    columns = false,
    children,
}: {
    columns?: boolean;
    children: ReactNode;
}) {
    return (
        <div
            className={cn('ui-menu-options', columns && 'is-columns')}
            role="group"
        >
            {children}
        </div>
    );
}

type MenuOptionProps = {
    /** A choice (a filter, a sort): shows cyan with a check when chosen. */
    selected?: boolean;
    onSelect: () => void;
    icon?: IconName;
    /** A second line under the label. */
    description?: ReactNode;
    /** Keep the menu open after choosing (multi-choice filters). */
    keepOpen?: boolean;
    disabled?: boolean;
    children: ReactNode;
};

/** One row in a menu: 44px, the standard picker row. */
export function MenuOption({
    selected,
    onSelect,
    icon,
    description,
    keepOpen = false,
    disabled,
    children,
}: MenuOptionProps) {
    const { close } = useContext(MenuContext);

    return (
        <button
            type="button"
            className="ui-menu-option"
            role={selected === undefined ? 'menuitem' : 'menuitemradio'}
            aria-checked={selected === undefined ? undefined : selected}
            disabled={disabled}
            onClick={() => {
                onSelect();

                if (!keepOpen) {
                    close();
                }
            }}
        >
            {icon && <Icon name={icon} variant="line" />}
            {description ? (
                <span>
                    {children}
                    <small>{description}</small>
                </span>
            ) : (
                children
            )}
        </button>
    );
}
