const situations = [
    {
        value: 'non_eu_employee',
        emoji: '💼',
        title: 'Non-EU employee',
        subtitle: 'Work visa · Blue Card · Work permit',
    },
    {
        value: 'eu_employee',
        emoji: '🇪🇺',
        title: 'EU employee',
        subtitle: 'Freedom of movement · Registration only',
    },
    {
        value: 'student',
        emoji: '🎓',
        title: 'Student',
        subtitle: 'Student visa · Blocked account · Health insurance',
    },
    {
        value: 'freelancer',
        emoji: '🖥️',
        title: 'Freelancer',
        subtitle: 'Freiberufler · Tax registration · Clients',
    },
    {
        value: 'family_reunification',
        emoji: '👨‍👩‍👧',
        title: 'Family reunification',
        subtitle: 'Spouse/partner · Dependent visa',
    },
    {
        value: 'digital_nomad',
        emoji: '🌍',
        title: 'Digital nomad',
        subtitle: 'Remote work · Temporary stay',
    },
    {
        value: 'other',
        emoji: '✈️',
        title: 'Something else',
        subtitle: 'Retirement · Long-stay · Other purpose',
    },
];

export function SituationStep({
    value,
    onChange,
}: {
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="py-2 pb-6">
                <h2 className="mb-2 font-display text-[26px] font-medium">
                    What's your situation?
                </h2>
                <p className="text-sm text-muted-foreground">
                    This shapes your personalised checklist and visa guidance.
                </p>
            </div>

            <div className="flex flex-col gap-2.5">
                {situations.map((s) => (
                    <button
                        key={s.value}
                        type="button"
                        onClick={() => onChange(s.value)}
                        className={`flex items-center gap-3.5 rounded-xl border-[1.5px] p-3.5 text-left transition-all ${
                            value === s.value
                                ? 'border-primary bg-accent-soft'
                                : 'border-border bg-card hover:border-primary/30'
                        }`}
                    >
                        <span className="text-[22px]">{s.emoji}</span>
                        <div className="flex-1">
                            <div className="text-sm font-semibold">
                                {s.title}
                            </div>
                            <div className="mt-0.5 text-xs text-muted-foreground">
                                {s.subtitle}
                            </div>
                        </div>
                        <span
                            className={`text-base font-bold text-primary transition-opacity ${
                                value === s.value ? 'opacity-100' : 'opacity-0'
                            }`}
                        >
                            ✓
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}
