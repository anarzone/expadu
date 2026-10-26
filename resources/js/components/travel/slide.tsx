import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import '../../../css/travel.css';

/**
 * Travel's one way to open and close a part in place: Slide, as the
 * departure rows open (owner, 23 Oct 2026: "Use the same one which we
 * decided instead (slide animation)", everywhere on Travel). Its content
 * stays mounted while closed (hidden from assistive tech and the keyboard)
 * so closing folds it away rather than cutting it.
 */
export function Slide({
    open,
    id,
    className,
    innerClassName,
    children,
}: {
    open: boolean;
    id?: string;
    className?: string;
    innerClassName?: string;
    children: ReactNode;
}) {
    return (
        <div
            id={id}
            className={cn('tl-slide', className)}
            data-open={open}
            aria-hidden={!open}
            inert={!open}
        >
            <div className={cn('tl-slide-in', innerClassName)}>{children}</div>
        </div>
    );
}
