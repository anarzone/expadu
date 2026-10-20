/* The owner's motion lab for the Events street (QA tooling, shown with
   ?motion=lab): how much moves, switched live, and Replay to watch the
   street start over (posters pasted up, the column from its first). The
   choice is remembered in this browser only. */

import type { StreetMotion } from './street-scene';

const KEY = 'expadu.events.motion';

const LEVELS: { value: StreetMotion; label: string; hint: string }[] = [
    { value: 'still', label: 'Still', hint: 'Nothing moves' },
    {
        value: 'calm',
        label: 'Calm',
        hint: 'The sky and the weather: clouds, stars, the sun, the lamp, rain',
    },
    {
        value: 'full',
        label: 'Full',
        hint: 'The mock: also birds, the plane, smoke, windows, the tree, leaves and pigeons',
    },
];

export function readMotion(): StreetMotion {
    try {
        const stored = window.localStorage.getItem(KEY);

        return LEVELS.some((l) => l.value === stored)
            ? (stored as StreetMotion)
            : 'full';
    } catch {
        return 'full';
    }
}

export function MotionLab({
    motion,
    onMotion,
    onReplay,
}: {
    motion: StreetMotion;
    onMotion: (motion: StreetMotion) => void;
    onReplay: () => void;
}) {
    const choose = (next: StreetMotion) => {
        onMotion(next);

        try {
            window.localStorage.setItem(KEY, next);
        } catch {
            // Remembering is a convenience; the lab works without it.
        }
    };

    return (
        <div className="st-lab" role="group" aria-label="Motion lab">
            <span className="st-lab-name">Motion</span>
            <div className="st-lab-levels">
                {LEVELS.map((l) => (
                    <button
                        key={l.value}
                        type="button"
                        title={l.hint}
                        aria-pressed={motion === l.value}
                        onClick={() => choose(l.value)}
                    >
                        {l.label}
                    </button>
                ))}
            </div>
            <button
                type="button"
                className="st-lab-replay"
                aria-label="Replay the street's motion"
                title="Replay"
                onClick={onReplay}
            >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 12a8 8 0 1 0 2.4-5.7M4 4v4h4" />
                </svg>
                Replay
            </button>
        </div>
    );
}
