<?php

namespace App\Services;

use App\Models\CityNews;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use App\Support\RedisLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class RecommendationService
{
    /**
     * Generate ranked recommendation cards for a user.
     * This is the core intelligence engine — it connects transit, weather, events,
     * appointments, and disruptions into smart, actionable suggestions.
     *
     * @return array<int, array{type: string, title: string, subtitle: string, emoji: string, value: string, unit: string, priority: int, color: string, meta: array<string, mixed>}>
     */
    public function getRecommendations(User $user, ?float $lat = null, ?float $lng = null): array
    {
        $places = $user->places()->get();
        $home = $places->first();
        $work = $places->firstWhere('category', 'work');
        $homeLat = $lat ?? ($home?->lat ? (float) $home->lat : 50.9375);
        $homeLng = $lng ?? ($home?->lng ? (float) $home->lng : 6.9603);
        $homeStop = $home?->address ?? $home?->name ?? 'Ehrenfeld';

        $weather = app(WeatherService::class)->getCurrentWeather($homeLat, $homeLng);
        $forecast = app(WeatherService::class)->getForecast($homeLat, $homeLng);
        $disruptions = app(DisruptionService::class)->getActiveDisruptions();
        $disruptedLines = app(DisruptionService::class)->getDisruptedLines();

        $gtfs = App::make(GtfsDepartureService::class);
        $departures = $gtfs->getDeparturesNearby($homeLat, $homeLng, 6);

        $cards = [];

        // 1. Appointment warning (highest priority)
        $cards = array_merge($cards, $this->appointmentCards($user));

        // 2. Weather-aware commute recommendation
        $cards = array_merge($cards, $this->commuteCards($user, $work, $weather, $forecast, $departures, $disruptedLines, $homeLat, $homeLng));

        // 3. Next transit departures
        $cards = array_merge($cards, $this->departureCards($departures, $homeStop, $disruptedLines));

        // 4. Today's events
        $cards = array_merge($cards, $this->eventCards($user));

        // 5. Weather alerts
        $cards = array_merge($cards, $this->weatherCards($weather, $forecast));

        // 6. Disruption alerts
        $cards = array_merge($cards, $this->disruptionCards($disruptions, $user));

        // 7. Settlement nudges
        $cards = array_merge($cards, $this->settlementCards($user));

        // 8. City news
        $cards = array_merge($cards, $this->newsCards());

        // Adjust priorities based on user profile + engagement history
        $cards = $this->adjustPrioritiesByProfile($user, $cards);
        $cards = $this->adjustPrioritiesByEngagement($user, $cards);

        // Sort by priority (highest first)
        usort($cards, fn (array $a, array $b) => $b['priority'] <=> $a['priority']);

        return $cards;
    }

    /**
     * Build the complete dashboard feed from the unified recommendation engine.
     *
     * @return array{recommendations: array, weather: array, forecast: array, settlement: array, departures: array}
     */
    public function buildDashboardFeed(User $user, ?Request $request = null): array
    {
        $location = app(UserLocationService::class)->resolve($user, $request);
        $homeLat = $location['lat'];
        $homeLng = $location['lng'];
        $homeStop = $location['name'];

        $weather = app(WeatherService::class)->getCurrentWeather($homeLat, $homeLng);
        $forecast = app(WeatherService::class)->getForecast($homeLat, $homeLng);

        $recommendations = $this->getRecommendations($user, $homeLat, $homeLng);

        // Settlement progress
        $totalTasks = $user->userTasks()->count();
        $completedTasks = $user->userTasks()->whereNotNull('completed_at')->count();
        $daysSinceArrival = $user->arrival_date
            ? (int) Carbon::parse($user->arrival_date)->diffInDays(now())
            : 0;

        // GTFS departures for departure board
        $gtfs = App::make(GtfsDepartureService::class);
        $deptResult = ($homeLat && $homeLng)
            ? $gtfs->getDeparturesNearby($homeLat, $homeLng, 6)
            : $gtfs->getDepartures($homeStop, 6);

        GtfsDepartureService::logDepartureDebug($user->id, 'home', $deptResult, $homeLat, $homeLng);

        // User places
        $places = $user->places()
            ->orderByRaw("CASE category WHEN 'home' THEN 0 WHEN 'work' THEN 1 ELSE 2 END")
            ->orderBy('sort_order')
            ->limit(5)
            ->get()
            ->map(fn ($p) => $p->only('id', 'emoji', 'name', 'address'))
            ->all();

        // This week events
        $thisWeek = Event::query()
            ->where('starts_at', '>', now())
            ->where('starts_at', '<', now()->addDays(7))
            ->where('quality_score', '>=', 0.3)
            ->orderByDesc('is_expat_relevant')
            ->orderBy('starts_at')
            ->limit(5)
            ->get(['id', 'title', 'emoji', 'category', 'starts_at', 'location_name', 'is_free', 'tags', 'is_expat_relevant'])
            ->map(fn ($e) => $e->toArray())
            ->all();

        // Nearby spots
        $nearbySpots = Spot::nearby($homeLat, $homeLng)
            ->limit(5)
            ->get()
            ->map(function (Spot $s) use ($homeLat, $homeLng) {
                $R = 6371;
                $dLat = deg2rad($s->lat - $homeLat);
                $dLng = deg2rad($s->lng - $homeLng);
                $a = sin($dLat / 2) ** 2 + cos(deg2rad($homeLat)) * cos(deg2rad($s->lat)) * sin($dLng / 2) ** 2;
                $distKm = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));

                $cat = $s->category instanceof \BackedEnum ? $s->category->value : (string) $s->category;

                $emoji = match ($cat) {
                    'cafe' => '☕',
                    'coworking' => '🏢',
                    'library' => '📚',
                    'park' => '🌳',
                    default => '📍',
                };

                $tags = [];
                if ($s->wifi_speed) {
                    $tags[] = 'WiFi';
                }
                if ($s->noise_level === 'quiet' || $cat === 'library') {
                    $tags[] = 'Quiet';
                }
                if ($cat === 'coworking') {
                    $tags[] = 'Cowork';
                }
                if ($cat === 'library') {
                    $tags[] = 'Free';
                }

                // Extract area from address (second part after comma)
                $area = '';
                if ($s->address) {
                    $parts = explode(',', $s->address);
                    $area = trim($parts[1] ?? $parts[0] ?? '');
                    // Remove "Köln" prefix if present
                    $area = preg_replace('/^Köln\s*/i', '', $area) ?: $area;
                }

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'emoji' => $emoji,
                    'area' => $area,
                    'distance_km' => round($distKm, 1),
                    'tags' => array_slice($tags, 0, 3),
                    'lat' => (float) $s->lat,
                    'lng' => (float) $s->lng,
                ];
            })
            ->all();

        // Check if user needs to set up addresses
        $homePlace = $user->places()->where('category', 'home')->first();
        $workPlace = $user->places()->where('category', 'work')->first();
        $needsSetup = ! $homePlace?->address || ! $workPlace?->address;

        // Log recommendations for debugging
        RedisLogger::log("recommendation_debug:{$user->id}", [
            'page' => 'home',
            'location' => $homeStop,
            'lat' => $homeLat,
            'lng' => $homeLng,
            'cards' => collect($recommendations)->pluck('type')->all(),
            'card_count' => count($recommendations),
            'weather' => $weather['condition'] ?? null,
            'departures_source' => $deptResult['source'] ?? null,
        ]);

        return [
            'recommendations' => array_slice($recommendations, 0, 8),
            'needs_setup' => $needsSetup,
            'settlement' => [
                'total' => $totalTasks,
                'completed' => $completedTasks,
                'percent' => $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0,
                'days_since_arrival' => $daysSinceArrival,
                'situation' => $user->situation?->value,
            ],
            'departures' => $deptResult,
            'places' => $places,
            'nearby_spots' => $nearbySpots,
            'this_week' => $thisWeek,
        ];
    }

    /**
     * Get context-aware commute recommendations for the transit hero.
     * Considers time of day, day of week, and user's place schedule.
     *
     * @return array{headline: string, route_cards: array, leave_by: array|null, context: string}
     */
    public function getCommuteRecommendation(User $user): array
    {
        $home = $user->places()->where('category', 'home')->first();
        $work = $user->places()->where('category', 'work')->first();
        $homeLat = $home?->lat ? (float) $home->lat : 50.9375;
        $homeLng = $home?->lng ? (float) $home->lng : 6.9603;

        $weather = app(WeatherService::class)->getCurrentWeather($homeLat, $homeLng);
        $forecast = app(WeatherService::class)->getForecast($homeLat, $homeLng);
        $disruptedLines = app(DisruptionService::class)->getDisruptedLines();

        // Determine commute context based on time and day
        $context = $this->determineCommuteContext($user, $home, $work);

        // Off-hours / discovery mode → delegate entirely to DiscoverySuggestionService
        if ($context['type'] === 'off_hours') {
            return app(DiscoverySuggestionService::class)
                ->getFullDiscovery($user, $context['from_lat'], $context['from_lng']);
        }

        // Get departures from the relevant location
        $gtfs = App::make(GtfsDepartureService::class);
        $fromLat = $context['from_lat'];
        $fromLng = $context['from_lng'];
        $departures = $gtfs->getDeparturesNearby($fromLat, $fromLng, 10);

        // Bike time to destination
        $bikeTime = $this->calculateBikeTime(
            (object) ['lat' => $context['from_lat'], 'lng' => $context['from_lng']],
            (object) ['lat' => $context['to_lat'], 'lng' => $context['to_lng']],
        );

        // Transit route cards from GTFS — prefer direction towards Work, different lines
        $allDeps = $departures['departures'] ?? [];

        // For each line, prefer the direction heading towards Work
        if ($work?->lat && $work?->lng) {
            $workLat = (float) $work->lat;
            $workLng = (float) $work->lng;

            // Group by line, prefer direction heading towards Work
            $kvb = app(KvbApiService::class);
            $nearWorkStop = $kvb->nearestStop($workLat, $workLng);
            $workStopName = $nearWorkStop ? mb_strtolower($nearWorkStop['name']) : '';

            $lineGroups = [];
            foreach ($allDeps as $dep) {
                $lineGroups[$dep['line']][] = $dep;
            }

            $sortedDeps = [];
            foreach ($lineGroups as $lineDeps) {
                if (count($lineDeps) === 1) {
                    $sortedDeps[] = $lineDeps[0];
                } else {
                    // Prefer direction whose headsign matches Work area
                    usort($lineDeps, function ($a, $b) use ($workStopName) {
                        if ($workStopName) {
                            $aMatch = str_contains(mb_strtolower($a['direction']), $workStopName) ? 1 : 0;
                            $bMatch = str_contains(mb_strtolower($b['direction']), $workStopName) ? 1 : 0;
                            if ($aMatch !== $bMatch) {
                                return $bMatch <=> $aMatch;
                            }
                        }

                        return ($a['departures'][0] ?? 999) <=> ($b['departures'][0] ?? 999);
                    });
                    $sortedDeps[] = $lineDeps[0];
                }
            }

            usort($sortedDeps, fn ($a, $b) => ($a['departures'][0] ?? 999) <=> ($b['departures'][0] ?? 999));
            $allDeps = $sortedDeps;
        }

        $transitCards = [];
        $seenLines = [];
        foreach ($allDeps as $dep) {
            if (count($transitCards) >= 2) {
                break;
            }
            $nextMin = $dep['departures'][0] ?? null;
            if ($nextMin === null) {
                continue;
            }
            if (in_array($dep['line'], $seenLines, true)) {
                continue;
            }
            $seenLines[] = $dep['line'];
            $line = $dep['line'];
            $isDisrupted = isset($disruptedLines[$line]);
            // Next departures after the current one
            $following = array_values(array_filter(
                array_slice($dep['departures'], 1),
                fn ($t) => $t > 0
            ));
            $followingText = $following
                ? 'Then in '.implode(', ', $following).' min'
                : 'On time';

            $transitCards[] = [
                'type' => 'transit',
                'badge' => $line,
                'name' => ucfirst($dep['type'])." Line {$line} → {$dep['direction']}",
                'detail' => $isDisrupted
                    ? "Disrupted · {$disruptedLines[$line]} · Use alternative"
                    : $followingText,
                'time' => $nextMin,
                'status' => $isDisrupted ? 'warn' : 'ok',
                'best' => false,
                'to_lat' => $context['to_lat'],
                'to_lng' => $context['to_lng'],
                'to_name' => $context['to_name'],
            ];
        }

        // Bike card
        $bikeCard = [
            'type' => 'bike',
            'badge' => '🚲',
            'name' => 'Bike to '.$context['to_name'],
            'detail' => "No disruptions · {$weather['condition']} · {$weather['temperature']}°C",
            'time' => $bikeTime,
            'status' => 'ok',
            'best' => false,
            'to_lat' => $context['to_lat'],
            'to_lng' => $context['to_lng'],
            'to_name' => $context['to_name'],
        ];

        // 3rd card: context-aware smart suggestion
        $suggestionCard = app(DiscoverySuggestionService::class)
            ->getContextCard($user, $weather, $forecast, $context['type'], $context['to_lat'], $context['to_lng']);

        // Determine best route — bike when weather is good NOW, transit otherwise
        $allCards = [$bikeCard, ...$transitCards];
        if ($suggestionCard) {
            $allCards[] = $suggestionCard;
        }
        $rainStarts = $forecast['rain_starts'] ?? null;
        $rainAlreadyStarted = $rainStarts && now()->format('H:i') >= $rainStarts;
        $bikeScore = $forecast['bike_score'] ?? '';
        $bikeIsGood = (str_starts_with($bikeScore, 'Great') || str_starts_with($bikeScore, 'Good'))
            && ! $rainAlreadyStarted
            && $bikeTime <= 30;
        $bestIdx = $bikeIsGood ? 0 : (count($transitCards) > 0 ? 1 : 0);
        $allCards[$bestIdx]['best'] = true;
        $bestCard = $allCards[$bestIdx];

        // Headline — context-aware
        $rainStarts = $forecast['rain_starts'] ?? null;
        if ($context['type'] === 'off_hours') {
            $headline = "{$weather['emoji']} {$weather['temperature']}°C — {$weather['condition']}";
        } elseif ($bestCard['type'] === 'bike') {
            $headline = $rainStarts
                ? "🚲 Bike today — dry until {$rainStarts}, lane clear"
                : "🚲 Bike today — {$weather['condition']}, lane clear";
        } else {
            $headline = "🚋 Transit today — {$weather['temperature']}°C, {$weather['condition']}";
        }

        // Leave-by calculation — use Valhalla for real travel time when available
        $leaveBy = null;
        $arriveByTime = $context['arrive_by'] ?? null;
        if ($arriveByTime && $context['type'] !== 'off_hours') {
            // Try Valhalla for accurate travel time (bike for best card, or pedestrian)
            $valhalla = app(ValhallaRoutingService::class);
            $travelMin = $bestCard['time'] ?: $bikeTime;

            if ($valhalla->isAvailable()) {
                $costing = $bestCard['type'] === 'bike' ? 'bicycle' : 'pedestrian';
                $valhallaRoute = $valhalla->route(
                    $context['from_lat'], $context['from_lng'],
                    $context['to_lat'], $context['to_lng'],
                    $costing,
                );
                if ($valhallaRoute) {
                    $travelMin = $valhallaRoute['duration_min'];
                }
            }

            $arriveAt = Carbon::createFromFormat('H:i', $arriveByTime);
            $leaveAt = $arriveAt->copy()->subMinutes($travelMin);
            $leaveBy = [
                'time' => $leaveAt->format('H:i'),
                'message' => $rainStarts
                    ? "Rain arrives at {$rainStarts} — plan return journey early."
                    : "{$weather['condition']} all day — enjoy the ride.",
            ];
        }

        $needsSetup = ! $home?->address || ! $work?->address;

        return [
            'from' => $context['from_name'],
            'to' => $context['to_name'],
            'headline' => $headline,
            'route_cards' => array_slice($allCards, 0, 3),
            'leave_by' => $leaveBy,
            'weather' => $weather,
            'forecast' => $forecast,
            'needs_setup' => $needsSetup,
            'context' => $context['type'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function appointmentCards(User $user): array
    {
        $appointment = $user->appointments()
            ->where('scheduled_at', '>', now())
            ->where('scheduled_at', '<', now()->addHours(48))
            ->orderBy('scheduled_at')
            ->first();

        if (! $appointment) {
            return [];
        }

        $isToday = Carbon::parse($appointment->scheduled_at)->isToday();
        $isTomorrow = Carbon::parse($appointment->scheduled_at)->isTomorrow();

        return [[
            'type' => 'appointment',
            'title' => $appointment->office_name,
            'subtitle' => $appointment->notes ?? 'Upcoming appointment',
            'emoji' => '🏛️',
            'value' => Carbon::parse($appointment->scheduled_at)->format('H'),
            'unit' => ':'.Carbon::parse($appointment->scheduled_at)->format('i'),
            'priority' => $isToday ? 95 : ($isTomorrow ? 85 : 70),
            'color' => 'warn',
            'meta' => [
                'label' => $isToday ? 'Today' : ($isTomorrow ? 'Tomorrow' : Carbon::parse($appointment->scheduled_at)->format('D')),
                'urgent' => $isToday || $isTomorrow,
            ],
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function commuteCards(User $user, mixed $work, array $weather, array $forecast, array $departures, array $disruptedLines, float $homeLat, float $homeLng): array
    {
        $bikeScore = $forecast['bike_score'] ?? 'Fair';
        $rainStarts = $forecast['rain_starts'] ?? null;
        $temp = $weather['temperature'] ?? 0;
        $hour = now()->hour;
        $isWorkHours = $hour >= 6 && $hour <= 21;

        // Perfect biking conditions
        if (str_starts_with($bikeScore, 'Great') && ! $rainStarts && $temp >= 5) {
            return [[
                'type' => 'commute_tip',
                'title' => "Great day to bike — {$temp}°C, no rain",
                'subtitle' => 'Safe cycle lanes · No disruptions reported',
                'emoji' => '🚲',
                'value' => '',
                'unit' => '',
                'priority' => 80,
                'color' => 'success',
                'meta' => [],
            ]];
        }

        // Rain coming — suggest transit
        if ($rainStarts) {
            return [[
                'type' => 'commute_tip',
                'title' => "Rain from {$rainStarts} — take transit",
                'subtitle' => 'Consider tram/bus to stay dry',
                'emoji' => '🌧️',
                'value' => '',
                'unit' => '',
                'priority' => 78,
                'color' => 'warn',
                'meta' => [],
            ]];
        }

        // Cold weather
        if ($temp < 3 && $isWorkHours) {
            return [[
                'type' => 'commute_tip',
                'title' => "Cold today — {$temp}°C, consider transit",
                'subtitle' => 'Wrap up warm if cycling',
                'emoji' => '🥶',
                'value' => '',
                'unit' => '',
                'priority' => 70,
                'color' => 'accent',
                'meta' => [],
            ]];
        }

        // Default — always show during work hours if user has work place
        if ($work && $isWorkHours) {
            $condition = $weather['condition'] ?? 'Partly cloudy';

            return [[
                'type' => 'commute_tip',
                'title' => "{$temp}°C · {$condition}",
                'subtitle' => $bikeScore,
                'emoji' => $weather['emoji'] ?? '⛅',
                'value' => '',
                'unit' => '',
                'priority' => 55,
                'color' => 'accent',
                'meta' => [],
            ]];
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function departureCards(array $departureData, string $stopName, array $disruptedLines): array
    {
        $cards = [];
        foreach (array_slice($departureData['departures'] ?? [], 0, 2) as $dep) {
            $nextMin = $dep['departures'][0] ?? null;
            if ($nextMin === null) {
                continue;
            }
            $line = $dep['line'];
            $isDisrupted = isset($disruptedLines[$line]);

            $cards[] = [
                'type' => 'departure',
                'title' => "Line {$line} in {$nextMin} min".($isDisrupted ? ' · Disrupted' : ' · On time'),
                'subtitle' => "{$stopName} → {$dep['direction']}",
                'emoji' => '🚋',
                'value' => (string) $nextMin,
                'unit' => 'min',
                'priority' => $nextMin <= 5 ? 75 : 60,
                'color' => $isDisrupted ? 'warn' : 'success',
                'meta' => [
                    'line' => $line,
                    'direction' => $dep['direction'],
                    'following' => array_slice($dep['departures'], 1, 2),
                    'disrupted' => $isDisrupted,
                ],
            ];
        }

        return $cards;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function eventCards(User $user): array
    {
        $events = Event::query()
            ->whereDate('starts_at', today())
            ->where('starts_at', '>', now())
            ->where('quality_score', '>=', 0.3)
            ->orderByDesc('is_expat_relevant')
            ->orderByDesc('quality_score')
            ->orderBy('starts_at')
            ->limit(2)
            ->get();

        $cards = [];
        foreach ($events as $event) {
            $going = $event->attendees()->where('user_id', $user->id)->exists();
            $attendees = $event->attendees()->count();

            $cards[] = [
                'type' => 'event',
                'title' => $event->title,
                'subtitle' => ($event->location_name ?? '').($attendees > 0 ? " · {$attendees} going" : ''),
                'emoji' => $event->emoji ?? '📅',
                'value' => Carbon::parse($event->starts_at)->format('H'),
                'unit' => ':'.Carbon::parse($event->starts_at)->format('i'),
                'priority' => $going ? 72 : 50,
                'color' => 'accent',
                'meta' => [
                    'going' => $going,
                    'venue' => $event->location_name,
                ],
            ];
        }

        return $cards;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function weatherCards(array $weather, array $forecast): array
    {
        $rainStarts = $forecast['rain_starts'] ?? null;
        if (! $rainStarts) {
            return [];
        }

        return [[
            'type' => 'weather_alert',
            'title' => "Rain arriving at {$rainStarts}",
            'subtitle' => 'Plan return journey early · Bring umbrella',
            'emoji' => '🌧️',
            'value' => explode(':', $rainStarts)[0] ?? '',
            'unit' => ':'.(explode(':', $rainStarts)[1] ?? '00'),
            'priority' => 65,
            'color' => 'warn',
            'meta' => ['rain_starts' => $rainStarts],
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function disruptionCards(array $disruptions, User $user): array
    {
        // Get user's lines from routines + home/work stops for relevance filtering
        $userLines = collect();
        $routines = $user->routines()->where('is_active', true)->get();
        foreach ($routines as $r) {
            // Extract line numbers from stop names if available
            $userLines = $userLines->merge([$r->from_stop, $r->to_stop]);
        }

        return collect($disruptions)
            ->map(function (array $d) use ($userLines) {
                $isAccessibility = ($d['type'] ?? '') === 'accessibility';
                $affectedLines = $d['affected_lines'] ?? [];

                // Boost priority if disruption affects user's lines
                $isPersonal = $userLines->isNotEmpty() && ! empty(array_intersect(
                    array_map('strtolower', $affectedLines),
                    $userLines->map(fn ($s) => strtolower($s))->all(),
                ));

                $basePriority = match ($d['severity'] ?? 'minor') {
                    'critical' => 90,
                    'major' => 75,
                    default => $isAccessibility ? 45 : 60,
                };

                return [
                    'type' => $isAccessibility ? 'accessibility_alert' : 'disruption',
                    'title' => $d['title'],
                    'subtitle' => $d['description'] ?? '',
                    'emoji' => $isAccessibility ? '♿' : '⚠️',
                    'value' => '',
                    'unit' => '',
                    'priority' => $isPersonal ? $basePriority + 10 : $basePriority - 20,
                    'color' => match ($d['severity'] ?? 'minor') {
                        'critical' => 'danger',
                        'major' => 'warn',
                        default => $isAccessibility ? 'neutral' : 'warn',
                    },
                    'meta' => [
                        'lines' => $affectedLines,
                        'severity' => $d['severity'] ?? 'minor',
                        'type' => $d['type'] ?? 'line',
                        'source' => $d['source'] ?? '',
                        'personal' => $isPersonal,
                    ],
                ];
            })
            ->sortByDesc('priority')
            ->take(2) // Max 2 disruption cards — prevent feed spam
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function settlementCards(User $user): array
    {
        $pendingTasks = $user->userTasks()
            ->whereNull('completed_at')
            ->notSnoozed()
            ->with(['task', 'user'])
            ->get();

        $cards = [];
        foreach ($pendingTasks as $userTask) {
            $status = $userTask->deadline_status;
            if ($status['urgency'] === 'none' || $status['urgency'] === 'on_track') {
                continue;
            }

            $basePriority = match ($userTask->task->urgency->value) {
                'critical' => 82,
                'high' => 70,
                'medium' => 55,
                default => 40,
            };

            $isOverdue = $status['urgency'] === 'overdue';

            $cards[] = [
                'type' => $isOverdue ? 'deadline_overdue' : 'deadline_warning',
                'title' => $userTask->task->title,
                'subtitle' => $status['label'],
                'emoji' => $isOverdue ? '🚨' : '⏰',
                'value' => $status['days_remaining'] !== null ? (string) abs($status['days_remaining']) : '',
                'unit' => $status['days_remaining'] !== null ? ($isOverdue ? 'days overdue' : 'days left') : '',
                'priority' => $basePriority + $status['priority_boost'],
                'color' => match ($status['urgency']) {
                    'overdue', 'critical' => 'danger',
                    'urgent' => 'warn',
                    default => 'accent',
                },
                'meta' => [
                    'task_id' => $userTask->task->id,
                    'days_remaining' => $status['days_remaining'],
                    'absolute_deadline' => $userTask->absolute_deadline?->toDateString(),
                    'documents_required' => $userTask->task->documents_required ?? [],
                ],
            ];
        }

        // Return top 3 most urgent
        usort($cards, fn ($a, $b) => $b['priority'] <=> $a['priority']);

        return array_slice($cards, 0, 3);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function newsCards(): array
    {
        $news = CityNews::query()
            ->where('published_at', '>', now()->subDays(7))
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('published_at')
            ->limit(1) // Max 1 news card — prevent filler
            ->get();

        return $news->map(fn ($n) => [
            'type' => 'news',
            'title' => $n->title,
            'subtitle' => $n->summary ?? $n->source,
            'emoji' => match ($n->category) {
                'transit' => '🚋',
                'regulation' => '📜',
                'event' => '📅',
                'weather' => '🌤️',
                'culture' => '🎭',
                default => '📰',
            },
            'value' => '',
            'unit' => '',
            'priority' => match ($n->relevance) {
                'expat' => 68,
                'transit' => 62,
                'bureaucracy' => 58,
                default => 40,
            },
            'color' => 'accent',
            'meta' => ['source' => $n->source, 'url' => $n->source_url, 'category' => $n->category],
        ])->all();
    }

    /**
     * Adjust card priorities based on user situation and German level.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<int, array<string, mixed>>
     */
    protected function adjustPrioritiesByProfile(User $user, array $cards): array
    {
        $situation = $user->situation?->value;
        $germanLevel = $user->german_level?->value;

        foreach ($cards as &$card) {
            $type = $card['type'] ?? '';

            // Situation-based adjustments
            match ($situation) {
                'student' => match ($type) {
                    'event' => $card['priority'] += 10,
                    'news' => $card['priority'] += 5,
                    default => null,
                },
                'freelancer', 'digital_nomad' => match ($type) {
                    'commute_tip' => $card['priority'] -= 10, // Flexible schedule
                    default => null,
                },
                'family_reunification' => match ($type) {
                    'deadline_overdue', 'deadline_upcoming', 'deadline_soon' => $card['priority'] += 10,
                    default => null,
                },
                default => null,
            };

            // German level adjustments
            if (in_array($germanLevel, ['none', 'a1', 'a2'])) {
                // Beginners: boost language and bureaucracy help
                if (str_contains($type, 'deadline') || $type === 'settlement') {
                    $card['priority'] += 5;
                }
            }

            $card['priority'] = max(0, min(100, $card['priority']));
        }

        return $cards;
    }

    /**
     * Adjust card priorities based on user engagement history.
     * Cards the user clicks get boosted. Card types the user never engages with get deprioritized.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<int, array<string, mixed>>
     */
    protected function adjustPrioritiesByEngagement(User $user, array $cards): array
    {
        // Get click counts per card type from last 7 days
        $clicks = $user->behaviorEvents()
            ->where('event_type', 'card_clicked')
            ->where('created_at', '>=', now()->subDays(7))
            ->get()
            ->groupBy(fn ($e) => $e->payload['card_type'] ?? 'unknown')
            ->map->count();

        // Get dismiss counts per card type from last 7 days
        $dismissals = $user->behaviorEvents()
            ->where('event_type', 'card_dismissed')
            ->where('created_at', '>=', now()->subDays(7))
            ->get()
            ->groupBy(fn ($e) => $e->payload['card_type'] ?? 'unknown')
            ->map->count();

        foreach ($cards as &$card) {
            $type = $card['type'] ?? 'unknown';
            $clickCount = $clicks->get($type, 0);
            $dismissCount = $dismissals->get($type, 0);

            // Boost clicked types (+5 per click, max +15)
            $card['priority'] += min($clickCount * 5, 15);

            // Penalize dismissed types (-10 per dismissal, max -30)
            $card['priority'] -= min($dismissCount * 10, 30);

            // Clamp to 0-100
            $card['priority'] = max(0, min(100, $card['priority']));
        }

        return $cards;
    }

    /**
     * Resolve coordinates for a location name — check saved places first, then KVB stops.
     *
     * @return array{lat: float, lng: float}
     */
    protected function resolveLocationCoords(User $user, string $name, float $fallbackLat, float $fallbackLng): array
    {
        // Check saved places
        $place = $user->places()->where('name', 'ILIKE', "%{$name}%")->first();
        if ($place?->lat && $place?->lng) {
            return ['lat' => (float) $place->lat, 'lng' => (float) $place->lng];
        }

        // Check KVB stops
        $kvb = app(KvbApiService::class);
        $stop = $kvb->searchStops($name, 1)[0] ?? null;
        if ($stop) {
            return ['lat' => $stop['lat'], 'lng' => $stop['lng']];
        }

        return ['lat' => $fallbackLat, 'lng' => $fallbackLng];
    }

    protected function calculateBikeTime(mixed $home, mixed $work): int
    {
        if (! $home?->lat || ! $home?->lng || ! $work?->lat || ! $work?->lng) {
            return 22; // fallback
        }

        $R = 6371;
        $dLat = deg2rad($work->lat - $home->lat);
        $dLng = deg2rad($work->lng - $home->lng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($home->lat)) * cos(deg2rad($work->lat)) * sin($dLng / 2) ** 2;
        $dist = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));

        return max(5, (int) round(($dist / 15) * 60)); // 15 km/h avg
    }

    /**
     * Determine commute context: where is the user going based on time + day?
     *
     * Contexts:
     * - 'to_work': morning on weekday, before work arrive_by → recommend Home→Work
     * - 'to_home': afternoon/evening on weekday, after work hours → recommend Work→Home
     * - 'to_place': heading to a frequent non-work destination
     * - 'off_hours': weekend/late night → show nearby departures, no commute
     *
     * @return array{type: string, from_name: string, to_name: string, from_lat: float, from_lng: float, to_lat: float, to_lng: float, arrive_by: string|null}
     */
    protected function determineCommuteContext(User $user, mixed $home, mixed $work): array
    {
        $now = Carbon::now();
        $hour = $now->hour;
        $minute = $now->minute;
        $isWeekday = $now->isWeekday();
        $nowMinutes = $hour * 60 + $minute; // current time as minutes since midnight

        $homeLat = $home?->lat ? (float) $home->lat : 50.9375;
        $homeLng = $home?->lng ? (float) $home->lng : 6.9603;
        $workLat = $work?->lat ? (float) $work->lat : null;
        $workLng = $work?->lng ? (float) $work->lng : null;
        $workArriveBy = $work?->arrive_by ? Carbon::parse($work->arrive_by)->format('H:i') : null;
        $workArriveMin = $workArriveBy ? ((int) explode(':', $workArriveBy)[0]) * 60 + ((int) explode(':', $workArriveBy)[1]) : null;

        $locationService = app(LocationPatternService::class);
        $currentLocation = $locationService->detectCurrentLocation($user);
        $atCategory = $currentLocation['place_category'] ?? null;
        $atName = $currentLocation['place_name'] ?? null;

        $discovery = fn (string $fromName, float $fromLat, float $fromLng) => [
            'type' => 'off_hours',
            'from_name' => $fromName,
            'to_name' => 'Nearby',
            'from_lat' => $fromLat,
            'from_lng' => $fromLng,
            'to_lat' => $fromLat,
            'to_lng' => $fromLng,
            'arrive_by' => null,
        ];

        // ── 0. Weekend / late night → always discovery ──
        if (! $isWeekday || $hour >= 22 || $hour < 5) {
            return $discovery($home?->name ?? 'Home', $homeLat, $homeLng);
        }

        // ── 1. Check ALL scheduled places (arrive_by set) for today ──
        // Find the next upcoming scheduled place within a 2hr window
        $scheduledPlaces = $user->places()
            ->whereNotNull('arrive_by')
            ->where('category', '!=', 'home')
            ->orderByRaw("CASE category WHEN 'work' THEN 0 ELSE 1 END")
            ->orderBy('arrive_by')
            ->get()
            ->filter(fn ($p) => $p->isActiveToday());

        $nextScheduled = null;
        foreach ($scheduledPlaces as $place) {
            $arriveAt = Carbon::parse($place->arrive_by);
            $arriveMin = $arriveAt->hour * 60 + $arriveAt->minute;

            // Window: from 2hr before arrive_by to 1hr after (arrival → activity done)
            $windowStart = $arriveMin - 120;
            $windowEnd = $arriveMin + 60;

            if ($nowMinutes >= $windowStart && $nowMinutes < $arriveMin) {
                // Before arrive_by → should be heading there
                $nextScheduled = ['place' => $place, 'phase' => 'heading', 'arrive_by' => $arriveAt->format('H:i')];
                break;
            }

            if ($nowMinutes >= $arriveMin && $nowMinutes < $windowEnd) {
                // Past arrive_by but within 1hr → user should be there or it's done
                $nextScheduled = ['place' => $place, 'phase' => 'there_or_done', 'arrive_by' => $arriveAt->format('H:i')];
                break;
            }
        }

        if ($nextScheduled) {
            $place = $nextScheduled['place'];
            $placeLat = (float) $place->lat;
            $placeLng = (float) $place->lng;

            if ($nextScheduled['phase'] === 'heading') {
                // GPS says user is already at or near the scheduled place → discovery
                if ($atName && mb_strtolower($atName) === mb_strtolower($place->name)) {
                    return $discovery($place->name, $placeLat, $placeLng);
                }

                // User is elsewhere → commute to the scheduled place
                $fromLat = $atCategory === 'work' ? ($workLat ?? $homeLat) : $homeLat;
                $fromLng = $atCategory === 'work' ? ($workLng ?? $homeLng) : $homeLng;

                return [
                    'type' => 'to_regular',
                    'from_name' => $atName ?? $home?->name ?? 'Home',
                    'to_name' => $place->name,
                    'from_lat' => $fromLat,
                    'from_lng' => $fromLng,
                    'to_lat' => $placeLat,
                    'to_lng' => $placeLng,
                    'arrive_by' => $nextScheduled['arrive_by'],
                ];
            }

            if ($nextScheduled['phase'] === 'there_or_done') {
                // Past arrive_by: if GPS says user is at/near the place → discovery (activity in progress or done)
                // If GPS says user is at Home → they didn't go → discovery
                return $discovery($atName ?? $home?->name ?? 'Home', $homeLat, $homeLng);
            }
        }

        // ── 2. Saved Routine matching current day+hour ──
        $activeRoutine = $user->routines()
            ->where('is_active', true)
            ->get()
            ->first(function ($r) use ($now) {
                $dayName = strtolower($now->format('D'));
                $routineHour = (int) explode(':', $r->departure_time)[0];

                return in_array($dayName, $r->days ?? [], true)
                    && abs($now->hour - $routineHour) <= 1;
            });

        if ($activeRoutine) {
            $fromCoords = $this->resolveLocationCoords($user, $activeRoutine->from_stop, $homeLat, $homeLng);
            $toCoords = $this->resolveLocationCoords($user, $activeRoutine->to_stop, $workLat ?? $homeLat, $workLng ?? $homeLng);

            return [
                'type' => 'routine',
                'from_name' => $activeRoutine->from_stop,
                'to_name' => $activeRoutine->to_stop,
                'from_lat' => $fromCoords['lat'],
                'from_lng' => $fromCoords['lng'],
                'to_lat' => $toCoords['lat'],
                'to_lng' => $toCoords['lng'],
                'arrive_by' => $activeRoutine->departure_time,
            ];
        }

        // ── 3. GPS-based detection with time awareness ──
        if ($currentLocation) {
            // At Home
            if ($atCategory === 'home') {
                // Morning before work cutoff → suggest Work
                $goToWorkCutoff = $workArriveMin ? ($workArriveMin / 60) + 1 : 10;
                if ($workLat && $workLng && $hour < $goToWorkCutoff) {
                    return [
                        'type' => 'to_work',
                        'from_name' => $atName,
                        'to_name' => $work?->name ?? 'Work',
                        'from_lat' => $homeLat,
                        'from_lng' => $homeLng,
                        'to_lat' => $workLat,
                        'to_lng' => $workLng,
                        'arrive_by' => $workArriveBy,
                    ];
                }

                // Past work cutoff, at Home → discovery
                return $discovery($atName, $homeLat, $homeLng);
            }

            // At Work
            if ($atCategory === 'work') {
                // Past arrive_by + 9 hours → suggest Home (work day done)
                $workDoneMin = $workArriveMin ? $workArriveMin + 540 : 17 * 60; // 9hr work day
                if ($nowMinutes >= $workDoneMin) {
                    return [
                        'type' => 'to_home',
                        'from_name' => $atName,
                        'to_name' => $home?->name ?? 'Home',
                        'from_lat' => $workLat ?? $homeLat,
                        'from_lng' => $workLng ?? $homeLng,
                        'to_lat' => $homeLat,
                        'to_lng' => $homeLng,
                        'arrive_by' => null,
                    ];
                }

                // Still within work hours → show departures from work
                return [
                    'type' => 'at_work',
                    'from_name' => $atName,
                    'to_name' => $home?->name ?? 'Home',
                    'from_lat' => $workLat ?? $homeLat,
                    'from_lng' => $workLng ?? $homeLng,
                    'to_lat' => $homeLat,
                    'to_lng' => $homeLng,
                    'arrive_by' => null,
                ];
            }

            // At custom place → check if activity is done (1hr past arrive_by)
            if ($atCategory === 'custom') {
                $customPlace = $user->places()->where('name', $atName)->first();
                if ($customPlace?->arrive_by) {
                    $placeArriveMin = Carbon::parse($customPlace->arrive_by)->hour * 60 + Carbon::parse($customPlace->arrive_by)->minute;
                    if ($nowMinutes >= $placeArriveMin + 60) {
                        // Activity done → suggest Home
                        return [
                            'type' => 'to_home',
                            'from_name' => $atName,
                            'to_name' => $home?->name ?? 'Home',
                            'from_lat' => (float) ($customPlace->lat ?? $homeLat),
                            'from_lng' => (float) ($customPlace->lng ?? $homeLng),
                            'to_lat' => $homeLat,
                            'to_lng' => $homeLng,
                            'arrive_by' => null,
                        ];
                    }
                }

                // Still at custom place, activity not done → discovery
                return $discovery($atName, (float) ($customPlace?->lat ?? $homeLat), (float) ($customPlace?->lng ?? $homeLng));
            }
        }

        // ── 4. No GPS: time-based fallback ──
        if (! $workLat || ! $workLng) {
            return $discovery($home?->name ?? 'Home', $homeLat, $homeLng);
        }

        $workHour = $workArriveMin ? (int) ($workArriveMin / 60) : 9;

        // Before work
        if ($hour < $workHour) {
            return [
                'type' => 'to_work',
                'from_name' => $home?->name ?? 'Home',
                'to_name' => $work?->name ?? 'Work',
                'from_lat' => $homeLat,
                'from_lng' => $homeLng,
                'to_lat' => $workLat,
                'to_lng' => $workLng,
                'arrive_by' => $workArriveBy,
            ];
        }

        // After work day (arrive_by + 9hr or 17:00)
        $returnHour = $workArriveMin ? min(20, (int) (($workArriveMin + 540) / 60)) : 17;
        if ($hour >= $returnHour) {
            return [
                'type' => 'to_home',
                'from_name' => $work?->name ?? 'Work',
                'to_name' => $home?->name ?? 'Home',
                'from_lat' => $workLat,
                'from_lng' => $workLng,
                'to_lat' => $homeLat,
                'to_lng' => $homeLng,
                'arrive_by' => null,
            ];
        }

        // Middle of day, no GPS → discovery (don't assume location)
        return $discovery($home?->name ?? 'Home', $homeLat, $homeLng);
    }
}
