<?php

namespace App\Bureaucracy\Analyser;

use App\Bureaucracy\Verification\SourceText;

/** Text helpers shared by retrieval and verification. */
final class AnswerText
{
    /** Whitespace collapsed, nothing else changed: the form a quote is matched in. */
    public static function whitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /** Lower case with typography unified, for comparing words and phrases. */
    public static function key(string $text): string
    {
        return SourceText::key($text);
    }

    /** @return list<string> lower-case words, apostrophes kept inside words */
    public static function words(string $text): array
    {
        preg_match_all("/[\p{L}\p{N}]+(?:'[\p{L}]+)*/u", self::key($text), $matches);

        return $matches[0];
    }

    /** Light stem so "move", "moved" and "moving", or "card" and "cards", compare equal. */
    public static function stem(string $word): string
    {
        $word = preg_replace("/'s$/u", '', $word) ?? $word;
        foreach (['ing', 'ed', 'es', 's', 'e'] as $suffix) {
            if (str_ends_with($word, $suffix) && mb_strlen($word) - mb_strlen($suffix) >= 3) {
                return mb_substr($word, 0, mb_strlen($word) - mb_strlen($suffix));
            }
        }

        return $word;
    }

    /** Whether two words are the same word, allowing inflection ("registration", "registered"). */
    public static function sameWord(string $one, string $two): bool
    {
        if ($one === $two || self::stem($one) === self::stem($two)) {
            return true;
        }
        $shorter = min(mb_strlen($one), mb_strlen($two));
        if ($shorter < 5) {
            return false;
        }
        $common = 0;
        while ($common < $shorter && mb_substr($one, $common, 1) === mb_substr($two, $common, 1)) {
            $common++;
        }

        return $common >= max(5, (int) ceil(0.75 * $shorter));
    }
}
