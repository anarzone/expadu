import { Head } from '@inertiajs/react';
import { useState } from 'react';
import {
    AccountPanel,
    CityPanel,
    DataPanel,
    ComposerPanel,
    DeletePanel,
    LaterPanel,
    LearnPanel,
} from '@/components/settings-page/panels';
import type { EditKey } from '@/components/settings-page/panels';
import AppLayout from '@/layouts/app-layout';
import '../../css/settings.css';

/**
 * Settings (owner, 22 Oct 2026: "build it"; the mock's settings-next): one
 * page of panels in Paperwork's Your situation language. Your account on
 * the left from 1000px, the rest beside it; phones read them one under
 * another. One tile is open at a time: opening another cancels the first.
 * The D-Ticket and location sharing live in Travel.
 */
export default function Settings() {
    const [edit, setEdit] = useState<EditKey>(null);

    return (
        <AppLayout>
            <Head title="Settings" />
            <div className="settings-page">
                <AccountPanel edit={edit} onEdit={setEdit} />
                <div className="settings-side">
                    <LearnPanel />
                    <ComposerPanel />
                    <CityPanel edit={edit} onEdit={setEdit} />
                    <LaterPanel
                        id="notify"
                        icon="bell"
                        title="Notifications"
                        label="With Alerts"
                    >
                        What Expadu tells you about, and where, is set here once
                        the new Alerts is designed.
                    </LaterPanel>
                    <DataPanel />
                    <DeletePanel />
                </div>
            </div>
        </AppLayout>
    );
}
