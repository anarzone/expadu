import { router } from '@inertiajs/react';

/**
 * Veedel is the primary Places navigation: home Veedel first (server
 * puts it there), highest-content Veedels after, "All Cologne" lifts
 * the filter.
 */
export function VeedelPills({
    options,
    active,
}: {
    options: string[];
    active: string;
}) {
    function select(veedel: string) {
        router.get(
            '/explore',
            { veedel },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['spots', 'filters'],
            },
        );
    }

    const pills = [
        ...options.map((name) => ({ name, label: name })),
        { name: 'all', label: 'All Cologne' },
    ];

    return (
        <div
            className="flex gap-2 overflow-x-auto px-3.5 pt-1 pb-2 md:px-4"
            style={{ scrollbarWidth: 'none' }}
        >
            {pills.map((pill) => (
                <button
                    key={pill.name}
                    onClick={() => select(pill.name)}
                    className={`shrink-0 cursor-pointer rounded-full border px-3.5 py-1.5 text-[13px] font-semibold transition-all ${
                        active === pill.name
                            ? 'border-primary bg-primary text-white'
                            : 'border-border bg-card text-muted-foreground hover:border-primary hover:text-primary'
                    }`}
                >
                    {pill.name === options[0] && active !== pill.name
                        ? `📍 ${pill.label}`
                        : pill.label}
                </button>
            ))}
        </div>
    );
}
