<?php

namespace App\Transit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The stops a notice names, as the timetable (GTFS) names them: "Ubierring"
 * and "Bonn Hauptbahnhof" in KVB's words are "Köln Ubierring" and "Bonn
 * Hbf". Only names found in the notice's own text, never guessed. A bare
 * name ("Neumarkt") is the operator's city's stop; one in another city
 * ("Dransdorf", Bonn) counts only when the notice names that city too.
 * When the text marks its stops ("(H) Sechzigstr.", as KVB does), only
 * marked names count: "verlegt auf die Universitätsstr." is a street.
 */
final class NoticeStops
{
    /** A bare name shorter than this ("Hbf", "Post") is too common to count. */
    private const MIN_BARE = 6;

    /** What "(H)" (Haltestelle) becomes in the normalised text. */
    private const STOP = '§';

    /**
     * @param  string  $home  the operator's city as GTFS writes it ("Köln")
     * @return list<string>
     */
    public static function in(string $text, string $home): array
    {
        $said = ' '.self::normal($text).' ';
        $haystack = $said;
        $marked = str_contains($said, self::STOP);
        [$whole, $bare] = self::index();
        $found = [];

        // Whole names first, then bare ones; longest first, and a match is
        // taken out, so "Köln Neumarkt" isn't found again as "Neumarkt".
        foreach ($whole as $needle => $name) {
            if (self::take($haystack, (string) $needle, $marked)) {
                $found[$name] = true;
            }
        }
        foreach ($bare as $needle => $byCity) {
            $name = $byCity[$home] ?? null;
            if ($name === null) {
                $elsewhere = array_filter($byCity, fn (string $city): bool => self::names($said, self::normal($city)), ARRAY_FILTER_USE_KEY);
                $name = count($elsewhere) === 1 ? reset($elsewhere) : null;
            }
            if ($name !== null && self::take($haystack, (string) $needle, $marked)) {
                $found[$name] = true;
            }
        }

        return array_keys($found);
    }

    /** Whole words, and right after "(H)" when the text marks its stops. */
    private static function pattern(string $needle, bool $marked = false): string
    {
        return '/'.($marked ? '(?<='.self::STOP.' )' : '(?<![\p{L}\d])').preg_quote($needle, '/').'(?![\p{L}\d])/u';
    }

    /** Whether the text names it. */
    private static function names(string $haystack, string $needle, bool $marked = false): bool
    {
        return str_contains($haystack, $needle) && preg_match(self::pattern($needle, $marked), $haystack) === 1;
    }

    /** Finds it and blanks it out of the text. */
    private static function take(string &$haystack, string $needle, bool $marked): bool
    {
        if (! self::names($haystack, $needle, $marked)) {
            return false;
        }
        $haystack = (string) preg_replace(self::pattern($needle, $marked), ' ', $haystack);

        return true;
    }

    /**
     * Whole names, and bare names by city, each longest first.
     *
     * @return array{0: array<string, string>, 1: array<string, array<string, string>>}
     */
    private static function index(): array
    {
        // Kept a day; an empty timetable (before its import) isn't kept.
        $names = Cache::get('notice-stops:names');
        if (! is_array($names) || $names === []) {
            $names = DB::table('gtfs_stops')->distinct()->orderBy('stop_name')->pluck('stop_name')->map(fn ($n) => (string) $n)->all();
            if ($names !== []) {
                Cache::put('notice-stops:names', $names, now()->addDay());
            }
        }

        $whole = [];
        $bare = [];
        foreach ($names as $name) {
            $whole[self::normal($name)] = $name;
            $parts = explode(' ', $name, 2);
            if (count($parts) === 2 && mb_strlen($parts[1]) >= self::MIN_BARE) {
                $bare[self::normal($parts[1])][$parts[0]] = $name;
            }
        }
        // Keys that look like numbers come back as ints.
        $longest = fn ($a, $b): int => mb_strlen((string) $b) <=> mb_strlen((string) $a);
        uksort($whole, $longest);
        uksort($bare, $longest);

        return [$whole, $bare];
    }

    /** Lower case, "(H)" as a mark, Hauptbahnhof as Hbf and Straße as Str. */
    private static function normal(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/\(h\)\s*/u', ' '.self::STOP.' ', $text);
        $text = (string) preg_replace('/hauptbahnhof/u', 'hbf', $text);
        $text = (string) preg_replace('/stra(ß|ss)e\b/u', 'str.', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
