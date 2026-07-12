import { Head, router, usePage } from '@inertiajs/react';
import {
    IconArrowRight,
    IconBuildingCommunity,
    IconBus,
    IconCalendarEvent,
    IconCheck,
    IconChecks,
    IconChevronDown,
    IconChevronUp,
    IconFileText,
    IconSettings,
    IconX,
} from '@tabler/icons-react';
import type { ComponentType, ReactNode } from 'react';
import { useMemo, useRef, useState } from 'react';
import { ICON_STROKE } from '@/constants/icons';
import AppLayout from '@/layouts/app-layout';
import { edit as editNotifications } from '@/routes/notifications';

type Alert = {
    id: number;
    category: 'transit' | 'bureau' | 'events' | 'city';
    lane: 'action' | 'good' | 'posted';
    severity: 'danger' | 'warn' | 'info' | 'success';
    source: string;
    action_label: string | null;
    title: string;
    body: string;
    deep_link: string | null;
    read: boolean;
    created_at: string;
    occurrences: number;
};

/** Severity → left border + icon-tile tint (the design's sev-* classes). */
const SEVERITY: Record<Alert['severity'], { border: string; icon: string }> = {
    danger: { border: 'border-l-danger', icon: 'bg-danger-soft text-danger' },
    warn: { border: 'border-l-warn', icon: 'bg-warn-soft text-warn' },
    info: { border: 'border-l-navy', icon: 'bg-navy-soft text-navy' },
    success: {
        border: 'border-l-success',
        icon: 'bg-success-soft text-success',
    },
};

const CATEGORY_ICON: Record<
    Alert['category'],
    ComponentType<{ size?: number; stroke?: number }>
> = {
    transit: IconBus,
    bureau: IconFileText,
    events: IconCalendarEvent,
    city: IconBuildingCommunity,
};

const CATEGORIES: { id: 'all' | Alert['category']; label: string }[] = [
    { id: 'all', label: 'All' },
    { id: 'transit', label: 'Transit' },
    { id: 'bureau', label: 'Bureaucracy' },
    { id: 'events', label: 'Events' },
    { id: 'city', label: 'City' },
];

/** Compact relative time — "25m ago" / "2h ago" / "3 Jul". */
function timeAgo(iso: string): string {
    const mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);

    if (mins < 1) {
        return 'just now';
    }

    if (mins < 60) {
        return `${mins}m ago`;
    }

    const hours = Math.floor(mins / 60);

    if (hours < 24) {
        return `${hours}h ago`;
    }

    const days = Math.floor(hours / 24);

    if (days === 1) {
        return 'yesterday';
    }

    return new Date(iso).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

function post(url: string): void {
    void fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
    })
        .then(() => router.reload({ only: ['unreadAlertCount'] }))
        .catch(() => {});
}

function AlertCard({
    alert,
    onRead,
    onAction,
    onDismiss,
}: {
    alert: Alert;
    onRead: (id: number) => void;
    onAction: (a: Alert) => void;
    onDismiss: (id: number) => void;
}) {
    const Icon = CATEGORY_ICON[alert.category];
    const sev = SEVERITY[alert.severity];

    return (
        <div
            onClick={() => !alert.read && onRead(alert.id)}
            className={`group relative mb-2.5 flex cursor-pointer gap-3.5 rounded-[14px] border border-l-[3px] border-border bg-card p-4 shadow-card transition-opacity ${sev.border} ${alert.read ? 'opacity-[0.62] shadow-none' : ''}`}
        >
            <span
                className={`flex size-[38px] shrink-0 items-center justify-center rounded-[11px] ${sev.icon}`}
            >
                <Icon size={19} stroke={ICON_STROKE} />
            </span>
            <div className="min-w-0 flex-1 pr-5">
                <div className="flex items-start gap-2">
                    <span className="text-[14.5px] leading-[1.3] font-semibold">
                        {alert.title}
                    </span>
                    {!alert.read && (
                        <span className="mt-1.5 size-[7px] shrink-0 rounded-full bg-primary" />
                    )}
                </div>
                {alert.body && (
                    <p className="mt-1.5 text-[13px] leading-[1.55] text-text-2">
                        {alert.body}
                    </p>
                )}
                <div className="mt-2.5 flex flex-wrap items-center gap-2">
                    <span className="font-mono text-[10px] tracking-[0.05em] text-text-3 uppercase">
                        {alert.source}
                    </span>
                    <span className="text-[11px] text-text-3">·</span>
                    <span className="font-mono text-[10.5px] text-text-3">
                        {timeAgo(alert.created_at)}
                    </span>
                    {alert.occurrences > 1 && (
                        <>
                            <span className="text-[11px] text-text-3">·</span>
                            <span className="font-mono text-[10.5px] text-text-3">
                                updated {alert.occurrences}×
                            </span>
                        </>
                    )}
                </div>
                {alert.action_label && alert.deep_link && (
                    <div className="mt-3">
                        <button
                            onClick={(e) => {
                                e.stopPropagation();
                                onAction(alert);
                            }}
                            className={`inline-flex items-center gap-1.5 rounded-full px-3.5 py-2 text-[12.5px] font-semibold transition-colors ${
                                alert.lane === 'action' &&
                                alert.severity === 'danger'
                                    ? 'bg-primary text-white shadow-[0_2px_9px_rgba(255,57,2,0.26)] hover:bg-primary/90'
                                    : 'border border-border bg-card text-foreground hover:border-primary hover:text-primary'
                            }`}
                        >
                            {alert.action_label}
                            <IconArrowRight size={14} stroke={ICON_STROKE} />
                        </button>
                    </div>
                )}
            </div>
            <button
                onClick={(e) => {
                    e.stopPropagation();
                    onDismiss(alert.id);
                }}
                aria-label="Dismiss"
                className="absolute top-2.5 right-2.5 flex size-[25px] items-center justify-center rounded-[7px] text-text-3 opacity-0 transition-opacity group-hover:opacity-100 hover:bg-surface-2"
            >
                <IconX size={15} stroke={ICON_STROKE} />
            </button>
        </div>
    );
}

function Lane({
    title,
    count,
    countTone,
    children,
}: {
    title: string;
    count?: number;
    countTone?: 'act' | 'good';
    children: ReactNode;
}) {
    return (
        <>
            <div className="mt-6 mb-3 flex items-center gap-2.5 font-mono text-[11px] font-medium tracking-[0.12em] text-text-3 uppercase">
                {title}
                {count !== undefined && (
                    <span
                        className={`rounded-full px-2 py-px text-[10.5px] font-bold ${
                            countTone === 'good'
                                ? 'bg-success-soft text-success'
                                : 'bg-danger-soft text-danger'
                        }`}
                    >
                        {count}
                    </span>
                )}
            </div>
            {children}
        </>
    );
}

export default function Alerts() {
    const { alerts: serverAlerts, unreadCount: serverUnread } = usePage<{
        alerts: Alert[];
        unreadCount: number;
    }>().props;

    const [cat, setCat] = useState<'all' | Alert['category']>('all');
    const [readIds, setReadIds] = useState<Set<number>>(new Set());
    const [dismissedIds, setDismissedIds] = useState<Set<number>>(new Set());
    const [showRead, setShowRead] = useState(false);
    const [toast, setToast] = useState<string | null>(null);
    const toastTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    function flash(msg: string) {
        if (toastTimer.current) {
            clearTimeout(toastTimer.current);
        }

        setToast(msg);
        toastTimer.current = setTimeout(() => setToast(null), 2000);
    }

    const isRead = (a: Alert) => a.read || readIds.has(a.id);

    const {
        laneAction,
        laneGood,
        lanePosted,
        readList,
        catCounts,
        unreadCount,
        summary,
    } = useMemo(() => {
        const live = serverAlerts.filter((a) => !dismissedIds.has(a.id));
        const inCat = (a: Alert) => cat === 'all' || a.category === cat;
        const unread = live.filter((a) => !isRead(a) && inCat(a));

        const catCounts: Record<string, number> = { all: 0 };

        for (const a of live.filter((x) => !isRead(x))) {
            catCounts.all = (catCounts.all ?? 0) + 1;
            catCounts[a.category] = (catCounts[a.category] ?? 0) + 1;
        }

        const laneAction = unread.filter((a) => a.lane === 'action');
        const laneGood = unread.filter((a) => a.lane === 'good');
        const lanePosted = unread.filter((a) => a.lane === 'posted');
        const know = laneGood.length + lanePosted.length;

        let summary: string;

        if (unread.length === 0) {
            summary =
                cat === 'all'
                    ? 'No unread alerts'
                    : 'Nothing unread in this filter';
        } else if (laneAction.length > 0) {
            summary = `${laneAction.length} ${laneAction.length === 1 ? 'needs' : 'need'} action · ${know} to know`;
        } else {
            summary = `${unread.length} ${unread.length === 1 ? 'alert' : 'alerts'} to know`;
        }

        return {
            laneAction,
            laneGood,
            lanePosted,
            readList: live.filter((a) => isRead(a) && inCat(a)),
            catCounts,
            unreadCount: live.filter((a) => !isRead(a)).length,
            summary,
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [serverAlerts, dismissedIds, readIds, cat]);

    function markRead(id: number) {
        setReadIds((s) => new Set(s).add(id));
        post(`/alerts/${id}/read`);
    }

    function dismiss(id: number) {
        setDismissedIds((s) => new Set(s).add(id));
        flash('Dismissed');
        post(`/alerts/${id}/dismiss`);
    }

    function act(a: Alert) {
        markRead(a.id);

        if (a.deep_link) {
            router.visit(a.deep_link);
        }
    }

    function markAll() {
        setReadIds(new Set(serverAlerts.map((a) => a.id)));
        flash('All marked read');
        post('/alerts/read-all');
    }

    const headerUnread = unreadCount || serverUnread;
    const allCaught =
        laneAction.length + laneGood.length + lanePosted.length === 0;

    return (
        <AppLayout breadcrumbs={[{ title: 'Alerts', href: '/alerts' }]}>
            <Head title="Alerts" />
            <div className="mx-auto w-full max-w-[680px] px-1 pb-16">
                {/* Header */}
                <div className="flex items-start justify-between gap-4 pt-1">
                    <div>
                        <h1 className="font-display text-[30px] leading-tight font-medium tracking-[-0.02em]">
                            Alerts
                        </h1>
                        <p className="mt-1.5 text-[13.5px] text-text-2">
                            {summary}
                        </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        {headerUnread > 0 && (
                            <button
                                onClick={markAll}
                                className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-3 py-2 text-[12.5px] font-semibold text-text-2 transition-colors hover:border-primary hover:text-primary"
                            >
                                <IconChecks size={14} stroke={ICON_STROKE} />
                                Mark all read
                            </button>
                        )}
                        <button
                            onClick={() =>
                                router.visit(editNotifications.url())
                            }
                            aria-label="Notification settings"
                            title="Notification settings"
                            className="flex size-9 items-center justify-center rounded-full border border-border bg-card text-text-2 transition-colors hover:border-primary hover:text-primary"
                        >
                            <IconSettings size={18} stroke={1.7} />
                        </button>
                    </div>
                </div>

                {/* Category filter */}
                <div className="mt-[18px] flex gap-2 overflow-x-auto pb-1.5">
                    {CATEGORIES.map((c) => {
                        const n = catCounts[c.id] ?? 0;
                        const on = cat === c.id;

                        return (
                            <button
                                key={c.id}
                                onClick={() => setCat(c.id)}
                                className={`flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-2 text-[13px] transition-colors ${
                                    on
                                        ? 'border-primary bg-primary-soft font-semibold text-primary'
                                        : 'border-border bg-card font-medium text-text-2 hover:border-primary'
                                }`}
                            >
                                {c.label}
                                {n > 0 && (
                                    <span className="font-mono text-[11px] opacity-70">
                                        {n}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>

                {allCaught ? (
                    <div className="px-4 pt-14 pb-8 text-center">
                        <div className="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-success-soft text-success">
                            <IconCheck size={26} stroke={ICON_STROKE} />
                        </div>
                        <div className="font-display text-[21px] font-medium">
                            You're all caught up
                        </div>
                        <div className="mx-auto mt-1.5 max-w-xs text-[13.5px] leading-relaxed text-text-2">
                            {cat === 'all'
                                ? 'Nothing needs you right now. We’ll ping you the moment something does.'
                                : 'Switch the filter to see other alerts.'}
                        </div>
                    </div>
                ) : (
                    <>
                        {laneAction.length > 0 && (
                            <Lane
                                title="Needs your action"
                                count={laneAction.length}
                                countTone="act"
                            >
                                {laneAction.map((a) => (
                                    <AlertCard
                                        key={a.id}
                                        alert={a}
                                        onRead={markRead}
                                        onAction={act}
                                        onDismiss={dismiss}
                                    />
                                ))}
                            </Lane>
                        )}
                        {laneGood.length > 0 && (
                            <Lane
                                title="Good news"
                                count={laneGood.length}
                                countTone="good"
                            >
                                {laneGood.map((a) => (
                                    <AlertCard
                                        key={a.id}
                                        alert={a}
                                        onRead={markRead}
                                        onAction={act}
                                        onDismiss={dismiss}
                                    />
                                ))}
                            </Lane>
                        )}
                        {lanePosted.length > 0 && (
                            <Lane title="Keeping you posted">
                                {lanePosted.map((a) => (
                                    <AlertCard
                                        key={a.id}
                                        alert={a}
                                        onRead={markRead}
                                        onAction={act}
                                        onDismiss={dismiss}
                                    />
                                ))}
                            </Lane>
                        )}
                    </>
                )}

                {/* Read — collapsed */}
                {readList.length > 0 && (
                    <>
                        <button
                            onClick={() => setShowRead((v) => !v)}
                            className="mt-4 mb-2.5 flex w-full items-center justify-between rounded-[14px] border border-dashed border-border px-4 py-3 text-[13px] font-semibold text-text-2"
                        >
                            <span>
                                {readList.length} read{' '}
                                {readList.length === 1 ? 'alert' : 'alerts'}
                            </span>
                            {showRead ? (
                                <IconChevronUp size={16} stroke={ICON_STROKE} />
                            ) : (
                                <IconChevronDown
                                    size={16}
                                    stroke={ICON_STROKE}
                                />
                            )}
                        </button>
                        {showRead &&
                            readList.map((a) => (
                                <AlertCard
                                    key={a.id}
                                    alert={a}
                                    onRead={markRead}
                                    onAction={act}
                                    onDismiss={dismiss}
                                />
                            ))}
                    </>
                )}
            </div>

            {toast && (
                <div className="fixed inset-x-0 bottom-24 z-[60] flex justify-center px-4 md:bottom-8">
                    <div className="rounded-full border border-transparent bg-foreground px-4 py-2.5 text-[13px] font-medium text-background shadow-lg dark:border-border dark:bg-secondary dark:text-foreground">
                        {toast}
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
