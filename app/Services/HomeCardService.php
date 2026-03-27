<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;

class HomeCardService
{
    /**
     * Build the home feed cards for a user.
     *
     * @return array<int, array{type: string, data: array<string, mixed>, priority: int}>
     */
    public function buildFeed(User $user): array
    {
        $home = $user->places()->orderBy('sort_order')->first();
        $homeLat = $home?->lat ? (float) $home->lat : null;
        $homeLng = $home?->lng ? (float) $home->lng : null;
        $homeStopName = $home?->address ?? $home?->name ?? 'Ehrenfeld';

        $cards = array_filter([
            $this->buildBlueHighlight($user, $homeStopName, $homeLat, $homeLng),
            $this->buildDisruptionBanner(),
            $this->buildSettlementProgress($user),
            $this->buildYourPlaces($user),
            $this->buildQuickAccess($user),
            $this->buildThisWeek(),
            $this->buildLiveDepartures($homeStopName, $homeLat, $homeLng),
        ]);

        usort($cards, fn (array $a, array $b) => $b['priority'] <=> $a['priority']);

        return array_values($cards);
    }

    /**
     * @return array{type: string, data: array<string, mixed>, priority: int}|null
     */
    protected function buildBlueHighlight(User $user, string $homeStopName = '', ?float $homeLat = null, ?float $homeLng = null): ?array
    {
        $urgentTasks = $user->userTasks()
            ->whereNull('completed_at')
            ->whereHas('task', fn ($q) => $q->where('urgency', 'critical'))
            ->with('task')
            ->limit(1)
            ->get();

        $nextAppointment = $user->appointments()
            ->where('scheduled_at', '>', now())
            ->where('scheduled_at', '<', now()->addHours(48))
            ->orderBy('scheduled_at')
            ->first();

        if ($urgentTasks->isEmpty() && ! $nextAppointment) {
            return null;
        }

        // Build appointment data with documents
        $appointmentData = null;
        if ($nextAppointment) {
            $appointmentData = [
                ...$nextAppointment->only('id', 'office_name', 'scheduled_at'),
                'notes' => $nextAppointment->notes,
                'is_tomorrow' => Carbon::parse($nextAppointment->scheduled_at)->isTomorrow(),
                'time' => Carbon::parse($nextAppointment->scheduled_at)->format('H:i'),
            ];
        }

        // Build urgent task data with documents
        $urgentTaskData = null;
        if ($urgentTasks->isNotEmpty()) {
            $task = $urgentTasks->first()->task;
            $urgentTaskData = [
                ...$task->only('id', 'title', 'urgency', 'deadline_days'),
                'documents_required' => $task->documents_required ?? [],
            ];
        }

        // Build timeline rows (next departure, tonight's event, weather)
        $timelineRows = [];

        // Next departure from user's nearest stop via GTFS
        $gtfsService = App::make(GtfsDepartureService::class);
        $deptResult = ($homeLat && $homeLng)
            ? $gtfsService->getDeparturesNearby($homeLat, $homeLng, 3)
            : $gtfsService->getDepartures($homeStopName, 3);
        $nextDep = collect($deptResult['departures'] ?? [])
            ->filter(fn (array $d) => ! empty($d['departures']))
            ->first();

        if ($nextDep) {
            $mins = $nextDep['departures'][0];
            $delayNote = $mins <= 1 ? 'Now' : "in {$mins} min";
            $timelineRows[] = [
                'emoji' => '🚇',
                'title' => "Line {$nextDep['line']} {$delayNote} · No delays",
                'subtitle' => ($deptResult['stop_name'] ?? $homeStopName).' → '.$nextDep['direction'],
                'value' => (string) $mins,
                'unit' => 'min',
            ];
        } else {
            $timelineRows[] = [
                'emoji' => '🚇',
                'title' => 'No upcoming departures',
                'subtitle' => $homeStopName,
                'value' => '—',
                'unit' => '',
            ];
        }

        // Tonight's event
        $tonightEvent = Event::query()
            ->whereDate('starts_at', today())
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->first();

        if ($tonightEvent) {
            $timelineRows[] = [
                'emoji' => $tonightEvent->emoji ?? '📅',
                'title' => $tonightEvent->title,
                'subtitle' => $tonightEvent->location_name.' · '.($tonightEvent->attendees_count ?? 0).' going',
                'value' => Carbon::parse($tonightEvent->starts_at)->format('H'),
                'unit' => ':'.Carbon::parse($tonightEvent->starts_at)->format('i'),
            ];
        }

        // Weather context — real data from Bright Sky
        $weather = app(WeatherService::class)->getCurrentWeather();
        $forecast = app(WeatherService::class)->getForecast();
        $rainNote = $forecast['rain_starts'] ? "Rain from {$forecast['rain_starts']}" : 'No rain today';
        $timelineRows[] = [
            'emoji' => $weather['emoji'],
            'title' => "{$weather['condition']} — {$forecast['bike_score']}",
            'subtitle' => "{$weather['temperature']}°C · Wind {$weather['wind_speed']} km/h · {$rainNote}",
            'value' => (string) $weather['temperature'],
            'unit' => '°C',
        ];

        return [
            'type' => 'blue_highlight',
            'data' => [
                'urgent_task' => $urgentTaskData,
                'appointment' => $appointmentData,
                'headline' => $this->getWeatherHeadline(),
                'timeline_rows' => $timelineRows,
            ],
            'priority' => 100,
        ];
    }

    /**
     * Active disruptions — requires VRS real-time API.
     * Returns null until VRS access is approved.
     *
     * @return array{type: string, data: array<string, mixed>, priority: int}|null
     */
    protected function buildDisruptionBanner(): ?array
    {
        // VRS real-time disruptions not yet available
        // Will be enabled when api@vrs.de provides GTFS-RT access
        return null;
    }

    /**
     * @return array{type: string, data: array<string, mixed>, priority: int}|null
     */
    protected function buildSettlementProgress(User $user): ?array
    {
        $totalTasks = $user->userTasks()->count();
        $completedTasks = $user->userTasks()->whereNotNull('completed_at')->count();

        $daysSinceArrival = $user->arrival_date
            ? (int) Carbon::parse($user->arrival_date)->diffInDays(now())
            : 0;

        $priority = 60;

        // Boost for new users
        if ($daysSinceArrival < 30) {
            $priority = 85;
        }

        return [
            'type' => 'settlement_progress',
            'data' => [
                'total' => $totalTasks,
                'completed' => $completedTasks,
                'percent' => $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0,
                'situation' => $user->situation?->value,
                'days_since_arrival' => $daysSinceArrival,
            ],
            'priority' => $priority,
        ];
    }

    /**
     * @return array{type: string, data: array<string, mixed>, priority: int}
     */
    protected function buildYourPlaces(User $user): array
    {
        $places = $user->places()->limit(5)->get()
            ->map(fn ($p) => $p->only('id', 'emoji', 'name', 'address'))
            ->all();

        return [
            'type' => 'your_places',
            'data' => ['places' => $places],
            'priority' => 55,
        ];
    }

    /**
     * @return array{type: string, data: array<string, mixed>, priority: int}
     */
    protected function buildQuickAccess(User $user): array
    {
        $pendingTasks = $user->userTasks()->whereNull('completed_at')->count();
        $unreadAlerts = $user->alerts()->whereNull('read_at')->count();

        return [
            'type' => 'quick_access',
            'data' => [
                'items' => [
                    ['emoji' => '☕', 'label' => 'Work Spots', 'href' => '/explore', 'subtitle' => 'Find nearby'],
                    ['emoji' => '📋', 'label' => 'Checklist', 'href' => '/bureaucracy', 'subtitle' => $pendingTasks.' pending'],
                    ['emoji' => '🗣️', 'label' => 'Language', 'href' => '/language-exchange', 'subtitle' => 'Find partners'],
                    ['emoji' => '🔔', 'label' => 'Alerts', 'href' => '/alerts', 'subtitle' => $unreadAlerts > 0 ? $unreadAlerts.' new' : 'All clear'],
                ],
            ],
            'priority' => 45,
        ];
    }

    /**
     * @return array{type: string, data: array<string, mixed>, priority: int}
     */
    protected function buildThisWeek(): array
    {
        $events = Event::query()
            ->where('starts_at', '>', now())
            ->where('starts_at', '<', now()->addDays(7))
            ->orderBy('starts_at')
            ->limit(3)
            ->get(['id', 'title', 'emoji', 'category', 'starts_at', 'location_name', 'is_free', 'price'])
            ->map(fn ($e) => $e->toArray())
            ->all();

        return [
            'type' => 'this_week',
            'data' => [
                'events' => $events,
                'placeholder' => empty($events),
            ],
            'priority' => 30,
        ];
    }

    /**
     * Live departure data from GTFS.
     *
     * @return array{type: string, data: array<string, mixed>, priority: int}
     */
    protected function buildLiveDepartures(string $homeStopName = '', ?float $homeLat = null, ?float $homeLng = null): array
    {
        $gtfsService = App::make(GtfsDepartureService::class);
        $result = ($homeLat && $homeLng)
            ? $gtfsService->getDeparturesNearby($homeLat, $homeLng, 6)
            : $gtfsService->getDepartures($homeStopName, 6);

        $departures = collect($result['departures'] ?? [])
            ->take(3)
            ->map(fn (array $dep) => [
                'line' => $dep['line'],
                'direction' => $dep['direction'],
                'color' => $dep['color'],
                'minutes' => $dep['departures'][0] ?? null,
            ])
            ->filter(fn (array $dep) => $dep['minutes'] !== null)
            ->values()
            ->all();

        if (empty($departures)) {
            return [
                'type' => 'live_departures',
                'data' => [
                    'departures' => [],
                    'source' => $result['source'] ?? 'mock',
                    'placeholder' => true,
                ],
                'priority' => 30,
            ];
        }

        return [
            'type' => 'live_departures',
            'data' => [
                'departures' => $departures,
                'source' => $result['source'] ?? 'mock',
                'stop_name' => $result['stop_name'] ?? $homeStopName,
                'placeholder' => false,
            ],
            'priority' => 30,
        ];
    }

    protected function getWeatherHeadline(): string
    {
        $weather = app(WeatherService::class)->getCurrentWeather();
        $forecast = app(WeatherService::class)->getForecast();

        $condition = $weather['condition'] ?? 'Clear sky';

        if ($forecast['rain_starts']) {
            return "{$condition} until {$forecast['rain_starts']} —\ngood day to bike.";
        }

        return "{$condition} today —\n{$forecast['bike_score']}.";
    }
}
