import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { HowWeRank } from '@/components/personalisation/how-we-rank';
import { Icon } from '@/components/ui/icon';
import { Sheet } from '@/components/ui/sheet';
import { showToast } from '@/components/ui/toast';
import { signalLater } from '@/lib/signals';
import './personalisation.css';

/** The toast's own time for Undo, as Not now has it. */
const UNDO = 5000;

/*
 * Why am I seeing this? (owner, 29 Oct 2026, the consent canvas's boards 5
 * and 8): for an event Today's AI put first with a "why", which of the
 * person's leanings it matches as code reads them (TodayDiscovery::reasons),
 * and what they can do: less
 * like this (its kind lower, nothing hidden) or not for me (off Today),
 * each with Undo.
 */
export function WhySheet({
    event,
    onClose,
    onNotForMe,
}: {
    event: {
        id: number;
        title: string;
        when: string;
        why: string;
        reasons: string[];
    } | null;
    onClose: () => void;
    /** Taken off Today at once; Undo puts it back. */
    onNotForMe: (undo: boolean) => void;
}) {
    const [ranking, setRanking] = useState(false);

    function tell(
        action: 'less_like_this' | 'not_for_me',
        message: string,
        onUndo?: () => void,
    ) {
        if (!event) {
            return;
        }

        const cancel = signalLater(
            {
                action,
                surface: 'today',
                item_type: 'event',
                item_id: event.id,
            },
            UNDO,
        );
        showToast(message, {
            duration: UNDO,
            undo: () => {
                cancel();
                onUndo?.();
            },
        });
        onClose();
    }

    return (
        <>
            <Sheet
                open={event !== null}
                onClose={onClose}
                title="Why am I seeing this?"
                description={event ? `${event.title} · ${event.when}` : ''}
                className="why-sheet"
            >
                {event && event.reasons.length > 0 ? (
                    <ul className="why-facts">
                        {event.reasons.map((reason) => (
                            <li key={reason}>
                                <Icon name="check" variant="line" />
                                {reason}
                            </li>
                        ))}
                    </ul>
                ) : null}
                <div className="why-actions">
                    <button
                        type="button"
                        onClick={() =>
                            tell(
                                'less_like_this',
                                'Noted. Fewer like this from tomorrow.',
                            )
                        }
                    >
                        <Icon name="minus" variant="line" />
                        Less like this
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            onNotForMe(false);
                            tell('not_for_me', 'Taken off Today.', () =>
                                onNotForMe(true),
                            );
                        }}
                    >
                        <Icon name="cancel" variant="line" />
                        Not for me
                    </button>
                    <Link href="/settings/privacy">
                        <Icon name="sliders" variant="line" />
                        Personalised suggestions settings
                    </Link>
                    <button type="button" onClick={() => setRanking(true)}>
                        <Icon name="list" variant="line" />
                        How we rank
                    </button>
                </div>
            </Sheet>
            <HowWeRank open={ranking} onClose={() => setRanking(false)} />
        </>
    );
}
