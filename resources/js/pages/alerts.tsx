import { Head, router, usePage } from '@inertiajs/react';
import { AlertRow } from '@/components/alerts/alert-row';
import AppLayout from '@/layouts/app-layout';

type AlertData = {
    id: number; type: string; title: string; body: string | null;
    deep_link: string | null; read_at: string | null; created_at: string;
};

const tabs = [
    { id: 'all', label: 'All' },
    { id: 'system', label: 'System' },
    { id: 'social', label: 'Social' },
    { id: 'reminder', label: 'Reminders' },
];

export default function Alerts() {
    const { alerts, unreadCount, tab: activeTab } = usePage<{
        alerts: { data: AlertData[] };
        unreadCount: number;
        tab: string;
    }>().props;

    function switchTab(t: string) {
        router.get('/alerts', t !== 'all' ? { tab: t } : {}, { preserveState: true });
    }

    function markAllRead() {
        router.post('/alerts/read-all', {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Alerts', href: '/alerts' }]}>
            <Head title="Alerts" />
            <div className="mx-auto w-full max-w-[680px]">
                <div className="flex items-center justify-between border-b border-border px-6 py-4">
                    <div>
                        <h1 className="font-display text-xl font-medium">🔔 Alerts</h1>
                        {unreadCount > 0 && (
                            <span className="text-xs text-muted-foreground">{unreadCount} unread</span>
                        )}
                    </div>
                    {unreadCount > 0 && (
                        <button onClick={markAllRead} className="text-xs font-semibold text-primary">
                            Mark all read
                        </button>
                    )}
                </div>

                <div className="flex border-b border-border">
                    {tabs.map((t) => (
                        <button
                            key={t.id}
                            onClick={() => switchTab(t.id)}
                            className={`flex-1 border-b-2 px-3 py-3 text-center text-xs font-semibold transition-colors ${
                                activeTab === t.id ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:bg-secondary'
                            }`}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>

                <div>
                    {alerts.data.length === 0 ? (
                        <div className="py-16 text-center">
                            <span className="text-4xl">🔕</span>
                            <p className="mt-3 text-sm text-muted-foreground">No alerts yet</p>
                        </div>
                    ) : (
                        alerts.data.map((a) => <AlertRow key={a.id} alert={a} />)
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
