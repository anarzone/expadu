import { showToast } from '@/components/ui/toast';
import { signalLater } from '@/lib/signals';

/** The toast's own time for Undo, as Not now has it. */
const UNDO = 5000;

/*
 * "Not this" on an assistant's suggested event (owner, 22 Oct 2026): the card
 * goes at once, with Undo and no confirm tap; once Undo has passed it goes to
 * the signals ledger (90 days) as not for me, which the assistant leaves out
 * from then on. A place's card folds to its own stub instead (PlaceAnswer).
 */
export function notThis(
    item: { type: 'event'; id: number },
    onUndo: () => void,
) {
    const cancel = signalLater(
        {
            action: 'not_for_me',
            surface: 'composer',
            item_type: 'event',
            item_id: item.id,
        },
        UNDO,
    );

    showToast('Noted. Composer will leave it out.', {
        duration: UNDO,
        undo: () => {
            cancel();
            onUndo();
        },
    });
}
