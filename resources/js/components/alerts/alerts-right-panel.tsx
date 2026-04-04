import { usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { usePushSubscription } from '@/hooks/use-push-subscription';

type AlertCounts = {
    unread: number;
    system: number;
    social: number;
    reminder: number;
};

const notificationSettings = [
    {
        id: 'transit',
        label: 'Transit disruptions',
        sub: 'Delays on your saved routes',
    },
    {
        id: 'burgeramt',
        label: 'Bürgeramt slots',
        sub: 'New appointments available',
    },
    {
        id: 'language',
        label: 'Language partner requests',
        sub: 'New connections and messages',
    },
    {
        id: 'events',
        label: 'Event reminders',
        sub: "1 day before events you're attending",
    },
    {
        id: 'digest',
        label: 'Weekly digest',
        sub: 'Monday morning events roundup',
    },
    {
        id: 'rhine',
        label: 'Rhine flood alerts',
        sub: 'When water level exceeds threshold',
    },
];

export function AlertsRightPanel({ counts }: { counts: AlertCounts }) {
    return (
        <>
            <NotificationSettings />
            <AlertSummary counts={counts} />
        </>
    );
}

function NotificationSettings() {
    const { notificationPreferences } = usePage<{
        notificationPreferences?: Record<string, boolean>;
    }>().props;
    const { isSupported, isSubscribed, subscribe, unsubscribe } =
        usePushSubscription();

    const [toggles, setToggles] = useState<Record<string, boolean>>(
        notificationPreferences ?? {
            transit: true,
            burgeramt: true,
            language: true,
            events: true,
            digest: false,
            rhine: true,
        },
    );
    const [pushLoading, setPushLoading] = useState(false);

    const csrf =
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') || '';

    const persistPreferences = useCallback(
        (prefs: Record<string, boolean>) => {
            fetch('/notification-preferences', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify({ preferences: prefs }),
            }).catch(() => {});
        },
        [csrf],
    );

    function toggle(id: string) {
        setToggles((prev) => {
            const next = { ...prev, [id]: !prev[id] };
            persistPreferences(next);
            return next;
        });
    }

    async function handlePushToggle() {
        setPushLoading(true);
        if (isSubscribed) {
            await unsubscribe();
        } else {
            await subscribe();
        }
        setPushLoading(false);
    }

    return (
        <div className="mb-3.5 overflow-hidden rounded-xl border border-border bg-card">
            <div className="border-b border-border px-4 py-3">
                <span className="text-[13px] font-bold">
                    Notification settings
                </span>
            </div>

            {/* Master push toggle */}
            {isSupported && (
                <div className="flex items-center justify-between border-b border-border bg-[#F6F5F1] px-4 py-[11px] dark:bg-[#2A2920]">
                    <div>
                        <div className="text-[13px] font-semibold">
                            Push notifications
                        </div>
                        <div className="mt-px text-[11px] text-muted-foreground">
                            {isSubscribed
                                ? 'Enabled — you will receive alerts'
                                : 'Enable to receive alerts on this device'}
                        </div>
                    </div>
                    <button
                        onClick={handlePushToggle}
                        disabled={pushLoading}
                        className={`relative h-[22px] w-10 shrink-0 rounded-full transition-colors duration-250 ${
                            isSubscribed ? 'bg-[#1A4CD4]' : 'bg-border'
                        } ${pushLoading ? 'opacity-50' : ''}`}
                    >
                        <span
                            className={`absolute top-[2px] left-[2px] size-[18px] rounded-full bg-white shadow-sm transition-transform duration-250 ${
                                isSubscribed
                                    ? 'translate-x-[18px]'
                                    : 'translate-x-0'
                            }`}
                            style={{
                                transitionTimingFunction:
                                    'cubic-bezier(0.32, 1, 0.4, 1)',
                            }}
                        />
                    </button>
                </div>
            )}

            {/* Per-type toggles */}
            {notificationSettings.map((setting) => (
                <div
                    key={setting.id}
                    className="flex items-center justify-between border-b border-border px-4 py-[11px] last:border-b-0"
                >
                    <div>
                        <div className="text-[13px] font-medium">
                            {setting.label}
                        </div>
                        <div className="mt-px text-[11px] text-muted-foreground">
                            {setting.sub}
                        </div>
                    </div>
                    <button
                        onClick={() => toggle(setting.id)}
                        className={`relative h-[22px] w-10 shrink-0 rounded-full transition-colors duration-250 ${
                            toggles[setting.id] ? 'bg-success' : 'bg-border'
                        }`}
                    >
                        <span
                            className={`absolute top-[2px] left-[2px] size-[18px] rounded-full bg-white shadow-sm transition-transform duration-250 ${
                                toggles[setting.id]
                                    ? 'translate-x-[18px]'
                                    : 'translate-x-0'
                            }`}
                            style={{
                                transitionTimingFunction:
                                    'cubic-bezier(0.32, 1, 0.4, 1)',
                            }}
                        />
                    </button>
                </div>
            ))}
        </div>
    );
}

function AlertSummary({ counts }: { counts: AlertCounts }) {
    return (
        <div className="mb-3.5 overflow-hidden rounded-xl border border-border bg-card">
            <div className="border-b border-border px-4 py-3">
                <span className="text-[13px] font-bold">Alert summary</span>
            </div>
            <div className="flex flex-col gap-2.5 px-4 py-3.5">
                <SummaryRow label="Unread" value={counts.unread} />
                <SummaryRow label="System alerts" value={counts.system} />
                <SummaryRow label="Social" value={counts.social} />
                <SummaryRow label="Reminders" value={counts.reminder} />
            </div>
        </div>
    );
}

function SummaryRow({ label, value }: { label: string; value: number }) {
    return (
        <div className="flex justify-between text-[13px]">
            <span className="text-muted-foreground">{label}</span>
            <span className="font-bold">{value}</span>
        </div>
    );
}
