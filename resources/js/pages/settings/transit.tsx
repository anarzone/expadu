import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import Heading from '@/components/heading';
import { ToggleCard, ToggleRow } from '@/components/settings/toggle-row';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

function csrf(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

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

    function toggleDticket() {
        const next = !hasDticket;
        setHasDticket(next);
        router.patch(
            '/settings/profile',
            { has_deutschlandticket: next },
            { preserveScroll: true, preserveState: true },
        );
    }

    const persistShareLocation = useCallback((value: boolean) => {
        fetch('/user-settings', {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                Accept: 'application/json',
            },
            body: JSON.stringify({ settings: { share_location: value } }),
        }).catch(() => {});
    }, []);

    function toggleShareLocation() {
        setShareLocation((prev) => {
            const next = !prev;
            persistShareLocation(next);

            return next;
        });
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
                            onToggle={toggleDticket}
                        />
                        <ToggleRow
                            label="Share location for transit"
                            sub="Improves departure-time accuracy near you"
                            on={shareLocation}
                            onToggle={toggleShareLocation}
                        />
                    </ToggleCard>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
