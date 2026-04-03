import { Head, router, usePage } from '@inertiajs/react';
import { AlertRow } from '@/components/alerts/alert-row';
import { AlertsRightPanel } from '@/components/alerts/alerts-right-panel';
import AppLayout from '@/layouts/app-layout';

type AlertData = {
    id: number;
    type: string;
    title: string;
    body: string | null;
    deep_link: string | null;
    read_at: string | null;
    created_at: string;
};

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
        alerts,
        unreadCount,
        counts,
        tab: activeTab,
    } = usePage<{
        alerts: { data: AlertData[] };
        unreadCount: number;
        counts: {
            unread: number;
            system: number;
            social: number;
            reminder: number;
        };
        tab: string;
    }>().props;

    // Track index per type for visual config mapping
    const typeCounters: Record<string, number> = {};

    function getTypeIndex(type: string): number {
        if (!(type in typeCounters)) {
typeCounters[type] = 0;
}

        return typeCounters[type]++;
    }

    function switchTab(t: string) {
        router.get('/alerts', t !== 'all' ? { tab: t } : {}, {
            preserveState: true,
        });
    }

    function markAllRead() {
        router.post('/alerts/read-all', {}, { preserveScroll: true });
    }

    const grouped = groupAlerts(alerts.data);
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
                    </div>
                </div>

                {/* Tabs — sticky below header */}
                <div className="sticky top-[57px] z-40 flex border-b border-border bg-card">
                    {tabs.map((t) => (
                        <button
                            key={t.id}
                            onClick={() => switchTab(t.id)}
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
                {alerts.data.length === 0 ? (
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

                            return (
                                <div key={groupName}>
                                    <div className="flex items-center gap-2 px-6 pt-3.5 pb-1.5">
                                        <span className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase">
                                            {groupName}
                                        </span>
                                        <div className="h-px flex-1 bg-border" />
                                    </div>
                                    {items.map((alert) => (
                                        <AlertRow
                                            key={alert.id}
                                            alert={alert}
                                            indexInType={getTypeIndex(
                                                alert.type,
                                            )}
                                        />
                                    ))}
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
