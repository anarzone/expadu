import { Link } from '@inertiajs/react';
import { useState } from 'react';

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
 * changed the suggestions. It leads to Settings › Privacy, which says in
 * full what it learns, never uses and sends, and asks that they're 16 or
 * older (owner, 29 Oct 2026). Never a pop-up; "Not now" keeps it quiet on
 * this device for a month.
 */
export function PersonaliseInvite() {
    const [hidden, setHidden] = useState(snoozed);

    if (hidden) {
        return null;
    }

    return (
        <p className="answer-fine composer-invite">
            Composer can suggest what suits you once it learns what you like.{' '}
            <Link className="composer-text-button" href="/settings/privacy">
                Turn it on in Settings
            </Link>{' '}
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
        </p>
    );
}
