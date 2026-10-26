import { router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import Heading from '@/components/heading';
import { ToggleCard, ToggleRow } from '@/components/settings/toggle-row';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { writeJson } from '@/lib/reliable-write';

/**
 * The opt-in for personalised suggestions (#210's signal ledger, EXP-182).
 * Off by default. On is one tap. Off erases what was learned (the ledger and
 * its aggregates), so it asks first, as other permanent deletes do.
 */
export default function Personalisation() {
    const { userSettings } = usePage<{
        userSettings?: Record<string, boolean | string>;
    }>().props;
    const [on, setOn] = useState<boolean>(
        (userSettings?.personalisation as boolean) ?? false,
    );
    const [asking, setAsking] = useState(false);
    const [saving, setSaving] = useState(false);
    const savingRef = useRef(false);
    const [error, setError] = useState<string | null>(null);

    async function save(next: boolean) {
        if (savingRef.current) {
            return;
        }

        savingRef.current = true;
        setSaving(true);
        setError(null);

        try {
            await writeJson('/user-settings', 'PUT', {
                settings: { personalisation: next },
            });
            setOn(next);
            setAsking(false);
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
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Personalised suggestions"
                description="Let Expadu learn from the places, events, Today alerts, Travel stops and Composer topics you open, search, save, skip or dismiss, and let AI (Claude Haiku) put Today in order from that."
            />
            <ToggleCard>
                <ToggleRow
                    label="Learn what I like"
                    sub={
                        on
                            ? 'On: suggestions follow what you open and save'
                            : 'Off: everyone sees the same suggestions'
                    }
                    on={on}
                    onToggle={() => (on ? setAsking(true) : void save(true))}
                    disabled={saving}
                />
            </ToggleCard>
            {error && (
                <p role="alert" className="text-sm text-conflict-text">
                    {error}
                </p>
            )}
            <Dialog open={asking} onOpenChange={setAsking}>
                <DialogContent>
                    <DialogTitle>
                        Turn off and delete what was learned?
                    </DialogTitle>
                    <DialogDescription>
                        What Expadu learned from what you opened, saved or
                        skipped is deleted, and can’t be brought back.
                        Suggestions go back to the same ones everyone sees, and
                        Composer’s AI no longer reads what you like or your
                        plans.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Keep it on</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={saving}
                            onClick={() => void save(false)}
                        >
                            Turn off and delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
