import { useEffect, useId, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent, ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

const PHONE = '(max-width: 700px)';
const still = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const phone = () => window.matchMedia(PHONE).matches;

type SheetProps = {
    open: boolean;
    /** Asked to close: the close button, Escape, the scrim, a drag down. */
    onClose: () => void;
    title: ReactNode;
    /** A line under the title. */
    description?: ReactNode;
    /** Icon buttons before the close (`ui-sheet-action`, 44px), e.g. Share. */
    actions?: ReactNode;
    /** Actions pinned under the content. */
    footer?: ReactNode;
    children: ReactNode;
    className?: string;
};

/**
 * Details and short tasks (the mock's sheets: event, place, journey, Add a
 * plan): a bottom sheet on phones that closes by dragging its top edge
 * down, a compact dialog over a light scrim from 701px. Always closable;
 * focus moves in and returns to what opened it; settle motion, none with
 * reduced motion. The page stays mounted and recognisable behind it.
 */
export function Sheet({
    open,
    onClose,
    title,
    description,
    actions,
    footer,
    children,
    className,
}: SheetProps) {
    const dialog = useRef<HTMLDialogElement>(null);
    const opener = useRef<Element | null>(null);
    const drag = useRef<{ y: number; id: number; at: number } | null>(null);
    const titleId = useId();
    const descriptionId = useId();
    // Still drawn while it slides away after `open` turns false.
    const [shown, setShown] = useState(open);
    const [wasOpen, setWasOpen] = useState(open);

    if (open !== wasOpen) {
        setWasOpen(open);

        if (open) {
            setShown(true);
        }
    }

    useEffect(() => {
        const box = dialog.current;

        if (!box) {
            return;
        }

        if (open) {
            if (box.open) {
                return;
            }

            opener.current = document.activeElement;
            box.showModal();

            if (!still()) {
                box.animate(
                    phone()
                        ? [
                              { transform: 'translateY(100%)' },
                              { transform: 'translateY(0)' },
                          ]
                        : [
                              {
                                  opacity: 0,
                                  transform: 'translateY(28px) scale(.99)',
                              },
                              { opacity: 1, transform: 'none' },
                          ],
                    {
                        duration: phone() ? 280 : 300,
                        easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
                    },
                );
            }

            return;
        }

        if (!box.open) {
            return;
        }

        const finish = () => {
            box.style.transform = '';
            box.close();
            setShown(false);
            const back = opener.current;

            if (back instanceof HTMLElement && back.isConnected) {
                back.focus({ preventScroll: true });
            }
        };

        if (still()) {
            finish();

            return;
        }

        // A sheet that was dragged down leaves from where it is.
        const from = box.style.transform || 'translateY(0)';
        const leaving = box.animate(
            phone()
                ? [{ transform: from }, { transform: 'translateY(100%)' }]
                : [
                      { opacity: 1, transform: 'none' },
                      { opacity: 0, transform: 'translateY(20px) scale(.99)' },
                  ],
            {
                duration: phone() ? 220 : 180,
                easing: 'cubic-bezier(.4,0,1,1)',
                fill: 'forwards',
            },
        );
        leaving.finished.then(
            () => {
                finish();
                leaving.cancel();
            },
            () => {},
        );
    }, [open, shown]);

    const onPointerDown = (event: ReactPointerEvent<HTMLElement>) => {
        if (!phone() || (event.target as Element).closest('button')) {
            return;
        }

        drag.current = {
            y: event.clientY,
            id: event.pointerId,
            at: performance.now(),
        };
        event.currentTarget.setPointerCapture(event.pointerId);
    };
    const onPointerMove = (event: ReactPointerEvent<HTMLElement>) => {
        const start = drag.current;

        if (!start || start.id !== event.pointerId || !dialog.current) {
            return;
        }

        dialog.current.style.transform = `translateY(${Math.max(0, event.clientY - start.y)}px)`;
    };
    const onPointerUp = (event: ReactPointerEvent<HTMLElement>) => {
        const start = drag.current;

        if (!start || start.id !== event.pointerId || !dialog.current) {
            return;
        }

        drag.current = null;
        const distance = event.clientY - start.y;
        const fast = distance / (performance.now() - start.at) > 0.6;

        if (distance > 80 || (fast && distance > 24)) {
            onClose();

            return;
        }

        dialog.current.style.transform = '';
    };

    if (!shown) {
        return null;
    }

    return (
        <dialog
            ref={dialog}
            className={cn('ui-sheet', className)}
            aria-labelledby={titleId}
            aria-describedby={description ? descriptionId : undefined}
            onCancel={(event) => {
                event.preventDefault();
                onClose();
            }}
            onClick={(event) => {
                // A tap on the scrim (the dialog's own box, outside its
                // content) closes it.
                if (event.target !== event.currentTarget) {
                    return;
                }

                const box = event.currentTarget.getBoundingClientRect();
                const outside =
                    event.clientX < box.left ||
                    event.clientX > box.right ||
                    event.clientY < box.top ||
                    event.clientY > box.bottom;

                if (outside) {
                    onClose();
                }
            }}
        >
            <header
                className="ui-sheet-head"
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerCancel={onPointerUp}
            >
                <div>
                    <h2 id={titleId}>{title}</h2>
                    {description && <p id={descriptionId}>{description}</p>}
                </div>
                {actions}
                <button
                    type="button"
                    className="ui-sheet-close"
                    aria-label="Close"
                    onClick={onClose}
                >
                    <Icon name="close" variant="line" />
                </button>
            </header>
            <div className="ui-sheet-body">{children}</div>
            {footer && <div className="ui-sheet-foot">{footer}</div>}
        </dialog>
    );
}
