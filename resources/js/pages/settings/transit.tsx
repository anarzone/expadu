import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { ToggleCard, ToggleRow } from '@/components/settings/toggle-row';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

export default function TransitSettings() {
    const { auth } = usePage<{
        auth: { user: { has_deutschlandticket?: boolean } };
    }>().props;

    const [hasDticket, setHasDticket] = useState<boolean>(
        auth.user.has_deutschlandticket ?? false,
    );

    function toggle() {
        const next = !hasDticket;
        setHasDticket(next);
        router.patch(
            '/settings/profile',
            { has_deutschlandticket: next },
            { preserveScroll: true, preserveState: true },
        );
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
                        description="Tell us what you carry so fares show up right."
                    />
                    <ToggleCard>
                        <ToggleRow
                            label="I have a Deutschlandticket"
                            sub="Shows trips as covered instead of a single fare"
                            on={hasDticket}
                            onToggle={toggle}
                        />
                    </ToggleCard>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
