import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';

/*
 * Composer's detail dialog (prototype/dev/design/composer-studio/review-features.js
 * `shell()`): a modal with its title and a close button, a bottom sheet on a phone whose
 * header drags down to close. It closes on Escape, on the backdrop and on its close
 * button, and focuses `initialFocus` (a selector inside it) as it opens.
 */
export function DetailDialog({
    title,
    initialFocus,
    onClose,
    children,
}: {
    title: string;
    initialFocus?: string;
    onClose: () => void;
    children: ReactNode;
}) {
    const dialog = useRef<HTMLDialogElement>(null);
    const drag = useRef<{
        id: number;
        y: number;
        at: number;
        dy: number;
    } | null>(null);

    useEffect(() => {
        const element = dialog.current;
        element?.showModal();
        element
            ?.querySelector<HTMLElement>(initialFocus ?? '[data-detail-close]')
            ?.focus({ preventScroll: true });

        return () => element?.close();
    }, [initialFocus]);

    const phone = () => window.matchMedia('(max-width: 700px)').matches;
    const release = (event: React.PointerEvent) => {
        if (drag.current?.id !== event.pointerId) {
            return;
        }

        const { dy, at } = drag.current;
        drag.current = null;
        dialog.current?.style.removeProperty('transform');

        if (
            dy > 96 ||
            (dy > 32 && dy / Math.max(1, event.timeStamp - at) > 0.5)
        ) {
            onClose();
        }
    };

    return (
        <dialog
            ref={dialog}
            className="studio-detail-dialog"
            aria-label={title}
            onCancel={(event) => {
                event.preventDefault();
                onClose();
            }}
            onClick={(event) => {
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
            onKeyDown={(event) => {
                // The panel behind closes on Escape too; this dialog goes first.
                event.stopPropagation();

                if (event.key === 'Escape') {
                    event.preventDefault();
                    onClose();
                }
            }}
        >
            <header
                onPointerDown={(event) => {
                    if (
                        !phone() ||
                        (event.target as Element).closest('button')
                    ) {
                        return;
                    }

                    drag.current = {
                        id: event.pointerId,
                        y: event.clientY,
                        at: event.timeStamp,
                        dy: 0,
                    };
                    event.currentTarget.setPointerCapture(event.pointerId);
                }}
                onPointerMove={(event) => {
                    if (drag.current?.id !== event.pointerId) {
                        return;
                    }

                    drag.current.dy = Math.max(
                        0,
                        event.clientY - drag.current.y,
                    );
                    dialog.current?.style.setProperty(
                        'transform',
                        `translateY(${drag.current.dy}px)`,
                    );
                }}
                onPointerUp={release}
                onPointerCancel={release}
            >
                <h2>{title}</h2>
                <button
                    type="button"
                    data-detail-close
                    aria-label="Close"
                    onClick={onClose}
                >
                    <Icon name="close" variant="line" />
                </button>
            </header>
            <div className="detail-body">{children}</div>
        </dialog>
    );
}
