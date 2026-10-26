import { progress } from '@/actions/App/Http/Controllers/ComposerController';
import { api } from './types';

type Looked = 'listings' | 'places' | 'weather' | 'plans' | 'taste';

type Report = {
    step: 'reading' | 'writing' | null;
    looked: Looked[];
};

const LOOKED: Record<Looked, () => string> = {
    listings: () =>
        new Date().getHours() >= 17 ? 'tonight’s listings' : 'today’s listings',
    places: () => 'places',
    weather: () => 'the weather',
    plans: () => 'your plans',
    taste: () => 'what you like',
};

/** What the line under the box says while the assistant works (3–6 s). */
function label({ step, looked }: Report): string | null {
    if (step === 'writing') {
        return 'Putting it together…';
    }

    const named = (Object.keys(LOOKED) as Looked[])
        .filter((key) => looked.includes(key))
        .map((key) => LOOKED[key]());

    if (named.length) {
        const list =
            named.length > 1
                ? `${named.slice(0, -1).join(', ')} and ${named.at(-1)}`
                : named[0];

        return `Looking at ${list}…`;
    }

    return step === 'reading' ? 'Reading your request…' : null;
}

/*
 * Asks how far the assistant's read has got (#324: the loop runs inside the
 * parse request, keyed by the permit's request id) and says it as it goes. Only
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
