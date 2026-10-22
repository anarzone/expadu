<?php

namespace App\Composer\Answers;

/**
 * Whether one sentence the AI wrote for "around you now" holds only what its items
 * say (owner, 22 Oct 2026: nothing fake). A sentence keeps every number, time and
 * name from the items it cites, makes no claim (weather, price, crowds, delays) they
 * don't make, hedges nothing and never speaks of the person's own day, which the AI
 * is never sent. A sentence that fails is dropped, never repaired.
 */
final class AroundCheck
{
    /** Longest sentence kept, in characters. */
    public const MAX_LENGTH = 220;

    /** Capitalised words that name nothing to check: the speaker, the app and its parts, the city, days and months. */
    private const ALWAYS = ['i', "i'm", "i'll", "i've", "i'd", 'expadu', 'composer', 'paperwork', 'travel', 'events', 'cologne', 'köln',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', 'ok'];

    /** Ordinary words a sentence may start with. */
    private const STARTERS = ['a', 'all', 'also', 'and', 'any', 'anything', 'as', 'at', 'bring', 'but', 'by', 'can', 'catch', 'check', 'come', 'could', 'do',
        'enjoy', 'evening', 'fancy', 'feel', 'for', 'from', 'get', 'go', 'good', 'grab', 'great', 'happy', 'have', 'head', 'hello', 'here', "here's", 'hey', 'hi',
        'how', 'if', 'in', 'it', "it's", 'join', 'just', 'keep', 'let', "let's", 'looking', 'make', 'maybe', 'meanwhile', 'no', 'not', 'nothing', 'now', 'on',
        'one', 'or', 'otherwise', 'out', 'perfect', 'plan', 'quiet', 'right', 'so', 'some', 'something', 'sorry', 'sounds', 'still', 'take',
        'tell', 'thanks', 'that', "that's", 'the', 'then', 'there', "there's", 'these', 'this', 'tonight', 'today', 'tomorrow', 'try', 'unfortunately',
        'want', 'well', 'what', "what's", 'when', 'where', 'whether', 'while', 'why', 'with', 'yes', 'you', "you're", "you'll", 'your'];

    /** Claims a sentence may make only when its items make them too. */
    private const CLAIMS = ['/\bfree\b/u', '/\bcheap\w*/u', '/\bexpensive\b/u', '/\bsold[ -]?out\b/u', '/\btickets?\b/u', '/€|\beuros?\b/u',
        '/\bsun\w*/u', '/\brain\w*/u', '/\bdry\b/u', '/\bcold\w*/u', '/\bwarm\w*/u', '/\bhot\b/u', '/\bchilly\b/u', '/\bwind\w*/u', '/\bsnow\w*/u',
        '/\bstorm\w*/u', '/\bcloud\w*/u', '/\bovercast\b/u', '/\bfog\w*/u', '/\bclear\b/u', '/\bthunder\w*/u',
        '/\bdelay\w*/u', '/\bcancel\w*/u', '/\bdivert\w*/u', '/\bstrikes?\b/u', '/\bclosed\b/u', '/\bcrowd\w*/u', '/\bbusy\b/u',
        '/\bpopular\b/u', '/\bfamous\b/u', '/\bbest\b/u', '/\biconic\b/u', '/\blegendary\b/u', '/\baward\w*/u', '/\bpremiere\b/u',
        '/\boutdoors?\b/u', '/\bindoors?\b/u', '/\bopen[ -]air\b/u', '/\blast chance\b/u', '/\bfamil(y|ies)\b/u', '/\bkids?\b/u', '/\bchildren\b/u'];

    /** Counts are claims too; none is checked against the listings, so none is kept. */
    private const COUNTS = '/\b(two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|dozens?|hundreds?|thousands?)\b/u';

    /** Hedged facts aren't facts. */
    private const HEDGES = '/\b(might|probably|likely|usually|often|typically|apparently|reportedly|rumou?red|supposedly)\b/u';

    /** The person's own day: the AI is never sent it, so it can't speak of it. Only "your evening", "your umbrella" and the like. */
    private const PERSONAL = '/(?<!plan )\byour (?!(evening|night|afternoon|morning|weekend|umbrella|jacket|coat|way)\b)|\byou have (a|an|no|nothing|\d)|\byou\'re (meeting|seeing|due|booked)\b/u';

    /**
     * @param  string  $sentence  one sentence as written
     * @param  string  $source  the text of the items it cites, plus the time line every sentence may use
     */
    public function holds(string $sentence, string $source): bool
    {
        $sentence = trim(str_replace(['’', '‘'], "'", $sentence));
        $lower = mb_strtolower($sentence);
        $source = mb_strtolower(str_replace(['’', '‘'], "'", $source));

        if ($sentence === '' || mb_strlen($sentence) > self::MAX_LENGTH || preg_match('/https?:|www\.|[*_#`<>\n]/u', $sentence)
            || preg_match(self::HEDGES, $lower) || preg_match(self::PERSONAL, $lower) || preg_match(self::COUNTS, $lower)) {
            return false;
        }

        // Every number and time, as written in the items.
        preg_match_all('/\d+(?:[:.,]\d+)*/u', $sentence, $numbers);
        preg_match_all('/\d+(?:[:.,]\d+)*/u', $source, $known);
        if (array_diff($numbers[0], $known[0]) !== []) {
            return false;
        }

        foreach (self::CLAIMS as $claim) {
            if (preg_match($claim, $lower) && ! preg_match($claim, $source)) {
                return false;
            }
        }

        // Every name: a capitalised word the items hold, unless it's the speaker, the
        // app, the city, a day or month, or an ordinary word starting a sentence.
        preg_match_all("/\p{Lu}[\p{L}'-]*/u", $sentence, $names, PREG_OFFSET_CAPTURE);
        foreach ($names[0] as [$name, $offset]) {
            $word = rtrim(mb_strtolower($name), "'-");
            $before = rtrim(substr($sentence, 0, $offset), " \t\"'(“");
            $first = $before === '' || preg_match('/[.!?:;–—]$/u', $before) === 1;
            if (in_array($word, self::ALWAYS, true) || ($first && in_array($word, self::STARTERS, true))
                || str_contains($source, preg_replace("/'s$/u", '', $word))) {
                continue;
            }

            return false;
        }

        return true;
    }
}
