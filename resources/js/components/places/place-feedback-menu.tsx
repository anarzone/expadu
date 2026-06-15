import {
    IconDots,
    IconSparkles,
    IconBookmark,
    IconBookmarkFilled,
    IconMapPinCheck,
    IconEyeOff,
} from '@tabler/icons-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ICON_STROKE } from '@/constants/icons';
import type { FeedbackAction, FeedbackState } from '@/hooks/use-feedback';

/**
 * The social "⋯" feedback menu shared by every place card (home rails +
 * Places page). Leads with "More like this" — a forward-looking signal that
 * needs no visit — then Save / Been / Not interested. The page wires onAction
 * to useFeedback; this component is purely presentational.
 */
export function PlaceFeedbackMenu({
    state,
    onAction,
    variant = 'inline',
    label,
}: {
    state: FeedbackState | null;
    onAction: (action: FeedbackAction) => void;
    /** overlay = light-on-dark button for image corners; inline = bordered. */
    variant?: 'inline' | 'overlay';
    label?: string;
}) {
    const saved = state === 'saved';
    const been = state === 'been';

    const trigger =
        variant === 'overlay'
            ? 'flex size-[26px] items-center justify-center rounded-full bg-black/45 text-white shadow-sm backdrop-blur-sm transition-colors hover:bg-black/65'
            : 'flex size-8 items-center justify-center rounded-full border border-border bg-card text-muted-foreground transition-colors hover:border-primary hover:text-primary';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                aria-label={label ? `Feedback for ${label}` : 'Place feedback'}
                onClick={(e) => e.stopPropagation()}
                className={trigger}
            >
                <IconDots size={16} stroke={ICON_STROKE} />
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align={variant === 'overlay' ? 'start' : 'end'}
                onClick={(e) => e.stopPropagation()}
                className="w-52"
            >
                <DropdownMenuItem onSelect={() => onAction('more_like_this')}>
                    <IconSparkles stroke={ICON_STROKE} />
                    More like this
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() => onAction(saved ? 'clear' : 'saved')}
                >
                    {saved ? (
                        <IconBookmarkFilled />
                    ) : (
                        <IconBookmark stroke={ICON_STROKE} />
                    )}
                    {saved ? 'Saved' : 'Save for later'}
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() => onAction(been ? 'clear' : 'been')}
                >
                    <IconMapPinCheck stroke={ICON_STROKE} />
                    {been ? 'Visited' : 'Been here'}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    className="text-danger focus:text-danger"
                    onSelect={() => onAction('not_interested')}
                >
                    <IconEyeOff stroke={ICON_STROKE} />
                    Not interested
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * A small standing-state badge for saved / visited places. "more_like_this"
 * and "not_interested" carry no badge (the latter removes the card entirely).
 */
export function FeedbackBadge({
    state,
    variant = 'inline',
}: {
    state: FeedbackState | null;
    variant?: 'inline' | 'corner';
}) {
    if (state !== 'saved' && state !== 'been') {
        return null;
    }

    const tone =
        state === 'saved'
            ? 'bg-accent-soft text-primary'
            : 'bg-success-soft text-success';
    const position =
        variant === 'corner' ? 'absolute top-2 left-2 z-[1] shadow-sm' : '';

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ${tone} ${position}`}
        >
            {state === 'saved' ? (
                <IconBookmarkFilled size={12} />
            ) : (
                <IconMapPinCheck size={12} stroke={ICON_STROKE} />
            )}
            {state === 'saved' ? 'Saved' : 'Visited'}
        </span>
    );
}

/** Brief bottom-centre confirmation toast for a feedback action. */
export function FeedbackToast({ message }: { message: string | null }) {
    if (!message) {
        return null;
    }

    return (
        <div
            role="status"
            className="fixed inset-x-0 bottom-24 z-50 flex justify-center px-4 md:bottom-8"
        >
            <div className="rounded-full bg-foreground px-4 py-2.5 text-[13px] font-medium text-background shadow-lg">
                {message}
            </div>
        </div>
    );
}
