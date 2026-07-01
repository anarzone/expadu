import { Head, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import Heading from '@/components/heading';
import { usePushSubscription } from '@/hooks/use-push-subscription';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editNotifications } from '@/routes/notifications';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Notification settings',
        href: editNotifications(),
    },
];

/**
 * Per-type toggles. Keys map to the ActionBus preference gates
 * (see NotificationPreference::defaults): transit / burgeramt / checklist /
 * events / weather / rhine / digest.
 */
const NOTIFICATION_TYPES: { id: string; label: string; sub: string }[] = [
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
        id: 'checklist',
        label: 'Bureaucracy deadlines',
        sub: 'Reminders before your paperwork is due',
    },
    {
        id: 'events',
        label: 'Event reminders',
        sub: "1 day before events you're attending",
    },
    {
        id: 'weather',
        label: 'Weather alerts',
        sub: 'Rain, wind, and commute-affecting weather',
    },
    {
        id: 'rhine',
        label: 'Rhine flood alerts',
        sub: 'When water level exceeds threshold',
    },
    {
        id: 'digest',
        label: 'Weekly digest',
        sub: 'Monday morning events roundup',
    },
];

function Toggle({
    on,
    onClick,
    disabled,
}: {
    on: boolean;
    onClick: () => void;
    disabled?: boolean;
}) {
    return (
        <button
            onClick={onClick}
            disabled={disabled}
            className={`relative h-[22px] w-10 shrink-0 rounded-full transition-colors duration-250 ${
                on ? 'bg-success' : 'bg-border'
            } ${disabled ? 'opacity-50' : ''}`}
        >
            <span
                className={`absolute top-[2px] left-[2px] size-[18px] rounded-full bg-white shadow-sm transition-transform duration-250 ${
                    on ? 'translate-x-[18px]' : 'translate-x-0'
                }`}
                style={{
                    transitionTimingFunction: 'cubic-bezier(0.32, 1, 0.4, 1)',
                }}
            />
        </button>
    );
}

export default function NotificationsSettings() {
    const { preferences } = usePage<{
        preferences: Record<string, boolean>;
    }>().props;

    const { isSupported, isSubscribed, subscribe, unsubscribe } =
        usePushSubscription();

    const [toggles, setToggles] =
        useState<Record<string, boolean>>(preferences);
    const [pushLoading, setPushLoading] = useState(false);

    const csrf =
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') || '';

    const persist = useCallback(
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
            persist(next);

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
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notification settings" />

            <h1 className="sr-only">Notification settings</h1>

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Notifications"
                        description="Choose what Expadu pings you about, and where."
                    />

                    {/* Master push toggle */}
                    <div className="overflow-hidden rounded-xl border border-border bg-card">
                        <div className="flex items-center justify-between bg-surface-2 px-4 py-[13px]">
                            <div>
                                <div className="text-[13.5px] font-semibold">
                                    Push notifications
                                </div>
                                <div className="mt-0.5 text-[11.5px] text-muted-foreground">
                                    {!isSupported
                                        ? 'Not supported on this browser'
                                        : isSubscribed
                                          ? 'Enabled — you will receive alerts on this device'
                                          : 'Enable to receive alerts on this device'}
                                </div>
                            </div>
                            {isSupported && (
                                <Toggle
                                    on={isSubscribed}
                                    onClick={handlePushToggle}
                                    disabled={pushLoading}
                                />
                            )}
                        </div>
                    </div>

                    {/* Per-type toggles */}
                    <div className="overflow-hidden rounded-xl border border-border bg-card">
                        {NOTIFICATION_TYPES.map((setting) => (
                            <div
                                key={setting.id}
                                className="flex items-center justify-between border-b border-border px-4 py-[13px] last:border-b-0"
                            >
                                <div>
                                    <div className="text-[13.5px] font-medium">
                                        {setting.label}
                                    </div>
                                    <div className="mt-0.5 text-[11.5px] text-muted-foreground">
                                        {setting.sub}
                                    </div>
                                </div>
                                <Toggle
                                    on={!!toggles[setting.id]}
                                    onClick={() => toggle(setting.id)}
                                />
                            </div>
                        ))}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
