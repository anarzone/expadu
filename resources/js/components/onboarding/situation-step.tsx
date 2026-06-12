// Friendly first-person choices. 'job' resolves to eu_employee /
// non_eu_employee via the EU follow-up (see resolveSituation in onboarding.tsx).
const choices = [
    {
        value: 'job',
        emoji: '💼',
        title: 'I have a job here',
        subtitle: 'Employed by a company in Germany',
    },
    {
        value: 'student',
        emoji: '🎓',
        title: "I'm studying",
        subtitle: 'University, Studienkolleg or study preparation',
    },
    {
        value: 'freelancer',
        emoji: '💻',
        title: 'I work for myself',
        subtitle: 'Freelance or my own business',
    },
    {
        value: 'family_reunification',
        emoji: '❤️',
        title: "I'm joining family",
        subtitle: 'My partner or family lives here',
    },
    {
        value: 'digital_nomad',
        emoji: '🌍',
        title: 'I work remotely',
        subtitle: 'Digital nomad, employer abroad',
    },
    {
        value: 'other',
        emoji: '✨',
        title: 'Something else',
        subtitle: "We'll start with the essentials everyone needs",
    },
];

const entryModes = [
    {
        value: 'd_visa',
        emoji: '🛂',
        label: 'With a national D visa',
        subtitle: 'Your permit application is due before the visa expires',
    },
    {
        value: 'visa_free',
        emoji: '🛬',
        label: 'Visa-free (90-day window)',
        subtitle:
            "Permit due within 90 days — and no working before it's approved",
    },
    {
        value: 'has_permit',
        emoji: '💳',
        label: 'I already hold a German residence permit',
        subtitle: "We'll skip the first-permit tasks entirely",
    },
];

export function SituationStep({
    value,
    isEu,
    entryMode,
    showEuQuestion,
    onChange,
    onIsEuChange,
    onEntryModeChange,
    visaExpiresAt,
    onVisaExpiresAtChange,
}: {
    value: string;
    isEu: boolean | null;
    entryMode: string;
    showEuQuestion: boolean;
    onChange: (value: string) => void;
    onIsEuChange: (value: boolean) => void;
    onEntryModeChange: (value: string) => void;
    visaExpiresAt: string;
    onVisaExpiresAtChange: (value: string) => void;
}) {
    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="py-2 pb-6">
                <h2 className="mb-2 font-display text-[26px] font-medium">
                    What brings you to Cologne?
                </h2>
                <p className="text-sm text-muted-foreground">
                    This picks your paperwork path — each situation has
                    different offices and documents.
                </p>
            </div>

            <div className="flex flex-col gap-2.5">
                {choices.map((s) => (
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
                        <span className="w-8 text-center text-[22px]">
                            {s.emoji}
                        </span>
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

            {showEuQuestion && (
                <div className="mt-4 animate-in rounded-xl border border-dashed border-border bg-card p-4 fade-in slide-in-from-bottom-2">
                    <div className="mb-1 text-sm font-semibold">
                        Are you an EU / EEA / Swiss citizen?
                    </div>
                    <p className="mb-3 text-xs text-primary">
                        Why we ask: EU citizens skip the residence-permit
                        process entirely — it changes half your checklist.
                    </p>
                    <div className="flex gap-2.5">
                        {[
                            { value: true, emoji: '🇪🇺', label: 'Yes' },
                            { value: false, emoji: '🌐', label: 'No' },
                        ].map((opt) => (
                            <button
                                key={String(opt.value)}
                                type="button"
                                onClick={() => onIsEuChange(opt.value)}
                                className={`flex flex-1 items-center justify-center gap-2 rounded-[10px] border-[1.5px] px-3.5 py-2.5 text-sm font-semibold transition-all ${
                                    isEu === opt.value
                                        ? 'border-primary bg-accent-soft text-primary'
                                        : 'border-border bg-card hover:border-primary/30'
                                }`}
                            >
                                <span>{opt.emoji}</span>
                                {opt.label}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {isEu === false && (
                <div className="mt-4 animate-in rounded-xl border border-dashed border-border bg-card p-4 fade-in slide-in-from-bottom-2">
                    <div className="mb-1 text-sm font-semibold">
                        How did you enter Germany?
                    </div>
                    <p className="mb-3 text-xs text-primary">
                        Why we ask: this sets your real permit deadline — the
                        90-day clock and the visa-expiry clock are different
                        animals.
                    </p>
                    <div className="flex flex-col gap-2">
                        {entryModes.map((opt) => (
                            <button
                                key={opt.value}
                                type="button"
                                onClick={() => onEntryModeChange(opt.value)}
                                className={`rounded-[10px] border-[1.5px] px-3.5 py-3 text-left transition-all ${
                                    entryMode === opt.value
                                        ? 'border-primary bg-accent-soft'
                                        : 'border-border bg-card hover:border-primary/30'
                                }`}
                            >
                                <span className="text-sm font-semibold">
                                    {opt.emoji} {opt.label}
                                </span>
                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                    {opt.subtitle}
                                </span>
                            </button>
                        ))}
                    </div>
                    {entryMode === 'd_visa' && (
                        <div className="mt-3">
                            <label className="flex flex-wrap items-center gap-2 text-[13px] font-semibold">
                                When does your visa expire?
                                <input
                                    type="date"
                                    value={visaExpiresAt}
                                    onChange={(e) =>
                                        onVisaExpiresAtChange(e.target.value)
                                    }
                                    className="rounded-[10px] border-[1.5px] border-border bg-card px-3 py-2 text-sm font-normal outline-none focus:border-primary"
                                />
                            </label>
                            <p className="mt-1.5 text-xs text-muted-foreground">
                                Optional — but with it, your permit deadline
                                becomes a real countdown instead of a vague
                                warning.
                            </p>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
