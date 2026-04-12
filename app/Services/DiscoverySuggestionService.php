<?php

namespace App\Services;

use App\Models\CityNews;
use App\Models\Event;
use App\Models\Service;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Support\Facades\App;

/**
 * Provides rotating discovery suggestions from REAL database data.
 * Zero hardcoded place names — all suggestions come from Spot, Service, Event, CityNews models.
 *
 * Used in two modes:
 * - Full discovery (off-hours): all 3 cards
 * - Context card (commute Card 3): 1 smart card based on time/context
 */
class DiscoverySuggestionService
{
    /**
     * Full discovery mode — 3 rotating cards.
     */
    public function getFullDiscovery(User $user, float $lat, float $lng): array
    {
        $weather = app(WeatherService::class)->getCurrentWeather($lat, $lng);
        $forecast = app(WeatherService::class)->getForecast($lat, $lng);
        $home = $user->places()->where('category', 'home')->first();
        $work = $user->places()->where('category', 'work')->first();

        // Build pools for client-side rotation
        $activityPool = $this->buildActivityPool($user, $weather, $forecast, $lat, $lng);
        $favPool = $this->buildFavPool($user, $lat, $lng);
        $eventPool = $this->buildEventPool($user);

        $headline = isset($weather['temperature'])
            ? "{$weather['emoji']} {$weather['temperature']}°C — {$weather['condition']}"
            : '🌍 Cologne — Discover what\'s nearby';

        return [
            'from' => $home?->name ?? 'Home',
            'to' => 'Discover',
            'headline' => $headline,
            'route_cards' => [
                $activityPool[0] ?? $this->card('🗺️', 'Explore nearby', 'Open the map'),
                $favPool[0] ?? $this->card('🗺️', 'Discover spots', 'Check the explore page'),
                $eventPool[0] ?? $this->card('📅', 'Check events', 'See what\'s happening'),
            ],
            'card_pools' => [
                array_slice($activityPool, 0, 5),
                array_slice($favPool, 0, 5),
                array_slice($eventPool, 0, 5),
            ],
            'leave_by' => null,
            'weather' => $weather,
            'forecast' => $forecast,
            'context' => 'discovery',
            'needs_setup' => ! $home?->address || ! $work?->address,
        ];
    }

    /**
     * Activity card — nearby real spots from DB, filtered by weather.
     */
    public function getActivityCard(User $user, array $weather, array $forecast, ?float $lat = null, ?float $lng = null): array
    {
        $pool = $this->buildActivityPool($user, $weather, $forecast, $lat, $lng);

        if (empty($pool)) {
            return $this->card('🗺️', 'Explore nearby', 'Open the map to discover spots');
        }

        return $pool[now()->minute % count($pool)];
    }

    /**
     * Build the full activity suggestion pool — returned to frontend for rotation.
     *
     * @return array<int, array>
     */
    public function buildActivityPool(User $user, array $weather, array $forecast, ?float $lat = null, ?float $lng = null): array
    {
        $lat ??= 50.9375;
        $lng ??= 6.9603;

        // Fetch nearby spots (all categories), exclude user's current location
        $allSpots = Spot::nearby($lat, $lng)->limit(30)->get()
            ->filter(fn (Spot $s) => $s->lat === null || $s->lng === null
                || $this->metersApart($lat, $lng, (float) $s->lat, (float) $s->lng) >= 200)
            ->values();

        if ($allSpots->isEmpty()) {
            return [$this->card('🗺️', 'Explore nearby', 'Open the map to discover spots')];
        }

        // Score all spots using weighted algorithm
        $scorer = app(SpotScoringService::class);
        $scored = $scorer->scoreSpots($user, $allSpots, $lat, $lng, $weather);

        // Enforce familiar + new mix
        $familiar = $scored->filter(fn ($s) => $s->visit_count > 0)->take(2);
        $new = $scored->filter(fn ($s) => $s->visit_count === 0)->take(3);
        $mixed = $familiar->merge($new)->sortByDesc('score')->take(5);

        // If not enough variety, fill from top scored
        if ($mixed->count() < 3) {
            $mixed = $scored->take(5);
        }

        $categoryEmoji = [
            'cafe' => '☕', 'library' => '📚', 'park' => '🌳', 'coworking' => '💻',
        ];

        return $mixed->map(function ($spot) use ($categoryEmoji) {
            $emoji = $categoryEmoji[$spot->category?->value ?? 'cafe'] ?? '📍';

            return $this->card($emoji, $spot->name, $spot->reason, (float) $spot->lat, (float) $spot->lng);
        })->values()->all();
    }

    /**
     * Context-aware smart card for commute Card 3.
     * Shows different content based on commute context.
     */
    public function getContextCard(User $user, array $weather, array $forecast, string $contextType, float $toLat, float $toLng): array
    {
        $hour = now()->hour;

        return match ($contextType) {
            // Morning commute → tonight's event or weather alert
            'to_work', 'routine' => $this->morningContextCard($user, $weather, $forecast, $toLat, $toLng),

            // At work → lunch spot or disruption alert
            'at_work' => $this->workContextCard($user, $weather, $toLat, $toLng),

            // Evening return → event tonight or café near home
            'to_home' => $this->eveningContextCard($user, $weather, $toLat, $toLng),

            // Any other → activity card
            default => $this->getActivityCard($user, $weather, $forecast, $toLat, $toLng),
        };
    }

    /**
     * Morning: tonight's event > weather alert > café near work for lunch.
     */
    private function morningContextCard(User $user, array $weather, array $forecast, float $workLat, float $workLng): array
    {
        // Tonight's event the user is attending
        $goingEvent = Event::query()
            ->whereDate('starts_at', today())
            ->where('starts_at', '>', now())
            ->where('quality_score', '>=', 0.3)
            ->whereHas('attendees', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        if ($goingEvent) {
            return $this->card(
                '🎫',
                $goingEvent->title,
                ($goingEvent->location_name ?? 'Cologne').' · '.$goingEvent->starts_at->format('H:i'),
                $goingEvent->lat ?? $workLat,
                $goingEvent->lng ?? $workLng,
                $goingEvent->source_url,
            );
        }

        // Weather alert if rain coming
        if ($forecast['rain_starts'] ?? null) {
            return $this->card('🌧️', "Rain from {$forecast['rain_starts']}", 'Bring umbrella · Plan return early');
        }

        // Tonight's top event (not attending)
        $event = Event::query()
            ->whereDate('starts_at', today())
            ->where('starts_at', '>', now()->setTime(17, 0))
            ->where('quality_score', '>=', 0.5)
            ->orderByDesc('is_expat_relevant')
            ->first();

        if ($event) {
            $priceLabel = $event->price_text ?? ($event->is_free ? 'Free' : null);
            $detail = ($event->location_name ?? 'Cologne')
                .' · '.$event->starts_at->format('H:i')
                .($priceLabel ? ' · '.$priceLabel : '');

            return $this->card(
                $event->emoji ?? '📅',
                $event->title,
                $detail,
                $event->lat ?? $workLat,
                $event->lng ?? $workLng,
                $event->source_url,
            );
        }

        // Fallback: café near work for lunch
        $cafe = Spot::nearby($workLat, $workLng)->where('category', 'cafe')->first();

        if ($cafe) {
            $dist = $this->distLabel($workLat, $workLng, (float) $cafe->lat, (float) $cafe->lng);

            return $this->card('☕', $cafe->name, "{$dist} · Lunch spot near work", (float) $cafe->lat, (float) $cafe->lng);
        }

        return $this->card('☀️', "{$weather['temperature']}°C today", $weather['condition']);
    }

    /**
     * At work: lunch spot > disruption alert.
     */
    private function workContextCard(User $user, array $weather, float $workLat, float $workLng): array
    {
        $hour = now()->hour;
        $pool = [];

        // Lunch hours (11-14): nearby cafés and restaurants
        if ($hour >= 11 && $hour <= 14) {
            $cafes = Spot::nearby($workLat, $workLng)->where('category', 'cafe')->limit(3)->get();
            foreach ($cafes as $c) {
                $dist = $this->distLabel($workLat, $workLng, (float) $c->lat, (float) $c->lng);
                $pool[] = $this->card('🍽️', $c->name, "{$dist} · Lunch break", (float) $c->lat, (float) $c->lng);
            }
        }

        // Disruption affecting user's commute lines
        $disruptions = app(DisruptionService::class)->getLineDisruptions();
        $activeDisruption = collect($disruptions)->first(fn ($d) => ($d['severity'] ?? '') !== 'minor');
        if ($activeDisruption) {
            $pool[] = $this->card('⚠️', $activeDisruption['title'], $activeDisruption['description'] ?? 'Transit disruption');
        }

        // Nearby useful services
        $pharmacy = Service::where('category', 'pharmacy')
            ->selectRaw('*, POWER(lat - ?, 2) * 12321 + POWER(lng - ?, 2) * 4900 AS dist_sq', [$workLat, $workLng])
            ->orderBy('dist_sq')
            ->first();
        if ($pharmacy) {
            $pool[] = $this->card('💊', $pharmacy->name, 'Nearest pharmacy', (float) $pharmacy->lat, (float) $pharmacy->lng);
        }

        if (! empty($pool)) {
            return $pool[now()->minute % count($pool)];
        }

        return $this->card('☀️', "{$weather['temperature']}°C", "{$weather['condition']} · Check before leaving");
    }

    /**
     * Evening return: tonight's event > café near home.
     */
    private function eveningContextCard(User $user, array $weather, float $homeLat, float $homeLng): array
    {
        // Event the user is attending tonight
        $goingEvent = Event::query()
            ->whereDate('starts_at', today())
            ->where('starts_at', '>', now())
            ->where('quality_score', '>=', 0.3)
            ->whereHas('attendees', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        if ($goingEvent) {
            return $this->card(
                '🎫',
                $goingEvent->title,
                'You\'re going · '.$goingEvent->starts_at->format('H:i'),
                $goingEvent->lat ?? $homeLat,
                $goingEvent->lng ?? $homeLng,
                $goingEvent->source_url,
            );
        }

        // Café near home
        $cafe = Spot::nearby($homeLat, $homeLng)->where('category', 'cafe')->first();
        if ($cafe) {
            $dist = $this->distLabel($homeLat, $homeLng, (float) $cafe->lat, (float) $cafe->lng);

            return $this->card('☕', $cafe->name, "{$dist} · Evening coffee", (float) $cafe->lat, (float) $cafe->lng);
        }

        return $this->card('🏠', 'Heading home', "{$weather['temperature']}°C · {$weather['condition']}");
    }

    /**
     * Favorite spot card — rotates through tracked spots from DB.
     */
    public function getFavSpotCard(User $user, float $lat, float $lng): array
    {
        $pool = $this->buildFavPool($user, $lat, $lng);

        if (empty($pool)) {
            return $this->card('🗺️', 'Explore your neighborhood', 'Open the map to find spots');
        }

        return $pool[(now()->minute + 3) % count($pool)];
    }

    /**
     * @return array<int, array>
     */
    public function buildFavPool(User $user, float $lat, float $lng): array
    {
        $pool = [];
        $frequent = app(FrequentDestinationService::class);
        $spots = $frequent->getFrequentDestinations($user, 60, 10);
        $spots = array_values(array_filter($spots, fn ($s) => $s['visits'] >= 2));

        // Build lookup: places with explicit schedule settings (UserPlace overrides auto-detection)
        $userPlaces = $user->places()->get();
        $inactiveNames = [];
        $hasManualSchedule = []; // names where user set day_mode/arrive_by explicitly

        foreach ($userPlaces as $p) {
            $lowerName = mb_strtolower($p->name);

            // If place has manual schedule (not 'all'), use isActiveToday() as the authority
            if ($p->day_mode !== 'all' || $p->arrive_by) {
                $hasManualSchedule[] = $lowerName;
                if (! $p->isActiveToday()) {
                    $inactiveNames[] = $lowerName;
                }
            } elseif (! $p->isActiveToday()) {
                $inactiveNames[] = $lowerName;
            }
        }

        $currentDow = (int) now()->dayOfWeek;
        $currentHour = now()->hour;

        $spots = array_values(array_filter($spots, function ($s) use ($inactiveNames, $hasManualSchedule, $currentDow, $currentHour) {
            $name = mb_strtolower($s['name']);

            // Check if this destination matches a UserPlace with manual schedule
            $hasManual = false;
            foreach ($hasManualSchedule as $manual) {
                if (str_contains($name, $manual) || str_contains($manual, $name)) {
                    $hasManual = true;
                    break;
                }
            }

            // If UserPlace has manual schedule → use isActiveToday() result (already in inactiveNames)
            if ($hasManual) {
                foreach ($inactiveNames as $inactive) {
                    if (str_contains($name, $inactive) || str_contains($inactive, $name)) {
                        return false;
                    }
                }

                return true; // Manual schedule says it's active → show it
            }

            // No manual schedule → check places inactive today
            foreach ($inactiveNames as $inactive) {
                if (str_contains($name, $inactive) || str_contains($inactive, $name)) {
                    return false;
                }
            }

            // Auto-detected routine (no manual config): only show near typical DOW + hour
            if (($s['classification'] ?? 'favourite') === 'routine') {
                $routineDays = $s['routine_days'] ?? [];
                $routineHour = $s['routine_hour'] ?? null;

                if (! empty($routineDays) && ! in_array($currentDow, $routineDays, true)) {
                    return false;
                }
                if ($routineHour !== null && abs($currentHour - $routineHour) > 2) {
                    return false;
                }
            }

            return true;
        }));

        foreach ($spots as $spot) {
            $isRoutine = ($spot['classification'] ?? 'favourite') === 'routine';
            $label = $isRoutine ? 'Regular · '.$spot['visits'].' visits' : 'Your favourite · '.$spot['visits'].' visits';
            $pool[] = $this->card($isRoutine ? '🔄' : '⭐', $spot['name'], $label, $spot['lat'], $spot['lng']);
        }

        if (empty($pool)) {
            $nearby = Spot::nearby($lat, $lng)->limit(5)->get();
            foreach ($nearby as $s) {
                $pool[] = $this->card('📍', $s->name, 'Discover something new', (float) $s->lat, (float) $s->lng);
            }
        }

        return $pool;
    }

    /**
     * Event or city news card — rotates through today's events and news.
     */
    public function getEventCard(User $user): array
    {
        $pool = $this->buildEventPool($user);

        if (empty($pool)) {
            return $this->card('🗺️', 'Explore Cologne', 'Check events and spots nearby');
        }

        return $pool[(now()->minute + 7) % count($pool)];
    }

    /**
     * @return array<int, array>
     */
    public function buildEventPool(User $user): array
    {
        $items = [];

        $events = Event::query()
            ->where('starts_at', '>', now())
            ->where('starts_at', '<', now()->addDays(7))
            ->where('quality_score', '>=', 0.3)
            ->orderByDesc('is_expat_relevant')
            ->orderBy('starts_at')
            ->limit(10)
            ->get();

        // Pre-load attending IDs to avoid N+1
        $attendingIds = $user->attendingEvents()
            ->whereIn('events.id', $events->pluck('id'))
            ->pluck('events.id')
            ->flip();

        foreach ($events as $e) {
            $going = $attendingIds->has($e->id);

            $priceLabel = match (true) {
                $going => null,
                (bool) $e->price_text => $e->price_text,
                (bool) $e->is_free => 'Free',
                default => null,
            };

            $detail = ($going ? 'You\'re going · ' : '')
                .($e->location_name ?? 'Cologne')
                .' · '.$e->starts_at->format('H:i')
                .($priceLabel ? ' · '.$priceLabel : '');

            $items[] = $this->card(
                $going ? '🎫' : ($e->emoji ?? '📅'),
                $e->title,
                $detail,
                $e->lat,
                $e->lng,
                $e->source_url,
            );
        }

        $news = CityNews::query()
            ->where('published_at', '>', now()->subDays(3))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('relevance', '!=', 'skip')
            ->orderByDesc('published_at')
            ->limit(5)
            ->get();

        foreach ($news as $n) {
            $emoji = match ($n->category) {
                'transit' => '🚋',
                'event' => '📅',
                default => '📰',
            };
            $items[] = $this->card($emoji, $n->title, $n->summary ?? $n->source, null, null, $n->source_url);
        }

        if (empty($items)) {
            $gtfs = App::make(GtfsDepartureService::class);
            $home = $user->places()->where('category', 'home')->first();
            $deps = $gtfs->getDeparturesNearby(
                $home?->lat ? (float) $home->lat : 50.9375,
                $home?->lng ? (float) $home->lng : 6.9603,
                3,
            );
            $next = ($deps['departures'] ?? [])[0] ?? null;

            if ($next && ! empty($next['departures'])) {
                $items[] = $this->card('🚋', "Line {$next['line']} in {$next['departures'][0]} min", ($deps['stop_name'] ?? 'Nearby')." → {$next['direction']}");
            }
        }

        return $items;
    }

    /**
     * Human-readable distance string between two coordinates.
     * Shows metres below 1 km, kilometres above.
     */
    private function distLabel(float $fromLat, float $fromLng, float $toLat, float $toLng): string
    {
        $metres = $this->metersApart($fromLat, $fromLng, $toLat, $toLng);

        if ($metres < 1000) {
            return round($metres / 50) * 50 .'m';
        }

        return number_format($metres / 1000, 1).' km';
    }

    private function metersApart(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Build a card. All names come from database — never hardcoded place names.
     *
     * @return array{type: string, badge: string, name: string, detail: string, time: int, status: string, best: bool, to_lat: float|null, to_lng: float|null, to_name: string}
     */
    private function card(string $badge, string $name, string $detail, ?float $lat = null, ?float $lng = null, ?string $link = null): array
    {
        // Infer card type from badge for engagement tracking granularity
        $type = match (true) {
            in_array($badge, ['☕', '💻', '📚', '🌳', '🌆', '📍', '🍽️', '💊']) => 'spot',
            in_array($badge, ['🚲', '🏠']) => 'commute',
            in_array($badge, ['📅', '🗣️']) => 'event',
            in_array($badge, ['🌧️', '☀️']) => 'weather',
            in_array($badge, ['⚠️', '🚋']) => 'transit',
            in_array($badge, ['🔄', '⭐']) => 'routine',
            $badge === '📰' => 'news',
            default => 'discovery',
        };

        return [
            'type' => $type,
            'badge' => $badge,
            'name' => $name,
            'detail' => $detail,
            'time' => 0,
            'status' => 'ok',
            'best' => false,
            'to_lat' => $lat,
            'to_lng' => $lng,
            'to_name' => $name,
            'link' => $link,
        ];
    }
}
