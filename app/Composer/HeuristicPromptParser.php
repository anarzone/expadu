<?php

namespace App\Composer;

use App\Composer\Concerns\NormalisesConstraints;
use App\Composer\Contracts\ParsesPrompt;
use App\Privacy\ProcessingPermit;
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
        'something to do', 'fill my day', 'keep me busy', 'day out', 'free ', 'recommend', 'where can i play',
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
        'culture' => 'culture', 'exhibition' => 'culture', 'theatre' => 'theatre',
        'library' => 'library', 'study' => 'library', 'read' => 'library',
        'basketball' => 'basketball', 'hoops' => 'basketball',
        'football' => 'pitch', 'soccer' => 'pitch', 'pitch' => 'pitch',
        'swim' => 'swimming', 'swimming' => 'swimming', 'pool' => 'swimming',
        'lake' => 'lake', 'beach' => 'lake',
        'forest' => 'nature', 'woods' => 'nature', 'nature reserve' => 'nature',
        'playground' => 'playground',
        'skate' => 'skatepark', 'skatepark' => 'skatepark',
        'tennis' => 'tennis',
        'dog park' => 'dog_park',
        'concert' => 'event', 'gig' => 'event', 'live music' => 'event',
        'supermarket' => 'supermarket',
        'convenience shop' => 'convenience',
        'convenience' => 'convenience',
        'kiosk' => 'kiosk',
        'clothes shop' => 'clothes',
        'clothes' => 'clothes',
        'shoe shop' => 'shoes',
        'shoes' => 'shoes',
        'jewellery shop' => 'jewelry',
        'jewelry' => 'jewelry',
        'department store' => 'department_store',
        'shopping centre' => 'shopping_centre',
        'general shop' => 'general_store',
        'general store' => 'general_store',
        'bicycle shop' => 'bicycle_shop',
        'sports shop' => 'sports_shop',
        'bookshop' => 'bookshop',
        'stationery shop' => 'stationery',
        'stationery' => 'stationery',
        'electronics shop' => 'electronics',
        'electronics' => 'electronics',
        'phone shop' => 'phone_shop',
        'computer shop' => 'computer_shop',
        'furniture shop' => 'furniture',
        'furniture' => 'furniture',
        'homeware shop' => 'homeware',
        'homeware' => 'homeware',
        'hardware shop' => 'hardware',
        'hardware' => 'hardware',
        'garden centre' => 'garden_centre',
        'florist' => 'florist',
        'pet shop' => 'pet_shop',
        'toy shop' => 'toy_shop',
        'gift shop' => 'gift_shop',
        'art shop' => 'art_shop',
        'antiques shop' => 'antiques',
        'antiques' => 'antiques',
        'second-hand shop' => 'second_hand',
        'second hand' => 'second_hand',
        'music shop' => 'music_shop',
        'drinks shop' => 'drinks_shop',
        'coffee and tea shop' => 'coffee_tea_shop',
        'coffee tea shop' => 'coffee_tea_shop',
        'butcher' => 'butcher',
        'greengrocer' => 'greengrocer',
        'delicatessen' => 'delicatessen',
        'seafood shop' => 'seafood_shop',
        'confectionery shop' => 'confectionery',
        'confectionery' => 'confectionery',
        'car dealership' => 'car_dealer',
        'car dealer' => 'car_dealer',
        'car parts shop' => 'auto_parts',
        'auto parts' => 'auto_parts',
        'motorcycle shop' => 'motorcycle_shop',
        'hairdresser' => 'hairdresser',
        'beauty salon' => 'beauty',
        'beauty' => 'beauty',
        'laundry' => 'laundry',
        'dry cleaner' => 'dry_cleaning',
        'dry cleaning' => 'dry_cleaning',
        'tailor' => 'tailor',
        'shoe repair' => 'shoe_repair',
        'car repair' => 'car_repair',
        'travel agency' => 'travel_agency',
        'ticket office' => 'ticket_shop',
        'ticket shop' => 'ticket_shop',
        'massage studio' => 'massage',
        'massage' => 'massage',
        'tattoo studio' => 'tattoo',
        'tattoo' => 'tattoo',
        'copy shop' => 'copy_shop',
        'pet grooming' => 'pet_grooming',
        'locksmith' => 'locksmith',
        'bank' => 'bank',
        'post office' => 'post_office',
        'car rental' => 'car_rental',
        'pharmacy' => 'pharmacy',
        'drugstore' => 'drugstore',
        'doctor' => 'doctor',
        'dentist' => 'dentist',
        'optician' => 'optician',
        'hearing aid specialist' => 'hearing_aids',
        'hearing aids' => 'hearing_aids',
        'medical supplies' => 'medical_supply',
        'medical supply' => 'medical_supply',
        'clinic' => 'clinic',
        'hospital' => 'hospital',
        'veterinary clinic' => 'veterinary',
        'veterinary' => 'veterinary',
        'community centre' => 'community_centre',
        'place of worship' => 'place_of_worship',
        'language school' => 'language_school',
        'music school' => 'music_school',
        'hotel' => 'hotel',
        'hostel' => 'hostel',
        'guest house' => 'guest_house',
        'motel' => 'motel',
        'campsite' => 'campsite',
        'gym' => 'fitness_centre',
        'fitness centre' => 'fitness_centre',
        'dance studio' => 'dance_studio',
        'cinema' => 'cinema',
        'arts centre' => 'arts_centre',
        'escape room' => 'escape_room',
        'ice cream shop' => 'ice_cream',
        'ice cream' => 'ice_cream',
        'market' => 'market',
        'shopping' => 'shopping',
        'local services' => 'services',
        'health' => 'health',
        'community' => 'community',
        'places to stay' => 'stay',
        'fitness' => 'fitness',

    ];

    public function parse(string $text, Profile $profile, CarbonImmutable $now, ?ProcessingPermit $permit = null): ParsedPrompt
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

        $placeRequest = $this->explicitPlaceRequirements($t);
        if ($hasTime || ($placeRequest['activities'] !== [] && $placeRequest['radius_km'] !== null)) {
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
                activities: $this->explicitPlaceRequirements($t)['activities'],
                radiusKm: $this->explicitPlaceRequirements($t)['radius_km'],
            ),
            $profile,
            $now,
        );
    }

    /** Explicit requirements also constrain the model parser's output. */
    public function explicitPlaceRequirements(string $text): array
    {
        $text = mb_strtolower($text);
        $activities = [];
        foreach ([
            'soccer' => ['football', 'soccer', 'fußball', 'fussball'],
            'basketball' => ['basketball'], 'table_tennis' => ['table tennis', 'tischtennis'],
            'tennis' => ['tennis'], 'boules' => ['boules', 'bocce', 'pétanque'],
            'skateboard' => ['skateboard', 'skateboarding'], 'swimming' => ['swimming'],
            'volleyball' => ['volleyball'], 'badminton' => ['badminton'],
        ] as $activity => $words) {
            if ($this->containsWord($text, $words) && ! ($activity === 'tennis' && str_contains($text, 'table tennis'))) {
                $activities[] = $activity;
            }
        }
        $radius = null;
        if (preg_match('/\bwithin\s+(\d+(?:[.,]\d+)?)\s*(km|kilomet(?:er|re)s?|m|met(?:er|re)s?)\b/u', $text, $match)) {
            $radius = min(50.0, (float) str_replace(',', '.', $match[1]) / (str_starts_with($match[2], 'k') ? 1 : 1000));
        } elseif ($this->containsAny($text, ['nearby', 'near me', 'close to me'])) {
            $radius = (float) config('composer.nearby_radius_km');
        }

        return ['activities' => $activities, 'radius_km' => $radius, 'budget' => $this->extractBudget($text)];
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
        $terms = self::CATEGORY_SYNONYMS;
        uksort($terms, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        $remaining = $t;
        $matched = [];
        foreach ($terms as $term => $canonical) {
            // A specific phrase owns its words; separate requests still match.
            $variants = [$term, $term.'s'];
            if (str_ends_with($term, 'y')) {
                $variants[] = substr($term, 0, -1).'ies';
            }
            $pattern = '/\\b(?:'.implode('|', array_map(static fn (string $variant): string => preg_quote($variant, '/'), $variants)).')\\b/u';
            if (preg_match($pattern, $remaining)) {
                $matched[$term] = true;
                $remaining = preg_replace_callback($pattern, static fn (array $match): string => str_repeat(' ', strlen($match[0])), $remaining);
            }
        }

        $categories = [];
        foreach (self::CATEGORY_SYNONYMS as $term => $canonical) {
            if (isset($matched[$term])) {
                $categories[] = $canonical;
            }
        }

        // Standalone "sport(s)" fans out without widening a sports-shop request.
        if ($this->containsWord($remaining, ['sport', 'sports'])) {
            array_push($categories, 'pitch', 'basketball', 'tennis', 'table_tennis', 'skatepark', 'boules');
        }

        if ($this->containsWord($remaining, ['outdoors', 'outdoor', 'outside', 'nature'])
            || str_contains($remaining, 'fresh air')) {
            array_push($categories, 'park', 'lake', 'nature', 'playground', 'pitch', 'basketball', 'dog_park', 'skatepark');
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
        if (preg_match('/\bfree\s+(football|soccer|basketball|tennis|table tennis|courts?|pitches?|places?|entry|admission|activities)\b/u', $t)
            || str_contains($t, 'for free') || str_contains($t, 'free entry')
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
