import { Head, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import Heading from '@/components/heading';
import { ToggleCard, ToggleRow } from '@/components/settings/toggle-row';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { writeJson } from '@/lib/reliable-write';

type Key = 'composer_ask_first_everyday' | 'composer_ask_first_paperwork';

/**
 * Ask first (owner, 23 Oct 2026): whether Composer asks before it changes
 * something, or does it and offers Undo. Everyday changes (saved places, My
 * day, home and work) default to doing it; Paperwork defaults to asking.
 */
export default function ComposerSettings() {
    const { userSettings } = usePage<{
        userSettings?: Record<string, boolean | string>;
    }>().props;
    const [on, setOn] = useState<Record<Key, boolean>>({
        composer_ask_first_everyday:
            (userSettings?.composer_ask_first_everyday as boolean) ?? false,
        composer_ask_first_paperwork:
            (userSettings?.composer_ask_first_paperwork as boolean) ?? true,
    });
    const savingRef = useRef(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function save(key: Key) {
        if (savingRef.current) {
            return;
        }

        savingRef.current = true;
        setSaving(true);
        setError(null);

        try {
            const next = !on[key];
            await writeJson('/user-settings', 'PUT', {
                settings: { [key]: next },
            });
            setOn((all) => ({ ...all, [key]: next }));
            router.reload({ only: ['userSettings'] });
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'We couldn’t save that. Please try again.',
            );
        } finally {
            savingRef.current = false;
            setSaving(false);
        }
    }

    return (
        <AppLayout>
            <Head title="Composer settings" />

            <h1 className="sr-only">Composer</h1>

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Composer"
                        description="Whether Composer asks before it changes something for you. Either way, a change it makes can be undone for 24 hours."
                    />
                    <ToggleCard>
                        <ToggleRow
                            label="Ask before everyday changes"
                            sub={
                                on.composer_ask_first_everyday
                                    ? 'On: saved places, My day, home and work wait for your Apply'
                                    : 'Off: Composer makes them and shows Undo'
                            }
                            on={on.composer_ask_first_everyday}
                            onToggle={() =>
                                void save('composer_ask_first_everyday')
                            }
                            disabled={saving}
                        />
                        <ToggleRow
                            label="Ask before Paperwork changes"
                            sub={
                                on.composer_ask_first_paperwork
                                    ? 'On: a change to your record waits for your Apply'
                                    : 'Off: Composer makes it and shows Undo'
                            }
                            on={on.composer_ask_first_paperwork}
                            onToggle={() =>
                                void save('composer_ask_first_paperwork')
                            }
                            disabled={saving}
                        />
                    </ToggleCard>
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
