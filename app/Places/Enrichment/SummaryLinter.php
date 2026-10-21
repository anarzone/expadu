<?php

namespace App\Places\Enrichment;

use Illuminate\Support\Facades\DB;

/**
 * Throws away a place description that says more than the data does. It
 * returns the reason for the first rule broken, or null when it passes.
 *
 * Always rejected: over 200 characters, any digit, a currency, a URL, a
 * weekday, a month, a clock time, hedging, comments about the data, hard
 * needs (accessibility is a code filter, never prose), hours, prices,
 * praise, and location claims. Rejected unless the input says so too:
 * history, water, views, meals, time of day, seasons, children, dogs, work,
 * events, diets, outdoor and indoor; quiet, cosy and lively need the
 * verified tag. A capitalised word must belong to the place's own name or
 * to its tags (cuisine=italian lets "Italian" through), so no other place,
 * street, person or brand is named.
 */
final class SummaryLinter
{
    public const MAX_LENGTH = 200;

    /** @var array<string, string> */
    private const ALWAYS = [
        'digit' => '/\d/u',
        'currency' => '/€|\$|£|\beuros?\b|\bEUR\b/iu',
        'url' => '/https?:|www\.|@|\b[\w-]+\.(de|com|org|net|eu|koeln|info)\b/iu',
        'weekday' => '/\b(mon|tues|wednes|thurs|fri|satur|sun)days?\b|\bweek(end|day|ly)s?\b|\b(daily|nightly)\b|\b(montag|dienstag|mittwoch|donnerstag|freitag|samstag|sonntag|wochenende)\b/iu',
        'clock' => '/\b(noon|midday|midnight|o\'clock|a\.m\.|p\.m\.|am|pm)\b/iu',
        'hedge' => '/\b(probably|likely|perhaps|maybe|might|may|possibly|presumably|apparently|appears?|seems?|reportedly|could|should)\b/iu',
        'meta' => '/\b(osm|openstreetmap|tags?|tagged|data|listed|listing|information|info|details?|unknown|unspecified|not (specified|stated|known|mentioned)|no further|source)\b/iu',
        'hard_need' => '/\b(wheelchairs?|accessib\w*|step-?free|barrier-?free|ramps?|lifts?|elevators?|toilets?|restrooms?|parking)\b/iu',
        'hours' => '/\bopen(s|ed)?\s+(daily|late|until|till|from|on|every|all|around|at|during|year|today|now|round|weekdays?|weekends?)\b|\bopening (hours|times)\b|\bclos(ed|es|ing|ure)\b|\bhours?\b|\ball (day|night)\b|\baround the clock\b/iu',
        'price' => '/\b(prices?|priced|pricey|cheap\w*|expensive|affordable|inexpensive|budget|costs?|paid|fees?|tickets?|admission|entry|bargain)\b|(?<![-\w])free\b/iu',
        'praise' => '/\b(popular|famous|renowned|best|beloved|favou?rites?|iconic|must-(see|visit)|award\w*|rated|ratings?|reviews?|stars?|michelin|acclaimed|finest|perfect|ideal|great|excellent|amazing|wonderful|stunning|beautiful|lovely|delightful|charming|unique|gem|trendy|hip|stylish|romantic|authentic|welcoming|relax(ed|ing)|laid-back|inviting|vibes?|atmospher\w*|ambien\w*|delicious|tasty|nice)\b|(?<!-)\bfriendly\b/iu',
        'location' => '/\b(near|nearby|next to|close to|heart of|cent(re|er)|central|downtown|district|neighbou?rhood|quarter|veedel|located|situated|minutes?|walk(ing)? (distance|from)|km|kilomet\w*|met(er|re)s?|miles?)\b/iu',
    ];

    /** Case-sensitive: "May" and "Sun" are months and days, "may" and "sun" are not. */
    private const MONTHS = '/\b(January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sept?|Oct|Nov|Dec|Mon|Tue|Tues|Wed|Thu|Thurs|Fri|Sat|Sun|Januar|Februar|März|Mai|Juni|Juli|Oktober|Dezember)\b/u';

    /** @var array<string, array{0: string, 1: string}> description words => what the input must contain */
    private const GROUNDED = [
        'history' => ['/\b(histor\w*|centur\w*|medieval|romans?|ancient|oldest|founded|built|heritage|monuments?|memorials?|ruins?|castles?)\b/iu', '/histor|heritage|denkmal|monument|memorial|mittelalter|medieval|röm|roman|antik|ancient|castle|burg|city_gate|ruin|archaeolog/u'],
        'water' => ['/\b(rivers?|riverside|rhine|lakes?|lakeside|water\w*|ponds?|canals?|harbou?rs?|beach\w*|shores?|swim\w*|pools?)\b/iu', '/water|river|rhein|rhine|lake|\bsee\b|weiher|teich|pond|canal|kanal|hafen|harbou?r|beach|strand|ufer|marina|swim|pool/u'],
        'view' => ['/\b(views?|viewpoints?|panoram\w*|skyline|overlook\w*|vista)\b/iu', '/viewpoint|aussicht|ausblick|blick|view|panorama|skyline|observation/u'],
        'meals' => ['/\b(breakfast|brunch|lunch|dinner|supper)\b/iu', '/breakfast|brunch|lunch|dinner|frühstück|mittag|abend/u'],
        'daytime' => ['/\b(morning\w*|evening\w*|night\w*|late|early|daytime|after dark)\b/iu', '/morning|evening|night|morgen|abend|nacht|\blit=yes/u'],
        'season' => ['/\b(summer\w*|winter\w*|spring|autumn|seasonal\w*)\b/iu', '/sommer|summer|winter|frühling|spring|herbst|autumn|saison|season/u'],
        'children' => ['/\b(kids?|child\w*|famil\w*|toddlers?|bab(y|ies)|parents?)\b/iu', '/kinder|kids|child|famil|baby|babies|eltern|playground|spielplatz|changing_table|highchair|kids_area|zoo|toddler/u'],
        'dogs' => ['/\bdogs?\b/iu', '/dog|hund/u'],
        'work' => ['/\b(wi-?fi|wlan|internet|laptops?|remote work\w*|co-?working|desks?|study|studying)\b/iu', '/internet|wlan|wifi|coworking|laptop|arbeit|library|study|lernen/u'],
        'events' => ['/\b(music|concerts?|live|dj|danc\w*|parties|party|events?|exhibitions?|exhibits?|performances?|readings?|screenings?|films?|movies?)\b/iu', '/music|musik|concert|konzert|live|dj|tanz|dance|party|event|veranstalt|exhibit|ausstell|perform|lesung|reading|theatre|theater|cinema|kino|film|museum|gallery|arts_centre|nightclub/u'],
        'outdoor' => ['/\b(terraces?|gardens?|outdoors?|outside|patio|beer garden|al fresco|open[- ]air|sunny|shad(e|y))\b/iu', '/outdoor|terrass|terrace|garten|garden|biergarten|park|draußen|outside|patio|freiluft/u'],
        'indoor' => ['/\b(indoors?|inside|sheltered|covered|rainy)\b/iu', '/indoor|covered|innen|drinnen|halle|überdacht/u'],
    ];

    /** Diet words: each must appear (by its first four letters) in the input. */
    private const DIET = '/\b(vegan\w*|vegetarian\w*|veggie\w*|halal|kosher|gluten\w*|lactose\w*|organic|bio)\b/iu';

    /** @var array<string, string> description words => the verified tag they need */
    private const VIBES = [
        'quiet' => '/\b(quiet\w*|calm\w*|peaceful\w*|tranquil\w*|serene|secluded|silent)\b/iu',
        'cosy' => '/\b(cos(y|ier|iness)|coz(y|ier|iness)|snug|homely|homey|intimate)\b/iu',
        'lively' => '/\b(lively|vibrant|bustling|buzzing|busy|energetic)\b/iu',
    ];

    /** A sentence about the data rather than the place ("The data gives no further detail."). */
    private const ASIDE = '/\b(data|osm|openstreetmap|source|text|tags?|tagged|listed|listing|says nothing|no further|nothing (more|further|else)|no other details?|not (given|stated|specified|mentioned|known)|(is|are) given|details? (is|are) (available|given))\b/iu';

    /** @var list<string>|null lowercased city and Veedel names */
    private ?array $placeNames = null;

    /**
     * Drops sentences about the data itself; the rest is still checked.
     */
    public static function withoutAsides(string $summary): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+(?=\p{Lu})/u', trim($summary)) ?: [];

        return trim(implode(' ', array_filter($sentences, fn (string $sentence): bool => preg_match(self::ASIDE, $sentence) !== 1)));
    }

    /** @param list<string> $verifiedTags */
    public function check(string $summary, PlaceEnrichmentInput $input, array $verifiedTags): ?string
    {
        if (mb_strlen($summary) > self::MAX_LENGTH) {
            return 'too_long';
        }

        // The place's own name may hold digits or words the rules forbid.
        $text = trim((string) preg_replace('/\s+/u', ' ', str_ireplace($input->name, ' ', $summary)));

        foreach (self::ALWAYS as $reason => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $reason;
            }
        }
        if (preg_match(self::MONTHS, $text) === 1) {
            return 'date';
        }

        $haystack = $input->haystack();
        foreach (self::GROUNDED as $concept => [$said, $given]) {
            if (preg_match($said, $text) === 1 && preg_match($given, $haystack) !== 1
                && ! ($concept === 'indoor' && in_array('indoors', $verifiedTags, true))
                && ! ($concept === 'children' && array_intersect(['family_friendly', 'kid_play'], $verifiedTags) !== [])) {
                return 'ungrounded:'.$concept;
            }
        }
        if (preg_match_all(self::DIET, $text, $diets)) {
            foreach ($diets[0] as $word) {
                if (! str_contains($haystack, mb_substr(mb_strtolower($word), 0, 4))) {
                    return 'ungrounded:diet';
                }
            }
        }
        foreach (self::VIBES as $tag => $pattern) {
            if (preg_match($pattern, $text) === 1 && ! in_array($tag, $verifiedTags, true)) {
                return 'ungrounded:'.$tag;
            }
        }

        return $this->namesOthers($text, $input) ? 'name' : null;
    }

    /**
     * Another name: the city or a Veedel anywhere; a capitalised word
     * inside a sentence, or any word with ä/ö/ü/ß, that is neither in the
     * place's name nor in its tags (a street, a place, a person, a brand).
     */
    private function namesOthers(string $text, PlaceEnrichmentInput $input): bool
    {
        $known = [];
        foreach ([$input->name, $input->categoryLabel, ...array_values($input->sentTags())] as $source) {
            foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($source)) ?: [] as $token) {
                $known[$token] = true;
            }
        }

        $lowerText = mb_strtolower($text);
        foreach ($this->placeNames() as $place) {
            if (preg_match('/(?<!\p{L})'.preg_quote($place, '/').'(?!\p{L})/u', $lowerText) === 1 && ! str_contains(mb_strtolower($input->name), $place)) {
                return true;
            }
        }

        preg_match_all('/[\p{L}][\p{L}\'’]*/u', $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$word, $offset]) {
            $lower = mb_strtolower(rtrim($word, '\'’'));
            $german = preg_match('/[äöüß]/iu', $word) === 1;
            if ((! $german && preg_match('/^\p{Lu}/u', $word) !== 1) || isset($known[$lower])
                || (preg_match('/^(\p{L}+)[\'’]s$/u', $word, $possessive) === 1 && isset($known[mb_strtolower($possessive[1])]))) {
                continue;
            }
            // An ordinary word opening a sentence ("Suited to children") is capitalised too.
            $before = rtrim(substr($text, 0, $offset));
            if (! $german && ($before === '' || preg_match('/[.!?:;]$/u', $before) === 1)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** @return list<string> */
    private function placeNames(): array
    {
        return $this->placeNames ??= array_values(array_unique(array_filter([
            'cologne', 'köln', 'koeln', 'germany', 'deutschland', 'rhineland', 'rheinland',
            ...DB::table('veedels')->pluck('name')->map(fn ($name): string => mb_strtolower(trim((string) $name)))->all(),
        ], fn (string $name): bool => mb_strlen($name) >= 4)));
    }
}
