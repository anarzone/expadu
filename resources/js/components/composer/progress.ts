import { progress } from '@/actions/App/Http/Controllers/ComposerController';
import { readList } from './read-words';
import { api } from './types';

type Report = {
    step: 'reading' | 'writing' | null;
    looked: string[];
};

/** What the line under the box says while the assistant works (3–6 s). */
function label({ step, looked }: Report): string | null {
    if (step === 'writing') {
        return 'Putting it together…';
    }

    const list = readList(looked, {
        listings:
            new Date().getHours() >= 17
                ? 'tonight’s listings'
                : 'today’s listings',
    });

    if (list) {
        return `Looking at ${list}…`;
    }

    return step === 'reading' ? 'Reading your request…' : null;
}

/*
 * Asks how far the assistant has got (the loop runs inside the ask request,
 * keyed by the permit's request id) and says it as it goes. Only
 * what the server reports; a miss or an error keeps the line as it was.
 * Returns the stop.
 */
export function watchProgress(
    requestId: string,
    say: (text: string) => void,
): () => void {
    let stopped = false;
    let asking = false;

    const timer = window.setInterval(() => {
        if (asking) {
            return;
        }

        asking = true;
        api<Report>(progress.url({ requestId }))
            .then((report) => {
                const text = label(report);

                if (!stopped && text) {
                    say(text);
                }
            })
            .catch(() => undefined)
            .finally(() => {
                asking = false;
            });
    }, 700);

    return () => {
        stopped = true;
        window.clearInterval(timer);
    };
}
