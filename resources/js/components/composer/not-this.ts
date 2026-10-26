import PlaceFeedbackController from '@/actions/App/Http/Controllers/Api/PlaceFeedbackController';
import { showToast } from '@/components/ui/toast';
import { signalLater } from '@/lib/signals';
import { api } from './types';

/** The toast's own time for Undo, as Not now has it. */
const UNDO = 5000;

/*
 * "Not this" on an assistant's suggestion (owner, 22 Oct 2026): the card goes at
 * once, with Undo and no confirm tap; once Undo has passed it's recorded as not
 * for me, which the assistant leaves out from then on. An event goes to the
 * signals ledger (90 days); a place takes its own "Not interested", as the place
 * sheet records it.
 */
export function notThis(
    item: { type: 'event' | 'spot'; id: number },
    onUndo: () => void,
) {
    let cancel: () => void;

    if (item.type === 'event') {
        cancel = signalLater(
            {
                action: 'not_for_me',
                surface: 'composer',
                item_type: 'event',
                item_id: item.id,
            },
            UNDO,
        );
    } else {
        const timeout = window.setTimeout(() => {
            void api(PlaceFeedbackController.url({ spot: item.id }), {
                action: 'not_interested',
            }).catch(() => undefined);
        }, UNDO);
        cancel = () => window.clearTimeout(timeout);
    }

    showToast('Noted. Composer will leave it out.', {
        duration: UNDO,
        undo: () => {
            cancel();
            onUndo();
        },
    });
}
