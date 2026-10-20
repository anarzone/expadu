import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';

/*
 * Honest states (the mock's guide: States). Every module has them; keep
 * the layout steady and never show a blank area or a guess. The wording
 * lives here so every page says the same thing: "Not listed", "Recorded
 * 22:19", "Scheduled · not live", "Platform not known".
 */

const BERLIN = 'Europe/Berlin';

const clock = (at: Date) =>
    new Intl.DateTimeFormat('en-GB', {
        timeZone: BERLIN,
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(at);

const day = (at: Date) =>
    new Intl.DateTimeFormat('en-GB', {
        timeZone: BERLIN,
        day: 'numeric',
        month: 'short',
    }).format(at);

const sameBerlinDay = (a: Date, b: Date) => day(a) === day(b);

/** "22:19" today, "7 Sept · 22:19" another day (Cologne time). */
export function whenRecorded(at: Date | string, now = new Date()): string {
    const date = typeof at === 'string' ? new Date(at) : at;

    return sameBerlinDay(date, now)
        ? clock(date)
        : `${day(date)} · ${clock(date)}`;
}

export type Source = 'live' | 'recorded' | 'scheduled';

/**
 * What a time or a departure is based on: "Live", "Recorded 22:19" or
 * "Scheduled · not live". A recorded value without its time says so.
 */
export function sourceLabel({
    source,
    at,
    now,
}: {
    source: Source;
    at?: Date | string | null;
    now?: Date;
}): string {
    if (source === 'live') {
        return 'Live';
    }

    if (source === 'recorded') {
        return at ? `Recorded ${whenRecorded(at, now)}` : 'Recorded';
    }

    return 'Scheduled · not live';
}

export const NOT_LISTED = 'Not listed';
export const PLATFORM_NOT_KNOWN = 'Platform not known';

/** A fact the source doesn't give: "Not listed", in the muted ink. */
export function NotListed({ children = NOT_LISTED }: { children?: string }) {
    return <span className="ui-not-listed">{children}</span>;
}

type NoticeProps = {
    /**
     * empty: nothing here yet, with the next step · error: it failed, with
     * a retry · unavailable: the source can't be reached right now · stale:
     * shown from a recording, with its time · not-listed: the source
     * doesn't say.
     */
    kind: 'empty' | 'error' | 'unavailable' | 'stale' | 'not-listed';
    title?: ReactNode;
    children?: ReactNode;
    /** When the shown data was recorded (stale). */
    at?: Date | string | null;
    /** Offers "Try again" (error, unavailable). */
    onRetry?: () => void;
    /** The next step (empty): a label, an icon and what it does. */
    action?: {
        label: string;
        icon?: IconName;
        onClick?: () => void;
        href?: string;
    };
};

const defaultTitles: Record<NoticeProps['kind'], string> = {
    empty: 'Nothing here yet',
    error: 'This could not be loaded.',
    unavailable: 'Not available right now',
    stale: 'Not live',
    'not-listed': NOT_LISTED,
};

/** One module's state, sized like the content it stands in for. */
export function StateNotice({
    kind,
    title,
    children,
    at,
    onRetry,
    action,
}: NoticeProps) {
    const retry = onRetry && (kind === 'error' || kind === 'unavailable');

    return (
        <div
            className="ui-notice"
            role={kind === 'error' ? 'alert' : undefined}
            data-state={kind}
        >
            <strong>{title ?? defaultTitles[kind]}</strong>
            {children && <p>{children}</p>}
            {kind === 'stale' && at && (
                <span className="ui-notice-when">
                    {sourceLabel({ source: 'recorded', at })} · Not live
                </span>
            )}
            {retry && (
                <button
                    type="button"
                    className="ui-notice-action"
                    onClick={onRetry}
                >
                    Try again
                </button>
            )}
            {action &&
                (action.href ? (
                    <Link className="ui-notice-action" href={action.href}>
                        {action.icon && (
                            <Icon name={action.icon} variant="line" />
                        )}
                        {action.label}
                    </Link>
                ) : (
                    <button
                        type="button"
                        className="ui-notice-action"
                        onClick={action.onClick}
                    >
                        {action.icon && (
                            <Icon name={action.icon} variant="line" />
                        )}
                        {action.label}
                    </button>
                ))}
        </div>
    );
}
