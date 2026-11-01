<?php

namespace App\Support;

/**
 * Whether a listing says, in its own words, that the event is off. Plain
 * rules over the source text, no AI, and conservative: a cancelled show
 * left on Events misleads, but so does a live one taken off for a word
 * like "Eintritt entfällt" (free entry).
 */
final class EventCancellation
{
    private const SUBJECT = '(?:Veranstaltung|Konzert|Show|Vorstellung|Termin|Lesung|Party|Auftritt|Aufführung|Führung|Event)';

    /** A date or year after "verschoben" means it moved; this listing may be the new date. */
    private const NEW_DATE = '/\d{1,2}\.\s?\d{1,2}\.|\b20\d{2}\b/u';

    public static function said(string $title, ?string $description): bool
    {
        return self::titleSays($title) || self::descriptionSays((string) $description);
    }

    private static function titleSays(string $title): bool
    {
        if (preg_match('/\b(abgesagt|cancell?ed|entfällt)\b/iu', $title)
            && ! preg_match('/\b(Eintritt|\w*gebühr|Abendkasse|Kosten|Anmeldung|Pause)\s+entfällt\b/iu', $title)) {
            return true;
        }

        if (preg_match('/\bfällt\b.{0,30}\baus\b/iu', $title)) {
            return true;
        }

        return preg_match('/\bverschoben\b/iu', $title) === 1 && preg_match(self::NEW_DATE, $title) !== 1;
    }

    private static function descriptionSays(string $description): bool
    {
        foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $description) ?: [] as $sentence) {
            if (preg_match('/\b'.self::SUBJECT.'\s+(ist|wird|wurde)\s+(leider\s+)?abgesagt\b/iu', $sentence)) {
                return true;
            }

            if (preg_match('/\b'.self::SUBJECT.'\s+(ist|wird|wurde)\s+(leider\s+)?verschoben\b(.*)$/iu', $sentence, $moved)
                && preg_match(self::NEW_DATE, $moved[3]) !== 1) {
                return true;
            }

            if (preg_match('/\b'.self::SUBJECT.'\b.{0,60}\bfällt\b.{0,40}\baus\b/iu', $sentence)) {
                return true;
            }
        }

        return false;
    }
}
