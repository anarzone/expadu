import { router } from '@inertiajs/react';
import {
    IconBuildingBank,
    IconBus,
    IconCalendarEvent,
    IconChecklist,
    IconCloudRain,
    IconConfetti,
    IconMapPin,
    IconMessages,
    IconRipple,
} from '@tabler/icons-react';
import type { IconProps } from '@tabler/icons-react';
import React from 'react';
import type { ComponentType } from 'react';
import { AlertActionsMenu } from '@/components/alerts/alert-actions-menu';
import { ICON_STROKE } from '@/constants/icons';
import { useTracker } from '@/hooks/use-tracker';

export type AlertData = {
    id: number;
    type: string;
    subtype?: string | null;
    title: string;
    body: string | null;
    deep_link: string | null;
    read_at: string | null;
    dismissed_at: string | null;
    created_at: string;
};

type VisualConfig = {
    Icon: ComponentType<IconProps>;
    bg: string;
    tag: string;
    tagBg: string;
    tagColor: string;
    cta: string;
    ctaBg: string;
    ctaColor: string;
};

// Match visual config based on alert content (deep_link + title keywords)
function matchConfig(alert: AlertData): VisualConfig {
    const t = alert.title?.toLowerCase() ?? '';
    const link = alert.deep_link ?? '';

    // System alerts — match by content
    if (
        t.includes('disruption') ||
        t.includes('delay') ||
        t.includes('running') ||
        t.includes('line ')
    ) {
        return systemConfigs.transit;
    }

    if (
        t.includes('bürgeramt') ||
        t.includes('appointment') ||
        t.includes('slot')
    ) {
        return systemConfigs.buergeramt;
    }

    if (
        t.includes('rhine') ||
        t.includes('water level') ||
        t.includes('flood')
    ) {
        return systemConfigs.rhine;
    }

    if (
        t.includes('rain') ||
        t.includes('weather') ||
        t.includes('wind') ||
        t.includes('temperature') ||
        t.includes('freezing') ||
        t.includes('heat')
    ) {
        return systemConfigs.weather;
    }

    // Social alerts
    if (
        t.includes('partner') ||
        t.includes('connection') ||
        t.includes('accepted') ||
        link === '/language-exchange'
    ) {
        return socialConfigs.language;
    }

    if (
        t.includes('joined') ||
        t.includes('mixer') ||
        t.includes('event') ||
        link === '/events'
    ) {
        return socialConfigs.event;
    }

    if (t.includes('tip') || t.includes('area') || link === '/explore') {
        return socialConfigs.tip;
    }

    // Reminder alerts
    if (
        t.includes('tomorrow') ||
        t.includes('reminder') ||
        t.includes('event')
    ) {
        return reminderConfigs.event;
    }

    if (
        t.includes('bank') ||
        t.includes('steuer') ||
        t.includes('bureaucracy') ||
        link === '/bureaucracy'
    ) {
        return reminderConfigs.bureaucracy;
    }

    // Fallback by type
    if (alert.type === 'social') {
        return socialConfigs.language;
    }

    if (alert.type === 'reminder') {
        return reminderConfigs.event;
    }

    return systemConfigs.transit;
}

const systemConfigs: Record<string, VisualConfig> = {
    transit: {
        Icon: IconBus,
        bg: 'bg-danger-soft',
        tag: 'Transit',
        tagBg: 'bg-danger-soft',
        tagColor: 'text-danger',
        cta: 'Check live status',
        ctaBg: 'bg-warn-soft',
        ctaColor: 'text-warn',
    },
    buergeramt: {
        Icon: IconBuildingBank,
        bg: 'bg-success-soft',
        tag: 'Bürgeramt',
        tagBg: 'bg-success-soft',
        tagColor: 'text-success',
        cta: 'Book now',
        ctaBg: 'bg-success dark:bg-success',
        ctaColor: 'text-white',
    },
    rhine: {
        Icon: IconRipple,
        bg: 'bg-accent-soft',
        tag: 'Rhine',
        tagBg: 'bg-accent-soft',
        tagColor: 'text-primary',
        cta: 'View Rhine level',
        ctaBg: 'bg-accent-soft',
        ctaColor: 'text-primary',
    },
    weather: {
        Icon: IconCloudRain,
        bg: 'bg-surface-2',
        tag: 'Weather',
        tagBg: 'bg-surface-2',
        tagColor: 'text-muted-foreground',
        cta: 'Plan my journey',
        ctaBg: 'bg-accent-soft',
        ctaColor: 'text-primary',
    },
};

const socialConfigs: Record<string, VisualConfig> = {
    language: {
        Icon: IconMessages,
        bg: 'bg-accent-soft',
        tag: 'Language',
        tagBg: 'bg-accent-soft',
        tagColor: 'text-primary',
        cta: 'View profile',
        ctaBg: 'bg-primary',
        ctaColor: 'text-primary-foreground',
    },
    event: {
        Icon: IconConfetti,
        bg: 'bg-purple-100 dark:bg-purple-900',
        tag: 'Events',
        tagBg: 'bg-purple-100 dark:bg-purple-900',
        tagColor: 'text-purple-700 dark:text-purple-300',
        cta: 'View event',
        ctaBg: 'bg-purple-100 dark:bg-purple-900',
        ctaColor: 'text-purple-700 dark:text-purple-300',
    },
    tip: {
        Icon: IconMapPin,
        bg: 'bg-accent-soft',
        tag: 'Explore',
        tagBg: 'bg-accent-soft',
        tagColor: 'text-primary',
        cta: 'Explore',
        ctaBg: 'bg-accent-soft',
        ctaColor: 'text-primary',
    },
};

const reminderConfigs: Record<string, VisualConfig> = {
    event: {
        Icon: IconCalendarEvent,
        bg: 'bg-warn-soft',
        tag: 'Reminder',
        tagBg: 'bg-warn-soft',
        tagColor: 'text-warn',
        cta: 'View event',
        ctaBg: 'bg-warn-soft',
        ctaColor: 'text-warn',
    },
    bureaucracy: {
        Icon: IconChecklist,
        bg: 'bg-surface-2',
        tag: 'Bureaucracy',
        tagBg: 'bg-surface-2',
        tagColor: 'text-muted-foreground',
        cta: 'Get started',
        ctaBg: 'bg-accent-soft',
        ctaColor: 'text-primary',
    },
};

function timeAgo(dateStr: string): string {
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);

    if (mins < 1) {
        return 'Just now';
    }

    if (mins < 60) {
        return `${mins} min ago`;
    }

    const hours = Math.floor(mins / 60);

    if (hours < 24) {
        return `${hours} hour${hours > 1 ? 's' : ''} ago`;
    }

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

/** Lightweight title→subtype fallback for alerts without an explicit subtype column. */
function detectSubtypeFromTitle(title: string): string {
    const t = title.toLowerCase();

    if (t.includes('disruption')) {
        return 'transit_disruption';
    }

    if (t.includes('delay') || t.includes('running')) {
        return 'transit_delay';
    }

    if (t.includes('rain') || t.includes('weather') || t.includes('wind')) {
        return 'weather';
    }

    if (t.includes('rhine') || t.includes('water level')) {
        return 'rhine';
    }

    if (
        t.includes('bürgeramt') ||
        t.includes('appointment') ||
        t.includes('slot')
    ) {
        return 'buergeramt';
    }

    return 'generic';
}

export function AlertRow({
    alert,
    onMarkRead,
    onDismiss,
}: {
    alert: AlertData;
    onMarkRead?: (id: number) => void;
    onDismiss?: (id: number) => void;
}) {
    const { track } = useTracker();
    const isUnread = !alert.read_at;
    const config = matchConfig(alert);
    const ConfigIcon = config.Icon;
    const hasAction =
        alert.deep_link &&
        alert.deep_link !== '/alerts' &&
        alert.deep_link !== '/dashboard';

    function markRead(e?: React.MouseEvent) {
        e?.stopPropagation();

        if (isUnread) {
            track('alert_read', { alert_id: alert.id });
            onMarkRead?.(alert.id);
        }
    }

    function handleCtaClick(e: React.MouseEvent) {
        e.stopPropagation();
        markRead();

        if (alert.deep_link) {
            router.visit(alert.deep_link);
        }
    }

    const subtypeForMenu =
        alert.subtype ?? detectSubtypeFromTitle(alert.title ?? '');

    const content = (
        <div
            onClick={markRead}
            className={`group relative flex items-start gap-[13px] border-b border-border px-6 py-3.5 transition-colors hover:bg-secondary/50 ${
                isUnread ? 'bg-primary/[0.03]' : ''
            }`}
        >
            {/* Unread left bar */}
            {isUnread && (
                <div className="absolute top-0 bottom-0 left-0 w-[3px] bg-primary" />
            )}

            {/* Top-right overflow menu — mute + thumbs-down (Roadmap #6/#7) */}
            <div className="absolute top-2 right-2 z-10">
                <AlertActionsMenu alertId={alert.id} subtype={subtypeForMenu} />
            </div>

            {/* Icon bubble — 42x42 circle */}
            <div
                className={`flex size-[42px] shrink-0 items-center justify-center rounded-full ${config.bg} ${config.tagColor}`}
            >
                <ConfigIcon size={20} stroke={ICON_STROKE} />
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
                {hasAction && (
                    <button
                        onClick={handleCtaClick}
                        className={`rounded-lg px-[11px] py-[5px] text-[11px] font-bold whitespace-nowrap ${config.ctaBg} ${config.ctaColor}`}
                    >
                        {config.cta}
                    </button>
                )}
                {onDismiss && (
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            onDismiss(alert.id);
                        }}
                        className="text-[11px] text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
                    >
                        Dismiss
                    </button>
                )}
            </div>
        </div>
    );

    // No Link wrapper — clicking row only marks read,
    // CTA button handles navigation via router.visit

    return content;
}
