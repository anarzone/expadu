import { useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import type { Conversation } from './types';

/*
 * A listed conversation with its ⋯ (owner, 24 Oct 2026): Rename edits its
 * title in place (Enter keeps it, Esc leaves it; empty is the automatic
 * title again), Delete asks once, since it's for good. The same in the
 * panel's Recent conversations, History and Full Composer's rail.
 */
export function ManagedConversation({
    conversation,
    row,
    busy,
    className,
    onRename,
    onDelete,
}: {
    conversation: Conversation;
    /** The row as the list draws it (its button that opens the conversation). */
    row: ReactNode;
    busy: boolean;
    className?: string;
    onRename: (title: string) => Promise<boolean>;
    onDelete: () => Promise<boolean>;
}) {
    const [menu, setMenu] = useState(false);
    const [renaming, setRenaming] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [title, setTitle] = useState(conversation.title);
    const box = useRef<HTMLDivElement>(null);
    const more = useRef<HTMLButtonElement>(null);
    const field = useRef<HTMLInputElement>(null);
    const keep = useRef<HTMLButtonElement>(null);
    const menuId = useId();
    const name = conversation.title;

    // A tap or focus outside closes the menu.
    useEffect(() => {
        if (!menu) {
            return;
        }

        const outside = (event: Event) => {
            if (!box.current?.contains(event.target as Node)) {
                setMenu(false);
            }
        };
        document.addEventListener('pointerdown', outside);
        document.addEventListener('focusin', outside);

        return () => {
            document.removeEventListener('pointerdown', outside);
            document.removeEventListener('focusin', outside);
        };
    }, [menu]);

    useEffect(() => {
        if (renaming) {
            field.current?.select();
        }
    }, [renaming]);

    useEffect(() => {
        if (confirming) {
            keep.current?.focus({ preventScroll: true });
        }
    }, [confirming]);

    // Focus goes back to the ⋯ once it's usable again (it waits while renaming).
    const returning = useRef(false);
    const back = () => {
        returning.current = true;
        more.current?.focus({ preventScroll: true });
    };
    useEffect(() => {
        if (!renaming && !confirming && returning.current) {
            returning.current = false;
            more.current?.focus({ preventScroll: true });
        }
    }, [renaming, confirming]);

    const save = async () => {
        if (title.trim() === name) {
            setRenaming(false);
            back();

            return;
        }

        if (await onRename(title)) {
            setRenaming(false);
            back();
        }
    };

    return (
        <div
            ref={box}
            className={['composer-conv', className].filter(Boolean).join(' ')}
        >
            <div className="composer-conv-line">
                {renaming ? (
                    <form
                        className="composer-conv-rename"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save();
                        }}
                    >
                        <input
                            ref={field}
                            aria-label={`New name for ${name}`}
                            maxLength={120}
                            value={title}
                            placeholder="Empty: named from its first message"
                            onChange={(event) => setTitle(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Escape') {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    setTitle(name);
                                    setRenaming(false);
                                    back();
                                }
                            }}
                        />
                    </form>
                ) : (
                    row
                )}
                <div
                    className="composer-conv-more"
                    onKeyDown={(event) => {
                        if (event.key === 'Escape' && menu) {
                            event.preventDefault();
                            event.stopPropagation();
                            setMenu(false);
                            back();
                        }
                    }}
                >
                    <button
                        ref={more}
                        type="button"
                        aria-label={`More options for ${name}`}
                        aria-haspopup="menu"
                        aria-expanded={menu}
                        aria-controls={menu ? menuId : undefined}
                        disabled={busy || renaming}
                        onClick={() => setMenu((on) => !on)}
                    >
                        <span aria-hidden="true">⋯</span>
                    </button>
                    {menu && (
                        <div
                            id={menuId}
                            className="composer-conv-menu"
                            role="menu"
                            aria-label={`Options for ${name}`}
                        >
                            <button
                                type="button"
                                role="menuitem"
                                onClick={() => {
                                    setMenu(false);
                                    setTitle(name);
                                    setRenaming(true);
                                }}
                            >
                                <Icon name="pencil" variant="line" />
                                Rename
                            </button>
                            <button
                                type="button"
                                role="menuitem"
                                className="is-danger"
                                onClick={() => {
                                    setMenu(false);
                                    setConfirming(true);
                                }}
                            >
                                <Icon name="trash" variant="line" />
                                Delete
                            </button>
                        </div>
                    )}
                </div>
            </div>
            {confirming && (
                <div
                    className="composer-confirm composer-conv-confirm"
                    role="alertdialog"
                    aria-label={`Delete ${name}?`}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            event.stopPropagation();
                            setConfirming(false);
                            back();
                        }
                    }}
                >
                    <strong>Delete this conversation?</strong>
                    <span>This can’t be undone.</span>
                    <span className="composer-view-buttons">
                        <button
                            ref={keep}
                            type="button"
                            className="composer-ghost"
                            onClick={() => {
                                setConfirming(false);
                                back();
                            }}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="composer-apply"
                            disabled={busy}
                            onClick={async () => {
                                if (await onDelete()) {
                                    setConfirming(false);
                                }
                            }}
                        >
                            Delete
                        </button>
                    </span>
                </div>
            )}
        </div>
    );
}
