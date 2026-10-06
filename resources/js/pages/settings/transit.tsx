import { Head, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import Heading from '@/components/heading';
import { ToggleCard, ToggleRow } from '@/components/settings/toggle-row';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { writeJson } from '@/lib/reliable-write';

export default function TransitSettings() {
    const { auth, userSettings } = usePage<{
        auth: { user: { has_deutschlandticket?: boolean } };
        userSettings?: Record<string, boolean | string>;
    }>().props;

    const [hasDticket, setHasDticket] = useState<boolean>(
        auth.user.has_deutschlandticket ?? false,
    );
    const [shareLocation, setShareLocation] = useState<boolean>(
        (userSettings?.share_location as boolean) ?? true,
    );

    const savingRef = useRef(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function saveSetting(kind: 'ticket' | 'location') {
        if (savingRef.current) {
            return;
        }

        savingRef.current = true;
        setSaving(true);
        setError(null);

        try {
            if (kind === 'ticket') {
                const next = !hasDticket;
                await writeJson('/settings/profile', 'PATCH', {
                    has_deutschlandticket: next,
                });
                setHasDticket(next);
            } else {
                const next = !shareLocation;
                await writeJson('/user-settings', 'PUT', {
                    settings: { share_location: next },
                });
                setShareLocation(next);
            }

            router.reload({ only: ['userSettings', 'auth'] });
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

    return (
        <AppLayout rightPanel={null}>
            <Head title="Transit settings" />

            <h1 className="sr-only">Transit &amp; tickets</h1>

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Transit & tickets"
                        description="Your ticket and location — so fares and departure times show up right."
                    />
                    <ToggleCard>
                        <ToggleRow
                            label="I have a Deutschlandticket"
                            sub="Shows trips as covered instead of a single fare"
                            on={hasDticket}
                            onToggle={() => void saveSetting('ticket')}
                            disabled={saving}
                        />
                        <ToggleRow
                            label="Share location for transit"
                            sub="Improves departure-time accuracy near you"
                            on={shareLocation}
                            onToggle={() => void saveSetting('location')}
                            disabled={saving}
                        />
                    </ToggleCard>
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    {saving && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Saving…
                        </p>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
