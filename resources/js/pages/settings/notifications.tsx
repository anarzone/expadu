import { Head, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import Heading from '@/components/heading';
import {
    Toggle,
    ToggleCard,
    ToggleRow,
} from '@/components/settings/toggle-row';
import { usePushSubscription } from '@/hooks/use-push-subscription';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

/**
 * Per-type toggles. Keys map to the ActionBus preference gates
 * (see NotificationPreference::defaults): transit / checklist / events /
 * weather / rhine / digest. (The `burgeramt` gate has no live producer since
 * the slot-checker was removed, so it isn't surfaced as a toggle.)
 */
const NOTIFICATION_TYPES: { id: string; label: string; sub: string }[] = [
    {
        id: 'transit',
        label: 'Transit disruptions',
        sub: "Delays on lines and trips you're taking",
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
        <AppLayout rightPanel={null}>
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
                    <ToggleCard>
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
                    </ToggleCard>

                    {/* Per-type toggles. These also gate the in-app alerts
                        feed, so they stay meaningful when device push is off —
                        the sub-label spells that out to avoid the "do these do
                        anything?" confusion. */}
                    <div>
                        <div className="mb-2 px-1">
                            <div className="text-[13.5px] font-semibold">
                                Alert types
                            </div>
                            <div className="mt-0.5 text-[11.5px] text-muted-foreground">
                                {isSubscribed
                                    ? 'Shown in your alerts feed and pushed to this device.'
                                    : 'Shown in your alerts feed. Turn on push above to also get them on this device.'}
                            </div>
                        </div>
                        <ToggleCard>
                            {NOTIFICATION_TYPES.map((setting) => (
                                <ToggleRow
                                    key={setting.id}
                                    label={setting.label}
                                    sub={setting.sub}
                                    on={!!toggles[setting.id]}
                                    onToggle={() => toggle(setting.id)}
                                />
                            ))}
                        </ToggleCard>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
