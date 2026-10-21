import { cn } from '@/lib/utils';
import { TravelChip } from './labels';
import { TravelIcon } from './travel-icon';

// KVB's notice wherever it shows (design session, 21 Oct 2026): beside the
// English brief, a quiet link to KVB's own page; with no brief, KVB's own
// words in German behind a chip that says so. No translation on screen.

const hostOf = (url: string): string => {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return 'KVB';
    }
};

/** "KVB's notice ↗": the German source, one tap from the brief. */
export function KvbNoticeLink({
    url,
    className,
}: {
    url: string;
    className?: string;
}) {
    return (
        <a
            className={cn('kvb-notice-link', className)}
            href={url}
            target="_blank"
            rel="noopener"
            aria-label={`KVB’s notice, in German, opens ${hostOf(url)}`}
        >
            KVB’s notice <span aria-hidden="true">↗</span>
        </a>
    );
}

/** In front of a notice shown in its source's own words. */
export function InGermanChip({
    fromKvb = true,
    className,
}: {
    fromKvb?: boolean;
    className?: string;
}) {
    return (
        <TravelChip
            kind="unsure"
            icon={<TravelIcon name="info" />}
            className={cn('kvb-german-chip', className)}
        >
            {fromKvb ? 'In German · from KVB' : 'In German'}
        </TravelChip>
    );
}
