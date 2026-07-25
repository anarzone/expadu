import { IconChevronRight, IconX } from '@tabler/icons-react';
import type { ReactNode } from 'react';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { ICON_STROKE } from '@/constants/icons';

export type ItemDetailKind = 'event' | 'place';

export function ItemDetailLayout({
    main,
    rail,
}: {
    main: ReactNode;
    rail: ReactNode;
}) {
    return (
        <div className="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[minmax(0,1.1fr)_minmax(340px,0.9fr)]">
            <article className="min-h-0 overflow-y-auto px-5 py-5 sm:px-7 sm:py-7 lg:px-8">
                {main}
            </article>
            <aside className="flex min-h-0 flex-col border-t border-border bg-secondary/35 lg:overflow-y-auto lg:border-t-0 lg:border-l">
                {rail}
            </aside>
        </div>
    );
}

function ItemDetailContent({
    kind,
    breadcrumb,
    title,
    onClose,
    children,
}: {
    kind: ItemDetailKind;
    breadcrumb: string;
    title: string;
    onClose: () => void;
    children: ReactNode;
}) {
    return (
        <div
            data-item-detail={kind}
            className="flex min-h-0 flex-1 flex-col bg-card text-foreground"
        >
            <header className="flex min-h-14 shrink-0 items-center justify-between gap-4 border-b border-border px-5 sm:px-6">
                <div
                    className="flex min-w-0 items-center gap-2 font-mono text-[11px] font-semibold tracking-[0.08em] text-text-3 uppercase"
                    aria-label={`${kind === 'event' ? 'Events' : 'Places'} breadcrumb`}
                >
                    <span>{kind === 'event' ? 'Events' : 'Places'}</span>
                    <IconChevronRight
                        size={13}
                        stroke={ICON_STROKE}
                        aria-hidden="true"
                    />
                    <strong className="truncate text-foreground">
                        {breadcrumb}
                    </strong>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label={`Close ${title}`}
                    className="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-[11px] border border-border bg-card text-text-2 transition-colors hover:border-primary hover:text-primary"
                >
                    <IconX size={19} stroke={ICON_STROKE} />
                </button>
            </header>
            {children}
        </div>
    );
}

/**
 * Shared shell for decision-quality place and event details.
 *
 * Desktop is the approved two-column workspace. Mobile uses the existing
 * draggable sheet and stacks the planning rail after the explanatory content.
 */
export function ItemDetailShell({
    kind,
    isMobile,
    breadcrumb,
    title,
    onClose,
    children,
}: {
    kind: ItemDetailKind;
    isMobile: boolean;
    breadcrumb: string;
    title: string;
    onClose: () => void;
    children: ReactNode;
}) {
    const content = (
        <ItemDetailContent
            kind={kind}
            breadcrumb={breadcrumb}
            title={title}
            onClose={onClose}
        >
            {children}
        </ItemDetailContent>
    );

    if (isMobile) {
        return (
            <BottomSheet open onClose={onClose}>
                <div className="-mx-5 -mt-1 flex min-h-full flex-col pb-20">
                    {content}
                </div>
            </BottomSheet>
        );
    }

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent
                aria-describedby={undefined}
                showClose={false}
                className="h-[min(790px,calc(100dvh-2rem))] w-[calc(100vw-2rem)] max-w-[1100px] gap-0 overflow-hidden rounded-[24px] border-border bg-card p-0 shadow-[0_24px_90px_rgba(33,29,21,0.24)] sm:max-w-[1100px]"
            >
                <DialogTitle className="sr-only">{title}</DialogTitle>
                {content}
            </DialogContent>
        </Dialog>
    );
}
