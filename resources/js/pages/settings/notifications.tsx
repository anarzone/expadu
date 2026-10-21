import { Head, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import Heading from '@/components/heading';
import {
    Toggle,
    ToggleCard,
    ToggleRow,
} from '@/components/settings/toggle-row';
import { usePushSubscription } from '@/hooks/use-push-subscription';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { writeJson } from '@/lib/reliable-write';

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

    const {
        isReady,
        isSupported,
        isSubscribed,
        statusError,
        retryStatus,
        subscribe,
        unsubscribe,
    } = usePushSubscription();

    const [toggles, setToggles] =
        useState<Record<string, boolean>>(preferences);
    const [pushLoading, setPushLoading] = useState(false);

    const savingRef = useRef(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function toggle(id: string) {
        if (savingRef.current) {
            return;
        }

        savingRef.current = true;
        setSaving(true);
        setError(null);
        const next = { ...toggles, [id]: !toggles[id] };

        try {
            await writeJson('/notification-preferences', 'PUT', {
                preferences: { [id]: next[id] },
            });
            setToggles(next);
        } catch (error) {
            setError(
                error instanceof Error
                    ? error.message
                    : 'We couldn’t save that. Please try again.',
            );
        } finally {
            savingRef.current = false;
            setSaving(false);
        }
    }

    async function handlePushToggle() {
        if (pushLoading) {
            return;
        }

        setPushLoading(true);
        setError(null);
        const saved = isSubscribed ? await unsubscribe() : await subscribe();

        if (!saved) {
            setError(
                typeof Notification !== 'undefined' &&
                    Notification.permission === 'denied'
                    ? 'Notifications are blocked in this browser. You can allow them in your browser settings.'
                    : 'We couldn’t update push notifications. Please try again.',
            );
        }

        setPushLoading(false);
    }

    return (
        <AppLayout>
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
                        <div className="flex items-center justify-between bg-bg-quiet px-4 py-[13px]">
                            <div>
                                <div className="text-[13.5px] font-semibold">
                                    Push notifications
                                </div>
                                <div className="mt-0.5 text-[11.5px] text-text-muted">
                                    {!isReady
                                        ? 'Checking this device…'
                                        : !isSupported
                                          ? 'Not supported on this browser'
                                          : statusError
                                            ? 'Status unavailable'
                                            : isSubscribed
                                              ? 'Enabled — you will receive alerts on this device'
                                              : 'Enable to receive alerts on this device'}
                                </div>
                            </div>
                            {isSupported && (
                                <Toggle
                                    label="Push notifications"
                                    on={isSubscribed}
                                    onClick={handlePushToggle}
                                    disabled={
                                        pushLoading || !isReady || !!statusError
                                    }
                                />
                            )}
                        </div>
                    </ToggleCard>
                    {statusError && (
                        <div
                            role="alert"
                            className="text-sm text-conflict-text"
                        >
                            {statusError}{' '}
                            <button
                                type="button"
                                className="min-h-11 font-semibold underline"
                                onClick={retryStatus}
                            >
                                Try again
                            </button>
                        </div>
                    )}

                    {/* Per-type toggles. These also gate the in-app alerts
                        feed, so they stay meaningful when device push is off —
                        the sub-label spells that out to avoid the "do these do
                        anything?" confusion. */}
                    <div>
                        <div className="mb-2 px-1">
                            <div className="text-[13.5px] font-semibold">
                                Alert types
                            </div>
                            <div className="mt-0.5 text-[11.5px] text-text-muted">
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
                                    onToggle={() => void toggle(setting.id)}
                                    disabled={saving}
                                />
                            ))}
                        </ToggleCard>
                    </div>
                    {error && (
                        <p role="alert" className="text-sm text-conflict-text">
                            {error}
                        </p>
                    )}
                    {saving && (
                        <p role="status" className="text-sm text-text-muted">
                            Saving…
                        </p>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
