<?php

namespace App\Marketing;

use Illuminate\Support\HtmlString;

/**
 * Where the long German words in guide titles may break. Big display titles
 * on a phone can't fit "Wohnungsgeberbescheinigung" on one line, and browsers
 * can't be trusted to hyphenate German inside an English page: without a
 * break point they split the word anywhere ("Kundenzent / ren"). Each word
 * here gets soft hyphens at its real joints, which only show when a line
 * actually breaks there.
 *
 * A new guide with a long German word in its title adds it here.
 */
final class GermanCompounds
{
    /** "|" marks each joint. */
    private const WORDS = [
        'Aufenthalts|erlaubnis',
        'Aufenthalts|titel',
        'Ausländer|behörde',
        'Bürger|amt',
        'Deutschland|ticket',
        'Ein|bürgerung',
        'Kranken|versicherung',
        'Kunden|zentren',
        'Kunden|zentrum',
        'Melde|bescheinigung',
        'Niederlassungs|erlaubnis',
        'Rundfunk|beitrag',
        'Staats|angehörigkeit',
        'Steuer|identifikations|nummer',
        'Wohnungs|geber|bescheinigung',
        'Wohnungs|geber|bestätigung',
    ];

    /** The text, escaped, with soft hyphens in the listed words. */
    public static function softHyphens(string $text): HtmlString
    {
        $html = e($text);

        foreach (self::WORDS as $word) {
            $html = str_replace(str_replace('|', '', $word), str_replace('|', '&shy;', $word), $html);
        }

        return new HtmlString($html);
    }
}
