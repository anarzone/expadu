import { Link, router } from '@inertiajs/react';
import { useTracker } from '@/hooks/use-tracker';

type AlertData = {
    id: number;
    type: string;
    title: string;
    body: string | null;
    deep_link: string | null;
    read_at: string | null;
    created_at: string;
};

// Per-type visual config matching the prototype exactly
const typeConfigs: Record<
    string,
    {
        emoji: string;
        bg: string;
        tag: string;
        tagBg: string;
        tagColor: string;
        cta: string;
        ctaBg: string;
        ctaColor: string;
    }[]
> = {
    system: [
        {
            emoji: '🚇',
            bg: 'bg-danger-soft',
            tag: 'Transit',
            tagBg: 'bg-danger-soft',
            tagColor: 'text-danger',
            cta: 'See alternatives',
            ctaBg: 'bg-accent-soft',
            ctaColor: 'text-primary',
        },
        {
            emoji: '🏛️',
            bg: 'bg-success-soft',
            tag: 'Bürgeramt',
            tagBg: 'bg-success-soft',
            tagColor: 'text-success',
            cta: 'Book now',
            ctaBg: 'bg-success dark:bg-success',
            ctaColor: 'text-white',
        },
        {
            emoji: '🌊',
            bg: 'bg-accent-soft',
            tag: 'Rhine',
            tagBg: 'bg-accent-soft',
            tagColor: 'text-primary',
            cta: 'View Rhine level',
            ctaBg: 'bg-accent-soft',
            ctaColor: 'text-primary',
        },
        {
            emoji: '🌦️',
            bg: 'bg-surface-2',
            tag: 'Weather',
            tagBg: 'bg-surface-2',
            tagColor: 'text-muted-foreground',
            cta: 'Plan my journey',
            ctaBg: 'bg-accent-soft',
            ctaColor: 'text-primary',
        },
        {
            emoji: '🚇',
            bg: 'bg-warn-soft',
            tag: 'Transit',
            tagBg: 'bg-warn-soft',
            tagColor: 'text-warn',
            cta: 'Check live status',
            ctaBg: 'bg-warn-soft',
            ctaColor: 'text-warn',
        },
    ],
    social: [
        {
            emoji: '🇬🇧',
            bg: 'bg-accent-soft',
            tag: 'Language',
            tagBg: 'bg-accent-soft',
            tagColor: 'text-primary',
            cta: 'Send message',
            ctaBg: 'bg-primary',
            ctaColor: 'text-primary-foreground',
        },
        {
            emoji: '🗣️',
            bg: 'bg-purple-100 dark:bg-purple-900',
            tag: 'Language',
            tagBg: 'bg-purple-100 dark:bg-purple-900',
            tagColor: 'text-purple-700 dark:text-purple-300',
            cta: 'View profile',
            ctaBg: 'bg-accent-soft',
            ctaColor: 'text-primary',
        },
        {
            emoji: '🎉',
            bg: 'bg-success-soft',
            tag: 'Event',
            tagBg: 'bg-success-soft',
            tagColor: 'text-success',
            cta: 'View event',
            ctaBg: 'bg-success-soft',
            ctaColor: 'text-success',
        },
        {
            emoji: '💬',
            bg: 'bg-surface-2',
            tag: 'Community',
            tagBg: 'bg-surface-2',
            tagColor: 'text-muted-foreground',
            cta: 'See on map',
            ctaBg: 'bg-surface-2',
            ctaColor: 'text-muted-foreground',
        },
    ],
    reminder: [
        {
            emoji: '📅',
            bg: 'bg-warn-soft',
            tag: 'Event',
            tagBg: 'bg-warn-soft',
            tagColor: 'text-warn',
            cta: 'Get directions',
            ctaBg: 'bg-primary',
            ctaColor: 'text-primary-foreground',
        },
        {
            emoji: '🏛️',
            bg: 'bg-danger-soft',
            tag: 'Bureaucracy',
            tagBg: 'bg-danger-soft',
            tagColor: 'text-danger',
            cta: 'Open N26 now',
            ctaBg: 'bg-primary',
            ctaColor: 'text-primary-foreground',
        },
        {
            emoji: '📬',
            bg: 'bg-surface-2',
            tag: 'Tax ID',
            tagBg: 'bg-surface-2',
            tagColor: 'text-muted-foreground',
            cta: 'Request a copy',
            ctaBg: 'bg-surface-2',
            ctaColor: 'text-muted-foreground',
        },
    ],
};

function timeAgo(dateStr: string): string {
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins} min ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours} hour${hours > 1 ? 's' : ''} ago`;
    const days = Math.floor(hours / 24);
    if (days === 1) {
        return (
            'Yesterday ' +
            new Date(dateStr).toLocaleTimeString('en-GB', {
                hour: '2-digit',
                minute: '2-digit',
            })
        );
    }
    return `${days} days ago`;
}

export function AlertRow({
    alert,
    indexInType,
}: {
    alert: AlertData;
    indexInType: number;
}) {
    const { track } = useTracker();
    const isUnread = !alert.read_at;
    const configs = typeConfigs[alert.type] ?? typeConfigs.system;
    const config = configs[indexInType % configs.length];

    function markRead(e?: React.MouseEvent) {
        e?.stopPropagation();
        if (isUnread) {
            track('alert_read', { alert_id: alert.id });
            router.post(
                `/alerts/${alert.id}/read`,
                {},
                { preserveScroll: true },
            );
        }
    }

    function handleCtaClick(e: React.MouseEvent) {
        e.stopPropagation();
        markRead();
        if (alert.deep_link) {
            router.visit(alert.deep_link);
        }
    }

    const content = (
        <div
            onClick={markRead}
            className={`relative flex items-start gap-[13px] border-b border-border px-6 py-3.5 transition-colors hover:bg-secondary/50 ${
                isUnread ? 'bg-primary/[0.03]' : ''
            }`}
        >
            {/* Unread left bar */}
            {isUnread && (
                <div className="absolute top-0 bottom-0 left-0 w-[3px] bg-primary" />
            )}

            {/* Icon bubble — 42x42 circle */}
            <div
                className={`flex size-[42px] shrink-0 items-center justify-center rounded-full text-lg ${config.bg}`}
            >
                {config.emoji}
            </div>

            {/* Body */}
            <div className="min-w-0 flex-1">
                <div
                    className={`text-sm leading-[1.3] ${isUnread ? 'font-bold' : 'font-semibold'}`}
                >
                    {alert.title}
                </div>
                {alert.body && (
                    <div className="mt-0.5 text-[13px] leading-[1.5] text-muted-foreground">
                        {alert.body}
                    </div>
                )}
                <div className="mt-[5px] flex flex-wrap items-center gap-2">
                    <span className="font-mono text-[11px] text-muted-foreground">
                        {timeAgo(alert.created_at)}
                    </span>
                    <span
                        className={`rounded-full px-[7px] py-px text-[10px] font-bold tracking-[0.04em] uppercase ${config.tagBg} ${config.tagColor}`}
                    >
                        {config.tag}
                    </span>
                </div>
            </div>

            {/* Action column */}
            <div className="flex shrink-0 flex-col items-end gap-1.5">
                {isUnread && (
                    <div className="size-2 shrink-0 rounded-full bg-primary" />
                )}
                <button
                    onClick={handleCtaClick}
                    className={`rounded-lg px-[11px] py-[5px] text-[11px] font-bold whitespace-nowrap ${config.ctaBg} ${config.ctaColor}`}
                >
                    {config.cta}
                </button>
            </div>
        </div>
    );

    if (alert.deep_link) {
        return (
            <Link href={alert.deep_link} onClick={markRead} className="block">
                {content}
            </Link>
        );
    }

    return content;
}
