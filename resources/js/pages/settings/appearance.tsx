import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

export default function Appearance() {
    return (
        <AppLayout rightPanel={null}>
            <Head title="Appearance settings" />

            <h1 className="sr-only">Appearance settings</h1>

            <SettingsLayout>
                <div className="space-y-6">
                    <AppearanceTabs />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
