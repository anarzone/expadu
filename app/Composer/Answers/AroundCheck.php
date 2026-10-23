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
    /** Longest sentence kept, in characters: the model may write two short ones as one (the answer stays within 50 words). */
    public const MAX_LENGTH = 300;

    /** Capitalised words that name nothing to check: the speaker, the app and its parts, the city, days and months. */
    private const ALWAYS = ['i', "i'm", "i'll", "i've", "i'd", 'expadu', 'composer', 'paperwork', 'travel', 'events', 'cologne', 'köln',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', 'ok'];

    /** Ordinary words a sentence may start with. */
    private const STARTERS = ['a', 'all', 'also', 'and', 'any', 'anything', 'as', 'at', 'bring', 'but', 'by', 'can', 'catch', 'check', 'come', 'could', 'do',
        'enjoy', 'evening', 'fancy', 'feel', 'for', 'from', 'get', 'go', 'good', 'grab', 'great', 'happy', 'have', 'head', 'hello', 'here', "here's", 'hey', 'hi',
        'how', 'if', 'in', 'it', "it's", 'join', 'just', 'keep', 'let', "let's", 'looking', 'make', 'maybe', 'meanwhile', 'no', 'not', 'nothing', 'now', 'on',
        'one', 'or', 'otherwise', 'out', 'perfect', 'plan', 'quiet', 'right', 'since', 'so', 'some', 'something', 'sorry', 'sounds', 'still', 'take',
        'tell', 'thanks', 'that', "that's", 'the', 'then', 'there', "there's", 'these', 'this', 'tonight', 'today', 'tomorrow', 'try', 'unfortunately',
        'want', 'well', 'what', "what's", 'when', 'where', 'whether', 'which', 'while', 'who', 'why', 'with', 'would', 'yes', 'you', "you're", "you'll", 'your',
        // A question back ("Which one do you mean?", "Did you mean…", "Shall I plan it?").
        'did', 'do', 'does', 'is', 'are', 'shall', 'should', 'leave', 'walk', 'ride',
        // Order and place words a sentence starts with ("After that…", "Near you…", "Everything I found…").
        'after', 'afterwards', 'before', 'first', 'next', 'later', 'finally', 'near', 'nearby', 'close', 'closest', 'nearest', 'everything',
        'both', 'each', 'either', 'neither', 'only', 'two', 'three', 'earlier', 'instead', 'to', 'they', 'we',
        // Kinds of place a sentence may start with ("Places can't be filtered…", "Cafés near you…").
        'places', 'restaurants', 'cafés', 'cafes', 'bars', 'pubs', 'libraries', 'museums', 'parks', 'playgrounds', 'pitches', 'gyms',
        'shops', 'bakeries', 'pharmacies', 'supermarkets', 'events', 'concerts', 'listings'];

    /** Claims a sentence may make only when its items make them too. */
    private const CLAIMS = ['/\bfree\b/u', '/\bcheap\w*/u', '/\bexpensive\b/u', '/\bsold[ -]?out\b/u', '/\btickets?\b/u', '/€|\beuros?\b/u',
        '/\bsun\w*/u', '/\brain\w*/u', '/\bdry\b/u', '/\bcold\w*/u', '/\bwarm\w*/u', '/\bhot\b/u', '/\bchilly\b/u', '/\bwind\w*/u', '/\bsnow\w*/u',
        '/\bstorm\w*/u', '/\bcloud\w*/u', '/\bovercast\b/u', '/\bfog\w*/u', '/\bclear\b/u', '/\bthunder\w*/u',
        '/\bdelay\w*/u', '/\bcancel\w*/u', '/\bdivert\w*/u', '/\bstrikes?\b/u', '/\bclosed\b/u', '/\bcrowd\w*/u', '/\bbusy\b/u',
        '/\bpopular\b/u', '/\bfamous\b/u', '/\bbest\b/u', '/\biconic\b/u', '/\blegendary\b/u', '/\baward\w*/u', '/\bpremiere\b/u',
        '/\boutdoors?\b/u', '/\bindoors?\b/u', '/\bopen[ -]air\b/u', '/\blast chance\b/u', '/\bfamil(y|ies)\b/u', '/\bkids?\b/u', '/\bchildren\b/u',
        // Access is a fact only a listing can state (the assistant called a museum "step-free", 22 Oct 2026).
        '/\bstep[- ]?free\b/u', '/\bwheelchair\w*/u', '/\baccessib\w*/u', '/\bbarrier[- ]?free\b/u', '/\blifts?\b/u',
    ];

    /**
     * Facts the places don't hold yet, and "open" without listed hours (owner's test, 23 Oct 2026): a
     * sentence may say them only as its results do, or say they aren't listed or known ("wifi isn't
     * listed", "I can't confirm which are open late").
     */
    private const UNLISTED = '/\b(wi-?fi|laptops?|sockets?|vegan|vegetarian|gluten[- ]free|live music|outdoor seating|terrace|open (right )?now|open (all day|late|24))\b/u';

    private const NOT = '/\b(not|no|neither|nor|never|isn\'t|aren\'t|can\'t|cannot|don\'t|doesn\'t|unknown|without)\b/u';

    /** Counts are claims too: kept only as the number of things the sentence itself names (`$named`). */
    private const COUNTS = '/\b(two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|dozens?|hundreds?|thousands?)\b/u';

    private const NUMBERS = ['two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6];

    /** Hedged facts aren't facts. */
    private const HEDGES = '/\b(might|probably|likely|usually|often|typically|apparently|reportedly|rumou?red|supposedly)\b/u';

    /** The person's own day: the AI is never sent it, so it can't speak of it. Only "your evening", "your umbrella" and the like. */
    private const PERSONAL = '/(?<!plan )\byour (?!(evening|night|afternoon|morning|weekend|umbrella|jacket|coat|way|home)\b)|\byou have (a|an|no|nothing|\d)|\byou\'re (meeting|seeing|due|booked)\b/u';

    /**
     * @param  string  $sentence  one sentence as written
     * @param  string  $source  the text of the items it cites, plus the time line every sentence may use
     * @param  bool  $personal  it cites the person's own facts (their taste or their day, with the
     *                          opt-in), so it may speak of them, as softly as those facts are
     * @param  int  $named  how many listed things it may count: those it names, or its lookups found ("two events" among them)
     */
    public function holds(string $sentence, string $source, bool $personal = false, int $named = 0): bool
    {
        $sentence = trim(str_replace(['’', '‘'], "'", $sentence));
        $lower = mb_strtolower($sentence);
        $source = mb_strtolower(str_replace(['’', '‘'], "'", $source));

        if ($sentence === '' || mb_strlen($sentence) > self::MAX_LENGTH || preg_match('/https?:|www\.|[*_#`<>\n]/u', $sentence)
            || (! $personal && (preg_match(self::HEDGES, $lower) || preg_match(self::PERSONAL, $lower)))) {
            return false;
        }
        // A count holds when it's how many things the sentence names, or a number its results give ("3 places nearby").
        preg_match_all(self::COUNTS, $lower, $counts);
        if (collect($counts[0])->contains(fn (string $count): bool => (self::NUMBERS[$count] ?? PHP_INT_MAX) > $named
            && (! isset(self::NUMBERS[$count]) || preg_match('/\b'.self::NUMBERS[$count].'\b/u', $source) !== 1))) {
            return false;
        }

        // Every number and time, as written in the items.
        preg_match_all('/\d+(?:[:.,]\d+)*/u', $sentence, $numbers);
        preg_match_all('/\d+(?:[:.,]\d+)*/u', $source, $known);
        if (array_diff($numbers[0], $known[0]) !== []) {
            return false;
        }

        // An unlisted fact ("has Wi-Fi", "good for laptops") holds only where a source says it, not where
        // one says it isn't ("Laptop work isn't listed."); saying it isn't listed always holds.
        $said = implode("\n", array_filter(preg_split('/(?<=[.!?])\s+|\n/u', mb_strtolower($source)) ?: [], fn (string $part): bool => preg_match(self::NOT, $part) !== 1));
        // Each such fact by itself: Wi-Fi listed doesn't back "good for laptops".
        $fact = fn (string $phrase): string => preg_replace(['/wi-?fi/u', '/s$/u'], ['wifi', ''], $phrase);
        preg_match_all(self::UNLISTED, $said, $listed);
        $listed = array_map($fact, $listed[0]);
        preg_match_all(self::UNLISTED, $lower, $claimed);
        if (preg_match(self::NOT, $lower) !== 1 && collect($claimed[0])->contains(fn (string $phrase): bool => ! in_array($fact($phrase), $listed, true))) {
            return false;
        }
        foreach (self::CLAIMS as $claim) {
            if (preg_match($claim, $lower) && ! preg_match($claim, $source)) {
                return false;
            }
        }

        // The words of an unlisted fact it names ("Live music isn't listed") are words, not names.
        preg_match_all(self::UNLISTED, $lower, $phrases);
        $unlisted = array_merge([], ...array_map(fn (string $phrase): array => preg_split('/[\s-]+/u', $phrase), $phrases[0]));
        // Every name: a capitalised word the items hold, unless it's the speaker, the
        // app, the city, a day or month, or an ordinary word starting a sentence.
        preg_match_all("/\p{Lu}[\p{L}'-]*/u", $sentence, $names, PREG_OFFSET_CAPTURE);
        foreach ($names[0] as [$name, $offset]) {
            // "Today's" and "Cologne's" are the word itself.
            $word = preg_replace("/'s$/u", '', rtrim(mb_strtolower($name), "'-"));
            $before = rtrim(substr($sentence, 0, $offset), " \t\"'(“");
            $first = $before === '' || preg_match('/[.!?:;–—]$/u', $before) === 1;
            if (in_array($word, self::ALWAYS, true) || ($first && in_array($word, self::STARTERS, true)) || str_contains($source, $word) || in_array($word, $unlisted, true)) {
                continue;
            }

            return false;
        }

        return true;
    }
}
