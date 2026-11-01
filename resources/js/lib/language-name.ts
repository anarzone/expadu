/** The classifier's own codes, in words; any other code by its name. */
const LANGUAGE_WORDS: Record<string, string> = {
    none_needed: 'None needed',
    mixed: 'Mixed languages',
};

/** A language code in English words: "de" → "German". */
export function languageName(code: string | null | undefined): string | null {
    if (!code) {
        return null;
    }

    if (code in LANGUAGE_WORDS) {
        return LANGUAGE_WORDS[code];
    }

    try {
        return (
            new Intl.DisplayNames(['en'], { type: 'language' }).of(code) ?? code
        );
    } catch {
        return code;
    }
}
