<?php

namespace App\Composer;

use App\Composer\Concerns\NormalisesConstraints;
use App\Composer\Contracts\ParsesPrompt;
use App\Profile\Profile;
use Carbon\CarbonImmutable;

/**
 * The default, key-free parser: pure string heuristics, no network, fully
 * deterministic. It classifies intent and — for plan_day — extracts a
 * constraint window the way the LLM would, against closed vocabularies so
 * it can never invent an area or category. This is not a "fallback"; until
 * a provider key lands it IS the engine, and when one does the OpenAI
 * driver simply takes over the same contract.
 *
 * Classification order matters: explicit navigation → paperwork question →
 * time-bounded plan → plain search. The chips let the user correct any
 * mis-read, so the product never shows a spinner that ends in an apology.
 */
class HeuristicPromptParser implements ParsesPrompt
{
    use NormalisesConstraints;

    /** Hard signals that the user is asking a bureaucracy question. */
    private const BUREAUCRACY_KEYWORDS = [
        'anmeldung', 'abmeldung', 'ummeldung', 'register', 'registration', 'deregister',
        'appointment', 'termin', 'visa', 'permit', 'aufenthalt', 'residence', 'settle',
        'ausländerbehörde', 'auslanderbehorde', 'auslander', 'bürgeramt', 'buergeramt',
        'kundenzentrum', 'fiktionsbescheinigung', 'blue card', 'chancenkarte',
        'niederlassung', 'tax', 'steuer', 'tax id', 'finanzamt', 'elster',
        'insurance', 'krankenversicherung', 'health insurance', 'sperrkonto',
        'blocked account', 'bank account', 'rundfunk', 'broadcasting fee', 'gez',
        'licence', 'license', 'führerschein', 'fuhrerschein', 'driving licence',
        'driving license', 'kita', 'daycare', 'childcare', 'elterngeld', 'kindergeld',
        'birth certificate', 'geburtsurkunde', 'passport', 'reisepass', 'personalausweis',
        'paperwork', 'bureaucracy', 'document', 'documents', 'deadline',
    ];

    /** Explicit "get me there" phrasing → take_me_there. */
    private const NAV_PHRASES = [
        'take me to', 'how do i get to', 'how to get to', 'how can i get to',
        'directions to', 'route to', 'navigate to', 'way to get to', 'get me to',
    ];

    /** Word-bounded time markers; their presence makes it a plan, not a search. */
    private const TIME_WORDS = [
        'today', 'tonight', 'tomorrow', 'weekend', 'morning', 'afternoon', 'evening',
        'noon', 'midday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday',
        'saturday', 'sunday',
    ];

    /** Planning phrasings that imply "compose my time" even without a clock word. */
    private const PLANNING_VERBS = [
        'plan my', 'plan a', 'plan our', 'what should i do', 'what to do',
        'something to do', 'fill my day', 'keep me busy', 'day out', 'free ',
    ];

    /** Synonym → canonical category from the closed activity vocabulary. */
    private const CATEGORY_SYNONYMS = [
        'park' => 'park', 'parks' => 'park', 'green space' => 'park', 'walk' => 'park',
        'stroll' => 'park', 'picnic' => 'park',
        'cafe' => 'cafe', 'café' => 'cafe', 'coffee' => 'cafe', 'brunch' => 'cafe',
        'restaurant' => 'restaurant', 'lunch' => 'restaurant', 'dinner' => 'restaurant',
        'eat' => 'restaurant', 'food' => 'restaurant', 'dine' => 'restaurant',
        'bar' => 'bar', 'drinks' => 'bar', 'beer' => 'bar', 'pub' => 'bar',
        'cocktail' => 'bar', 'nightlife' => 'bar',
        'museum' => 'culture', 'gallery' => 'culture', 'art' => 'culture',
        'culture' => 'culture', 'exhibition' => 'culture', 'theatre' => 'culture',
        'library' => 'library', 'study' => 'library', 'read' => 'library',
        'basketball' => 'basketball', 'hoops' => 'basketball',
        'football' => 'pitch', 'soccer' => 'pitch', 'pitch' => 'pitch',
        'swim' => 'swimming', 'swimming' => 'swimming', 'pool' => 'swimming',
        'lake' => 'lake', 'beach' => 'lake',
        'playground' => 'playground',
        'skate' => 'skatepark', 'skatepark' => 'skatepark',
        'tennis' => 'tennis',
        'dog park' => 'dog_park',
        'concert' => 'event', 'gig' => 'event', 'live music' => 'event',
    ];

    public function parse(string $text, Profile $profile, CarbonImmutable $now): ParsedPrompt
    {
        $raw = trim($text);
        $t = mb_strtolower($raw);

        // 1. Explicit navigation wins outright.
        if ($this->containsAny($t, self::NAV_PHRASES)) {
            return new ParsedPrompt(PromptIntent::TakeMeThere, query: $raw);
        }

        // 2. A bureaucracy keyword routes to the verified checklist, never a plan.
        if ($this->containsAny($t, self::BUREAUCRACY_KEYWORDS)) {
            return new ParsedPrompt(PromptIntent::BureaucracyQ, query: $raw);
        }

        // 3. A time window or planning phrase means "compose my time".
        $hasTime = $this->containsWord($t, self::TIME_WORDS)
            || $this->containsAny($t, self::PLANNING_VERBS);

        if ($hasTime) {
            return new ParsedPrompt(
                PromptIntent::PlanDay,
                plan: $this->buildConstraints($t, $profile, $now),
            );
        }

        // 4. Anything else is a plain search over the index.
        return new ParsedPrompt(PromptIntent::Find, query: $raw);
    }

    private function buildConstraints(string $t, Profile $profile, CarbonImmutable $now): Constraints
    {
        $day = $this->resolveDay($t, $now);
        [$start, $end] = $this->resolveWindow($t, $day);

        return $this->clampConstraints(
            new Constraints(
                windowStart: $start,
                windowEnd: $end,
                areas: $this->extractAreas($t),
                categories: $this->extractCategories($t),
                companions: $this->extractCompanions($t),
                budget: $this->extractBudget($t),
            ),
            $profile,
            $now,
        );
    }

    private function resolveDay(string $t, CarbonImmutable $now): CarbonImmutable
    {
        $today = $now->startOfDay();

        if (str_contains($t, 'tomorrow')) {
            return $today->addDay();
        }
        if (str_contains($t, 'today') || str_contains($t, 'tonight')) {
            return $today;
        }
        if (str_contains($t, 'weekend')) {
            return $this->nextWeekday($today, CarbonImmutable::SATURDAY);
        }

        $isoByName = [
            'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
            'friday' => 5, 'saturday' => 6, 'sunday' => 7,
        ];
        foreach ($isoByName as $name => $iso) {
            if ($this->containsWord($t, [$name])) {
                return $this->nextWeekday($today, $iso % 7); // Carbon: Sunday = 0
            }
        }

        return $today;
    }

    /**
     * Coming occurrence of a weekday, including today if it matches.
     */
    private function nextWeekday(CarbonImmutable $from, int $dayOfWeek): CarbonImmutable
    {
        $cursor = $from;
        for ($i = 0; $i < 7; $i++) {
            if ($cursor->dayOfWeek === $dayOfWeek) {
                return $cursor;
            }
            $cursor = $cursor->addDay();
        }

        return $from;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveWindow(string $t, CarbonImmutable $day): array
    {
        if (str_contains($t, 'morning')) {
            return [$day->setTime(9, 0), $day->setTime(12, 0)];
        }
        if (str_contains($t, 'afternoon')) {
            return [$day->setTime(12, 0), $day->setTime(18, 0)];
        }
        if (str_contains($t, 'evening') || str_contains($t, 'tonight') || str_contains($t, 'night')) {
            return [$day->setTime(18, 0), $day->setTime(23, 0)];
        }
        if (str_contains($t, 'noon') || str_contains($t, 'midday')) {
            return [$day->setTime(12, 0), $day->setTime(15, 0)];
        }

        return [$day->setTime(10, 0), $day->setTime(22, 0)];
    }

    /**
     * @return list<string>
     */
    private function extractAreas(string $t): array
    {
        $areas = [];
        foreach (array_merge(...array_values(config('veedels'))) as $veedel) {
            if (str_contains($t, mb_strtolower($veedel))) {
                $areas[] = $veedel;
            }
        }

        return array_values(array_unique($areas));
    }

    /**
     * @return list<string>
     */
    private function extractCategories(string $t): array
    {
        $categories = [];
        foreach (self::CATEGORY_SYNONYMS as $term => $canonical) {
            // Match the term and its plural so "museums"/"galleries" land too
            // (regular -s and -y→-ies). The map stays singular.
            $variants = [$term, $term.'s'];
            if (str_ends_with($term, 'y')) {
                $variants[] = substr($term, 0, -1).'ies';
            }
            if ($this->containsWord($t, $variants)) {
                $categories[] = $canonical;
            }
        }

        // "sport(s)" is an umbrella the 1:1 synonym map can't express.
        if ($this->containsWord($t, ['sport', 'sports'])) {
            array_push($categories, 'pitch', 'basketball', 'tennis', 'table_tennis', 'skatepark', 'boules');
        }

        // "outdoors / outside / fresh air" — the open-air umbrella, the same way.
        if ($this->containsWord($t, ['outdoors', 'outdoor', 'outside', 'nature'])
            || str_contains($t, 'fresh air')) {
            array_push($categories, 'park', 'lake', 'playground', 'pitch', 'basketball', 'dog_park', 'skatepark');
        }

        return array_values(array_unique($categories));
    }

    private function extractCompanions(string $t): ?string
    {
        return match (true) {
            $this->containsWord($t, ['kids', 'kid', 'children', 'child', 'family', 'toddler', 'baby']) => 'kids',
            $this->containsWord($t, ['partner', 'date', 'girlfriend', 'boyfriend', 'wife', 'husband', 'spouse']) => 'partner',
            $this->containsWord($t, ['friends', 'mates']) || str_contains($t, 'meet people') || str_contains($t, 'hang out') => 'friends',
            $this->containsWord($t, ['alone', 'solo']) || str_contains($t, 'by myself') || str_contains($t, 'on my own') => 'alone',
            default => null,
        };
    }

    private function extractBudget(string $t): ?string
    {
        // Deliberately NOT the bare word "free" — "free Saturday" is time, not money.
        if (str_contains($t, 'for free') || str_contains($t, 'free entry')
            || str_contains($t, 'free admission') || str_contains($t, 'no cost')
            || str_contains($t, 'gratis')) {
            return 'free';
        }
        if ($this->containsWord($t, ['cheap', 'inexpensive', 'affordable'])
            || str_contains($t, 'low budget') || str_contains($t, 'on a budget')
            || str_contains($t, 'not expensive')) {
            return 'low';
        }

        return null;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Word-bounded match so "art" doesn't fire inside "start" and "sun"
     * doesn't fire inside "sunny".
     *
     * @param  list<string>  $words
     */
    private function containsWord(string $haystack, array $words): bool
    {
        foreach ($words as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }
}
