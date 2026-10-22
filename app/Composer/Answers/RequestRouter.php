<?php

namespace App\Composer\Answers;

use App\Composer\HeuristicPromptParser;
use Carbon\CarbonImmutable;

/**
 * Which kind of answer a request wants, in the approved Composer's own order
 * (prototype/dev/design/composer-studio/model.js `respond()`): plain string rules,
 * no model, so the same words always get the same kind. The first rule that matches
 * wins, which is why "How do I get to my Blue Card appointment?" is a journey, not
 * paperwork, and "Is the cinema step-free?" is access, not events.
 *
 * It only reads the words and the few facts it's given (RouterContext); what each
 * kind then says is Answers'.
 */
final class RequestRouter
{
    /** Where "near" means: the appointment, or a named area. Before it, you're at the start. */
    private const ANCHORS = [
        'appointment' => '/\b(appointment|blue card|mulheim|muelheim|mülheim|wiener platz)\b/u',
        'rheinauhafen' => '/\brheinauhafen\b/u',
        'stadtgarten' => '/\bstadtgarten\b/u',
        'ehrenfeld' => '/\behrenfeld\b/u',
        'neumarkt' => '/\bneumarkt\b/u',
    ];

    /** Everyday places, in the mock's order (model.js nearbyKinds). */
    private const PLACES = [
        'pharmacy' => '/\b(pharmac(y|ies)|apotheke|chemist)\b/u',
        'bakery' => '/\b(baker(y|ies)|bread|croissants?)\b/u',
        'cafe' => '/\b(cafes?|coffee)\b/u',
        'grocery' => '/\b(grocer(y|ies)|supermarkets?|food shop)\b/u',
        'playground' => '/\bplaygrounds?\b/u',
        'library' => '/\b(librar(y|ies)|bibliothek)\b/u',
        'fastfood' => '/\b(fast food|takeaway|take-away|imbiss|quick bite|d(ö|o)ner|kebab)\b/u',
        // Parks plus woods, lakes and nature areas.
        'green' => '/\b(somewhere green|green spaces?|woods?|forest|nature)\b/u',
        'restaurant' => '/\b(restaurants?|dinner|lunch|(somewhere|place|bite) to eat)\b/u',
        'park' => '/\bparks?\b/u',
    ];

    /** What Expadu doesn't list yet (model.js services). A pharmacy is a place, above. */
    private const SERVICES = [
        'doctors' => '/\b(doctor|gp|hausarzt|physician)s?\b/u',
        'dentists' => '/\b(dentist|zahnarzt)s?\b/u',
        'pharmacies' => '/\b(pharmac(y|ies)|apotheke|chemist)\b/u',
        'banks' => '/\b(bank|bank account)s?\b/u',
        'tax' => '/\btax (advisor|adviser|return)s?\b/u',
        'lawyers' => '/\b(lawyer|legal advice)s?\b/u',
    ];

    private const TOPICS = [
        'music' => '/\b(music|concerts?|gigs?|live band)\b/u',
        'exhibition' => '/\b(exhibitions?|museums?|art|gallery|galleries)\b/u',
        'film' => '/\b(films?|cinema|movies?)\b/u',
        'football' => '/\b(football|match|bundesliga|fc)\b/u',
    ];

    private const CATEGORIES = [
        'family' => '/\b(kids?|children|child|family|playground)\b/u',
        'sport' => '/\b(table tennis|ping pong|kick ?about|pitch|sports?)\b/u',
        'swimming' => '/\b(swim|swimming|lake|bathing)\b/u',
    ];

    /**
     * A trip to a place named (owner's staging tests, 22 Oct 2026: "show me departures to go
     * Cafe Nova in City Center", "how can i go to the cafe nova?"). These words mean a trip
     * whatever the place's case, typos and all; "go to" and the like only with a name
     * (capitalised), so "go to the park" stays an outing.
     */
    private const TRIP_TO = [
        '/\b(?:take|bring|get|guide) me\s+(?:\S+\s+)?to\s+(?<place>.+)$/iu',
        '/\b(?:navigate|directions?|route|departures?|connections?)\s+(?:\S+\s+){0,2}?to\s+(?:go\s+(?:to\s+)?)?(?<place>.+)$/iu',
        '/(?<!on my )(?<!on the )\bway\s+(?:\S+\s+){0,2}?to\s+(?<place>.+)$/iu',
        '/\b(?:how|hw|hoe|hwo)\s+(?:\S+\s+){0,4}?(?:get|go|travel|come|walk|cycle|ride)\s+(?:over\s+)?to\s+(?<place>.+)$/iu',
        '/\b(?:how|hw)\s+(?:\S+\s+){0,3}?reach\s+(?<place>.+)$/iu',
        '/\bhow (?:far|long)\b(?:\s+(?:away|is|it|does it take|will it take|to|from here|from me))*\s+(?<place>.+)$/iu',
        '/\bhow many (?:minutes|mins|km|kilomet(?:re|er)s)\b(?:\s+(?:is it|does it take|will it take|away))?\s+(?:to|from here to)\s+(?<place>.+)$/iu',
    ];

    /** Asking how far or how long, not for the way (owner's staging test, 22 Oct 2026: "how far is it?"). */
    private const DISTANCE = '/\bhow (?:far|long)\b|\bhow many (?:minutes|mins|km|kilomet(?:re|er)s)\b|\bdistance\b/iu';

    private const GO_TO = '/\b(?:go|going|head|heading|travel|travelling|get)\s+to\s+(?<place>.+)$/iu';

    /** The place a trip was to, again: "how do I get there?", "how far is it?", "take me there". */
    private const AGAIN = '/^(?:and\s+|so\s+)?(?:(?:how|hw)\b.*\b(?:get|go|reach)\s+there|(?:take|bring|get) me there|directions(?:\s+there)?|how (?:far|long) (?:is it|away is it|does it take)(?:\s+(?:from here|to get there))?|how many (?:minutes|mins|km) (?:is it|does it take|away)|(?:the )?(?:way|route) there)\b[?!. ]*$/iu';

    /** A greeting and nothing else ("hi", "Hallo!"): what's around you now. */
    private const GREETING = '/^(hi|hiya|hello|hey|hallo|moin|servus|yo|good (morning|afternoon|evening|day))( there| composer| expadu)?[!.,? ]*$/u';

    /** Casual or open messages (owner, 22 Oct 2026): "What's up?", "I'm bored", "anything fun?", "what should I do". */
    private const OPEN = "/\\bw(h)?at'?s+ *u+p+\\b|\\bwh?at up\\b|\\bwass?up\\b|^sup\\b|\\bwhat'?s (new|good|happening|going on)\\b|\\bhow'?s it going\\b|\\bhow are (you|u)\\b"
        ."|\\b(i'?m|im|i am|so|feeling|i feel) bored\\b|^bored\\b|\\banything (fun|good|interesting|nice|cool|exciting)\\b|\\bsomething (fun|interesting|cool)\\b"
        ."|\\bwhat (should|can|could|shall) (i|we) do\\b|\\bwhat to do\\b|\\bentertain me\\b|\\bany (ideas|suggestions)\\b|\\bwhat'?s (around|near) (me|here)\\b"
        .'|\\bwas (ist|geht) (los|ab)\\b|^was geht\\b|\\bwas soll ich (machen|tun)\\b|\\blangweilig\\b/u';

    private const WEEKDAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    private const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

    /**
     * @return array{kind: string, date: string}&array<string, mixed>
     */
    public function route(string $text, RouterContext $context): array
    {
        // Curly apostrophes and accents read the same as typed ones.
        $t = str_replace(['’', '‘', 'é'], ["'", "'", 'e'], mb_strtolower(trim($text)));
        $date = $this->date($t, $context);

        if ($date === null || ! $this->valid($date)) {
            return ['kind' => 'clarify', 'date' => $context->date];
        }

        if (preg_match(self::GREETING, $t)) {
            return ['kind' => 'around', 'date' => $context->today];
        }

        if (preg_match('/what can you do|how (can|do) you help|^help[?!. ]*$|who are you/u', $t)) {
            return ['kind' => 'help', 'date' => $date];
        }

        // "When should I leave for my 14:00?" is about the appointment's trip.
        if (($leaving = $this->leavingFor($t, $context)) !== null) {
            return ['kind' => 'journey', 'date' => $date, 'to' => 'appointment', 'unnamed' => $leaving];
        }

        // "When should I leave for the cinema?": today's event, by its title or venue.
        if (($event = $this->eventTrip($t, $context)) !== null) {
            return ['kind' => 'journey', 'date' => $context->today, 'event' => $event];
        }

        // "Show me departures to Cafe Nova in City Center": a trip to that place, today; "how do
        // I get there?" the place the last trip was to (the workspace knows which).
        if ($this->date($t, $context, $context->today) === $context->today && ($trip = self::destination($text)) !== null) {
            return ['kind' => 'journey', 'date' => $context->today, ...$trip];
        }

        // On your way (Travel's "I'm on my way"): where to get off, when you'll be there, what's next.
        if ($context->onTheWay) {
            if (preg_match("/\bwhere (do|should|must) i get (off|out)\b|\bwhich stop\b|\bmy stop\b/u", $t)) {
                return ['kind' => 'onway', 'date' => $date, 'ask' => 'stop'];
            }
            if (preg_match("/\bwhen (will|do|shall) i (get there|arrive|be there)\b|\bhow long (until|till|left|to go|is left)\b|\bwill i (make it|be on time|be late)\b|\bam i (on time|late)\b/u", $t)) {
                return ['kind' => 'onway', 'date' => $date, 'ask' => 'arrive'];
            }
            if (preg_match("/\bwhat('s| is) next\b|\bnext step\b|\bwhere am i\b|\bhow('s| is) (my|the) trip\b|\b(my|the) trip\b/u", $t)) {
                return ['kind' => 'onway', 'date' => $date, 'ask' => 'next'];
            }
        }

        if ($context->appointmentStart !== null
            && preg_match('/\b(trams?|trains?|bus(es)?|line \d+|s-?bahn|u-?bahn|connection|ride)\b.*\b(on time|late|delay(s|ed)?|running|cancell?ed)\b|\b(any|are there) delays?\b/u', $t)) {
            return ['kind' => 'journey', 'date' => $date, 'to' => 'appointment', 'status' => true];
        }

        // Getting somewhere comes before paperwork words.
        if (preg_match('/\b(how (do|can|should) i get (to|from)|get there|directions to|journey to|route to|faster way|quicker way|another route)\b/u', $t)) {
            return ['kind' => 'journey', 'date' => $date];
        }

        // Step-free access, answered from the listing; before event words ("Is the cinema step-free?").
        if (preg_match('/\b(step-?free|wheelchair|accessib(le|ility)|lifts?|elevators?|stairs)\b/u', $t)) {
            return ['kind' => 'access', 'date' => $date, 'about' => $t];
        }

        // What to bring: your own checklist's progress, not guidance.
        if (preg_match("/\bwhat (do|should) i (need to )?bring\b|\bwhat to bring\b|\bbring to my\b|\bdocuments? (do i need|are ready|ready)\b|\bdocuments?\b.*\b(missing|still need|left|to get)\b|\bmissing\b.*\bdocuments?\b|\bwhat am i missing\b/u", $t)) {
            return ['kind' => 'checklist', 'date' => $date];
        }

        // Questions about your plans answer from them; they never change them.
        if (preg_match("/\b(when|what time)\b.*\b(my|next)\b.*\b(appointment|plan|class)\b|\bnext (appointment|plan)\b|\bwhat('s| is)( on)? my (day|plan|schedule)\b/u", $t)) {
            return ['kind' => 'next', 'date' => $date, 'appointment' => str_contains($t, 'appointment')];
        }

        if (preg_match('/\b(rain|rainy|raining|indoors?|inside)\b/u', $t)) {
            return ['kind' => 'indoor', 'date' => $date];
        }

        if (preg_match('/\b(help me plan|plan my day)\b/u', $t)) {
            return ['kind' => 'proposal', 'date' => $date, 'appointment' => (bool) preg_match('/around (my|an?|the) .*appointment/u', $t)];
        }

        // "Somewhere nearby to play football": the places listed for that activity.
        if ($places = $this->places($t, $this->date($t, $context, $context->today) ?? $context->today)) {
            return $places;
        }

        // "Near my appointment" or "open now" means today unless a day is named.
        if ($nearby = $this->nearby($t, $this->date($t, $context, $context->today) ?? $context->today)) {
            return $nearby;
        }

        if (preg_match('/visa|register|registration|anmeldung|permit|bureaucracy|blue card|paperwork|settle in/u', $t)) {
            return ['kind' => 'bureaucracy', 'date' => $date];
        }

        if ($service = $this->first(self::SERVICES, $t)) {
            return ['kind' => 'service', 'date' => $date, 'service' => $service];
        }

        if (preg_match("/\b(events?|concerts?|exhibitions?|museums?|music|films?|cinema|what('s| is) on|things to do|going out|tonight)\b/u", $t)) {
            return ['kind' => 'event', 'date' => $date, 'topic' => $this->first(self::TOPICS, $t),
                'days' => str_contains($t, 'weekend') ? 2 : (preg_match('/\b(this|next) week\b/u', $t) ? 7 : 1),
                'evening' => (bool) preg_match('/\b(tonight|this evening|evening)\b/u', $t)];
        }

        if (preg_match('/journey|route|how.*get|transport/u', $t)) {
            return ['kind' => 'journey', 'date' => $date];
        }

        if (preg_match('/\bmore (outdoor )?(places|ideas)\b/u', $t)) {
            return ['kind' => 'discovery', 'date' => $date, 'more' => true];
        }

        if ($category = $this->first(self::CATEGORIES, $t)) {
            return ['kind' => 'discovery', 'date' => $date, 'category' => $category];
        }

        if (preg_match('/\b(cafes?|coffee|work from|laptop|bars?|pubs?|restaurants?|eat|food|brunch|christmas market|open now|opening hours)\b/u', $t)) {
            return ['kind' => 'unavailable', 'date' => $date, 'topic' => 'listing'];
        }

        if (preg_match('/indoors|no parks|quiet|volleyball|free|budget|30 minutes/u', $t)) {
            return ['kind' => 'unavailable', 'date' => $date];
        }

        if (preg_match('/what.*plan|show.*plan|what.*saturday|what.*doing/u', $t)) {
            return ['kind' => 'answer', 'date' => $date];
        }

        if (preg_match('/later|move.*park|change.*plan|adjust|revise/u', $t)) {
            return ['kind' => 'revise', 'date' => $date];
        }

        if (preg_match('/\bplan\b|appointment/u', $t)) {
            return ['kind' => 'proposal', 'date' => $date, 'appointment' => str_contains($t, 'appointment')];
        }

        if (preg_match('/outdoor|park|fresh air|walk|discover/u', $t)) {
            return ['kind' => 'discovery', 'date' => $date];
        }

        // Open about now: what's around you. About another day, it's that day's plan.
        if (preg_match(self::OPEN, $t)) {
            $day = $this->date($t, $context, $context->today);

            return $day === $context->today ? ['kind' => 'around', 'date' => $day] : ['kind' => 'proposal', 'date' => $day, 'appointment' => false];
        }

        return ['kind' => 'clarify', 'date' => $date];
    }

    /**
     * "When should I leave for my 14:00?" (model.js leaveFor): the day's appointment, named by
     * its time or as the appointment. A bare "When should I leave?" means it too, and the
     * answer then says which (true is that bare case). Null when it isn't about it.
     */
    private function leavingFor(string $t, RouterContext $context): ?bool
    {
        if ($context->appointmentStart === null
            || ! preg_match('/\bwhen (should|do|must) i (leave|set off|go)\b|\bleave for my\b|\bhow (do|can|should) i get to\b|\b(directions|way|route) to\b/u', $t)) {
            return null;
        }
        $time = preg_match('/\b(\d{1,2}:\d{2})\b/', $t, $m) ? str_pad($m[1], 5, '0', STR_PAD_LEFT) : null;
        $bare = $time === null && ! preg_match('/\b(to|for)\b/u', $t);
        if ($time !== null ? $time !== $context->appointmentStart : (! $bare && ! preg_match('/appointment|blue card/u', $t))) {
            return null;
        }

        return $bare;
    }

    /**
     * The place a trip is to, as typed ("Cafe Nova"), the area named with it ("City
     * Center") and a time to leave at ("at 18:30"); `again` for the last trip's place ("how
     * do I get there?"). Null when the words aren't a trip to a place. The keyword reader
     * asks this too, so both readers know a trip the same way.
     *
     * @return array{place: ?string, area: ?string, at: ?string, again: bool, ask: ?string}|null
     */
    public static function destination(string $text): ?array
    {
        $text = trim(str_replace(['’', '‘'], "'", $text));
        // "How far is it?" asks for the distance and the time, not the way.
        $ask = preg_match(self::DISTANCE, $text) === 1 ? 'distance' : null;
        if (preg_match(self::AGAIN, $text) === 1) {
            return ['place' => null, 'area' => null, 'at' => null, 'again' => true, 'ask' => $ask];
        }
        $sure = false;
        foreach (self::TRIP_TO as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                $sure = true;
                break;
            }
        }
        if (! $sure && preg_match(self::GO_TO, $text, $m) !== 1) {
            return null;
        }
        $at = preg_match('/\s+at\s+(\d{1,2}):(\d{2})\b/u', $m['place'], $time) === 1 && (int) $time[1] < 24 && (int) $time[2] < 60
            ? sprintf('%02d:%s', $time[1], $time[2]) : null;
        $place = (string) preg_replace([
            '/^(?:(?:get|go|travel)\s+to|it\s+to|it\s+from\s+here\s+to)\s+/iu',
            '/^(?:the|my)\s+/iu',
            '/\s+(at \d{1,2}:\d{2}|right now|now|today|tonight|please|pls|plz|asap|from here|by (?:tram|bus|train|bike|foot))\b.*$/iu',
        ], '', trim($m['place']));
        $place = rtrim($place, ' ?.!,');
        $area = null;
        if (preg_match('/^(?<place>.+?)\s+(?:in|near|at|by)\s+(?:the\s+)?(?<area>[^,]+)$/iu', $place, $a) === 1) {
            [$place, $area] = [trim($a['place']), trim($a['area'])];
        }
        // "The best way to spend Saturday" is no trip.
        if (preg_match('/^(spend|do|make|have|enjoy|relax|meet|see|start|end|use|kill|pass|celebrate|learn|find|stay|save|cook|eat|sleep|chill)\b/iu', $place) === 1) {
            return null;
        }
        if (preg_match('/^(there|it|that( place)?|this place)$/iu', $place) === 1) {
            return ['place' => null, 'area' => null, 'at' => $at, 'again' => true, 'ask' => $ask];
        }

        return mb_strlen($place) >= 3 && mb_strlen($place) <= 80 && ($sure || preg_match('/\p{Lu}/u', $place) === 1)
            ? ['place' => $place, 'area' => $area, 'at' => $at, 'again' => false, 'ask' => $ask] : null;
    }

    /** "When should I leave for the cinema?" (model.js eventTrip): the id of today's event it names. */
    private function eventTrip(string $t, RouterContext $context): ?int
    {
        if (! preg_match('/\bwhen (should|do|must) i (leave|set off|go)\b|\bhow (do|can|should) i get to\b/u', $t)
            || ! preg_match('/\b(?:for|to)\s+(?:the\s+|my\s+)?(.+?)[?.!\s]*$/u', $t, $m)) {
            return null;
        }
        $named = $m[1];
        foreach ($context->tonight as $event) {
            $title = mb_strtolower($event['title']);
            $venue = mb_strtolower((string) $event['venue']);
            if (str_contains($title, $named) || ($venue !== '' && (str_contains($venue, $named) || str_contains($named, $venue)))) {
                return $event['id'];
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    /**
     * A place to do an activity ("somewhere to play football", "where can I play
     * basketball nearby?"), in the parser's own activity vocabulary.
     *
     * @return array<string, mixed>|null
     */
    private function places(string $t, string $date): ?array
    {
        $wants = app(HeuristicPromptParser::class)->explicitPlaceRequirements($t);
        if ($wants['activities'] === [] || ! preg_match('/\b(som(e)?where|where (can|could|do|should) (i|we)|find (me |us )?(a |some )?(place|spot|pitch|court)s?|places? to|a place|nearby|near (me|here|by)|close (by|to me)|around here)\b/u', $t)) {
            return null;
        }

        return ['kind' => 'places', 'date' => $date, 'activities' => $wants['activities'], 'radius' => $wants['radius_km']];
    }

    private function nearby(string $t, string $date): ?array
    {
        $place = $this->first(self::PLACES, $t);
        if ($place === null) {
            return null;
        }
        $near = $this->first(self::ANCHORS, $t);
        // A park without a place stays general discovery ("a park to walk").
        if (in_array($place, ['park', 'green'], true) && $near === null) {
            return null;
        }

        return ['kind' => 'nearby', 'date' => $date, 'place' => $place, 'near' => $near ?? 'here',
            'open' => (bool) preg_match('/\b(open|still open|open now)\b/u', $t),
            'after' => (bool) preg_match('/\bafter (my |the )?(blue card )?appointment\b/u', $t),
            'wifi' => (bool) preg_match('/\b(wi-?fi|laptop|work from|(can|to) work)\b/u', $t)];
    }

    /** The day a request is about (model.js requestDate); null for a date that doesn't exist. */
    private function date(string $t, RouterContext $context, ?string $default = null): ?string
    {
        $today = CarbonImmutable::parse($context->today, 'Europe/Berlin');
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $t, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        $months = implode('|', self::MONTHS);
        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+('.$months.')\b/u', $t, $m) || preg_match('/\b('.$months.')\s+(\d{1,2})(?:st|nd|rd|th)?\b/u', $t, $m)) {
            [$day, $month] = ctype_digit($m[1]) ? [(int) $m[1], $m[2]] : [(int) $m[2], $m[1]];
            $number = array_search($month, self::MONTHS, true) + 1;
            $year = (int) $today->format('Y');

            return checkdate($number, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $number, $day) : null;
        }
        if (preg_match('/\b(this )?weekend\b/u', $t)) {
            return $this->next('saturday', $today);
        }
        if (preg_match('/\btomorrow\b/u', $t)) {
            return $today->addDay()->toDateString();
        }
        if (preg_match('/\b('.implode('|', self::WEEKDAYS).')\b/u', $t, $m)) {
            return $this->next($m[1], $today);
        }
        if (preg_match('/\b(today|tonight|this (morning|afternoon|evening|week))\b/u', $t)) {
            return $today->toDateString();
        }

        return $default ?? $context->date;
    }

    /** Today if it is that weekday, otherwise the next one (model.js nextWeekday). */
    private function next(string $weekday, CarbonImmutable $today): string
    {
        return $today->addDays((array_search($weekday, self::WEEKDAYS, true) - $today->dayOfWeek + 7) % 7)->toDateString();
    }

    private function valid(string $date): bool
    {
        [$year, $month, $day] = array_map(intval(...), explode('-', $date));

        return checkdate($month, $day, $year);
    }

    /** @param  array<string, string>  $patterns */
    private function first(array $patterns, string $t): ?string
    {
        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $t)) {
                return $key;
            }
        }

        return null;
    }
}
