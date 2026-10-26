import { router } from '@inertiajs/react';
import { useState } from 'react';
import { writeJson } from '@/lib/reliable-write';

const SNOOZE = 'expadu.composer.personalise-invite';
const MONTH = 30 * 24 * 60 * 60 * 1000;

function snoozed(): boolean {
    try {
        return Number(localStorage.getItem(SNOOZE) ?? 0) > Date.now();
    } catch {
        return false;
    }
}

/*
 * The one-line invitation to personalised suggestions (owner, 22 Oct 2026):
 * under an assistant's answer, for people with it off, when it would have
 * changed the suggestions. It says what it uses, and that the AI then reads
 * taste facts and plans (AI session, #324); one tap turns it on (Settings
 * turns it off). Never a pop-up; "Not now" keeps it
 * quiet on this device for a month.
 */
export function PersonaliseInvite() {
    const [hidden, setHidden] = useState(snoozed);
    const [state, setState] = useState<'idle' | 'saving' | 'on' | 'failed'>(
        'idle',
    );

    if (hidden && state !== 'on') {
        return null;
    }

    if (state === 'on') {
        return (
            <p className="answer-fine" role="status">
                Personalised suggestions are on. You can turn them off in
                Settings.
            </p>
        );
    }

    return (
        <p className="answer-fine composer-invite">
            Composer can learn what you like from the places and events you
            open, save or skip; its AI then reads that and your plans for today
            and tomorrow.{' '}
            <button
                type="button"
                className="composer-text-button"
                disabled={state === 'saving'}
                onClick={async () => {
                    setState('saving');

                    try {
                        await writeJson('/user-settings', 'PUT', {
                            settings: { personalisation: true },
                        });
                        setState('on');
                        router.reload({ only: ['userSettings'] });
                    } catch {
                        setState('failed');
                    }
                }}
            >
                Turn it on
            </button>{' '}
            <button
                type="button"
                className="composer-text-button"
                onClick={() => {
                    try {
                        localStorage.setItem(
                            SNOOZE,
                            String(Date.now() + MONTH),
                        );
                    } catch {
                        // Private windows: quiet while the page lasts.
                    }

                    setHidden(true);
                }}
            >
                Not now
            </button>
            {state === 'failed' && (
                <span role="alert"> That didn’t save. Try again.</span>
            )}
        </p>
    );
}
