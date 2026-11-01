<?php

namespace App\Support;

/**
 * A tip that hedges, or names a language the listing never mentions, is a
 * guess dressed as a fact, so it isn't shown. Checked when the classifier
 * writes a tip and again when one is shown, so tips written before the check
 * existed never reach a person either. Hand-written (manual) events are not
 * the model's guesses and are not checked here.
 */
final class EventTipGuard
{
    private const HEDGES = '/\b(appears?|apparently|probably|likely|seems?|presumably|may|maybe|might|could|perhaps|possibly|usually|often|typically|as far as)\b/iu';

    /** A specific language the tip claims, and what in the source backs it. */
    private const LANGUAGES = [
        'english' => ['/\b(english|englisch\w*)\b/iu', '/\b(english|englisch\w*)\b/iu'],
        'german' => ['/\b(german|deutsch(?!land)\w*)\b/iu', '/\b(german|deutsch(?!land)\w*)\b/iu'],
    ];

    /** Talk of language with no language named ("no language needed"). */
    private const ANY_LANGUAGE = '/\b(language|languages|sprache\w*|spoken|speak\w*)\b/iu';

    public static function grounded(mixed $tip, string $source): ?string
    {
        $tip = is_string($tip) ? trim($tip) : '';
        if ($tip === '' || preg_match(self::HEDGES, $tip)) {
            return null;
        }

        $named = false;
        foreach (self::LANGUAGES as [$claim, $evidence]) {
            if (preg_match($claim, $tip)) {
                $named = true;
                // Each language the tip names must be named in the listing itself.
                if (! preg_match($evidence, $source)) {
                    return null;
                }
            }
        }
        if (! $named && preg_match(self::ANY_LANGUAGE, $tip) && ! self::mentionsAnyLanguage($source)) {
            return null;
        }

        return $tip;
    }

    private static function mentionsAnyLanguage(string $source): bool
    {
        if (preg_match(self::ANY_LANGUAGE, $source)) {
            return true;
        }
        foreach (self::LANGUAGES as [, $evidence]) {
            if (preg_match($evidence, $source)) {
                return true;
            }
        }

        return false;
    }
}
