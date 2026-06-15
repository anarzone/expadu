// Keys + labels mirror the backend App\Profile\Interest enum.
const INTERESTS = [
    { value: 'parks', label: 'Parks & green', emoji: '🌳' },
    { value: 'swimming', label: 'Swimming & lakes', emoji: '🏊' },
    { value: 'sports', label: 'Sports & courts', emoji: '⚽' },
    { value: 'museums', label: 'Museums & galleries', emoji: '🎨' },
    { value: 'sights', label: 'Sights & landmarks', emoji: '🏛️' },
    { value: 'family', label: 'Family & kids', emoji: '🛝' },
    { value: 'cafes', label: 'Cafés & bakeries', emoji: '☕' },
    { value: 'nightlife', label: 'Bars & nightlife', emoji: '🍻' },
    { value: 'live_music', label: 'Live music', emoji: '🎶' },
    { value: 'coworking', label: 'Coworking', emoji: '💻' },
    { value: 'libraries', label: 'Libraries', emoji: '📚' },
    { value: 'dogs', label: 'Dog-friendly', emoji: '🐕' },
];

// Mirrors App\Profile\Interest::MIN_SELECT / MAX_SELECT.
const MIN = 3;
const MAX = 6;

export function InterestsStep({
    interests,
    onToggle,
}: {
    interests: string[];
    onToggle: (value: string) => void;
}) {
    const count = interests.length;
    const atMax = count >= MAX;

    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="py-2 pb-6">
                <h2 className="mb-2 font-display text-[26px] font-medium">
                    What are you into?
                </h2>
                <p className="text-sm text-muted-foreground">
                    Pick at least {MIN} (up to {MAX}) — we lead your home feed
                    and day plans with these, and you can change them anytime.
                </p>
                <p
                    className={`mt-2 text-[13px] font-semibold ${count >= MIN ? 'text-success' : 'text-muted-foreground'}`}
                >
                    {count}/{MAX} chosen
                    {count < MIN ? ` · pick ${MIN - count} more` : ''}
                </p>
            </div>

            <div className="grid grid-cols-2 gap-2.5">
                {INTERESTS.map((interest) => {
                    const on = interests.includes(interest.value);
                    const disabled = !on && atMax;

                    return (
                        <button
                            key={interest.value}
                            type="button"
                            aria-pressed={on}
                            disabled={disabled}
                            onClick={() => onToggle(interest.value)}
                            className={`flex items-center gap-2.5 rounded-[12px] border-[1.5px] px-4 py-3.5 text-left text-[14px] font-semibold transition-all ${
                                on
                                    ? 'border-primary bg-accent-soft text-primary'
                                    : disabled
                                      ? 'border-border bg-card opacity-40'
                                      : 'border-border bg-card hover:border-primary/30'
                            }`}
                        >
                            <span className="text-[20px] leading-none">
                                {interest.emoji}
                            </span>
                            {interest.label}
                            {on && (
                                <span className="ml-auto text-primary">✓</span>
                            )}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
