<?php

namespace App\Support;

/**
 * A tip that hedges, or names a language the listing never mentions, is a
 * guess dressed as a fact, so it isn't shown. Checked when the classifier
 * writes a tip and again when one is shown, so tips written before the check
 * existed never reach a person either.
 */
final class EventTipGuard
{
    private const LANGUAGE_WORDS = '/\b(german|english|deutsch\w*|englisch\w*|language|sprache|spoken|speak\w*)\b/iu';

    private const HEDGES = '/\b(appears?|apparently|probably|likely|seems?|presumably|might|may well|perhaps|possibly|as far as)\b/i';

    public static function grounded(mixed $tip, string $source): ?string
    {
        $tip = is_string($tip) ? trim($tip) : '';

        if ($tip === ''
            || preg_match(self::HEDGES, $tip)
            || (preg_match(self::LANGUAGE_WORDS, $tip) && ! preg_match(self::LANGUAGE_WORDS, $source))) {
            return null;
        }

        return $tip;
    }
}
