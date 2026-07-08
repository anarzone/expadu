import { Head, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import Heading from '@/components/heading';
import { ToggleCard, ToggleRow } from '@/components/settings/toggle-row';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

/** Frontend key → the /user-settings backend key. */
const KEYS = {
    shareLocation: 'share_location',
} as const;

function csrf(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

export default function PrivacySettings() {
    const { userSettings } = usePage<{
        userSettings?: Record<string, boolean | string>;
    }>().props;

    const s = userSettings ?? {};
    const [toggles, setToggles] = useState({
        shareLocation: (s.share_location as boolean) ?? true,
    });

    const persist = useCallback((key: keyof typeof KEYS, value: boolean) => {
        fetch('/user-settings', {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                Accept: 'application/json',
            },
            body: JSON.stringify({ settings: { [KEYS[key]]: value } }),
        }).catch(() => {});
    }, []);

    function toggle(key: keyof typeof KEYS) {
        setToggles((prev) => {
            const next = { ...prev, [key]: !prev[key] };
            persist(key, next[key]);

            return next;
        });
    }

    return (
        <AppLayout rightPanel={null}>
            <Head title="Privacy settings" />

            <h1 className="sr-only">Privacy &amp; data</h1>

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Privacy & data"
                        description="Control what Expadu collects and how it's used."
                    />
                    <ToggleCard>
                        <ToggleRow
                            label="Share location for transit"
                            sub="Improves departure times accuracy"
                            on={toggles.shareLocation}
                            onToggle={() => toggle('shareLocation')}
                        />
                    </ToggleCard>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
