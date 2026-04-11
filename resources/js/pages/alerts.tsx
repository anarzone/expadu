import { Head, router, usePage } from '@inertiajs/react';
import { IconSettings } from '@tabler/icons-react';
import { useCallback, useState } from 'react';
import { AlertRow } from '@/components/alerts/alert-row';
import type { AlertData } from '@/components/alerts/alert-row';
import { AlertStackRow } from '@/components/alerts/alert-stack';
import type { AlertStack } from '@/components/alerts/alert-stack';
import { AlertsRightPanel } from '@/components/alerts/alerts-right-panel';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import AppLayout from '@/layouts/app-layout';

// Subtypes that should never be stacked — each appears individually
const NEVER_STACK = new Set([
    'buergeramt',
    'event_reminder',
    'rhine',
    'reminder',
    'bureaucracy_reminder',
    'partner_match',
    'connection_accepted',
    'event_activity',
    'area_tip',
    'generic',
]);

/** Detect subtype from alert data — granular so only truly similar alerts stack */
function detectSubtype(alert: AlertData): string {
    if (alert.subtype) {
        return alert.subtype;
    }

    const t = alert.title?.toLowerCase() ?? '';

    // Transit
    if (t.includes('disruption')) {
        return 'transit_disruption';
    }

    if (t.includes('delay') || t.includes('running')) {
        return 'transit_delay';
    }

    // Weather
    if (t.includes('rain')) {
        return 'weather_rain';
    }

    if (t.includes('wind')) {
        return 'weather_wind';
    }

    if (
        t.includes('heat') ||
        t.includes('freezing') ||
        t.includes('temperature')
    ) {
        return 'weather_temp';
    }

    if (t.includes('weather')) {
        return 'weather';
    }

    // Infrastructure
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

    // Social — each is its own subtype (no grouping across different social actions)
    if (t.includes('partner') || t.includes('match')) {
        return 'partner_match';
    }

    if (t.includes('connection') || t.includes('accepted')) {
        return 'connection_accepted';
    }

    if (t.includes('joined')) {
        return 'event_activity';
    }

    if (t.includes('tip') || t.includes('area')) {
        return 'area_tip';
    }

    // Reminders
    if (t.includes('tomorrow')) {
        return 'event_reminder';
    }

    if (t.includes('reminder')) {
        return 'reminder';
    }

    if (t.includes('bank') || t.includes('steuer')) {
        return 'bureaucracy_reminder';
    }

    return 'generic';
}

const STACK_WINDOW_MS = 5 * 60 * 60 * 1000; // 5 hours

/** Stack alerts by subtype + 5-hour time window */
function stackAlerts(alerts: AlertData[]): (AlertData | AlertStack)[] {
    const result: (AlertData | AlertStack)[] = [];
    // Key: "subtype:windowStart" → alerts in that window
    const grouped = new Map<string, AlertData[]>();

    for (const alert of alerts) {
        const subtype = detectSubtype(alert);

        if (NEVER_STACK.has(subtype)) {
            result.push(alert);
            continue;
        }

        // Find which 5h window this alert belongs to
        const time = new Date(alert.created_at).getTime();
        let matched = false;

        for (const [key, group] of grouped) {
            if (!key.startsWith(subtype + ':')) {
                continue;
            }

            // Check if this alert is within 5h of the latest in this group
            const latestTime = new Date(group[0].created_at).getTime();

            if (Math.abs(time - latestTime) <= STACK_WINDOW_MS) {
                group.push(alert);
                matched = true;
                break;
            }
        }

        if (!matched) {
            const windowKey = `${subtype}:${time}`;
            grouped.set(windowKey, [alert]);
        }
    }

    for (const [key, group] of grouped) {
        const subtype = key.split(':')[0];

        if (group.length === 1) {
            result.push(group[0]);
        } else {
            result.push({
                stackType: 'stack',
                stackKey: key,
                subtype,
                latest: group[0],
                count: group.length,
                alerts: group,
            });
        }
    }

    // Sort: stacks by their latest alert time, mixed with individual alerts
    return result.sort((a, b) => {
        const aTime =
            'stackType' in a
                ? new Date(a.latest.created_at).getTime()
                : new Date(a.created_at).getTime();
        const bTime =
            'stackType' in b
                ? new Date(b.latest.created_at).getTime()
                : new Date(b.created_at).getTime();

        return bTime - aTime;
    });
}

function isStack(item: AlertData | AlertStack): item is AlertStack {
    return 'stackType' in item;
}

const tabs = [
    { id: 'all', label: 'All' },
    { id: 'system', label: 'System' },
    { id: 'social', label: 'Social' },
    { id: 'reminder', label: 'Reminders' },
];

function getTimeGroup(dateStr: string): string {
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);

    if (mins < 60) {
        return 'Just now';
    }

    const hours = Math.floor(mins / 60);

    if (hours < 24) {
        return 'Today';
    }

    const days = Math.floor(hours / 24);

    if (days === 1) {
        return 'Yesterday';
    }

    return 'Earlier';
}

function groupAlerts(alerts: AlertData[]): Record<string, AlertData[]> {
    const groups: Record<string, AlertData[]> = {};

    for (const alert of alerts) {
        const group = getTimeGroup(alert.created_at);

        if (!groups[group]) {
            groups[group] = [];
        }

        groups[group].push(alert);
    }

    return groups;
}

export default function Alerts() {
    const {
        alerts: serverAlerts,
        counts: serverCounts,
        tab: serverTab,
    } = usePage<{
        alerts: { data: AlertData[] };
        counts: {
            unread: number;
            system: number;
            social: number;
            reminder: number;
        };
        tab: string;
    }>().props;

    // Local state — no page refreshes
    const [alertList, setAlertList] = useState<AlertData[]>(serverAlerts.data);
    const [counts, setCounts] = useState(serverCounts);
    const [activeTab, setActiveTab] = useState(serverTab || 'all');
    const [settingsOpen, setSettingsOpen] = useState(false);

    const csrf =
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') || '';

    // The header badge + right panel read from `counts`, which is seeded from
    // the server (full DB totals, not the paginated list) and kept in sync
    // optimistically below.
    const unreadCount = counts.unread;

    // Refresh Inertia shared props so the sidebar / mobile dock badges stay in
    // sync with the alerts page after optimistic updates. Without this the raw
    // fetch() bypasses Inertia and the badge goes stale.
    const refreshSharedUnreadCount = useCallback(() => {
        router.reload({ only: ['unreadAlertCount'] });
    }, []);

    // Filter by active tab (local, no server request)
    const filteredAlerts =
        activeTab === 'all'
            ? alertList
            : alertList.filter((a) => a.type === activeTab);

    // Mark single alert read — local state + background fetch
    const markRead = useCallback(
        (alertId: number) => {
            const wasUnread = alertList.some(
                (a) => a.id === alertId && !a.read_at,
            );

            setAlertList((prev) =>
                prev.map((a) =>
                    a.id === alertId
                        ? {
                              ...a,
                              read_at: a.read_at ?? new Date().toISOString(),
                          }
                        : a,
                ),
            );

            if (wasUnread) {
                setCounts((prev) => ({
                    ...prev,
                    unread: Math.max(0, prev.unread - 1),
                }));
            }

            fetch(`/alerts/${alertId}/read`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
            })
                .then(refreshSharedUnreadCount)
                .catch(() => {});
        },
        [alertList, csrf, refreshSharedUnreadCount],
    );

    // Dismiss alert — remove from list + background fetch
    const dismissAlert = useCallback(
        (alertId: number) => {
            const alert = alertList.find((a) => a.id === alertId);
            const wasUnread = alert && !alert.read_at;

            setAlertList((prev) => prev.filter((a) => a.id !== alertId));

            if (wasUnread) {
                setCounts((prev) => ({
                    ...prev,
                    unread: Math.max(0, prev.unread - 1),
                }));
            }

            fetch(`/alerts/${alertId}/dismiss`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
            })
                .then(refreshSharedUnreadCount)
                .catch(() => {});
        },
        [alertList, csrf, refreshSharedUnreadCount],
    );

    // Mark all read — local state + background fetch
    function markAllRead() {
        setAlertList((prev) =>
            prev.map((a) => ({
                ...a,
                read_at: a.read_at ?? new Date().toISOString(),
            })),
        );
        setCounts((prev) => ({ ...prev, unread: 0 }));

        fetch('/alerts/read-all', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': csrf,
                Accept: 'application/json',
            },
        })
            .then(refreshSharedUnreadCount)
            .catch(() => {});
    }

    const grouped = groupAlerts(filteredAlerts);
    const groupOrder = ['Just now', 'Today', 'Yesterday', 'Earlier'];

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Alerts', href: '/alerts' }]}
            rightPanel={<AlertsRightPanel counts={counts} />}
        >
            <Head title="Alerts" />
            <div className="mx-auto w-full max-w-[680px]">
                {/* Sticky header */}
                <div className="sticky top-0 z-50 flex items-center justify-between border-b border-border bg-background/[0.92] px-6 py-4 backdrop-blur-2xl">
                    <span className="font-display text-xl font-medium tracking-tight">
                        Alerts
                    </span>
                    <div className="flex items-center gap-3">
                        <span
                            className={`rounded-full px-2.5 py-0.5 text-[11px] font-bold text-white ${
                                unreadCount > 0 ? 'bg-danger' : 'bg-success'
                            }`}
                        >
                            {unreadCount > 0
                                ? `${unreadCount} new`
                                : 'All read'}
                        </span>
                        {unreadCount > 0 && (
                            <button
                                onClick={markAllRead}
                                className="text-xs font-semibold text-[#1A4CD4]"
                            >
                                Mark all read
                            </button>
                        )}
                        <button
                            onClick={() => setSettingsOpen(true)}
                            className="text-muted-foreground transition-colors hover:text-foreground lg:hidden"
                        >
                            <IconSettings size={20} stroke={1.7} />
                        </button>
                    </div>
                </div>

                {/* Tabs — local filtering, no page reload */}
                <div className="sticky top-[57px] z-40 flex border-b border-border bg-card">
                    {tabs.map((t) => (
                        <button
                            key={t.id}
                            onClick={() => setActiveTab(t.id)}
                            className={`flex-1 border-b-2 px-3 py-[11px] text-center text-xs font-semibold transition-all ${
                                activeTab === t.id
                                    ? 'border-[#1A4CD4] text-[#1A4CD4]'
                                    : 'border-transparent text-muted-foreground hover:bg-secondary'
                            }`}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>

                {/* Alert feed grouped by time */}
                {filteredAlerts.length === 0 ? (
                    <div className="py-16 text-center">
                        <div className="mb-3 text-[40px]">🔔</div>
                        <div className="text-base font-semibold text-muted-foreground">
                            No alerts here
                        </div>
                        <div className="mt-1.5 text-[13px] leading-relaxed text-muted-foreground">
                            You're all caught up in this category.
                        </div>
                    </div>
                ) : (
                    <div>
                        {groupOrder.map((groupName) => {
                            const items = grouped[groupName];

                            if (!items || items.length === 0) {
                                return null;
                            }

                            const stacked = stackAlerts(items);

                            return (
                                <div key={groupName}>
                                    <div className="flex items-center gap-2 px-6 pt-3.5 pb-1.5">
                                        <span className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase">
                                            {groupName}
                                        </span>
                                        <div className="h-px flex-1 bg-border" />
                                    </div>
                                    {stacked.map((item) =>
                                        isStack(item) ? (
                                            <AlertStackRow
                                                key={`stack-${item.stackKey}`}
                                                stack={item}
                                                onMarkRead={markRead}
                                                onDismiss={dismissAlert}
                                            />
                                        ) : (
                                            <AlertRow
                                                key={item.id}
                                                alert={item}
                                                onMarkRead={markRead}
                                                onDismiss={dismissAlert}
                                            />
                                        ),
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>

            {/* Mobile notification settings */}
            <BottomSheet
                open={settingsOpen}
                onClose={() => setSettingsOpen(false)}
            >
                <AlertsRightPanel counts={counts} />
            </BottomSheet>
        </AppLayout>
    );
}
