<?php

namespace App\Bureaucracy\Verification;

use Normalizer;

/**
 * One comparison form for official page text, quotes and card text, so a quote
 * matches its source regardless of markup, entities, soft hyphens, typographic
 * quotes or line breaks — and never because of anything looser than that.
 */
final class SourceText
{
    public static function fromHtml(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? '';
        // Tags become spaces so adjacent block elements never glue words together.
        $text = preg_replace('#<[^>]+>#', ' ', $html) ?? '';

        return self::clean(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Readable text with typography and whitespace unified; case is preserved. */
    public static function clean(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }
        $text = str_replace(["\u{00AD}", "\u{200B}", "\u{2060}", "\u{FEFF}"], '', $text);
        $text = str_replace(["\u{00A0}", "\u{2007}", "\u{2009}", "\u{202F}", "\u{2002}", "\u{2003}"], ' ', $text);
        $text = str_replace(['„', '“', '”', '‟', '«', '»', '″'], '"', $text);
        $text = str_replace(['‚', '‘', '’', '‛', '‹', '›', '′'], "'", $text);
        $text = str_replace(['–', '—', '‑', '−'], '-', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /** The comparison key: cleaned and case-folded. */
    public static function key(string $text): string
    {
        return mb_strtolower(self::clean($text), 'UTF-8');
    }

    public static function contains(string $haystack, string $needle): bool
    {
        $needle = self::key($needle);

        return $needle !== '' && str_contains(self::key($haystack), $needle);
    }
}
