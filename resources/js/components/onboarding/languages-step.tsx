const germanLevels = [
    { value: 'a1', label: 'A1 Beginner' },
    { value: 'a2', label: 'A2 Elementary' },
    { value: 'b1', label: 'B1 Intermediate' },
    { value: 'b2', label: 'B2 Upper-intermediate' },
    { value: 'c1', label: 'C1 Advanced' },
    { value: 'c2', label: 'C2 Mastery' },
    { value: 'none', label: 'None yet' },
];

const languages = [
    { value: 'english', emoji: '🇬🇧', label: 'English' },
    { value: 'turkish', emoji: '🇹🇷', label: 'Turkish' },
    { value: 'arabic', emoji: '🇸🇦', label: 'Arabic' },
    { value: 'spanish', emoji: '🇪🇸', label: 'Spanish' },
    { value: 'french', emoji: '🇫🇷', label: 'French' },
    { value: 'russian', emoji: '🇷🇺', label: 'Russian' },
    { value: 'portuguese', emoji: '🇵🇹', label: 'Portuguese' },
    { value: 'italian', emoji: '🇮🇹', label: 'Italian' },
    { value: 'azerbaijani', emoji: '🇦🇿', label: 'Azerbaijani' },
    { value: 'hindi', emoji: '🇮🇳', label: 'Hindi' },
    { value: 'mandarin', emoji: '🇨🇳', label: 'Mandarin' },
    { value: 'other', emoji: '➕', label: 'Other' },
];

export function LanguagesStep({
    germanLevel,
    speaks,
    onGermanLevelChange,
    onSpeaksChange,
}: {
    germanLevel: string;
    speaks: string[];
    onGermanLevelChange: (value: string) => void;
    onSpeaksChange: (value: string[]) => void;
}) {
    function toggleLanguage(value: string) {
        if (speaks.includes(value)) {
            onSpeaksChange(speaks.filter((l) => l !== value));
        } else {
            onSpeaksChange([...speaks, value]);
        }
    }

    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="py-2 pb-6">
                <h2 className="mb-2 font-display text-[26px] font-medium">
                    Your languages
                </h2>
                <p className="text-sm text-muted-foreground">
                    We'll match you with the best language exchange partners.
                </p>
            </div>

            <div className="mb-5">
                <div className="mb-2.5 text-xs font-bold tracking-[0.07em] text-muted-foreground uppercase">
                    Your German level
                </div>
                <div className="flex flex-wrap gap-2">
                    {germanLevels.map((level) => (
                        <button
                            key={level.value}
                            type="button"
                            onClick={() => onGermanLevelChange(level.value)}
                            className={`rounded-full border-[1.5px] px-3.5 py-[7px] text-[13px] font-medium transition-all ${
                                germanLevel === level.value
                                    ? 'border-primary bg-primary text-white'
                                    : 'border-border bg-card text-foreground hover:border-primary/30'
                            }`}
                        >
                            {level.label}
                        </button>
                    ))}
                </div>
            </div>

            <div>
                <div className="mb-2.5 text-xs font-bold tracking-[0.07em] text-muted-foreground uppercase">
                    Languages you speak (for exchange)
                </div>
                <div className="flex flex-wrap gap-2">
                    {languages.map((lang) => (
                        <button
                            key={lang.value}
                            type="button"
                            onClick={() => toggleLanguage(lang.value)}
                            className={`rounded-full border-[1.5px] px-3.5 py-[7px] text-[13px] font-medium transition-all ${
                                speaks.includes(lang.value)
                                    ? 'border-primary bg-primary text-white'
                                    : 'border-border bg-card text-foreground hover:border-primary/30'
                            }`}
                        >
                            {lang.emoji} {lang.label}
                        </button>
                    ))}
                </div>
            </div>
        </div>
    );
}
