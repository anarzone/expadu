<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;

class HomeCardService
{
    /**
     * Build the home feed cards for a user.
     *
     * @return array<int, array{type: string, data: array<string, mixed>, priority: int}>
     */
    public function buildFeed(User $user): array
    {
        $cards = array_filter([
            $this->buildBlueHighlight($user),
            $this->buildSettlementProgress($user),
            $this->buildYourPlaces($user),
            $this->buildQuickAccess($user),
            $this->buildThisWeek(),
            $this->buildLiveDepartures(),
        ]);

        usort($cards, fn (array $a, array $b) => $b['priority'] <=> $a['priority']);

        return array_values($cards);
    }

    /**
     * @return array{type: string, data: array<string, mixed>, priority: int}|null
     */
    protected function buildBlueHighlight(User $user): ?array
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

        // Next departure (mock until VRS integration)
        $timelineRows[] = [
            'emoji' => '🚇',
            'title' => 'Line 9 in 4 min · No delays',
            'subtitle' => 'Venloer Str./Gürtel → Neumarkt',
            'value' => '4',
            'unit' => 'min',
        ];

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

        // Weather context (placeholder until Bright Sky integration)
        $timelineRows[] = [
            'emoji' => '🌤️',
            'title' => 'Dry until evening — good day to bike',
            'subtitle' => '14°C · Wind 12 km/h',
            'value' => '14',
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
     * Placeholder — will be populated with real transit data in Phase 2.
     *
     * @return array{type: string, data: array<string, mixed>, priority: int}
     */
    protected function buildLiveDepartures(): array
    {
        return [
            'type' => 'live_departures',
            'data' => [
                'departures' => [],
                'placeholder' => true,
            ],
            'priority' => 30,
        ];
    }

    protected function getWeatherHeadline(): string
    {
        // Placeholder until Bright Sky integration
        return "Dry until 18:00 —\ngood day to bike.";
    }
}
