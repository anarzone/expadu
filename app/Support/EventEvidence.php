<?php

namespace App\Support;

use App\Models\Event;

/**
 * "Nothing can be fake" (owner, 21 Oct 2026): what the model wrote about an
 * event is shown only when the listing itself backs it. A chip or language
 * needs the listing to say so; a summary or translation needs every figure
 * it states to appear in the source. Without evidence, nothing is shown.
 * Hand-written (manual) events are ours and are not checked here.
 */
final class EventEvidence
{
    /** What in the listing backs each chip the classifier may give. */
    private const CHIP_EVIDENCE = [
        'English-friendly' => '/\b(english|englisch\w*)\b/iu',
        'newcomers welcome' => '/\b(newcomers?|neu in (köln|der stadt)|neuankömml\w*|zugezogen\w*|neubürger\w*|beginners?|anfänger\w*|einsteiger\w*|(alle|everyone|all) (sind )?(herzlich )?willkommen|everyone (is )?welcome|all welcome)\b/iu',
        'solo-OK' => '/\b(solo|allein\w*|alone|on your own|einzelpersonen|ohne begleitung)\b/iu',
    ];

    private const ENGLISH = '/\b(english|englisch\w*)\b/iu';

    private const GERMAN = '/\b(german|deutsch(?!land)\w*)\b/iu';

    private const NO_LANGUAGE_NEEDED = '/\b((ohne|keine) (deutsch|sprach)\w*|no (german|language)\w* (needed|required)|sprachunabhängig\w*|language[- ]free)\b/iu';

    public static function source(Event $event): string
    {
        return $event->title.' '.$event->description.' '.$event->price_text;
    }

    /**
     * @param  list<string>  $chips
     * @return list<string>
     */
    public static function chips(array $chips, string $source): array
    {
        return array_values(array_filter($chips, fn (string $chip): bool => isset(self::CHIP_EVIDENCE[$chip]) && preg_match(self::CHIP_EVIDENCE[$chip], $source) === 1));
    }

    public static function language(?string $language, string $source): ?string
    {
        $backed = match ($language) {
            'en' => preg_match(self::ENGLISH, $source) === 1,
            'de' => preg_match(self::GERMAN, $source) === 1,
            'mixed' => preg_match(self::ENGLISH, $source) === 1 && preg_match(self::GERMAN, $source) === 1,
            'none_needed' => preg_match(self::NO_LANGUAGE_NEEDED, $source) === 1,
            default => false,
        };

        return $backed ? $language : null;
    }

    /** The text when every figure it states is in the source; null otherwise. */
    public static function text(?string $text, string $source): ?string
    {
        $text = is_string($text) ? trim($text) : '';
        if ($text === '') {
            return null;
        }

        preg_match_all('/\d+/u', $source, $sourceNumbers);
        $known = array_flip(array_map(fn (string $n): string => ltrim($n, '0') ?: '0', $sourceNumbers[0]));
        preg_match_all('/\d+/u', $text, $numbers);
        foreach ($numbers[0] as $number) {
            // Zero adds nothing: "19:00" restates "19 Uhr".
            $number = ltrim($number, '0') ?: '0';
            if ($number !== '0' && ! isset($known[$number])) {
                return null;
            }
        }
        if (preg_match('/€|\beur(o|os)?\b/iu', $text) && ! preg_match('/€|\beur(o|os)?\b/iu', $source)) {
            return null;
        }

        return $text;
    }
}
