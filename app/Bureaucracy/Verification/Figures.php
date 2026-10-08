<?php

namespace App\Bureaucracy\Verification;

/**
 * Finds the checkable facts in English card text and German source text:
 * durations, money, section references, language levels and bare numbers.
 * Values are canonical so "two weeks", "14 days" and "zwei Wochen" compare equal.
 *
 * Works on SourceText::key() output; offsets are byte offsets into that string.
 */
final class Figures
{
    private const EnglishUnits = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9];

    private const EnglishTeens = ['ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15,
        'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19];

    private const EnglishTens = ['twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90];

    private const GermanUnits = ['ein' => 1, 'zwei' => 2, 'drei' => 3, 'vier' => 4, 'fünf' => 5, 'sechs' => 6, 'sieben' => 7, 'acht' => 8, 'neun' => 9];

    private const GermanTeens = ['zehn' => 10, 'elf' => 11, 'zwölf' => 12, 'dreizehn' => 13, 'vierzehn' => 14, 'fünfzehn' => 15,
        'sechzehn' => 16, 'siebzehn' => 17, 'achtzehn' => 18, 'neunzehn' => 19];

    private const GermanTens = ['zwanzig' => 20, 'dreißig' => 30, 'vierzig' => 40, 'fünfzig' => 50, 'sechzig' => 60, 'siebzig' => 70, 'achtzig' => 80, 'neunzig' => 90];

    /** @var array<string, int>|null */
    private static ?array $words = null;

    /**
     * @return list<array{kind: string, value: string, start: int, end: int}>
     */
    public static function in(string $key): array
    {
        $found = [];
        $take = function (string $pattern, callable $value) use ($key, &$found): void {
            preg_match_all($pattern, $key, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                [$text, $start] = $match[0];
                $end = $start + strlen($text);
                foreach ($found as $existing) {
                    if ($start < $existing['end'] && $end > $existing['start']) {
                        continue 2;
                    }
                }
                $figure = $value($match);
                if ($figure !== null) {
                    $found[] = [...$figure, 'start' => $start, 'end' => $end];
                }
            }
        };
        $number = '\d+(?:[.,]\d+)*';
        $words = implode('|', array_map(fn ($word) => preg_quote($word, '/'), array_keys(self::words())));
        $money = '(?:€|eur\b|euro\b|euros\b)';
        // Amounts may group thousands with a space, as German law pages do ("25 000 Euro").
        $amount = '\d{1,3}(?:[ \x{00A0}]\d{3})+(?:[.,]\d{1,2})?|'.$number;

        $take("/(*UCP){$money}\s?({$amount})|({$amount})\s?{$money}/u", fn ($m) => ['kind' => 'money', 'value' => self::amount($m[1][0] !== '' ? $m[1][0] : $m[2][0])]);
        $take('/(*UCP)(?:§§?|\bsections?\s)\s*(\d+[a-z]?)\b(?:\s*\(\d+[a-z]?\))*(?:\s*(?:abs\.|absatz)\s*\d+)?(?:\s*(?:satz|nr\.|nummer)\s*\d+)*/u',
            fn ($m) => ['kind' => 'section', 'value' => $m[1][0]]);
        $take("/(*UCP)\b({$number}|{$words}|an|a)(?:\s+|-)(working[\s-]days?|business[\s-]days?|werktag(?:e|en|es)?|arbeitstag(?:e|en|es)?|days?|tag(?:e|en|es)?|weeks?|woche(?:n)?|fortnights?|months?|monat(?:e|en|s)?|years?|jahr(?:e|en|es)?|hours?|stunde(?:n)?)\b/u",
            fn ($m) => self::duration($m[1][0], $m[2][0]));
        $take('/(*UCP)\bfortnight\b/u', fn () => ['kind' => 'duration', 'value' => 'days:14']);
        $take('/(*UCP)\b[abc] ?[12]\b/u', fn ($m) => ['kind' => 'level', 'value' => str_replace(' ', '', $m[0][0])]);
        $take("/{$number}/u", fn ($m) => ['kind' => 'number', 'value' => self::amount($m[0][0])]);

        usort($found, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $found;
    }

    /** @return list<string> canonical "kind:value" tokens */
    public static function tokens(string $key): array
    {
        return array_values(array_unique(array_map(fn ($figure) => $figure['kind'].':'.$figure['value'], self::in($key))));
    }

    private static function duration(string $amount, string $unit): ?array
    {
        $count = ctype_digit($amount) ? (int) $amount : (in_array($amount, ['a', 'an'], true) ? 1 : (self::words()[$amount] ?? null));
        if ($count === null) {
            return null;
        }
        $unit = preg_replace('/[\s-]+/', ' ', $unit) ?? $unit;

        return ['kind' => 'duration', 'value' => match (true) {
            str_starts_with($unit, 'working') || str_starts_with($unit, 'business') || str_starts_with($unit, 'werktag') || str_starts_with($unit, 'arbeitstag') => 'workdays:'.$count,
            str_starts_with($unit, 'day') || str_starts_with($unit, 'tag') => 'days:'.$count,
            str_starts_with($unit, 'week') || str_starts_with($unit, 'woche') => 'days:'.($count * 7),
            str_starts_with($unit, 'fortnight') => 'days:'.($count * 14),
            str_starts_with($unit, 'month') || str_starts_with($unit, 'monat') => 'months:'.$count,
            str_starts_with($unit, 'year') || str_starts_with($unit, 'jahr') => 'months:'.($count * 12),
            default => 'hours:'.$count,
        }];
    }

    /** "1.000", "1,000" and "1 000" are one thousand; a final ",50" or ".50" is cents. */
    private static function amount(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{1,3}(?:[.,\s]\d{3})+)(?:[.,](\d{1,2}))?$/', $raw, $m)) {
            $whole = preg_replace('/\D/', '', $m[1]);

            return isset($m[2]) ? $whole.'.'.str_pad($m[2], 2, '0') : $whole;
        }
        if (preg_match('/^(\d+)[.,](\d{1,2})$/', $raw, $m)) {
            return $m[1].'.'.str_pad($m[2], 2, '0');
        }

        return ltrim(preg_replace('/\D/', '', $raw) ?? $raw, '0') ?: '0';
    }

    /** @return array<string, int> */
    private static function words(): array
    {
        if (self::$words !== null) {
            return self::$words;
        }
        $words = [...self::EnglishUnits, ...self::EnglishTeens, ...self::EnglishTens];
        foreach (self::EnglishTens as $ten => $tenValue) {
            foreach (self::EnglishUnits as $unit => $unitValue) {
                $words["{$ten}-{$unit}"] = $tenValue + $unitValue;
                $words["{$ten} {$unit}"] = $tenValue + $unitValue;
            }
        }
        $words = [...$words, ...self::GermanUnits, ...self::GermanTeens, ...self::GermanTens];
        foreach (['eine', 'einem', 'einen', 'einer', 'eines'] as $article) {
            $words[$article] = 1;
        }
        foreach (self::GermanTens as $ten => $tenValue) {
            foreach (self::GermanUnits as $unit => $unitValue) {
                $words["{$unit}und{$ten}"] = $tenValue + $unitValue;
            }
        }
        // Longest first, so "twenty-one" wins over "twenty" and "einundzwanzig" over "ein".
        uksort($words, fn ($a, $b) => strlen($b) <=> strlen($a));

        return self::$words = $words;
    }
}
